<?php

declare(strict_types=1);

/**
 * One resolver for the administrator's persisted read/view scope.
 *
 * This class deliberately does not participate in branch authorization. Only an
 * admin gets a persisted view filter; cashier/production/supervisor bindings are
 * resolved elsewhere by dl_accessibleBranchIds() and remain unchanged.
 */
final class AdminAreaScope
{
    private const SESSION_PREFIX = 'daily_ledger.admin_view_scope.';

    /**
     * Identity of the view whose scope is being resolved.
     *
     * `/daily-ledger/admin/overview` -> `admin.overview`, `/daily-ledger/admin/commissary` ->
     * `admin.commissary`, `/daily-ledger/ledger` -> `ledger`. Ledger SUB-routes resolve to the ledger
     * itself, because the htmx `/ledger/rows` partial must read the scope its own page set - giving
     * it a separate key would silently show it unfiltered rows.
     *
     * No REQUEST_URI (CLI, tests) degrades to a single stable key, which is what the existing oracles
     * exercise.
     */
    private static function viewKey(): string
    {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = trim((string)preg_replace('#^.*?/daily-ledger/?#', '', $path), '/');
        if ($path === '') {
            return 'default';
        }
        $parts = explode('/', $path);
        if ($parts[0] === 'ledger') {
            return 'ledger';
        }
        if ($parts[0] === 'admin' && isset($parts[1]) && $parts[1] !== '') {
            return 'admin.' . $parts[1];
        }
        return $parts[0];
    }

    /** @return array{type:string,id:int,label:string,branch_ids:array<int,int>,value:string,areas:array,commissaries:array} */
    public static function resolve(array $request, array &$session, array $user): array
    {
        $db = module()?->db();
        $role = (string)($user['role'] ?? '');
        $areas = [];
        $commissaries = [];
        if ($db) {
            $areas = $db->query('SELECT id, code, name FROM dl_areas WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $commissaries = $db->query('SELECT id, code, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // View scope cannot become an authorization input for operational roles.
        if ($role !== 'admin' || !$db) {
            return self::result('ALL', 0, 'All branches', [], 'ALL', $areas, $commissaries);
        }

        $actorId = function_exists('dl_getActorUserId') ? dl_getActorUserId($user) : (int)($user['id'] ?? 0);
        // Keyed per ACTOR **and per VIEW**. Keying on the actor alone made one selection global: an
        // area set on the Ledger narrowed Overview, Commissary, Sales and every other view, and it
        // never cleared when navigating away. A view scope is a property of the view, not of the
        // administrator, so each view keeps its own; a view that was never set resolves to ALL.
        $sessionKey = self::SESSION_PREFIX . max(0, $actorId) . '.' . self::viewKey();
        $explicit = array_key_exists('scope', $request) ? strtoupper(trim((string)$request['scope'])) : null;
        $selection = null;
        if ($explicit !== null) {
            $selection = self::validate($explicit, $areas, $commissaries);
            if ($selection !== null) {
                $session[$sessionKey] = $selection;
            }
        }
        if ($selection === null) {
            $selection = self::validate((string)($session[$sessionKey] ?? ''), $areas, $commissaries);
        }
        // Missing is not a reset: use persisted valid state, then default ALL.
        $selection ??= 'ALL';

        if ($selection === 'ALL') {
            return self::result('ALL', 0, 'All branches', [], 'ALL', $areas, $commissaries);
        }
        [$type, $rawId] = explode(':', $selection, 2);
        $id = (int)$rawId;
        if ($type === 'AREA') {
            $stmt = $db->prepare('SELECT id FROM dl_branches WHERE is_active = 1 AND area_id = :id ORDER BY id');
            $stmt->execute([':id' => $id]);
            $name = self::nameFor($areas, $id);
            return self::result('AREA', $id, 'Area: ' . $name, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), $selection, $areas, $commissaries);
        }

        // A commissary scope is its supply network, not its geographic area.
        $stmt = $db->prepare('SELECT id FROM dl_branches WHERE is_active = 1 AND (id = :id OR assigned_commissary_id = :assigned) ORDER BY id');
        $stmt->execute([':id' => $id, ':assigned' => $id]);
        return self::result('COMMISSARY', $id, 'Commissary network: ' . self::nameFor($commissaries, $id), array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), $selection, $areas, $commissaries);
    }

    private static function validate(string $value, array $areas, array $commissaries): ?string
    {
        if ($value === 'ALL') {
            return 'ALL';
        }
        if (!preg_match('/^(AREA|COMMISSARY):(\d+)$/', $value, $m)) {
            return null;
        }
        $id = (int)$m[2];
        $set = $m[1] === 'AREA' ? $areas : $commissaries;
        foreach ($set as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $m[1] . ':' . $id;
            }
        }
        return null;
    }

    private static function nameFor(array $rows, int $id): string
    {
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return (string)($row['name'] ?? $row['code'] ?? $id);
            }
        }
        return (string)$id;
    }

    private static function result(string $type, int $id, string $label, array $branchIds, string $value, array $areas, array $commissaries): array
    {
        foreach ($areas as &$area) {
            $area['selected'] = $type === 'AREA' && (int)($area['id'] ?? 0) === $id;
        }
        unset($area);
        foreach ($commissaries as &$commissary) {
            $commissary['selected'] = $type === 'COMMISSARY' && (int)($commissary['id'] ?? 0) === $id;
        }
        unset($commissary);
        return ['type' => $type, 'id' => $id, 'label' => $label, 'branch_ids' => $branchIds, 'value' => $value, 'areas' => $areas, 'commissaries' => $commissaries];
    }
}

/** Resolve against the actual request/session without coupling it to authorization. */
function dl_adminAreaScope(array $user, ?array $request = null): array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
    return AdminAreaScope::resolve($request ?? (module()?->input() ?? []), $_SESSION, $user);
}

/**
 * Branch ids for a VIEW query (a picker, a list, a Daily Sheet column set).
 *
 * Authorization comes first and is never widened or narrowed here: the result is always a subset of
 * dl_accessibleBranchIds(). On top of that:
 *
 * - an ADMIN's set is narrowed by the persisted area/network scope;
 * - an OPERATIONAL actor's set is their assigned branches, extended by the network of any assigned
 *   branch that is itself a commissary.
 *
 * The operational half is the 2026-10-10 correction. Using the assignment alone made a production
 * user assigned to a commissary present that one branch instead of the network they dispatch to, so
 * the Daily Sheet rendered ZERO destination columns (measured: prod-rizal, RIZAL-COMMIS1 -> columns=0).
 * Using the raw set instead (what AdminAreaScope's ALL-for-operational-roles fallback produced) put
 * every branch in the tenant in the sheet, which is the reported "Rizal Commissary includes Pagadian
 * commissary branches". The network keeps the commissary's own branches - which legitimately span
 * more than one area, RIZAL-COMMIS1 covers both Dapitan and Dipolog - and still excludes a sibling
 * commissary's network.
 *
 * @return int[]
 */
function dl_adminViewBranchIds(array $user, ?array $request = null): array
{
    $authorized = array_values(array_unique(array_map('intval', dl_accessibleBranchIds($user))));
    if (($user['role'] ?? '') !== 'admin') {
        return dl_assignedBranchNetwork($authorized);
    }

    $scope = dl_adminAreaScope($user, $request);
    if (($scope['type'] ?? 'ALL') === 'ALL') {
        return $authorized;
    }

    $allowed = array_fill_keys(array_map('intval', $scope['branch_ids'] ?? []), true);
    return array_values(array_filter(
        $authorized,
        static fn(int $branchId): bool => isset($allowed[$branchId])
    ));
}

/**
 * The branch set an operational actor OPERATES: the branches assigned to them, plus the branches
 * assigned to any of those that is itself a commissary.
 *
 * One definition, used by BOTH authorization (dl_accessibleBranchIds) and presentation
 * (dl_adminViewBranchIds), so what an actor may act on and what they are shown agree by
 * construction. They were allowed to disagree once and the result was measurable: prod-rizal could
 * SELECT all 11 RIZAL network destinations in the Daily Sheet (presentation) while
 * dl_processProductionMovement rejected every one of them with "Destination branch is not allowed
 * for this user" (authorization) - the production lane could not record a dispatch at all.
 *
 * A cashier assigned an ordinary branch is unaffected (nothing to expand). It can never add a branch
 * outside the network of a commissary the actor was ALREADY assigned, so it cannot widen access beyond
 * the estate the actor was entrusted with.
 *
 * @param int[] $branchIds
 * @return int[]
 */
function dl_assignedBranchNetwork(array $branchIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn(int $id): bool => $id > 0)));
    $db = module()?->db();
    if ($db === null || $ids === []) {
        return $ids;
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $commissaryStmt = $db->prepare("SELECT id FROM dl_branches WHERE is_active = 1 AND is_commissary = 1 AND id IN ({$marks})");
    $commissaryStmt->execute($ids);
    $commissaryIds = array_map('intval', array_column($commissaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    if ($commissaryIds === []) {
        return $ids;
    }

    $netMarks = implode(',', array_fill(0, count($commissaryIds), '?'));
    $netStmt = $db->prepare("SELECT id FROM dl_branches WHERE is_active = 1 AND assigned_commissary_id IN ({$netMarks})");
    $netStmt->execute($commissaryIds);
    $network = array_map('intval', array_column($netStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));

    return array_values(array_unique(array_merge($ids, $network)));
}
