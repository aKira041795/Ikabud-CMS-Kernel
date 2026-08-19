<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Validates that a verification-layer artifact is a legitimate, task-bound test
 * result — not an arbitrary or replayed file. Closing P1-2: the ingestor and the
 * release gate must not certify a pass merely because some file exists, matches
 * its content hash, and names the task. A valid artifact must:
 *  - exist on disk and match its recorded sha256 content hash;
 *  - be a JSON object declaring the recognized result schema;
 *  - declare a consistent, non-failing summary (exit_code 0, total > 0,
 *    failed === 0, passed > 0, passed + failed + skipped === total);
 *  - carry a valid HMAC attestation from a configured external runner key;
 *  - be bound to the task (task_id) AND to the exact implementation evidence
 *    the layer claims: contract revision, Git HEAD, working-tree fingerprint,
 *    the verification layer it certifies, and a runner identity.
 *
 * Public task/Git fields are not treated as proof: a self-authored or replayed
 * artifact cannot certify a layer without the configured runner attestation.
 */
final class DevelopmentVerificationArtifact
{
    /** Schema a task-bound test result artifact must declare. */
    public const RESULT_SCHEMA = 'ark.workbench-test-result.v1';

    public const SIGNATURE_ALGORITHM = 'hmac-sha256';

    private const KEY_ENV = 'WORKBENCH_EVIDENCE_HMAC_KEY';

    private const KEY_ID_ENV = 'WORKBENCH_EVIDENCE_KEY_ID';

    /**
     * Validate an artifact from disk.
     *
     * @param string $path Absolute path to the artifact.
     * @param string $taskId Task the layer is being recorded against.
     * @param string $expectedHash sha256 content hash recorded at ingest.
     * @param array<string,mixed> $binding Expected bindings: contract_revision,
     *                                     head, fingerprint, layer, runner.
     * @return array{valid:bool,reasons:list<string>,summary:?array<string,mixed>}
     */
    public static function validate(string $path, string $taskId, string $expectedHash, array $binding = []): array
    {
        $reasons = [];
        if (!is_file($path)) {
            return ['valid' => false, 'reasons' => ['artifact not found on disk'], 'summary' => null];
        }
        if (hash_file('sha256', $path) !== $expectedHash) {
            return ['valid' => false, 'reasons' => ['artifact content hash does not match'], 'summary' => null];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['valid' => false, 'reasons' => ['artifact is not a valid JSON result'], 'summary' => null];
        }

        // Content bindings are public and can be copied into a fabricated JSON
        // document. Require a runner attestation whose key is configured outside
        // the artifact/task ledger so caller-authored PASS claims are not trusted.
        $trustedKey = self::trustedKey();
        $algorithm = (string) ($decoded['signature_algorithm'] ?? '');
        $keyId = (string) ($decoded['attestation_key_id'] ?? '');
        $expectedKeyId = self::trustedKeyId();
        $signature = (string) ($decoded['signature'] ?? '');
        if ($trustedKey === null) {
            $reasons[] = 'trusted verification attestation key is not configured';
        }
        if ($algorithm !== self::SIGNATURE_ALGORITHM) {
            $reasons[] = 'artifact signature_algorithm must be ' . self::SIGNATURE_ALGORITHM;
        }
        if ($keyId === '' || !hash_equals($expectedKeyId, $keyId)) {
            $reasons[] = 'artifact attestation_key_id does not match the trusted key';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            $reasons[] = 'artifact signature is missing or invalid';
        } elseif ($trustedKey !== null) {
            $unsigned = $decoded;
            unset($unsigned['signature']);
            $expectedSignature = hash_hmac('sha256', self::canonicalJson($unsigned), $trustedKey);
            if (!hash_equals($expectedSignature, $signature)) {
                $reasons[] = 'artifact signature does not match the trusted runner attestation';
            }
        }

        $summary = (array) ($decoded['summary'] ?? []);
        $failed = (int) ($summary['failed'] ?? 0);
        $total = (int) ($summary['total'] ?? 0);
        $passed = (int) ($summary['passed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $exitCode = (int) ($summary['exit_code'] ?? -1);
        if ((string) ($decoded['schema'] ?? '') !== self::RESULT_SCHEMA) {
            $reasons[] = 'artifact does not declare the result schema ' . self::RESULT_SCHEMA;
        }
        if ($exitCode !== 0) {
            $reasons[] = "artifact summary exit_code is {$exitCode}, not 0";
        }
        if ($total <= 0) {
            $reasons[] = 'artifact summary declares no executed tests';
        }
        if ($failed !== 0) {
            $reasons[] = "artifact summary reports {$failed} failed test(s)";
        }
        if ($passed <= 0) {
            $reasons[] = 'artifact summary declares no passed tests';
        }
        if ($total > 0 && ($passed + $failed + $skipped) !== $total) {
            $reasons[] = 'artifact summary counts are inconsistent (passed+failed+skipped != total)';
        }

        $artifactTask = (string) ($decoded['task_id'] ?? '');
        if ($artifactTask === '') {
            $reasons[] = 'artifact is not bound to a task (missing task_id)';
        } elseif ($artifactTask !== $taskId) {
            $reasons[] = "artifact is bound to task {$artifactTask}, not {$taskId}";
        }

        // Bind the artifact to the exact implementation evidence the layer claims.
        $binding = array_map('strval', $binding);
        $artifactRevision = (string) ($decoded['contract_revision'] ?? '');
        $expectedRevision = (string) ($binding['contract_revision'] ?? '');
        if ($artifactRevision === '') {
            $reasons[] = 'artifact is not bound to a contract revision';
        } elseif ($expectedRevision === '') {
            $reasons[] = 'no contract revision binding is available to verify the artifact';
        } elseif ($artifactRevision !== $expectedRevision) {
            $reasons[] = "artifact contract_revision {$artifactRevision} does not match {$expectedRevision}";
        }

        $artifactHead = (string) ($decoded['git_head'] ?? '');
        $expectedHead = (string) ($binding['head'] ?? '');
        if ($artifactHead === '') {
            $reasons[] = 'artifact is not bound to a Git HEAD';
        } elseif ($expectedHead === '') {
            $reasons[] = 'no Git HEAD binding is available to verify the artifact';
        } elseif ($artifactHead !== $expectedHead) {
            $reasons[] = 'artifact Git HEAD does not match the implementation head';
        }

        $artifactFp = (string) ($decoded['fingerprint'] ?? '');
        $expectedFp = (string) ($binding['fingerprint'] ?? '');
        if ($artifactFp === '') {
            $reasons[] = 'artifact is not bound to a working-tree fingerprint';
        } elseif ($expectedFp === '') {
            $reasons[] = 'no working-tree fingerprint binding is available to verify the artifact';
        } elseif ($artifactFp !== $expectedFp) {
            $reasons[] = 'artifact working-tree fingerprint does not match the implementation fingerprint';
        }

        $runner = (string) ($decoded['runner'] ?? '');
        if ($runner === '') {
            $reasons[] = 'artifact does not declare a runner identity';
        }

        $artifactLayer = (string) ($decoded['layer'] ?? '');
        $expectedLayer = (string) ($binding['layer'] ?? '');
        if ($artifactLayer === '') {
            $reasons[] = 'artifact does not declare the verification layer it certifies';
        } elseif ($expectedLayer === '') {
            $reasons[] = 'no verification layer binding is available to verify the artifact';
        } elseif ($artifactLayer !== $expectedLayer) {
            $reasons[] = "artifact layer {$artifactLayer} does not certify the {$expectedLayer} layer";
        }

        if ($reasons !== []) {
            return ['valid' => false, 'reasons' => $reasons, 'summary' => $summary];
        }

        return ['valid' => true, 'reasons' => [], 'summary' => $summary];
    }

    /**
     * Sign an artifact payload for a trusted external runner. The returned
     * signature covers every field except `signature` using canonical JSON.
     * The shared key is never persisted in the artifact or task ledger.
     *
     * @param array<string,mixed> $artifact
     */
    public static function sign(array $artifact, ?string $key = null): string
    {
        unset($artifact['signature']);
        $key ??= self::trustedKey();
        if ($key === null) {
            throw new \RuntimeException(self::KEY_ENV . ' must contain at least 32 bytes');
        }

        return hash_hmac('sha256', self::canonicalJson($artifact), $key);
    }

    public static function trustedKeyId(): string
    {
        $keyId = trim((string) ($_ENV[self::KEY_ID_ENV] ?? getenv(self::KEY_ID_ENV) ?: 'default'));

        return $keyId !== '' ? $keyId : 'default';
    }

    private static function trustedKey(): ?string
    {
        $key = (string) ($_ENV[self::KEY_ENV] ?? getenv(self::KEY_ENV) ?: '');

        return strlen($key) >= 32 ? $key : null;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
