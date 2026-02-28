<?php

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/Elf.php';

class CodeGen {
    private string $code = '';
    private string $data = '';
    private array  $data_patches = [];
    private array  $vars = []; // name => ['offset' => int, 'type' => string]
    private int    $stack_size = 0;
    private int    $code_base_addr;

    public function generate(ProgramNode $program, int $code_base_addr): string {
        $this->code_base_addr = $code_base_addr;

        foreach ($program->functions as $fn) {
            if ($fn->name === 'main') {
                $this->generateMain($fn);
            } else {
                throw new RuntimeException("Unknown function '{$fn->name}' on line {$fn->line}");
            }
        }

        $this->patchDataAddresses();
        return $this->code . $this->data;
    }

    private function patchDataAddresses(): void {
        $data_base = $this->code_base_addr + strlen($this->code);
        foreach ($this->data_patches as [$code_pos, $data_offset]) {
            $addr = $data_base + $data_offset;
            $packed = pack('P', $addr);
            for ($i = 0; $i < 8; $i++) {
                $this->code[$code_pos + $i] = $packed[$i];
            }
        }
    }

    private function addData(string $str): int {
        $offset = strlen($this->data);
        $this->data .= $str;
        return $offset;
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

    private function generateMain(FunctionNode $fn): void {
        $this->vars       = [];
        $this->stack_size = 0;
        $this->code       = '';
        $this->data       = '';
        $this->data_patches = [];

        $this->collectVars($fn->body);

        if ($this->stack_size > 0) {
            $this->emit("\x55");                                     // push rbp
            $this->emit("\x48\x89\xE5");                             // mov rbp, rsp
            $aligned = ($this->stack_size + 15) & ~15;
            $this->emit("\x48\x83\xEC" . pack('C', $aligned));       // sub rsp, N
        }

        $this->generateBody($fn->body);
        $this->emitExit(0);
    }

    private function collectVars(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) {
                $type = $stmt->type_name ?? $this->exprType($stmt->value);
                $size = ($type === 'String') ? 16 : 8;
                $this->stack_size += $size;
                $this->vars[$stmt->name] = [
                    'offset' => $this->stack_size,
                    'type'   => $type,
                ];
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
            $var = $this->vars[$stmt->name];
            // mov [rbp-offset], rax (pointer or i32 value)
            $this->emit("\x48\x89\x45" . pack('c', -$var['offset']));
            if ($var['type'] === 'String') {
                // mov [rbp-(offset-8)], rdx (string length)
                $this->emit("\x48\x89\x55" . pack('c', -($var['offset'] - 8)));
            }
            return;
        }

        if ($stmt instanceof IfNode) {
            $this->generateIf($stmt);
            return;
        }

        if ($stmt instanceof PrintlnNode) {
            $this->generatePrintln($stmt);
            return;
        }

        if ($stmt instanceof ExprStmtNode) {
            if ($stmt->expr instanceof CallNode && $stmt->expr->name === 'exit') {
                if (count($stmt->expr->args) !== 1) {
                    throw new RuntimeException("exit() takes exactly 1 argument on line {$stmt->line}");
                }
                $this->generateExpr($stmt->expr->args[0]);
                $this->emit("\x48\x89\xC7");                         // mov rdi, rax
                $this->emit("\x48\xC7\xC0\x3C\x00\x00\x00");        // mov rax, 60
                $this->emit("\x0F\x05");                             // syscall
                return;
            }
            $this->generateExpr($stmt->expr);
            return;
        }

        throw new RuntimeException("Unknown statement type: " . get_class($stmt));
    }

    private function generatePrintln(PrintlnNode $node): void {
        foreach ($node->parts as $part) {
            if (is_string($part)) {
                $this->emitWriteString($part);
            } else {
                $type = $this->exprType($part);
                $this->generateExpr($part);
                if ($type === 'String') {
                    $this->emitPrintString();
                } else {
                    $this->emitPrintInt();
                }
            }
        }
    }

    private function emitWriteString(string $str): void {
        $data_offset = $this->addData($str);
        $len = strlen($str);

        $this->emit("\x48\xC7\xC0\x01\x00\x00\x00");        // mov rax, 1 (write)
        $this->emit("\x48\xC7\xC7\x01\x00\x00\x00");        // mov rdi, 1 (stdout)
        $this->emit("\x48\xBE");                              // movabs rsi, <addr>
        $this->data_patches[] = [strlen($this->code), $data_offset];
        $this->emit("\x00\x00\x00\x00\x00\x00\x00\x00");
        $this->emit("\x48\xC7\xC2" . pack('V', $len));      // mov rdx, len
        $this->emit("\x0F\x05");                              // syscall
    }

    private function emitPrintString(): void {
        // rax = string pointer, rdx = string length
        $this->emit("\x48\x89\xC6");                          // mov rsi, rax
        $this->emit("\x48\xC7\xC0\x01\x00\x00\x00");         // mov rax, 1 (write)
        $this->emit("\x48\xC7\xC7\x01\x00\x00\x00");         // mov rdi, 1 (stdout)
        $this->emit("\x0F\x05");                              // syscall
    }

    private function emitPrintInt(): void {
        $this->emit("\x48\x83\xEC\x20");                     // sub rsp, 32
        $this->emit("\x4C\x8D\x44\x24\x1F");                 // lea r8, [rsp+31]
        $this->emit("\x4D\x31\xC9");                          // xor r9, r9
        $this->emit("\x48\xC7\xC1\x0A\x00\x00\x00");         // mov rcx, 10

        $this->emit("\x49\xFF\xC8");                          // dec r8
        $this->emit("\x48\x31\xD2");                          // xor rdx, rdx
        $this->emit("\x48\xF7\xF1");                          // div rcx
        $this->emit("\x80\xC2\x30");                          // add dl, '0'
        $this->emit("\x41\x88\x10");                          // mov [r8], dl
        $this->emit("\x49\xFF\xC1");                          // inc r9
        $this->emit("\x48\x85\xC0");                          // test rax, rax
        $this->emit("\x75\xE9");                              // jnz loop (-23)

        $this->emit("\x4C\x89\xC6");                          // mov rsi, r8
        $this->emit("\x4C\x89\xCA");                          // mov rdx, r9
        $this->emit("\x48\xC7\xC0\x01\x00\x00\x00");         // mov rax, 1 (write)
        $this->emit("\x48\xC7\xC7\x01\x00\x00\x00");         // mov rdi, 1 (stdout)
        $this->emit("\x0F\x05");                              // syscall
        $this->emit("\x48\x83\xC4\x20");                      // add rsp, 32
    }

    private function generateIf(IfNode $node): void {
        $this->generateExpr($node->condition);
        $this->emit("\x48\x85\xC0");                          // test rax, rax

        if ($node->else_body === null) {
            $this->emit("\x0F\x84");
            $jz_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            $this->generateBody($node->then_body);
            $this->patch32($jz_patch, strlen($this->code) - $jz_patch - 4);
        } else {
            $this->emit("\x0F\x84");
            $jz_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            $this->generateBody($node->then_body);

            $this->emit("\xE9");
            $jmp_patch = strlen($this->code);
            $this->emit("\x00\x00\x00\x00");

            $this->patch32($jz_patch, strlen($this->code) - $jz_patch - 4);
            $this->generateBody($node->else_body);
            $this->patch32($jmp_patch, strlen($this->code) - $jmp_patch - 4);
        }
    }

    private function generateExpr(mixed $expr): void {
        if ($expr instanceof IntLitNode) {
            $this->emit("\x48\xC7\xC0" . pack('V', $expr->value));
            return;
        }

        if ($expr instanceof BoolLitNode) {
            $val = $expr->value ? 1 : 0;
            $this->emit("\x48\xC7\xC0" . pack('V', $val));
            return;
        }

        if ($expr instanceof StringFromNode) {
            $data_offset = $this->addData($expr->value);
            $len = strlen($expr->value);
            // movabs rax, <data address placeholder>
            $this->emit("\x48\xB8");
            $this->data_patches[] = [strlen($this->code), $data_offset];
            $this->emit("\x00\x00\x00\x00\x00\x00\x00\x00");
            // mov rdx, len
            $this->emit("\x48\xC7\xC2" . pack('V', $len));
            return;
        }

        if ($expr instanceof IdentNode) {
            if (!isset($this->vars[$expr->name])) {
                throw new RuntimeException("Undefined variable '{$expr->name}' on line {$expr->line}");
            }
            $var = $this->vars[$expr->name];
            // mov rax, [rbp-offset]
            $this->emit("\x48\x8B\x45" . pack('c', -$var['offset']));
            if ($var['type'] === 'String') {
                // mov rdx, [rbp-(offset-8)]
                $this->emit("\x48\x8B\x55" . pack('c', -($var['offset'] - 8)));
            }
            return;
        }

        if ($expr instanceof BinaryOpNode) {
            $this->generateExpr($expr->left);
            $this->emit("\x50");                               // push rax
            $this->generateExpr($expr->right);
            $this->emit("\x48\x89\xC1");                       // mov rcx, rax
            $this->emit("\x58");                               // pop rax

            switch ($expr->op) {
                case '+':
                    $this->emit("\x48\x01\xC8");
                    break;
                case '-':
                    $this->emit("\x48\x29\xC8");
                    break;
                case '*':
                    $this->emit("\x48\x0F\xAF\xC1");
                    break;
                case '/':
                    $this->emit("\x48\x99");
                    $this->emit("\x48\xF7\xF9");
                    break;
                case '==':
                    $this->emitComparison("\x0F\x94");
                    break;
                case '!=':
                    $this->emitComparison("\x0F\x95");
                    break;
                case '<':
                    $this->emitComparison("\x0F\x9C");
                    break;
                case '>':
                    $this->emitComparison("\x0F\x9F");
                    break;
                case '<=':
                    $this->emitComparison("\x0F\x9E");
                    break;
                case '>=':
                    $this->emitComparison("\x0F\x9D");
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
        $this->emit("\x48\x39\xC8");
        $this->emit($setcc_opcode . "\xC0");
        $this->emit("\x48\x0F\xB6\xC0");
    }

    private function emitExit(int $code): void {
        $this->emit("\x48\xC7\xC0\x3C\x00\x00\x00");
        if ($code === 0) {
            $this->emit("\x48\x31\xFF");
        } else {
            $this->emit("\x48\xC7\xC7" . pack('V', $code));
        }
        $this->emit("\x0F\x05");
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
