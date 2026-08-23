<?php
/**
 * Live external-service canary exemplar.
 *
 * @canary
 *
 * Placeholder only: a real canary would call an external API, but this
 * exemplar stays fully deterministic and makes no network calls so direct
 * execution is harmless.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== Live External Canary Exemplar ===\n";
t('placeholder canary stub passes without external calls', true);

echo "\n{$pass}/" . ($pass + $fail) . " passed\n";
exit($fail > 0 ? 1 : 0);
