#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — S13 defect-fix runtime harness.
 *
 * Two modes, both driving the REAL application code (not a source grep):
 *
 *   sheet [YYYY-MM-DD] [commissary_id]
 *       Boots the kernel, resolves tenant 207, mints a daily-ledger admin JWT,
 *       invokes the real handleAdminCommissary() and prints, as JSON:
 *         - cells   : map "productId:branchId" => rendered branch-cell value
 *         - equation: the Daily Sheet equation text
 *         - summary : map productName => {beg,produced,dispatched,wastage,returned,net}
 *       This is how FIX 1 proves the rendered page (not the handler) and how
 *       FIX 4/FIX 5 read the figures the operator actually sees.
 *
 *   withdraw <payload-file>
 *       Stubs php://input with the payload file (the process exits inside the
 *       real apiSaveCashierWithdrawals through $ctx->json()), so the REAL
 *       cashier-withdrawal write path runs end to end. stdout is the JSON
 *       response the endpoint would have returned.
 *
 * Usage:
 *   php daily_ledger_defect_fixes_s13_harness.php sheet 2026-09-28
 *   php daily_ledger_defect_fixes_s13_harness.php withdraw /tmp/s13-payload.json
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['sheet', 'withdraw'], true)) {
    fwrite(STDERR, "Usage: php daily_ledger_defect_fixes_s13_harness.php sheet [date] [commissary_id] | withdraw <payload-file>\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';

if ($mode === 'sheet') {
    $date = (string)($argv[2] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $_GET['date'] = $date;
    }
    $commissaryId = (int)($argv[3] ?? 0);
    if ($commissaryId > 0) {
        $_GET['commissary_id'] = (string)$commissaryId;
    }
} else {
    $payloadFile = (string)($argv[2] ?? '');
    if ($payloadFile === '' || !is_file($payloadFile)) {
        fwrite(STDERR, "withdraw mode requires a readable payload file\n");
        exit(2);
    }
    $rawPayload = (string)file_get_contents($payloadFile);
}

$_SERVER['REQUEST_URI'] = $mode === 'sheet'
    ? '/daily-ledger/admin/commissary'
    : '/daily-ledger/api/v1/cashier/ledger/withdrawals';

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}

// Mint a real access token directly (no refresh-token cache write) and present
// it the way the browser does, so dlCurrentUser()/dlUserFromRequest() resolve.
$token = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'prod-rizal',
    'name' => 'Noah Omamalin',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $token;

if ($mode === 'sheet') {
    ob_start();
    handleAdminCommissary();
    $html = (string)ob_get_clean();

    $cells = [];
    if (preg_match_all(
        '/<span class="production-branch-value" data-product="(\d+)" data-branch-id="(\d+)">(-?\d+)<\/span>/',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $cells[$match[1] . ':' . $match[2]] = (int)$match[3];
        }
    }

    $equation = '';
    if (preg_match('/BEG \+ ADDTL[^<]*/', $html, $eqMatch)) {
        $equation = trim($eqMatch[0]);
    }

    $summary = [];
    if (preg_match('/Per-Product Summary[\s\S]*?<tbody>([\s\S]*?)<\/tbody>/', $html, $summaryMatch)) {
        preg_match_all('/<tr>([\s\S]*?)<\/tr>/', $summaryMatch[1], $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/', $row[1], $tds);
            $values = array_map(
                static fn(string $td): string => trim(html_entity_decode(strip_tags($td))),
                $tds[1]
            );
            if (count($values) < 8) {
                continue;
            }
            $summary[$values[0]] = [
                'beg' => (int)$values[2],
                'produced' => (int)$values[3],
                'dispatched' => (int)$values[4],
                'wastage' => (int)$values[5],
                'returned' => (int)$values[6],
                'net' => (int)$values[7],
            ];
        }
    }

    // Per-row Daily Sheet figures: the displayed equation's terms beside the
    // pre-filled ACTUAL BAL. Kept separate from the branch cells above.
    $rowsDetail = [];
    if (preg_match_all('/<tr class="daily-sheet-product-row" data-product-id="(\d+)"[\s\S]*?<\/tr>/', $html, $rowMatches, PREG_SET_ORDER)) {
        foreach ($rowMatches as $rowMatch) {
            $pid = (string)$rowMatch[1];
            $block = $rowMatch[0];
            $rowDetail = ['beg' => 0, 'addtl' => 0, 'total' => 0, 'actual' => 0];
            if (preg_match('/id="production-beg-' . $pid . '"[^>]*value="(-?\d+)"/', $block, $m)) {
                $rowDetail['beg'] = (int)$m[1];
            }
            if (preg_match('/id="production-addtl-' . $pid . '"[^>]*>(-?\d+)<\/span>/', $block, $m)) {
                $rowDetail['addtl'] = (int)$m[1];
            }
            if (preg_match('/production-total">(-?\d+)</', $block, $m)) {
                $rowDetail['total'] = (int)$m[1];
            }
            if (preg_match('/id="production-actual-' . $pid . '"[^>]*value="(-?\d+)"/', $block, $m)) {
                $rowDetail['actual'] = (int)$m[1];
            }
            $rowsDetail[$pid] = $rowDetail;
        }
    }

    echo json_encode([
        'cells' => $cells,
        'equation' => $equation,
        'summary' => $summary,
        'rows_detail' => $rowsDetail,
        'rows' => preg_match_all('/<tr class="daily-sheet-product-row"/', $html),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ── withdraw mode: serve the payload through php://input, then run the real
//    endpoint. $ctx->json() exits, so the JSON is the process output. ──
final class DlS13InputWrapper
{
    public static string $body = '';
    public $context;
    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read($count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        if ($whence === SEEK_SET) {
            $this->position = (int)$offset;
        } elseif ($whence === SEEK_CUR) {
            $this->position += (int)$offset;
        } elseif ($whence === SEEK_END) {
            $this->position = strlen(self::$body) + (int)$offset;
        }
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat($path, $flags): array
    {
        return [];
    }
}

DlS13InputWrapper::$body = $rawPayload;
stream_wrapper_unregister('php');
stream_wrapper_register('php', DlS13InputWrapper::class);

apiSaveCashierWithdrawals();
