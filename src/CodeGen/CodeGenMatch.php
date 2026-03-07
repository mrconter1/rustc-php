<?php

trait CodeGenMatch {
    private function generateMatch(MatchNode $node, bool $as_expr): void {
        $subject_slot = $this->match_subject_slots[spl_object_id($node)];

        $this->generateExpr($node->subject);
        $this->asm->store(X86::RBP, -$subject_slot['offset'], X86::RAX);
        if (!empty($subject_slot['has_payload'])) {
            $this->asm->store(X86::RBP, -($subject_slot['offset'] - 8), X86::RDX);
        }

        if (!empty($subject_slot['is_int'])) {
            $this->generateMatchInt($node, $subject_slot, $as_expr);
            return;
        }

        $enum_type    = $subject_slot['enum_type'];
        $end_patches = [];
        $pending_jne = null;

        foreach ($node->arms as $arm) {
            if ($arm->is_wildcard) continue;

            if ($pending_jne !== null) {
                $this->asm->patch32($pending_jne, $this->asm->pos() - $pending_jne - 4);
                $pending_jne = null;
            }

            $discriminant = $this->enum_defs[$enum_type]['variants'][$arm->variant_name]['discriminant'];
            $this->asm->load(X86::RAX, X86::RBP, -$subject_slot['offset']);
            $this->asm->mov_imm32(X86::RCX, $discriminant);
            $this->asm->cmp(X86::RAX, X86::RCX);
            $pending_jne = $this->asm->jne_rel32();

            if ($arm->binding !== null) {
                $binding_slot = $this->match_binding_slots[spl_object_id($arm)];
                $this->asm->load(X86::RCX, X86::RBP, -($subject_slot['offset'] - 8));
                $this->asm->store(X86::RBP, -$binding_slot['offset'], X86::RCX);
                $field_type = $this->enum_defs[$enum_type]['variants'][$arm->variant_name]['fields'][0] ?? 'i32';
                $this->vars[$arm->binding] = ['offset' => $binding_slot['offset'], 'type' => $field_type];
            }

            if ($as_expr) {
                $this->generateBodyForExpr($arm->body);
            } else {
                $this->generateBody($arm->body);
            }

            if ($arm->binding !== null) {
                unset($this->vars[$arm->binding]);
            }

            $end_patches[] = $this->asm->jmp_rel32();
        }

        if ($pending_jne !== null) {
            $this->asm->patch32($pending_jne, $this->asm->pos() - $pending_jne - 4);
        }

        foreach ($node->arms as $arm) {
            if (!$arm->is_wildcard) continue;
            if ($as_expr) {
                $this->generateBodyForExpr($arm->body);
            } else {
                $this->generateBody($arm->body);
            }
        }

        $end_pos = $this->asm->pos();
        foreach ($end_patches as $patch) {
            $this->asm->patch32($patch, $end_pos - $patch - 4);
        }
    }

    private function generateMatchInt(MatchNode $node, array $subject_slot, bool $as_expr): void {
        $end_patches = [];
        $pending_jne = null;
        foreach ($node->arms as $arm) {
            if ($arm->is_wildcard) continue;
            if ($pending_jne !== null) {
                $this->asm->patch32($pending_jne, $this->asm->pos() - $pending_jne - 4);
                $pending_jne = null;
            }
            $this->asm->load(X86::RAX, X86::RBP, -$subject_slot['offset']);
            $this->asm->mov_imm32(X86::RCX, $arm->int_lit);
            $this->asm->cmp(X86::RAX, X86::RCX);
            $pending_jne = $this->asm->jne_rel32();
            if ($as_expr) {
                $this->generateBodyForExpr($arm->body);
            } else {
                $this->generateBody($arm->body);
            }
            $end_patches[] = $this->asm->jmp_rel32();
        }
        if ($pending_jne !== null) {
            $this->asm->patch32($pending_jne, $this->asm->pos() - $pending_jne - 4);
        }
        foreach ($node->arms as $arm) {
            if (!$arm->is_wildcard) continue;
            if ($as_expr) {
                $this->generateBodyForExpr($arm->body);
            } else {
                $this->generateBody($arm->body);
            }
        }
        $end_pos = $this->asm->pos();
        foreach ($end_patches as $patch) {
            $this->asm->patch32($patch, $end_pos - $patch - 4);
        }
    }
}
