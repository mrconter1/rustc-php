<?php

trait CodeGenExpr {
    private function generateExpr(mixed $expr): void {
        if ($expr instanceof UnitLitNode) {
            return;
        }
        if ($expr instanceof IntLitNode) {
            $this->asm->mov_imm32(X86::RAX, $expr->value);
            return;
        }

        if ($expr instanceof BoolLitNode) {
            $this->asm->mov_imm32(X86::RAX, $expr->value ? 1 : 0);
            return;
        }

        if ($expr instanceof StringFromNode) {
            $data_offset = $this->addData($expr->value);
            $patch_pos = $this->asm->mov_imm64(X86::RAX);
            $this->data_patches[] = [$patch_pos, $data_offset];
            $this->asm->mov_imm32(X86::RDX, strlen($expr->value));
            return;
        }
        if ($expr instanceof StrSliceNode) {
            $data_offset = $this->addData($expr->value);
            $patch_pos = $this->asm->mov_imm64(X86::RAX);
            $this->data_patches[] = [$patch_pos, $data_offset];
            $this->asm->mov_imm32(X86::RDX, strlen($expr->value));
            return;
        }

        if ($expr instanceof StructLitNode) {
            $sd = $this->struct_defs[$expr->struct_name];
            $fields = $expr->fields;
            if (count($fields) >= 1) {
                $this->generateExpr($fields[0]['value']);
                if (count($fields) >= 2) {
                    $this->asm->push(X86::RAX);
                    $this->generateExpr($fields[1]['value']);
                    $this->asm->mov(X86::RDX, X86::RAX);
                    $this->asm->pop(X86::RAX);
                }
            }
            return;
        }

        if ($expr instanceof TupleLitNode) {
            $el = $expr->elements;
            if (count($el) > 2) {
                throw new RuntimeException("Only 2-element tuples supported in codegen at line {$expr->line}");
            }
            if (count($el) === 0) return;
            $this->generateExpr($el[0]);
            if (count($el) >= 2) {
                $this->asm->push(X86::RAX);
                $this->generateExpr($el[1]);
                $this->asm->mov(X86::RDX, X86::RAX);
                $this->asm->pop(X86::RAX);
            }
            return;
        }

        if ($expr instanceof CastNode) {
            $this->generateExpr($expr->expr);
            return;
        }

        if ($expr instanceof IdentNode) {
            if (isset($this->vars[$expr->name])) {
                $var = $this->vars[$expr->name];
                $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
            } elseif (isset($this->const_exprs[$expr->name])) {
                $this->generateExpr($this->const_exprs[$expr->name]['expr']);
                return;
            } elseif (isset($this->static_offsets[$expr->name])) {
                $info = $this->static_offsets[$expr->name];
                $patch_pos = $this->asm->mov_imm64(X86::RAX);
                $this->data_patches[] = [$patch_pos, $info['offset']];
                $this->asm->load(X86::RAX, X86::RAX, 0);
                return;
            } else {
                throw new RuntimeException("Undefined variable '{$expr->name}' at line {$expr->line}");
            }
            if ($this->isRawPointerType($var['type'])) {
                return;
            }
            if ($var['type'] === 'String') {
                $this->asm->load(X86::RDX, X86::RBP, -($var['offset'] - 8));
            } elseif ($var['type'] === '&String' || $var['type'] === '&mut String') {
                $this->asm->load(X86::RDX, X86::RAX, 8);
                $this->asm->load(X86::RAX, X86::RAX, 0);
            } elseif ($this->isFatType($var['type'])) {
                $this->asm->load(X86::RDX, X86::RBP, -($var['offset'] - 8));
            } elseif (str_starts_with($var['type'], '&')) {
                $inner = $var['type'];
                if (str_starts_with($inner, '&mut ')) $inner = substr($inner, 5);
                else $inner = substr($inner, 1);
                if (!isset($this->struct_defs[$inner]) && !isset($this->enum_defs[$inner])) {
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                }
            }
            return;
        }

        if ($expr instanceof EnumVariantNode) {
            $enum_def    = $this->enum_defs[$expr->enum_name];
            $variant_def = $enum_def['variants'][$expr->variant_name];
            $discriminant = $variant_def['discriminant'];
            $has_field    = !empty($variant_def['fields']);
            if ($has_field && !empty($expr->args)) {
                $this->generateExpr($expr->args[0]);
                $this->asm->mov(X86::RDX, X86::RAX);
                $this->asm->mov_imm32(X86::RAX, $discriminant);
            } else {
                $this->asm->mov_imm32(X86::RAX, $discriminant);
                if ($enum_def['has_payload']) {
                    $this->asm->mov_imm32(X86::RDX, 0);
                }
            }
            return;
        }

        if ($expr instanceof MatchNode) {
            $this->generateMatch($expr, true);
            return;
        }

        if ($expr instanceof TupleIndexNode) {
            $this->generateExpr($expr->object);
            if ($expr->index === 1) {
                $this->asm->mov(X86::RAX, X86::RDX);
            }
            return;
        }

        if ($expr instanceof FieldAccessNode) {
            if ($expr->object instanceof IdentNode) {
                $var = $this->vars[$expr->object->name];
                $var_type = $var['type'];
                $is_ref = str_starts_with($var_type, '&mut ') || str_starts_with($var_type, '&');
                $base_type = $var_type;
                if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
                elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
                $sd = $this->struct_defs[$base_type];
                $field_off = $sd['field_offsets'][$expr->field_name];
                if ($is_ref) {
                    $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
                    $this->asm->load(X86::RAX, X86::RAX, $field_off);
                } else {
                    $this->asm->load(X86::RAX, X86::RBP, -($var['offset'] - $field_off));
                }
            }
            return;
        }

        if ($expr instanceof DerefNode) {
            if ($expr->operand instanceof IdentNode) {
                $name = $expr->operand->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' at line {$expr->line}");
                }
                $var = $this->vars[$name];
                $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
                $inner_type = $var['type'];
                if (preg_match('/^Box<.+>$/', $inner_type)) {
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                    return;
                }
                if ($this->isRawPointerType($inner_type)) {
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                    return;
                }
                if (str_starts_with($inner_type, '&mut ')) {
                    $inner_type = substr($inner_type, 5);
                } elseif (str_starts_with($inner_type, '&')) {
                    $inner_type = substr($inner_type, 1);
                }
                if ($inner_type === 'String') {
                    $this->asm->load(X86::RDX, X86::RAX, 8);
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                } else {
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                }
            } else {
                $this->generateExpr($expr->operand);
                $this->asm->load(X86::RAX, X86::RAX, 0);
            }
            return;
        }

        if ($expr instanceof BorrowNode) {
            if ($expr->operand instanceof DerefNode && $expr->operand->operand instanceof IdentNode) {
                $name = $expr->operand->operand->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' at line {$expr->line}, column " . (isset($expr->column) ? $expr->column : 1));
                }
                $var = $this->vars[$name];
                if ($var['type'] === 'String') {
                    $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
                    $this->asm->load(X86::RDX, X86::RBP, -($var['offset'] - 8));
                    return;
                }
                if ($var['type'] === '&String' || $var['type'] === '&mut String') {
                    $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
                    $this->asm->load(X86::RDX, X86::RAX, 8);
                    $this->asm->load(X86::RAX, X86::RAX, 0);
                    return;
                }
            }
            if ($expr->operand instanceof FieldAccessNode && $expr->operand->object instanceof IdentNode) {
                $name = $expr->operand->object->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' at line {$expr->line}, column " . (isset($expr->column) ? $expr->column : 1));
                }
                $var = $this->vars[$name];
                $base_type = $var['type'];
                if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
                elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
                $sd = $this->struct_defs[$base_type] ?? null;
                if ($sd !== null && isset($sd['field_offsets'][$expr->operand->field_name])) {
                    $field_off = $sd['field_offsets'][$expr->operand->field_name];
                    $this->asm->lea(X86::RAX, X86::RBP, -($var['offset'] - $field_off));
                    return;
                }
            }
            if ($expr->operand instanceof IdentNode) {
                $name = $expr->operand->name;
                if (!isset($this->vars[$name])) {
                    throw new RuntimeException("Undefined variable '$name' at line {$expr->line}, column " . (isset($expr->column) ? $expr->column : 1));
                }
                $var = $this->vars[$name];
                $this->asm->lea(X86::RAX, X86::RBP, -$var['offset']);
                return;
            }
            $this->stack_size += 8;
            $temp_offset = $this->stack_size;
            $this->generateExpr($expr->operand);
            $this->asm->store(X86::RBP, -$temp_offset, X86::RAX);
            $this->asm->lea(X86::RAX, X86::RBP, -$temp_offset);
            return;
        }

        if ($expr instanceof UnaryOpNode) {
            $this->generateExpr($expr->operand);
            if ($expr->op === '-') {
                $this->asm->neg(X86::RAX);
            } elseif ($expr->op === '!') {
                $this->asm->test(X86::RAX, X86::RAX);
                $this->asm->setcc(X86::CC_E);
                $this->asm->movzx_rax_al();
            }
            return;
        }

        if ($expr instanceof BinaryOpNode) {
            $this->generateExpr($expr->left);
            $this->asm->push(X86::RAX);
            $this->generateExpr($expr->right);
            $this->asm->mov(X86::RCX, X86::RAX);
            $this->asm->pop(X86::RAX);

            switch ($expr->op) {
                case '+':  $this->asm->add(X86::RAX, X86::RCX); break;
                case '-':  $this->asm->sub(X86::RAX, X86::RCX); break;
                case '*':  $this->asm->imul(X86::RAX, X86::RCX); break;
                case '/':
                    $this->asm->cqo();
                    $this->asm->idiv(X86::RCX);
                    break;
                case '%':
                    $this->asm->cqo();
                    $this->asm->idiv(X86::RCX);
                    $this->asm->mov(X86::RAX, X86::RDX);
                    break;
                case '&&': $this->asm->test(X86::RAX, X86::RCX);
                           $this->asm->setcc(X86::CC_NE);
                           $this->asm->movzx_rax_al();
                           break;
                case '||':
                    $this->asm->add(X86::RAX, X86::RCX);
                    $this->asm->test(X86::RAX, X86::RAX);
                    $this->asm->setcc(X86::CC_NE);
                    $this->asm->movzx_rax_al();
                    break;
                case '==': $this->emitCmp(X86::CC_E); break;
                case '!=': $this->emitCmp(X86::CC_NE); break;
                case '<':  $this->emitCmp(X86::CC_L); break;
                case '>':  $this->emitCmp(X86::CC_G); break;
                case '<=': $this->emitCmp(X86::CC_LE); break;
                case '>=': $this->emitCmp(X86::CC_GE); break;
                default:
                    throw new RuntimeException("Unknown operator '{$expr->op}' at line {$expr->line}");
            }
            return;
        }

        if ($expr instanceof CallNode) {
            if ($expr->name === 'exit') {
                if (count($expr->args) !== 1) {
                    throw new RuntimeException("exit() takes exactly 1 argument at line {$expr->line}");
                }
                $this->generateExpr($expr->args[0]);
                $this->asm->mov(X86::RDI, X86::RAX);
                $this->asm->mov_imm32(X86::RAX, 60);
                $this->asm->syscall();
                return;
            }
            if ($expr->name === 'Box::new' && count($expr->args) === 1) {
                $inner_type = $this->exprType($expr->args[0]);
                $size = $this->typeSize($inner_type);
                $this->generateExpr($expr->args[0]);
                if ($this->isFatType($inner_type)) {
                    $this->asm->push(X86::RDX);
                }
                $this->asm->push(X86::RAX);
                $this->asm->mov_imm32(X86::RDI, $size);
                $patch_pos = $this->asm->call_rel32();
                $this->call_patches[] = [$patch_pos, 'alloc'];
                $this->asm->mov(X86::RCX, X86::RAX);
                $this->asm->pop(X86::RAX);
                $this->asm->store(X86::RCX, 0, X86::RAX);
                if ($this->isFatType($inner_type)) {
                    $this->asm->pop(X86::RDX);
                    $this->asm->store(X86::RCX, 8, X86::RDX);
                }
                $this->asm->mov(X86::RAX, X86::RCX);
                return;
            }

            $n = count($expr->args);
            if ($n > 6) {
                throw new RuntimeException("Functions with more than 6 arguments are not supported at line {$expr->line}");
            }

            $sig = $this->func_sigs[$expr->name] ?? null;
            $reg_idx = 0;
            $param_reg_map = [];
            for ($i = 0; $i < $n; $i++) {
                $ptype = $sig ? $sig['params'][$i]['type'] : 'i32';
                $param_reg_map[$i] = ['reg_idx' => $reg_idx, 'type' => $ptype];
                $reg_idx += $this->isFatType($ptype) ? 2 : 1;
            }

            for ($i = 0; $i < $n; $i++) {
                $this->generateExpr($expr->args[$i]);
                if ($this->isFatType($param_reg_map[$i]['type'])) {
                    $this->asm->push(X86::RDX);
                }
                $this->asm->push(X86::RAX);
            }
            for ($i = $n - 1; $i >= 0; $i--) {
                $ri = $param_reg_map[$i]['reg_idx'];
                $this->asm->pop(self::ARG_REGS[$ri]);
                if ($this->isFatType($param_reg_map[$i]['type'])) {
                    $this->asm->pop(self::ARG_REGS[$ri + 1]);
                }
            }

            $patch_pos = $this->asm->call_rel32();
            $this->call_patches[] = [$patch_pos, $expr->name];
            return;
        }

        if ($expr instanceof MethodCallNode) {
            $receiver_type = $this->exprType($expr->receiver);
            $base_type = $receiver_type;
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);

            $mangled = "$base_type::{$expr->method_name}";
            if ($mangled === 'str::len' && count($expr->args) === 0) {
                $this->generateExpr($expr->receiver);
                $this->asm->mov(X86::RAX, X86::RDX);
                return;
            }
            $sig = $this->func_sigs[$mangled];

            $n = count($expr->args);
            $total_args = $n + 1;
            if ($total_args > 6) {
                throw new RuntimeException("Methods with more than 6 total arguments are not supported at line {$expr->line}");
            }

            $reg_idx = 0;
            $arg_reg_map = [];
            for ($i = 0; $i < $total_args; $i++) {
                $ptype = str_replace('self', $base_type, $sig['params'][$i]['type']);
                $arg_reg_map[$i] = ['reg_idx' => $reg_idx, 'type' => $ptype];
                $reg_idx += $this->isFatType($ptype) ? 2 : 1;
            }

            // Receiver (arg 0)
            $self_param_type = $arg_reg_map[0]['type'];
            $raw_receiver_ref = false;
            if ($expr->receiver instanceof IdentNode && isset($this->vars[$expr->receiver->name])) {
                $raw_type = $this->vars[$expr->receiver->name]['type'];
                $raw_receiver_ref = str_starts_with($raw_type, '&') || str_starts_with($raw_type, '&mut ');
            }
            if (!$raw_receiver_ref && $self_param_type === "&$base_type" && $receiver_type === $base_type) {
                if (!($expr->receiver instanceof IdentNode)) throw new RuntimeException("Auto-borrow only supported for variables at line {$expr->line}");
                $var = $this->vars[$expr->receiver->name];
                $this->asm->lea(X86::RAX, X86::RBP, -$var['offset']);
            } elseif (!$raw_receiver_ref && $self_param_type === "&mut $base_type" && $receiver_type === $base_type) {
                if (!($expr->receiver instanceof IdentNode)) throw new RuntimeException("Auto-borrow-mut only supported for variables at line {$expr->line}");
                $var = $this->vars[$expr->receiver->name];
                $this->asm->lea(X86::RAX, X86::RBP, -$var['offset']);
            } else {
                $this->generateExpr($expr->receiver);
            }
            if ($this->isFatType($arg_reg_map[0]['type'])) {
                $this->asm->push(X86::RDX);
            }
            $this->asm->push(X86::RAX);

            // Other args
            for ($i = 0; $i < $n; $i++) {
                $this->generateExpr($expr->args[$i]);
                if ($this->isFatType($arg_reg_map[$i + 1]['type'])) {
                    $this->asm->push(X86::RDX);
                }
                $this->asm->push(X86::RAX);
            }

            // Pop into regs
            for ($i = $total_args - 1; $i >= 0; $i--) {
                $ri = $arg_reg_map[$i]['reg_idx'];
                $this->asm->pop(self::ARG_REGS[$ri]);
                if ($this->isFatType($arg_reg_map[$i]['type'])) {
                    $this->asm->pop(self::ARG_REGS[$ri + 1]);
                }
            }

            $patch_pos = $this->asm->call_rel32();
            $this->call_patches[] = [$patch_pos, $mangled];
            return;
        }

        if ($expr instanceof IndexNode) {
            $obj_type = $this->exprType($expr->object);
            $this->generateExpr($expr->object);
            $this->asm->push(X86::RDX);
            $this->asm->push(X86::RAX);
            $this->generateExpr($expr->index);
            $this->asm->mov(X86::RCX, X86::RAX);
            $this->asm->pop(X86::RAX);
            $this->asm->pop(X86::RDX);
            if ($obj_type === '&str' || $obj_type === '&mut str' || $obj_type === 'str') {
                $this->asm->add(X86::RAX, X86::RCX);
                $this->asm->movzx_rax_byte_at(X86::RAX, 0);
            } elseif (preg_match('/^&(mut )?\[i32\]$/', $obj_type)) {
                $this->asm->imul_imm8(X86::RCX, X86::RCX, 4);
                $this->asm->add(X86::RAX, X86::RCX);
                $this->asm->load32(X86::RAX, X86::RAX, 0);
            } else {
                $this->asm->add(X86::RAX, X86::RCX);
                $this->asm->load(X86::RAX, X86::RAX, 0);
            }
            return;
        }

        if ($expr instanceof IfNode) {
            $this->generateIfExpr($expr);
            return;
        }

        if ($expr instanceof IfLetNode) {
            $this->generateIfLetExpr($expr);
            return;
        }

        throw new RuntimeException("Unknown expression type: " . get_class($expr));
    }

    private function generateIfExpr(IfNode $node): void {
        if ($node->else_body === null) {
            throw new RuntimeException("if expression requires else branch at line {$node->line}");
        }

        $this->generateExpr($node->condition);
        $this->asm->test(X86::RAX, X86::RAX);

        $jz_patch = $this->asm->jz_rel32();
        $this->generateBodyForExpr($node->then_body);
        $jmp_patch = $this->asm->jmp_rel32();
        $this->asm->patch32($jz_patch, $this->asm->pos() - $jz_patch - 4);
        $this->generateBodyForExpr($node->else_body);
        $this->asm->patch32($jmp_patch, $this->asm->pos() - $jmp_patch - 4);
    }

    private function generateIfLetExpr(IfLetNode $node): void {
        if ($node->else_body === null) {
            throw new RuntimeException("if let expression requires else branch at line {$node->line}");
        }
        $subject_slot = $this->if_let_subject_slots[spl_object_id($node)];
        $enum_type = $subject_slot['enum_type'];
        $discriminant = $this->enum_defs[$enum_type]['variants'][$node->variant_name]['discriminant'];

        $this->generateExpr($node->subject);
        $this->asm->store(X86::RBP, -$subject_slot['offset'], X86::RAX);
        if ($subject_slot['has_payload']) {
            $this->asm->store(X86::RBP, -($subject_slot['offset'] - 8), X86::RDX);
        }
        $this->asm->load(X86::RAX, X86::RBP, -$subject_slot['offset']);
        $this->asm->mov_imm32(X86::RCX, $discriminant);
        $this->asm->cmp(X86::RAX, X86::RCX);
        $jne_else = $this->asm->jne_rel32();

        if ($node->literal_value !== null) {
            $this->asm->load(X86::RAX, X86::RBP, -($subject_slot['offset'] - 8));
            $this->asm->mov_imm32(X86::RCX, $node->literal_value);
            $this->asm->cmp(X86::RAX, X86::RCX);
            $jne_else2 = $this->asm->jne_rel32();
        }
        if ($node->binding !== null) {
            $binding_slot = $this->if_let_binding_slots[spl_object_id($node)];
            $this->asm->load(X86::RCX, X86::RBP, -($subject_slot['offset'] - 8));
            $this->asm->store(X86::RBP, -$binding_slot['offset'], X86::RCX);
            $field_type = $this->enum_defs[$enum_type]['variants'][$node->variant_name]['fields'][0] ?? 'i32';
            $this->vars[$node->binding] = ['offset' => $binding_slot['offset'], 'type' => $field_type];
        }
        $this->generateBodyForExpr($node->then_body);
        if ($node->binding !== null) {
            unset($this->vars[$node->binding]);
        }
        $jmp_end = $this->asm->jmp_rel32();
        $else_pos = $this->asm->pos();
        $this->asm->patch32($jne_else, $else_pos - $jne_else - 4);
        if ($node->literal_value !== null) {
            $this->asm->patch32($jne_else2, $else_pos - $jne_else2 - 4);
        }
        $this->generateBodyForExpr($node->else_body);
        $this->asm->patch32($jmp_end, $this->asm->pos() - $jmp_end - 4);
    }

    private function generateBodyForExpr(array $stmts): void {
        $n = count($stmts);
        for ($i = 0; $i < $n; $i++) {
            $stmt = $stmts[$i];
            if ($i === $n - 1 && $stmt instanceof ReturnNode && $stmt->value !== null) {
                $this->generateExpr($stmt->value);
            } elseif ($i === $n - 1 && $stmt instanceof IfNode) {
                $this->generateIfExpr($stmt);
            } elseif ($i === $n - 1 && $stmt instanceof IfLetNode) {
                $this->generateIfLetExpr($stmt);
            } elseif ($i === $n - 1 && $stmt instanceof MatchNode) {
                $this->generateMatch($stmt, true);
            } else {
                $this->generateStmt($stmt);
            }
        }
    }

    private function emitCmp(int $cc): void {
        $this->asm->cmp(X86::RAX, X86::RCX);
        $this->asm->setcc($cc);
        $this->asm->movzx_rax_al();
    }
}
