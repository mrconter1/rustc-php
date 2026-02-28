<?php

class Token {
    // Keywords
    const FN     = 'FN';
    const LET    = 'LET';
    const MUT    = 'MUT';
    const IF     = 'IF';
    const ELSE   = 'ELSE';
    const WHILE  = 'WHILE';
    const RETURN   = 'RETURN';
    const LOOP     = 'LOOP';
    const BREAK    = 'BREAK';
    const CONTINUE = 'CONTINUE';
    const TRUE     = 'TRUE';
    const FALSE    = 'FALSE';

    // Literals
    const INT_LIT = 'INT_LIT';
    const STR_LIT = 'STR_LIT';

    // Identifier and macro
    const IDENT = 'IDENT';
    const MACRO = 'MACRO'; // identifier followed by !  e.g. println!

    // Punctuation
    const LPAREN    = 'LPAREN';    // (
    const RPAREN    = 'RPAREN';    // )
    const LBRACE    = 'LBRACE';    // {
    const RBRACE    = 'RBRACE';    // }
    const SEMICOLON = 'SEMICOLON'; // ;
    const COLON     = 'COLON';     // :
    const DCOLON    = 'DCOLON';    // ::
    const COMMA     = 'COMMA';     // ,
    const DOT       = 'DOT';       // .
    const ARROW     = 'ARROW';     // ->

    // Operators
    const PLUS  = 'PLUS';  // +
    const MINUS = 'MINUS'; // -
    const STAR  = 'STAR';  // *
    const SLASH   = 'SLASH';   // /
    const PERCENT = 'PERCENT'; // %
    const EQ    = 'EQ';    // =
    const EQEQ  = 'EQEQ';  // ==
    const NEQ   = 'NEQ';   // !=
    const LT    = 'LT';    // <
    const GT    = 'GT';    // >
    const LTE   = 'LTE';   // <=
    const GTE   = 'GTE';   // >=
    const BANG  = 'BANG';  // !
    const AND   = 'AND';   // &&
    const OR    = 'OR';    // ||
    const AMP   = 'AMP';   // &

    // End of file
    const EOF = 'EOF';

    public string $type;
    public mixed  $value;
    public int    $line;

    public function __construct(string $type, mixed $value, int $line) {
        $this->type  = $type;
        $this->value = $value;
        $this->line  = $line;
    }

    public function __toString(): string {
        return $this->value !== null
            ? "{$this->type}({$this->value})@{$this->line}"
            : "{$this->type}@{$this->line}";
    }
}
