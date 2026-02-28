<?php

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

class IfNode {
    public mixed  $condition;
    public array  $then_body;
    public ?array $else_body;
    public int    $line;
    public function __construct(mixed $condition, array $then_body, ?array $else_body, int $line) {
        $this->condition = $condition;
        $this->then_body = $then_body;
        $this->else_body = $else_body;
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

class BoolLitNode {
    public bool $value;
    public int  $line;
    public function __construct(bool $value, int $line) {
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

class StrLitNode {
    public string $value;
    public int    $line;
    public function __construct(string $value, int $line) {
        $this->value = $value;
        $this->line  = $line;
    }
}

class StringFromNode {
    public string $value;
    public int    $line;
    public function __construct(string $value, int $line) {
        $this->value = $value;
        $this->line  = $line;
    }
}

class PrintlnNode {
    public array $parts; // mixed: string literals (string) and expression nodes
    public int   $line;
    public function __construct(array $parts, int $line) {
        $this->parts = $parts;
        $this->line  = $line;
    }
}
