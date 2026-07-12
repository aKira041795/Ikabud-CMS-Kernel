<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Crypto;

function tenantCryptoAuditUsage(): void
{
    echo "Tenant DB Crypto Audit\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/audit-tenant-db-crypto.php [--all] [--tenant=ID] [--legacy-key=KEY] [--apply]\n";
    echo "  php scripts/audit-tenant-db-crypto.php --tenant=ID --set-password=PLAINTEXT --apply\n";
    echo "\n";
    echo "Options:\n";
    echo "  --all                 Audit all tenant DB connection rows (default when no tenant is given).\n";
    echo "  --tenant=ID           Audit a single tenant ID.\n";
    echo "  --legacy-key=KEY      Try decrypting failing rows with a legacy CONTROL_DB_ENC_KEY.\n";
    echo "  --set-password=PASS   Reset one tenant row to a known DB password, re-encrypted with the current key.\n";
    echo "  --apply               Persist a repair using --legacy-key or --set-password.\n";
    echo "  --help                Show this message.\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function tenantCryptoAuditRows(PDO $controlDb, ?int $tenantId = null): array
{
    $sql = 'SELECT c.tenant_id, c.db_host, c.db_port, c.db_name, c.db_user, c.db_pass, c.db_pass_ciphertext, c.db_pass_iv, c.db_pass_tag, '
        . 't.tenant_key, t.status '
        . 'FROM kernel_tenant_db_connections c '
        . 'LEFT JOIN kernel_tenants t ON t.id = c.tenant_id';
    $params = [];
    if ($tenantId !== null) {
        $sql .= ' WHERE c.tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenantId;
    }
    $sql .= ' ORDER BY c.tenant_id ASC';

    $stmt = $controlDb->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int, string>
 */
function tenantCryptoAuditDomains(PDO $controlDb, int $tenantId): array
{
    $stmt = $controlDb->prepare(
        'SELECT domain FROM kernel_tenant_domains WHERE tenant_id = :tenant_id ORDER BY domain ASC'
    );
    $stmt->execute([':tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $domains = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $domain = trim((string)($row['domain'] ?? ''));
        if ($domain !== '') {
            $domains[] = $domain;
        }
    }

    return array_values(array_unique($domains));
}

/**
 * @return array{status:string, plaintext:string, detail:string, used_legacy_key:bool}
 */
function tenantCryptoAuditInspectRow(array $row, Crypto $currentCrypto, ?Crypto $legacyCrypto = null): array
{
    $plaintext = (string)($row['db_pass'] ?? '');
    $cipher = (string)($row['db_pass_ciphertext'] ?? '');
    $iv = (string)($row['db_pass_iv'] ?? '');
    $tag = (string)($row['db_pass_tag'] ?? '');
    if ($cipher === '' || $iv === '' || $tag === '') {
        if ($plaintext !== '') {
            return [
                'status' => 'plaintext-only',
                'plaintext' => $plaintext,
                'detail' => 'Row still stores a plaintext db_pass and should be re-encrypted with the current key.',
                'used_legacy_key' => false,
            ];
        }

        return [
            'status' => 'missing',
            'plaintext' => '',
            'detail' => 'No usable tenant DB password is stored in this row.',
            'used_legacy_key' => false,
        ];
    }

    try {
        return [
            'status' => 'ok',
            'plaintext' => $currentCrypto->decryptString($cipher, $iv, $tag),
            'detail' => 'Encrypted tenant DB password decrypts with the current CONTROL_DB_ENC_KEY.',
            'used_legacy_key' => false,
        ];
    } catch (Throwable $currentError) {
        if ($legacyCrypto !== null) {
            try {
                return [
                    'status' => 'legacy-key-ok',
                    'plaintext' => $legacyCrypto->decryptString($cipher, $iv, $tag),
                    'detail' => 'Current key failed but the provided legacy key can decrypt this row.',
                    'used_legacy_key' => true,
                ];
            } catch (Throwable) {
            }
        }

        return [
            'status' => 'fail',
            'plaintext' => '',
            'detail' => 'Current key cannot decrypt this tenant DB password. Verify CONTROL_DB_ENC_KEY or reset/re-encrypt the password.',
            'used_legacy_key' => false,
        ];
    }
}

function tenantCryptoAuditUpdateRow(PDO $controlDb, int $tenantId, string $plaintext, Crypto $currentCrypto): void
{
    $encrypted = $currentCrypto->encryptString($plaintext);
    $stmt = $controlDb->prepare(
        'UPDATE kernel_tenant_db_connections '
        . 'SET db_pass = NULL, db_pass_ciphertext = :cipher, db_pass_iv = :iv, db_pass_tag = :tag '
        . 'WHERE tenant_id = :tenant_id LIMIT 1'
    );
    $stmt->execute([
        ':cipher' => $encrypted['ciphertext'] ?? null,
        ':iv' => $encrypted['iv'] ?? null,
        ':tag' => $encrypted['tag'] ?? null,
        ':tenant_id' => $tenantId,
    ]);
}

$options = getopt('', ['all', 'tenant:', 'legacy-key:', 'set-password:', 'apply', 'help']);
if (isset($options['help'])) {
    tenantCryptoAuditUsage();
    exit(0);
}

$tenantId = isset($options['tenant']) ? (int)$options['tenant'] : null;
if ($tenantId !== null && $tenantId <= 0) {
    fwrite(STDERR, "Invalid --tenant value.\n");
    exit(1);
}

$setPassword = array_key_exists('set-password', $options) ? (string)$options['set-password'] : null;
$legacyKey = array_key_exists('legacy-key', $options) ? trim((string)$options['legacy-key']) : '';
$apply = isset($options['apply']);

if ($setPassword !== null && $tenantId === null) {
    fwrite(STDERR, "--set-password requires --tenant=ID.\n");
    exit(1);
}

try {
    $currentCrypto = new Crypto();
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to load current CONTROL_DB_ENC_KEY: ' . $e->getMessage() . "\n");
    exit(1);
}

$legacyCrypto = null;
if ($legacyKey !== '') {
    try {
        $legacyCrypto = new Crypto($legacyKey);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Unable to use --legacy-key: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$controlDb = app()->controlDb();
$rows = tenantCryptoAuditRows($controlDb, $tenantId);
if ($rows === []) {
    fwrite(STDERR, $tenantId !== null ? 'No tenant DB connection row found for tenant ' . $tenantId . ".\n" : "No tenant DB connection rows found.\n");
    exit(1);
}

// Validate --apply: block if there are NO plaintext-only or legacy-key-ok rows to repair.
if ($apply && $setPassword === null && $legacyKey === '') {
    $hasRepairable = false;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $cipher = (string)($row['db_pass_ciphertext'] ?? '');
        $iv = (string)($row['db_pass_iv'] ?? '');
        $tag = (string)($row['db_pass_tag'] ?? '');
        $plaintext = (string)($row['db_pass'] ?? '');
        if (($cipher === '' || $iv === '' || $tag === '') && $plaintext !== '') {
            $hasRepairable = true;
            break;
        }
    }
    if (!$hasRepairable) {
        fwrite(STDERR, "--apply requires --legacy-key=... or --set-password=... (no plaintext-only rows found).\n");
        exit(1);
    }
}

$summary = [
    'ok' => 0,
    'legacy-key-ok' => 0,
    'plaintext-only' => 0,
    'fail' => 0,
    'missing' => 0,
    'repaired' => 0,
];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $currentTenantId = (int)($row['tenant_id'] ?? 0);
    $tenantKey = trim((string)($row['tenant_key'] ?? ''));
    $domains = tenantCryptoAuditDomains($controlDb, $currentTenantId);
    $inspection = tenantCryptoAuditInspectRow($row, $currentCrypto, $legacyCrypto);
    $status = $inspection['status'];
    $detail = $inspection['detail'];
    $plaintext = $inspection['plaintext'];
    $summary[$status] = ($summary[$status] ?? 0) + 1;

    $repairNote = '';
    $shouldRepair = false;
    if ($apply) {
        if ($setPassword !== null && $tenantId === $currentTenantId) {
            $plaintext = $setPassword;
            $shouldRepair = true;
            $repairNote = 'reset from provided plaintext';
        } elseif ($status === 'legacy-key-ok' || $status === 'plaintext-only') {
            $shouldRepair = true;
            $repairNote = $status === 'legacy-key-ok' ? 're-encrypted from legacy key' : 're-encrypted from plaintext fallback';
        }
    }

    if ($shouldRepair) {
        tenantCryptoAuditUpdateRow($controlDb, $currentTenantId, $plaintext, $currentCrypto);
        $summary['repaired']++;
        $status = 'repaired';
        $detail .= ' Applied repair: ' . $repairNote . '.';
    }

    $domainLabel = $domains !== [] ? implode(', ', $domains) : 'no domains';
    echo '[' . strtoupper($status) . '] tenant ' . $currentTenantId;
    if ($tenantKey !== '') {
        echo ' (' . $tenantKey . ')';
    }
    echo ' domains=' . $domainLabel;
    echo ' db=' . (string)($row['db_user'] ?? '') . '@' . (string)($row['db_host'] ?? '') . '/' . (string)($row['db_name'] ?? '');
    echo "\n";
    echo '  ' . $detail . "\n";
}

echo "\nSummary:\n";
foreach (['ok', 'legacy-key-ok', 'plaintext-only', 'fail', 'missing', 'repaired'] as $key) {
    echo '  ' . $key . ': ' . (int)($summary[$key] ?? 0) . "\n";
}

if (!$apply && ($summary['fail'] > 0 || $summary['legacy-key-ok'] > 0 || $summary['plaintext-only'] > 0)) {
    echo "\nHints:\n";
    echo "  - If rows show LEGACY-KEY-OK, rerun with --legacy-key=<old key> --apply to re-encrypt them with the current CONTROL_DB_ENC_KEY.\n";
    echo "  - If you know a tenant's DB password, rerun with --tenant=<id> --set-password='<password>' --apply.\n";
    echo "  - If all failing rows should already decrypt, verify the live CONTROL_DB_ENC_KEY in .env was preserved across the upgrade.\n";
}

exit($summary['fail'] > 0 ? 2 : 0);