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
    public array  $body;
    public int    $line;
    public function __construct(string $name, array $body, int $line) {
        $this->name = $name;
        $this->body = $body;
        $this->line = $line;
    }
}

class LetNode {
    public string  $name;
    public ?string $type_name;
    public mixed   $value;
    public int     $line;
    public function __construct(string $name, ?string $type_name, mixed $value, int $line) {
        $this->name      = $name;
        $this->type_name = $type_name;
        $this->value     = $value;
        $this->line      = $line;
    }
}

class ExprStmtNode {
    public mixed $expr;
    public int   $line;
    public function __construct(mixed $expr, int $line) {
        $this->expr = $expr;
        $this->line = $line;
    }
}

class IntLitNode {
    public int $value;
    public int $line;
    public function __construct(int $value, int $line) {
        $this->value = $value;
        $this->line  = $line;
    }
}

class IdentNode {
    public string $name;
    public int    $line;
    public function __construct(string $name, int $line) {
        $this->name = $name;
        $this->line = $line;
    }
}

class BinaryOpNode {
    public mixed  $left;
    public string $op;
    public mixed  $right;
    public int    $line;
    public function __construct(mixed $left, string $op, mixed $right, int $line) {
        $this->left  = $left;
        $this->op    = $op;
        $this->right = $right;
        $this->line  = $line;
    }
}

class CallNode {
    public string $name;
    public array  $args;
    public int    $line;
    public function __construct(string $name, array $args, int $line) {
        $this->name = $name;
        $this->args = $args;
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
        if ($this->check(Token::LET)) {
            return $this->parseLet();
        }
        $expr = $this->parseExpr();
        $this->expect(Token::SEMICOLON);
        return new ExprStmtNode($expr, $expr->line);
    }

    private function parseLet(): LetNode {
        $line = $this->expect(Token::LET)->line;
        $name = $this->expect(Token::IDENT)->value;

        $type_name = null;
        if ($this->check(Token::COLON)) {
            $this->expect(Token::COLON);
            $type_name = $this->expect(Token::IDENT)->value;
        }

        $this->expect(Token::EQ);
        $value = $this->parseExpr();
        $this->expect(Token::SEMICOLON);

        return new LetNode($name, $type_name, $value, $line);
    }

    // --- expression parsing with precedence ---

    private function parseExpr(): mixed {
        return $this->parseComparison();
    }

    private function parseComparison(): mixed {
        $left = $this->parseAddSub();
        while ($this->check(Token::EQEQ) || $this->check(Token::NEQ)
            || $this->check(Token::LT)   || $this->check(Token::GT)
            || $this->check(Token::LTE)  || $this->check(Token::GTE)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseAddSub();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseAddSub(): mixed {
        $left = $this->parseMulDiv();
        while ($this->check(Token::PLUS) || $this->check(Token::MINUS)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseMulDiv();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseMulDiv(): mixed {
        $left = $this->parsePrimary();
        while ($this->check(Token::STAR) || $this->check(Token::SLASH)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parsePrimary();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parsePrimary(): mixed {
        $token = $this->current();

        if ($token->type === Token::INT_LIT) {
            $this->pos++;
            return new IntLitNode($token->value, $token->line);
        }

        if ($token->type === Token::IDENT) {
            $this->pos++;
            if ($this->check(Token::LPAREN)) {
                $this->expect(Token::LPAREN);
                $args = [];
                while (!$this->check(Token::RPAREN)) {
                    $args[] = $this->parseExpr();
                    if ($this->check(Token::COMMA)) {
                        $this->pos++;
                    }
                }
                $this->expect(Token::RPAREN);
                return new CallNode($token->value, $args, $token->line);
            }
            return new IdentNode($token->value, $token->line);
        }

        if ($token->type === Token::LPAREN) {
            $this->pos++;
            $expr = $this->parseExpr();
            $this->expect(Token::RPAREN);
            return $expr;
        }

        throw new RuntimeException(
            "Unexpected token {$token->type}({$token->value}) on line {$token->line}"
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
