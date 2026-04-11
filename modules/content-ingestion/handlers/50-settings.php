<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Content Ingestion — Settings Handlers
//
//   GET  /cms/admin/bridge/settings         → wpBridgeAdminSettings  (UI page)
//   POST /cms/admin/bridge/settings         → wpBridgeAdminSettings  (form save)
//   POST /api/v1/bridge/token/rotate        → wpBridgeApiTokenRotate (token rotation)
//   GET  /api/v1/bridge/health              → wpBridgeApiHealth      (connection health)
//   GET  /api/v1/bridge/companion/download  → wpBridgeApiCompanionDownload
// ─────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────
// Settings helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Ensure the bridge API token is set, generating one if missing.
 * Returns the current (or newly generated) token.
 */
function wpBridgeEnsureApiToken(): string
{
    $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $token    = (string)($settings['bridge_api_token'] ?? '');

    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $settings['bridge_api_token'] = $token;
        saveModuleSettings('content-ingestion', $settings);
    }

    return $token;
}

/**
 * Return the bridge_api_token from settings (empty string if unset).
 */
function wpBridgeGetApiToken(): string
{
    $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    return (string)($settings['bridge_api_token'] ?? '');
}

/**
 * Return the source site URL from settings (empty string if unset).
 */
function wpBridgeGetSourceUrl(): string
{
    $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    return (string)($settings['source_site_url'] ?? '');
}

// ─────────────────────────────────────────────────────────────────────────
// GET/POST /cms/admin/bridge/settings
// ─────────────────────────────────────────────────────────────────────────

/**
 * Bridge settings page — renders configuration form (GET) or saves (POST).
 *
 * User-editable fields (from module.json settings_fields):
 *   bridge_enabled  — master on/off switch
 *   source_site_url — WordPress base URL
 *   source_name     — human-readable label for this source
 *
 * bridge_api_token is auto-managed (generated on first access, rotated via
 * the /api/v1/bridge/token/rotate endpoint). It is never submitted via the form.
 */
function wpBridgeAdminSettings(array $params = []): void
{
    $user = cmsRequireCap('import_export.manage');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        app()->csrfEnforce();

        $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];

        // bridge_enabled — checkbox
        $settings['bridge_enabled'] = !empty($_POST['bridge_enabled']);

        // source_site_url — sanitize to a valid URL or empty
        $rawUrl = trim((string)($_POST['source_site_url'] ?? ''));
        if ($rawUrl !== '') {
            // Strip trailing slash, enforce http/https scheme
            $rawUrl = rtrim($rawUrl, '/');
            if (!preg_match('#^https?://#i', $rawUrl)) {
                $rawUrl = 'https://' . $rawUrl;
            }
        }
        $settings['source_site_url'] = $rawUrl;

        // source_name — plain text
        $settings['source_name'] = trim((string)($_POST['source_name'] ?? ''));

        // Ensure bridge_state stays valid (never whiteout from POST)
        if (empty($settings['bridge_state'])) {
            $settings['bridge_state'] = 'active';
        }

        // Auto-generate token if none exists yet
        if (empty($settings['bridge_api_token'])) {
            $settings['bridge_api_token'] = bin2hex(random_bytes(32));
        }

        saveModuleSettings('content-ingestion', $settings);

        write_log('Bridge settings saved', 'info', ['source' => 'content-ingestion', 'user_id' => (int)($user['id'] ?? 0)]);

        $_SESSION['bridge_message'] = ['type' => 'success', 'text' => 'Bridge settings saved.'];
        header('Location: /cms/admin/bridge/settings');
        exit;
    }

    // GET — ensure token exists before rendering (so the "copy token" section works immediately)
    wpBridgeEnsureApiToken();

    $settings      = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $token         = (string)($settings['bridge_api_token'] ?? '');
    $tokenMasked   = wpBridgeMaskToken($token);
    $baseUrl       = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $ingestUrl     = $baseUrl . '/api/v1/bridge/ingest';

    // Flash message
    $message = $_SESSION['bridge_message'] ?? null;
    unset($_SESSION['bridge_message']);

    echo cmsRender('modules/content-ingestion/admin/bridge-settings.disyl', array_merge(
        cmsAdminContext($user, 'wordpress_bridge', [
            ['label' => 'Content Ingestion', 'url' => $baseUrl . '/cms/admin/bridge'],
            ['label' => 'Settings', 'url' => ''],
        ]),
        [
            'page_title'        => 'Content Ingestion — Settings',
            'bridge_enabled'    => !empty($settings['bridge_enabled']),
            'source_site_url'   => (string)($settings['source_site_url'] ?? ''),
            'source_name'       => (string)($settings['source_name'] ?? ''),
            'token_masked'      => $tokenMasked,
            'token_set'         => $token !== '',
            'ingest_url'        => $ingestUrl,
            'api_base'          => $baseUrl . '/api/v1',
            'message'           => $message,
        ]
    ));
}

/**
 * Mask an API token for display — show only the last 6 characters.
 * Returns '(not set)' if empty.
 */
function wpBridgeMaskToken(string $token): string
{
    if ($token === '') {
        return '(not set)';
    }
    $len = strlen($token);
    if ($len <= 6) {
        return str_repeat('•', $len);
    }
    return str_repeat('•', $len - 6) . substr($token, -6);
}

// ─────────────────────────────────────────────────────────────────────────
// POST /api/v1/bridge/token/rotate
// ─────────────────────────────────────────────────────────────────────────

/**
 * Generates a new bridge API token and saves it, invalidating the old one.
 *
 * After rotation, any WP companion plugin configured with the old token
 * must be reconfigured.
 */
function wpBridgeApiTokenRotate(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');
    app()->csrfEnforce();

    $newToken = bin2hex(random_bytes(32));

    $settings = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $settings['bridge_api_token'] = $newToken;
    saveModuleSettings('content-ingestion', $settings);

    write_log('Bridge API token rotated', 'info', ['source' => 'content-ingestion']);

    // Return masked token in the response so the UI can update display
    echo json_encode([
        'ok'           => true,
        'token_masked' => wpBridgeMaskToken($newToken),
        'message'      => 'Token rotated. Update your WP companion plugin configuration.',
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET /api/v1/bridge/health
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns a snapshot of the bridge connection status for the current tenant.
 *
 * Accessible to admins. Does not expose the raw token.
 */
function wpBridgeApiHealth(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('import_export.manage');

    $settings       = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $bridgeState    = wpBridgeGetState();
    $bridgeEnabled  = !empty($settings['bridge_enabled']);
    $sourceSiteUrl  = (string)($settings['source_site_url'] ?? '');
    $sourceName     = (string)($settings['source_name'] ?? '');
    $tokenConfigured = !empty($settings['bridge_api_token']);

    // Last ingestion activity
    $lastIngestedAt = null;
    $lastOutcome    = null;
    try {
        $db   = wpBridgeDb();
        $stmt = $db->prepare(
            "SELECT status, created_at FROM bridge_ingestion_log ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lastIngestedAt = $row['created_at'];
            $lastOutcome    = $row['status'];
        }
    } catch (\Throwable) {
        // Non-fatal — bridge_ingestion_log may not exist in test environments
    }

    echo json_encode([
        'ok'              => true,
        'bridge_enabled'  => $bridgeEnabled,
        'bridge_state'    => $bridgeState,
        'source_site_url' => $sourceSiteUrl,
        'source_name'     => $sourceName,
        'token_configured'=> $tokenConfigured,
        'last_ingested_at'=> $lastIngestedAt,
        'last_outcome'    => $lastOutcome,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET /api/v1/bridge/companion/download
// ─────────────────────────────────────────────────────────────────────────

/**
 * Generates and serves a downloadable WordPress companion plugin PHP file.
 *
 * The file has the tenant's ingest URL and current API token pre-embedded,
 * so the recipient can drop it directly into wp-content/plugins/ without
 * any manual configuration.
 *
 * Security: tokens are embedded only in a server-generated download that
 * already requires admin authentication. The file itself performs no
 * authentication — the token in the file is the credential.
 */
function wpBridgeApiCompanionDownload(array $params = []): void
{
    cmsRequireCap('import_export.manage');

    $settings   = function_exists('getModuleSettings') ? getModuleSettings('content-ingestion') : [];
    $token      = (string)($settings['bridge_api_token'] ?? '');

    if ($token === '') {
        // Auto-generate before download so the file is immediately usable
        $token = wpBridgeEnsureApiToken();
    }

    $baseUrl   = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $ingestUrl = $baseUrl . '/api/v1/bridge/ingest';
    $sourceName = (string)($settings['source_name'] ?? 'wordpress');

    // Sanitize source_name to a safe plugin slug
    $pluginSlug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($sourceName));
    $pluginSlug = trim(preg_replace('/-+/', '-', $pluginSlug), '-');
    if ($pluginSlug === '') {
        $pluginSlug = 'appOS-bridge';
    }

    $filename   = 'applicationos-bridge-connector.php';
    $pluginBody = wpBridgeGenerateCompanionPlugin($ingestUrl, $token, $pluginSlug);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pluginBody));
    header('Cache-Control: no-store');

    echo $pluginBody;
    exit;
}

/**
 * Generate the PHP source of the WP companion plugin with the given endpoint + token.
 *
 * The generated plugin:
 * - Hooks into save_post / delete_post / add_attachment / delete_attachment
 * - POSTs a normalized envelope to the ApplicationOS bridge ingest endpoint
 * - Authenticates via Authorization: Bearer header
 * - Uses wp_remote_post (non-blocking option available via constant)
 *
 * @param string $ingestUrl  Full URL of the ApplicationOS /api/v1/bridge/ingest endpoint
 * @param string $token      Plain-text API token to embed
 * @param string $sourceName Slug used as the `source` field in payloads
 */
function wpBridgeGenerateCompanionPlugin(string $ingestUrl, string $token, string $sourceName): string
{
    // Escape values for safe embedding inside a PHP string literal
    $safeUrl    = addslashes($ingestUrl);
    $safeToken  = addslashes($token);
    $safeSrc    = addslashes($sourceName);

    return <<<PHP
<?php
/**
 * Plugin Name: ApplicationOS Bridge Connector
 * Description: Pushes WordPress content changes to ApplicationOS via the Bridge API.
 *              Generated automatically — do not edit the APPLOS_* constants manually.
 *              Re-download from ApplicationOS Admin → Content Ingestion → Settings if token changes.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

// ── Configuration (auto-generated — do not edit) ─────────────────────────
define('APPLOS_BRIDGE_INGEST_URL', '{$safeUrl}');
define('APPLOS_BRIDGE_TOKEN',      '{$safeToken}');
define('APPLOS_BRIDGE_SOURCE',     '{$safeSrc}');
// Set to true to fire ingestion in background (non-blocking HTTP)
define('APPLOS_BRIDGE_NONBLOCKING', false);

// ── Hooks ─────────────────────────────────────────────────────────────────

add_action('save_post',         'applos_bridge_on_save_post', 10, 3);
add_action('delete_post',       'applos_bridge_on_delete_post', 10, 1);
add_action('add_attachment',    'applos_bridge_on_attachment', 10, 1);
add_action('edit_attachment',   'applos_bridge_on_attachment', 10, 1);
add_action('delete_attachment', 'applos_bridge_on_attachment', 10, 1);

function applos_bridge_on_save_post(int \$post_id, WP_Post \$post, bool \$update): void
{
    // Skip revisions, auto-drafts, and our own syncs
    if (wp_is_post_revision(\$post_id) || wp_is_post_autosave(\$post_id)) {
        return;
    }
    if (defined('APPLOS_BRIDGE_SYNCING') && APPLOS_BRIDGE_SYNCING) {
        return;
    }

    \$supported = ['post', 'page'];
    if (!in_array(\$post->post_type, \$supported, true)) {
        return;
    }

    \$categories = [];
    \$tags       = [];

    foreach (wp_get_post_categories(\$post_id, ['fields' => 'all']) as \$cat) {
        \$categories[] = ['id' => (string)\$cat->term_id, 'name' => \$cat->name, 'slug' => \$cat->slug];
    }
    foreach (wp_get_post_tags(\$post_id, ['fields' => 'all']) as \$tag) {
        \$tags[] = ['id' => (string)\$tag->term_id, 'name' => \$tag->name, 'slug' => \$tag->slug];
    }

    \$envelope = [
        'event'             => 'cms.migration.content.upserted',
        'source'            => APPLOS_BRIDGE_SOURCE,
        'external_id'       => (string)\$post_id,
        'external_modified' => \$post->post_modified_gmt,
        'payload'           => [
            'title'      => \$post->post_title,
            'slug'       => \$post->post_name,
            'body'       => \$post->post_content,
            'excerpt'    => \$post->post_excerpt,
            'type'       => \$post->post_type,
            'status'     => \$post->post_status,
            'categories' => \$categories,
            'tags'       => \$tags,
            'author_external_id' => (string)\$post->post_author,
        ],
    ];

    applos_bridge_send(\$envelope);
}

function applos_bridge_on_delete_post(int \$post_id): void
{
    \$post = get_post(\$post_id);
    if (!\$post) {
        return;
    }
    // Emit with status=trash so the bridge can mark the item retired
    \$post->post_status       = 'trash';
    \$post->post_modified_gmt = gmdate('Y-m-d H:i:s');
    applos_bridge_on_save_post(\$post_id, \$post, true);
}

function applos_bridge_on_attachment(int \$post_id): void
{
    // Attachments sync is handled by the bridge media pipeline; just ping ingest
    \$post = get_post(\$post_id);
    if (!\$post) {
        return;
    }
    applos_bridge_on_save_post(\$post_id, \$post, true);
}

/**
 * Send an envelope to the ApplicationOS bridge ingest endpoint.
 */
function applos_bridge_send(array \$envelope): void
{
    \$args = [
        'method'    => 'POST',
        'headers'   => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . APPLOS_BRIDGE_TOKEN,
        ],
        'body'      => wp_json_encode(\$envelope),
        'timeout'   => APPLOS_BRIDGE_NONBLOCKING ? 0.01 : 15,
        'blocking'  => !APPLOS_BRIDGE_NONBLOCKING,
        'sslverify' => true,
    ];

    \$response = wp_remote_post(APPLOS_BRIDGE_INGEST_URL, \$args);

    if (!APPLOS_BRIDGE_NONBLOCKING && is_wp_error(\$response)) {
        error_log('ApplicationOS Bridge error: ' . \$response->get_error_message());
    }
}
PHP;
}
