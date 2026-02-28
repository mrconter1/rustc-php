<?php

require_once __DIR__ . '/src/Lexer.php';
require_once __DIR__ . '/src/Parser.php';
require_once __DIR__ . '/src/Monomorphizer.php';
require_once __DIR__ . '/src/OwnershipChecker.php';
require_once __DIR__ . '/src/CodeGen.php';
require_once __DIR__ . '/src/Elf.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php rustc.php <input.rs> [-o <output>]\n");
    exit(1);
}

$input  = $argv[1];
$output = 'a.out';

for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '-o' && isset($argv[$i + 1])) {
        $output = $argv[$i + 1];
        $i++;
    }
}

if (!file_exists($input)) {
    fwrite(STDERR, "Error: file not found: $input\n");
    exit(1);
}

$source = file_get_contents($input);

// UTF-16 LE with BOM (FF FE) — strip BOM first
if (str_starts_with($source, "\xFF\xFE")) {
    $source = substr($source, 2);
}

// UTF-16 LE detected by null byte at position 1 — extract every other byte (ASCII only)
if (strlen($source) > 1 && $source[1] === "\x00") {
    $out = '';
    for ($i = 0; $i < strlen($source); $i += 2) {
        $out .= $source[$i];
    }
    $source = $out;
}

// Strip UTF-8 BOM if present
$source = ltrim($source, "\xEF\xBB\xBF");

try {
    $tokens  = (new Lexer($source))->tokenize();
    $ast     = (new Parser($tokens))->parse();
    $ast     = (new Monomorphizer())->monomorphize($ast);
    (new OwnershipChecker())->check($ast);
    $code    = (new CodeGen())->generate($ast, Elf::LOAD_ADDR + Elf::CODE_OFFSET);
    (new Elf($code))->write($output);
    echo "Compiled $input -> $output\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
