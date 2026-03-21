<?php
http_response_code(404);
exit;
// TEMPORARY: Test artifact cleanup helper. Remove after use.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['token'] ?? '') !== 'cleanup-test-2026') {
    http_response_code(403);
    exit;
}
$dir = dirname(__DIR__) . '/modules/test-widget';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
    echo json_encode(['ok' => true, 'message' => 'cleaned up']);
} else {
    echo json_encode(['ok' => true, 'message' => 'already gone']);
}
