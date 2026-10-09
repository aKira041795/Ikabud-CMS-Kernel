<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/kernel-concurrency-gate-' . bin2hex(random_bytes(4));
mkdir($tempDir, 0700, true);
$router = $tempDir . '/router.php';
file_put_contents($router, "<?php\nusleep(20000);\nheader('Content-Type: text/plain');\necho 'ok';\n");

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    fwrite(STDERR, "FAIL: unable to reserve test port: {$error}\n");
    exit(1);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr(strrchr((string)$address, ':'), 1);

$serverLog = $tempDir . '/server.log';
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, "FAIL: unable to start fixture HTTP server\n");
    exit(1);
}

$ready = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $port, $probeErrno, $probeError, 0.1);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(20_000);
}

$pass = 0;
$fail = 0;
$run = static function (string $arguments) use ($root): array {
    $lines = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/concurrency-gate.php') . ' ' . $arguments . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
};
$assert = static function (string $name, bool $condition, string $detail) use (&$pass, &$fail): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ' — ' . $detail . PHP_EOL;
    $condition ? $pass++ : $fail++;
};

try {
    $assert('fixture server is reachable', $ready, $ready ? 'ready' : (string)@file_get_contents($serverLog));
    if ($ready) {
        $base = '--url=' . escapeshellarg('http://127.0.0.1:' . $port) . ' --path=/login --requests=8 --concurrency=2';
        [$passCode, $passOutput] = $run($base . ' --fail-on-ratio=20');
        $assert('loose ratio and zero errors passes with exit 0', $passCode === 0, "exit={$passCode} {$passOutput}");

        [$failCode, $failOutput] = $run($base . ' --fail-on-ratio=0.01');
        $assert('impossible ratio fails with exit 1', $failCode === 1, "exit={$failCode} {$failOutput}");
    }

    [$missingCode, $missingOutput] = $run('--url=http://127.0.0.1:9 --path=/login --requests=5 --concurrency=2');
    $assert('unreachable URL is not measured with exit 2', $missingCode === 2, "exit={$missingCode} {$missingOutput}");
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($router);
    @unlink($serverLog);
    @rmdir($tempDir);
}

echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
