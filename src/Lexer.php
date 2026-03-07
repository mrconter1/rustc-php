<?php

require_once __DIR__ . '/Token.php';

class Lexer {
    private string $source;
    private int    $pos   = 0;
    private int    $line  = 1;
    private int    $column = 1;

    private const KEYWORDS = [
        'fn'     => Token::FN,
        'let'    => Token::LET,
        'mut'    => Token::MUT,
        'if'     => Token::IF,
        'else'   => Token::ELSE,
        'while'  => Token::WHILE,
        'return'   => Token::RETURN,
        'loop'     => Token::LOOP,
        'break'    => Token::BREAK,
        'continue' => Token::CONTINUE,
        'const'    => Token::CONST,
        'struct'   => Token::STRUCT,
        'true'     => Token::TRUE,
        'false'    => Token::FALSE,
        'impl'     => Token::IMPL,
        'self'     => Token::SELF,
        'enum'     => Token::ENUM,
        'match'    => Token::MATCH,
        'mod'      => Token::MOD,
        'use'      => Token::USE,
        'pub'      => Token::PUB,
        'trait'    => Token::TRAIT,
        'for'      => Token::FOR,
        'in'       => Token::IN,
        'as'       => Token::AS,
        'static'   => Token::STATIC,
    ];

    public function __construct(string $source) {
        $this->source = $source;
    }

    public function tokenize(): array {
        $tokens = [];
        while ($this->pos < strlen($this->source)) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= strlen($this->source)) {
                break;
            }
            $tokens[] = $this->nextToken();
        }
        $tokens[] = new Token(Token::EOF, null, $this->line, $this->column);
        return $tokens;
    }

    private function advanceColumn(): void {
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === "\n") {
            $this->line++;
            $this->column = 1;
        } else {
            $this->column++;
        }
        $this->pos++;
    }

    private function nextToken(): Token {
        $line  = $this->line;
        $column = $this->column;
        $ch   = $this->source[$this->pos];

        if ($ch === '"') {
            return $this->readString($line, $column);
        }
        if (ctype_digit($ch)) {
            return $this->readInt($line, $column);
        }
        if (ctype_alpha($ch) || $ch === '_') {
            return $this->readIdent($line, $column);
        }
        return $this->readSymbol($line, $column);
    }

    private function skipWhitespaceAndComments(): void {
        while ($this->pos < strlen($this->source)) {
            $ch = $this->source[$this->pos];
            if ($ch === "\n") {
                $this->line++;
                $this->column = 1;
                $this->pos++;
            } elseif (ctype_space($ch)) {
                $this->column++;
                $this->pos++;
            } elseif ($this->charAt($this->pos) === '/' && $this->charAt($this->pos + 1) === '/') {
                while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== "\n") {
                    $this->pos++;
                }
            } else {
                break;
            }
        }
    }

    private function readString(int $line, int $column): Token {
        $this->pos++; // skip opening "
        $this->column++;
        $value = '';
        while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== '"') {
            if ($this->source[$this->pos] === '\\') {
                $this->advanceColumn();
                $value .= match($this->source[$this->pos] ?? '') {
                    'n'  => "\n",
                    't'  => "\t",
                    '"'  => '"',
                    '\\' => '\\',
                    default => '\\' . $this->source[$this->pos],
                };
            } else {
                $value .= $this->source[$this->pos];
            }
            $this->advanceColumn();
        }
        $this->pos++; // skip closing "
        $this->column++;
        return new Token(Token::STR_LIT, $value, $line, $column);
    }

    private function readInt(int $line, int $column): Token {
        $start = $this->pos;
        while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
            $this->advanceColumn();
        }
        $value = (int)substr($this->source, $start, $this->pos - $start);
        return new Token(Token::INT_LIT, $value, $line, $column);
    }

    private function readIdent(int $line, int $column): Token {
        $start = $this->pos;
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $this->advanceColumn();
        }
        $word = substr($this->source, $start, $this->pos - $start);

        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '!') {
            $this->advanceColumn();
            return new Token(Token::MACRO, $word, $line, $column);
        }

        $type = self::KEYWORDS[$word] ?? Token::IDENT;
        return new Token($type, $word, $line, $column);
    }

    private function readSymbol(int $line, int $column): Token {
        $ch   = $this->source[$this->pos++];
        $this->column++;
        $next = $this->charAt($this->pos);

        if ($ch === '#') { return new Token(Token::HASH, '#', $line, $column); }
        if ($ch === '.' && $next === '.') { $this->pos++; $this->column++; return new Token(Token::DOTDOT,    '..',  $line, $column); }
        if ($ch === ':' && $next === ':') { $this->pos++; $this->column++; return new Token(Token::DCOLON,    '::',  $line, $column); }
        if ($ch === '=' && $next === '>') { $this->pos++; $this->column++; return new Token(Token::FAT_ARROW, '=>',  $line, $column); }
        if ($ch === '=' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::EQEQ,      '==',  $line, $column); }
        if ($ch === '!' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::NEQ,    '!=',  $line, $column); }
        if ($ch === '<' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::LTE,    '<=',  $line, $column); }
        if ($ch === '>' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::GTE,    '>=',  $line, $column); }
        if ($ch === '-' && $next === '>') { $this->pos++; $this->column++; return new Token(Token::ARROW,  '->',  $line, $column); }
        if ($ch === '&' && $next === '&') { $this->pos++; $this->column++; return new Token(Token::AND,    '&&',  $line, $column); }
        if ($ch === '|' && $next === '|') { $this->pos++; $this->column++; return new Token(Token::OR,     '||',  $line, $column); }

        if ($ch === '|' && $next !== '|') { return new Token(Token::PIPE, '|', $line, $column); }
        if ($ch === '+' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::PLUS_EQ,  '+=', $line, $column); }
        if ($ch === '-' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::MINUS_EQ, '-=', $line, $column); }
        if ($ch === '*' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::STAR_EQ,  '*=', $line, $column); }
        if ($ch === '/' && $next === '=') { $this->pos++; $this->column++; return new Token(Token::SLASH_EQ, '/=', $line, $column); }

        $tokens = [
            '(' => [Token::LPAREN,    '('],
            ')' => [Token::RPAREN,    ')'],
            '{' => [Token::LBRACE,    '{'],
            '}' => [Token::RBRACE,    '}'],
            '[' => [Token::LBRACKET, '['],
            ']' => [Token::RBRACKET, ']'],
            ';' => [Token::SEMICOLON, ';'],
            ',' => [Token::COMMA,     ','],
            '.' => [Token::DOT,       '.'],
            '+' => [Token::PLUS,      '+'],
            '-' => [Token::MINUS,     '-'],
            '*' => [Token::STAR,      '*'],
            '/' => [Token::SLASH,     '/'],
            '%' => [Token::PERCENT,  '%'],
            '=' => [Token::EQ,        '='],
            '!' => [Token::BANG,      '!'],
            '<' => [Token::LT,        '<'],
            '>' => [Token::GT,        '>'],
            ':' => [Token::COLON,     ':'],
            '&' => [Token::AMP,       '&'],
        ];
        if (isset($tokens[$ch])) {
            return new Token($tokens[$ch][0], $tokens[$ch][1], $line, $column);
        }
        throw new RuntimeException("Unexpected character '$ch' at line $line, column $column");
    }

    private function charAt(int $pos): ?string {
        return $pos < strlen($this->source) ? $this->source[$pos] : null;
    }
}
