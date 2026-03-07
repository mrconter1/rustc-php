<?php

trait CodeGenTypes {
    private function tupleElementTypes(string $type): ?array {
        if (strlen($type) < 2 || $type[0] !== '(' || $type[strlen($type) - 1] !== ')') return null;
        $inner = substr($type, 1, -1);
        $depth = 0;
        $start = 0;
        $elements = [];
        for ($i = 0; $i <= strlen($inner); $i++) {
            $c = $i < strlen($inner) ? $inner[$i] : ',';
            if ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif (($c === ',' && $depth === 0) || $i === strlen($inner)) {
                $seg = trim(substr($inner, $start, $i - $start));
                if ($seg !== '') $elements[] = $seg;
                $start = $i + 1;
            }
        }
        return $elements;
    }

    private function isRawPointerType(string $type): bool {
        return str_starts_with($type, '*const ') || str_starts_with($type, '*mut ');
    }

    private function typeSize(string $type): int {
        if ($type === '()') return 0;
        if ($this->isRawPointerType($type)) return 8;
        if (preg_match('/^Box<(.+)>$/', $type, $m)) return 8;
        $elements = $this->tupleElementTypes($type);
        if ($elements !== null) {
            $n = 0;
            foreach ($elements as $t) $n += $this->typeSize($t);
            return $n;
        }
        if ($type === 'u128') return 16;
        if (in_array($type, self::INT_PRIMITIVES, true) || $type === 'bool') return 8;
        if (isset($this->struct_defs[$type])) return $this->struct_defs[$type]['size'];
        if (isset($this->enum_defs[$type])) return $this->enum_defs[$type]['size'];
        return 8;
    }

    private function isFatType(string $type): bool {
        if ($type === 'String') return true;
        if ($type === 'str' || $type === '&str' || $type === '&mut str') return true;
        if ($type === 'u128') return true;
        if (preg_match('/^&(mut )?\[.+\]$/', $type)) return true;
        if ($this->tupleElementTypes($type) !== null) return $this->typeSize($type) > 8;
        if (isset($this->enum_defs[$type]) && $this->enum_defs[$type]['has_payload']) return true;
        if (isset($this->struct_defs[$type]) && $this->struct_defs[$type]['size'] > 8) return true;
        return false;
    }

    private function exprType(mixed $expr): string {
        if ($expr instanceof UnitLitNode) return '()';
        if ($expr instanceof TupleLitNode) {
            $parts = [];
            foreach ($expr->elements as $e) $parts[] = $this->exprType($e);
            return '(' . implode(',', $parts) . ')';
        }
        if ($expr instanceof TupleIndexNode) {
            $obj_type = $this->exprType($expr->object);
            $elements = $this->tupleElementTypes($obj_type);
            if ($elements !== null && isset($elements[$expr->index])) return $elements[$expr->index];
            return 'i32';
        }
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof StrSliceNode) return '&str';
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
        if ($expr instanceof CastNode) {
            return $expr->target_type;
        }
        if ($expr instanceof IdentNode) {
            if (isset($this->vars[$expr->name])) {
                $type = $this->vars[$expr->name]['type'];
            } elseif (isset($this->const_exprs[$expr->name])) {
                return $this->const_exprs[$expr->name]['type'];
            } elseif (isset($this->static_offsets[$expr->name])) {
                return $this->static_offsets[$expr->name]['type'];
            } else {
                $type = 'i32';
            }
            if (str_starts_with($type, '&mut ')) return substr($type, 5);
            if (str_starts_with($type, '&')) return substr($type, 1);
            return $type;
        }
        if ($expr instanceof BorrowNode) {
            $prefix = $expr->mutable ? '&mut ' : '&';
            if ($expr->operand instanceof IdentNode) {
                return $prefix . ($this->vars[$expr->operand->name]['type'] ?? 'i32');
            }
            return $prefix . 'i32';
        }
        if ($expr instanceof DerefNode) {
            $inner_type = $this->exprType($expr->operand);
            if (preg_match('/^Box<(.+)>$/', $inner_type, $m)) return $m[1];
            if (str_starts_with($inner_type, '&mut ')) return substr($inner_type, 5);
            if (str_starts_with($inner_type, '&')) return substr($inner_type, 1);
            return $inner_type;
        }
        if ($expr instanceof BinaryOpNode) return 'i32';
        if ($expr instanceof IndexNode) {
            $obj_type = $this->exprType($expr->object);
            if ($obj_type === '&str' || $obj_type === '&mut str' || $obj_type === 'str') return 'i32';
            if (preg_match('/^&(mut )?\[i32\]$/', $obj_type)) return 'i32';
            $base = $obj_type;
            if (str_starts_with($base, '&mut ')) $base = substr($base, 5);
            elseif (str_starts_with($base, '&')) $base = substr($base, 1);
            if ($base === 'alloc__VecI32') return 'i32';
            return 'i32';
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
        if ($expr instanceof IfLetNode) {
            if (!empty($expr->then_body)) {
                $last = end($expr->then_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->exprType($last->value);
                }
            }
            if ($expr->else_body !== null && !empty($expr->else_body)) {
                $last = end($expr->else_body);
                if ($last instanceof ReturnNode && $last->value !== null) {
                    return $this->exprType($last->value);
                }
            }
            return 'i32';
        }
        if ($expr instanceof CallNode) {
            if ($expr->name === 'Box::new' && count($expr->args) === 1) {
                return 'Box<' . $this->exprType($expr->args[0]) . '>';
            }
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
        return 'i32';
    }
}
