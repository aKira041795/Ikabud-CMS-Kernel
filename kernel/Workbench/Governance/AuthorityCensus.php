<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Governance;

/**
 * Authority census — declared policy rows vs the active policy store.
 *
 * A capability's authority is expressed in four places that must agree, and
 * nothing reconciles them. This census covers the pair that produced the
 * sharpest recorded instance: the declared policy rows the seed code intends
 * (source 3) and the rows the store actually permits (source 4).
 *
 * The comparison itself is a pure function of two arrays. Reading the real
 * sources belongs to the command that feeds it; the class only gains a
 * declaration-capture helper so the command does not have to reimplement the
 * isolated read.
 *
 * It grants nothing and writes nothing. Correcting a divergence changes what
 * the system permits, so those corrections stay with the director.
 */
final class AuthorityCensus
{
    /**
     * Pure comparison of declared rows against the store's active rows.
     *
     * Each declaration is matched to the active row with the same
     * `capability_id`. Rows are classified as:
     *
     *   - `declared_absent` — no active row exists for the declaration;
     *   - `superseded`      — the declaration's version is not the active one,
     *                         so it is inert bookkeeping, not a live divergence;
     *   - `divergent`       — same version, but the role and/or caller sets differ;
     *   - `matching`        — same version and same sets. It is still reported so
     *                         the total is auditable.
     *
     * Role and caller lists compare as SETS: order is not authority. An empty
     * list means unrestricted, which is the widest possible set. Widening and
     * narrowing are different findings and are never merged.
     *
     * @param list<array<string,mixed>> $declarations
     * @param list<array<string,mixed>> $activeRows
     * @return array{counts: array<string,int>, findings: list<array<string,mixed>>}
     */
    public static function compare(array $declarations, array $activeRows): array
    {
        $activeByCapability = [];
        foreach ($activeRows as $row) {
            if (array_key_exists('is_active', $row) && !$row['is_active']) {
                continue;
            }
            $id = trim((string) ($row['capability_id'] ?? ''));
            if ($id === '' || isset($activeByCapability[$id])) {
                continue;
            }
            $activeByCapability[$id] = $row;
        }

        $counts = [
            'matching' => 0,
            'declared_absent' => 0,
            'divergent' => 0,
            'superseded' => 0,
        ];
        $findings = [];

        foreach ($declarations as $declaration) {
            $id = trim((string) ($declaration['capability_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $declaredVersion = isset($declaration['policy_version']) ? (int) $declaration['policy_version'] : null;
            $active = $activeByCapability[$id] ?? null;

            if ($active === null) {
                ++$counts['declared_absent'];
                $findings[] = self::finding($id, 'declared_absent', null, [], $declaredVersion, null);
                continue;
            }

            $activeVersion = isset($active['policy_version']) ? (int) $active['policy_version'] : null;
            if ($declaredVersion !== null && $activeVersion !== null && $declaredVersion !== $activeVersion) {
                ++$counts['superseded'];
                $findings[] = self::finding($id, 'superseded', null, [], $declaredVersion, $activeVersion);
                continue;
            }

            $fields = [];
            $widening = false;
            $narrowing = false;
            foreach (['allowed_roles', 'caller_module'] as $field) {
                $relation = self::fieldRelation($declaration[$field] ?? null, $active[$field] ?? null);
                if ($relation === 0) {
                    continue;
                }
                $fields[] = $field;
                if ($relation === 1) {
                    $widening = true;
                } elseif ($relation === -1) {
                    $narrowing = true;
                } else {
                    // Incomparable sets have both a wider and a narrower edge.
                    $widening = true;
                    $narrowing = true;
                }
            }

            if ($fields === []) {
                ++$counts['matching'];
                $findings[] = self::finding($id, 'matching', null, [], $declaredVersion, $activeVersion);
                continue;
            }

            $direction = $widening && $narrowing ? 'mixed' : ($widening ? 'widening' : 'narrowing');
            ++$counts['divergent'];
            $findings[] = self::finding($id, 'divergent', $direction, $fields, $declaredVersion, $activeVersion);
        }

        usort(
            $findings,
            static fn (array $a, array $b): int => strcmp((string) $a['capability_id'], (string) $b['capability_id'])
        );

        return ['counts' => $counts, 'findings' => $findings];
    }

    /**
     * Capture the declared policy rows by running the seed declarations in an
     * isolated PHP subprocess.
     *
     * The declarations are not data: they are expressions inside module helper
     * functions that join the currently active policy version at runtime. The
     * only faithful read is to run them. The subprocess replaces the authority
     * store with a scratch in-memory SQLite database seeded at the requested
     * active version, so the declarations are captured as rows and the real
     * authority store is never opened, written, or locked.
     *
     * @return list<array<string,mixed>>
     */
    public static function captureDeclaredRows(string $root, int $activePolicyVersion = 1): array
    {
        if ($activePolicyVersion < 1) {
            $activePolicyVersion = 1;
        }
        $script = str_replace(
            ['__ROOT__', '__ACTIVE__'],
            [var_export($root, true), (string) $activePolicyVersion],
            self::captureScript()
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=0', '-r', $script],
            $descriptors,
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            return [];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        $stdout = is_string($stdout) ? $stdout : '';
        $marker = '___AUTHORITY_CENSUS_JSON___';
        $position = strpos($stdout, $marker);
        if ($position === false) {
            return [];
        }
        $decoded = json_decode(trim(substr($stdout, $position + strlen($marker))), true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::normalizeDeclaredRows($decoded, $activePolicyVersion);
    }

    /**
     * @param array<int,mixed> $rows
     * @return list<array<string,mixed>>
     */
    private static function normalizeDeclaredRows(array $rows, int $activePolicyVersion): array
    {
        $byCapability = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['capability_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $version = (int) ($row['policy_version'] ?? 0);
            $candidate = [
                'capability_id' => $id,
                'policy_version' => $version,
                'allowed_roles' => isset($row['allowed_roles']) ? (string) $row['allowed_roles'] : null,
                'caller_module' => isset($row['caller_module']) ? (string) $row['caller_module'] : null,
            ];
            if (!isset($byCapability[$id])) {
                $byCapability[$id] = $candidate;
                continue;
            }
            $current = $byCapability[$id];
            $currentIsActive = $current['policy_version'] === $activePolicyVersion;
            $candidateIsActive = $version === $activePolicyVersion;
            if ($candidateIsActive && !$currentIsActive) {
                $byCapability[$id] = $candidate;
                continue;
            }
            if ($candidateIsActive === $currentIsActive && $version > $current['policy_version']) {
                $byCapability[$id] = $candidate;
            }
        }

        $result = array_values($byCapability);
        usort(
            $result,
            static fn (array $a, array $b): int => strcmp((string) $a['capability_id'], (string) $b['capability_id'])
        );

        return $result;
    }

    /**
     * Compare one field as a set of CSV tokens.
     *
     * An empty set means unrestricted. Returns 1 when the declaration is
     * broader, -1 when it is narrower, 0 when equal, and 2 when the sets are
     * incomparable (both broader and narrower on different tokens).
     */
    private static function fieldRelation(mixed $declared, mixed $active): int
    {
        $declaredSet = self::csvSet($declared);
        $activeSet = self::csvSet($active);
        if ($declaredSet === $activeSet) {
            return 0;
        }
        if ($declaredSet === []) {
            return 1;
        }
        if ($activeSet === []) {
            return -1;
        }
        $declaredExtras = array_diff($declaredSet, $activeSet);
        $activeExtras = array_diff($activeSet, $declaredSet);
        if ($declaredExtras === []) {
            return -1;
        }
        if ($activeExtras === []) {
            return 1;
        }
        return 2;
    }

    /**
     * @return list<string>
     */
    private static function csvSet(mixed $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $seen = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $seen[$token] = true;
            }
        }
        $set = array_keys($seen);
        sort($set, SORT_STRING);
        return $set;
    }

    /**
     * @param list<string> $fields
     * @return array{capability_id: string, class: string, direction: ?string, fields: list<string>, declared_version: ?int, active_version: ?int}
     */
    private static function finding(
        string $id,
        string $class,
        ?string $direction,
        array $fields,
        ?int $declaredVersion,
        ?int $activeVersion
    ): array {
        return [
            'capability_id' => $id,
            'class' => $class,
            'direction' => $direction,
            'fields' => $fields,
            'declared_version' => $declaredVersion,
            'active_version' => $activeVersion,
        ];
    }

    /**
     * The isolated capture harness. `__ROOT__` and `__ACTIVE__` are substituted
     * before the script is handed to a separate PHP process.
     */
    private static function captureScript(): string
    {
        return <<<'HARNESS'
$root = __ROOT__;
$active = __ACTIVE__;

define('BASE_PATH', $root);
define('KERNEL_PATH', $root . '/kernel');
define('STORAGE_PATH', $root . '/storage');
define('SRC_PATH', $root . '/src');
define('CONFIG_PATH', $root . '/config');

require $root . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    if (strncmp($class, 'Ikabud\\Kernel\\', 14) === 0) {
        $path = $root . '/kernel/' . str_replace('\\', '/', substr($class, 14)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

if (!function_exists('kernelRegisterRenderContextContract')) {
    function kernelRegisterRenderContextContract(...$args): void {}
}
if (!function_exists('kernel_request_context_get')) {
    function kernel_request_context_get($key, $default = null) { return $default; }
}
if (!function_exists('kernel_request_context_set')) {
    function kernel_request_context_set($key, $value) { return $value; }
}
if (!function_exists('kernel_request_context_delete')) {
    function kernel_request_context_delete($key): void {}
}

class AuthorityCensusScratchEvents
{
    public function listen(...$args): void {}
    public function fire(...$args): void {}
}

class AuthorityCensusScratchWorkflow
{
    public function registerCaller(...$args): void {}
    public function ensureDefinition(...$args): void {}
}

class AuthorityCensusScratchTenant
{
    public function current() { return 1; }
    public function resolve($actor = null) { return 1; }
    public function setTenantId($id): void {}
}

class AuthorityCensusScratchApp
{
    public $pdo = null;
    public function dbForTenant(int $id) { return $this->pdo; }
    public function db() { return $this->pdo; }
    public function controlDb() { return $this->pdo; }
    public function user() { return ['id' => 1, 'role' => 'superadmin', 'tenant_id' => 1]; }
    public function tenant() { return new AuthorityCensusScratchTenant(); }
    public function setUser($user): void {}
    public function events() { return new AuthorityCensusScratchEvents(); }
    public function workflow() { return new AuthorityCensusScratchWorkflow(); }
    public function getActiveModule() { return null; }
    public function clearActiveModule(): void {}
    public function setActiveModule($module): void {}
}

$GLOBALS['authorityCensusScratchApp'] = new AuthorityCensusScratchApp();
if (!function_exists('app')) {
    function app() { return $GLOBALS['authorityCensusScratchApp']; }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static function (): string { return date('Y-m-d H:i:s'); });
$pdo->exec('CREATE TABLE capability_authorization_policies ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, policy_version INTEGER, capability_id TEXT, '
    . 'capability_version TEXT, provider TEXT, caller_module TEXT, allowed_roles TEXT, '
    . 'provider_activation_required INTEGER, requires_protocol TEXT, is_active INTEGER, '
    . 'grant_state TEXT, updated_at TEXT)');
$seed = $pdo->prepare('INSERT INTO capability_authorization_policies '
    . '(policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, '
    . 'provider_activation_required, requires_protocol, is_active, grant_state, updated_at) '
    . "VALUES (:v, '__active_marker__', '1', 'kernel', NULL, 'admin', 1, 'v1', 1, 'granted', 'x')");
$seed->execute([':v' => $active]);
$GLOBALS['authorityCensusScratchApp']->pdo = $pdo;

$candidates = array_merge(
    glob($root . '/modules/*/*/helpers.php') ?: [],
    glob($root . '/modules/*/helpers.php') ?: []
);
sort($candidates);
$core = $root . '/modules/cms-akira/cms-akira-core/helpers.php';
$ordered = [];
if (is_file($core)) {
    $ordered[] = $core;
}
foreach ($candidates as $candidate) {
    if ($candidate === $core) {
        continue;
    }
    $source = @file_get_contents($candidate);
    if (is_string($source) && str_contains($source, 'seedPolicyForCurrentScope')) {
        $ordered[] = $candidate;
    }
}

foreach ($ordered as $helper) {
    try {
        require_once $helper;
    } catch (Throwable $e) {
        // A helper that cannot load is reported, not fatal: the declarations it
        // would have seeded stay absent from the capture rather than aborting it.
        fwrite(STDERR, 'authority-census: skipped ' . $helper . ': ' . $e->getMessage() . "\n");
    }
}

$rows = $pdo->query(
    "SELECT policy_version, capability_id, caller_module, allowed_roles "
    . "FROM capability_authorization_policies WHERE capability_id <> '__active_marker__' "
    . 'ORDER BY capability_id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

echo '___AUTHORITY_CENSUS_JSON___' . json_encode(is_array($rows) ? $rows : []);
HARNESS;
    }
}
