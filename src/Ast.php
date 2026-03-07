<?php

class ProgramNode {
    public array $functions;
    public array $structs;
    public array $impls;
    public array $enums;
    public array $mod_decls;
    public array $uses;
    public array $traits;
    public array $consts;
    public array $statics;
    public function __construct(array $functions, array $structs = [], array $impls = [], array $enums = [], array $mod_decls = [], array $uses = [], array $traits = [], array $consts = [], array $statics = []) {
        $this->functions = $functions;
        $this->structs   = $structs;
        $this->impls     = $impls;
        $this->enums     = $enums;
        $this->mod_decls = $mod_decls;
        $this->uses      = $uses;
        $this->traits    = $traits;
        $this->consts    = $consts;
        $this->statics   = $statics;
    }
}

class ConstItemNode {
    public string $name;
    public string $type;
    public mixed  $value;
    public int   $line;
    public function __construct(string $name, string $type, mixed $value, int $line) {
        $this->name  = $name;
        $this->type  = $type;
        $this->value = $value;
        $this->line  = $line;
    }
}

class StaticItemNode {
    public string $name;
    public string $type;
    public mixed  $value;
    public bool   $mutable;
    public int   $line;
    public function __construct(string $name, string $type, mixed $value, bool $mutable, int $line) {
        $this->name    = $name;
        $this->type    = $type;
        $this->value   = $value;
        $this->mutable = $mutable;
        $this->line    = $line;
    }
}

class TraitNode {
    public string $name;
    public array  $methods;
    public bool   $is_pub;
    public int    $line;
    public function __construct(string $name, array $methods, int $line, bool $is_pub = false) {
        $this->name    = $name;
        $this->methods = $methods;
        $this->is_pub  = $is_pub;
        $this->line    = $line;
    }
}

class ModDeclNode {
    public string $name;
    public int    $line;
    public function __construct(string $name, int $line) {
        $this->name = $name;
        $this->line = $line;
    }
}

class UseNode {
    public array $path;
    public int   $line;
    public function __construct(array $path, int $line) {
        $this->path = $path;
        $this->line = $line;
    }
}

class EnumDefNode {
    public string $name;
    public array  $variants; // [['name' => string, 'fields' => [string, ...]], ...]
    public bool   $is_pub;
    public int    $line;
    public function __construct(string $name, array $variants, int $line, bool $is_pub = false) {
        $this->name     = $name;
        $this->variants = $variants;
        $this->is_pub   = $is_pub;
        $this->line     = $line;
    }
}

class EnumVariantNode {
    public string $enum_name;
    public string $variant_name;
    public array  $args;
    public int    $line;
    public function __construct(string $enum_name, string $variant_name, array $args, int $line) {
        $this->enum_name    = $enum_name;
        $this->variant_name = $variant_name;
        $this->args         = $args;
        $this->line         = $line;
    }
}

class MatchArmNode {
    public bool    $is_wildcard;
    public ?string $enum_name;
    public ?string $variant_name;
    public ?string $binding;
    public array   $body;
    public int     $line;
    public function __construct(bool $is_wildcard, ?string $enum_name, ?string $variant_name, ?string $binding, array $body, int $line) {
        $this->is_wildcard    = $is_wildcard;
        $this->enum_name      = $enum_name;
        $this->variant_name   = $variant_name;
        $this->binding        = $binding;
        $this->body           = $body;
        $this->line           = $line;
    }
}

class MatchNode {
    public mixed $subject;
    public array $arms;
    public int   $line;
    public function __construct(mixed $subject, array $arms, int $line) {
        $this->subject = $subject;
        $this->arms    = $arms;
        $this->line    = $line;
    }
}

class StructDefNode {
    public string  $name;
    public array   $fields;
    public array   $type_params;
    public bool    $is_pub;
    public ?string $module;
    public int     $line;
    public function __construct(string $name, array $fields, int $line, array $type_params = [], bool $is_pub = false, ?string $module = null) {
        $this->name        = $name;
        $this->fields      = $fields;
        $this->type_params = $type_params;
        $this->is_pub      = $is_pub;
        $this->module      = $module;
        $this->line        = $line;
    }
}

class StructLitNode {
    public string $struct_name;
    public array  $fields;
    public int    $line;
    public function __construct(string $struct_name, array $fields, int $line) {
        $this->struct_name = $struct_name;
        $this->fields      = $fields;
        $this->line        = $line;
    }
}

class FieldAccessNode {
    public mixed  $object;
    public string $field_name;
    public int    $line;
    public int    $column;
    public function __construct(mixed $object, string $field_name, int $line, int $column = 1) {
        $this->object     = $object;
        $this->field_name = $field_name;
        $this->line       = $line;
        $this->column     = $column;
    }
}

class FieldAssignNode {
    public mixed  $object;
    public string $field_name;
    public mixed  $value;
    public int    $line;
    public function __construct(mixed $object, string $field_name, mixed $value, int $line) {
        $this->object     = $object;
        $this->field_name = $field_name;
        $this->value      = $value;
        $this->line       = $line;
    }
}

class FunctionNode {
    public string  $name;
    public array   $params;      // [['name' => string, 'type' => string], ...]
    public ?string $return_type;
    public ?array  $body;
    public array   $type_params;
    public array   $type_bounds;
    public bool    $is_pub;
    public ?string $module;
    public int     $line;
    public function __construct(string $name, array $params, ?string $return_type, ?array $body, int $line, array $type_params = [], bool $is_pub = false, ?string $module = null, array $type_bounds = []) {
        $this->name        = $name;
        $this->params      = $params;
        $this->return_type = $return_type;
        $this->body        = $body;
        $this->type_params = $type_params;
        $this->type_bounds = $type_bounds;
        $this->is_pub      = $is_pub;
        $this->module      = $module;
        $this->line        = $line;
    }
}

class ReturnNode {
    public mixed $value;
    public int   $line;
    public function __construct(mixed $value, int $line) {
        $this->value = $value;
        $this->line  = $line;
    }
}

class LetNode {
    public string  $name;
    public ?string $type_name;
    public mixed   $value;
    public bool    $mutable;
    public int     $line;
    public int     $column;
    /** @var list<string> tuple destructuring binding names, empty for single binding */
    public array   $bindings;
    public function __construct(string $name, ?string $type_name, mixed $value, bool $mutable, int $line, array $bindings = [], int $column = 1) {
        $this->name      = $name;
        $this->type_name = $type_name;
        $this->value     = $value;
        $this->mutable   = $mutable;
        $this->line      = $line;
        $this->bindings  = $bindings;
        $this->column    = $column;
    }
}

class AssignNode {
    public string $name;
    public mixed  $value;
    public int    $line;
    public int    $column;
    public function __construct(string $name, mixed $value, int $line, int $column = 1) {
        $this->name   = $name;
        $this->value  = $value;
        $this->line   = $line;
        $this->column = $column;
    }
}

class CompoundAssignNode {
    public mixed  $target;
    public string $op;
    public mixed  $value;
    public int    $line;
    public function __construct(mixed $target, string $op, mixed $value, int $line) {
        $this->target = $target;
        $this->op     = $op;
        $this->value  = $value;
        $this->line   = $line;
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

class WhileNode {
    public mixed $condition;
    public array $body;
    public int   $line;
    public function __construct(mixed $condition, array $body, int $line) {
        $this->condition = $condition;
        $this->body      = $body;
        $this->line      = $line;
    }
}

class IfLetNode {
    public mixed  $subject;
    public ?string $enum_name;
    public string $variant_name;
    public ?string $binding;
    public array  $then_body;
    public ?array $else_body;
    public int    $line;
    public function __construct(mixed $subject, ?string $enum_name, string $variant_name, ?string $binding, array $then_body, ?array $else_body, int $line) {
        $this->subject     = $subject;
        $this->enum_name   = $enum_name;
        $this->variant_name = $variant_name;
        $this->binding     = $binding;
        $this->then_body   = $then_body;
        $this->else_body   = $else_body;
        $this->line        = $line;
    }
}

class WhileLetNode {
    public mixed   $subject;
    public ?string $enum_name;
    public string  $variant_name;
    public ?string $binding;
    public array   $body;
    public int     $line;
    public function __construct(mixed $subject, ?string $enum_name, string $variant_name, ?string $binding, array $body, int $line) {
        $this->subject      = $subject;
        $this->enum_name    = $enum_name;
        $this->variant_name = $variant_name;
        $this->binding      = $binding;
        $this->body         = $body;
        $this->line         = $line;
    }
}

class ClosureNode {
    public array $params; // [['name' => string, 'type' => string], ...]
    public array $body;   // array of stmts (single expr wrapped in ReturnNode)
    public int   $line;
    public function __construct(array $params, array $body, int $line) {
        $this->params = $params;
        $this->body   = $body;
        $this->line   = $line;
    }
}

class RangeNode {
    public mixed $start;
    public mixed $end;
    public int   $line;
    public function __construct(mixed $start, mixed $end, int $line) {
        $this->start = $start;
        $this->end   = $end;
        $this->line  = $line;
    }
}

class ForNode {
    public string $var_name;
    public mixed  $iter_expr;
    public array $body;
    public int   $line;
    public function __construct(string $var_name, mixed $iter_expr, array $body, int $line) {
        $this->var_name  = $var_name;
        $this->iter_expr = $iter_expr;
        $this->body      = $body;
        $this->line      = $line;
    }
}

class LoopNode {
    public array $body;
    public int   $line;
    public function __construct(array $body, int $line) {
        $this->body = $body;
        $this->line = $line;
    }
}

class BreakNode {
    public int $line;
    public int $level;
    public function __construct(int $line, int $level = 0) {
        $this->line = $line;
        $this->level = $level;
    }
}

class ContinueNode {
    public int $line;
    public function __construct(int $line) {
        $this->line = $line;
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

class UnitLitNode {
    public int $line;
    public function __construct(int $line) {
        $this->line = $line;
    }
}

class TupleLitNode {
    /** @var list<mixed> */
    public array $elements;
    public int   $line;
    public function __construct(array $elements, int $line) {
        $this->elements = $elements;
        $this->line    = $line;
    }
}

class TupleIndexNode {
    public mixed $object;
    public int   $index;
    public int   $line;
    public function __construct(mixed $object, int $index, int $line) {
        $this->object = $object;
        $this->index  = $index;
        $this->line   = $line;
    }
}

class IdentNode {
    public string $name;
    public int    $line;
    public int    $column;
    public function __construct(string $name, int $line, int $column = 1) {
        $this->name   = $name;
        $this->line   = $line;
        $this->column = $column;
    }
}

class BorrowNode {
    public mixed $operand;
    public bool  $mutable;
    public int   $line;
    public int   $column;
    public function __construct(mixed $operand, bool $mutable, int $line, int $column = 1) {
        $this->operand = $operand;
        $this->mutable = $mutable;
        $this->line    = $line;
        $this->column  = $column;
    }
}

class DerefNode {
    public mixed $operand;
    public int   $line;
    public function __construct(mixed $operand, int $line) {
        $this->operand = $operand;
        $this->line    = $line;
    }
}

class CastNode {
    public mixed  $expr;
    public string $target_type;
    public int    $line;
    public function __construct(mixed $expr, string $target_type, int $line) {
        $this->expr        = $expr;
        $this->target_type = $target_type;
        $this->line        = $line;
    }
}

class DerefAssignNode {
    public mixed $operand;
    public mixed $value;
    public int   $line;
    public function __construct(mixed $operand, mixed $value, int $line) {
        $this->operand = $operand;
        $this->value   = $value;
        $this->line    = $line;
    }
}

class UnaryOpNode {
    public string $op;
    public mixed  $operand;
    public int    $line;
    public function __construct(string $op, mixed $operand, int $line) {
        $this->op      = $op;
        $this->operand = $operand;
        $this->line    = $line;
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

class StrSliceNode {
    public string $value;
    public int    $line;
    public function __construct(string $value, int $line) {
        $this->value = $value;
        $this->line  = $line;
    }
}

class IndexNode {
    public mixed  $object;
    public mixed  $index;
    public int    $line;
    public function __construct(mixed $object, mixed $index, int $line) {
        $this->object = $object;
        $this->index  = $index;
        $this->line   = $line;
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

class ImplNode {
    public string  $struct_name;
    public array   $functions;
    public array   $type_params;
    public array   $type_bounds;
    public ?string $trait_name;
    public int     $line;
    public function __construct(string $struct_name, array $functions, int $line, array $type_params = [], ?string $trait_name = null, array $type_bounds = []) {
        $this->struct_name = $struct_name;
        $this->functions   = $functions;
        $this->type_params = $type_params;
        $this->type_bounds = $type_bounds;
        $this->trait_name  = $trait_name;
        $this->line        = $line;
    }
}

class MethodCallNode {
    public mixed  $receiver;
    public string $method_name;
    public array  $args;
    public int    $line;
    public function __construct(mixed $receiver, string $method_name, array $args, int $line) {
        $this->receiver    = $receiver;
        $this->method_name = $method_name;
        $this->args        = $args;
        $this->line        = $line;
    }
}
