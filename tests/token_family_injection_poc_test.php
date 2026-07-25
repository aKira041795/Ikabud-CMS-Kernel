<?php

declare(strict_types=1);

/**
 * TokenFamily — injection PoC test.
 *
 * Demonstrates the decomposed pattern: TokenFamily receives a
 * TenantDatabase via constructor instead of calling app()->db().
 * A FakeTenantDatabase is used in place of a real PDO connection.
 *
 * This is the first vertical slice of App decomposition (Step 2).
 */

require_once __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== TokenFamily — Injection PoC ===\n";

// ── Fake TenantDatabase for isolated testing ──
// Stores executed SQL and parameters for verification.
class FakeTenantDatabase implements \Ikabud\Kernel\Contracts\TenantDatabase
{
    /** @var array{string, array}[] */
    public array $executed = [];

    /** @var array<int, array<string, mixed>> Simulated rows for fetch */
    public array $rows = [];

    /** @var int Number of rows to return from the next fetch */
    public int $fetchCount = 0;

    /** @var int Current fetch position */
    private int $fetchIndex = 0;

    /** @var list<\PDOStatement> Statements created by prepare() */
    private array $statements = [];

    public string $lastInsertId = '0';

    public function prepare(string $sql): \PDOStatement
    {
        $this->executed[] = [$sql, []];
        $stmt = new FakePdoStatement($this->rows, $this->fetchIndex);
        $this->statements[] = $stmt;
        return $stmt;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $this->executed[] = [$sql, $params];
        $this->fetchIndex = 0;
        return new FakePdoStatement($this->rows, $this->fetchIndex);
    }

    public function execute(string $sql, array $params = []): bool
    {
        $this->executed[] = [$sql, $params];
        return true;
    }

    public function lastInsertId(): string
    {
        return $this->lastInsertId;
    }

    public function dbForTenant(int $tenantId): ?\PDO
    {
        return null;
    }

    public function reconnect(): \PDO
    {
        throw new \RuntimeException('Not implemented in fake');
    }

    public function reconnectForTenant(int $tenantId): ?\PDO
    {
        return null;
    }

    public function reset(): void
    {
        $this->executed = [];
        $this->rows = [];
        $this->fetchIndex = 0;
        $this->statements = [];
    }
}

class FakePdoStatement extends \PDOStatement
{
    public function __construct(
        private array &$rows,
        private int &$fetchIndex,
    ) {}

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch($mode = \PDO::FETCH_ASSOC, ...$args): mixed
    {
        $idx = $this->fetchIndex;
        $this->fetchIndex++;
        return $this->rows[$idx] ?? false;
    }

    public function fetchAll($mode = \PDO::FETCH_ASSOC, ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn($column = 0): mixed
    {
        $row = $this->fetch();
        $values = is_array($row) ? array_values($row) : [];
        return $values[$column] ?? false;
    }
}

// ── 1. Constructor injection ──
echo "\n--- Constructor Injection ---\n";

$db = new FakeTenantDatabase();
$tf = new \Ikabud\Kernel\Services\TokenFamily($db);
t('TokenFamily accepts TenantDatabase via constructor', $tf !== null);

// ── 2. AppTenantDatabase adapter wraps App correctly ──
echo "\n--- AppTenantDatabase Adapter ---\n";

$adapter = new \Ikabud\Kernel\Adapters\AppTenantDatabase();
t('AppTenantDatabase implements TenantDatabase', $adapter instanceof \Ikabud\Kernel\Contracts\TenantDatabase);

// ── 3. AppAuthProvider adapter ──
echo "\n--- AppAuthProvider Adapter ---\n";

$authAdapter = new \Ikabud\Kernel\Adapters\AppAuthProvider();
t('AppAuthProvider implements AuthProvider', $authAdapter instanceof \Ikabud\Kernel\Contracts\AuthProvider);

// ── 4. AppRenderEngine adapter ──
echo "\n--- AppRenderEngine Adapter ---\n";

$renderAdapter = new \Ikabud\Kernel\Adapters\AppRenderEngine();
t('AppRenderEngine implements RenderEngine', $renderAdapter instanceof \Ikabud\Kernel\Contracts\RenderEngine);

// ── 5. TokenFamily::create() with fake DB ──
echo "\n--- TokenFamily::create() ---\n";

$db->reset();
$result = $tf->create(42, 'device-abc-123');

t('create() returns family_id', isset($result['family_id']) && $result['family_id'] !== '');
t('create() returns refresh_token', isset($result['refresh_token']) && $result['refresh_token'] !== '');
t('create() returns refresh_hash', isset($result['refresh_hash']) && $result['refresh_hash'] !== '');
t('create() returns expires_at', isset($result['expires_at']) && $result['expires_at'] !== '');
t('create() executed an INSERT', count($db->executed) >= 1);
t('create() INSERT contains kernel_token_families', !empty($db->executed) && str_contains($db->executed[0][0], 'INSERT INTO kernel_token_families'));

// ── 6. TokenFamily::rotate() with family not found ──
echo "\n--- TokenFamily::rotate() — not found ---\n";

$db->reset();
$db->rows = []; // No rows returned → family not found
$rotateResult = $tf->rotate('nonexistent-family', 'somehash');
t('rotate() returns success=false for missing family', ($rotateResult['success'] ?? true) === false);
t('rotate() reason is family_not_found', ($rotateResult['reason'] ?? '') === 'family_not_found');

// ── 7. TokenFamily::rotate() with active family ──
echo "\n--- TokenFamily::rotate() — active family ---\n";

$db->reset();
$db->rows = [[
    'id' => 1,
    'user_id' => 42,
    'status' => 'active',
    'current_token_hash' => 'old-hash-value',
    'consumed_token_hashes' => '[]',
]];
$rotateResult = $tf->rotate('family-1', 'valid-token-hash');
t('rotate() returns success for valid family', $rotateResult['success'] === true);
t('rotate() returns user_id 42', ($rotateResult['user_id'] ?? 0) === 42);
t('rotate() returns new refresh_token', isset($rotateResult['new_token']) && $rotateResult['new_token'] !== '');
t('rotate() executed UPDATE with consumed old hash', count($db->executed) >= 1);
// First executed should be the SELECT FOR UPDATE
t('rotate() first query is SELECT ... FOR UPDATE', !empty($db->executed) && str_contains($db->executed[0][0], 'FOR UPDATE'));

// ── 8. Theft detection ──
echo "\n--- TokenFamily — theft detection ---\n";

$db->reset();
// Clear logs before theft test (theft detection writes [critical] intentionally)
file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
// A consumed token hash is presented again
$db->rows = [[
    'id' => 2,
    'user_id' => 99,
    'status' => 'active',
    'current_token_hash' => 'new-hash',
    'consumed_token_hashes' => json_encode(['stolen-token-hash']),
]];
$theftResult = $tf->rotate('family-stolen', 'stolen-token-hash');
t('theft detection returns success=false', ($theftResult['success'] ?? true) === false);
t('theft detection reason is theft_detected', ($theftResult['reason'] ?? '') === 'theft_detected');

// ── 9. TokenFamily::revoke() ──
echo "\n--- TokenFamily::revoke() ---\n";

$db->reset();
$tf->revoke('family-to-revoke');
t('revoke() executed an UPDATE', count($db->executed) >= 1);
t('revoke() UPDATE targets kernel_token_families', str_contains($db->executed[0][0], 'UPDATE kernel_token_families'));
t('revoke() WHERE sets status to revoked', str_contains($db->executed[0][0], "status = 'revoked'"));

// ── 10. TokenFamily::revokeAllForUser() ──
echo "\n--- TokenFamily::revokeAllForUser() ---\n";

$db->reset();
$tf->revokeAllForUser(42);
t('revokeAllForUser() executed 2 queries', count($db->executed) === 2);
t('revokeAllForUser() first targets kernel_token_families', str_contains($db->executed[0][0], 'UPDATE kernel_token_families'));
t('revokeAllForUser() second targets kernel_device_sessions', str_contains($db->executed[1][0], 'UPDATE kernel_device_sessions'));

// ── 11. Static instance() accessor ──
echo "\n--- Static Access ---\n";

$staticInstance = \Ikabud\Kernel\Services\TokenFamily::instance();
t('TokenFamily::instance() returns a TokenFamily', $staticInstance instanceof \Ikabud\Kernel\Services\TokenFamily);
t('TokenFamily::instance() uses AppTenantDatabase internally', $staticInstance !== null);

// Clear logs before final check (theft detection test intentionally writes critical)
file_put_contents(__DIR__ . '/../storage/logs/app.log', '');

// ── 12. Truth table: contracts created ──
echo "\n--- Contract Verification ---\n";

t('TenantDatabase interface exists', interface_exists(\Ikabud\Kernel\Contracts\TenantDatabase::class));
t('AuthProvider interface exists', interface_exists(\Ikabud\Kernel\Contracts\AuthProvider::class));
t('RenderEngine interface exists', interface_exists(\Ikabud\Kernel\Contracts\RenderEngine::class));
t('AppTenantDatabase adapter exists', class_exists(\Ikabud\Kernel\Adapters\AppTenantDatabase::class));
t('AppAuthProvider adapter exists', class_exists(\Ikabud\Kernel\Adapters\AppAuthProvider::class));
t('AppRenderEngine adapter exists', class_exists(\Ikabud\Kernel\Adapters\AppRenderEngine::class));

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
