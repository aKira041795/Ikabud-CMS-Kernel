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
        $sessionKey = self::SESSION_PREFIX . max(0, $actorId);
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
 * Branch ids for an admin VIEW query: authorization first, then the persisted
 * area/network presentation scope. Operational roles never consult that scope.
 *
 * @return int[]
 */
function dl_adminViewBranchIds(array $user, ?array $request = null): array
{
    $authorized = array_values(array_unique(array_map('intval', dl_accessibleBranchIds($user))));
    if (($user['role'] ?? '') !== 'admin') {
        return $authorized;
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
