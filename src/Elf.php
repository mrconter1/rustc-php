<?php

class Elf {
    public const LOAD_ADDR   = 0x400000;
    public const HEADER_SIZE = 64;
    public const PHDR_SIZE   = 56;
    public const CODE_OFFSET = 120; // HEADER_SIZE + PHDR_SIZE

    private string $code;

    public function __construct(string $code) {
        $this->code = $code;
    }

    public function build(): string {
        $code_offset = self::HEADER_SIZE + self::PHDR_SIZE;
        $entry_point = self::LOAD_ADDR + $code_offset;
        $file_size   = $code_offset + strlen($this->code);

        return $this->elfHeader($entry_point)
             . $this->programHeader($file_size)
             . $this->code;
    }

    public function write(string $path): void {
        file_put_contents($path, $this->build());
        chmod($path, 0755);
    }

    private function elfHeader(int $entry_point): string {
        return "\x7fELF"           // Magic
             . pack('C', 2)        // Class: 64-bit
             . pack('C', 1)        // Data: little-endian
             . pack('C', 1)        // Version
             . pack('C', 0)        // OS/ABI: System V
             . str_repeat("\x00", 8) // Padding
             . pack('v', 2)        // Type: ET_EXEC (executable)
             . pack('v', 0x3e)     // Machine: x86-64
             . pack('V', 1)        // ELF version
             . pack('P', $entry_point)        // Entry point
             . pack('P', self::HEADER_SIZE)   // Program header offset
             . pack('P', 0)        // Section header offset (none)
             . pack('V', 0)        // Flags
             . pack('v', self::HEADER_SIZE)   // ELF header size
             . pack('v', self::PHDR_SIZE)     // Program header entry size
             . pack('v', 1)        // Program header count
             . pack('v', 64)       // Section header entry size
             . pack('v', 0)        // Section header count
             . pack('v', 0);       // Section name string table index
    }

    private function programHeader(int $file_size): string {
        return pack('V', 1)                  // Type: PT_LOAD
             . pack('V', 7)                  // Flags: PF_R | PF_W | PF_X (read + write + execute)
             . pack('P', 0)                  // File offset: load from start of file
             . pack('P', self::LOAD_ADDR)    // Virtual address
             . pack('P', self::LOAD_ADDR)    // Physical address
             . pack('P', $file_size)         // Size in file
             . pack('P', $file_size)         // Size in memory
             . pack('P', 0x1000);            // Alignment (4096)
    }
}
