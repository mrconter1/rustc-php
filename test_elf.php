<?php

require_once __DIR__ . '/src/Elf.php';

// x86-64 machine code for: exit(0)
//   48 C7 C0 3C 00 00 00   mov rax, 60   (Linux exit syscall number)
//   48 31 FF               xor rdi, rdi  (exit code 0)
//   0F 05                  syscall
$code = "\x48\xC7\xC0\x3C\x00\x00\x00"
      . "\x48\x31\xFF"
      . "\x0F\x05";

$output = $argv[1] ?? 'test_out';

$elf = new Elf($code);
$elf->write($output);

echo "Written to: $output\n";
echo "Run with:   wsl ./$output\n";
echo "Exit code:  wsl ./$output; echo \$?\n";
