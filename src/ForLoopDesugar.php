<?php

require_once __DIR__ . '/Ast.php';

/**
 * Desugars ForNode into lower-level constructs before Monomorphizer/OwnershipChecker.
 *
 * for i in 0..n { body }
 *   -> let mut __for_i = 0; loop { if __for_i >= n { break; } let i = __for_i; body; __for_i = __for_i + 1; }
 *
 * for x in iterable { body }
 *   -> let mut __for_iter = iterable.into_iter();
 *      loop { match __for_iter.next() { Option::Some(x) => { body } _ => { break; } } }
 */
class ForLoopDesugar {
    private int $tmp_counter = 0;

    public function desugar(ProgramNode $program): ProgramNode {
        foreach ($program->functions as $fn) {
            if ($fn->body !== null) {
                $fn->body = $this->rewriteBody($fn->body);
            }
        }
        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                if ($fn->body !== null) {
                    $fn->body = $this->rewriteBody($fn->body);
                }
            }
        }

        // Only inject Option enum if the generic desugaring path was actually used
        // (range-based for loops don't need it)
        return $program;
    }

    private function freshVar(string $base): string {
        return "__for_{$base}_" . ($this->tmp_counter++);
    }

    private function rewriteBody(array $stmts): array {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof ForNode) {
                foreach ($this->desugarFor($stmt) as $s) {
                    $out[] = $s;
                }
            } else {
                $out[] = $this->rewriteStmt($stmt);
            }
        }
        return $out;
    }

    /** @return array of statements replacing ForNode */
    private function desugarFor(ForNode $node): array {
        $line = $node->line;

        if ($node->iter_expr instanceof RangeNode) {
            return $this->desugarRange($node->var_name, $node->iter_expr, $this->rewriteBody($node->body), $line);
        }

        return $this->desugarGeneric($node->var_name, $node->iter_expr, $this->rewriteBody($node->body), $line);
    }

    /** Desugar `for i in start..end` without needing Option. */
    private function desugarRange(string $var, RangeNode $range, array $body, int $line): array {
        $idx_var = $this->freshVar('i');
        $end_var = $this->freshVar('end');

        // let mut __for_i_N = start;
        $let_idx = new LetNode($idx_var, 'i32', $range->start, true, $line);
        // let __for_end_N = end;
        $let_end = new LetNode($end_var, 'i32', $range->end, false, $line);

        // if __for_i_N >= __for_end_N { break; }
        $cond = new BinaryOpNode(new IdentNode($idx_var, $line), '>=', new IdentNode($end_var, $line), $line);
        $guard = new IfNode($cond, [new BreakNode($line)], null, $line);

        // let var = __for_i_N;
        $let_var = new LetNode($var, null, new IdentNode($idx_var, $line), false, $line);

        // __for_i_N = __for_i_N + 1;
        $incr = new AssignNode($idx_var, new BinaryOpNode(new IdentNode($idx_var, $line), '+', new IntLitNode(1, $line), $line), $line);

        $bodyWithContinueRewritten = $this->replaceContinueWithIncrBreak($body, $incr, $line);
        $inner_body = array_merge($bodyWithContinueRewritten, [$incr, new BreakNode($line)]);
        $inner_loop = new LoopNode($inner_body, $line);
        $loop_body = array_merge([$guard, $let_var], [$inner_loop]);
        $loop = new LoopNode($loop_body, $line);

        return [$let_idx, $let_end, $loop];
    }

    /** Desugar `for x in expr` via into_iter / next / Option. */
    private function desugarGeneric(string $var, mixed $iter_expr, array $body, int $line): array {
        $iter_var = $this->freshVar('iter');

        $into_iter = new MethodCallNode($iter_expr, 'into_iter', [], $line);
        $let_iter = new LetNode($iter_var, null, $into_iter, true, $line);

        $next_call = new MethodCallNode(new IdentNode($iter_var, $line), 'next', [], $line);
        $some_arm = new MatchArmNode(false, 'Option', 'Some', $var, $body, $line);
        $none_arm = new MatchArmNode(true, null, null, null, [new BreakNode($line)], $line);
        $match = new MatchNode($next_call, [$some_arm, $none_arm], $line);

        $loop = new LoopNode([$match], $line);

        return [$let_iter, $loop];
    }

    /** Replace ContinueNode with [incr, break] in for-loop body; do not replace continue inside nested loops. */
    private function replaceContinueWithIncrBreak(array $body, AssignNode $incr, int $line, bool $replaceContinue = true): array {
        $out = [];
        foreach ($body as $stmt) {
            if ($stmt instanceof ContinueNode && $replaceContinue) {
                $out[] = $incr;
                $out[] = new BreakNode($stmt->line);
            } elseif ($stmt instanceof IfNode) {
                $out[] = new IfNode(
                    $stmt->condition,
                    $this->replaceContinueWithIncrBreak($stmt->then_body, $incr, $line, $replaceContinue),
                    $stmt->else_body !== null ? $this->replaceContinueWithIncrBreak($stmt->else_body, $incr, $line, $replaceContinue) : null,
                    $stmt->line
                );
            } elseif ($stmt instanceof LoopNode) {
                $out[] = new LoopNode($this->replaceContinueWithIncrBreak($stmt->body, $incr, $line, false), $stmt->line);
            } elseif ($stmt instanceof MatchNode) {
                $arms = [];
                foreach ($stmt->arms as $arm) {
                    $arms[] = new MatchArmNode(
                        $arm->is_wildcard, $arm->enum_name, $arm->variant_name,
                        $arm->binding,
                        $this->replaceContinueWithIncrBreak($arm->body, $incr, $line, $replaceContinue),
                        $arm->line
                    );
                }
                $out[] = new MatchNode($stmt->subject, $arms, $stmt->line);
            } elseif ($stmt instanceof IfLetNode) {
                $out[] = new IfLetNode(
                    $stmt->subject, $stmt->enum_name, $stmt->variant_name, $stmt->binding,
                    $this->replaceContinueWithIncrBreak($stmt->then_body, $incr, $line, $replaceContinue),
                    $stmt->else_body !== null ? $this->replaceContinueWithIncrBreak($stmt->else_body, $incr, $line, $replaceContinue) : null,
                    $stmt->line
                );
            } elseif ($stmt instanceof WhileNode) {
                $out[] = new WhileNode($stmt->condition, $this->replaceContinueWithIncrBreak($stmt->body, $incr, $line, $replaceContinue), $stmt->line);
            } elseif ($stmt instanceof WhileLetNode) {
                $out[] = new WhileLetNode($stmt->subject, $stmt->enum_name, $stmt->variant_name, $stmt->binding, $this->replaceContinueWithIncrBreak($stmt->body, $incr, $line, $replaceContinue), $stmt->line);
            } elseif ($stmt instanceof BreakNode && $replaceContinue) {
                $out[] = new BreakNode($stmt->line, 1);
            } else {
                $out[] = $stmt;
            }
        }
        return $out;
    }

    private function rewriteStmt(mixed $stmt): mixed {
        if ($stmt instanceof IfNode) {
            $then_body = $this->rewriteBody($stmt->then_body);
            $else_body = $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body) : null;
            return new IfNode($stmt->condition, $then_body, $else_body, $stmt->line);
        }
        if ($stmt instanceof IfLetNode) {
            $then_body = $this->rewriteBody($stmt->then_body);
            $else_body = $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body) : null;
            return new IfLetNode($stmt->subject, $stmt->enum_name, $stmt->variant_name, $stmt->binding, $then_body, $else_body, $stmt->line);
        }
        if ($stmt instanceof WhileNode) {
            return new WhileNode($stmt->condition, $this->rewriteBody($stmt->body), $stmt->line);
        }
        if ($stmt instanceof WhileLetNode) {
            return new WhileLetNode($stmt->subject, $stmt->enum_name, $stmt->variant_name, $stmt->binding, $this->rewriteBody($stmt->body), $stmt->line);
        }
        if ($stmt instanceof LoopNode) {
            return new LoopNode($this->rewriteBody($stmt->body), $stmt->line);
        }
        if ($stmt instanceof MatchNode) {
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $arms[] = new MatchArmNode(
                    $arm->is_wildcard, $arm->enum_name, $arm->variant_name,
                    $arm->binding, $this->rewriteBody($arm->body), $arm->line
                );
            }
            return new MatchNode($stmt->subject, $arms, $stmt->line);
        }
        return $stmt;
    }
}
