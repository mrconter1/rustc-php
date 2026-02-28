<?php

require_once __DIR__ . '/Parser.php';

class CodeGen {
    private string $code = '';
    private array  $vars = []; // name => stack offset
    private int    $stack_size = 0;

    public function generate(ProgramNode $program): string {
        foreach ($program->functions as $fn) {
            if ($fn->name === 'main') {
                $this->generateMain($fn);
            } else {
                throw new RuntimeException("Unknown function '{$fn->name}' on line {$fn->line}");
            }
        }
        return $this->code;
    }

    private function generateMain(FunctionNode $fn): void {
        $this->vars       = [];
        $this->stack_size = 0;
        $this->code       = '';

        $this->collectVars($fn->body);

        if ($this->stack_size > 0) {
            // push rbp
            $this->emit("\x55");
            // mov rbp, rsp
            $this->emit("\x48\x89\xE5");
            // sub rsp, N (aligned to 16)
            $aligned = ($this->stack_size + 15) & ~15;
            $this->emit("\x48\x83\xEC" . pack('C', $aligned));
        }

        foreach ($fn->body as $stmt) {
            $this->generateStmt($stmt);
        }

        // implicit exit(0) at end of main
        $this->emitExit(0);
    }

    private function collectVars(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) {
                $this->stack_size += 8;
                $this->vars[$stmt->name] = $this->stack_size;
            }
        }
    }

    private function generateStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            $this->generateExpr($stmt->value);
            $offset = $this->vars[$stmt->name];
            // mov [rbp - offset], rax
            $this->emit("\x48\x89\x45" . pack('c', -$offset));
            return;
        }

        if ($stmt instanceof ExprStmtNode) {
            if ($stmt->expr instanceof CallNode && $stmt->expr->name === 'exit') {
                if (count($stmt->expr->args) !== 1) {
                    throw new RuntimeException("exit() takes exactly 1 argument on line {$stmt->line}");
                }
                $this->generateExpr($stmt->expr->args[0]);
                // mov rdi, rax
                $this->emit("\x48\x89\xC7");
                // mov rax, 60
                $this->emit("\x48\xC7\xC0\x3C\x00\x00\x00");
                // syscall
                $this->emit("\x0F\x05");
                return;
            }
            $this->generateExpr($stmt->expr);
            return;
        }

        throw new RuntimeException("Unknown statement type: " . get_class($stmt));
    }

    private function generateExpr(mixed $expr): void {
        if ($expr instanceof IntLitNode) {
            // mov rax, imm32
            $this->emit("\x48\xC7\xC0" . pack('V', $expr->value));
            return;
        }

        if ($expr instanceof IdentNode) {
            if (!isset($this->vars[$expr->name])) {
                throw new RuntimeException("Undefined variable '{$expr->name}' on line {$expr->line}");
            }
            $offset = $this->vars[$expr->name];
            // mov rax, [rbp - offset]
            $this->emit("\x48\x8B\x45" . pack('c', -$offset));
            return;
        }

        if ($expr instanceof BinaryOpNode) {
            $this->generateExpr($expr->left);
            // push rax (save left)
            $this->emit("\x50");
            $this->generateExpr($expr->right);
            // mov rcx, rax (right in rcx)
            $this->emit("\x48\x89\xC1");
            // pop rax (left in rax)
            $this->emit("\x58");

            switch ($expr->op) {
                case '+':
                    // add rax, rcx
                    $this->emit("\x48\x01\xC8");
                    break;
                case '-':
                    // sub rax, rcx
                    $this->emit("\x48\x29\xC8");
                    break;
                case '*':
                    // imul rax, rcx
                    $this->emit("\x48\x0F\xAF\xC1");
                    break;
                case '/':
                    // cqo (sign-extend rax into rdx:rax)
                    $this->emit("\x48\x99");
                    // idiv rcx
                    $this->emit("\x48\xF7\xF9");
                    break;
                default:
                    throw new RuntimeException("Unknown operator '{$expr->op}' on line {$expr->line}");
            }
            return;
        }

        if ($expr instanceof CallNode) {
            throw new RuntimeException("Function call '{$expr->name}' not supported in expression context on line {$expr->line}");
        }

        throw new RuntimeException("Unknown expression type: " . get_class($expr));
    }

    private function emitExit(int $code): void {
        // mov rax, 60
        $this->emit("\x48\xC7\xC0\x3C\x00\x00\x00");
        if ($code === 0) {
            // xor rdi, rdi
            $this->emit("\x48\x31\xFF");
        } else {
            // mov rdi, imm32
            $this->emit("\x48\xC7\xC7" . pack('V', $code));
        }
        // syscall
        $this->emit("\x0F\x05");
    }

    private function emit(string $bytes): void {
        $this->code .= $bytes;
    }
}
