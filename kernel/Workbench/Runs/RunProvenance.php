<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Runs;

/**
 * Canonical run provenance block — attached to every top-level Workbench report and export.
 *
 * Required fields per DeepSeek v4 Priority 1:
 *   run_id, started_at, finished_at, completion_status,
 *   git_sha, module_id, module_version, app_url,
 *   environment_fingerprint, tenant/role fixture identity,
 *   scenario/seed version, test-plan version,
 *   gate_policy, ai_policy, resolved_provider/model,
 *   artifact_schema_versions, redaction_status
 *
 * Rules:
 *   - completion_status must be 'complete', 'interrupted', 'blocked', or 'failed-before-analysis'
 *   - Partially completed runs must never be presented as release certification
 *   - Effective AI policy must show the resolved model, not only the configured default
 *   - Each report must reference exact input artifacts by relative path and content hash
 */
final class RunProvenance
{
    private const COMPLETION_STATUSES = ['complete', 'interrupted', 'blocked', 'failed-before-analysis'];

    /** @param array<string,mixed> $params */
    public function build(array $params): array
    {
        $completionStatus = (string) ($params['completion_status'] ?? 'failed-before-analysis');
        if (!in_array($completionStatus, self::COMPLETION_STATUSES, true)) {
            $completionStatus = 'failed-before-analysis';
        }

        $provenance = [
            'provenance_schema' => 'ark.workbench-run-provenance.v1',
            'run_id' => (string) ($params['run_id'] ?? ''),
            'started_at' => (string) ($params['started_at'] ?? gmdate(DATE_ATOM)),
            'finished_at' => (string) ($params['finished_at'] ?? gmdate(DATE_ATOM)),
            'completion_status' => $completionStatus,
            'git_sha' => $this->resolveGitSha($params),
            'module_id' => (string) ($params['module_id'] ?? ''),
            'module_version' => $this->resolveModuleVersion($params),
            'app_url' => (string) ($params['app_url'] ?? $this->detectAppUrl()),
            'environment_fingerprint' => $this->buildEnvironmentFingerprint($params),
            'tenant_identity' => $this->buildTenantIdentity($params),
            'role_fixture_identity' => $this->buildRoleFixtureIdentity($params),
            'scenario_version' => (string) ($params['scenario_version'] ?? ''),
            'test_plan_version' => (string) ($params['test_plan_version'] ?? ''),
            'gate_policy' => (string) ($params['gate_policy'] ?? 'critical'),
            'ai_policy' => $this->buildAiPolicy($params),
            'resolved_provider' => (string) ($params['resolved_provider'] ?? ''),
            'resolved_model' => (string) ($params['resolved_model'] ?? ''),
            'artifact_schema_versions' => $this->buildArtifactSchemaVersions($params),
            'input_artifacts' => $this->buildInputArtifacts($params),
            'redaction_status' => (string) ($params['redaction_status'] ?? 'none'),
        ];

        // A partially completed run must never be presented as release certification
        if ($completionStatus !== 'complete') {
            $provenance['certification_disclaimer'] =
                'This run did not complete. Results must not be used for release certification.';
        }

        return $provenance;
    }

    /** @param array<string,mixed> $params */
    private function resolveGitSha(array $params): string
    {
        $sha = (string) ($params['git_sha'] ?? '');
        if ($sha !== '') {
            return $sha;
        }

        // Try to detect from the environment
        $envSha = getenv('GIT_COMMIT') ?: getenv('GIT_SHA') ?: getenv('COMMIT_REF') ?: '';
        if ($envSha !== '') {
            return $envSha;
        }

        // Try to detect from the git binary
        $headFile = ($params['project_root'] ?? getcwd()) . '/.git/HEAD';
        if (is_file($headFile)) {
            $head = trim((string) file_get_contents($headFile));
            if (str_starts_with($head, 'ref: ')) {
                $refPath = ($params['project_root'] ?? getcwd()) . '/.git/' . substr($head, 5);
                if (is_file($refPath)) {
                    return trim((string) file_get_contents($refPath));
                }
            } elseif (strlen($head) === 40 && ctype_xdigit($head)) {
                return $head;
            }
        }

        return 'unknown';
    }

    /** @param array<string,mixed> $params */
    private function resolveModuleVersion(array $params): string
    {
        $version = (string) ($params['module_version'] ?? '');
        if ($version !== '') {
            return $version;
        }

        $moduleId = (string) ($params['module_id'] ?? '');
        if ($moduleId === '') {
            return 'unknown';
        }

        // Try to read module.json
        $root = $params['project_root'] ?? getcwd();
        $moduleJson = $root . '/modules/' . $moduleId . '/module.json';
        if (is_file($moduleJson)) {
            $manifest = json_decode((string) file_get_contents($moduleJson), true);
            if (is_array($manifest) && isset($manifest['version'])) {
                return (string) $manifest['version'];
            }
        }

        return 'unknown';
    }

    /** @param array<string,mixed> $params */
    private function buildEnvironmentFingerprint(array $params): string
    {
        $components = [
            'php' => PHP_VERSION,
            'os' => PHP_OS . ' ' . php_uname('r'),
            'host' => (string) ($params['environment_host'] ?? gethostname()),
            'app_env' => (string) ($params['app_env'] ?? getenv('APP_ENV') ?: 'production'),
        ];

        if (!empty($params['environment_fingerprint'])) {
            return (string) $params['environment_fingerprint'];
        }

        return hash('sha256', json_encode($components, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $params */
    private function buildTenantIdentity(array $params): array
    {
        return [
            'tenant_id' => (int) ($params['tenant_id'] ?? 0),
            'tenant_key' => (string) ($params['tenant_key'] ?? ''),
            'domain' => (string) ($params['tenant_domain'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $params */
    private function buildRoleFixtureIdentity(array $params): array
    {
        return [
            'role' => (string) ($params['fixture_role'] ?? ''),
            'user_id' => (int) ($params['fixture_user_id'] ?? 0),
            'fixture_label' => (string) ($params['fixture_label'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $params */
    private function buildAiPolicy(array $params): array
    {
        $configured = (array) ($params['ai_policy'] ?? []);
        return [
            'ai_enabled' => (bool) ($configured['enabled'] ?? $params['ai_enabled'] ?? false),
            'configured_provider' => (string) ($configured['provider'] ?? $params['configured_provider'] ?? ''),
            'configured_model' => (string) ($configured['model'] ?? $params['configured_model'] ?? ''),
            'tier' => (string) ($configured['tier'] ?? $params['ai_tier'] ?? 'free'),
            'resolved_provider' => (string) ($params['resolved_provider'] ?? $configured['provider'] ?? ''),
            'resolved_model' => (string) ($params['resolved_model'] ?? $configured['model'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $params */
    private function buildArtifactSchemaVersions(array $params): array
    {
        $versions = (array) ($params['artifact_schema_versions'] ?? []);
        $versions += [
            'provenance' => 'ark.workbench-run-provenance.v1',
            'manifest' => 'ark.workbench-run-manifest.v1',
            'evidence' => 'ark.workbench-evidence-observation.v1',
            'issues' => 'ark.workbench-issue.v1',
        ];
        return $versions;
    }

    /** @param array<string,mixed> $params */
    private function buildInputArtifacts(array $params): array
    {
        $artifacts = (array) ($params['input_artifacts'] ?? []);
        $normalized = [];

        foreach ($artifacts as $key => $artifact) {
            if (is_string($artifact)) {
                $path = $artifact;
                $hash = is_file($path) ? hash_file('sha256', $path) : '';
                $normalized[$key] = [
                    'relative_path' => $this->relativePath(
                        (string) ($params['project_root'] ?? getcwd()),
                        $path
                    ),
                    'content_hash' => $hash,
                ];
            } elseif (is_array($artifact)) {
                $path = (string) ($artifact['path'] ?? $artifact['relative_path'] ?? '');
                $absolutePath = $path;
                if ($absolutePath !== '' && !str_starts_with($absolutePath, '/')) {
                    $absolutePath = rtrim((string) ($params['project_root'] ?? getcwd()), '/') . '/' . $absolutePath;
                }
                $normalized[$key] = [
                    'relative_path' => $this->relativePath(
                        (string) ($params['project_root'] ?? getcwd()),
                        $absolutePath !== '' ? $absolutePath : $path
                    ),
                    'content_hash' => (string) ($artifact['content_hash'] ?? (is_file($absolutePath) ? hash_file('sha256', $absolutePath) : '')),
                ];
            }
        }

        return $normalized;
    }

    private function detectAppUrl(): string
    {
        $url = getenv('APP_URL') ?: getenv('APP_BASE_URL') ?: '';
        return $url !== '' ? $url : 'http://localhost';
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }
        return $path;
    }
}
