<?php

require_once __DIR__ . '/Ast.php';

/**
 * Desugars ClosureNode before Monomorphizer/OwnershipChecker/CodeGen.
 *
 * For each closure `|x: i32, y: i32| body` with captured variables from the
 * outer scope, this pass:
 *  1. Synthesizes a struct  __Closure_N { capture1: T1, ... }
 *  2. Synthesizes a function __closure_N_call(__env: &__Closure_N, x: i32, y: i32) -> R { body }
 *     where every reference to a captured variable inside the body is rewritten
 *     to a field access __env.capture.
 *  3. Replaces the ClosureNode with StructLitNode("__Closure_N", { capture1: capture1 })
 *  4. Rewrites every direct call `f(args)` where f is a known closure variable to
 *     `__closure_N_call(&f, args)`.
 *
 * Limitations (intentional for this compiler):
 *  - Closure params must have explicit type annotations (defaults to i32).
 *  - Captured variables must be copy types (i32, bool). Non-copy types are captured
 *    by value too, which may violate move semantics — the compiler currently does not
 *    enforce this.
 *  - Closures cannot be passed as function arguments (no Fn trait dispatch at this
 *    stage). Only direct calls of local closure variables are supported.
 */
class ClosureDesugar {
    private int   $counter      = 0;
    private array $new_structs  = [];
    private array $new_fns      = [];

    public function desugar(ProgramNode $program): ProgramNode {
        foreach ($program->functions as $fn) {
            if ($fn->body === null) continue;
            $scope        = [];
            $closure_vars = [];
            foreach ($fn->params as $p) {
                $scope[$p['name']] = $p['type'];
            }
            $fn->body = $this->rewriteBody($fn->body, $scope, $closure_vars);
        }
        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                if ($fn->body === null) continue;
                $scope        = [];
                $closure_vars = [];
                foreach ($fn->params as $p) {
                    $scope[$p['name']] = str_replace('self', $impl->struct_name, $p['type']);
                }
                $fn->body = $this->rewriteBody($fn->body, $scope, $closure_vars);
            }
        }
        $program->structs   = array_merge($program->structs,   $this->new_structs);
        $program->functions = array_merge($program->functions, $this->new_fns);
        return $program;
    }

    // ── outer-scope rewriting ──────────────────────────────────────────────

    private function rewriteBody(array $stmts, array &$scope, array &$closure_vars): array {
        $out = [];
        foreach ($stmts as $stmt) {
            $out[] = $this->rewriteStmt($stmt, $scope, $closure_vars);
        }
        return $out;
    }

    private function rewriteStmt(mixed $stmt, array &$scope, array &$closure_vars): mixed {
        if ($stmt instanceof LetNode) {
            if ($stmt->value instanceof ClosureNode) {
                $struct_lit  = $this->desugarClosure($stmt->value, $scope);
                $struct_type = $struct_lit->struct_name;
                $closure_vars[$stmt->name] = $struct_type;
                $scope[$stmt->name]        = $struct_type;
                return new LetNode($stmt->name, $struct_type, $struct_lit, $stmt->mutable, $stmt->line, $stmt->bindings);
            }
            $new_value = $this->rewriteExpr($stmt->value, $scope, $closure_vars);
            $type      = $stmt->type_name ?? $this->inferExprType($stmt->value, $scope);
            $scope[$stmt->name] = $type;
            return new LetNode($stmt->name, $stmt->type_name, $new_value, $stmt->mutable, $stmt->line, $stmt->bindings);
        }

        if ($stmt instanceof AssignNode) {
            return new AssignNode($stmt->name, $this->rewriteExpr($stmt->value, $scope, $closure_vars), $stmt->line);
        }

        if ($stmt instanceof FieldAssignNode) {
            return new FieldAssignNode(
                $this->rewriteExpr($stmt->object, $scope, $closure_vars),
                $stmt->field_name,
                $this->rewriteExpr($stmt->value, $scope, $closure_vars),
                $stmt->line
            );
        }

        if ($stmt instanceof DerefAssignNode) {
            return new DerefAssignNode(
                $this->rewriteExpr($stmt->operand, $scope, $closure_vars),
                $this->rewriteExpr($stmt->value, $scope, $closure_vars),
                $stmt->line
            );
        }

        if ($stmt instanceof ExprStmtNode) {
            return new ExprStmtNode($this->rewriteExpr($stmt->expr, $scope, $closure_vars), $stmt->line);
        }

        if ($stmt instanceof ReturnNode) {
            return new ReturnNode(
                $stmt->value !== null ? $this->rewriteExpr($stmt->value, $scope, $closure_vars) : null,
                $stmt->line
            );
        }

        if ($stmt instanceof PrintlnNode) {
            $parts = [];
            foreach ($stmt->parts as $part) {
                $parts[] = is_string($part) ? $part : $this->rewriteExpr($part, $scope, $closure_vars);
            }
            return new PrintlnNode($parts, $stmt->line);
        }

        if ($stmt instanceof IfNode) {
            $sc1 = $scope; $cv1 = $closure_vars;
            $then_body = $this->rewriteBody($stmt->then_body, $sc1, $cv1);
            $else_body = null;
            if ($stmt->else_body !== null) {
                $sc2 = $scope; $cv2 = $closure_vars;
                $else_body = $this->rewriteBody($stmt->else_body, $sc2, $cv2);
            }
            return new IfNode(
                $this->rewriteExpr($stmt->condition, $scope, $closure_vars),
                $then_body, $else_body, $stmt->line
            );
        }

        if ($stmt instanceof WhileNode) {
            $sc = $scope; $cv = $closure_vars;
            return new WhileNode(
                $this->rewriteExpr($stmt->condition, $scope, $closure_vars),
                $this->rewriteBody($stmt->body, $sc, $cv),
                $stmt->line
            );
        }

        if ($stmt instanceof IfLetNode) {
            $sc1 = $scope; $cv1 = $closure_vars;
            $then_body = $this->rewriteBody($stmt->then_body, $sc1, $cv1);
            $else_body = null;
            if ($stmt->else_body !== null) {
                $sc2 = $scope; $cv2 = $closure_vars;
                $else_body = $this->rewriteBody($stmt->else_body, $sc2, $cv2);
            }
            return new IfLetNode(
                $this->rewriteExpr($stmt->subject, $scope, $closure_vars),
                $stmt->enum_name,
                $stmt->variant_name,
                $stmt->binding,
                $then_body,
                $else_body,
                $stmt->line
            );
        }

        if ($stmt instanceof WhileLetNode) {
            $sc = $scope; $cv = $closure_vars;
            return new WhileLetNode(
                $this->rewriteExpr($stmt->subject, $scope, $closure_vars),
                $stmt->enum_name,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteBody($stmt->body, $sc, $cv),
                $stmt->line
            );
        }

        if ($stmt instanceof LoopNode) {
            $sc = $scope; $cv = $closure_vars;
            return new LoopNode($this->rewriteBody($stmt->body, $sc, $cv), $stmt->line);
        }

        if ($stmt instanceof MatchNode) {
            $subject = $this->rewriteExpr($stmt->subject, $scope, $closure_vars);
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $asc = $scope; $acv = $closure_vars;
                if ($arm->binding !== null) $asc[$arm->binding] = 'i32';
                $arms[] = new MatchArmNode(
                    $arm->is_wildcard, $arm->enum_name, $arm->variant_name,
                    $arm->binding, $this->rewriteBody($arm->body, $asc, $acv), $arm->line
                );
            }
            return new MatchNode($subject, $arms, $stmt->line);
        }

        return $stmt;
    }

    private function rewriteExpr(mixed $expr, array &$scope, array &$closure_vars): mixed {
        if ($expr instanceof ClosureNode) {
            return $this->desugarClosure($expr, $scope);
        }

        if ($expr instanceof CallNode) {
            if (isset($closure_vars[$expr->name])) {
                $struct_type = $closure_vars[$expr->name];
                $fn_name     = $this->fnNameForStruct($struct_type);
                $new_args    = [new BorrowNode(new IdentNode($expr->name, $expr->line), false, $expr->line)];
                foreach ($expr->args as $a) {
                    $new_args[] = $this->rewriteExpr($a, $scope, $closure_vars);
                }
                return new CallNode($fn_name, $new_args, $expr->line);
            }
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a, $scope, $closure_vars);
            return new CallNode($expr->name, $args, $expr->line);
        }

        if ($expr instanceof MethodCallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a, $scope, $closure_vars);
            return new MethodCallNode(
                $this->rewriteExpr($expr->receiver, $scope, $closure_vars),
                $expr->method_name, $args, $expr->line
            );
        }

        if ($expr instanceof BinaryOpNode) {
            return new BinaryOpNode(
                $this->rewriteExpr($expr->left, $scope, $closure_vars),
                $expr->op,
                $this->rewriteExpr($expr->right, $scope, $closure_vars),
                $expr->line
            );
        }

        if ($expr instanceof UnaryOpNode) {
            return new UnaryOpNode(
                $expr->op,
                $this->rewriteExpr($expr->operand, $scope, $closure_vars),
                $expr->line
            );
        }

        if ($expr instanceof BorrowNode) {
            return new BorrowNode(
                $this->rewriteExpr($expr->operand, $scope, $closure_vars),
                $expr->mutable, $expr->line
            );
        }

        if ($expr instanceof DerefNode) {
            return new DerefNode($this->rewriteExpr($expr->operand, $scope, $closure_vars), $expr->line);
        }
        if ($expr instanceof CastNode) {
            return new CastNode($this->rewriteExpr($expr->expr, $scope, $closure_vars), $expr->target_type, $expr->line);
        }

        if ($expr instanceof FieldAccessNode) {
            return new FieldAccessNode(
                $this->rewriteExpr($expr->object, $scope, $closure_vars),
                $expr->field_name, $expr->line
            );
        }

        if ($expr instanceof IndexNode) {
            return new IndexNode(
                $this->rewriteExpr($expr->object, $scope, $closure_vars),
                $this->rewriteExpr($expr->index, $scope, $closure_vars),
                $expr->line
            );
        }

        if ($expr instanceof IfNode) {
            $sc1 = $scope; $cv1 = $closure_vars;
            $then_body = $this->rewriteBody($expr->then_body, $sc1, $cv1);
            $else_body = null;
            if ($expr->else_body !== null) {
                $sc2 = $scope; $cv2 = $closure_vars;
                $else_body = $this->rewriteBody($expr->else_body, $sc2, $cv2);
            }
            return new IfNode(
                $this->rewriteExpr($expr->condition, $scope, $closure_vars),
                $then_body, $else_body, $expr->line
            );
        }

        if ($expr instanceof IfLetNode) {
            $sc1 = $scope; $cv1 = $closure_vars;
            $then_body = $this->rewriteBody($expr->then_body, $sc1, $cv1);
            $else_body = null;
            if ($expr->else_body !== null) {
                $sc2 = $scope; $cv2 = $closure_vars;
                $else_body = $this->rewriteBody($expr->else_body, $sc2, $cv2);
            }
            return new IfLetNode(
                $this->rewriteExpr($expr->subject, $scope, $closure_vars),
                $expr->enum_name,
                $expr->variant_name,
                $expr->binding,
                $then_body,
                $else_body,
                $expr->line
            );
        }

        if ($expr instanceof MatchNode) {
            return $this->rewriteStmt($expr, $scope, $closure_vars);
        }

        return $expr;
    }

    // ── core desugaring ────────────────────────────────────────────────────

    private function desugarClosure(ClosureNode $node, array $scope): StructLitNode {
        $n           = $this->counter++;
        $struct_name = "__Closure_{$n}";
        $fn_name     = "__closure_{$n}_call";

        $param_names = array_map(fn($p) => $p['name'], $node->params);
        $free_names  = $this->collectFreeVars($node->body, $param_names);

        // Build capture map: name → type (only vars present in the outer scope)
        $captures = [];
        foreach ($free_names as $vname) {
            if (isset($scope[$vname])) {
                $captures[$vname] = $scope[$vname];
            }
        }

        // Synthesize struct (always at least one byte on the stack — add _dummy when empty)
        $fields = [];
        foreach ($captures as $vname => $vtype) {
            $fields[] = ['name' => $vname, 'type' => $vtype];
        }
        if (empty($fields)) {
            $fields[] = ['name' => '_dummy', 'type' => 'i32'];
        }
        $this->new_structs[] = new StructDefNode($struct_name, $fields, $node->line);

        // Rewrite closure body: replace captured idents with __env.field
        $fn_body = $this->rewriteClosureBody($node->body, $captures, $param_names);

        // Infer return type from original body + combined scope
        $combined_scope = $captures;
        foreach ($node->params as $p) {
            $combined_scope[$p['name']] = $p['type'];
        }
        $return_type = $this->inferBodyReturnType($node->body, $combined_scope);

        // Synthesize call function: fn __closure_N_call(__env: &Struct, params...) -> R
        $fn_params = [['name' => '__env', 'type' => "&{$struct_name}"]];
        foreach ($node->params as $p) {
            $fn_params[] = ['name' => $p['name'], 'type' => $p['type']];
        }
        $this->new_fns[] = new FunctionNode($fn_name, $fn_params, $return_type, $fn_body, $node->line);

        // Build the struct literal (captures as field values)
        $field_exprs = [];
        foreach ($captures as $vname => $_) {
            $field_exprs[] = ['name' => $vname, 'value' => new IdentNode($vname, $node->line)];
        }
        if (empty($captures)) {
            $field_exprs[] = ['name' => '_dummy', 'value' => new IntLitNode(0, $node->line)];
        }
        return new StructLitNode($struct_name, $field_exprs, $node->line);
    }

    private function fnNameForStruct(string $struct_type): string {
        $n = substr($struct_type, strlen('__Closure_'));
        return "__closure_{$n}_call";
    }

    // ── free-variable analysis ─────────────────────────────────────────────

    private function collectFreeVars(array $body, array $bound_names): array {
        $free  = [];
        $local = $bound_names;
        foreach ($body as $stmt) {
            $this->scanStmtFree($stmt, $local, $free);
            if ($stmt instanceof LetNode) {
                $local[] = $stmt->name;
            }
        }
        return array_values(array_unique($free));
    }

    private function scanStmtFree(mixed $stmt, array $local, array &$free): void {
        if ($stmt instanceof LetNode) {
            $this->scanExprFree($stmt->value, $local, $free);
        } elseif ($stmt instanceof AssignNode) {
            $this->scanExprFree($stmt->value, $local, $free);
            if (!in_array($stmt->name, $local)) $free[] = $stmt->name;
        } elseif ($stmt instanceof ReturnNode && $stmt->value !== null) {
            $this->scanExprFree($stmt->value, $local, $free);
        } elseif ($stmt instanceof ExprStmtNode) {
            $this->scanExprFree($stmt->expr, $local, $free);
        } elseif ($stmt instanceof PrintlnNode) {
            foreach ($stmt->parts as $p) {
                if (!is_string($p)) $this->scanExprFree($p, $local, $free);
            }
        } elseif ($stmt instanceof IfNode) {
            $this->scanExprFree($stmt->condition, $local, $free);
            $lc = $local;
            foreach ($stmt->then_body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
            if ($stmt->else_body) {
                $lc2 = $local;
                foreach ($stmt->else_body as $s) { $this->scanStmtFree($s, $lc2, $free); if ($s instanceof LetNode) $lc2[] = $s->name; }
            }
        } elseif ($stmt instanceof WhileNode) {
            $this->scanExprFree($stmt->condition, $local, $free);
            $lc = $local;
            foreach ($stmt->body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
        } elseif ($stmt instanceof IfLetNode) {
            $this->scanExprFree($stmt->subject, $local, $free);
            $lc = $local;
            if ($stmt->binding !== null) $lc[] = $stmt->binding;
            foreach ($stmt->then_body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
            if ($stmt->else_body) {
                $lc2 = $local;
                foreach ($stmt->else_body as $s) { $this->scanStmtFree($s, $lc2, $free); if ($s instanceof LetNode) $lc2[] = $s->name; }
            }
        } elseif ($stmt instanceof WhileLetNode) {
            $this->scanExprFree($stmt->subject, $local, $free);
            $lc = $local;
            if ($stmt->binding !== null) $lc[] = $stmt->binding;
            foreach ($stmt->body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
        } elseif ($stmt instanceof LoopNode) {
            $lc = $local;
            foreach ($stmt->body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
        } elseif ($stmt instanceof MatchNode) {
            $this->scanExprFree($stmt->subject, $local, $free);
            foreach ($stmt->arms as $arm) {
                $lc = $local;
                if ($arm->binding !== null) $lc[] = $arm->binding;
                foreach ($arm->body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
            }
        }
    }

    private function scanExprFree(mixed $expr, array $local, array &$free): void {
        if ($expr instanceof IdentNode) {
            if (!in_array($expr->name, $local)) $free[] = $expr->name;
        } elseif ($expr instanceof BinaryOpNode) {
            $this->scanExprFree($expr->left, $local, $free);
            $this->scanExprFree($expr->right, $local, $free);
        } elseif ($expr instanceof UnaryOpNode) {
            $this->scanExprFree($expr->operand, $local, $free);
        } elseif ($expr instanceof CallNode) {
            foreach ($expr->args as $a) $this->scanExprFree($a, $local, $free);
        } elseif ($expr instanceof MethodCallNode) {
            $this->scanExprFree($expr->receiver, $local, $free);
            foreach ($expr->args as $a) $this->scanExprFree($a, $local, $free);
        } elseif ($expr instanceof FieldAccessNode) {
            $this->scanExprFree($expr->object, $local, $free);
        } elseif ($expr instanceof BorrowNode) {
            $this->scanExprFree($expr->operand, $local, $free);
        } elseif ($expr instanceof DerefNode) {
            $this->scanExprFree($expr->operand, $local, $free);
        } elseif ($expr instanceof IndexNode) {
            $this->scanExprFree($expr->object, $local, $free);
            $this->scanExprFree($expr->index, $local, $free);
        } elseif ($expr instanceof IfNode) {
            $this->scanExprFree($expr->condition, $local, $free);
            $lc = $local;
            foreach ($expr->then_body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
            if ($expr->else_body) {
                $lc2 = $local;
                foreach ($expr->else_body as $s) { $this->scanStmtFree($s, $lc2, $free); if ($s instanceof LetNode) $lc2[] = $s->name; }
            }
        } elseif ($expr instanceof IfLetNode) {
            $this->scanExprFree($expr->subject, $local, $free);
            $lc = $local;
            if ($expr->binding !== null) $lc[] = $expr->binding;
            foreach ($expr->then_body as $s) { $this->scanStmtFree($s, $lc, $free); if ($s instanceof LetNode) $lc[] = $s->name; }
            if ($expr->else_body) {
                $lc2 = $local;
                foreach ($expr->else_body as $s) { $this->scanStmtFree($s, $lc2, $free); if ($s instanceof LetNode) $lc2[] = $s->name; }
            }
        } elseif ($expr instanceof MatchNode) {
            $this->scanStmtFree($expr, $local, $free);
        }
        // IntLitNode, BoolLitNode, StrSliceNode, StringFromNode, RangeNode — no free vars
    }

    // ── closure-body rewriting ─────────────────────────────────────────────

    /** Replace captured variable idents with __env.field inside the closure body. */
    private function rewriteClosureBody(array $stmts, array $captures, array $local_bindings): array {
        $out = [];
        $lb  = $local_bindings;
        foreach ($stmts as $stmt) {
            $out[] = $this->rewriteClosureStmt($stmt, $captures, $lb);
            if ($stmt instanceof LetNode) $lb[] = $stmt->name;
        }
        return $out;
    }

    private function rewriteClosureStmt(mixed $stmt, array $captures, array $lb): mixed {
        if ($stmt instanceof LetNode) {
            return new LetNode(
                $stmt->name, $stmt->type_name,
                $this->rewriteClosureExpr($stmt->value, $captures, $lb),
                $stmt->mutable, $stmt->line, $stmt->bindings
            );
        }
        if ($stmt instanceof AssignNode) {
            return new AssignNode($stmt->name, $this->rewriteClosureExpr($stmt->value, $captures, $lb), $stmt->line);
        }
        if ($stmt instanceof ReturnNode) {
            return new ReturnNode(
                $stmt->value !== null ? $this->rewriteClosureExpr($stmt->value, $captures, $lb) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof ExprStmtNode) {
            return new ExprStmtNode($this->rewriteClosureExpr($stmt->expr, $captures, $lb), $stmt->line);
        }
        if ($stmt instanceof PrintlnNode) {
            $parts = [];
            foreach ($stmt->parts as $p) {
                $parts[] = is_string($p) ? $p : $this->rewriteClosureExpr($p, $captures, $lb);
            }
            return new PrintlnNode($parts, $stmt->line);
        }
        if ($stmt instanceof IfNode) {
            return new IfNode(
                $this->rewriteClosureExpr($stmt->condition, $captures, $lb),
                $this->rewriteClosureBody($stmt->then_body, $captures, $lb),
                $stmt->else_body !== null ? $this->rewriteClosureBody($stmt->else_body, $captures, $lb) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileNode) {
            return new WhileNode(
                $this->rewriteClosureExpr($stmt->condition, $captures, $lb),
                $this->rewriteClosureBody($stmt->body, $captures, $lb),
                $stmt->line
            );
        }
        if ($stmt instanceof IfLetNode) {
            return new IfLetNode(
                $this->rewriteClosureExpr($stmt->subject, $captures, $lb),
                $stmt->enum_name,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteClosureBody($stmt->then_body, $captures, $lb),
                $stmt->else_body !== null ? $this->rewriteClosureBody($stmt->else_body, $captures, $lb) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileLetNode) {
            return new WhileLetNode(
                $this->rewriteClosureExpr($stmt->subject, $captures, $lb),
                $stmt->enum_name,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteClosureBody($stmt->body, $captures, $lb),
                $stmt->line
            );
        }
        if ($stmt instanceof LoopNode) {
            return new LoopNode($this->rewriteClosureBody($stmt->body, $captures, $lb), $stmt->line);
        }
        if ($stmt instanceof BreakNode || $stmt instanceof ContinueNode) {
            return $stmt;
        }
        return $stmt;
    }

    private function rewriteClosureExpr(mixed $expr, array $captures, array $lb): mixed {
        if ($expr instanceof IdentNode) {
            if (isset($captures[$expr->name]) && !in_array($expr->name, $lb)) {
                return new FieldAccessNode(new IdentNode('__env', $expr->line), $expr->name, $expr->line);
            }
            return $expr;
        }
        if ($expr instanceof BinaryOpNode) {
            return new BinaryOpNode(
                $this->rewriteClosureExpr($expr->left, $captures, $lb),
                $expr->op,
                $this->rewriteClosureExpr($expr->right, $captures, $lb),
                $expr->line
            );
        }
        if ($expr instanceof UnaryOpNode) {
            return new UnaryOpNode($expr->op, $this->rewriteClosureExpr($expr->operand, $captures, $lb), $expr->line);
        }
        if ($expr instanceof CallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteClosureExpr($a, $captures, $lb);
            return new CallNode($expr->name, $args, $expr->line);
        }
        if ($expr instanceof MethodCallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteClosureExpr($a, $captures, $lb);
            return new MethodCallNode(
                $this->rewriteClosureExpr($expr->receiver, $captures, $lb),
                $expr->method_name, $args, $expr->line
            );
        }
        if ($expr instanceof FieldAccessNode) {
            return new FieldAccessNode(
                $this->rewriteClosureExpr($expr->object, $captures, $lb),
                $expr->field_name, $expr->line
            );
        }
        if ($expr instanceof BorrowNode) {
            return new BorrowNode($this->rewriteClosureExpr($expr->operand, $captures, $lb), $expr->mutable, $expr->line);
        }
        if ($expr instanceof DerefNode) {
            return new DerefNode($this->rewriteClosureExpr($expr->operand, $captures, $lb), $expr->line);
        }
        if ($expr instanceof CastNode) {
            return new CastNode($this->rewriteClosureExpr($expr->expr, $captures, $lb), $expr->target_type, $expr->line);
        }
        if ($expr instanceof IndexNode) {
            return new IndexNode(
                $this->rewriteClosureExpr($expr->object, $captures, $lb),
                $this->rewriteClosureExpr($expr->index, $captures, $lb),
                $expr->line
            );
        }
        if ($expr instanceof IfNode) {
            return new IfNode(
                $this->rewriteClosureExpr($expr->condition, $captures, $lb),
                $this->rewriteClosureBody($expr->then_body, $captures, $lb),
                $expr->else_body !== null ? $this->rewriteClosureBody($expr->else_body, $captures, $lb) : null,
                $expr->line
            );
        }
        if ($expr instanceof IfLetNode) {
            return new IfLetNode(
                $this->rewriteClosureExpr($expr->subject, $captures, $lb),
                $expr->enum_name,
                $expr->variant_name,
                $expr->binding,
                $this->rewriteClosureBody($expr->then_body, $captures, $lb),
                $expr->else_body !== null ? $this->rewriteClosureBody($expr->else_body, $captures, $lb) : null,
                $expr->line
            );
        }
        return $expr;
    }

    // ── type helpers ───────────────────────────────────────────────────────

    private function inferBodyReturnType(array $body, array $scope): string {
        if (empty($body)) return 'i32';
        $last = end($body);
        if ($last instanceof ReturnNode && $last->value !== null) {
            return $this->inferExprType($last->value, $scope);
        }
        return 'i32';
    }

    private function inferExprType(mixed $expr, array $scope): string {
        if ($expr instanceof IntLitNode)  return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StrSliceNode || $expr instanceof StringFromNode) return '&str';
        if ($expr instanceof IdentNode)   return $scope[$expr->name] ?? 'i32';
        if ($expr instanceof BinaryOpNode) {
            return match($expr->op) {
                '+', '-', '*', '/', '%' => 'i32',
                '==', '!=', '<', '>', '<=', '>=', '&&', '||' => 'bool',
                default => 'i32',
            };
        }
        if ($expr instanceof UnaryOpNode) return $expr->op === '!' ? 'bool' : 'i32';
        if ($expr instanceof IfNode) {
            if (!empty($expr->then_body)) {
                $last = end($expr->then_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->inferExprType($last->value, $scope);
                }
            }
            return 'i32';
        }
        if ($expr instanceof IfLetNode) {
            if (!empty($expr->then_body)) {
                $last = end($expr->then_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->inferExprType($last->value, $scope);
                }
            }
            return 'i32';
        }
        return 'i32';
    }
}
