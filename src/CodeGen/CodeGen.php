<?php

require_once __DIR__ . '/../Parser.php';
require_once __DIR__ . '/../Elf.php';
require_once __DIR__ . '/../X86.php';
require_once __DIR__ . '/CodeGenTypes.php';
require_once __DIR__ . '/CodeGenExpr.php';
require_once __DIR__ . '/CodeGenMatch.php';
require_once __DIR__ . '/CodeGenStmt.php';

class CodeGen {
    use CodeGenTypes;
    use CodeGenExpr;
    use CodeGenMatch;
    use CodeGenStmt;

    private X86    $asm;
    private string $data = '';
    private array  $data_patches = [];
    private array  $vars = [];
    private int    $stack_size = 0;
    private int    $code_base_addr;

    private array $func_addrs = [];
    private array $func_sigs = [];
    private array $struct_defs = [];
    private array $enum_defs   = [];
    private array $call_patches = [];
    private array $return_patches = [];
    private array $let_slots = [];
    private array $loop_stack = [];
    private array $match_subject_slots = [];
    private array $match_binding_slots  = [];
    private array $if_let_subject_slots = [];
    private array $if_let_binding_slots = [];
    private array $const_exprs = [];
    private array $static_offsets = [];

    private int $heap_start_off;
    private int $heap_cur_off;
    private int $heap_end_off;

    private const ARG_REGS = [X86::RDI, X86::RSI, X86::RDX, X86::RCX, X86::R8, X86::R9];

    private const INT_PRIMITIVES = ['i32', 'u8', 'u16', 'u32', 'u64', 'u128', 'usize'];

    public function __construct() {
        $this->asm = new X86();
    }

    public function generate(ProgramNode $program, int $code_base_addr): string {
        $this->code_base_addr = $code_base_addr;
        $this->asm->reset();
        $this->data = '';
        $this->heap_start_off = $this->addDataQuad(0);
        $this->heap_cur_off   = $this->addDataQuad(0);
        $this->heap_end_off   = $this->addDataQuad(0);
        $this->data_patches = [];
        $this->call_patches = [];
        $this->func_addrs = [];
        $this->func_sigs = [];
        $this->struct_defs = [];

        foreach ($program->structs as $sd) {
            $this->struct_defs[$sd->name] = [
                'fields' => $sd->fields,
                'size' => 0,
                'field_offsets' => [],
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
            $discriminant = 0;
            $has_payload  = false;
            $variants_map = [];
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

        foreach ($program->consts as $c) {
            $this->const_exprs[$c->name] = ['expr' => $c->value, 'type' => $c->type];
        }
        foreach ($program->statics as $s) {
            $offset = $this->emitStaticInit($s);
            $this->static_offsets[$s->name] = ['offset' => $offset, 'type' => $s->type];
        }

        foreach ($program->functions as $fn) {
            $this->func_sigs[$fn->name] = [
                'params'      => $fn->params,
                'return_type' => $fn->return_type,
            ];
        }

        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                $mangled = "{$impl->struct_name}::{$fn->name}";
                $this->func_sigs[$mangled] = [
                    'params'      => $fn->params,
                    'return_type' => $fn->return_type,
                    'struct'      => $impl->struct_name,
                ];
            }
        }

        $this->emitEntryPoint();
        $this->emitHeapRuntime();

        foreach ($program->functions as $fn) {
            $this->generateFunction($fn);
        }

        foreach ($program->impls as $impl) {
            foreach ($impl->functions as $fn) {
                $mangled = "{$impl->struct_name}::{$fn->name}";
                $this->generateFunction($fn, $mangled, $impl->struct_name);
            }
        }

        $this->patchCalls();
        $this->patchDataAddresses();
        return $this->asm->getBuffer() . $this->data;
    }

    private function registerBuiltinEnumsFromProgram(ProgramNode $program): void {
        $types = $this->collectTypesFromProgram($program);
        foreach ($types as $type) {
            $this->registerBuiltinEnumIf($type);
        }
    }

    private function collectTypesFromProgram(ProgramNode $program): array {
        $types = [];
        foreach ($program->consts as $c) {
            $types[$c->type] = true;
            $this->collectTypesFromExpr($c->value, $types);
        }
        foreach ($program->statics as $s) {
            $types[$s->type] = true;
            $this->collectTypesFromExpr($s->value, $types);
        }
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
            if ($stmt instanceof ReturnNode && $stmt->value !== null) {
                $this->collectTypesFromExpr($stmt->value, $types);
            }
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
            if ($stmt instanceof DerefAssignNode) $this->collectTypesFromExpr($stmt->operand, $types);
            if ($stmt instanceof IfNode) {
                $this->collectTypesFromExpr($stmt->condition, $types);
                $this->collectTypesFromStmts($stmt->then_body, $types);
                if ($stmt->else_body !== null) $this->collectTypesFromStmts($stmt->else_body, $types);
            }
            if ($stmt instanceof IfLetNode) {
                $this->collectTypesFromExpr($stmt->subject, $types);
                if ($stmt->enum_name !== null) $types[$stmt->enum_name] = true;
                $this->collectTypesFromStmts($stmt->then_body, $types);
                if ($stmt->else_body !== null) $this->collectTypesFromStmts($stmt->else_body, $types);
            }
            if ($stmt instanceof WhileNode) {
                $this->collectTypesFromExpr($stmt->condition, $types);
                $this->collectTypesFromStmts($stmt->body, $types);
            }
            if ($stmt instanceof WhileLetNode) {
                $this->collectTypesFromExpr($stmt->subject, $types);
                if ($stmt->enum_name !== null) $types[$stmt->enum_name] = true;
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
        if ($expr instanceof TupleLitNode) {
            foreach ($expr->elements as $e) $this->collectTypesFromExpr($e, $types);
        }
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
        if ($expr instanceof CastNode) {
            $this->collectTypesFromExpr($expr->expr, $types);
        }
        if ($expr instanceof FieldAccessNode) $this->collectTypesFromExpr($expr->object, $types);
        if ($expr instanceof TupleIndexNode) $this->collectTypesFromExpr($expr->object, $types);
        if ($expr instanceof IndexNode) {
            $this->collectTypesFromExpr($expr->object, $types);
            $this->collectTypesFromExpr($expr->index, $types);
        }
        if ($expr instanceof IfNode) {
            $this->collectTypesFromExpr($expr->condition, $types);
            $this->collectTypesFromStmts($expr->then_body, $types);
            if ($expr->else_body !== null) $this->collectTypesFromStmts($expr->else_body, $types);
        }
        if ($expr instanceof IfLetNode) {
            $this->collectTypesFromExpr($expr->subject, $types);
            if ($expr->enum_name !== null) $types[$expr->enum_name] = true;
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

    private function emitHeapRuntime(): void {
        $this->func_addrs['heap_init'] = $this->asm->pos();
        $this->asm->xor_(X86::RDI, X86::RDI);
        $this->asm->mov_imm32(X86::RSI, 4 * 1024 * 1024);
        $this->asm->mov_imm32(X86::RDX, 3);
        $this->asm->mov_imm32(X86::R10, 0x22);
        $this->asm->mov_imm32(X86::R8, -1);
        $this->asm->mov_imm32(X86::R9, 0);
        $this->asm->mov_imm32(X86::RAX, 9);
        $this->asm->syscall();
        $p = $this->asm->mov_imm64(X86::RSI);
        $this->data_patches[] = [$p, $this->heap_start_off];
        $this->asm->store(X86::RSI, 0, X86::RAX);
        $p = $this->asm->mov_imm64(X86::RSI);
        $this->data_patches[] = [$p, $this->heap_cur_off];
        $this->asm->store(X86::RSI, 0, X86::RAX);
        $this->asm->mov(X86::RCX, X86::RAX);
        $this->asm->add_imm32(X86::RCX, 4 * 1024 * 1024);
        $p = $this->asm->mov_imm64(X86::RSI);
        $this->data_patches[] = [$p, $this->heap_end_off];
        $this->asm->store(X86::RSI, 0, X86::RCX);
        $this->asm->ret();

        $this->func_addrs['alloc'] = $this->asm->pos();
        $this->asm->mov(X86::RCX, X86::RDI);
        $this->asm->add_imm8(X86::RCX, 7);
        $this->asm->and_imm32(X86::RCX, 0xFFFFFFF8);
        $p = $this->asm->mov_imm64(X86::RSI);
        $this->data_patches[] = [$p, $this->heap_cur_off];
        $this->asm->load(X86::RAX, X86::RSI, 0);
        $this->asm->load(X86::RDX, X86::RSI, 0);
        $this->asm->add(X86::RDX, X86::RCX);
        $this->asm->store(X86::RSI, 0, X86::RDX);
        $this->asm->ret();
    }

    private function emitEntryPoint(): void {
        $patch_pos = $this->asm->call_rel32();
        $this->call_patches[] = [$patch_pos, 'heap_init'];
        $patch_pos = $this->asm->call_rel32();
        $this->call_patches[] = [$patch_pos, 'main'];
        $this->asm->mov(X86::RDI, X86::RAX);
        $this->asm->mov_imm32(X86::RAX, 60);
        $this->asm->syscall();
    }

    private function patchCalls(): void {
        foreach ($this->call_patches as [$patch_pos, $func_name]) {
            if (!isset($this->func_addrs[$func_name])) {
                throw new RuntimeException("Undefined function '$func_name'");
            }
            $target = $this->func_addrs[$func_name];
            $this->asm->patch32($patch_pos, $target - $patch_pos - 4);
        }
    }

    private function patchDataAddresses(): void {
        $data_base = $this->code_base_addr + $this->asm->pos();
        foreach ($this->data_patches as [$asm_pos, $data_offset]) {
            $this->asm->patch64($asm_pos, $data_base + $data_offset);
        }
    }

    private function addData(string $str): int {
        $offset = strlen($this->data);
        $this->data .= $str;
        return $offset;
    }

    private function addDataQuad(int $value): int {
        $offset = strlen($this->data);
        $this->data .= pack('P', $value);
        return $offset;
    }

    private function emitStaticInit(StaticItemNode $s): int {
        if ($s->value instanceof IntLitNode) {
            return $this->addDataQuad($s->value->value);
        }
        throw new RuntimeException("Static '{$s->name}' initializer must be an integer literal at line {$s->line}");
    }

    private function generateFunction(FunctionNode $fn, ?string $mangled_name = null, ?string $struct_name = null): void {
        $name = $mangled_name ?? $fn->name;
        $this->func_addrs[$name] = $this->asm->pos();
        $this->vars = [];
        $this->stack_size = 0;
        $this->return_patches = [];
        $this->let_slots = [];

        $reg_idx = 0;
        foreach ($fn->params as $param) {
            $ptype = $param['type'];
            if ($struct_name !== null) {
                $ptype = str_replace('self', $struct_name, $ptype);
            }
            $size = $this->isFatType($ptype) ? 16 : 8;
            $this->stack_size += $size;
            $this->vars[$param['name']] = [
                'offset'  => $this->stack_size,
                'type'    => $ptype,
                'reg_idx' => $reg_idx,
            ];
            $reg_idx += $this->isFatType($ptype) ? 2 : 1;
        }

        $param_vars = $this->vars;
        $this->collectVars($fn->body);
        $this->vars = $param_vars;

        $this->asm->push(X86::RBP);
        $this->asm->mov(X86::RBP, X86::RSP);
        $aligned = ($this->stack_size + 15) & ~15;
        if ($aligned > 0) {
            if ($aligned <= 127) {
                $this->asm->sub_imm8(X86::RSP, $aligned);
            } else {
                $this->asm->sub_imm32(X86::RSP, $aligned);
            }
        }

        foreach ($fn->params as $param) {
            $var   = $this->vars[$param['name']];
            $ptype = $var['type'];
            $ri    = $var['reg_idx'];
            $this->asm->store(X86::RBP, -$var['offset'], self::ARG_REGS[$ri]);
            if ($this->isFatType($ptype)) {
                $this->asm->store(X86::RBP, -($var['offset'] - 8), self::ARG_REGS[$ri + 1]);
            }
        }

        $this->generateBody($fn->body);

        $this->asm->xor_(X86::RAX, X86::RAX);

        $epilogue_pos = $this->asm->pos();
        foreach ($this->return_patches as $patch_pos) {
            $this->asm->patch32($patch_pos, $epilogue_pos - $patch_pos - 4);
        }

        $this->asm->mov(X86::RSP, X86::RBP);
        $this->asm->pop(X86::RBP);
        $this->asm->ret();
    }

    private function collectVars(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) {
                $this->collectMatchSlotsFromExpr($stmt->value);
                $type = $stmt->type_name ?? $this->exprType($stmt->value);
                if (!empty($stmt->bindings)) {
                    if (count($stmt->bindings) > 2) {
                        throw new RuntimeException("Only 2-element tuple destructuring supported at line {$stmt->line}");
                    }
                    $element_types = $this->tupleElementTypes($type);
                    if ($element_types === null || count($element_types) !== count($stmt->bindings)) {
                        throw new RuntimeException("Tuple destructuring type mismatch at line {$stmt->line}");
                    }
                    $base = $this->stack_size;
                    $slots = [];
                    $off = $base;
                    foreach ($element_types as $et) {
                        $sz = $this->typeSize($et);
                        $slots[] = ['offset' => $off, 'type' => $et];
                        $off += $sz;
                    }
                    $this->stack_size = $off;
                    $this->let_slots[spl_object_id($stmt)] = $slots;
                    foreach ($stmt->bindings as $i => $name) {
                        $this->vars[$name] = $slots[$i];
                    }
                } else {
                    $size = $this->typeSize($type);
                    $this->stack_size += $size;
                    $slot = [
                        'offset' => $this->stack_size,
                        'type'   => $type,
                    ];
                    $this->let_slots[spl_object_id($stmt)] = $slot;
                    $this->vars[$stmt->name] = $slot;
                }
            }
            if ($stmt instanceof IfNode) {
                $this->collectVars($stmt->then_body);
                if ($stmt->else_body !== null) {
                    $this->collectVars($stmt->else_body);
                }
            }
            if ($stmt instanceof IfLetNode) {
                $this->registerIfLetSlot($stmt);
            }
            if ($stmt instanceof WhileNode) {
                $this->collectVars($stmt->body);
            }
            if ($stmt instanceof WhileLetNode) {
                $this->registerWhileLetSlot($stmt);
            }
            if ($stmt instanceof LoopNode) {
                $this->collectVars($stmt->body);
            }
            if ($stmt instanceof MatchNode) {
                $this->registerMatchSlot($stmt);
            }
            if ($stmt instanceof ReturnNode && $stmt->value !== null) {
                $this->collectMatchSlotsFromExpr($stmt->value);
            }
            if ($stmt instanceof ExprStmtNode) {
                $this->collectMatchSlotsFromExpr($stmt->expr);
            }
        }
    }

    private function registerMatchSlot(MatchNode $stmt): void {
        $subject_type = $this->exprType($stmt->subject);
        $has_int_arm = array_reduce($stmt->arms, fn($carry, $a) => $carry || $a->int_lit !== null, false);
        $is_int_match = ($subject_type === 'i32' && $has_int_arm && array_reduce($stmt->arms, fn($carry, $a) => $carry && ($a->is_wildcard || $a->int_lit !== null), true));
        $has_payload  = !$is_int_match && isset($this->enum_defs[$subject_type]) && $this->enum_defs[$subject_type]['has_payload'];
        $this->stack_size += $is_int_match ? 8 : 16;
        $this->match_subject_slots[spl_object_id($stmt)] = [
            'offset'      => $this->stack_size,
            'has_payload' => $has_payload,
            'enum_type'   => $is_int_match ? null : $subject_type,
            'is_int'      => $is_int_match,
        ];
        foreach ($stmt->arms as $arm) {
            if ($arm->binding !== null) {
                $this->stack_size += 8;
                $this->match_binding_slots[spl_object_id($arm)] = ['offset' => $this->stack_size];
            }
            $this->collectVars($arm->body);
        }
    }

    private function registerIfLetSlot(IfLetNode $stmt): void {
        $enum_type = $stmt->enum_name ?? $this->exprType($stmt->subject);
        if (!isset($this->enum_defs[$enum_type])) {
            $subject_type = $this->exprType($stmt->subject);
            if (($stmt->enum_name === 'Option' && str_starts_with($subject_type, 'Option<')) || ($stmt->enum_name === 'Result' && str_starts_with($subject_type, 'Result<'))) {
                $enum_type = $subject_type;
            }
        }
        $has_payload = isset($this->enum_defs[$enum_type]) && $this->enum_defs[$enum_type]['has_payload'];
        $this->stack_size += 16;
        $this->if_let_subject_slots[spl_object_id($stmt)] = [
            'offset'      => $this->stack_size,
            'has_payload' => $has_payload,
            'enum_type'   => $enum_type,
        ];
        if ($stmt->binding !== null) {
            $this->stack_size += 8;
            $this->if_let_binding_slots[spl_object_id($stmt)] = ['offset' => $this->stack_size];
        }
        $this->collectVars($stmt->then_body);
        if ($stmt->else_body !== null) {
            $this->collectVars($stmt->else_body);
        }
    }

    private function registerWhileLetSlot(WhileLetNode $stmt): void {
        $enum_type = $stmt->enum_name ?? $this->exprType($stmt->subject);
        if (!isset($this->enum_defs[$enum_type])) {
            $subject_type = $this->exprType($stmt->subject);
            if (($stmt->enum_name === 'Option' && str_starts_with($subject_type, 'Option<')) || ($stmt->enum_name === 'Result' && str_starts_with($subject_type, 'Result<'))) {
                $enum_type = $subject_type;
            }
        }
        $has_payload = isset($this->enum_defs[$enum_type]) && $this->enum_defs[$enum_type]['has_payload'];
        $this->stack_size += 16;
        $this->if_let_subject_slots[spl_object_id($stmt)] = [
            'offset'      => $this->stack_size,
            'has_payload' => $has_payload,
            'enum_type'   => $enum_type,
        ];
        if ($stmt->binding !== null) {
            $this->stack_size += 8;
            $this->if_let_binding_slots[spl_object_id($stmt)] = ['offset' => $this->stack_size];
        }
        $this->collectVars($stmt->body);
    }

    private function collectMatchSlotsFromExpr(mixed $expr): void {
        if ($expr instanceof MatchNode) {
            if (!isset($this->match_subject_slots[spl_object_id($expr)])) {
                $this->registerMatchSlot($expr);
            }
            return;
        }
        if ($expr instanceof CallNode) {
            foreach ($expr->args as $a) $this->collectMatchSlotsFromExpr($a);
            return;
        }
        if ($expr instanceof MethodCallNode) {
            $this->collectMatchSlotsFromExpr($expr->receiver);
            foreach ($expr->args as $a) $this->collectMatchSlotsFromExpr($a);
            return;
        }
        if ($expr instanceof BinaryOpNode) {
            $this->collectMatchSlotsFromExpr($expr->left);
            $this->collectMatchSlotsFromExpr($expr->right);
            return;
        }
        if ($expr instanceof UnaryOpNode || $expr instanceof BorrowNode || $expr instanceof DerefNode) {
            $this->collectMatchSlotsFromExpr($expr->operand);
            return;
        }
        if ($expr instanceof CastNode) {
            $this->collectMatchSlotsFromExpr($expr->expr);
            return;
        }
        if ($expr instanceof TupleLitNode) {
            foreach ($expr->elements as $e) $this->collectMatchSlotsFromExpr($e);
            return;
        }
        if ($expr instanceof TupleIndexNode) {
            $this->collectMatchSlotsFromExpr($expr->object);
            return;
        }
        if ($expr instanceof FieldAccessNode) $this->collectMatchSlotsFromExpr($expr->object);
        if ($expr instanceof IndexNode) {
            $this->collectMatchSlotsFromExpr($expr->object);
            $this->collectMatchSlotsFromExpr($expr->index);
        }
        if ($expr instanceof IfNode) {
            $this->collectMatchSlotsFromExpr($expr->condition);
            $this->collectMatchSlotsFromStmts($expr->then_body);
            if ($expr->else_body !== null) {
                $this->collectMatchSlotsFromStmts($expr->else_body);
            }
        }
        if ($expr instanceof IfLetNode) {
            if (!isset($this->if_let_subject_slots[spl_object_id($expr)])) {
                $this->registerIfLetSlot($expr);
            }
            return;
        }
    }

    private function collectMatchSlotsFromStmts(array $stmts): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof LetNode) $this->collectMatchSlotsFromExpr($stmt->value);
            if ($stmt instanceof MatchNode) $this->registerMatchSlot($stmt);
            if ($stmt instanceof ReturnNode && $stmt->value !== null) $this->collectMatchSlotsFromExpr($stmt->value);
            if ($stmt instanceof ExprStmtNode) $this->collectMatchSlotsFromExpr($stmt->expr);
            if ($stmt instanceof IfNode) {
                $this->collectMatchSlotsFromExpr($stmt->condition);
                $this->collectMatchSlotsFromStmts($stmt->then_body);
                if ($stmt->else_body !== null) $this->collectMatchSlotsFromStmts($stmt->else_body);
            }
            if ($stmt instanceof WhileNode) $this->collectMatchSlotsFromStmts($stmt->body);
            if ($stmt instanceof WhileLetNode) {
                if (!isset($this->if_let_subject_slots[spl_object_id($stmt)])) {
                    $this->registerWhileLetSlot($stmt);
                } else {
                    $this->collectMatchSlotsFromStmts($stmt->body);
                }
            }
            if ($stmt instanceof LoopNode) $this->collectMatchSlotsFromStmts($stmt->body);
            if ($stmt instanceof IfLetNode) {
                if (!isset($this->if_let_subject_slots[spl_object_id($stmt)])) {
                    $this->registerIfLetSlot($stmt);
                } else {
                    $this->collectMatchSlotsFromStmts($stmt->then_body);
                    if ($stmt->else_body !== null) $this->collectMatchSlotsFromStmts($stmt->else_body);
                }
            }
            if ($stmt instanceof MatchNode) {
                if (!isset($this->match_subject_slots[spl_object_id($stmt)])) {
                    $this->registerMatchSlot($stmt);
                } else {
                    foreach ($stmt->arms as $arm) $this->collectMatchSlotsFromStmts($arm->body);
                }
            }
        }
    }

    private function generateBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->generateStmt($stmt);
        }
    }
}
