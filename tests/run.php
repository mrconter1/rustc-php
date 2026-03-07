<?php

$cases_dir  = __DIR__ . '/cases';
$tmp_binary = __DIR__ . '/../test_out';
$src_dir    = __DIR__ . '/../src';

require_once $src_dir . '/Lexer.php';
require_once $src_dir . '/Token.php';
require_once $src_dir . '/Ast.php';
require_once $src_dir . '/Parser.php';
require_once $src_dir . '/ModuleResolver.php';
require_once $src_dir . '/ForLoopDesugar.php';
require_once $src_dir . '/ClosureDesugar.php';
require_once $src_dir . '/Monomorphizer.php';
require_once $src_dir . '/OwnershipChecker.php';
require_once $src_dir . '/X86.php';
require_once $src_dir . '/CodeGen/CodeGen.php';
require_once $src_dir . '/Elf.php';

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cases_dir));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'rs') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$passed = 0;
$failed = 0;
$failed_tests = [];
$start  = hrtime(true);

foreach ($files as $file) {
    $name   = str_replace('\\', '/', substr($file, strlen($cases_dir) + 1));
    $header = parseHeader($file);

    if (isset($header['error'])) {
        $result = runErrorTest($file, $header['error'], $tmp_binary);
    } elseif (isset($header['exit']) || isset($header['stdout'])) {
        $result = runTest($file, $header, $tmp_binary);
    } else {
        if (file_exists(dirname($file) . DIRECTORY_SEPARATOR . 'main.rs') && basename($file) !== 'main.rs') {
            continue;
        }
        echo "SKIP  $name — no test header\n";
        continue;
    }

    if ($result === true) {
        echo "PASS  $name\n";
        $passed++;
    } else {
        echo "FAIL  $name — $result\n";
        $failed_tests[] = ['name' => $name, 'reason' => $result];
        $failed++;
    }
}

@unlink($tmp_binary);

$elapsed = (hrtime(true) - $start) / 1e9;
echo "\n$passed passed, $failed failed\n";
echo "Total time: " . round($elapsed, 2) . "s\n";

if ($failed > 0) {
    echo "\n--- Failed tests ---\n";
    foreach ($failed_tests as $t) {
        echo $t['name'] . "\n  " . $t['reason'] . "\n";
    }
}

exit($failed > 0 ? 1 : 0);

function parseHeader(string $file): array {
    $lines  = file($file);
    $header = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '//')) break;
        if (preg_match('/^\/\/\s*exit:\s*(\d+)/', $line, $m)) {
            $header['exit'] = $m[1];
        }
        if (preg_match('/^\/\/\s*error:\s*(.+)/', $line, $m)) {
            $header['error'] = trim($m[1]);
        }
        if (preg_match('/^\/\/\s*stdout:\s*(.*)/', $line, $m)) {
            $header['stdout'][] = $m[1];
        }
        if (preg_match('/^\/\/\s*timeout:\s*(\d+)/', $line, $m)) {
            $header['timeout'] = (int)$m[1];
        }
        if (preg_match('/^\/\/\s*expect_timeout\b/', $line)) {
            $header['expect_timeout'] = true;
        }
    }
    return $header;
}

function compileInProcess(string $file, string $binary): ?string {
    try {
        $ast = (new ModuleResolver())->resolve($file);
        $ast = (new ForLoopDesugar())->desugar($ast);
        $ast = (new ClosureDesugar())->desugar($ast);
        $ast = (new Monomorphizer())->monomorphize($ast);
        (new OwnershipChecker())->check($ast);
        $code = (new CodeGen())->generate($ast, Elf::LOAD_ADDR + Elf::CODE_OFFSET);
        (new Elf($code))->write($binary);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function runTest(string $file, array $header, string $binary): string|true {
    $err = compileInProcess($file, $binary);
    if ($err !== null) {
        return "compilation failed: " . $err;
    }

    $binary_name = basename($binary);
    $timeout_sec = $header['timeout'] ?? 10;
    $expect_timeout = !empty($header['expect_timeout']);
    $cmd = $timeout_sec > 0 ? "wsl timeout $timeout_sec ./$binary_name 2>&1" : "wsl ./$binary_name 2>&1";
    exec($cmd, $run_out, $actual_exit);

    if ($expect_timeout) {
        if ($actual_exit !== 124 && $actual_exit !== 143) {
            return "expected timeout (exit 124 or 143), got $actual_exit";
        }
        return true;
    }

    $expected_exit = (int)($header['exit'] ?? 0);
    if ($actual_exit !== $expected_exit) {
        return "expected exit $expected_exit, got $actual_exit";
    }

    if (isset($header['stdout'])) {
        if ($run_out !== $header['stdout']) {
            return "stdout mismatch: expected [" . implode(', ', $header['stdout'])
                 . "], got [" . implode(', ', $run_out) . "]";
        }
    }

    return true;
}

function runErrorTest(string $file, string $expected_error, string $binary): string|true {
    $err = compileInProcess($file, $binary);
    if ($err === null) {
        return "expected compilation error, but compiled successfully";
    }
    if (stripos($err, $expected_error) === false) {
        return "expected error containing '$expected_error', got: $err";
    }
    return true;
}
