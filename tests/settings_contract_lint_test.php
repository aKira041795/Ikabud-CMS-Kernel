<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/settings-contract-lint-' . bin2hex(random_bytes(4));
$moduleDirectory = $root . '/modules/probe';
$baselineDirectory = $root . '/tests/fixtures';
mkdir($moduleDirectory, 0777, true);
mkdir($baselineDirectory, 0777, true);
$tool = realpath(__DIR__ . '/../tools/settings-contract-lint.php');
if ($tool === false) {
    throw new RuntimeException('settings contract linter not found');
}

/** @param list<array<string, mixed>> $fields */
function writeFixtureManifest(string $directory, array $fields): void
{
    $json = json_encode(['id' => 'probe', 'settings_fields' => $fields], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/module.json', $json . "\n");
}

/** @return array{code: int, output: string} */
function runLinter(string $tool, string $root, bool $update = false): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool)
        . ' ' . escapeshellarg('--root=' . $root)
        . ($update ? ' --update' : '') . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    return ['code' => $code, 'output' => implode("\n", $lines)];
}

/** @param array{code: int, output: string} $result */
function assertResult(array $result, int $code, string $contains, string $case): void
{
    if ($result['code'] !== $code || !str_contains($result['output'], $contains)) {
        throw new RuntimeException(
            "{$case} failed (expected exit {$code} containing " . json_encode($contains)
            . ", got exit {$result['code']}):\n{$result['output']}"
        );
    }
}

$clean = [
    ['key' => 'title', 'type' => 'text', 'default' => 'Original'],
    ['key' => 'provider', 'type' => 'select'],
];

try {
    writeFixtureManifest($moduleDirectory, $clean);
    assertResult(runLinter($tool, $root, true), 0, 'Snapshot: updated', 'baseline setup');

    // MUST-ALLOW: legacy declaration omissions remain warnings, not failures.
    $result = runLinter($tool, $root);
    assertResult($result, 0, 'WARNING:', 'clean fixture');
    if (!str_contains($result['output'], 'warnings=2') || str_contains($result['output'], 'ERROR:')) {
        throw new RuntimeException("clean fixture did not produce warnings only:\n{$result['output']}");
    }

    // MUST-REFUSE: duplicate field keys.
    writeFixtureManifest($moduleDirectory, [
        ['key' => 'title', 'type' => 'text', 'default' => 'Original'],
        ['key' => 'title', 'type' => 'text', 'default' => 'Other'],
    ]);
    assertResult(runLinter($tool, $root), 1, 'duplicate field key "title"', 'duplicate key');

    // MUST-REFUSE: absent types.
    writeFixtureManifest($moduleDirectory, [
        ['key' => 'title', 'default' => 'Original'],
        ['key' => 'provider', 'type' => 'select'],
    ]);
    assertResult(runLinter($tool, $root), 1, 'missing type', 'missing type');

    // MUST-REFUSE: types outside the closed vocabulary.
    writeFixtureManifest($moduleDirectory, [
        ['key' => 'title', 'type' => 'colour', 'default' => 'Original'],
        ['key' => 'provider', 'type' => 'select'],
    ]);
    assertResult(runLinter($tool, $root), 1, 'unknown type "colour"', 'unknown type');

    // MUST-REFUSE until an intentional contract change updates the snapshot.
    $changed = $clean;
    $changed[0]['default'] = 'Changed';
    writeFixtureManifest($moduleDirectory, $changed);
    assertResult(
        runLinter($tool, $root),
        1,
        'contract snapshot changed — review the diff; run --update to accept it in this commit if intentional.',
        'changed default'
    );
    assertResult(runLinter($tool, $root, true), 0, 'Snapshot: updated', 'changed default update');
    assertResult(runLinter($tool, $root), 0, 'Snapshot: matched', 'changed default accepted');

    echo "PASS: 5 settings contract lint tests\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
