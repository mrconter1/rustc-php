<?php

require_once __DIR__ . '/Parser.php';

class CodeGen {
    private string $code = '';
    private array  $vars = [];
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

        $this->generateBody($fn->body);

        // implicit exit(0)
        $this->emitExit(0);
    }

    private function collectVars(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) {
                $this->stack_size += 8;
                $this->vars[$stmt->name] = $this->stack_size;
            }
            if ($stmt instanceof IfNode) {
                $this->collectVars($stmt->then_body);
                if ($stmt->else_body !== null) {
                    $this->collectVars($stmt->else_body);
                }
            }
        }
    }

    private function generateBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->generateStmt($stmt);
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

        if ($stmt instanceof IfNode) {
            $this->generateIf($stmt);
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

    private function generateIf(IfNode $node): void {
        $this->generateExpr($node->condition);
        // test rax, rax
        $this->emit("\x48\x85\xC0");

        if ($node->else_body === null) {
            // jz end (32-bit relative, backpatched)
            $this->emit("\x0F\x84");
            $jz_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            $this->generateBody($node->then_body);

            $this->patch32($jz_patch, strlen($this->code) - $jz_patch - 4);
        } else {
            // jz else (32-bit relative, backpatched)
            $this->emit("\x0F\x84");
            $jz_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            $this->generateBody($node->then_body);

            // jmp end (32-bit relative, backpatched)
            $this->emit("\xE9");
            $jmp_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            // patch jz to jump here (else block)
            $this->patch32($jz_patch, strlen($this->code) - $jz_patch - 4);

            $this->generateBody($node->else_body);

            // patch jmp to jump here (end)
            $this->patch32($jmp_patch, strlen($this->code) - $jmp_patch - 4);
        }
    }

    private function generateExpr(mixed $expr): void {
        if ($expr instanceof IntLitNode) {
            // mov rax, imm32
            $this->emit("\x48\xC7\xC0" . pack('V', $expr->value));
            return;
        }

        if ($expr instanceof BoolLitNode) {
            $val = $expr->value ? 1 : 0;
            $this->emit("\x48\xC7\xC0" . pack('V', $val));
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
            // push rax
            $this->emit("\x50");
            $this->generateExpr($expr->right);
            // mov rcx, rax (right in rcx)
            $this->emit("\x48\x89\xC1");
            // pop rax (left in rax)
            $this->emit("\x58");

            switch ($expr->op) {
                case '+':
                    $this->emit("\x48\x01\xC8"); // add rax, rcx
                    break;
                case '-':
                    $this->emit("\x48\x29\xC8"); // sub rax, rcx
                    break;
                case '*':
                    $this->emit("\x48\x0F\xAF\xC1"); // imul rax, rcx
                    break;
                case '/':
                    $this->emit("\x48\x99");     // cqo
                    $this->emit("\x48\xF7\xF9"); // idiv rcx
                    break;
                case '==':
                    $this->emitComparison("\x0F\x94"); // sete al
                    break;
                case '!=':
                    $this->emitComparison("\x0F\x95"); // setne al
                    break;
                case '<':
                    $this->emitComparison("\x0F\x9C"); // setl al
                    break;
                case '>':
                    $this->emitComparison("\x0F\x9F"); // setg al
                    break;
                case '<=':
                    $this->emitComparison("\x0F\x9E"); // setle al
                    break;
                case '>=':
                    $this->emitComparison("\x0F\x9D"); // setge al
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

    private function emitComparison(string $setcc_opcode): void {
        // cmp rax, rcx
        $this->emit("\x48\x39\xC8");
        // setCC al
        $this->emit($setcc_opcode . "\xC0");
        // movzx rax, al
        $this->emit("\x48\x0F\xB6\xC0");
    }

    private function emitExit(int $code): void {
        $this->emit("\x48\xC7\xC0\x3C\x00\x00\x00"); // mov rax, 60
        if ($code === 0) {
            $this->emit("\x48\x31\xFF"); // xor rdi, rdi
        } else {
            $this->emit("\x48\xC7\xC7" . pack('V', $code)); // mov rdi, imm32
        }
        $this->emit("\x0F\x05"); // syscall
    }

    private function emit(string $bytes): void {
        $this->code .= $bytes;
    }

    private function patch32(int $offset, int $value): void {
        $packed = pack('V', $value);
        for ($i = 0; $i < 4; $i++) {
            $this->code[$offset + $i] = $packed[$i];
        }
    }
}
