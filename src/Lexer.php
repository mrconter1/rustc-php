<?php

require_once __DIR__ . '/Token.php';

class Lexer {
    private string $source;
    private int    $pos  = 0;
    private int    $line = 1;

    private const KEYWORDS = [
        'fn'     => Token::FN,
        'let'    => Token::LET,
        'mut'    => Token::MUT,
        'if'     => Token::IF,
        'else'   => Token::ELSE,
        'while'  => Token::WHILE,
        'return' => Token::RETURN,
        'true'   => Token::TRUE,
        'false'  => Token::FALSE,
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
        $tokens[] = new Token(Token::EOF, null, $this->line);
        return $tokens;
    }

    private function nextToken(): Token {
        $ch   = $this->source[$this->pos];
        $line = $this->line;

        if ($ch === '"') {
            return $this->readString($line);
        }
        if (ctype_digit($ch)) {
            return $this->readInt($line);
        }
        if (ctype_alpha($ch) || $ch === '_') {
            return $this->readIdent($line);
        }
        return $this->readSymbol($line);
    }

    private function skipWhitespaceAndComments(): void {
        while ($this->pos < strlen($this->source)) {
            $ch = $this->source[$this->pos];
            if ($ch === "\n") {
                $this->line++;
                $this->pos++;
            } elseif (ctype_space($ch)) {
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

    private function readString(int $line): Token {
        $this->pos++; // skip opening "
        $value = '';
        while ($this->pos < strlen($this->source) && $this->source[$this->pos] !== '"') {
            if ($this->source[$this->pos] === '\\') {
                $this->pos++;
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
            $this->pos++;
        }
        $this->pos++; // skip closing "
        return new Token(Token::STR_LIT, $value, $line);
    }

    private function readInt(int $line): Token {
        $start = $this->pos;
        while ($this->pos < strlen($this->source) && ctype_digit($this->source[$this->pos])) {
            $this->pos++;
        }
        $value = (int)substr($this->source, $start, $this->pos - $start);
        return new Token(Token::INT_LIT, $value, $line);
    }

    private function readIdent(int $line): Token {
        $start = $this->pos;
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $this->pos++;
        }
        $word = substr($this->source, $start, $this->pos - $start);

        // identifier followed by ! is a macro call, e.g. println!
        if ($this->pos < strlen($this->source) && $this->source[$this->pos] === '!') {
            $this->pos++;
            return new Token(Token::MACRO, $word, $line);
        }

        $type = self::KEYWORDS[$word] ?? Token::IDENT;
        return new Token($type, $word, $line);
    }

    private function readSymbol(int $line): Token {
        $ch   = $this->source[$this->pos++];
        $next = $this->charAt($this->pos);

        if ($ch === ':' && $next === ':') { $this->pos++; return new Token(Token::DCOLON, '::',  $line); }
        if ($ch === '=' && $next === '=') { $this->pos++; return new Token(Token::EQEQ,   '==',  $line); }
        if ($ch === '!' && $next === '=') { $this->pos++; return new Token(Token::NEQ,    '!=',  $line); }
        if ($ch === '<' && $next === '=') { $this->pos++; return new Token(Token::LTE,    '<=',  $line); }
        if ($ch === '>' && $next === '=') { $this->pos++; return new Token(Token::GTE,    '>=',  $line); }
        if ($ch === '-' && $next === '>') { $this->pos++; return new Token(Token::ARROW,  '->',  $line); }
        if ($ch === '&' && $next === '&') { $this->pos++; return new Token(Token::AND,    '&&',  $line); }
        if ($ch === '|' && $next === '|') { $this->pos++; return new Token(Token::OR,     '||',  $line); }

        return match($ch) {
            '(' => new Token(Token::LPAREN,    '(', $line),
            ')' => new Token(Token::RPAREN,    ')', $line),
            '{' => new Token(Token::LBRACE,    '{', $line),
            '}' => new Token(Token::RBRACE,    '}', $line),
            ';' => new Token(Token::SEMICOLON, ';', $line),
            ',' => new Token(Token::COMMA,     ',', $line),
            '.' => new Token(Token::DOT,       '.', $line),
            '+' => new Token(Token::PLUS,      '+', $line),
            '-' => new Token(Token::MINUS,     '-', $line),
            '*' => new Token(Token::STAR,      '*', $line),
            '/' => new Token(Token::SLASH,     '/', $line),
            '=' => new Token(Token::EQ,        '=', $line),
            '!' => new Token(Token::BANG,      '!', $line),
            '<' => new Token(Token::LT,        '<', $line),
            '>' => new Token(Token::GT,        '>', $line),
            ':' => new Token(Token::COLON,     ':', $line),
            '&' => new Token(Token::AMP,       '&', $line),
            default => throw new RuntimeException("Unexpected character '$ch' on line $line"),
        };
    }

    private function charAt(int $pos): ?string {
        return $pos < strlen($this->source) ? $this->source[$pos] : null;
    }
}
