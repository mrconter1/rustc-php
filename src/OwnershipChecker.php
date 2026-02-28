<?php

require_once __DIR__ . '/Ast.php';

class OwnershipChecker {
    private array $vars = [];
    private array $func_sigs = []; // name => ['params' => [...], 'return_type' => string|null]
    private ?string $current_return_type = null;

    public function check(ProgramNode $program): void {
        foreach ($program->functions as $fn) {
            $this->func_sigs[$fn->name] = [
                'params' => $fn->params,
                'return_type' => $fn->return_type,
            ];
        }

        foreach ($program->functions as $fn) {
            $this->checkFunction($fn);
        }
    }

    private function checkFunction(FunctionNode $fn): void {
        $this->vars = [];
        $this->current_return_type = $fn->return_type;
        foreach ($fn->params as $param) {
            $this->vars[$param['name']] = [
                'type' => $param['type'],
                'state' => 'owned',
                'mutable' => false,
                'moved_to' => null,
                'moved_line' => null,
            ];
        }
        $this->checkBody($fn->body);
    }

    private function checkBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->checkStmt($stmt);
        }
    }

    private function checkStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            $this->checkExpr($stmt->value);

            $expr_type = $this->exprType($stmt->value);
            $type = $stmt->type_name ?? $expr_type;

            if ($stmt->type_name !== null && $stmt->type_name !== $expr_type) {
                throw new RuntimeException(
                    "Type mismatch: expected '{$stmt->type_name}', got '$expr_type' on line {$stmt->line}"
                );
            }

            if ($stmt->value instanceof IdentNode && !$this->isCopy($type)) {
                $src = $stmt->value->name;
                if (isset($this->vars[$src])) {
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = $stmt->name;
                    $this->vars[$src]['moved_line'] = $stmt->line;
                }
            }

            $this->vars[$stmt->name] = [
                'type' => $type,
                'state' => 'owned',
                'mutable' => $stmt->mutable,
                'moved_to' => null,
                'moved_line' => null,
            ];
            return;
        }

        if ($stmt instanceof AssignNode) {
            if (!isset($this->vars[$stmt->name])) {
                throw new RuntimeException("Undefined variable '{$stmt->name}' on line {$stmt->line}");
            }
            $var = $this->vars[$stmt->name];
            if (!$var['mutable']) {
                throw new RuntimeException(
                    "Cannot assign twice to immutable variable '{$stmt->name}' on line {$stmt->line}"
                );
            }

            $this->checkExpr($stmt->value);

            $expr_type = $this->exprType($stmt->value);
            if ($var['type'] !== $expr_type) {
                throw new RuntimeException(
                    "Type mismatch: cannot assign '$expr_type' to '{$var['type']}' variable '{$stmt->name}' on line {$stmt->line}"
                );
            }

            if ($stmt->value instanceof IdentNode && !$this->isCopy($expr_type)) {
                $src = $stmt->value->name;
                if (isset($this->vars[$src])) {
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = $stmt->name;
                    $this->vars[$src]['moved_line'] = $stmt->line;
                }
            }

            if ($var['state'] === 'moved') {
                $this->vars[$stmt->name]['state'] = 'owned';
                $this->vars[$stmt->name]['moved_to'] = null;
                $this->vars[$stmt->name]['moved_line'] = null;
            }
            return;
        }

        if ($stmt instanceof ReturnNode) {
            if ($stmt->value !== null) {
                $this->checkExpr($stmt->value);

                if ($this->current_return_type !== null) {
                    $expr_type = $this->exprType($stmt->value);
                    if ($expr_type !== $this->current_return_type) {
                        throw new RuntimeException(
                            "Type mismatch: expected return type '{$this->current_return_type}', got '$expr_type' on line {$stmt->line}"
                        );
                    }
                }
            }
            return;
        }

        if ($stmt instanceof ExprStmtNode) {
            $this->checkExpr($stmt->expr);
            return;
        }

        if ($stmt instanceof PrintlnNode) {
            foreach ($stmt->parts as $part) {
                if (!is_string($part)) {
                    $this->checkExpr($part);
                }
            }
            return;
        }

        if ($stmt instanceof WhileNode) {
            $this->checkExpr($stmt->condition);
            $saved = $this->vars;
            $this->checkBody($stmt->body);
            $this->vars = $this->mergeStates($saved, $this->vars);
            return;
        }

        if ($stmt instanceof LoopNode) {
            $saved = $this->vars;
            $this->checkBody($stmt->body);
            $this->vars = $this->mergeStates($saved, $this->vars);
            return;
        }

        if ($stmt instanceof BreakNode || $stmt instanceof ContinueNode) {
            return;
        }

        if ($stmt instanceof IfNode) {
            $this->checkExpr($stmt->condition);

            $saved = $this->vars;
            $this->checkBody($stmt->then_body);
            $after_then = $this->vars;

            if ($stmt->else_body !== null) {
                $this->vars = $saved;
                $this->checkBody($stmt->else_body);
                $after_else = $this->vars;

                foreach ($after_else as $name => &$info) {
                    if (isset($after_then[$name]) && $after_then[$name]['state'] === 'moved') {
                        $info['state'] = 'moved';
                        $info['moved_to'] = $after_then[$name]['moved_to'];
                        $info['moved_line'] = $after_then[$name]['moved_line'];
                    }
                }
                unset($info);
                $this->vars = $after_else;
            } else {
                $this->vars = $this->mergeStates($saved, $after_then);
            }
            return;
        }
    }

    private function checkExpr(mixed $expr): void {
        if ($expr instanceof IdentNode) {
            if (isset($this->vars[$expr->name]) && $this->vars[$expr->name]['state'] === 'moved') {
                $v = $this->vars[$expr->name];
                $msg = "Use of moved value '{$expr->name}' on line {$expr->line}";
                if ($v['moved_to'] !== null) {
                    $msg .= " (moved to '{$v['moved_to']}' on line {$v['moved_line']})";
                }
                throw new RuntimeException($msg);
            }
            return;
        }

        if ($expr instanceof BorrowNode) {
            $this->checkExpr($expr->operand);
            return;
        }

        if ($expr instanceof UnaryOpNode) {
            $this->checkExpr($expr->operand);
            return;
        }

        if ($expr instanceof BinaryOpNode) {
            $this->checkExpr($expr->left);
            $this->checkExpr($expr->right);
            return;
        }

        if ($expr instanceof IfNode) {
            $this->checkStmt($expr);
            return;
        }

        if ($expr instanceof CallNode) {
            foreach ($expr->args as $arg) {
                $this->checkExpr($arg);
            }

            if ($expr->name !== 'exit' && isset($this->func_sigs[$expr->name])) {
                $sig = $this->func_sigs[$expr->name];
                $expected = count($sig['params']);
                $got = count($expr->args);
                if ($got !== $expected) {
                    throw new RuntimeException(
                        "Function '{$expr->name}' expects $expected arguments, got $got on line {$expr->line}"
                    );
                }
                foreach ($expr->args as $i => $arg) {
                    $arg_type = $this->exprType($arg);
                    $param_type = $sig['params'][$i]['type'];
                    if ($arg_type !== $param_type) {
                        throw new RuntimeException(
                            "Type mismatch: argument " . ($i + 1) . " of '{$expr->name}' expects '$param_type', got '$arg_type' on line {$expr->line}"
                        );
                    }
                }
            }
            return;
        }
    }

    private function mergeStates(array $before, array $after): array {
        $merged = $after;
        foreach ($merged as $name => &$info) {
            if (isset($before[$name]) && $before[$name]['state'] === 'owned' && $info['state'] === 'moved') {
                $info['state'] = 'moved';
            }
        }
        unset($info);
        return $merged;
    }

    private function isCopy(string $type): bool {
        if (str_starts_with($type, '&')) return true;
        return in_array($type, ['i32', 'bool']);
    }

    private function exprType(mixed $expr): string {
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof IdentNode) {
            return $this->vars[$expr->name]['type'] ?? 'i32';
        }
        if ($expr instanceof BorrowNode) {
            if ($expr->operand instanceof IdentNode) {
                $inner = $this->vars[$expr->operand->name]['type'] ?? 'i32';
                return '&' . $inner;
            }
            return '&i32';
        }
        if ($expr instanceof UnaryOpNode) {
            if ($expr->op === '!') return 'bool';
            return $this->exprType($expr->operand);
        }
        if ($expr instanceof IfNode) {
            if (!empty($expr->then_body)) {
                $last = end($expr->then_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->exprType($last->value);
                }
            }
            return 'i32';
        }
        if ($expr instanceof CallNode) {
            if (isset($this->func_sigs[$expr->name])) {
                return $this->func_sigs[$expr->name]['return_type'] ?? 'i32';
            }
            return 'i32';
        }
        if ($expr instanceof BinaryOpNode) {
            if (in_array($expr->op, ['==', '!=', '<', '>', '<=', '>=', '&&', '||'])) {
                return 'bool';
            }
            return 'i32';
        }
        return 'i32';
    }
}
