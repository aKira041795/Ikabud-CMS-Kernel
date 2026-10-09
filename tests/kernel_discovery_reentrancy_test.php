<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';

unset($GLOBALS['_kernel_discovered_modules']);
$depth = 1;
$maxDepth = 1;
$reentries = 0;

app()->events()->listen('kernel.database.query.after', static function () use (&$depth, &$maxDepth, &$reentries): void {
    $depth++;
    $maxDepth = max($maxDepth, $depth);
    $reentries++;
    discoverModules();
    $depth--;
}, 10, '');

$modules = discoverModules();
$ok = is_array($modules) && count($modules) > 0 && $reentries > 0 && $maxDepth <= 2;

echo ($ok ? 'PASS' : 'FAIL') . ': discovery query-listener re-entry is bounded'
    . ' — modules=' . count($modules) . ' reentries=' . $reentries . ' max_depth=' . $maxDepth . PHP_EOL;
echo 'Observed depth: ' . $maxDepth . PHP_EOL;
exit($ok ? 0 : 1);
