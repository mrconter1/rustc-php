<?php

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/Elf.php';
require_once __DIR__ . '/X86.php';

class CodeGen {
    private X86    $asm;
    private string $data = '';
    private array  $data_patches = [];
    private array  $vars = [];
    private int    $stack_size = 0;
    private int    $code_base_addr;

    private array $func_addrs = [];
    private array $func_sigs = [];
    private array $call_patches = [];
    private array $return_patches = [];
    private array $let_slots = [];
    private array $loop_stack = [];

    private const ARG_REGS = [X86::RDI, X86::RSI, X86::RDX, X86::RCX, X86::R8, X86::R9];

    public function __construct() {
        $this->asm = new X86();
    }

    public function generate(ProgramNode $program, int $code_base_addr): string {
        $this->code_base_addr = $code_base_addr;
        $this->asm->reset();
        $this->data = '';
        $this->data_patches = [];
        $this->call_patches = [];
        $this->func_addrs = [];
        $this->func_sigs = [];

        foreach ($program->functions as $fn) {
            $this->func_sigs[$fn->name] = [
                'params'      => $fn->params,
                'return_type' => $fn->return_type,
            ];
        }

        $this->emitEntryPoint();

        foreach ($program->functions as $fn) {
            $this->generateFunction($fn);
        }

        $this->patchCalls();
        $this->patchDataAddresses();
        return $this->asm->getBuffer() . $this->data;
    }

    private function emitEntryPoint(): void {
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

    private function exprType(mixed $expr): string {
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof IdentNode) {
            $type = $this->vars[$expr->name]['type'] ?? 'i32';
            return ltrim($type, '&');
        }
        if ($expr instanceof BorrowNode) {
            if ($expr->operand instanceof IdentNode) {
                return '&' . ($this->vars[$expr->operand->name]['type'] ?? 'i32');
            }
            return '&i32';
        }
        if ($expr instanceof BinaryOpNode) return 'i32';
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
        return 'i32';
    }

    private function generateFunction(FunctionNode $fn): void {
        $this->func_addrs[$fn->name] = $this->asm->pos();
        $this->vars = [];
        $this->stack_size = 0;
        $this->return_patches = [];
        $this->let_slots = [];

        $reg_idx = 0;
        foreach ($fn->params as $param) {
            $size = ($param['type'] === 'String') ? 16 : 8;
            $this->stack_size += $size;
            $this->vars[$param['name']] = [
                'offset'  => $this->stack_size,
                'type'    => $param['type'],
                'reg_idx' => $reg_idx,
            ];
            $reg_idx += ($param['type'] === 'String') ? 2 : 1;
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
            $var = $this->vars[$param['name']];
            $ri = $var['reg_idx'];
            $this->asm->store(X86::RBP, -$var['offset'], self::ARG_REGS[$ri]);
            if ($param['type'] === 'String') {
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
                $type = $stmt->type_name ?? $this->exprType($stmt->value);
                $size = ($type === 'String') ? 16 : 8;
                $this->stack_size += $size;
                $slot = [
                    'offset' => $this->stack_size,
                    'type'   => $type,
                ];
                $this->let_slots[spl_object_id($stmt)] = $slot;
                $this->vars[$stmt->name] = $slot;
            }
            if ($stmt instanceof IfNode) {
                $this->collectVars($stmt->then_body);
                if ($stmt->else_body !== null) {
                    $this->collectVars($stmt->else_body);
                }
            }
            if ($stmt instanceof WhileNode) {
                $this->collectVars($stmt->body);
            }
            if ($stmt instanceof LoopNode) {
                $this->collectVars($stmt->body);
            }
        }
    }

    private function generateBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->generateStmt($stmt);
        }
    }

    private function generateStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            $this->generateExpr($stmt->value);
            $slot = $this->let_slots[spl_object_id($stmt)];
            $this->vars[$stmt->name] = $slot;
            $this->asm->store(X86::RBP, -$slot['offset'], X86::RAX);
            if ($slot['type'] === 'String') {
                $this->asm->store(X86::RBP, -($slot['offset'] - 8), X86::RDX);
            }
            return;
        }

        if ($stmt instanceof AssignNode) {
            $this->generateExpr($stmt->value);
            $var = $this->vars[$stmt->name];
            $this->asm->store(X86::RBP, -$var['offset'], X86::RAX);
            if ($var['type'] === 'String') {
                $this->asm->store(X86::RBP, -($var['offset'] - 8), X86::RDX);
            }
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

        if ($stmt instanceof WhileNode) {
            $this->generateWhile($stmt);
            return;
        }

        if ($stmt instanceof LoopNode) {
            $this->generateLoop($stmt);
            return;
        }

        if ($stmt instanceof BreakNode) {
            if (empty($this->loop_stack)) {
                throw new RuntimeException("break outside of loop on line {$stmt->line}");
            }
            $ctx = &$this->loop_stack[count($this->loop_stack) - 1];
            $ctx['break_patches'][] = $this->asm->jmp_rel32();
            return;
        }

        if ($stmt instanceof ContinueNode) {
            if (empty($this->loop_stack)) {
                throw new RuntimeException("continue outside of loop on line {$stmt->line}");
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

        throw new RuntimeException("Unknown statement type: " . get_class($stmt));
    }

    private function generatePrintln(PrintlnNode $node): void {
        foreach ($node->parts as $part) {
            if (is_string($part)) {
                $this->emitWriteString($part);
            } else {
                $type = $this->exprType($part);
                $this->generateExpr($part);
                if ($type === 'String') {
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

    private function generateExpr(mixed $expr): void {
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

        if ($expr instanceof IdentNode) {
            if (!isset($this->vars[$expr->name])) {
                throw new RuntimeException("Undefined variable '{$expr->name}' on line {$expr->line}");
            }
            $var = $this->vars[$expr->name];
            $this->asm->load(X86::RAX, X86::RBP, -$var['offset']);
            if ($var['type'] === 'String') {
                $this->asm->load(X86::RDX, X86::RBP, -($var['offset'] - 8));
            } elseif ($var['type'] === '&String') {
                $this->asm->load(X86::RDX, X86::RAX, 8);
                $this->asm->load(X86::RAX, X86::RAX, 0);
            } elseif (str_starts_with($var['type'], '&')) {
                $this->asm->load(X86::RAX, X86::RAX, 0);
            }
            return;
        }

        if ($expr instanceof BorrowNode) {
            if (!($expr->operand instanceof IdentNode)) {
                throw new RuntimeException("Can only borrow variables on line {$expr->line}");
            }
            $name = $expr->operand->name;
            if (!isset($this->vars[$name])) {
                throw new RuntimeException("Undefined variable '$name' on line {$expr->line}");
            }
            $var = $this->vars[$name];
            $this->asm->lea(X86::RAX, X86::RBP, -$var['offset']);
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
                    throw new RuntimeException("Unknown operator '{$expr->op}' on line {$expr->line}");
            }
            return;
        }

        if ($expr instanceof CallNode) {
            if ($expr->name === 'exit') {
                if (count($expr->args) !== 1) {
                    throw new RuntimeException("exit() takes exactly 1 argument on line {$expr->line}");
                }
                $this->generateExpr($expr->args[0]);
                $this->asm->mov(X86::RDI, X86::RAX);
                $this->asm->mov_imm32(X86::RAX, 60);
                $this->asm->syscall();
                return;
            }

            $n = count($expr->args);
            if ($n > 6) {
                throw new RuntimeException("Functions with more than 6 arguments are not supported on line {$expr->line}");
            }

            $sig = $this->func_sigs[$expr->name] ?? null;
            $reg_idx = 0;
            $param_reg_map = [];
            for ($i = 0; $i < $n; $i++) {
                $ptype = $sig ? $sig['params'][$i]['type'] : 'i32';
                $param_reg_map[$i] = ['reg_idx' => $reg_idx, 'type' => $ptype];
                $reg_idx += ($ptype === 'String') ? 2 : 1;
            }

            for ($i = 0; $i < $n; $i++) {
                $this->generateExpr($expr->args[$i]);
                if ($param_reg_map[$i]['type'] === 'String') {
                    $this->asm->push(X86::RDX);
                }
                $this->asm->push(X86::RAX);
            }
            for ($i = $n - 1; $i >= 0; $i--) {
                $ri = $param_reg_map[$i]['reg_idx'];
                $this->asm->pop(self::ARG_REGS[$ri]);
                if ($param_reg_map[$i]['type'] === 'String') {
                    $this->asm->pop(self::ARG_REGS[$ri + 1]);
                }
            }

            $patch_pos = $this->asm->call_rel32();
            $this->call_patches[] = [$patch_pos, $expr->name];
            return;
        }

        if ($expr instanceof IfNode) {
            $this->generateIfExpr($expr);
            return;
        }

        throw new RuntimeException("Unknown expression type: " . get_class($expr));
    }

    private function generateIfExpr(IfNode $node): void {
        if ($node->else_body === null) {
            throw new RuntimeException("if expression requires else branch on line {$node->line}");
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

    private function generateBodyForExpr(array $stmts): void {
        $n = count($stmts);
        for ($i = 0; $i < $n; $i++) {
            $stmt = $stmts[$i];
            if ($i === $n - 1 && $stmt instanceof ReturnNode && $stmt->value !== null) {
                $this->generateExpr($stmt->value);
            } elseif ($i === $n - 1 && $stmt instanceof IfNode) {
                $this->generateIfExpr($stmt);
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
