<?php

require_once __DIR__ . '/src/Lexer.php';
require_once __DIR__ . '/src/Parser.php';

$source = 'fn main() {}';

$tokens = (new Lexer($source))->tokenize();
$ast    = (new Parser($tokens))->parse();

foreach ($ast->functions as $fn) {
    echo "Function: {$fn->name}\n";
    echo "Body statements: " . count($fn->body) . "\n";
}
