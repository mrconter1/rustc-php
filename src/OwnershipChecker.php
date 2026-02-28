<?php

require_once __DIR__ . '/Ast.php';

class OwnershipChecker {
    private array $vars = []; // name => ['type' => string, 'state' => 'owned'|'moved', 'moved_to' => string|null, 'moved_line' => int|null]

    public function check(ProgramNode $program): void {
        foreach ($program->functions as $fn) {
            $this->checkFunction($fn);
        }
    }

    private function checkFunction(FunctionNode $fn): void {
        $this->vars = [];
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

            $type = $stmt->type_name ?? $this->exprType($stmt->value);

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
                'moved_to' => null,
                'moved_line' => null,
            ];
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

        if ($expr instanceof BinaryOpNode) {
            $this->checkExpr($expr->left);
            $this->checkExpr($expr->right);
            return;
        }

        if ($expr instanceof CallNode) {
            foreach ($expr->args as $arg) {
                $this->checkExpr($arg);
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
        return in_array($type, ['i32', 'bool']);
    }

    private function exprType(mixed $expr): string {
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof IdentNode) {
            return $this->vars[$expr->name]['type'] ?? 'i32';
        }
        if ($expr instanceof BinaryOpNode) return 'i32';
        return 'i32';
    }
}
