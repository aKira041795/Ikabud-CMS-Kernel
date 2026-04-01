<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\v4 {
    class Parser {}
}

namespace Ikabud\Kernel\DiSyL\v4\AST {
    class DocumentNode {}
}

namespace Ikabud\Kernel\DiSyL\Compiler {
    class TemplateCompiler {}
    class CompiledTemplate {}
}

namespace {

require_once __DIR__ . '/../kernel/DiSyL/Compiler/TemplateCache.php';

use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . '/' . $item;
        if (is_dir($child)) {
            rrmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

echo "\n=== TEMPLATE CACHE SENTINEL ===\n";

$tmpDir = sys_get_temp_dir() . '/disyl-template-cache-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Failed to create temp directory: {$tmpDir}\n");
    exit(1);
}

$reflection = new ReflectionClass(TemplateCache::class);
$cache = $reflection->newInstanceWithoutConstructor();

$cacheDirProperty = $reflection->getProperty('cacheDir');
$cacheDirProperty->setValue($cache, $tmpDir);

$writeCache = $reflection->getMethod('writeCache');
$validateCacheFile = $reflection->getMethod('validateCacheFile');

$cachePath = $tmpDir . '/Template_Focused.php';
$compiledCode = <<<'PHP'
<?php
namespace Ikabud\Kernel\DiSyL\Compiled;

class Template_Focused {}
PHP;

$writeCache->invoke($cache, $cachePath, $compiledCode);
$written = (string) file_get_contents($cachePath);

t(
    'writeCache prepends sentinel header',
    str_starts_with($written, '<?php // DISYL_CACHE_SENTINEL:'),
    $written === '' ? 'cache file is empty' : ''
);

t(
    'validateCacheFile accepts freshly written cache',
    $validateCacheFile->invoke($cache, $cachePath) === true
);

$tamperedPath = $tmpDir . '/Template_Tampered.php';
file_put_contents($tamperedPath, str_replace('Template_Focused', 'Template_Tampered', $written));
t(
    'validateCacheFile rejects tampered cache body',
    $validateCacheFile->invoke($cache, $tamperedPath) === false
);

$missingSentinelPath = $tmpDir . '/Template_NoSentinel.php';
file_put_contents($missingSentinelPath, $compiledCode);
t(
    'validateCacheFile rejects cache without sentinel',
    $validateCacheFile->invoke($cache, $missingSentinelPath) === false
);

$crlfPath = $tmpDir . '/Template_CRLF.php';
$crlfCode = "<?php\r\nnamespace Ikabud\\Kernel\\DiSyL\\Compiled;\r\n\r\nclass Template_CRLF {}\r\n";
$writeCache->invoke($cache, $crlfPath, $crlfCode);
t(
    'writeCache normalizes CRLF open tags for validation',
    $validateCacheFile->invoke($cache, $crlfPath) === true
);

rrmdir($tmpDir);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($fail > 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

}