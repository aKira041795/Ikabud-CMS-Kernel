#!/usr/bin/env php
<?php
/**
 * CMS Scheduled Publishing Cron Script
 *
 * Finds all CMS content with status='scheduled' whose published_at <= NOW()
 * and flips them to status='published'.
 *
 * Usage:
 *   php modules/cms/cli/publish-scheduled.php
 *
 * Crontab (run every 5 minutes):
 *   star/5 * * * * cd /var/www/html/baronbakeshop && php modules/cms/cli/publish-scheduled.php >> storage/logs/cron.log 2>&1
 *   (replace star with asterisk)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// ── Bootstrap the application ────────────────────────────────────────

$basePath = dirname(__DIR__, 3); // modules/cms/cli -> project root
require_once $basePath . '/src/helpers/cli-bootstrap.php';

try {
    // Boot the kernel so DB + modules are available.
    $app = kernelCliBootstrap($basePath);
} catch (Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

// Load CMS module helpers (DB helpers, cache, etc.)
$cmsHelpersFile = $basePath . '/modules/cms/helpers.php';
if (is_file($cmsHelpersFile)) {
    require_once $cmsHelpersFile;
}

try {
    kernelCliRequireFunctions(['cmsDb']);
} catch (Throwable $e) {
    fwrite(STDERR, "CLI preflight failed: {$e->getMessage()}\n");
    exit(1);
}

// ── Run scheduled publishing ─────────────────────────────────────────

$now = date('Y-m-d H:i:s');
echo "[{$now}] CMS scheduled publishing check starting...\n";

try {
    $db = cmsDb();
    $stmt = $db->prepare(
        "SELECT id, title, slug, type, status FROM cms_content
         WHERE status = 'scheduled'
           AND published_at IS NOT NULL
           AND published_at <= NOW()
           AND deleted_at IS NULL"
    );
    $stmt->execute();
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($due)) {
        echo "[{$now}] No scheduled content due for publishing.\n";
        exit(0);
    }

    $published = [];
    foreach ($due as $row) {
        try {
            $db->prepare(
                "UPDATE cms_content SET status = 'published', updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $row['id']]);

            // Invalidate caches if helper is available
            if (function_exists('cmsCacheInvalidateContent')) {
                cmsCacheInvalidateContent($row);
            }

            $published[] = (int)$row['id'];
            echo "  Published: [{$row['id']}] {$row['title']} ({$row['type']})\n";

            // Log the event
            write_log(
                "Cron auto-published content ID {$row['id']}: {$row['title']}",
                'info',
                ['content_id' => $row['id'], 'slug' => $row['slug'], 'type' => $row['type'], 'source' => 'cron']
            );
        } catch (Throwable $e) {
            echo "  FAILED: [{$row['id']}] {$row['title']} — {$e->getMessage()}\n";
            write_log(
                "Cron failed to publish content {$row['id']}: {$e->getMessage()}",
                'error',
                ['content_id' => $row['id'], 'source' => 'cron']
            );
        }
    }

    $count = count($published);
    echo "[{$now}] Done. Published {$count} item(s).\n";
    exit(0);
} catch (Throwable $e) {
    echo "[{$now}] FATAL: {$e->getMessage()}\n";
    write_log("Cron scheduled publishing fatal error: {$e->getMessage()}", 'critical', [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}
