<?php

$cases_dir  = __DIR__ . '/cases';
$src_dir    = __DIR__ . '/../src';

// On Linux the binaries run natively, so keep them on the native filesystem.
// On Windows they must sit in the working directory for `wsl ./name` to find them.
const NATIVE = PHP_OS_FAMILY === 'Linux';

// Usage: php tests/run.php [-j N]
// Tests are split round-robin across N worker processes (default: one per core).
// A worker is this same script started with --worker <index> <count>.
$jobs   = null;
$worker = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '-j' && isset($argv[$i + 1])) {
        $jobs = max(1, (int)$argv[++$i]);
    } elseif ($argv[$i] === '--worker' && isset($argv[$i + 2])) {
        $worker = [(int)$argv[$i + 1], (int)$argv[$i + 2]];
        $i += 2;
    }
}
$jobs ??= defaultJobs();

$suffix     = $worker !== null ? '_' . $worker[0] : '';
$tmp_binary = NATIVE
    ? sys_get_temp_dir() . '/rustc-php-test-' . getmypid()
    : __DIR__ . '/../test_out' . $suffix;

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

if ($worker !== null) {
    // Worker: run our share of the files and report one JSON line per test.
    [$index, $count] = $worker;
    foreach ($files as $n => $file) {
        if ($n % $count !== $index) continue;
        $result = runOne($file, $cases_dir, $tmp_binary);
        if ($result !== null) echo json_encode($result), "\n";
    }
    @unlink($tmp_binary);
    exit(0);
}

$start = hrtime(true);

if ($jobs === 1) {
    $results = [];
    foreach ($files as $file) {
        $result = runOne($file, $cases_dir, $tmp_binary);
        if ($result !== null) $results[] = $result;
    }
    @unlink($tmp_binary);
} else {
    $results = runWorkers($jobs);
}

usort($results, fn($a, $b) => strcmp($a['name'], $b['name']));

$passed = 0;
$failed = 0;
$failed_tests = [];
foreach ($results as $r) {
    if ($r['status'] === 'skip') {
        echo "SKIP  {$r['name']} — no test header\n";
    } elseif ($r['status'] === 'pass') {
        echo "PASS  {$r['name']}\n";
        $passed++;
    } else {
        echo "FAIL  {$r['name']} — {$r['reason']}\n";
        $failed_tests[] = $r;
        $failed++;
    }
}

$elapsed = (hrtime(true) - $start) / 1e9;
echo "\n$passed passed, $failed failed\n";
echo "Total time: " . round($elapsed, 2) . "s ($jobs " . ($jobs === 1 ? 'job' : 'jobs') . ")\n";

$timed = array_filter($results, fn($r) => $r['status'] !== 'skip');
usort($timed, fn($a, $b) => $b['ms'] <=> $a['ms']);
echo "Slowest: " . implode(', ', array_map(
    fn($r) => $r['name'] . ' ' . round($r['ms']) . 'ms',
    array_slice($timed, 0, 5)
)) . "\n";

if ($failed > 0) {
    echo "\n--- Failed tests ---\n";
    foreach ($failed_tests as $t) {
        echo $t['name'] . "\n  " . $t['reason'] . "\n";
    }
}

exit($failed > 0 ? 1 : 0);

function defaultJobs(): int {
    $n = NATIVE ? (int)@shell_exec('nproc') : (int)getenv('NUMBER_OF_PROCESSORS');
    return max(1, $n);
}

// Returns ['name', 'status' => pass|fail|skip, 'reason', 'ms'], or null for
// module files that are only compiled as part of their directory's main.rs.
function runOne(string $file, string $cases_dir, string $tmp_binary): ?array {
    $name   = str_replace('\\', '/', substr($file, strlen($cases_dir) + 1));
    $header = parseHeader($file);
    $start  = hrtime(true);

    if (isset($header['error'])) {
        $result = runErrorTest($file, $header['error'], $tmp_binary);
    } elseif (isset($header['exit']) || isset($header['stdout'])) {
        $result = runTest($file, $header, $tmp_binary);
    } else {
        if (file_exists(dirname($file) . DIRECTORY_SEPARATOR . 'main.rs') && basename($file) !== 'main.rs') {
            return null;
        }
        return ['name' => $name, 'status' => 'skip', 'reason' => null, 'ms' => 0];
    }

    return [
        'name'   => $name,
        'status' => $result === true ? 'pass' : 'fail',
        'reason' => $result === true ? null : $result,
        'ms'     => (hrtime(true) - $start) / 1e6,
    ];
}

// Workers write to temp files rather than pipes: stream_select does not work
// on pipes under Windows, and a full pipe buffer would stall a worker.
function runWorkers(int $jobs): array {
    $procs = [];
    for ($k = 0; $k < $jobs; $k++) {
        $out = tempnam(sys_get_temp_dir(), 'rustc-php-w');
        $cmd = [PHP_BINARY, __FILE__, '--worker', (string)$k, (string)$jobs];
        $proc = proc_open($cmd, [1 => ['file', $out, 'w'], 2 => STDERR], $pipes, dirname(__DIR__));
        $procs[] = [$proc, $out];
    }

    $results = [];
    foreach ($procs as [$proc, $out]) {
        $code = proc_close($proc);
        foreach (file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if ($r === null) {
                fwrite(STDERR, "worker printed non-JSON output: $line\n");
                continue;
            }
            $results[] = $r;
        }
        if ($code !== 0) fwrite(STDERR, "worker exited with code $code\n");
        @unlink($out);
    }
    return $results;
}

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

    $timeout_sec = $header['timeout'] ?? 10;
    $expect_timeout = !empty($header['expect_timeout']);
    if (NATIVE) {
        chmod($binary, 0755);
        $target = escapeshellarg($binary);
        $prefix = '';
    } else {
        $target = './' . basename($binary);
        $prefix = 'wsl ';
    }
    $cmd = $prefix . ($timeout_sec > 0 ? "timeout $timeout_sec " : '') . "$target 2>&1";
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
