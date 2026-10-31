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

// ─── Extension update feeds ────────────────────────────────────────────────────
//
// The functions above track ONE repository's releases for the kernel and the
// modules shipped in it. An extension installed from a ZIP has no repository in
// this install, so its creator publishes its own feed and the manifest names it:
//
//     "update_url": "https://example.com/my-extension/update.json"
//
// Feed shape (the only shape accepted):
//
//     {
//       "id": "my-extension",
//       "versions": [
//         {
//           "version": "1.2.0",
//           "package_url": "https://example.com/my-extension-1.2.0.zip",
//           "sha256": "<hex digest of the exact zip bytes>",
//           "channel": "stable",
//           "requires_kernel": ">=6.2",
//           "released_at": "2026-10-01",
//           "notes": "Fixes ..."
//         }
//       ]
//     }
//
// A version without `sha256` is REFUSED, not trusted: an update the installer
// cannot verify is not an update, it is remote code execution with a nice
// version number. The package's own module.sig.json is verified on top of the
// digest by moduleVerifyPackageSignature() during the update itself.
//
// Network access is confined to moduleExtensionUpdateCheck() and
// moduleExtensionDownloadPackage(). Rendering a page only ever reads the cached
// state row, so a tenant admin page cannot be made to wait on a remote host.

/**
 * State row key for one extension's cached update state.
 */
function moduleExtensionUpdateStateKey(string $moduleId): string
{
    return 'extension_update:' . strtolower(trim($moduleId));
}

/**
 * The feed URL a manifest declares, or '' when it declares none we will fetch.
 *
 * Refuses anything but https (plain http is allowed only on an explicit
 * APP_EXTENSION_FEEDS_ALLOW_HTTP=1 for local development), and refuses loopback
 * and private-network hosts so a package cannot aim the updater at the server it
 * is running on.
 */
function moduleExtensionUpdateFeedUrl(array $manifest): string
{
    $raw = $manifest['update_url'] ?? null;
    if (!is_string($raw)) {
        return '';
    }
    $url = trim($raw);
    if ($url === '' || strlen($url) > 500) {
        return '';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $allowHttp = filter_var($_ENV['APP_EXTENSION_FEEDS_ALLOW_HTTP'] ?? 'false', FILTER_VALIDATE_BOOL);
    if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
        return '';
    }

    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    if ($host === '') {
        return '';
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return '';
    }
    if (preg_match('/^127\./', $host) === 1 || $host === '::1') {
        return '';
    }
    if (preg_match('/^10\./', $host) === 1 || preg_match('/^192\.168\./', $host) === 1) {
        return '';
    }
    if (preg_match('/^169\.254\./', $host) === 1 || $host === '0.0.0.0') {
        return '';
    }
    if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) === 1) {
        return '';
    }

    return $url;
}

/**
 * Cached update state for one extension. Never touches the network.
 *
 * @return array<string,mixed>
 */
function moduleExtensionUpdateState(string $moduleId): array
{
    $row = kernelUpdatesReadSyncState(moduleExtensionUpdateStateKey($moduleId));
    $json = is_array($row['json'] ?? null) ? $row['json'] : [];

    return [
        'module_id' => $moduleId,
        'feed_url' => (string) ($json['feed_url'] ?? ''),
        'auto_update' => !empty($json['auto_update']),
        'installed_version' => (string) ($json['installed_version'] ?? ''),
        'latest_version' => (string) ($json['latest_version'] ?? ''),
        'update_available' => !empty($json['update_available']),
        'package_url' => (string) ($json['package_url'] ?? ''),
        'sha256' => (string) ($json['sha256'] ?? ''),
        'channel' => (string) ($json['channel'] ?? 'stable'),
        'requires_kernel' => (string) ($json['requires_kernel'] ?? ''),
        'notes' => (string) ($json['notes'] ?? ''),
        'checked_at' => (string) ($json['checked_at'] ?? ''),
        'error' => (string) ($json['error'] ?? ''),
        'last_applied' => is_array($json['last_applied'] ?? null) ? $json['last_applied'] : null,
    ];
}

/**
 * Persist one extension's update state. Never throws: an unwritable state table
 * degrades to "no cached update", which is the safe direction.
 *
 * @param array<string,mixed> $state
 */
function moduleExtensionStoreUpdateState(string $moduleId, array $state): bool
{
    try {
        kernelUpdatesStoreSyncState(
            moduleExtensionUpdateStateKey($moduleId),
            empty($state['update_available']) ? 'current' : 'update_available',
            $state
        );
        return true;
    } catch (Throwable $e) {
        write_log('extension_update.state_write_failed', 'warning', [
            'module_id' => $moduleId,
            'error' => $e->getMessage(),
            'request_id' => function_exists('request_id') ? request_id() : null,
        ]);
        return false;
    }
}

/**
 * Fetch and decode a JSON document from an already-validated feed URL.
 */
function moduleExtensionFetchJson(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => kernelUpdatesTimeoutSeconds(),
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: " . kernelUpdatesUserAgent() . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $m) === 1) {
            $status = (int) $m[1];
            break;
        }
    }
    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'Feed request failed (HTTP ' . $status . ')', 'status' => $status];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Feed is not valid JSON', 'status' => $status];
    }

    return ['ok' => true, 'data' => $decoded, 'status' => $status];
}

/**
 * Pick the newest version entry the caller may install.
 *
 * Only entries that carry a sha256 digest are eligible, and only versions that
 * satisfy declared `requires_kernel` are eligible - an update that cannot run on
 * this kernel is not an available update.
 *
 * @param array<int,mixed> $versions
 * @return array<string,mixed>|null
 */
function moduleExtensionSelectVersion(array $versions, string $channel): ?array
{
    $kernelVersion = \Ikabud\Kernel\App::KERNEL_VERSION;
    $best = null;

    foreach ($versions as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $version = kernelUpdatesNormalizeVersion((string) ($entry['version'] ?? ''));
        if ($version === '0.0.0') {
            continue;
        }
        $entryChannel = trim((string) ($entry['channel'] ?? 'stable')) ?: 'stable';
        if ($channel !== '' && $entryChannel !== $channel) {
            continue;
        }
        $digest = strtolower(trim((string) ($entry['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            continue;
        }
        $requiresKernel = trim((string) ($entry['requires_kernel'] ?? ''));
        if ($requiresKernel !== '') {
            // A version that declares a kernel constraint must be evaluated against
            // THIS kernel. If the evaluator is unavailable we cannot prove the
            // update runs here, so the entry is not eligible - refusing an update
            // is recoverable, installing an incompatible one is not.
            if (!function_exists('kernelSemverRangeSatisfies')
                || !kernelSemverRangeSatisfies($kernelVersion, $requiresKernel)) {
                continue;
            }
        }
        if ($best !== null && version_compare($version, (string) $best['version'], '<=')) {
            continue;
        }
        $entry['version'] = $version;
        $entry['sha256'] = $digest;
        $entry['channel'] = $entryChannel;
        $best = $entry;
    }

    return $best;
}

/**
 * Refresh one extension's update state from its creator's feed.
 *
 * Returns ['ok' => true] plus the fresh state on success, or an error result.
 * The cached `auto_update` preference and `last_applied` record survive a refresh.
 *
 * @param array<string,mixed> $options 'force' (bool) bypasses the staleness guard
 * @return array<string,mixed>
 */
function moduleExtensionUpdateCheck(string $moduleId, array $options = []): array
{
    $manifest = function_exists('loadModuleManifestForId') ? loadModuleManifestForId($moduleId) : null;
    if (!is_array($manifest)) {
        return ['ok' => false, 'error_code' => 'module_not_found', 'error' => 'Module "' . $moduleId . '" is not installed.'];
    }

    $previous = moduleExtensionUpdateState($moduleId);
    $feedUrl = moduleExtensionUpdateFeedUrl($manifest);
    if ($feedUrl === '') {
        return [
            'ok' => false,
            'error_code' => 'no_update_feed',
            'error' => 'Module "' . $moduleId . '" does not declare a usable https update_url.',
        ];
    }

    if (empty($options['force']) && !empty($previous['checked_at'])) {
        $checkedAt = strtotime($previous['checked_at']);
        if ($checkedAt !== false && (time() - $checkedAt) < kernelUpdatesAutoCheckIntervalMinutes() * 60) {
            return ['ok' => true, 'cached' => true] + $previous;
        }
    }

    $installedVersion = kernelUpdatesNormalizeVersion((string) ($manifest['version'] ?? '0.0.0'));
    $channel = trim((string) ($manifest['update_channel'] ?? '')) ?: trim((string) (kernelUpdatesConfig()['channel'] ?? 'stable'));
    $response = moduleExtensionFetchJson($feedUrl);

    $state = $previous;
    $state['module_id'] = $moduleId;
    $state['feed_url'] = $feedUrl;
    $state['installed_version'] = $installedVersion;
    $state['channel'] = $channel;
    $state['checked_at'] = gmdate('c');

    if (empty($response['ok'])) {
        $state['error'] = (string) ($response['error'] ?? 'Feed request failed');
        $state['update_available'] = false;
        moduleExtensionStoreUpdateState($moduleId, $state);
        return ['ok' => false, 'error_code' => 'feed_unreachable', 'error' => $state['error']] + $state;
    }

    $feed = $response['data'];
    $feedId = trim((string) ($feed['id'] ?? ''));
    if ($feedId !== '' && $feedId !== $moduleId) {
        $state['error'] = 'Feed is for "' . $feedId . '", not "' . $moduleId . '".';
        $state['update_available'] = false;
        moduleExtensionStoreUpdateState($moduleId, $state);
        return ['ok' => false, 'error_code' => 'feed_identity_mismatch', 'error' => $state['error']] + $state;
    }

    $versions = is_array($feed['versions'] ?? null) ? array_values($feed['versions']) : [];
    $latest = moduleExtensionSelectVersion($versions, $channel);

    $state['error'] = '';
    if ($latest === null) {
        $state['latest_version'] = $installedVersion;
        $state['update_available'] = false;
        $state['package_url'] = '';
        $state['sha256'] = '';
        $state['requires_kernel'] = '';
        $state['notes'] = '';
        moduleExtensionStoreUpdateState($moduleId, $state);
        return ['ok' => true] + $state;
    }

    $state['latest_version'] = (string) $latest['version'];
    $state['package_url'] = (string) ($latest['package_url'] ?? '');
    $state['sha256'] = (string) $latest['sha256'];
    $state['requires_kernel'] = trim((string) ($latest['requires_kernel'] ?? ''));
    $state['notes'] = trim((string) ($latest['notes'] ?? ''));
    $state['update_available'] = version_compare((string) $latest['version'], $installedVersion, '>');

    if ($state['update_available'] && moduleExtensionUpdatePackageUrl($state['package_url']) === '') {
        // A version we cannot download is not an available update.
        $state['update_available'] = false;
        $state['error'] = 'Feed advertised ' . $state['latest_version'] . ' with an unusable package_url.';
    }

    moduleExtensionStoreUpdateState($moduleId, $state);
    return ['ok' => true] + $state;
}

/**
 * Validate a package URL from a feed: https only, same private-host refusals as
 * the feed URL, no credentials in the URL.
 */
function moduleExtensionUpdatePackageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || strpbrk($url, "\r\n") !== false) {
        return '';
    }
    if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
        return '';
    }
    $probe = ['update_url' => $url];
    return moduleExtensionUpdateFeedUrl($probe);
}

/**
 * Refresh every installed extension that declares a feed.
 *
 * @param array<string,mixed> $options 'force' (bool)
 * @return array<string,array<string,mixed>> keyed by module id
 */
function moduleExtensionCheckAll(array $options = []): array
{
    $results = [];
    if (!function_exists('discoverModules')) {
        return $results;
    }
    foreach (discoverModules() as $moduleId => $manifest) {
        if (!is_array($manifest) || moduleExtensionUpdateFeedUrl($manifest) === '') {
            continue;
        }
        $results[(string) $moduleId] = moduleExtensionUpdateCheck((string) $moduleId, $options);
    }
    return $results;
}

/**
 * Cached update state for every installed extension that has one. No network.
 *
 * @return array<string,array<string,mixed>>
 */
function moduleExtensionUpdateStates(): array
{
    $states = [];
    if (!function_exists('discoverModules')) {
        return $states;
    }
    foreach (discoverModules() as $moduleId => $manifest) {
        if (!is_array($manifest) || moduleExtensionUpdateFeedUrl($manifest) === '') {
            continue;
        }
        $states[(string) $moduleId] = moduleExtensionUpdateState((string) $moduleId);
    }
    return $states;
}

/**
 * Extensions with a cached available update. No network.
 *
 * @return array<string,array<string,mixed>>
 */
function moduleExtensionUpdatesAvailable(): array
{
    return array_filter(
        moduleExtensionUpdateStates(),
        static fn(array $state): bool => !empty($state['update_available']) && (string) ($state['sha256'] ?? '') !== ''
    );
}

/**
 * Whether this extension opted in to automatic updates. Default OFF: an update
 * the operator did not ask for is a change they did not make.
 */
function moduleExtensionAutoUpdateEnabled(string $moduleId): bool
{
    return moduleExtensionUpdateState($moduleId)['auto_update'] === true;
}

/**
 * Record the operator's auto-update choice for one extension.
 *
 * Refuses a module that declares no usable feed: there is nothing to update
 * from, and recording an opt-in would show an operator a promise the updater
 * cannot keep.
 */
function moduleExtensionSetAutoUpdate(string $moduleId, bool $enabled): bool
{
    $state = moduleExtensionUpdateState($moduleId);
    if ($state['feed_url'] === '') {
        $manifest = function_exists('loadModuleManifestForId') ? loadModuleManifestForId($moduleId) : null;
        $state['feed_url'] = is_array($manifest) ? moduleExtensionUpdateFeedUrl($manifest) : '';
    }
    if ($state['feed_url'] === '') {
        return false;
    }
    $state['module_id'] = $moduleId;
    $state['auto_update'] = $enabled;

    return moduleExtensionStoreUpdateState($moduleId, $state);
}

/**
 * Download the advertised package and prove its digest before returning a path.
 *
 * Refuses: no digest, a digest mismatch, a non-https or private-host URL, a body
 * over the size cap. The caller owns the returned temp file.
 *
 * @param array<string,mixed> $state state row carrying package_url + sha256
 * @return array{ok:bool,path?:string,bytes?:int,error?:string,error_code?:string}
 */
function moduleExtensionDownloadPackage(array $state): array
{
    $url = moduleExtensionUpdatePackageUrl((string) ($state['package_url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'error_code' => 'package_url_unusable', 'error' => 'The feed advertised no usable https package_url.'];
    }
    $expected = strtolower(trim((string) ($state['sha256'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
        return ['ok' => false, 'error_code' => 'package_digest_missing', 'error' => 'The feed advertised no sha256 digest for this version.'];
    }

    $maxBytes = 50 * 1024 * 1024;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => max(10, kernelUpdatesTimeoutSeconds()),
            'ignore_errors' => true,
            'header' => "Accept: application/zip\r\nUser-Agent: " . kernelUpdatesUserAgent() . "\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $source = @fopen($url, 'rb', false, $context);
    if ($source === false) {
        return ['ok' => false, 'error_code' => 'package_download_failed', 'error' => 'Could not open ' . $url];
    }

    $temp = tempnam(sys_get_temp_dir(), 'extpkg_');
    if ($temp === false) {
        fclose($source);
        return ['ok' => false, 'error_code' => 'package_download_failed', 'error' => 'Could not allocate a temp file.'];
    }
    $target = @fopen($temp, 'wb');
    if ($target === false) {
        fclose($source);
        @unlink($temp);
        return ['ok' => false, 'error_code' => 'package_download_failed', 'error' => 'Could not write the downloaded package.'];
    }

    $bytes = (int) stream_copy_to_stream($source, $target, $maxBytes + 1);
    fclose($source);
    fclose($target);

    if ($bytes <= 0 || $bytes > $maxBytes) {
        @unlink($temp);
        return ['ok' => false, 'error_code' => 'package_download_failed', 'error' => 'Package was empty or exceeded the 50 MB limit.'];
    }

    $actual = hash_file('sha256', $temp);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        @unlink($temp);
        return ['ok' => false, 'error_code' => 'package_digest_mismatch', 'error' => 'Downloaded package does not match the advertised sha256.'];
    }

    return ['ok' => true, 'path' => $temp, 'bytes' => $bytes];
}

/**
 * Download and apply one extension's advertised update.
 *
 * This is the single place automatic updates happen. The package still passes
 * every gate updateModuleFromZip() enforces; nothing here may bypass them.
 *
 * @param array<string,mixed> $options 'require_certification' (bool, default false)
 * @return array<string,mixed>
 */
function moduleExtensionApplyUpdate(string $moduleId, array $options = []): array
{
    $state = moduleExtensionUpdateState($moduleId);
    if (empty($state['update_available'])) {
        return ['ok' => false, 'error_code' => 'no_update_available', 'error' => 'No cached update is available for "' . $moduleId . '".'];
    }
    if (!function_exists('updateModuleFromZip')) {
        return ['ok' => false, 'error_code' => 'update_primitive_missing', 'error' => 'updateModuleFromZip() is not available in this install.'];
    }

    $download = moduleExtensionDownloadPackage($state);
    if (empty($download['ok'])) {
        return $download;
    }
    $path = (string) $download['path'];

    try {
        $result = updateModuleFromZip($path, [
            'require_certification' => !empty($options['require_certification']),
        ]);
    } finally {
        @unlink($path);
    }

    if (empty($result['ok'])) {
        $state['error'] = (string) ($result['error'] ?? 'Update failed');
        moduleExtensionStoreUpdateState($moduleId, $state);
        return $result + ['ok' => false];
    }

    $state['installed_version'] = (string) ($result['to_version'] ?? $state['latest_version']);
    $state['update_available'] = false;
    $state['error'] = '';
    $state['last_applied'] = [
        'from' => (string) ($result['from_version'] ?? ''),
        'to' => (string) ($result['to_version'] ?? ''),
        'at' => gmdate('c'),
    ];
    moduleExtensionStoreUpdateState($moduleId, $state);

    kernelUpdatesAudit('extension.update.applied', [
        'module_id' => $moduleId,
        'from_version' => (string) ($result['from_version'] ?? ''),
        'to_version' => (string) ($result['to_version'] ?? ''),
    ]);

    return $result;
}

/**
 * Apply every cached update whose extension opted in. Used by the cron script.
 *
 * @return array{applied:array<int,string>,failed:array<string,string>,skipped:array<int,string>}
 */
function moduleExtensionAutoUpdateAll(): array
{
    $applied = [];
    $failed = [];
    $skipped = [];

    foreach (moduleExtensionUpdatesAvailable() as $moduleId => $state) {
        if (empty($state['auto_update'])) {
            $skipped[] = (string) $moduleId;
            continue;
        }
        $result = moduleExtensionApplyUpdate((string) $moduleId, ['require_certification' => false]);
        if (!empty($result['ok'])) {
            $applied[] = (string) $moduleId;
        } else {
            $failed[(string) $moduleId] = (string) ($result['error'] ?? 'update failed');
        }
    }

    return ['applied' => $applied, 'failed' => $failed, 'skipped' => $skipped];
}