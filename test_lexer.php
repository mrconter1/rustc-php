<?php

require_once __DIR__ . '/src/Lexer.php';

$source = 'fn main() {}';

$lexer  = new Lexer($source);
$tokens = $lexer->tokenize();

foreach ($tokens as $token) {
    echo $token . "\n";
}
