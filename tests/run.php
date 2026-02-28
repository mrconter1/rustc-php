<?php

$cases_dir  = __DIR__ . '/cases';
$rustc      = __DIR__ . '/../rustc.php';
$tmp_binary = __DIR__ . '/../test_out';

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

foreach ($files as $file) {
    $name   = str_replace('\\', '/', substr($file, strlen($cases_dir) + 1));
    $header = parseHeader($file);

    if (isset($header['error'])) {
        $result = runErrorTest($file, $header['error'], $rustc, $tmp_binary);
    } elseif (isset($header['exit']) || isset($header['stdout'])) {
        $result = runTest($file, $header, $rustc, $tmp_binary);
    } else {
        echo "SKIP  $name — no test header\n";
        continue;
    }

    if ($result === true) {
        echo "PASS  $name\n";
        $passed++;
    } else {
        echo "FAIL  $name — $result\n";
        $failed++;
    }
}

@unlink($tmp_binary);

echo "\n$passed passed, $failed failed\n";
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
    }
    return $header;
}

function runTest(string $file, array $header, string $rustc, string $binary): string|true {
    exec("php \"$rustc\" \"$file\" -o \"$binary\" 2>&1", $compile_out, $compile_code);
    if ($compile_code !== 0) {
        return "compilation failed: " . implode(' ', $compile_out);
    }

    exec("wsl ./test_out 2>&1", $run_out, $actual_exit);

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

function runErrorTest(string $file, string $expected_error, string $rustc, string $binary): string|true {
    exec("php \"$rustc\" \"$file\" -o \"$binary\" 2>&1", $compile_out, $compile_code);
    if ($compile_code === 0) {
        return "expected compilation error, but compiled successfully";
    }
    $output = implode(' ', $compile_out);
    if (stripos($output, $expected_error) === false) {
        return "expected error containing '$expected_error', got: $output";
    }
    return true;
}
