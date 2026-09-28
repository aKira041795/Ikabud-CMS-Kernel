<?php
/**
 * Static corpus guard for storefront colour utilities known to fail WCAG AA.
 *
 * This intentionally discovers every public ecommerce template at runtime. New
 * templates therefore join the corpus automatically instead of requiring a
 * hand-maintained file list. No partial currently has an exception.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$templateRoot = $root . '/templates/modules/ecommerce/public';
$knownFailingTextClasses = [
    'text-gray-400',
    'text-amber-600',
    'text-red-500',
    'text-emerald-600',
    'text-orange-600',
];
$knownFailingBackgroundClasses = ['bg-orange-600'];
$explicitlyAllowedPartials = [];
$failures = [];
$templateCount = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($templateRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'disyl') {
        continue;
    }

    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($templateRoot) + 1));
    if (in_array($relativePath, $explicitlyAllowedPartials, true)) {
        continue;
    }

    $templateCount++;
    $contents = (string) file_get_contents($file->getPathname());
    foreach (array_merge($knownFailingTextClasses, $knownFailingBackgroundClasses) as $class) {
        if (preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $contents) === 1) {
            $failures[] = "{$relativePath}: {$class}";
        }
    }
}

if ($templateCount === 0) {
    fwrite(STDERR, "FAIL: storefront template corpus is empty\n");
    exit(1);
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: known contrast failures found in the discovered storefront corpus:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

printf(
    "PASS: %d discovered public ecommerce templates reject %s\n",
    $templateCount,
    implode(', ', array_merge($knownFailingTextClasses, $knownFailingBackgroundClasses))
);
