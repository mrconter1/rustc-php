<?php

require_once __DIR__ . '/Parser.php';

class CodeGen {
    public function generate(ProgramNode $program): string {
        $code = '';
        foreach ($program->functions as $fn) {
            if ($fn->name === 'main') {
                $code .= $this->generateMain($fn);
            } else {
                throw new RuntimeException("Unknown function '{$fn->name}' on line {$fn->line}");
            }
        }
        return $code;
    }

    private function generateMain(FunctionNode $fn): string {
        // Empty main body: just exit(0)
        //   48 C7 C0 3C 00 00 00   mov rax, 60  (Linux exit syscall)
        //   48 31 FF               xor rdi, rdi (exit code 0)
        //   0F 05                  syscall
        return "\x48\xC7\xC0\x3C\x00\x00\x00"
             . "\x48\x31\xFF"
             . "\x0F\x05";
    }
}
