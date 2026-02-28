<?php

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/Lexer.php';

// AST node types

class ProgramNode {
    public array $functions;
    public function __construct(array $functions) {
        $this->functions = $functions;
    }
}

class FunctionNode {
    public string $name;
    public array  $body; // list of statement nodes
    public int    $line;
    public function __construct(string $name, array $body, int $line) {
        $this->name = $name;
        $this->body = $body;
        $this->line = $line;
    }
}

// Parser

class Parser {
    private array $tokens;
    private int   $pos = 0;

    public function __construct(array $tokens) {
        $this->tokens = $tokens;
    }

    public function parse(): ProgramNode {
        $functions = [];
        while (!$this->check(Token::EOF)) {
            $functions[] = $this->parseFunction();
        }
        return new ProgramNode($functions);
    }

    private function parseFunction(): FunctionNode {
        $this->expect(Token::FN);
        $name = $this->expect(Token::IDENT)->value;
        $line = $this->current()->line;
        $this->expect(Token::LPAREN);
        $this->expect(Token::RPAREN);
        $body = $this->parseBlock();
        return new FunctionNode($name, $body, $line);
    }

    private function parseBlock(): array {
        $this->expect(Token::LBRACE);
        $stmts = [];
        while (!$this->check(Token::RBRACE) && !$this->check(Token::EOF)) {
            $stmts[] = $this->parseStatement();
        }
        $this->expect(Token::RBRACE);
        return $stmts;
    }

    private function parseStatement(): mixed {
        throw new RuntimeException(
            "Unexpected token {$this->current()->type} on line {$this->current()->line} — no statements supported yet"
        );
    }

    // --- helpers ---

    private function expect(string $type): Token {
        $token = $this->current();
        if ($token->type !== $type) {
            throw new RuntimeException(
                "Expected $type but got {$token->type}({$token->value}) on line {$token->line}"
            );
        }
        $this->pos++;
        return $token;
    }

    private function check(string $type): bool {
        return $this->current()->type === $type;
    }

    private function current(): Token {
        return $this->tokens[$this->pos];
    }
}
