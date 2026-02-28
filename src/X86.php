<?php

class X86 {
    const RAX = 0; const RCX = 1; const RDX = 2; const RBX = 3;
    const RSP = 4; const RBP = 5; const RSI = 6; const RDI = 7;
    const R8  = 8; const R9  = 9;

    const AL = 0; const CL = 1; const DL = 2;

    const CC_E  = 0x94; // ==
    const CC_NE = 0x95; // !=
    const CC_L  = 0x9C; // <
    const CC_GE = 0x9D; // >=
    const CC_LE = 0x9E; // <=
    const CC_G  = 0x9F; // >

    private string $buffer = '';

    public function getBuffer(): string { return $this->buffer; }
    public function pos(): int { return strlen($this->buffer); }
    public function reset(): void { $this->buffer = ''; }

    private function emit(string $bytes): void {
        $this->buffer .= $bytes;
    }

    // mov reg64, reg64
    public function mov(int $dst, int $src): void {
        $rex = 0x48 | (($src >> 3) << 2) | ($dst >> 3);
        $modrm = 0xC0 | (($src & 7) << 3) | ($dst & 7);
        $this->emit(chr($rex) . "\x89" . chr($modrm));
    }

    // mov reg64, imm32 (sign-extended to 64-bit)
    public function mov_imm32(int $reg, int $value): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xC0 | ($reg & 7);
        $this->emit(chr($rex) . "\xC7" . chr($modrm) . pack('V', $value));
    }

    // movabs reg64, imm64 — returns position of the 8-byte immediate for patching
    public function mov_imm64(int $reg): int {
        $rex = 0x48 | ($reg >> 3);
        $opcode = 0xB8 + ($reg & 7);
        $this->emit(chr($rex) . chr($opcode));
        $pos = strlen($this->buffer);
        $this->emit("\x00\x00\x00\x00\x00\x00\x00\x00");
        return $pos;
    }

    // mov reg64, [base + disp8]
    public function load(int $reg, int $base, int $disp): void {
        $rex = 0x48 | (($reg >> 3) << 2) | ($base >> 3);
        if ($base === self::RSP || ($base & 7) === self::RSP) {
            $modrm = 0x44 | (($reg & 7) << 3);
            $this->emit(chr($rex) . "\x8B" . chr($modrm) . "\x24" . pack('c', $disp));
        } else {
            $modrm = 0x40 | (($reg & 7) << 3) | ($base & 7);
            $this->emit(chr($rex) . "\x8B" . chr($modrm) . pack('c', $disp));
        }
    }

    // mov [base + disp8], reg64
    public function store(int $base, int $disp, int $reg): void {
        $rex = 0x48 | (($reg >> 3) << 2) | ($base >> 3);
        if ($base === self::RSP || ($base & 7) === self::RSP) {
            $modrm = 0x44 | (($reg & 7) << 3);
            $this->emit(chr($rex) . "\x89" . chr($modrm) . "\x24" . pack('c', $disp));
        } else {
            $modrm = 0x40 | (($reg & 7) << 3) | ($base & 7);
            $this->emit(chr($rex) . "\x89" . chr($modrm) . pack('c', $disp));
        }
    }

    // push reg64
    public function push(int $reg): void {
        if ($reg >= 8) $this->emit(chr(0x41));
        $this->emit(chr(0x50 + ($reg & 7)));
    }

    // pop reg64
    public function pop(int $reg): void {
        if ($reg >= 8) $this->emit(chr(0x41));
        $this->emit(chr(0x58 + ($reg & 7)));
    }

    // ALU reg-reg operations (add, sub, xor, cmp, test)
    private function emitALU(string $opcode, int $rm, int $reg): void {
        $rex = 0x48 | (($reg >> 3) << 2) | ($rm >> 3);
        $modrm = 0xC0 | (($reg & 7) << 3) | ($rm & 7);
        $this->emit(chr($rex) . $opcode . chr($modrm));
    }

    public function add(int $dst, int $src): void  { $this->emitALU("\x01", $dst, $src); }
    public function sub(int $dst, int $src): void  { $this->emitALU("\x29", $dst, $src); }
    public function xor_(int $dst, int $src): void { $this->emitALU("\x31", $dst, $src); }
    public function cmp(int $a, int $b): void      { $this->emitALU("\x39", $a, $b); }
    public function test(int $a, int $b): void     { $this->emitALU("\x85", $a, $b); }

    // sub/add reg64, imm8
    public function sub_imm8(int $reg, int $val): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xE8 | ($reg & 7); // /5
        $this->emit(chr($rex) . "\x83" . chr($modrm) . pack('C', $val));
    }

    public function add_imm8(int $reg, int $val): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xC0 | ($reg & 7); // /0
        $this->emit(chr($rex) . "\x83" . chr($modrm) . pack('C', $val));
    }

    // imul reg64, reg64
    public function imul(int $dst, int $src): void {
        $rex = 0x48 | (($dst >> 3) << 2) | ($src >> 3);
        $modrm = 0xC0 | (($dst & 7) << 3) | ($src & 7);
        $this->emit(chr($rex) . "\x0F\xAF" . chr($modrm));
    }

    // cqo (sign-extend rax into rdx:rax)
    public function cqo(): void { $this->emit("\x48\x99"); }

    // div/idiv reg64
    public function div(int $reg): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xF0 | ($reg & 7); // /6
        $this->emit(chr($rex) . "\xF7" . chr($modrm));
    }

    public function idiv(int $reg): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xF8 | ($reg & 7); // /7
        $this->emit(chr($rex) . "\xF7" . chr($modrm));
    }

    // inc/dec reg64
    public function inc(int $reg): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xC0 | ($reg & 7); // /0
        $this->emit(chr($rex) . "\xFF" . chr($modrm));
    }

    public function dec(int $reg): void {
        $rex = 0x48 | ($reg >> 3);
        $modrm = 0xC8 | ($reg & 7); // /1
        $this->emit(chr($rex) . "\xFF" . chr($modrm));
    }

    // setCC al
    public function setcc(int $cc): void {
        $this->emit("\x0F" . chr($cc) . "\xC0");
    }

    // movzx rax, al
    public function movzx_rax_al(): void {
        $this->emit("\x48\x0F\xB6\xC0");
    }

    // lea reg, [rsp + disp8]
    public function lea_rsp(int $reg, int $disp): void {
        $rex = 0x48 | (($reg >> 3) << 2);
        $modrm = 0x44 | (($reg & 7) << 3);
        $this->emit(chr($rex) . "\x8D" . chr($modrm) . "\x24" . pack('c', $disp));
    }

    // mov byte [base], imm8 (for r8-r15, adds REX.B)
    public function store_byte_imm(int $base, int $val): void {
        if ($base >= 8) $this->emit(chr(0x41));
        $modrm = $base & 7;
        $this->emit("\xC6" . chr($modrm) . pack('C', $val));
    }

    // mov [base], r8reg (8-bit store, e.g. mov [r8], dl)
    public function store_byte_reg(int $base, int $src8): void {
        if ($base >= 8) $this->emit(chr(0x41));
        $modrm = ($src8 << 3) | ($base & 7);
        $this->emit("\x88" . chr($modrm));
    }

    // add r/m8, imm8 (e.g. add dl, '0')
    public function add_r8_imm8(int $reg8, int $val): void {
        $modrm = 0xC0 | ($reg8 & 7);
        $this->emit("\x80" . chr($modrm) . pack('C', $val));
    }

    // jz rel32 — returns position of the 4-byte offset for patching
    public function jz_rel32(): int {
        $this->emit("\x0F\x84");
        $pos = strlen($this->buffer);
        $this->emit("\x00\x00\x00\x00");
        return $pos;
    }

    // jmp rel32 — returns position of the 4-byte offset for patching
    public function jmp_rel32(): int {
        $this->emit("\xE9");
        $pos = strlen($this->buffer);
        $this->emit("\x00\x00\x00\x00");
        return $pos;
    }

    // jnz to an absolute position (short jump, rel8)
    public function jnz_to(int $target): void {
        $offset = $target - $this->pos() - 2;
        $this->emit("\x75" . pack('c', $offset));
    }

    // syscall
    public function syscall(): void { $this->emit("\x0F\x05"); }

    // Patch a 32-bit value at the given buffer position
    public function patch32(int $pos, int $value): void {
        $packed = pack('V', $value);
        for ($i = 0; $i < 4; $i++) {
            $this->buffer[$pos + $i] = $packed[$i];
        }
    }

    // Patch a 64-bit value at the given buffer position
    public function patch64(int $pos, int $value): void {
        $packed = pack('P', $value);
        for ($i = 0; $i < 8; $i++) {
            $this->buffer[$pos + $i] = $packed[$i];
        }
    }
}
