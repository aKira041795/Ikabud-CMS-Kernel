<?php

declare(strict_types=1);

use Ikabud\Kernel\App;

function kernelUpdatesConfig(): array
{
    $config = config('app.updates', []);
    return is_array($config) ? $config : [];
}

function kernelUpdatesEnabled(): bool
{
    return (bool) (kernelUpdatesConfig()['enabled'] ?? false);
}

function kernelUpdatesRepo(): string
{
    $repo = trim((string) (kernelUpdatesConfig()['github_repo'] ?? ''));
    return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo) === 1 ? $repo : 'aKira041795/Ikabud-CMS-Kernel';
}

function kernelUpdatesTimeoutSeconds(): int
{
    return max(2, (int) (kernelUpdatesConfig()['timeout_seconds'] ?? 10));
}

function kernelUpdatesUserAgent(): string
{
    $agent = trim((string) (kernelUpdatesConfig()['user_agent'] ?? ''));
    return $agent !== '' ? $agent : 'Ikabud-Kernel-Updater/1.0';
}

function kernelUpdatesReleaseLimit(): int
{
    return max(1, min(20, (int) (kernelUpdatesConfig()['release_limit'] ?? 5)));
}

function kernelUpdatesBranch(): string
{
    $branch = trim((string) (kernelUpdatesConfig()['github_branch'] ?? 'master'));
    return $branch !== '' ? $branch : 'master';
}

function kernelUpdatesAutoCheckIntervalMinutes(): int
{
    return max(1, (int) (kernelUpdatesConfig()['auto_check_interval_minutes'] ?? 60));
}

function kernelUpdatesAutoSyncOnPlatformEnabled(): bool
{
    return (bool) (kernelUpdatesConfig()['auto_sync_on_platform'] ?? false);
}

function kernelUpdatesHttpJson(string $url): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ' . kernelUpdatesUserAgent(),
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => kernelUpdatesTimeoutSeconds(),
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $statusCode = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $matches) === 1) {
            $statusCode = (int) $matches[1];
            break;
        }
    }
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return [
        'ok' => $statusCode >= 200 && $statusCode < 300 && is_array($decoded),
        'status' => $statusCode,
        'data' => is_array($decoded) ? $decoded : null,
        'raw' => is_string($raw) ? $raw : '',
    ];
}

function kernelUpdatesEnsureCatalogAvailable(): bool
{
    try {
        $stmt = app()->db()->query("SHOW TABLES LIKE 'kernel_update_catalog'");
        return (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function kernelUpdatesNormalizeVersion(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '0.0.0';
    }
    $value = preg_replace('/^release[-_\/]/i', '', $value) ?? $value;
    $value = preg_replace('/^v(?=\d)/i', '', $value) ?? $value;
    return $value !== '' ? $value : '0.0.0';
}

function kernelUpdatesReleaseSummary(array $release): string
{
    $body = trim((string) ($release['body'] ?? ''));
    if ($body === '') {
        return '';
    }
    $body = preg_replace('/\s+/', ' ', $body) ?? $body;
    return mb_substr($body, 0, 280);
}

function kernelUpdatesManifestAssetUrl(array $release): ?string
{
    $assets = $release['assets'] ?? null;
    if (!is_array($assets)) {
        return null;
    }
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $name = strtolower((string) ($asset['name'] ?? ''));
        if ($name === 'release-manifest.json') {
            $url = trim((string) ($asset['browser_download_url'] ?? ''));
            return $url !== '' ? $url : null;
        }
    }
    return null;
}

function kernelUpdatesFetchReleaseManifest(array $release): ?array
{
    $url = kernelUpdatesManifestAssetUrl($release);
    if ($url === null) {
        return null;
    }
    $response = kernelUpdatesHttpJson($url);
    return !empty($response['ok']) && is_array($response['data']) ? $response['data'] : null;
}

function kernelUpdatesFetchTags(string $repo, int $limit, string $apiBase): array
{
    [$owner, $name] = explode('/', $repo, 2);
    $url = $apiBase . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/tags?per_page=' . $limit;
    $response = kernelUpdatesHttpJson($url);
    if (empty($response['ok']) || !is_array($response['data'])) {
        return [];
    }

    $tags = [];
    foreach ($response['data'] as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        $tagName = trim((string) ($tag['name'] ?? ''));
        if ($tagName === '') {
            continue;
        }
        $tags[] = [
            'id' => 'tag:' . $tagName,
            'tag_name' => $tagName,
            'name' => $tagName,
            'body' => '',
            'html_url' => 'https://github.com/' . $repo . '/tree/' . rawurlencode($tagName),
            'published_at' => '',
            'prerelease' => false,
            'draft' => false,
            'assets' => [],
        ];
    }

    return $tags;
}

function kernelUpdatesFetchBranchHead(string $repo, string $branch, string $apiBase): ?array
{
    [$owner, $name] = explode('/', $repo, 2);
    $url = $apiBase . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/commits?sha=' . rawurlencode($branch) . '&per_page=1';
    $response = kernelUpdatesHttpJson($url);
    if (empty($response['ok']) || !is_array($response['data']) || empty($response['data'][0]) || !is_array($response['data'][0])) {
        return null;
    }

    $commit = $response['data'][0];
    $sha = trim((string) ($commit['sha'] ?? ''));
    if ($sha === '') {
        return null;
    }

    $committer = is_array($commit['commit']['committer'] ?? null) ? $commit['commit']['committer'] : [];
    return [
        'branch' => $branch,
        'sha' => $sha,
        'short_sha' => substr($sha, 0, 7),
        'date' => (string) ($committer['date'] ?? ''),
        'message' => trim((string) ($commit['commit']['message'] ?? '')),
        'url' => (string) ($commit['html_url'] ?? ''),
    ];
}

function kernelUpdatesAudit(string $action, array $context = [], ?array $actor = null): void
{
    write_log('kernel_updates.' . $action, 'info', $context + ['request_id' => request_id()]);
    try {
        $stmt = app()->db()->prepare(
            'INSERT INTO audit_logs (module, actor_user_id, action, entity_type, entity_id, metadata_json, created_at)
             VALUES (:module, :actor_user_id, :action, :entity_type, :entity_id, :metadata_json, NOW())'
        );
        $actorId = (int) (($actor['id'] ?? 0));
        $stmt->execute([
            ':module' => 'kernel-updates',
            ':actor_user_id' => $actorId > 0 ? $actorId : null,
            ':action' => $action,
            ':entity_type' => 'update_catalog',
            ':entity_id' => kernelUpdatesRepo(),
            ':metadata_json' => json_encode($context, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        write_log('kernel_updates.audit_failed', 'warning', [
            'message' => $e->getMessage(),
            'request_id' => request_id(),
        ]);
    }
}

function kernelUpdatesUpsertCatalogEntry(array $entry): void
{
    $stmt = app()->db()->prepare(
        'INSERT INTO kernel_update_catalog
            (release_type, item_id, version, channel, source_repo, source_tag, title, release_url, summary, published_at, payload_json, is_latest)
         VALUES
            (:release_type, :item_id, :version, :channel, :source_repo, :source_tag, :title, :release_url, :summary, :published_at, :payload_json, :is_latest)
         ON DUPLICATE KEY UPDATE
            channel = VALUES(channel),
            source_repo = VALUES(source_repo),
            source_tag = VALUES(source_tag),
            title = VALUES(title),
            release_url = VALUES(release_url),
            summary = VALUES(summary),
            published_at = VALUES(published_at),
            payload_json = VALUES(payload_json),
            is_latest = VALUES(is_latest),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':release_type' => $entry['release_type'],
        ':item_id' => $entry['item_id'],
        ':version' => $entry['version'],
        ':channel' => $entry['channel'],
        ':source_repo' => $entry['source_repo'],
        ':source_tag' => $entry['source_tag'],
        ':title' => $entry['title'],
        ':release_url' => $entry['release_url'],
        ':summary' => $entry['summary'],
        ':published_at' => $entry['published_at'],
        ':payload_json' => json_encode($entry['payload_json'], JSON_UNESCAPED_SLASHES),
        ':is_latest' => $entry['is_latest'] ? 1 : 0,
    ]);
}

function kernelUpdatesStoreSyncState(string $key, ?string $value, ?array $json = null): void
{
    $stmt = app()->db()->prepare(
        'INSERT INTO kernel_update_sync_state (state_key, state_value, state_json)
         VALUES (:state_key, :state_value, :state_json)
         ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), state_json = VALUES(state_json), updated_at = NOW()'
    );
    $stmt->execute([
        ':state_key' => $key,
        ':state_value' => $value,
        ':state_json' => $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES),
    ]);
}

function kernelUpdatesReadSyncState(string $key): ?array
{
    try {
        $stmt = app()->db()->prepare('SELECT state_value, state_json, updated_at FROM kernel_update_sync_state WHERE state_key = :state_key LIMIT 1');
        $stmt->execute([':state_key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $json = json_decode((string) ($row['state_json'] ?? ''), true);
        return [
            'value' => $row['state_value'] ?? null,
            'json' => is_array($json) ? $json : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function kernelUpdatesIsSyncStale(?array $state = null): bool
{
    if (!is_array($state)) {
        return true;
    }
    $updatedAt = trim((string) ($state['updated_at'] ?? ''));
    if ($updatedAt === '') {
        return true;
    }
    $timestamp = strtotime($updatedAt);
    if ($timestamp === false) {
        return true;
    }
    $maxAge = kernelUpdatesAutoCheckIntervalMinutes() * 60;
    return (time() - $timestamp) >= $maxAge;
}

function kernelUpdatesMaybeAutoSync(?array $actor = null): array
{
    $state = kernelUpdatesReadSyncState('github_release_sync');
    if (!kernelUpdatesEnabled()) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'disabled'];
    }
    if (!kernelUpdatesEnsureCatalogAvailable()) {
        return ['ok' => false, 'skipped' => true, 'reason' => 'catalog_missing'];
    }
    if (!kernelUpdatesIsSyncStale($state)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'fresh'];
    }
    return kernelUpdatesSyncCatalog($actor);
}

function kernelUpdatesSyncCatalog(?array $actor = null): array
{
    if (!kernelUpdatesEnabled()) {
        return ['ok' => false, 'error' => 'Update checks are disabled'];
    }
    if (!kernelUpdatesEnsureCatalogAvailable()) {
        return ['ok' => false, 'error' => 'Update catalog tables are missing'];
    }

    $repo = kernelUpdatesRepo();
    $branch = kernelUpdatesBranch();
    $apiBase = rtrim((string) (kernelUpdatesConfig()['github_api_base'] ?? 'https://api.github.com'), '/');
    $limit = kernelUpdatesReleaseLimit();
    $channel = trim((string) (kernelUpdatesConfig()['channel'] ?? 'stable')) ?: 'stable';
    [$owner, $name] = explode('/', $repo, 2);
    $url = $apiBase . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/releases?per_page=' . $limit;
    $response = kernelUpdatesHttpJson($url);
    if (empty($response['ok']) || !is_array($response['data'])) {
        $error = 'GitHub release lookup failed';
        kernelUpdatesStoreSyncState('github_release_sync', $error, [
            'repo' => $repo,
            'status' => (int) ($response['status'] ?? 0),
            'synced_at' => gmdate('c'),
        ]);
        write_log('kernel_updates.sync_failed', 'warning', [
            'repo' => $repo,
            'status' => (int) ($response['status'] ?? 0),
            'request_id' => request_id(),
        ]);
        return ['ok' => false, 'error' => $error, 'status' => (int) ($response['status'] ?? 0)];
    }

    $releases = array_values(array_filter($response['data'], static fn($release) => is_array($release)));
    if (empty($releases)) {
        $releases = kernelUpdatesFetchTags($repo, $limit, $apiBase);
    }

    $previousState = kernelUpdatesReadSyncState('github_release_sync');
    $previousHeadSha = is_array($previousState['json'] ?? null) ? (string) (($previousState['json']['remote_head_sha'] ?? '')) : '';

    if (empty($releases)) {
        $branchHead = kernelUpdatesFetchBranchHead($repo, $branch, $apiBase);
        if ($branchHead !== null) {
            $headChanged = $previousHeadSha !== '' && $previousHeadSha !== (string) $branchHead['sha'];
            $state = [
                'repo' => $repo,
                'branch' => $branch,
                'release_count' => 0,
                'kernel_records' => 0,
                'module_records' => 0,
                'tracking_mode' => 'branch_head',
                'remote_head_sha' => $branchHead['sha'],
                'remote_head_short_sha' => $branchHead['short_sha'],
                'remote_head_date' => $branchHead['date'],
                'remote_head_url' => $branchHead['url'],
                'remote_head_message' => $branchHead['message'],
                'head_changed' => $headChanged,
                'previous_head_sha' => $previousHeadSha,
                'synced_at' => gmdate('c'),
            ];
            kernelUpdatesStoreSyncState('github_release_sync', 'ok', $state);
            kernelUpdatesAudit('updates.check', $state, $actor);
            return ['ok' => true] + $state;
        }
    }
    app()->db()->prepare('UPDATE kernel_update_catalog SET is_latest = 0 WHERE source_repo = :source_repo')->execute([
        ':source_repo' => $repo,
    ]);

    $kernelRecords = 0;
    $moduleRecords = 0;
    $latestAssigned = [];
    foreach ($releases as $release) {
        $tag = trim((string) ($release['tag_name'] ?? ''));
        $version = kernelUpdatesNormalizeVersion($tag !== '' ? $tag : (string) ($release['name'] ?? ''));
        $publishedAt = trim((string) ($release['published_at'] ?? ''));
        $publishedAtDb = $publishedAt !== '' ? gmdate('Y-m-d H:i:s', strtotime($publishedAt)) : null;
        $payload = [
            'release_id' => $release['id'] ?? null,
            'tag_name' => $tag,
            'prerelease' => (bool) ($release['prerelease'] ?? false),
            'draft' => (bool) ($release['draft'] ?? false),
            'assets' => $release['assets'] ?? [],
        ];
        $kernelIsLatest = empty($latestAssigned['kernel']);
        kernelUpdatesUpsertCatalogEntry([
            'release_type' => 'kernel',
            'item_id' => 'kernel',
            'version' => $version,
            'channel' => $channel,
            'source_repo' => $repo,
            'source_tag' => $tag,
            'title' => (string) ($release['name'] ?? ($tag !== '' ? $tag : $version)),
            'release_url' => (string) ($release['html_url'] ?? ''),
            'summary' => kernelUpdatesReleaseSummary($release),
            'published_at' => $publishedAtDb,
            'payload_json' => $payload,
            'is_latest' => $kernelIsLatest,
        ]);
        $kernelRecords++;
        if ($kernelIsLatest) {
            $latestAssigned['kernel'] = true;
        }

        $manifest = kernelUpdatesFetchReleaseManifest($release);
        $modules = is_array($manifest['modules'] ?? null) ? array_values($manifest['modules']) : [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            $moduleId = trim((string) ($module['id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }
            $moduleVersion = kernelUpdatesNormalizeVersion((string) ($module['version'] ?? '0.0.0'));
            $moduleIsLatest = empty($latestAssigned['module:' . $moduleId]);
            kernelUpdatesUpsertCatalogEntry([
                'release_type' => 'module',
                'item_id' => $moduleId,
                'version' => $moduleVersion,
                'channel' => $channel,
                'source_repo' => $repo,
                'source_tag' => $tag,
                'title' => (string) ($module['name'] ?? $moduleId),
                'release_url' => (string) ($module['package_url'] ?? ($release['html_url'] ?? '')),
                'summary' => trim((string) ($module['summary'] ?? '')),
                'published_at' => $publishedAtDb,
                'payload_json' => $module + ['release_tag' => $tag],
                'is_latest' => $moduleIsLatest,
            ]);
            $moduleRecords++;
            if ($moduleIsLatest) {
                $latestAssigned['module:' . $moduleId] = true;
            }
        }
    }

    $state = [
        'repo' => $repo,
        'branch' => $branch,
        'release_count' => count($releases),
        'kernel_records' => $kernelRecords,
        'module_records' => $moduleRecords,
        'tracking_mode' => 'catalog',
        'synced_at' => gmdate('c'),
    ];
    kernelUpdatesStoreSyncState('github_release_sync', 'ok', $state);
    kernelUpdatesAudit('updates.check', $state, $actor);
    return ['ok' => true] + $state;
}

function kernelUpdatesLatestCatalogRows(): array
{
    if (!kernelUpdatesEnsureCatalogAvailable()) {
        return [];
    }
    try {
        $stmt = app()->db()->query(
            'SELECT release_type, item_id, version, channel, source_repo, source_tag, title, release_url, summary, published_at, payload_json, is_latest
             FROM kernel_update_catalog
             WHERE is_latest = 1
             ORDER BY release_type ASC, item_id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function kernelUpdatesBuildSummary(): array
{
    $installedKernelVersion = App::KERNEL_VERSION;
    $rows = kernelUpdatesLatestCatalogRows();
    $latestKernel = null;
    $latestModules = [];
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $entry = [
            'item_id' => (string) ($row['item_id'] ?? ''),
            'version' => (string) ($row['version'] ?? '0.0.0'),
            'title' => (string) ($row['title'] ?? ''),
            'release_url' => (string) ($row['release_url'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'source_tag' => (string) ($row['source_tag'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
        ];
        if (($row['release_type'] ?? '') === 'kernel') {
            $latestKernel = $entry;
        } elseif (($row['release_type'] ?? '') === 'module') {
            $latestModules[$entry['item_id']] = $entry;
        }
    }

    $installedModules = [];
    foreach (discoverModules() as $module) {
        $moduleId = (string) ($module['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }
        $installedVersion = (string) ($module['version'] ?? '0.0.0');
        $latest = $latestModules[$moduleId] ?? null;
        $updateAvailable = $latest !== null && version_compare(kernelUpdatesNormalizeVersion($latest['version']), kernelUpdatesNormalizeVersion($installedVersion), '>');
        $installedModules[] = [
            'id' => $moduleId,
            'name' => (string) ($module['name'] ?? $moduleId),
            'installed_version' => $installedVersion,
            'available_version' => $latest['version'] ?? null,
            'update_available' => $updateAvailable,
            'release_url' => $latest['release_url'] ?? '',
            'summary' => $latest['summary'] ?? '',
            'requires_kernel' => $latest['payload']['requires_kernel'] ?? null,
        ];
    }

    $lastSync = kernelUpdatesReadSyncState('github_release_sync');
    $lastSyncJson = is_array($lastSync['json'] ?? null) ? $lastSync['json'] : [];
    $kernelUpdateAvailable = $latestKernel !== null && version_compare(kernelUpdatesNormalizeVersion($latestKernel['version']), kernelUpdatesNormalizeVersion($installedKernelVersion), '>');
    if ($latestKernel === null && (($lastSyncJson['tracking_mode'] ?? '') === 'branch_head')) {
        $latestKernel = [
            'version' => (string) (($lastSyncJson['branch'] ?? kernelUpdatesBranch()) . '@' . ($lastSyncJson['remote_head_short_sha'] ?? 'unknown')),
            'release_url' => (string) ($lastSyncJson['remote_head_url'] ?? ''),
            'summary' => (string) ($lastSyncJson['remote_head_message'] ?? 'Latest branch head detected from GitHub.'),
            'published_at' => (string) ($lastSyncJson['remote_head_date'] ?? ''),
        ];
        $kernelUpdateAvailable = (bool) ($lastSyncJson['head_changed'] ?? false);
    }
    return [
        'enabled' => kernelUpdatesEnabled(),
        'repo' => kernelUpdatesRepo(),
        'branch' => kernelUpdatesBranch(),
        'last_sync' => $lastSync,
        'auto_check_interval_minutes' => kernelUpdatesAutoCheckIntervalMinutes(),
        'sync_stale' => kernelUpdatesIsSyncStale($lastSync),
        'catalog_ready' => kernelUpdatesEnsureCatalogAvailable(),
        'kernel' => [
            'installed_version' => $installedKernelVersion,
            'available_version' => $latestKernel['version'] ?? null,
            'update_available' => $kernelUpdateAvailable,
            'release_url' => $latestKernel['release_url'] ?? '',
            'summary' => $latestKernel['summary'] ?? '',
            'published_at' => $latestKernel['published_at'] ?? '',
        ],
        'modules' => $installedModules,
        'module_updates_count' => count(array_filter($installedModules, static fn($module) => !empty($module['update_available']))),
        'manifest_support' => count($latestModules) > 0,
    ];
}