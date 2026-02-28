<?php

require_once __DIR__ . '/src/Lexer.php';
require_once __DIR__ . '/src/Parser.php';
require_once __DIR__ . '/src/ModuleResolver.php';
require_once __DIR__ . '/src/ForLoopDesugar.php';
require_once __DIR__ . '/src/ClosureDesugar.php';
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

try {
    $ast     = (new ModuleResolver())->resolve($input);
    $ast     = (new ForLoopDesugar())->desugar($ast);
    $ast     = (new ClosureDesugar())->desugar($ast);
    $ast     = (new Monomorphizer())->monomorphize($ast);
    (new OwnershipChecker())->check($ast);
    $code    = (new CodeGen())->generate($ast, Elf::LOAD_ADDR + Elf::CODE_OFFSET);
    (new Elf($code))->write($output);
    echo "Compiled $input -> $output\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
