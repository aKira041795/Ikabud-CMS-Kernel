<?php

declare(strict_types=1);

$command = [PHP_BINARY, dirname(__DIR__) . '/scripts/golden-module-ci.php'];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
if (!is_resource($process)) {
    fwrite(STDERR, "FAIL: unable to start golden module harness\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || !str_contains((string)$stdout, 'Golden module lifecycle: PASS')) {
    fwrite(STDERR, "FAIL: golden module lifecycle harness\n{$stdout}\n{$stderr}\n");
    exit(1);
}

$lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$stdout) ?: [])));
$summary = json_decode((string)end($lines), true);
if (!is_array($summary) || empty($summary['ok']) || empty($summary['checks'])) {
    fwrite(STDERR, "FAIL: golden module lifecycle summary is invalid\n{$stdout}\n");
    exit(1);
}

echo 'golden module lifecycle test: PASS (' . count($summary['checks']) . " checks)\n";
