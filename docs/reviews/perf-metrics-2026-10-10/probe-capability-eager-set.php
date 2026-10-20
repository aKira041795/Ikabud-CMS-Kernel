<?php

declare(strict_types=1);

/**
 * Probe: is there a minimal eager set for a module's capabilities?
 *
 * probe-module-tree.php shows `cms` dragging in 38 files / 1224 KB from a
 * 1857 B helpers.php. Registration only needs one thing out of that tree: the
 * callables returned by cms_capability_handlers(), because
 * validateModuleCapabilities() resolves them with is_callable() and a plain
 * function only exists once its file is included.
 *
 * So the question that decides whether any of the tree can be deferred is
 * narrow and answerable exactly: WHICH FILES define the handler functions, and
 * how big are they together?
 *
 * - if most handlers live in a couple of files, a small eager set exists and the
 *   rest of the tree can be deferred to dispatch time;
 * - if they are spread across the tree, the eager set is the tree and there is
 *   nothing to win.
 *
 * ReflectionFunction::getFileName() answers it directly, without guessing from
 * names.
 *
 *   php probe-capability-eager-set.php cms
 *   php probe-capability-eager-set.php ecommerce
 *
 * Read-only.
 */

require __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';

$moduleId = (string)($argv[1] ?? '');
if ($moduleId === '') {
    fwrite(STDERR, "usage: php probe-capability-eager-set.php <module-id>\n");
    exit(2);
}

$target = null;
foreach (getEnabledModules() as $module) {
    if ((string)($module['id'] ?? '') === $moduleId) {
        $target = $module;
        break;
    }
}

if ($target === null) {
    fwrite(STDERR, "module not found or not enabled: {$moduleId}\n");
    exit(2);
}

$root = (string)($target['_path'] ?? '');
$before = get_included_files();
loadModuleHelpers($target);
$treeFiles = array_diff(get_included_files(), $before);
$treeBytes = 0;
foreach ($treeFiles as $file) {
    $treeBytes += (int)@filesize($file);
}

$exportFn = preg_replace('/[^a-z0-9]+/i', '_', $moduleId) . '_capability_handlers';
if (!function_exists($exportFn)) {
    fwrite(STDERR, "no handler export function: {$exportFn}()\n");
    exit(1);
}

$map = $exportFn();
if (!is_array($map)) {
    fwrite(STDERR, "{$exportFn}() did not return an array\n");
    exit(1);
}

$byFile = [];
$unresolved = [];
foreach ($map as $capability => $callable) {
    try {
        if ($callable instanceof Closure || is_string($callable)) {
            $ref = new ReflectionFunction($callable);
        } elseif (is_array($callable) && count($callable) === 2) {
            $ref = new ReflectionMethod($callable[0], $callable[1]);
        } else {
            $unresolved[] = (string)$capability;
            continue;
        }
    } catch (Throwable $e) {
        $unresolved[] = (string)$capability;
        continue;
    }

    $file = (string)$ref->getFileName();
    if ($file === '' || !str_ends_with($file, '.php')) {
        // internal function or eval'd closure: no file to load
        $unresolved[] = (string)$capability;
        continue;
    }
    $byFile[$file] = ($byFile[$file] ?? 0) + 1;
}

$neededBytes = 0;
foreach (array_keys($byFile) as $file) {
    $neededBytes += (int)@filesize($file);
}
arsort($byFile);

printf("module:                 %s\n", $moduleId);
printf("capabilities in map:    %d\n", count($map));
printf("tree at registration:   %d files, %.1f KB\n", count($treeFiles), $treeBytes / 1024);
printf("files defining handlers:%d\n", count($byFile));
printf("their combined size:    %.1f KB  (%.1f%% of the tree)\n", $neededBytes / 1024, $treeBytes > 0 ? 100 * $neededBytes / $treeBytes : 0);
if ($unresolved !== []) {
    printf("unresolved callables:   %d (%s)\n", count($unresolved), implode(', ', array_slice($unresolved, 0, 4)));
}

echo "\n  handlers  size(KB)  file\n";
foreach ($byFile as $file => $count) {
    printf("  %7d  %8.1f  %s\n", $count, (int)@filesize($file) / 1024, str_replace(dirname($root) . '/', '', $file));
}

// Which tree files are NOT needed to define a handler - the deferrable set.
$deferrable = 0;
foreach ($treeFiles as $file) {
    if (!isset($byFile[$file])) {
        $deferrable += (int)@filesize($file);
    }
}
printf("\ndeferrable bytes:       %.1f KB (%.1f%% of the tree)\n", $deferrable / 1024, $treeBytes > 0 ? 100 * $deferrable / $treeBytes : 0);
