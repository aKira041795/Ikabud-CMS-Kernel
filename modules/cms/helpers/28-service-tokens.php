<?php

declare(strict_types=1);

/**
 * CMS Service Tokens (HARPP CMS Assistant).
 *
 * Scoped Bearer auth for headless agents. Each token maps to a virtual CMS editor whose
 * capabilities come from an allowlist stored on the token. Publish/schedule capabilities are
 * intentionally never included, so an agent can create/edit content and builder pages as DRAFTS
 * only; a human publishes through the normal CMS workflow.
 *
 * Raw tokens are shown exactly once at mint time; only sha256 hashes are stored.
 */

/**
 * The default capability allowlist for a new CMS Assistant service token.
 * Deliberately excludes: content.publish, content.schedule, builder.publish, settings.*,
 * users.*, media.delete, import_export.*, content_types.*, customizer.*, menus.*, redirects.*.
 */
function cmsServiceTokenDefaultCaps(): array
{
    return [
        'dashboard.view',
        'content.list', 'content.read', 'content.create', 'content.edit_any', 'content.autosave',
        'builder.access', 'builder.save', 'builder.preview', 'builder.revisions', 'builder.revision_restore',
        'media.list', 'media.upload', 'media.edit',
        'workflow.view', 'workflow.transition',
        'ai.summary', 'ai.seo', 'ai.refine',
        'categories.list', 'tags.list', 'revisions.list', 'revisions.view',
    ];
}

/**
 * Mint a new service token. Returns the RAW token (shown once) via a by-ref param.
 * Gated to CMS administrators.
 *
 * @return array{ok:bool, token?:string, id?:int, error?:string}
 */
function cmsMintServiceToken(array $actor, string $name, array $caps = []): array
{
    if (!$actor || !cmsUserCan($actor, 'settings.manage') || (string)($actor['role'] ?? '') !== 'administrator') {
        return ['ok' => false, 'error' => 'Administrator access is required to mint service tokens.'];
    }
    $name = trim(strip_tags($name));
    if ($name === '' || strlen($name) > 191) {
        return ['ok' => false, 'error' => 'A name between 1 and 191 characters is required.'];
    }
    $caps = array_values(array_unique(array_map('strval', $caps ?: cmsServiceTokenDefaultCaps())));
    if ($caps === []) {
        return ['ok' => false, 'error' => 'At least one capability is required.'];
    }
    // Hard deny-list: these can never be granted to a service token.
    $deny = [
        'content.publish', 'content.schedule', 'builder.publish',
        'settings.manage', 'users.manage', 'media.delete', 'menus.manage',
        'import_export.manage', 'content_types.manage', 'customizer.manage',
        'redirects.create', 'redirects.delete',
    ];
    $caps = array_values(array_filter($caps, static fn (string $c): bool => !in_array($c, $deny, true)));

    $raw = 'cms_' . bin2hex(random_bytes(24));
    $hash = hash('sha256', $raw);
    try {
        $db = cmsDb();
        $stmt = $db->prepare('INSERT INTO cms_service_tokens (name, token_hash, capabilities, role, is_active, created_by, created_at, updated_at) VALUES (:name, :hash, :caps, :role, 1, :by, NOW(), NOW())');
        $stmt->execute([
            ':name' => $name,
            ':hash' => $hash,
            ':caps' => json_encode($caps, JSON_THROW_ON_ERROR),
            ':role' => 'editor',
            ':by'   => (int)($actor['id'] ?? 0),
        ]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        write_log('cms service token mint failed: ' . $e->getMessage(), 'warning', ['actor_id' => (int)($actor['id'] ?? 0)]);
        return ['ok' => false, 'error' => 'Unable to create service token.'];
    }
    return ['ok' => true, 'id' => $id, 'token' => $raw, 'capabilities' => $caps];
}

/**
 * Revoke (deactivate) a service token by id. Gated to CMS administrators.
 */
function cmsRevokeServiceToken(array $actor, int $id): array
{
    if (!$actor || !cmsUserCan($actor, 'settings.manage') || (string)($actor['role'] ?? '') !== 'administrator') {
        return ['ok' => false, 'error' => 'Administrator access is required to revoke service tokens.'];
    }
    try {
        $stmt = cmsDb()->prepare('UPDATE cms_service_tokens SET is_active = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return ['ok' => true, 'id' => $id, 'revoked' => $stmt->rowCount() > 0];
    } catch (Throwable $e) {
        write_log('cms service token revoke failed: ' . $e->getMessage(), 'warning', ['token_id' => $id]);
        return ['ok' => false, 'error' => 'Unable to revoke service token.'];
    }
}

/**
 * List service tokens (hashes only, never the raw token). Gated to CMS administrators.
 */
function cmsListServiceTokens(array $actor): array
{
    if (!$actor || !cmsUserCan($actor, 'settings.manage') || (string)($actor['role'] ?? '') !== 'administrator') {
        return ['ok' => false, 'error' => 'Administrator access is required.', 'tokens' => []];
    }
    try {
        $rows = cmsDb()->query('SELECT id, name, role, is_active, capabilities, last_used_at, created_at FROM cms_service_tokens ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['capabilities'] = json_decode((string)($r['capabilities'] ?? '[]'), true) ?: [];
        }
        return ['ok' => true, 'tokens' => $rows];
    } catch (Throwable $e) {
        write_log('cms service token list failed: ' . $e->getMessage(), 'warning');
        return ['ok' => false, 'error' => 'Unable to list service tokens.', 'tokens' => []];
    }
}

/**
 * Read the raw Authorization header robustly across shared-hosting setups.
 * Apache/LiteSpeed populate $_SERVER variants, but some PHP-FPM/LiteSpeed
 * builds only expose it via getallheaders()/apache_request_headers().
 */
function cmsBearerHeader(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header)) {
        $header = '';
    }
    if ($header === '' && function_exists('getallheaders')) {
        $all = @getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) {
                    $header = (string)$v;
                    break;
                }
            }
        }
    }
    if ($header === '' && function_exists('apache_request_headers')) {
        $all = @apache_request_headers();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) {
                    $header = (string)$v;
                    break;
                }
            }
        }
    }
    return $header;
}

/**
 * Resolve an Authorization: Bearer token to a virtual CMS service user, or null.
 * Only runs when a Bearer header is present; the raw token is never logged.
 */
function cmsServiceUserFromBearer(): ?array
{
    $header = cmsBearerHeader();
    if (stripos($header, 'Bearer ') !== 0) {
        return null;
    }
    // A Bearer header reached PHP. Log presence (never the token) so a live 401
    // is diagnosable: no such line => header stripped upstream (shared host);
    // "lookup miss/error" => token not in the DB this request queries.
    write_log('cms.service_token: bearer present; resolving', 'info');
    $raw = trim(substr($header, 7));
    if ($raw === '' || strlen($raw) > 512) {
        return null;
    }
    $hash = hash('sha256', $raw);
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    try {
        $stmt = cmsDb()->prepare('SELECT id, name, capabilities, role, is_active FROM cms_service_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        write_log('cms.service_token: lookup error (' . get_class($e) . ')', 'error');
        return null;
    }
    if (!is_array($row) || (int)($row['is_active'] ?? 0) !== 1) {
        write_log('cms.service_token: lookup miss (no active row)', 'info');
        $cache = null;
        return null;
    }
    try {
        cmsDb()->prepare('UPDATE cms_service_tokens SET last_used_at = NOW() WHERE id = :id')->execute([':id' => (int)$row['id']]);
    } catch (Throwable $e) {
        // non-fatal
    }
    $caps = json_decode((string)($row['capabilities'] ?? '[]'), true) ?: [];
    $cache = [
        'id' => 0,
        'cms_user_id' => 0,
        'email' => 'assistant@cms.local',
        'full_name' => (string)$row['name'],
        'role' => (string)($row['role'] ?? 'editor'),
        'source' => 'cms',
        'is_service' => true,
        'service_caps' => array_values(array_filter(array_map('strval', is_array($caps) ? $caps : []))),
        'service_token_id' => (int)$row['id'],
    ];
    return $cache;
}
