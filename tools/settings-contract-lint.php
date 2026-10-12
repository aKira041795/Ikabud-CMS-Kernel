<?php

/**
 * Settings declaration contract linter (Kernel OS 6.3, Track A).
 *
 * This tool checks declaration quality only. Declaration quality is not runtime
 * compatibility; that gate is separate. It does not bootstrap the application,
 * access a database, or participate in any runtime settings read/write path.
 */

declare(strict_types=1);

const SETTINGS_CONTRACT_TYPES = [
    'bool', 'checkbox', 'email', 'number', 'password', 'select', 'text', 'textarea', 'time',
];
const SETTINGS_CONTRACT_BASELINE = 'tests/fixtures/settings-contract-baseline.json';

/** @return never */
function usage(string $message = ''): void
{
    if ($message !== '') {
        fwrite(STDERR, "ERROR: {$message}\n");
    }
    fwrite(STDERR, "Usage: php tools/settings-contract-lint.php [--root=<dir>] [--update]\n");
    exit(2);
}

/** @return mixed */
function normalizeValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('normalizeValue', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = normalizeValue($item);
    }
    return $value;
}

/** @return list<string> */
function manifestFiles(string $root): array
{
    $files = array_merge(
        glob($root . '/modules/*/module.json', GLOB_NOSORT) ?: [],
        glob($root . '/modules/*/*/module.json', GLOB_NOSORT) ?: [],
    );
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

$rootArgument = null;
$update = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--update') {
        if ($update) {
            usage('duplicate --update option');
        }
        $update = true;
        continue;
    }
    if (str_starts_with($argument, '--root=')) {
        if ($rootArgument !== null) {
            usage('duplicate --root option');
        }
        $rootArgument = substr($argument, strlen('--root='));
        if ($rootArgument === '') {
            usage('--root requires a directory');
        }
        continue;
    }
    usage("unknown option: {$argument}");
}

$root = realpath($rootArgument ?? dirname(__DIR__));
if ($root === false || !is_dir($root)) {
    usage('root directory does not exist');
}
$root = rtrim(str_replace('\\', '/', $root), '/');
$baselinePath = $root . '/' . SETTINGS_CONTRACT_BASELINE;
$files = manifestFiles($root);

$errors = [];
$warnings = [];
$contracts = [];
$fieldCount = 0;
$moduleCount = 0;
$missingDefaultCount = 0;
$selectWithoutOptionsCount = 0;

foreach ($files as $file) {
    $relative = substr(str_replace('\\', '/', $file), strlen($root) + 1);
    try {
        $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = "{$relative}: invalid JSON ({$exception->getMessage()})";
        continue;
    }
    if (!is_array($manifest) || !array_key_exists('settings_fields', $manifest)) {
        continue;
    }
    if (!is_array($manifest['settings_fields'])) {
        $errors[] = "{$relative}: settings_fields must be an array";
        continue;
    }

    ++$moduleCount;
    $seen = [];
    $fields = [];
    foreach ($manifest['settings_fields'] as $index => $field) {
        ++$fieldCount;
        $location = "{$relative}: settings_fields[{$index}]";
        if (!is_array($field)) {
            $errors[] = "{$location}: field must be an object";
            continue;
        }

        $key = $field['key'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            $errors[] = "{$location}: field key is empty";
            $key = '';
        } elseif (isset($seen[$key])) {
            $errors[] = "{$relative}: duplicate field key \"{$key}\" (indexes {$seen[$key]} and {$index})";
        } else {
            $seen[$key] = $index;
        }

        $type = $field['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $errors[] = "{$location}" . ($key !== '' ? " ({$key})" : '') . ': missing type';
            $type = null;
        } elseif (!in_array($type, SETTINGS_CONTRACT_TYPES, true)) {
            $errors[] = "{$location}" . ($key !== '' ? " ({$key})" : '') . ": unknown type \"{$type}\"";
        }

        $hasDefault = array_key_exists('default', $field);
        if (!$hasDefault) {
            ++$missingDefaultCount;
            $warnings[] = "{$relative}: {$key} lacks a default (allowed legacy absence)";
        }
        $hasOptions = array_key_exists('options', $field);
        if ($type === 'select' && !$hasOptions) {
            ++$selectWithoutOptionsCount;
            $warnings[] = "{$relative}: {$key} is a select without options (allowed legacy/dynamic exception)";
        }

        $contract = [
            'key' => $key,
            'type' => $type,
            'has_default' => $hasDefault,
        ];
        if ($hasDefault) {
            $contract['default'] = normalizeValue($field['default']);
        }
        $contract['has_options'] = $hasOptions;
        if ($hasOptions) {
            $contract['options'] = normalizeValue($field['options']);
        }
        $fields[] = $contract;
    }
    usort($fields, static fn (array $a, array $b): int => [$a['key'], $a['type'] ?? ''] <=> [$b['key'], $b['type'] ?? '']);
    $contracts[$relative] = $fields;
}
ksort($contracts, SORT_STRING);
sort($errors, SORT_STRING);
sort($warnings, SORT_STRING);

$snapshot = [
    'schema_version' => 1,
    'contracts' => $contracts,
];
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$snapshotState = 'matched';
$exitCode = 0;

if ($errors !== []) {
    $snapshotState = 'not checked (declaration errors)';
    $exitCode = 1;
} elseif ($update) {
    $directory = dirname($baselinePath);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "ERROR: cannot create baseline directory: {$directory}\n");
        exit(2);
    }
    if (file_put_contents($baselinePath, $json) === false) {
        fwrite(STDERR, "ERROR: cannot write baseline: {$baselinePath}\n");
        exit(2);
    }
    $snapshotState = 'updated';
} elseif (!is_file($baselinePath)) {
    fwrite(STDERR, "ERROR: contract baseline missing: {$baselinePath}\n");
    fwrite(STDERR, "Run with --update to generate it.\n");
    exit(2);
} elseif ((string) file_get_contents($baselinePath) !== $json) {
    $errors[] = 'contract snapshot changed — review the diff; run --update to accept it in this commit if intentional.';
    $snapshotState = 'mismatch';
    $exitCode = 1;
}

foreach ($errors as $error) {
    echo "ERROR: {$error}\n";
}
foreach ($warnings as $warning) {
    echo "WARNING: {$warning}\n";
}

echo "Summary: manifests=" . count($files)
    . " settings_modules={$moduleCount} fields={$fieldCount}"
    . " hard_failures=" . count($errors)
    . " warnings=" . count($warnings)
    . " missing_defaults={$missingDefaultCount}"
    . " selects_without_options={$selectWithoutOptionsCount}\n";
echo "Snapshot: {$snapshotState}\n";
echo "Baseline: {$baselinePath}\n";

exit($exitCode);
