<?php
/**
 * Footer colour persistence/render contract.
 *
 * Run against another ARK tree with ARK_THEME_PATH=/path/to/ark.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$passed = 0;
$failed = 0;

function footerVocabularyAssert(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failed;
    echo "  FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

$themePath = rtrim((string)(getenv('ARK_THEME_PATH') ?: dirname(__DIR__) . '/storage/cms-themes/ark'), '/');
$probe = [
    'bg_color' => '#123456',
    'text_color' => '#abcdef',
    'link_color' => '#fedcba',
    'link_hover_color' => '#13579b',
    'title_color' => '#2468ac',
];

// This is the save -> stored JSON -> reload path without a database dependency.
$saved = cmsValidateFooterSettings(array_merge(cmsFooterSettingsDefaults(), $probe));
$storedJson = json_encode($saved, JSON_THROW_ON_ERROR);
$reloaded = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);

$engine = new TemplateEngine(dirname(__DIR__) . '/templates', sys_get_temp_dir() . '/ark_footer_vocabulary_' . getmypid(), false);
$template = (string)file_get_contents($themePath . '/templates/regions/footer.disyl');
$markup = $engine->renderString($template, [
    'theme' => 'ark',
    'current_year' => '2026',
    'site' => ['title' => 'Vocabulary Probe'],
    'navigation' => ['footer' => '<a href="/">Home</a>'],
    'section_settings' => $reloaded,
]);

echo "ARK footer colour vocabulary\n\n";
foreach ($probe as $key => $value) {
    footerVocabularyAssert(($reloaded[$key] ?? null) === $value, "save/reload preserves footer.{$key}");
}
footerVocabularyAssert(
    array_intersect(array_keys($reloaded), [
        'footer_bg_color', 'footer_text_color', 'footer_link_color',
        'footer_link_hover_color', 'footer_title_color',
    ]) === [],
    'persisted JSON contains only canonical CMS colour spellings',
    $storedJson
);

// A synthetic stored row proves additive reads for old/imported ARK spellings.
// Canonical wins if both spellings exist; aliases fill only missing canonical keys.
$_ENV['CMS_CUSTOMIZER_CACHE_TTL'] = '0';
$aliasRow = json_encode([
    'bg_color' => '#010203',
    'footer_bg_color' => '#999999',
    'footer_text_color' => '#112233',
    'footer_link_color' => '#223344',
    'footer_link_hover_color' => '#334455',
    'footer_title_color' => '#445566',
], JSON_THROW_ON_ERROR);
$fakeDb = new class($aliasRow) {
    public function __construct(private string $settingsJson) {}
    public function prepare(string $sql): object
    {
        return new class($this->settingsJson) {
            public function __construct(private string $settingsJson) {}
            public function execute(array $params): void {}
            public function fetch(int $mode): array
            {
                return ['settings_json' => $this->settingsJson, 'widgets_json' => '[]'];
            }
        };
    }
};
$aliased = cmsCustomizerGet($fakeDb, 'footer', 'native')['settings'];
foreach ([
    'bg_color' => '#010203',
    'text_color' => '#112233',
    'link_color' => '#223344',
    'link_hover_color' => '#334455',
    'title_color' => '#445566',
] as $key => $expected) {
    footerVocabularyAssert(($aliased[$key] ?? null) === $expected, "read alias resolves footer.{$key}");
}

footerVocabularyAssert(
    str_contains($markup, 'background:#123456;color:#abcdef'),
    'emitted footer markup contains background and text colours',
    $markup
);
footerVocabularyAssert(
    str_contains($markup, '--footer-link-hover:#13579b;--footer-link:#fedcba'),
    'emitted footer markup contains link and hover colours',
    $markup
);
footerVocabularyAssert(
    str_contains($markup, '--footer-title:#2468ac'),
    'emitted footer markup contains title colour',
    $markup
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
