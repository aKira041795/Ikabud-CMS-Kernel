<?php
/**
 * Regression guard for ARK's region-driven page chrome.
 *
 * Run: php tests/ark_landmark_test.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " {$label}" . (!$ok && $detail !== '' ? " -- {$detail}" : '') . "\n";
}

function fetchArk(string $path): string
{
    $ch = curl_init('http://127.0.0.1' . $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . time());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: cmsnew.test'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status === 200 && is_string($body) ? $body : '';
}

$root = dirname(__DIR__);
$home = fetchArk('/');
$shop = fetchArk('/ecommerce/shop');
$css = (string) file_get_contents($root . '/storage/cms-themes/ark/style.css');

echo "\nARK landmark and dead-chrome guard\n\n";
t('CMS home responds', $home !== '');
t('shop responds', $shop !== '');
foreach (['home' => $home, 'shop' => $shop] as $page => $html) {
    t("{$page} has exactly one header landmark", substr_count($html, '<header') === 1,
        (string) substr_count($html, '<header'));
    t("{$page} has exactly one footer landmark", substr_count($html, '<footer') === 1,
        (string) substr_count($html, '<footer'));
}
t('CMS header retains banner role', preg_match('/<header\b[^>]*\brole=["\']banner["\']/', $home) === 1);
t('CMS footer retains contentinfo role', preg_match('/<footer\b[^>]*\brole=["\']contentinfo["\']/', $home) === 1);

// These classes belonged exclusively to fallback bodies discarded by ikb_region.
// Pin both markup and CSS removal so a parallel, unreachable chrome cannot return.
$retired = ['ark-header__inner', 'ark-header__brand', 'ark-header__nav',
    'ark-header__cart', 'ark-header__user', 'ark-footer__inner', 'ark-footer__bottom',
    'ark-footer__col', 'ark-footer__heading', 'ark-footer__text', 'ark-footer__social'];
foreach ($retired as $class) {
    t("retired chrome stays absent: {$class}",
        !str_contains($home . $shop . $css, $class),
        'found in rendered pages or source stylesheet');
}

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
