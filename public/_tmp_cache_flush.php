<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

if (!defined('APP_START')) {
	require_once dirname(__DIR__) . '/bootstrap.php';
}

if (is_file(dirname(__DIR__) . '/src/helpers/page-cache.php')) {
	require_once dirname(__DIR__) . '/src/helpers/page-cache.php';
}
if (is_file(dirname(__DIR__) . '/modules/cms/helpers/60-cache.php')) {
	require_once dirname(__DIR__) . '/modules/cms/helpers/60-cache.php';
}

$results = [];
$full = isset($_GET['full']) && (string)$_GET['full'] === '1';

if ($full) {
	$results[] = function_exists('cmsCacheFlushAll') ? ('cmsCacheFlushAll=' . (cmsCacheFlushAll() ? 'ok' : 'fail')) : 'cmsCacheFlushAll=unavailable';
	$results[] = function_exists('pageCacheFlushAll') ? ('pageCacheFlushAll=' . (pageCacheFlushAll() >= 0 ? 'ok' : 'fail')) : 'pageCacheFlushAll=unavailable';
}

$results[] = function_exists('apcu_clear_cache') ? ('apcu_clear_cache=' . (apcu_clear_cache() ? 'ok' : 'fail')) : 'apcu_clear_cache=unavailable';
$results[] = function_exists('opcache_reset') ? ('opcache_reset=' . (opcache_reset() ? 'ok' : 'fail')) : 'opcache_reset=unavailable';

echo implode("\n", $results) . "\n";
