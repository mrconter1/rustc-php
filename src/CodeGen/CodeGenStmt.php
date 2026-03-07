<?php

trait CodeGenStmt {
    private function generateStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            $slot = $this->let_slots[spl_object_id($stmt)];

            if (!empty($stmt->bindings)) {
                $slots = $slot;
                $this->generateExpr($stmt->value);
                $this->asm->store(X86::RBP, -$slots[0]['offset'], X86::RAX);
                if (count($slots) > 1) {
                    $this->asm->store(X86::RBP, -$slots[1]['offset'], X86::RDX);
                }
                foreach ($stmt->bindings as $i => $name) {
                    $this->vars[$name] = $slots[$i];
                }
                return;
            }

            if ($stmt->value instanceof StructLitNode) {
                $sd = $this->struct_defs[$stmt->value->struct_name];
                foreach ($stmt->value->fields as $f) {
                    $this->generateExpr($f['value']);
                    $field_off = $sd['field_offsets'][$f['name']];
                    $this->asm->store(X86::RBP, -($slot['offset'] - $field_off), X86::RAX);
                }
            } else {
                $this->generateExpr($stmt->value);
                $this->asm->store(X86::RBP, -$slot['offset'], X86::RAX);
                if ($this->isFatType($slot['type']) || isset($this->struct_defs[$slot['type']])) {
                    $this->asm->store(X86::RBP, -($slot['offset'] - 8), X86::RDX);
                }
            }
            $this->vars[$stmt->name] = $slot;
            return;
        }

        if ($stmt instanceof DerefAssignNode) {
            $this->generateExpr($stmt->value);
            if ($stmt->operand instanceof IdentNode) {
                $var = $this->vars[$stmt->operand->name];
                $this->asm->push(X86::RAX);
                $this->asm->load(X86::RCX, X86::RBP, -$var['offset']);
                $this->asm->pop(X86::RAX);
                $this->asm->store(X86::RCX, 0, X86::RAX);
            }
            return;
        }

        if ($stmt instanceof FieldAssignNode) {
            $this->generateExpr($stmt->value);
            if ($stmt->object instanceof IdentNode) {
                $var = $this->vars[$stmt->object->name];
                $var_type = $var['type'];
                $is_ref = str_starts_with($var_type, '&mut ') || str_starts_with($var_type, '&');
                $base_type = $var_type;
                if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
                elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
                $sd = $this->struct_defs[$base_type];
                $field_off = $sd['field_offsets'][$stmt->field_name];
                if ($is_ref) {
                    $this->asm->push(X86::RAX);
                    $this->asm->load(X86::RCX, X86::RBP, -$var['offset']);
                    $this->asm->pop(X86::RAX);
                    $this->asm->store(X86::RCX, $field_off, X86::RAX);
                } else {
                    $this->asm->store(X86::RBP, -($var['offset'] - $field_off), X86::RAX);
                }
            }
            return;
        }

        if ($stmt instanceof AssignNode) {
            $this->generateExpr($stmt->value);
            $var = $this->vars[$stmt->name];
            $this->asm->store(X86::RBP, -$var['offset'], X86::RAX);
            if ($this->isFatType($var['type'])) {
                $this->asm->store(X86::RBP, -($var['offset'] - 8), X86::RDX);
            }
            return;
        }

        if ($stmt instanceof CompoundAssignNode) {
            $this->generateCompoundAssign($stmt);
            return;
        }

        if ($stmt instanceof ReturnNode) {
            if ($stmt->value !== null) {
                $this->generateExpr($stmt->value);
            }
            $jmp_patch = $this->asm->jmp_rel32();
            $this->return_patches[] = $jmp_patch;
            return;
        }

        if ($stmt instanceof IfNode) {
            $this->generateIf($stmt);
            return;
        }

        if ($stmt instanceof IfLetNode) {
            $this->generateIfLet($stmt);
            return;
        }

        if ($stmt instanceof WhileNode) {
            $this->generateWhile($stmt);
            return;
        }

        if ($stmt instanceof WhileLetNode) {
            $this->generateWhileLet($stmt);
            return;
        }

        if ($stmt instanceof LoopNode) {
            $this->generateLoop($stmt);
            return;
        }

        if ($stmt instanceof BreakNode) {
            if (empty($this->loop_stack)) {
                throw new RuntimeException("break outside of loop at line {$stmt->line}");
            }
            $ctx = &$this->loop_stack[count($this->loop_stack) - 1];
            $ctx['break_patches'][] = $this->asm->jmp_rel32();
            return;
        }

        if ($stmt instanceof ContinueNode) {
            if (empty($this->loop_stack)) {
                throw new RuntimeException("continue outside of loop at line {$stmt->line}");
            }
            $ctx = $this->loop_stack[count($this->loop_stack) - 1];
            $this->asm->jmp_to($ctx['continue_target']);
            return;
        }

        if ($stmt instanceof PrintlnNode) {
            $this->generatePrintln($stmt);
            return;
        }

        if ($stmt instanceof ExprStmtNode) {
            $this->generateExpr($stmt->expr);
            return;
        }

        if ($stmt instanceof MatchNode) {
            $this->generateMatch($stmt, false);
            return;
        }

        throw new RuntimeException("Unknown statement type: " . get_class($stmt));
    }

    private function generateCompoundAssign(CompoundAssignNode $stmt): void {
        $target = $stmt->target;
        $op = $stmt->op;
        if ($target instanceof IdentNode) {
            $var = $this->vars[$target->name];
            $this->asm->load(X86::RCX, X86::RBP, -$var['offset']);
            $this->generateExpr($stmt->value);
            $this->emitCompoundOp($op, $stmt->line);
            $this->asm->store(X86::RBP, -$var['offset'], X86::RAX);
            return;
        }
        if ($target instanceof DerefNode && $target->operand instanceof IdentNode) {
            $var = $this->vars[$target->operand->name];
            $this->asm->load(X86::R8, X86::RBP, -$var['offset']);
            $this->asm->load(X86::RCX, X86::R8, 0);
            $this->generateExpr($stmt->value);
            $this->emitCompoundOp($op, $stmt->line);
            $this->asm->store(X86::R8, 0, X86::RAX);
            return;
        }
        if ($target instanceof FieldAccessNode && $target->object instanceof IdentNode) {
            $var = $this->vars[$target->object->name];
            $base_type = $var['type'];
            if (str_starts_with($base_type, '&mut ')) $base_type = substr($base_type, 5);
            elseif (str_starts_with($base_type, '&')) $base_type = substr($base_type, 1);
            $sd = $this->struct_defs[$base_type];
            $field_off = $sd['field_offsets'][$target->field_name];
            if (str_starts_with($var['type'], '&')) {
                $this->asm->load(X86::R8, X86::RBP, -$var['offset']);
                $this->asm->load(X86::RCX, X86::R8, $field_off);
            } else {
                $this->asm->load(X86::RCX, X86::RBP, -($var['offset'] - $field_off));
            }
            $this->generateExpr($stmt->value);
            $this->emitCompoundOp($op, $stmt->line);
            if (str_starts_with($var['type'], '&')) {
                $this->asm->store(X86::R8, $field_off, X86::RAX);
            } else {
                $this->asm->store(X86::RBP, -($var['offset'] - $field_off), X86::RAX);
            }
            return;
        }
        throw new RuntimeException("Compound assignment target not supported at line {$stmt->line}");
    }

    private function emitCompoundOp(string $op, int $line): void {
        switch ($op) {
            case '+':
                $this->asm->add(X86::RAX, X86::RCX);
                break;
            case '-':
                $this->asm->mov(X86::RBX, X86::RAX);
                $this->asm->mov(X86::RAX, X86::RCX);
                $this->asm->mov(X86::RCX, X86::RBX);
                $this->asm->sub(X86::RAX, X86::RCX);
                break;
            case '*':
                $this->asm->imul(X86::RAX, X86::RCX);
                break;
            case '/':
                $this->asm->mov(X86::RBX, X86::RAX);
                $this->asm->mov(X86::RAX, X86::RCX);
                $this->asm->mov(X86::RCX, X86::RBX);
                $this->asm->cqo();
                $this->asm->idiv(X86::RCX);
                break;
            default:
                throw new RuntimeException("Unknown compound operator '$op' at line $line");
        }
    }

    private function generatePrintln(PrintlnNode $node): void {
        foreach ($node->parts as $part) {
            if (is_string($part)) {
                $this->emitWriteString($part);
            } else {
                $type = $this->exprType($part);
                $this->generateExpr($part);
                if ($type === 'String' || $type === '&str' || $type === '&mut str' || $type === 'str') {
                    $this->emitPrintString();
                } else {
                    $this->emitPrintInt();
                }
            }
        }
    }

    private function emitWriteString(string $str): void {
        $data_offset = $this->addData($str);

        $this->asm->mov_imm32(X86::RAX, 1);
        $this->asm->mov_imm32(X86::RDI, 1);
        $patch_pos = $this->asm->mov_imm64(X86::RSI);
        $this->data_patches[] = [$patch_pos, $data_offset];
        $this->asm->mov_imm32(X86::RDX, strlen($str));
        $this->asm->syscall();
    }

    private function emitPrintString(): void {
        $this->asm->mov(X86::RSI, X86::RAX);
        $this->asm->mov_imm32(X86::RAX, 1);
        $this->asm->mov_imm32(X86::RDI, 1);
        $this->asm->syscall();
    }

    private function emitPrintInt(): void {
        $this->asm->sub_imm8(X86::RSP, 32);
        $this->asm->lea_rsp(X86::R8, 31);
        $this->asm->xor_(X86::R9, X86::R9);
        $this->asm->mov_imm32(X86::RCX, 10);

        $this->asm->xor_(X86::RBX, X86::RBX);
        $this->asm->test(X86::RAX, X86::RAX);
        $jns_patch = $this->asm->jns_rel32();
        $this->asm->mov_imm32(X86::RBX, 1);
        $this->asm->neg(X86::RAX);
        $this->asm->patch32($jns_patch, $this->asm->pos() - $jns_patch - 4);

        $loop_start = $this->asm->pos();
        $this->asm->dec(X86::R8);
        $this->asm->xor_(X86::RDX, X86::RDX);
        $this->asm->div(X86::RCX);
        $this->asm->add_r8_imm8(X86::DL, 0x30);
        $this->asm->store_byte_reg(X86::R8, X86::DL);
        $this->asm->inc(X86::R9);
        $this->asm->test(X86::RAX, X86::RAX);
        $this->asm->jnz_to($loop_start);

        $this->asm->test(X86::RBX, X86::RBX);
        $jz_patch = $this->asm->jz_rel32();
        $this->asm->dec(X86::R8);
        $this->asm->store_byte_imm(X86::R8, 0x2D);
        $this->asm->inc(X86::R9);
        $this->asm->patch32($jz_patch, $this->asm->pos() - $jz_patch - 4);

        $this->asm->mov(X86::RSI, X86::R8);
        $this->asm->mov(X86::RDX, X86::R9);
        $this->asm->mov_imm32(X86::RAX, 1);
        $this->asm->mov_imm32(X86::RDI, 1);
        $this->asm->syscall();
        $this->asm->add_imm8(X86::RSP, 32);
    }

    private function generateIf(IfNode $node): void {
        $this->generateExpr($node->condition);
        $this->asm->test(X86::RAX, X86::RAX);

        if ($node->else_body === null) {
            $jz_patch = $this->asm->jz_rel32();
            $this->generateBody($node->then_body);
            $this->asm->patch32($jz_patch, $this->asm->pos() - $jz_patch - 4);
        } else {
            $jz_patch = $this->asm->jz_rel32();
            $this->generateBody($node->then_body);
            $jmp_patch = $this->asm->jmp_rel32();
            $this->asm->patch32($jz_patch, $this->asm->pos() - $jz_patch - 4);
            $this->generateBody($node->else_body);
            $this->asm->patch32($jmp_patch, $this->asm->pos() - $jmp_patch - 4);
        }
    }

    private function generateWhile(WhileNode $node): void {
        $loop_top = $this->asm->pos();
        $this->loop_stack[] = ['continue_target' => $loop_top, 'break_patches' => []];

        $this->generateExpr($node->condition);
        $this->asm->test(X86::RAX, X86::RAX);
        $jz_patch = $this->asm->jz_rel32();
        $this->generateBody($node->body);
        $this->asm->jmp_to($loop_top);

        $after_loop = $this->asm->pos();
        $this->asm->patch32($jz_patch, $after_loop - $jz_patch - 4);

        $ctx = array_pop($this->loop_stack);
        foreach ($ctx['break_patches'] as $patch_pos) {
            $this->asm->patch32($patch_pos, $after_loop - $patch_pos - 4);
        }
    }

    private function generateIfLet(IfLetNode $node): void {
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

        if ($node->binding !== null) {
            $binding_slot = $this->if_let_binding_slots[spl_object_id($node)];
            $this->asm->load(X86::RCX, X86::RBP, -($subject_slot['offset'] - 8));
            $this->asm->store(X86::RBP, -$binding_slot['offset'], X86::RCX);
            $field_type = $this->enum_defs[$enum_type]['variants'][$node->variant_name]['fields'][0] ?? 'i32';
            $this->vars[$node->binding] = ['offset' => $binding_slot['offset'], 'type' => $field_type];
        }
        $this->generateBody($node->then_body);
        if ($node->binding !== null) {
            unset($this->vars[$node->binding]);
        }

        $jmp_end = $node->else_body !== null ? $this->asm->jmp_rel32() : null;
        $else_pos = $this->asm->pos();
        $this->asm->patch32($jne_else, $else_pos - $jne_else - 4);

        if ($node->else_body !== null) {
            $this->generateBody($node->else_body);
            $end_pos = $this->asm->pos();
            $this->asm->patch32($jmp_end, $end_pos - $jmp_end - 4);
        }
    }

    private function generateWhileLet(WhileLetNode $node): void {
        $subject_slot = $this->if_let_subject_slots[spl_object_id($node)];
        $enum_type = $subject_slot['enum_type'];
        $discriminant = $this->enum_defs[$enum_type]['variants'][$node->variant_name]['discriminant'];

        $loop_top = $this->asm->pos();
        $this->loop_stack[] = ['continue_target' => $loop_top, 'break_patches' => []];

        $this->generateExpr($node->subject);
        $this->asm->store(X86::RBP, -$subject_slot['offset'], X86::RAX);
        if ($subject_slot['has_payload']) {
            $this->asm->store(X86::RBP, -($subject_slot['offset'] - 8), X86::RDX);
        }
        $this->asm->load(X86::RAX, X86::RBP, -$subject_slot['offset']);
        $this->asm->mov_imm32(X86::RCX, $discriminant);
        $this->asm->cmp(X86::RAX, X86::RCX);
        $jne_after = $this->asm->jne_rel32();

        if ($node->binding !== null) {
            $binding_slot = $this->if_let_binding_slots[spl_object_id($node)];
            $this->asm->load(X86::RCX, X86::RBP, -($subject_slot['offset'] - 8));
            $this->asm->store(X86::RBP, -$binding_slot['offset'], X86::RCX);
            $field_type = $this->enum_defs[$enum_type]['variants'][$node->variant_name]['fields'][0] ?? 'i32';
            $this->vars[$node->binding] = ['offset' => $binding_slot['offset'], 'type' => $field_type];
        }
        $this->generateBody($node->body);
        if ($node->binding !== null) {
            unset($this->vars[$node->binding]);
        }
        $this->asm->jmp_to($loop_top);

        $after_loop = $this->asm->pos();
        $this->asm->patch32($jne_after, $after_loop - $jne_after - 4);
        $ctx = array_pop($this->loop_stack);
        foreach ($ctx['break_patches'] as $patch_pos) {
            $this->asm->patch32($patch_pos, $after_loop - $patch_pos - 4);
        }
    }

    private function generateLoop(LoopNode $node): void {
        $loop_top = $this->asm->pos();
        $this->loop_stack[] = ['continue_target' => $loop_top, 'break_patches' => []];

        $this->generateBody($node->body);
        $this->asm->jmp_to($loop_top);

        $after_loop = $this->asm->pos();
        $ctx = array_pop($this->loop_stack);
        foreach ($ctx['break_patches'] as $patch_pos) {
            $this->asm->patch32($patch_pos, $after_loop - $patch_pos - 4);
        }
    }
}
