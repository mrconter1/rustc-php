<?php

require_once __DIR__ . '/Ast.php';

class OwnershipChecker {
    private const INT_PRIMITIVES = ['i32', 'u8', 'u16', 'u32', 'u64', 'u128', 'usize'];

    private array $vars = [];
    private array $func_sigs = [];
    private array $struct_defs = [];
    private array $enum_defs   = [];
    private ?string $current_return_type = null;
    private ?string $current_module = null;

    private function typeSize(string $type): int {
        if ($type === 'u128') return 16;
        if (in_array($type, self::INT_PRIMITIVES, true) || $type === 'bool') return 8;
        if (isset($this->struct_defs[$type])) return $this->struct_defs[$type]['size'];
        if (isset($this->enum_defs[$type])) return $this->enum_defs[$type]['size'];
        return 8;
    }

    private function integerTypesCompatible(string $expected, string $actual): bool {
        return in_array($expected, self::INT_PRIMITIVES, true) && in_array($actual, self::INT_PRIMITIVES, true);
    }

    public function check(ProgramNode $program): void {
        foreach ($program->structs as $sd) {
            $this->struct_defs[$sd->name] = [
                'fields' => $sd->fields,
                'size' => 0,
                'field_offsets' => [],
                'module' => $sd->module,
            ];
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($program->structs as $sd) {
                $size = 0;
                $field_offsets = [];
                foreach ($sd->fields as $f) {
                    $field_offsets[$f['name']] = $size;
                    $size += $this->typeSize($f['type']);
                }
                if ($size !== $this->struct_defs[$sd->name]['size']) {
                    $changed = true;
                    $this->struct_defs[$sd->name]['size'] = $size;
                    $this->struct_defs[$sd->name]['field_offsets'] = $field_offsets;
                }
            }
        }

        foreach ($program->enums as $ed) {
            $discriminant  = 0;
            $has_payload   = false;
            $variants_map  = [];
            foreach ($ed->variants as $v) {
                $variants_map[$v['name']] = [
                    'discriminant' => $discriminant++,
                    'fields'       => $v['fields'],
                ];
                if (!empty($v['fields'])) $has_payload = true;
            }
            $this->enum_defs[$ed->name] = [
                'variants'    => $variants_map,
                'has_payload' => $has_payload,
                'size'        => $has_payload ? 16 : 8,
            ];
        }

        $this->registerBuiltinEnumsFromProgram($program);

        foreach ($program->functions as $fn) {
            $this->func_sigs[$fn->name] = [
                'params' => $fn->params,
                'return_type' => $fn->return_type,
            ];
        }

        $this->func_sigs['str::len'] = ['params' => [['name' => 'self', 'type' => '&str']], 'return_type' => 'i32', 'builtin' => true];
        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                $mangled = "{$impl->struct_name}::{$fn->name}";
                $this->func_sigs[$mangled] = [
                    'params' => $fn->params,
                    'return_type' => $fn->return_type,
                    'is_method' => true,
                    'struct' => $impl->struct_name,
                ];
            }
        }

        foreach ($program->functions as $fn) {
            $this->checkFunction($fn);
        }

        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                $this->checkFunction($fn, $impl->struct_name);
            }
        }
    }

    private function registerBuiltinEnumsFromProgram(ProgramNode $program): void {
        $types = $this->collectTypesFromProgram($program);
        foreach ($types as $type) {
            $this->registerBuiltinEnumIf($type);
        }
    }

    private function collectTypesFromProgram(ProgramNode $program): array {
        $types = [];
        foreach ($program->functions as $fn) {
            if ($fn->return_type !== null) $types[$fn->return_type] = true;
            foreach ($fn->params as $p) { $types[$p['type']] = true; }
            $this->collectTypesFromStmts($fn->body ?? [], $types);
        }
        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                if ($fn->return_type !== null) $types[$fn->return_type] = true;
                foreach ($fn->params as $p) { $types[$p['type']] = true; }
                $this->collectTypesFromStmts($fn->body ?? [], $types);
            }
        }
        return array_keys($types);
    }

    private function collectTypesFromStmts(array $stmts, array &$types): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) {
                if ($stmt->type_name !== null) $types[$stmt->type_name] = true;
                $this->collectTypesFromExpr($stmt->value, $types);
            }
            if ($stmt instanceof ReturnNode && $stmt->value !== null) $this->collectTypesFromExpr($stmt->value, $types);
            if ($stmt instanceof ExprStmtNode) $this->collectTypesFromExpr($stmt->expr, $types);
            if ($stmt instanceof AssignNode) $this->collectTypesFromExpr($stmt->value, $types);
            if ($stmt instanceof CompoundAssignNode) {
                $this->collectTypesFromExpr($stmt->target, $types);
                $this->collectTypesFromExpr($stmt->value, $types);
            }
            if ($stmt instanceof FieldAssignNode) {
                $this->collectTypesFromExpr($stmt->object, $types);
                $this->collectTypesFromExpr($stmt->value, $types);
            }
            if ($stmt instanceof DerefAssignNode) {
                $this->collectTypesFromExpr($stmt->operand, $types);
                $this->collectTypesFromExpr($stmt->value, $types);
            }
            if ($stmt instanceof IfNode) {
                $this->collectTypesFromExpr($stmt->condition, $types);
                $this->collectTypesFromStmts($stmt->then_body, $types);
                if ($stmt->else_body !== null) $this->collectTypesFromStmts($stmt->else_body, $types);
            }
            if ($stmt instanceof WhileNode) {
                $this->collectTypesFromExpr($stmt->condition, $types);
                $this->collectTypesFromStmts($stmt->body, $types);
            }
            if ($stmt instanceof LoopNode) $this->collectTypesFromStmts($stmt->body, $types);
            if ($stmt instanceof MatchNode) {
                $this->collectTypesFromExpr($stmt->subject, $types);
                foreach ($stmt->arms as $arm) {
                    if ($arm->enum_name !== null) $types[$arm->enum_name] = true;
                    $this->collectTypesFromStmts($arm->body, $types);
                }
            }
            if ($stmt instanceof PrintlnNode) {
                foreach ($stmt->parts as $p) { if (!is_string($p)) $this->collectTypesFromExpr($p, $types); }
            }
        }
    }

    private function collectTypesFromExpr(mixed $expr, array &$types): void {
        if ($expr instanceof EnumVariantNode) {
            $types[$expr->enum_name] = true;
            foreach ($expr->args as $a) $this->collectTypesFromExpr($a, $types);
        }
        if ($expr instanceof StructLitNode) $types[$expr->struct_name] = true;
        if ($expr instanceof CallNode) { foreach ($expr->args as $a) $this->collectTypesFromExpr($a, $types); }
        if ($expr instanceof MethodCallNode) {
            $this->collectTypesFromExpr($expr->receiver, $types);
            foreach ($expr->args as $a) $this->collectTypesFromExpr($a, $types);
        }
        if ($expr instanceof BinaryOpNode) {
            $this->collectTypesFromExpr($expr->left, $types);
            $this->collectTypesFromExpr($expr->right, $types);
        }
        if ($expr instanceof UnaryOpNode || $expr instanceof BorrowNode || $expr instanceof DerefNode) {
            $this->collectTypesFromExpr($expr->operand, $types);
        }
        if ($expr instanceof FieldAccessNode) $this->collectTypesFromExpr($expr->object, $types);
        if ($expr instanceof IndexNode) {
            $this->collectTypesFromExpr($expr->object, $types);
            $this->collectTypesFromExpr($expr->index, $types);
        }
        if ($expr instanceof IfNode) {
            $this->collectTypesFromExpr($expr->condition, $types);
            $this->collectTypesFromStmts($expr->then_body, $types);
            if ($expr->else_body !== null) $this->collectTypesFromStmts($expr->else_body, $types);
        }
        if ($expr instanceof MatchNode) {
            $this->collectTypesFromExpr($expr->subject, $types);
            foreach ($expr->arms as $arm) $this->collectTypesFromStmts($arm->body, $types);
        }
    }

    private function registerBuiltinEnumIf(string $type): void {
        if (isset($this->enum_defs[$type])) return;
        if (preg_match('/^Option<(.+)>$/', $type, $m)) {
            $this->enum_defs[$type] = [
                'variants'    => [
                    'None' => ['discriminant' => 0, 'fields' => []],
                    'Some' => ['discriminant' => 1, 'fields' => [$m[1]]],
                ],
                'has_payload' => true,
                'size'        => 16,
            ];
            return;
        }
        if (str_starts_with($type, 'Result<') && substr($type, -1) === '>') {
            $inner = substr($type, 7, -1);
            $depth = 0;
            $len = strlen($inner);
            for ($i = 0; $i < $len; $i++) {
                $c = $inner[$i];
                if ($c === '<') $depth++;
                elseif ($c === '>') $depth--;
                elseif ($c === ',' && $depth === 0) {
                    $t = trim(substr($inner, 0, $i));
                    $e = trim(substr($inner, $i + 1));
                    $this->enum_defs[$type] = [
                        'variants'    => [
                            'Ok'  => ['discriminant' => 0, 'fields' => [$t]],
                            'Err' => ['discriminant' => 1, 'fields' => [$e]],
                        ],
                        'has_payload' => true,
                        'size'        => 16,
                    ];
                    return;
                }
            }
        }
    }

    private function checkFunction(FunctionNode $fn, ?string $struct_name = null): void {
        $this->vars = [];
        $this->current_return_type = $fn->return_type;
        $this->current_module = $fn->module;
        foreach ($fn->params as $param) {
            $type = $param['type'];
            if ($struct_name !== null) {
                $type = str_replace('self', $struct_name, $type);
            }
            $this->vars[$param['name']] = [
                'type' => $type,
                'state' => 'owned',
                'mutable' => false,
                'moved_to' => null,
                'moved_line' => null,
            ];
        }
        if ($fn->body !== null) {
            $this->checkBody($fn->body);
        }
    }

    private function checkCompoundAssign(CompoundAssignNode $stmt): void {
        $target = $stmt->target;
        if ($target instanceof IdentNode) {
            if (!isset($this->vars[$target->name])) {
                throw new RuntimeException("Undefined variable '{$target->name}' on line {$stmt->line}");
            }
            $var = $this->vars[$target->name];
            if (!$var['mutable']) {
                throw new RuntimeException(
                    "Cannot assign to immutable variable '{$target->name}' on line {$stmt->line}"
                );
            }
        }
        if ($target instanceof DerefNode && $target->operand instanceof IdentNode) {
            $name = $target->operand->name;
            if (!isset($this->vars[$name])) {
                throw new RuntimeException("Undefined variable '$name' on line {$stmt->line}");
            }
            if (!str_starts_with($this->vars[$name]['type'], '&mut ')) {
                throw new RuntimeException("Cannot assign through immutable reference '$name' on line {$stmt->line}");
            }
        }
        if ($target instanceof FieldAccessNode && $target->object instanceof IdentNode) {
            $name = $target->object->name;
            if (!isset($this->vars[$name])) {
                throw new RuntimeException("Undefined variable '$name' on line {$stmt->line}");
            }
            $var_type = $this->vars[$name]['type'];
            $is_mut_ref = str_starts_with($var_type, '&mut ');
            if (!$this->vars[$name]['mutable'] && !$is_mut_ref) {
                throw new RuntimeException(
                    "Cannot assign to field of immutable variable '$name' on line {$stmt->line}"
                );
            }
        }
        $this->checkExpr($stmt->value);
        $lhs_type = $this->exprType($target);
        $rhs_type = $this->exprType($stmt->value);
        if ($lhs_type !== $rhs_type && !$this->integerTypesCompatible($lhs_type, $rhs_type)) {
            throw new RuntimeException(
                "Type mismatch: compound assignment requires compatible types, got '{$lhs_type}' and '{$rhs_type}' on line {$stmt->line}"
            );
        }
    }

    private function checkBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->checkStmt($stmt);
        }
    }

    private function checkStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            $this->checkExpr($stmt->value);

            $expr_type = $this->exprType($stmt->value);
            $type = $stmt->type_name ?? $expr_type;

            if ($stmt->type_name !== null && $stmt->type_name !== $expr_type && !$this->integerTypesCompatible($stmt->type_name, $expr_type)) {
                throw new RuntimeException(
                    "Type mismatch: expected '{$stmt->type_name}', got '$expr_type' on line {$stmt->line}"
                );
            }

            if ($stmt->value instanceof IdentNode && !$this->isCopy($type)) {
                $src = $stmt->value->name;
                if (isset($this->vars[$src])) {
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = $stmt->name;
                    $this->vars[$src]['moved_line'] = $stmt->line;
                }
            }

            $this->vars[$stmt->name] = [
                'type' => $type,
                'state' => 'owned',
                'mutable' => $stmt->mutable,
                'moved_to' => null,
                'moved_line' => null,
            ];
            return;
        }

        if ($stmt instanceof CompoundAssignNode) {
            $this->checkCompoundAssign($stmt);
            return;
        }

        if ($stmt instanceof AssignNode) {
            if (!isset($this->vars[$stmt->name])) {
                throw new RuntimeException("Undefined variable '{$stmt->name}' on line {$stmt->line}");
            }
            $var = $this->vars[$stmt->name];
            if (!$var['mutable']) {
                throw new RuntimeException(
                    "Cannot assign twice to immutable variable '{$stmt->name}' on line {$stmt->line}"
                );
            }

            $this->checkExpr($stmt->value);

            $expr_type = $this->exprType($stmt->value);
            if ($var['type'] !== $expr_type && !$this->integerTypesCompatible($var['type'], $expr_type)) {
                throw new RuntimeException(
                    "Type mismatch: cannot assign '$expr_type' to '{$var['type']}' variable '{$stmt->name}' on line {$stmt->line}"
                );
            }

            if ($stmt->value instanceof IdentNode && !$this->isCopy($expr_type)) {
                $src = $stmt->value->name;
                if (isset($this->vars[$src])) {
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = $stmt->name;
                    $this->vars[$src]['moved_line'] = $stmt->line;
                }
            }

            if ($var['state'] === 'moved') {
                $this->vars[$stmt->name]['state'] = 'owned';
                $this->vars[$stmt->name]['moved_to'] = null;
                $this->vars[$stmt->name]['moved_line'] = null;
            }
            return;
        }

        if ($stmt instanceof FieldAssignNode) {
            if ($stmt->object instanceof IdentNode) {
                $name = $stmt->object->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' on line {$stmt->line}");
                }
                $var_type = $this->vars[$name]['type'];
                $is_mut_ref = str_starts_with($var_type, '&mut ');
                if (!$this->vars[$name]['mutable'] && !$is_mut_ref) {
                    throw new RuntimeException(
                        "Cannot assign to field of immutable variable '$name' on line {$stmt->line}"
                    );
                }
                $var_type = $this->vars[$name]['type'];
                $base_type = $var_type;
                if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
                elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
                if (isset($this->struct_defs[$base_type])) {
                    $sd = $this->struct_defs[$base_type];
                    if ($sd['module'] !== null && $sd['module'] !== $this->current_module) {
                        foreach ($sd['fields'] as $f) {
                            if ($f['name'] === $stmt->field_name && empty($f['pub'])) {
                                throw new RuntimeException("Field '{$stmt->field_name}' of struct is private on line {$stmt->line}");
                            }
                        }
                    }
                    foreach ($sd['fields'] as $f) {
                        if ($f['name'] === $stmt->field_name) {
                            $val_type = $this->exprType($stmt->value);
                            if ($val_type !== $f['type'] && !$this->integerTypesCompatible($f['type'], $val_type)) {
                                throw new RuntimeException(
                                    "Type mismatch: cannot assign '$val_type' to field '{$stmt->field_name}' of type '{$f['type']}' on line {$stmt->line}"
                                );
                            }
                            break;
                        }
                    }
                }
            }
            $this->checkExpr($stmt->value);
            return;
        }

        if ($stmt instanceof DerefAssignNode) {
            $this->checkExpr($stmt->value);
            if ($stmt->operand instanceof IdentNode) {
                $name = $stmt->operand->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' on line {$stmt->line}");
                }
                $var = $this->vars[$name];
                if (!str_starts_with($var['type'], '&mut ')) {
                    throw new RuntimeException(
                        "Cannot assign through immutable reference '$name' on line {$stmt->line}"
                    );
                }
            }
            return;
        }

        if ($stmt instanceof AssignNode) {
            if (!isset($this->vars[$stmt->name])) {
                throw new RuntimeException("Undefined variable '{$stmt->name}' on line {$stmt->line}");
            }
            $var = $this->vars[$stmt->name];
            if (!$var['mutable']) {
                throw new RuntimeException(
                    "Cannot assign twice to immutable variable '{$stmt->name}' on line {$stmt->line}"
                );
            }
            $this->checkExpr($stmt->value);
            $expr_type = $this->exprType($stmt->value);
            if ($var['type'] !== $expr_type && !$this->integerTypesCompatible($var['type'], $expr_type)) {
                throw new RuntimeException(
                    "Type mismatch: cannot assign '$expr_type' to '{$var['type']}' variable '{$stmt->name}' on line {$stmt->line}"
                );
            }
            if ($stmt->value instanceof IdentNode && !$this->isCopy($expr_type)) {
                $src = $stmt->value->name;
                if (isset($this->vars[$src])) {
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = $stmt->name;
                    $this->vars[$src]['moved_line'] = $stmt->line;
                }
            }
            if ($var['state'] === 'moved') {
                $this->vars[$stmt->name]['state'] = 'owned';
                $this->vars[$stmt->name]['moved_to'] = null;
                $this->vars[$stmt->name]['moved_line'] = null;
            }
            return;
        }

        if ($stmt instanceof ReturnNode) {
            if ($stmt->value !== null) {
                $this->checkExpr($stmt->value);

                if ($this->current_return_type !== null) {
                    $expr_type = $this->exprType($stmt->value);
                    if ($expr_type !== $this->current_return_type && !$this->integerTypesCompatible($this->current_return_type, $expr_type)) {
                        throw new RuntimeException(
                            "Type mismatch: expected return type '{$this->current_return_type}', got '$expr_type' on line {$stmt->line}"
                        );
                    }
                }
            }
            return;
        }

        if ($stmt instanceof ExprStmtNode) {
            $this->checkExpr($stmt->expr);
            return;
        }

        if ($stmt instanceof PrintlnNode) {
            foreach ($stmt->parts as $part) {
                if (!is_string($part)) {
                    $this->checkExpr($part);
                }
            }
            return;
        }

        if ($stmt instanceof WhileNode) {
            $this->checkExpr($stmt->condition);
            $saved = $this->vars;
            $this->checkBody($stmt->body);
            $this->vars = $this->mergeStates($saved, $this->vars);
            return;
        }

        if ($stmt instanceof LoopNode) {
            $saved = $this->vars;
            $this->checkBody($stmt->body);
            $this->vars = $this->mergeStates($saved, $this->vars);
            return;
        }

        if ($stmt instanceof BreakNode || $stmt instanceof ContinueNode) {
            return;
        }

        if ($stmt instanceof MatchNode) {
            $this->checkExpr($stmt->subject);
            $subject_type = $this->exprType($stmt->subject);
            $base_type = $subject_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);

            if (!isset($this->enum_defs[$base_type])) {
                throw new RuntimeException("Cannot match on non-enum type '$base_type' on line {$stmt->line}");
            }

            $saved = $this->vars;
            foreach ($stmt->arms as $arm) {
                $this->vars = $saved;
                if (!$arm->is_wildcard) {
                    if (!isset($this->enum_defs[$base_type]['variants'][$arm->variant_name])) {
                        throw new RuntimeException("Enum '$base_type' has no variant '{$arm->variant_name}' on line {$arm->line}");
                    }
                    if ($arm->binding !== null) {
                        $field_type = $this->enum_defs[$base_type]['variants'][$arm->variant_name]['fields'][0] ?? 'i32';
                        $this->vars[$arm->binding] = [
                            'type'       => $field_type,
                            'state'      => 'owned',
                            'mutable'    => false,
                            'moved_to'   => null,
                            'moved_line' => null,
                        ];
                    }
                }
                $this->checkBody($arm->body);
            }
            $this->vars = $this->mergeStates($saved, $this->vars);
            return;
        }

        if ($stmt instanceof IfNode) {
            $this->checkExpr($stmt->condition);

            $saved = $this->vars;
            $this->checkBody($stmt->then_body);
            $after_then = $this->vars;

            if ($stmt->else_body !== null) {
                $this->vars = $saved;
                $this->checkBody($stmt->else_body);
                $after_else = $this->vars;

                foreach ($after_else as $name => &$info) {
                    if (isset($after_then[$name]) && $after_then[$name]['state'] === 'moved') {
                        $info['state'] = 'moved';
                        $info['moved_to'] = $after_then[$name]['moved_to'];
                        $info['moved_line'] = $after_then[$name]['moved_line'];
                    }
                }
                unset($info);
                $this->vars = $after_else;
            } else {
                $this->vars = $this->mergeStates($saved, $after_then);
            }
            return;
        }
    }

    private function checkExpr(mixed $expr): void {
        if ($expr instanceof IdentNode) {
            if (isset($this->vars[$expr->name]) && $this->vars[$expr->name]['state'] === 'moved') {
                $v = $this->vars[$expr->name];
                $msg = "Use of moved value '{$expr->name}' on line {$expr->line}";
                if ($v['moved_to'] !== null) {
                    $msg .= " (moved to '{$v['moved_to']}' on line {$v['moved_line']})";
                }
                throw new RuntimeException($msg);
            }
            return;
        }

        if ($expr instanceof StructLitNode) {
            foreach ($expr->fields as $f) {
                $this->checkExpr($f['value']);
            }
            if (isset($this->struct_defs[$expr->struct_name])) {
                $sd = $this->struct_defs[$expr->struct_name];
                if ($sd['module'] !== null && $sd['module'] !== $this->current_module) {
                    foreach ($sd['fields'] as $f) {
                        if (empty($f['pub'])) {
                            throw new RuntimeException("Field '{$f['name']}' of struct is private on line {$expr->line}");
                        }
                    }
                }
            }
            return;
        }

        if ($expr instanceof EnumVariantNode) {
            foreach ($expr->args as $arg) {
                $this->checkExpr($arg);
            }
            if (!isset($this->enum_defs[$expr->enum_name])) {
                throw new RuntimeException("Undefined enum '{$expr->enum_name}' on line {$expr->line}");
            }
            if (!isset($this->enum_defs[$expr->enum_name]['variants'][$expr->variant_name])) {
                throw new RuntimeException("Enum '{$expr->enum_name}' has no variant '{$expr->variant_name}' on line {$expr->line}");
            }
            return;
        }

        if ($expr instanceof MatchNode) {
            $this->checkStmt($expr);
            return;
        }

        if ($expr instanceof FieldAccessNode) {
            $this->checkExpr($expr->object);
            $obj_type = $this->exprType($expr->object);
            $base_type = $obj_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
            if (isset($this->struct_defs[$base_type])) {
                $sd = $this->struct_defs[$base_type];
                if ($sd['module'] !== null && $sd['module'] !== $this->current_module) {
                    foreach ($sd['fields'] as $f) {
                        if ($f['name'] === $expr->field_name && empty($f['pub'])) {
                            throw new RuntimeException("Field '{$expr->field_name}' of struct is private on line {$expr->line}");
                        }
                    }
                }
            }
            return;
        }

        if ($expr instanceof BorrowNode) {
            $this->checkExpr($expr->operand);
            $inner = $expr->operand instanceof DerefNode ? $expr->operand->operand : $expr->operand;
            if ($expr->mutable && $inner instanceof IdentNode) {
                $name = $inner->name;
                if (isset($this->vars[$name]) && !$this->vars[$name]['mutable']) {
                    throw new RuntimeException(
                        "Cannot borrow immutable variable '$name' as mutable on line {$expr->line}"
                    );
                }
            }
            return;
        }

        if ($expr instanceof DerefNode) {
            $this->checkExpr($expr->operand);
            return;
        }

        if ($expr instanceof UnaryOpNode) {
            $this->checkExpr($expr->operand);
            return;
        }

        if ($expr instanceof BinaryOpNode) {
            $this->checkExpr($expr->left);
            $this->checkExpr($expr->right);
            return;
        }

        if ($expr instanceof IfNode) {
            $this->checkStmt($expr);
            return;
        }

        if ($expr instanceof CallNode) {
            foreach ($expr->args as $arg) {
                $this->checkExpr($arg);
            }

            if ($expr->name !== 'exit' && isset($this->func_sigs[$expr->name])) {
                $sig = $this->func_sigs[$expr->name];
                $expected = count($sig['params']);
                $got = count($expr->args);
                if ($got !== $expected) {
                    throw new RuntimeException(
                        "Function '{$expr->name}' expects $expected arguments, got $got on line {$expr->line}"
                    );
                }
                foreach ($expr->args as $i => $arg) {
                    $arg_type = $this->exprType($arg);
                    $param_type = $sig['params'][$i]['type'];
                    if ($arg_type !== $param_type && !$this->integerTypesCompatible($param_type, $arg_type)) {
                        throw new RuntimeException(
                            "Type mismatch: argument " . ($i + 1) . " of '{$expr->name}' expects '$param_type', got '$arg_type' on line {$expr->line}"
                        );
                    }
                }
            }
            return;
        }

        if ($expr instanceof MethodCallNode) {
            $this->checkExpr($expr->receiver);
            foreach ($expr->args as $arg) {
                $this->checkExpr($arg);
            }

            $receiver_type = $this->exprType($expr->receiver);
            $base_type = $receiver_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);

            $mangled = "$base_type::{$expr->method_name}";
            if (!isset($this->func_sigs[$mangled])) {
                throw new RuntimeException("Method '{$expr->method_name}' not found for type '$base_type' on line {$expr->line}");
            }

            $sig = $this->func_sigs[$mangled];
            $expected = count($sig['params']) - 1;
            $got = count($expr->args);
            if ($got !== $expected) {
                throw new RuntimeException("Method '{$expr->method_name}' expects $expected arguments, got $got on line {$expr->line}");
            }

            $self_param_type = str_replace('self', $base_type, $sig['params'][0]['type']);
            if ($receiver_type !== $self_param_type) {
                // simple auto-borrow support
                if ($self_param_type === "&$base_type" && $receiver_type === $base_type) {
                     // okay, auto-borrow
                } elseif ($self_param_type === "&mut $base_type" && $receiver_type === $base_type) {
                     // okay, auto-borrow-mut
                } else {
                    throw new RuntimeException("Method '{$expr->method_name}' expects receiver '$self_param_type', got '$receiver_type' on line {$expr->line}");
                }
            }

            foreach ($expr->args as $i => $arg) {
                $arg_type = $this->exprType($arg);
                $param_type = str_replace('self', $base_type, $sig['params'][$i + 1]['type']);
                if ($arg_type !== $param_type && !$this->integerTypesCompatible($param_type, $arg_type)) {
                    throw new RuntimeException("Type mismatch: argument " . ($i + 1) . " of '{$expr->method_name}' expects '$param_type', got '$arg_type' on line {$expr->line}");
                }
            }

            // Handle move of receiver
            if ($sig['params'][0]['type'] === 'self' && !$this->isCopy($base_type)) {
                if ($expr->receiver instanceof IdentNode) {
                    $src = $expr->receiver->name;
                    $this->vars[$src]['state'] = 'moved';
                    $this->vars[$src]['moved_to'] = "method call {$expr->method_name}";
                    $this->vars[$src]['moved_line'] = $expr->line;
                }
            }
            return;
        }
    }

    private function mergeStates(array $before, array $after): array {
        $merged = $after;
        foreach ($merged as $name => &$info) {
            if (isset($before[$name]) && $before[$name]['state'] === 'owned' && $info['state'] === 'moved') {
                $info['state'] = 'moved';
            }
        }
        unset($info);
        return $merged;
    }

    private function isCopy(string $type): bool {
        if (str_starts_with($type, '&mut ')) return false;
        if (str_starts_with($type, '&')) return true;
        if (in_array($type, ['i32', 'bool'], true) || in_array($type, self::INT_PRIMITIVES, true)) return true;
        if (isset($this->enum_defs[$type])) return true;
        if (isset($this->struct_defs[$type])) {
            foreach ($this->struct_defs[$type]['fields'] as $f) {
                if (!$this->isCopy($f['type'])) return false;
            }
            return true;
        }
        return false;
    }

    private function exprType(mixed $expr): string {
        if ($expr instanceof UnitLitNode) return '()';
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof StrSliceNode) return '&str';
        if ($expr instanceof IndexNode) {
            $obj_type = $this->exprType($expr->object);
            return 'i32';
        }
        if ($expr instanceof StructLitNode) return $expr->struct_name;
        if ($expr instanceof EnumVariantNode) return $expr->enum_name;
        if ($expr instanceof MatchNode) {
            foreach ($expr->arms as $arm) {
                if (!empty($arm->body)) {
                    $last = end($arm->body);
                    if ($last instanceof ReturnNode && $last->value !== null) {
                        return $this->exprType($last->value);
                    }
                }
            }
            return 'i32';
        }
        if ($expr instanceof FieldAccessNode) {
            $obj_type = $this->exprType($expr->object);
            $base_type = $obj_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
            if (isset($this->struct_defs[$base_type])) {
                foreach ($this->struct_defs[$base_type]['fields'] as $f) {
                    if ($f['name'] === $expr->field_name) return $f['type'];
                }
            }
            return 'i32';
        }
        if ($expr instanceof IdentNode) {
            $type = $this->vars[$expr->name]['type'] ?? 'i32';
            if (str_starts_with($type, '&mut ')) return substr($type, 5);
            if (str_starts_with($type, '&')) return substr($type, 1);
            return $type;
        }
        if ($expr instanceof BorrowNode) {
            $prefix = $expr->mutable ? '&mut ' : '&';
            if ($expr->operand instanceof IdentNode) {
                $inner = $this->vars[$expr->operand->name]['type'] ?? 'i32';
                return $prefix . $inner;
            }
            if ($expr->operand instanceof DerefNode && $expr->operand->operand instanceof IdentNode) {
                $inner = $this->vars[$expr->operand->operand->name]['type'] ?? 'i32';
                if ($inner === 'String') return '&str';
                if ($inner === '&String' || $inner === '&mut String') return '&str';
                return $prefix . $inner;
            }
            return $prefix . 'i32';
        }
        if ($expr instanceof DerefNode) {
            $inner_type = $this->exprType($expr->operand);
            if (str_starts_with($inner_type, '&mut ')) return substr($inner_type, 5);
            if (str_starts_with($inner_type, '&')) return substr($inner_type, 1);
            return $inner_type;
        }
        if ($expr instanceof UnaryOpNode) {
            if ($expr->op === '!') return 'bool';
            return $this->exprType($expr->operand);
        }
        if ($expr instanceof IfNode) {
            if (!empty($expr->then_body)) {
                $last = end($expr->then_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->exprType($last->value);
                }
            }
            return 'i32';
        }
        if ($expr instanceof CallNode) {
            if (isset($this->func_sigs[$expr->name])) {
                return $this->func_sigs[$expr->name]['return_type'] ?? 'i32';
            }
            return 'i32';
        }
        if ($expr instanceof MethodCallNode) {
            $receiver_type = $this->exprType($expr->receiver);
            $base_type = $receiver_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
            $mangled = "$base_type::{$expr->method_name}";
            return $this->func_sigs[$mangled]['return_type'] ?? 'i32';
        }
        if ($expr instanceof BinaryOpNode) {
            if (in_array($expr->op, ['==', '!=', '<', '>', '<=', '>=', '&&', '||'])) {
                return 'bool';
            }
            return 'i32';
        }
        return 'i32';
    }
}
