<?php

declare(strict_types=1);

if (!function_exists('boundaryFindingFingerprint')) {
    function boundaryFindingFingerprint(array $finding): string
    {
        $rule = trim((string)($finding['rule'] ?? ''));
        $module = trim((string)($finding['module'] ?? ''));
        $path = str_replace('\\', '/', trim((string)($finding['path'] ?? '')));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = preg_replace('#^(?:\./)+#', '', $path) ?? $path;
        $evidence = trim((string)($finding['evidence'] ?? ''));
        $constructKey = $evidence;

        switch ($rule) {
            case 'cross-module-table-access':
                $constructKey = trim((string)strtok($evidence, '|'));
                break;

            case 'undeclared-capability-call':
            case 'template-entity-source':
                $constructKey = $evidence;
                break;

            case 'auth-route-contract':
            case 'auth-route-contract-warning-strict':
                $missing = array_values(array_filter(array_map('trim', explode(',', $evidence)), static fn(string $item): bool => $item !== ''));
                sort($missing, SORT_STRING);
                $constructKey = implode(',', $missing);
                break;

            case 'manifest-schema-fatal':
            case 'manifest-schema-cert-blocker':
                $parts = explode('|', $evidence, 3);
                $constructKey = trim((string)($parts[0] ?? '')) . '|' . trim((string)($parts[1] ?? ''));
                break;
        }

        return sha1($rule . '|' . $module . '|' . $path . '|' . $constructKey);
    }
}

if (!function_exists('boundaryNewFindings')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function boundaryNewFindings(array $current, array $baseline): array
    {
        $baselineFingerprints = [];
        foreach ($baseline as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $fingerprint = trim((string)($finding['fingerprint'] ?? ''));
            if ($fingerprint === '') {
                $fingerprint = boundaryFindingFingerprint($finding);
            }
            $baselineFingerprints[$fingerprint] = true;
        }

        $new = [];
        foreach ($current as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $fingerprint = trim((string)($finding['fingerprint'] ?? ''));
            if ($fingerprint === '') {
                $fingerprint = boundaryFindingFingerprint($finding);
                $finding['fingerprint'] = $fingerprint;
            }
            if (!isset($baselineFingerprints[$fingerprint])) {
                $new[] = $finding;
            }
        }

        return $new;
    }
}
