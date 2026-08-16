<?php
/**
 * PHP built-in server router.
 *
 * The built-in server (`php -S`) serves an existing file/directory directly and
 * returns 404 for anything else — it does NOT fall through to index.php. That
 * breaks `/admin/*` module routes when a `public/admin/` directory exists (e.g.
 * `/admin/ticketing/settings` maps to a non-existent `public/admin/ticketing/`
 * path and the server 404s before the app router runs). This router sends
 * every non-file request to `public/index.php` so the application's route table
 * handles it.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    // Serve real static files (assets, uploads, etc.) directly.
    return false;
}

require __DIR__ . '/index.php';
