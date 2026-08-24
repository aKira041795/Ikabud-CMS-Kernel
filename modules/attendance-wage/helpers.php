<?php

declare(strict_types=1);

/**
 * Attendance & Wage — Helpers
 *
 * Capability handlers for entity views + core business logic.
 * Auto-loaded globally when the module is enabled.
 */

/**
 * Check if a column exists in a table (safe fallback when migration hasn't run yet).
 * Static cache ensures the check runs only once per column per request.
 */
function aw_hasColumn(\PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $db->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
        $cache[$key] = true;
        return true;
    } catch (\Throwable $e) {
        $cache[$key] = false;
        return false;
    }
}

app()->registerAuthTable('attendance-wage', 'attendance_wage_users');

function attendance_wage_capability_handlers(): array
{
    return [
        // Auth capability (pipeline — kernel calls this for authentication)
        'kernel.auth.authenticate@1'         => 'aw_cap_kernel_auth_authenticate_1',
        // Module capabilities
        'attendance_wage.clock@1'            => 'aw_cap_clock_1',
        'attendance_wage.read@1'             => 'aw_cap_read_1',
        'attendance_wage.manage@1'           => 'aw_cap_manage_1',
        'attendance_wage.approve@1'          => 'aw_cap_approve_1',
        'attendance_wage.admin@1'            => 'aw_cap_admin_1',
        // Team attendance bridge capability (used by PAL for mobilization)
        'attendance_wage.team_attendance.summary@1' => 'aw_cap_team_attendance_summary_1',
        // Entity list handlers
        'entity.list.attendance_record@1'    => 'aw_cap_entity_list_attendance_record_1',
        'entity.list.employee_profile@1'     => 'aw_cap_entity_list_employee_profile_1',
        'entity.list.payroll_period@1'       => 'aw_cap_entity_list_payroll_period_1',
        'entity.list.salary_computation@1'   => 'aw_cap_entity_list_salary_computation_1',
        'entity.list.salary_adjustment@1'    => 'aw_cap_entity_list_salary_adjustment_1',
        'entity.list.employee_deduction@1'   => 'aw_cap_entity_list_employee_deduction_1',
        'entity.list.holiday@1'              => 'aw_cap_entity_list_holiday_1',
        'entity.list.cash_advance@1'         => 'aw_cap_entity_list_cash_advance_1',
        'entity.get.attendance_record@1'     => 'aw_cap_entity_get_attendance_record_1',
        'entity.get.employee_profile@1'      => 'aw_cap_entity_get_employee_profile_1',
        'entity.get.payroll_period@1'        => 'aw_cap_entity_get_payroll_period_1',
        'entity.get.salary_computation@1'    => 'aw_cap_entity_get_salary_computation_1',
        'entity.get.salary_adjustment@1'     => 'aw_cap_entity_get_salary_adjustment_1',
        'entity.get.employee_deduction@1'    => 'aw_cap_entity_get_employee_deduction_1',
        'entity.get.holiday@1'               => 'aw_cap_entity_get_holiday_1',
        'entity.get.cash_advance@1'          => 'aw_cap_entity_get_cash_advance_1',
        'entity.list.employee_schedule@1'    => 'aw_cap_entity_list_employee_schedule_1',
        'entity.get.employee_schedule@1'     => 'aw_cap_entity_get_employee_schedule_1',
        'entity.list.office_location@1'      => 'aw_cap_entity_list_office_location_1',
        'attendance.record.hours.update@1'   => 'aw_cap_attendance_hours_update_1',
        'entity.get.office_location@1'       => 'aw_cap_entity_get_office_location_1',
    ];
}

// Module capabilities — check authenticated user role
function aw_cap_clock_1(mixed $payload): array   { return ['granted' => aw_userHasRole(['admin','supervisor','employee'])]; }
function aw_cap_read_1(mixed $payload): array    { return ['granted' => aw_userHasRole(['admin','supervisor','employee'])]; }
function aw_cap_manage_1(mixed $payload): array  { return ['granted' => aw_userHasRole(['admin','supervisor'])]; }
function aw_cap_approve_1(mixed $payload): array { return ['granted' => aw_userHasRole(['admin'])]; }
function aw_cap_admin_1(mixed $payload): array   { return ['granted' => aw_userHasRole(['admin'])]; }

function aw_userHasRole(array $roles): bool
{
    $user = app()->user();
    if (!is_array($user) || (($user['source'] ?? '') !== 'attendance-wage')) return false;
    $userRole = (string)($user['role'] ?? '');
    return in_array($userRole, $roles, true);
}

function aw_csrfGuard(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfEnforceFromJwt('attendance_wage_token');
    }
}

// Auth capability (kernel.auth.authenticate@1 — pipeline provider)
function aw_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) return null;
    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') return null;

    $prefix = '@attendance-wage:';
    if (!str_starts_with($username, $prefix)) return null;
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') return null;

    try {
        $stmt = aw_db()->prepare(
            "SELECT id, username, email, password_hash, full_name, role, is_active FROM attendance_wage_users WHERE (username = :u OR email = :e) AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':u' => $username, ':e' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;

        // Block bootstrap passwords (force reset)
        $hash = (string)($row['password_hash'] ?? '');
        $blocked = ['!attendance-wage-bootstrap-password-reset-required!'];
        foreach ($blocked as $b) {
            if ($hash === $b || password_verify($b, $hash)) return null;
        }

        if (!password_verify($password, $hash)) return null;

        return [
            'user' => [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'employee'),
                'sub' => 'attendance-wage:' . (int)($row['id'] ?? 0),
            ],
            'source' => 'attendance-wage',
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function aw_tenant_id(): int
{
    $tenantId = \app()->tenant()->current();
    if ($tenantId === null || $tenantId <= 0) {
        $tenantId = \app()->tenant()->resolve(\app()->user());
    }

    return (int)($tenantId ?? 0);
}

function aw_db(): \PDO
{
    $tenantId = aw_tenant_id();
    $db = $tenantId > 0 ? \app()->dbForTenant($tenantId) : \app()->db();
    if (!$db instanceof \PDO) {
        throw new \RuntimeException('Attendance & Wage tenant database is unavailable.');
    }

    return $db;
}

function aw_allowedSort(mixed $payload, string $default, array $allowed): string
{
    $field = (string)($payload['sort']['field'] ?? $default);
    return in_array($field, $allowed, true) ? $field : $default;
}

function aw_sortDir(mixed $payload): string
{
    $dir = strtoupper((string)($payload['sort']['direction'] ?? 'DESC'));
    return in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'DESC';
}

// ── Entity LIST handlers ──

function aw_cap_entity_list_attendance_record_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 100);
    $sortField = aw_allowedSort($payload, 'clock_in', ['attendance_id', 'clock_in', 'clock_out', 'status']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT ar.attendance_id AS id, u.full_name AS employee_name, COALESCE(ar.location_in, '—') AS store_name, ar.clock_in, ar.clock_out, ar.status, ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, ar.clock_out) / 60, 1) AS hours, ar.created_at FROM attendance_records ar JOIN attendance_wage_users u ON u.id = ar.user_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_employee_profile_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $qualifier = (string)($payload['qualifier'] ?? '');
    $activeWhere = $qualifier === 'deactivated' ? 'is_active = 0' : 'is_active = 1';

    return \Ikabud\Kernel\EntityContext\EntityListQuery::run(
        aw_db(),
        'employee_profiles',
        [
            'id'                => 'profile_id',
            'first_name'        => 'first_name',
            'last_name'         => 'last_name',
            'name'              => "CONCAT_WS(' ', first_name, middle_name, last_name, suffix)",
            'employee_number'   => 'employee_number',
            'position'          => 'position',
            'department'        => 'department',
            'salary_type'       => 'salary_type',
            'basic_salary'      => 'basic_salary',
            'hourly_rate'       => 'hourly_rate',
            'daily_rate'        => "CASE WHEN salary_type = 'daily' THEN basic_salary ELSE 0 END",
            'employment_status' => 'employment_status',
            'account_status'    => "CASE WHEN is_active = 1 THEN 'active' ELSE 'deactivated' END",
            'hire_date'         => 'hire_date',
        ],
        $payload,
        $activeWhere
    );
}

function aw_cap_entity_list_payroll_period_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 12), 50);
    $sortField = aw_allowedSort($payload, 'start_date', ['period_id', 'period_name', 'start_date', 'end_date', 'status', 'total_gross', 'total_net_pay']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query(
            "SELECT pp.period_id AS id, pp.period_name, pp.period_type,
                    pp.start_date, pp.end_date, pp.pay_date, pp.status,
                    COALESCE(pp.total_gross_pay, 0) AS total_gross,
                    COALESCE(pp.total_net_pay, 0) AS total_net_pay,
                    (SELECT COUNT(*) FROM salary_computations sc WHERE sc.payroll_period_id = pp.period_id) AS comp_count
             FROM payroll_periods pp
             ORDER BY {$sortField} {$sortDir}
             LIMIT {$limit}"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM payroll_periods')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_salary_computation_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 25), 100);
    $qualifier = (string)($payload['qualifier'] ?? '');
    $sortField = aw_allowedSort($payload, 'created_at', ['computation_id', 'gross_pay', 'net_pay', 'status', 'created_at', 'employee_name']);
    $sortDir = aw_sortDir($payload);

    // Resolve period_id from qualifier (e.g. "by_period" reads from GET) or payload filters
    $periodId = 0;
    if (is_array($payload['filters'] ?? null) && isset($payload['filters']['period_id'])) {
        $periodId = (int)$payload['filters']['period_id'];
    } elseif ($qualifier === 'by_period' || $qualifier === '') {
        $periodId = (int)($_GET['period_id'] ?? 0);
    }

    try {
        $db = aw_db();
        $where = '';
        $params = [];
        if ($periodId > 0) {
            $where = 'WHERE sc.payroll_period_id = :pid';
            $params[':pid'] = $periodId;
        }

        $stmt = $db->prepare(
            "SELECT sc.computation_id AS id, sc.user_id, sc.payroll_period_id,
                    CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.department, ep.salary_type,
                    sc.gross_pay, sc.total_additions, sc.total_deductions, sc.other_deductions,
                    sc.net_pay, sc.status, sc.created_at
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             {$where}
             ORDER BY {$sortField} {$sortDir}
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $countSql = 'SELECT COUNT(*) FROM salary_computations sc'
            . ($periodId > 0 ? ' WHERE sc.payroll_period_id = ' . (int)$periodId : '');
        $total = (int)$db->query($countSql)->fetchColumn();

        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_salary_adjustment_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'effective_date', ['adjustment_id', 'adjustment_type', 'amount', 'status', 'effective_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT sa.adjustment_id AS id, u.full_name AS employee_name, sa.adjustment_type, sa.amount, sa.description, sa.status, sa.effective_date, sa.approval_date, sa.applied_date FROM salary_adjustments sa JOIN attendance_wage_users u ON u.id = sa.user_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM salary_adjustments')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_employee_deduction_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'total_amount', ['employee_name', 'total_amount', 'deduction_count']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        // Group all deduction line items by employee name with totals
        $sql = "
            SELECT employee_name,
                   COUNT(*) AS deduction_count,
                   SUM(amount) AS total_amount,
                   GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ', ') AS deduction_types
            FROM (
                SELECT employee_deductions.employee_name, employee_deductions.amount, 'manual' AS source
                FROM employee_deductions
                UNION ALL
                SELECT CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) AS employee_name,
                       cash_advance_repayments.amount, 'cash_advance' AS source
                FROM cash_advance_repayments
                JOIN cash_advances ON cash_advances.advance_id = cash_advance_repayments.advance_id
                LEFT JOIN employee_profiles ON employee_profiles.profile_id = cash_advances.employee_profile_id
                UNION ALL
                SELECT CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) AS employee_name,
                       salary_computations.sss_employee AS amount, 'sss' AS source
                FROM salary_computations
                LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
                WHERE salary_computations.sss_employee > 0 AND employee_profiles.sss_applicable = 1
                UNION ALL
                SELECT CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) AS employee_name,
                       salary_computations.philhealth_employee AS amount, 'philhealth' AS source
                FROM salary_computations
                LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
                WHERE salary_computations.philhealth_employee > 0 AND employee_profiles.philhealth_applicable = 1
                UNION ALL
                SELECT CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) AS employee_name,
                       salary_computations.pagibig_employee AS amount, 'pagibig' AS source
                FROM salary_computations
                LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
                WHERE salary_computations.pagibig_employee > 0 AND employee_profiles.pagibig_applicable = 1
            ) t
            GROUP BY employee_name
            ORDER BY {$sortField} {$sortDir} LIMIT {$limit}
        ";
        $stmt = $db->query($sql);
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query("SELECT COUNT(*) FROM (SELECT 1 FROM employee_deductions UNION ALL SELECT 1 FROM cash_advance_repayments UNION ALL SELECT 1 FROM salary_computations sc1 LEFT JOIN employee_profiles ep1 ON ep1.profile_id = sc1.employee_profile_id WHERE sc1.sss_employee>0 AND ep1.sss_applicable=1 UNION ALL SELECT 1 FROM salary_computations sc2 LEFT JOIN employee_profiles ep2 ON ep2.profile_id = sc2.employee_profile_id WHERE sc2.philhealth_employee>0 AND ep2.philhealth_applicable=1 UNION ALL SELECT 1 FROM salary_computations sc3 LEFT JOIN employee_profiles ep3 ON ep3.profile_id = sc3.employee_profile_id WHERE sc3.pagibig_employee>0 AND ep3.pagibig_applicable=1) t")->fetchColumn());
        // Use employee_name as id for action URL interpolation (URL-encoded for spaces/special chars)
        $rows = array_map(fn($r) => ['id' => rawurlencode($r['employee_name'])] + $r, $rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_holiday_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 50);
    try {
        $db = aw_db();
        $year = date('Y');
        $stmt = $db->query("SELECT holiday_id AS id, holiday_name, holiday_date, holiday_type, pay_multiplier, is_recurring, is_active FROM holidays WHERE (YEAR(holiday_date) = {$year} OR is_recurring = 1) AND is_active = 1 ORDER BY holiday_date ASC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM holidays WHERE is_active = 1')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_cash_advance_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'request_date', ['advance_id', 'amount', 'balance', 'status', 'request_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT ca.advance_id AS id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, ca.amount, ca.balance, ca.repayment_type, ca.status, ca.request_date, ca.approved_at FROM cash_advances ca LEFT JOIN employee_profiles ep ON ep.profile_id = ca.employee_profile_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM cash_advances')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_employee_schedule_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 100);
    $sortField = aw_allowedSort($payload, 'last_name', ['id', 'employee_name', 'shift_type']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        // Aggregate schedules per employee: collect days into CSV and compute min/max times
        $stmt = $db->query(
            "SELECT ep.profile_id AS id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.department,
                    GROUP_CONCAT(DISTINCT CONCAT(UCASE(LEFT(es.day_of_week,1)), SUBSTRING(es.day_of_week,2,2)) ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday') SEPARATOR ', ') AS days_label,
                    GROUP_CONCAT(DISTINCT es.day_of_week ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')) AS days_csv,
                    MAX(es.shift_type) AS shift_type,
                    MIN(NULLIF(es.start_time, '')) AS min_start,
                    MAX(NULLIF(es.end_time, '')) AS max_end,
                    SUM(es.is_dayoff) AS dayoff_count,
                    COUNT(*) AS total_days
             FROM employee_schedules es
             JOIN employee_profiles ep ON ep.user_id = es.user_id
             WHERE ep.is_active = 1
             GROUP BY ep.profile_id, ep.first_name, ep.middle_name, ep.last_name, ep.suffix, ep.position, ep.department
             ORDER BY {$sortField} {$sortDir} LIMIT {$limit}"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query(
            "SELECT COUNT(DISTINCT ep.profile_id) FROM employee_profiles ep JOIN employee_schedules es ON es.user_id = ep.user_id WHERE ep.is_active = 1"
        )->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

// ── Entity GET handlers ──

function aw_cap_entity_get_attendance_record_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM attendance_records WHERE attendance_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_employee_profile_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT ep.*, u.full_name AS name FROM employee_profiles ep JOIN attendance_wage_users u ON u.id=ep.user_id WHERE ep.profile_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_payroll_period_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM payroll_periods WHERE period_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_salary_computation_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, pp.period_name FROM salary_computations sc JOIN employee_profiles ep ON ep.profile_id=sc.employee_profile_id JOIN payroll_periods pp ON pp.period_id=sc.payroll_period_id WHERE sc.computation_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_salary_adjustment_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM salary_adjustments WHERE adjustment_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_employee_deduction_1(mixed $payload): array
{
    $id=(int)($payload['id']??0); if($id<=0)return[];
    $db=aw_db();
    // Try manual deduction first
    $s=$db->prepare('SELECT d.*, \'manual\' AS source FROM employee_deductions d WHERE d.deduction_id=:id LIMIT 1');
    $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC);
    if (is_array($r)) return $r;
    // Fallback: check if this is a cash advance repayment
    $s=$db->prepare(
        "SELECT car.repayment_id AS id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                car.amount, CONCAT('Cash Advance #', ca.advance_id, ' — ', ca.repayment_type) AS description,
                IF(car.status='deducted','completed',car.status) AS status, car.created_at AS deduction_date, 'cash_advance' AS source
         FROM cash_advance_repayments car
         JOIN cash_advances ca ON ca.advance_id = car.advance_id
         LEFT JOIN employee_profiles ep ON ep.profile_id = ca.employee_profile_id
         WHERE car.repayment_id = :id LIMIT 1"
    );
    $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC);
    return is_array($r)?$r:[];
}
function aw_cap_entity_get_holiday_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM holidays WHERE holiday_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_cash_advance_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT ca.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name FROM cash_advances ca LEFT JOIN employee_profiles ep ON ep.user_id = ca.user_id WHERE ca.advance_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_employee_schedule_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT es.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, ep.position, ep.department FROM employee_schedules es JOIN employee_profiles ep ON ep.user_id = es.user_id WHERE es.schedule_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }

// ── Team Attendance Summary capability (bridge for PAL mobilization) ──

/**
 * Capability: attendance_wage.team_attendance.summary@1
 *
 * Returns active groups, member attendance rows, per-member wage summary,
 * total hours, total computed wages, and evidence metadata for a team lead.
 *
 * Payload:
 *   - tenant_id (int)       — required; tenant scope
 *   - team_lead_email (string) — required (case-insensitive match against pal_team_lead_email)
 *   - date_from (string)    — required; Y-m-d
 *   - date_to (string)      — required; Y-m-d
 *   - group_id (int|null)   — optional; if provided, only return data for that group
 *
 * Returns:
 *   - groups: array of {group_id, name}
 *   - attendance: array of attendance rows (per getGroupAttendance)
 *   - employee_summary: array keyed by profile_id with {name, salary_type, days_worked, total_hours, computed_salary}
 *   - totals: {total_hours, total_computed_wages, record_count}
 *   - evidence: {group_ids[], date_from, date_to, generated_at, provider: "attendance-wage", version: "1"}
 */
function aw_cap_team_attendance_summary_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $tenantId = (string)($payload['tenant_id'] ?? '');
    $email = trim(strtolower((string)($payload['team_lead_email'] ?? '')));
    $dateFrom = (string)($payload['date_from'] ?? '');
    $dateTo = (string)($payload['date_to'] ?? '');
    $groupId = isset($payload['group_id']) ? (int)$payload['group_id'] : null;

    if ($tenantId === '' || $email === '' || $dateFrom === '' || $dateTo === '') {
        return ['ok' => false, 'error' => 'Missing required parameters: tenant_id, team_lead_email, date_from, date_to.'];
    }

    // Validate date format
    $dFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
    $dTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$dFrom || !$dTo) {
        return ['ok' => false, 'error' => 'Invalid date format. Expected Y-m-d.'];
    }

    try {
        // Use the tenant's own DB for AW data
        $tenantIdInt = (int)$tenantId;
        $db = $tenantIdInt > 0 ? app()->dbForTenant($tenantIdInt) : app()->db();
        if (!$db instanceof \PDO) {
            return ['ok' => false, 'error' => 'Tenant database unavailable.'];
        }

        // Load the group service + salary helpers
        if (!class_exists('AttendanceGroupService')) {
            require_once __DIR__ . '/services/AttendanceGroupService.php';
        }
        if (!function_exists('tl_computeSalary')) {
            require_once __DIR__ . '/handlers/tl-salary-helpers.php';
        }

        $svc = new \AttendanceGroupService($db, $tenantId, 0);

        // Find active groups for this team lead email
        $stmt = $db->prepare("
            SELECT group_id, name, pal_team_lead_email FROM attendance_groups
            WHERE LOWER(pal_team_lead_email) = :email AND tenant_id = :tid AND is_active = 1
        ");
        $stmt->execute([':email' => $email, ':tid' => $tenantId]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // If a specific group_id was requested, filter to only that group
        if ($groupId !== null && $groupId > 0) {
            $groups = array_values(array_filter($groups, fn($g) => (int)$g['group_id'] === $groupId));
        }

        if (empty($groups)) {
            return ['ok' => false, 'error' => 'No active attendance groups found for this team lead email.'];
        }

        // Collect attendance for all groups
        $attendance = [];
        foreach ($groups as $g) {
            $rows = $svc->getGroupAttendance((int)$g['group_id'], $dateFrom, $dateTo);
            // Attach group_name for identification
            foreach ($rows as &$row) {
                $row['group_name'] = $g['name'];
            }
            unset($row);
            $attendance = array_merge($attendance, $rows);
        }

        // Compute per-employee salary summary
        $employeeSummary = [];
        foreach ($attendance as $row) {
            $pid = $row['profile_id'];
            if (!isset($employeeSummary[$pid])) {
                $employeeSummary[$pid] = [
                    'name' => $row['employee_name'],
                    'salary_type' => $row['salary_type'] ?? 'daily',
                    'daily_rate' => tl_effectiveDailyRate($row),
                    'hourly_rate' => (float)($row['hourly_rate'] ?? 0),
                    'total_hours' => 0,
                    'days' => [],
                ];
            }
            $employeeSummary[$pid]['total_hours'] += (float)($row['hours_worked'] ?? 0);
            $d = substr($row['clock_in'] ?? '', 0, 10);
            if ($d !== '') {
                $employeeSummary[$pid]['days'][$d] = true;
            }
        }
        foreach ($employeeSummary as $pid => &$es) {
            $es['days_worked'] = count($es['days']);
            $es['computed_salary'] = tl_computeSalary($es, $dateFrom, $dateTo);
            // Remove internal 'days' map from output
            unset($es['days']);
        }
        unset($es);

        $totalHours = array_sum(array_column($attendance, 'hours_worked'));
        $totalWages = array_sum(array_column($employeeSummary, 'computed_salary'));
        $groupIds = array_column($groups, 'group_id');

        $evidence = [
            'group_ids' => $groupIds,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'provider' => 'attendance-wage',
            'version' => '1',
        ];

        return [
            'ok' => true,
            'groups' => $groups,
            'attendance' => $attendance,
            'employee_summary' => array_values($employeeSummary),
            'totals' => [
                'total_hours' => round($totalHours, 2),
                'total_computed_wages' => round($totalWages, 2),
                'record_count' => count($attendance),
            ],
            'evidence' => $evidence,
        ];
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('aw_cap_team_attendance_summary_1: ' . $e->getMessage(), 'error');
        }
        return ['ok' => false, 'error' => 'Failed to retrieve team attendance summary.'];
    }
}

// ── Core salary computation (ported from CI) ──

/**
 * Get the salary types applicable to a given payroll period type.
 * Returns an array of salary_type values that should be computed in this period.
 */
function aw_salaryTypesForPeriod(string $periodType): array
{
    return match ($periodType) {
        'weekly'      => ['hourly', 'daily'],
        'bi_weekly'   => ['hourly', 'daily'],
        'semi_monthly' => ['monthly', 'fixed', 'daily'],
        'monthly'     => ['monthly', 'fixed'],
        default       => ['monthly', 'fixed', 'daily', 'hourly'],
    };
}

/**
 * Get a human-readable label for which salary types a period covers.
 */
function aw_salaryTypesLabel(string $periodType): string
{
    $types = aw_salaryTypesForPeriod($periodType);
    return implode(', ', array_map('ucfirst', $types));
}

function aw_computeSalary(int $userId, int $periodId, int $computedBy): array
{
    $db = aw_db();
    $s = $db->prepare('SELECT * FROM employee_profiles WHERE user_id=:uid AND is_active=1 LIMIT 1'); $s->execute([':uid'=>$userId]); $profile=$s->fetch(\PDO::FETCH_ASSOC);
    if(!$profile) throw new \RuntimeException('Employee profile not found');
    $s=$db->prepare('SELECT * FROM payroll_periods WHERE period_id=:pid LIMIT 1'); $s->execute([':pid'=>$periodId]); $period=$s->fetch(\PDO::FETCH_ASSOC);
    if(!$period) throw new \RuntimeException('Payroll period not found');

    $salaryType = $profile['salary_type'] ?? 'daily';
    $hours = aw_calculateAttendanceHours($userId, $period['start_date'], $period['end_date'], $profile);
    $hr = aw_effectiveHourlyRate($profile);

    // In the absence of attendance data, assume complete working days for the period
    $daysWorked = $hours['days_worked'] ?? 0;
    $regularHours = $hours['regular_hours'];
    if ($daysWorked <= 0 && ($salaryType === 'daily' || $salaryType === 'hourly')) {
        $daysWorked = aw_workingDaysInPeriod($period['start_date'], $period['end_date']);
        $regularHours = (float)$daysWorked * (float)($profile['max_daily_hours'] ?? 8);
    }

    // Compute base pay according to salary type
    $regPay = match($salaryType) {
        'monthly' => (float)(((float)$profile['monthly_rate'] > 0) ? $profile['monthly_rate'] : $profile['basic_salary']),
        'fixed'   => (float)($profile['basic_salary']),
        'daily'   => (float)(((float)$profile['daily_rate'] > 0) ? $profile['daily_rate'] : $profile['basic_salary']) * max(1, $daysWorked),
        'hourly'  => $regularHours * $hr,
        default   => $regularHours * $hr,
    };

    $otPay    = $hours['overtime_hours'] * $hr * (float)$profile['overtime_rate'];
    $dotPay   = $hours['double_overtime_hours'] * $hr * (float)$profile['double_overtime_rate'];
    $holPay   = $hours['holiday_hours'] * $hr;
    $nsPay    = $hours['night_shift_hours'] * $hr * (float)($profile['night_diff_enabled'] ? $profile['night_diff_rate'] : 0);
    $rdPay    = $hours['rest_day_hours'] * $hr;
    $rdPrem   = $hours['rest_day_hours'] * $hr * ((float)$profile['rest_day_rate'] - 1.0);

    $gross = $regPay + $otPay + $dotPay + $holPay + $nsPay + $rdPay + $rdPrem;
    $benefits = aw_calculateBenefits($gross);
    // Respect applicable flags from the employee profile
    if (empty($profile['sss_applicable'])) { $benefits['sss'] = ['employee' => 0.0, 'employer' => 0.0]; }
    if (empty($profile['philhealth_applicable'])) { $benefits['philhealth'] = ['employee' => 0.0, 'employer' => 0.0]; }
    if (empty($profile['pagibig_applicable'])) { $benefits['pagibig'] = ['employee' => 0.0, 'employer' => 0.0]; }
    $adj = aw_getAdjustmentsForPeriod($userId, $periodId);
    // Auto-compute 13th month accrual (basic salary / 12 per period) if enabled
    $thirteenthMonth = 0;
    if (!empty($profile['thirteenth_month_enabled'])) {
        $thirteenthMonth = round($regPay / 12, 2);
        $adj['additions'] = ($adj['additions'] ?? 0) + $thirteenthMonth;
    }
    $ded = aw_getDeductionsForPeriod($userId, $period['start_date'], $period['end_date']);
    $caDed = aw_getCashAdvanceRepayment($userId, $periodId);
    $gross += ($adj['additions'] ?? 0);

    $totDed = $benefits['sss']['employee'] + $benefits['philhealth']['employee'] + $benefits['pagibig']['employee'] + $ded + $caDed + ($adj['deductions'] ?? 0);
    $tax = aw_calculateTax($gross, $totDed, $profile);
    $totDed += $tax;
    $netPay = $gross - $totDed;
    $erCost = $gross + $benefits['sss']['employer'] + $benefits['philhealth']['employer'] + $benefits['pagibig']['employer'];

    $data = [
        'tenant_id' => app()->tenant()->current() ?? '',
        'user_id' => $userId, 'payroll_period_id' => $periodId, 'employee_profile_id' => $profile['profile_id'],
        'basic_salary' => $profile['basic_salary'], 'regular_hours' => $hours['regular_hours'], 'overtime_hours' => $hours['overtime_hours'],
        'double_overtime_hours' => $hours['double_overtime_hours'], 'holiday_hours' => $hours['holiday_hours'],
        'night_shift_hours' => $hours['night_shift_hours'], 'rest_day_hours' => $hours['rest_day_hours'],
        'regular_pay' => $regPay, 'overtime_pay' => $otPay, 'double_overtime_pay' => $dotPay,
        'holiday_pay' => $holPay, 'night_shift_pay' => $nsPay, 'rest_day_pay' => $rdPay, 'rest_day_premium' => $rdPrem,
        'gross_pay' => $gross,
        'sss_employee' => $benefits['sss']['employee'], 'philhealth_employee' => $benefits['philhealth']['employee'],
        'pagibig_employee' => $benefits['pagibig']['employee'], 'income_tax' => $tax,
        'sss_employer' => $benefits['sss']['employer'], 'philhealth_employer' => $benefits['philhealth']['employer'],
        'pagibig_employer' => $benefits['pagibig']['employer'],
        'salary_deductions' => $ded, 'cash_advance_deduction' => $caDed, 'other_deductions' => ($adj['deductions']??0),
        'total_deductions' => $totDed, 'total_additions' => ($adj['additions']??0),
        'net_pay' => $netPay, 'total_employer_cost' => $erCost,
        'status' => 'computed', 'computed_by' => $computedBy, 'computation_date' => date('Y-m-d H:i:s'),
    ];

    $s = $db->prepare('SELECT computation_id, status FROM salary_computations WHERE employee_profile_id=:eid AND payroll_period_id=:pid LIMIT 1');
    $s->execute([':eid'=>$profile['profile_id'], ':pid'=>$periodId]); $existing = $s->fetch(\PDO::FETCH_ASSOC);
    if ($existing) {
        // Prevent overwriting approved or paid computations
        if (in_array($existing['status'] ?? '', ['approved', 'paid'], true)) {
            throw new \RuntimeException('Cannot recompute an approved or paid salary computation. Create an adjustment or reversal instead.');
        }
        $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($data)));
        $db->prepare("UPDATE salary_computations SET {$sets} WHERE computation_id=:cid")->execute(array_merge($data, [':cid'=>(int)$existing['computation_id']]));
        return array_merge(['ok' => true, 'computation_id'=>(int)$existing['computation_id'], 'gross_pay' => $gross, 'total_deductions' => $totDed, 'net_pay' => $netPay], $data);
    }
    $cols = implode(', ', array_keys($data)); $vals = ':'.implode(', :', array_keys($data));
    $db->prepare("INSERT INTO salary_computations ({$cols}) VALUES ({$vals})")->execute($data);
    return array_merge(['ok' => true, 'computation_id'=>(int)$db->lastInsertId(), 'gross_pay' => $gross, 'total_deductions' => $totDed, 'net_pay' => $netPay], $data);
}

function aw_computeSimpleSalary(array $profile, int $periodId, int $computedBy): array
{
    $db = aw_db();
    $s = $db->prepare('SELECT * FROM payroll_periods WHERE period_id=:pid LIMIT 1');
    $s->execute([':pid'=>$periodId]); $period = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$period) throw new \RuntimeException('Payroll period not found');

    $salaryType = $profile['salary_type'] ?? 'daily';
    $daysInPeriod = aw_workingDaysInPeriod($period['start_date'], $period['end_date']);
    $gross = match ($salaryType) {
        'monthly' => (float)(((float)$profile['monthly_rate'] > 0) ? $profile['monthly_rate'] : $profile['basic_salary']),
        'fixed'   => (float)($profile['basic_salary']),
        'hourly'  => (float)(((float)$profile['hourly_rate'] > 0) ? $profile['hourly_rate'] : $profile['basic_salary']) * 8 * max(1, $daysInPeriod),
        'daily'   => (float)(((float)$profile['daily_rate'] > 0) ? $profile['daily_rate'] : $profile['basic_salary']) * max(1, $daysInPeriod),
        default   => (float)($profile['basic_salary']) * max(1, $daysInPeriod),
    };

    $benefits = aw_calculateBenefits($gross);
    // Respect applicable flags from the employee profile
    if (empty($profile['sss_applicable'])) { $benefits['sss'] = ['employee' => 0.0, 'employer' => 0.0]; }
    if (empty($profile['philhealth_applicable'])) { $benefits['philhealth'] = ['employee' => 0.0, 'employer' => 0.0]; }
    if (empty($profile['pagibig_applicable'])) { $benefits['pagibig'] = ['employee' => 0.0, 'employer' => 0.0]; }
    // Auto-compute 13th month accrual (basic salary / 12 per period) if enabled
    $thirteenthMonth = 0;
    if (!empty($profile['thirteenth_month_enabled'])) {
        $thirteenthMonth = round($gross / 12, 2);
        $gross += $thirteenthMonth;
    }
    $totDed = $benefits['sss']['employee'] + $benefits['philhealth']['employee'] + $benefits['pagibig']['employee'];
    $tax = aw_calculateTax($gross, $totDed, $profile);
    $totDed += $tax;
    $netPay = $gross - $totDed;
    $erCost = $gross + $benefits['sss']['employer'] + $benefits['philhealth']['employer'] + $benefits['pagibig']['employer'];

    $data = [
        'tenant_id' => app()->tenant()->current() ?? '',
        'user_id' => (int)($profile['user_id'] ?? 0) > 0 ? (int)$profile['user_id'] : -(int)$profile['profile_id'], 'payroll_period_id' => $periodId, 'employee_profile_id' => $profile['profile_id'],
        'basic_salary' => (float)($profile['basic_salary'] ?? 0), 'regular_hours' => 0, 'overtime_hours' => 0,
        'double_overtime_hours' => 0, 'holiday_hours' => 0, 'night_shift_hours' => 0, 'rest_day_hours' => 0,
        'regular_pay' => $gross, 'overtime_pay' => 0, 'double_overtime_pay' => 0,
        'holiday_pay' => 0, 'night_shift_pay' => 0, 'rest_day_pay' => 0, 'rest_day_premium' => 0,
        'gross_pay' => $gross,
        'sss_employee' => $benefits['sss']['employee'], 'philhealth_employee' => $benefits['philhealth']['employee'],
        'pagibig_employee' => $benefits['pagibig']['employee'], 'income_tax' => $tax,
        'sss_employer' => $benefits['sss']['employer'], 'philhealth_employer' => $benefits['philhealth']['employer'],
        'pagibig_employer' => $benefits['pagibig']['employer'],
        'salary_deductions' => 0, 'cash_advance_deduction' => 0, 'other_deductions' => 0,
        'total_deductions' => $totDed, 'total_additions' => 0,
        'net_pay' => $netPay, 'total_employer_cost' => $erCost,
        'status' => 'computed', 'computed_by' => $computedBy, 'computation_date' => date('Y-m-d H:i:s'),
    ];

    $s = $db->prepare('SELECT computation_id, status FROM salary_computations WHERE employee_profile_id=:eid AND payroll_period_id=:pid LIMIT 1');
    $s->execute([':eid'=>$profile['profile_id'], ':pid'=>$periodId]); $existing = $s->fetch(\PDO::FETCH_ASSOC);
    if ($existing) {
        // Prevent overwriting approved or paid computations
        if (in_array($existing['status'] ?? '', ['approved', 'paid'], true)) {
            throw new \RuntimeException('Cannot recompute an approved or paid salary computation. Create an adjustment or reversal instead.');
        }
        $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($data)));
        $db->prepare("UPDATE salary_computations SET {$sets} WHERE computation_id=:cid")->execute(array_merge($data, [':cid'=>(int)$existing['computation_id']]));
        return array_merge(['ok' => true, 'computation_id'=>(int)$existing['computation_id'], 'gross_pay' => $gross, 'total_deductions' => $totDed, 'net_pay' => $netPay], $data);
    }
    $cols = implode(', ', array_keys($data)); $vals = ':'.implode(', :', array_keys($data));
    $db->prepare("INSERT INTO salary_computations ({$cols}) VALUES ({$vals})")->execute($data);
    return array_merge(['ok' => true, 'computation_id'=>(int)$db->lastInsertId(), 'gross_pay' => $gross, 'total_deductions' => $totDed, 'net_pay' => $netPay], $data);
}

function aw_calculateAttendanceHours(int $userId, string $startDate, string $endDate, array $profile): array
{
    $db = aw_db();
    $s = $db->prepare("SELECT clock_in, clock_out FROM attendance_records WHERE user_id=:uid AND DATE(clock_in) BETWEEN :start AND :end AND clock_out IS NOT NULL ORDER BY clock_in ASC");
    $s->execute([':uid'=>$userId, ':start'=>$startDate, ':end'=>$endDate]); $records = $s->fetchAll(\PDO::FETCH_ASSOC);

    $reg=0.0;$ot=0.0;$dot=0.0;$hol=0.0;$ns=0.0;$rd=0.0;$weekly=[];
    $maxD=(float)($profile['max_daily_hours']??8);$maxW=(float)($profile['max_weekly_hours']??40);
    $roundTo = (float)(aw_payrollSettings()['round_hours_to'] ?? 0);
    $otOk=(bool)($profile['overtime_allowed']??1);$holOk=(bool)($profile['holiday_pay_enabled']??1);
    $nsOk=(bool)($profile['night_diff_enabled']??1);$rdOk=(bool)($profile['rest_day_pay_enabled']??1);

    foreach($records as $rec){
        $ci=new \DateTime($rec['clock_in']); $co=new \DateTime($rec['clock_out']);
        $th=($co->getTimestamp()-$ci->getTimestamp())/3600.0;
        if ($roundTo > 0) $th = round($th / $roundTo) * $roundTo;
        $dt=$ci->format('Y-m-d'); $wk=$ci->format('Y-W');
        if(!isset($weekly[$wk]))$weekly[$wk]=0.0;
        if(aw_isHoliday($dt)&&$holOk){$hol+=$th;continue;}
        if(aw_isRestDay($userId,$dt,$profile)&&$rdOk){$rd+=$th;continue;}
        if($nsOk)$ns+=aw_nightShiftOverlap($ci,$co);
        $dr=min($th,$maxD);$reg+=$dr;$weekly[$wk]+=$dr;
        if($th>$maxD&&$otOk){
            $dailyOT=$th-$maxD;
            if($th>10){$dot+=($th-10);$dailyOT-=($th-10);}
            $remW=$maxW-$weekly[$wk];
            if($remW<=0){$dot+=$dailyOT;}
            else{$wot=min($dailyOT,$remW);$ot+=$wot;$weekly[$wk]+=$wot;if($dailyOT>$wot)$dot+=($dailyOT-$wot);}
        }
    }
    // Count distinct days with attendance
    $daysWorked = 0;
    $seenDays = [];
    foreach ($records as $rec) {
        $d = substr($rec['clock_in'] ?? '', 0, 10);
        if ($d !== '' && !isset($seenDays[$d])) { $seenDays[$d] = true; $daysWorked++; }
    }
    return ['regular_hours'=>round($reg,2),'overtime_hours'=>round($ot,2),'double_overtime_hours'=>round($dot,2),'holiday_hours'=>round($hol,2),'night_shift_hours'=>round($ns,2),'rest_day_hours'=>round($rd,2),'days_worked'=>$daysWorked];
}

function aw_calculateBenefits(float $grossPay): array
{
    $db = aw_db();
    $tenantId = (string)aw_tenant_id();
    $result = [];

    foreach (['sss', 'philhealth', 'pagibig'] as $type) {
        try {
            $stmt = $db->prepare(
                'SELECT employee_share_pct, employer_share_pct, employee_fixed, employer_fixed, min_contribution, max_contribution '
                . 'FROM benefits_contribution_rates '
                . 'WHERE tenant_id = :tenant_id AND benefit_type = :type AND is_active = 1 '
                . 'AND effective_date <= CURDATE() AND salary_from <= :salary_from '
                . 'AND (salary_to IS NULL OR salary_to >= :salary_to) '
                . 'ORDER BY effective_date DESC LIMIT 1'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':type' => $type,
                ':salary_from' => $grossPay,
                ':salary_to' => $grossPay,
            ]);
            $rate = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($rate)) {
                $result[$type] = aw_defaultBenefitsRate($type, $grossPay);
                continue;
            }

            $minimum = $rate['min_contribution'] === null ? 0.0 : (float)$rate['min_contribution'];
            $maximum = $rate['max_contribution'] === null ? PHP_FLOAT_MAX : (float)$rate['max_contribution'];
            $employee = $rate['employee_fixed'] === null
                ? $grossPay * (float)$rate['employee_share_pct']
                : (float)$rate['employee_fixed'];
            $employer = $rate['employer_fixed'] === null
                ? $grossPay * (float)$rate['employer_share_pct']
                : (float)$rate['employer_fixed'];
            $result[$type] = [
                'employee' => round(max($minimum, min($employee, $maximum)), 2),
                'employer' => round(max($minimum, min($employer, $maximum)), 2),
            ];
        } catch (\Throwable $exception) {
            // Missing contribution schema is supported by statutory fallbacks.
            $result[$type] = aw_defaultBenefitsRate($type, $grossPay);
        }
    }

    return $result;
}

function aw_defaultBenefitsRate(string $type, float $grossPay): array
{
    return match ($type) {
        'sss' => ['employee' => round(max(0, min($grossPay * 0.045, 1350.00)), 2), 'employer' => round(max(0, min($grossPay * 0.095, 2850.00)), 2)],
        'philhealth' => ['employee' => round(max(0, min($grossPay * 0.025, 2500.00) / 2), 2), 'employer' => round(max(0, min($grossPay * 0.025, 2500.00) / 2), 2)],
        'pagibig' => ['employee' => round(max(0, min($grossPay * 0.02, 100.00)), 2), 'employer' => round(max(0, min($grossPay * 0.02, 100.00)), 2)],
        default => ['employee' => 0.0, 'employer' => 0.0],
    };
}

function aw_calculateTax(float $grossPay, float $deductions, array $profile): float
{
    $ag=$grossPay*12; $ad=$deductions*12; $tx=max(0,$ag-$ad); $at=0.0;
    // Additional exemption for head of family: ₱50K per dependent (max 4)
    $depEx = 0;
    if (($profile['tax_exemption_status'] ?? '') === 'head_of_family') {
        $depCnt = min((int)($profile['dependents_count'] ?? 0), 4);
        $depEx = $depCnt * 50000;
    }
    $tx = max(0, $tx - $depEx);
    if($tx<=250000)$at=0; elseif($tx<=400000)$at=($tx-250000)*0.20; elseif($tx<=800000)$at=30000+($tx-400000)*0.25;
    elseif($tx<=2000000)$at=130000+($tx-800000)*0.30; elseif($tx<=8000000)$at=490000+($tx-2000000)*0.32; else $at=2400000+($tx-8000000)*0.35;
    return round($at/12,2);
}

function aw_isHoliday(string $date): ?array { $db=aw_db(); $s=$db->prepare('SELECT * FROM holidays WHERE holiday_date=:d AND is_active=1 LIMIT 1'); $s->execute([':d'=>$date]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r?:null; }
function aw_isRestDay(int $userId, string $date, array $profile): bool {
    $dn=strtolower(date('l',strtotime($date))); $db=aw_db(); $s=$db->prepare('SELECT is_dayoff FROM employee_schedules WHERE user_id=:uid AND day_of_week=:dow LIMIT 1'); $s->execute([':uid'=>$userId,':dow'=>$dn]); $sc=$s->fetch(\PDO::FETCH_ASSOC);
    if($sc) return (bool)($sc['is_dayoff']??false); return $dn===strtolower($profile['rest_day_schedule']??'sunday');
}
function aw_workingDaysInPeriod(string $startDate, string $endDate): int {
    $count=0; $tz=new \DateTimeZone('Asia/Manila');
    $s=new \DateTimeImmutable($startDate,$tz); $e=new \DateTimeImmutable($endDate,$tz); $c=$s;
    while($c<=$e){ if((int)$c->format('N')<7) $count++; $c=$c->modify('+1 day'); }
    return max(1, $count);
}
function aw_nightShiftOverlap(\DateTime $ci, \DateTime $co): float {
    $ns=(clone $ci)->setTime(22,0,0); $ne=(clone $ci)->setTime(6,0,0)->modify('+1 day');
    if($co<=$ns||$ci>=$ne)return 0.0; $os=max($ci,$ns); $oe=min($co,$ne); return ($oe->getTimestamp()-$os->getTimestamp())/3600.0;
}
function aw_effectiveDailyRate(array $row): float
{
    return (float)(((float)($row['daily_rate'] ?? 0)) ?: ((float)($row['basic_salary'] ?? 0)) ?: 0);
}

function aw_effectiveHourlyRate(array $profile): float {
    $settings = aw_payrollSettings();
    $hoursPerDay = (float)($settings['working_hours_per_day'] ?? $profile['max_daily_hours'] ?? 8);
    $daysPerMonth = (int)($settings['working_days_per_month'] ?? 22);
    return match($profile['salary_type']??'daily'){
        'hourly'=>(float)(((float)$profile['hourly_rate'] > 0) ? $profile['hourly_rate'] : $profile['basic_salary']),
        'daily'=>(float)(((float)$profile['daily_rate'] > 0) ? $profile['daily_rate'] : ($profile['basic_salary'] / max(1, $hoursPerDay))),
        'monthly'=>(float)(((float)$profile['monthly_rate'] > 0) ? $profile['monthly_rate'] : ($profile['basic_salary'] / max(1, $daysPerMonth) / max(1, $hoursPerDay))),
        'fixed'=>(float)($profile['basic_salary'] / max(1, $daysPerMonth) / max(1, $hoursPerDay)),
        default=>(float)($profile['basic_salary'] / max(1, $daysPerMonth) / max(1, $hoursPerDay)),
    };
}

function aw_payrollSettings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $db = aw_db();
        $s = $db->prepare('SELECT * FROM payroll_settings WHERE tenant_id = :tid LIMIT 1');
        $s->execute([':tid' => app()->tenant()->current() ?? '']);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        $cache = is_array($row) ? $row : [];

        // Merge module-level settings (timezone, branding) on top
        $moduleSettings = function_exists('getModuleSettings') ? getModuleSettings('attendance-wage') : [];
        $cache['timezone'] = $moduleSettings['timezone'] ?? 'Asia/Manila';

        return $cache;
    } catch (\Throwable $e) { return []; }
}

/**
 * Format a datetime string or timestamp to the configured timezone.
 *
 * @param string|int|null $datetime DateTime string, Unix timestamp, or null (uses now)
 * @param string $format PHP date format (default: 'Y-m-d h:i A')
 * @return string Formatted datetime string
 */
function aw_formatDateTime(string|int|null $datetime = null, string $format = 'Y-m-d h:i A'): string
{
    $settings = aw_payrollSettings();
    $tz = new \DateTimeZone($settings['timezone'] ?? 'Asia/Manila');

    if ($datetime === null) {
        $dt = new \DateTime('now', $tz);
    } elseif (is_numeric($datetime)) {
        $dt = new \DateTime('@' . (int)$datetime);
        $dt->setTimezone($tz);
    } else {
        try {
            $dt = new \DateTime($datetime);
            $dt->setTimezone($tz);
        } catch (\Throwable $e) {
            return (string)$datetime;
        }
    }

    return $dt->format($format);
}

/**
 * Convert a UTC datetime string to the configured timezone and return a DateTime.
 */
function aw_inTimezone(string $utcDatetime): \DateTime
{
    $settings = aw_payrollSettings();
    $tz = new \DateTimeZone($settings['timezone'] ?? 'Asia/Manila');
    $dt = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
    $dt->setTimezone($tz);
    return $dt;
}

/**
 * Normalize tax_exemption_status values for display.
 * Converts 'head_of_family' → 'Head of Family', 'single' → 'Single', etc.
 */
function aw_formatTaxStatus(?string $status): string
{
    return aw_formatLookup('tax_exemption_status', $status);
}

/**
 * Centralized lookup for all enum-like DB values.
 * Converts raw underscored values to human-readable labels.
 * Falls back to ucfirst(str_replace('_', ' ', $value)) for unknown values.
 */
function aw_formatLookup(string $field, ?string $value): string
{
    static $maps = [
        'salary_type' => [
            'hourly' => 'Hourly', 'daily' => 'Daily', 'monthly' => 'Monthly', 'fixed' => 'Fixed',
        ],
        'employment_status' => [
            'regular' => 'Regular', 'probationary' => 'Probationary', 'contractual' => 'Contractual',
            'part_time' => 'Part-Time', 'terminated' => 'Terminated',
        ],
        'period_type' => [
            'weekly' => 'Weekly', 'bi_weekly' => 'Bi-Weekly', 'semi_monthly' => 'Semi-Monthly', 'monthly' => 'Monthly',
        ],
        'repayment_type' => [
            'full_next_payroll' => 'Full (Next Payroll)', 'installment' => 'Installment', 'lumpsum_date' => 'Lump Sum by Date',
        ],
        'holiday_type' => [
            'regular' => 'Regular', 'special_non_working' => 'Special Non-Working', 'special_working' => 'Special Working',
        ],
        'shift_type' => [
            'day' => 'Day', 'night' => 'Night', 'rotating' => 'Rotating', 'fixed' => 'Fixed', 'flexible' => 'Flexible', 'split' => 'Split',
        ],
        'adjustment_type' => [
            'bonus' => 'Bonus', 'allowance' => 'Allowance', 'penalty' => 'Penalty', 'deduction' => 'Deduction',
            'correction' => 'Correction', 'thirteenth_month' => '13th Month', 'holiday_bonus' => 'Holiday Bonus',
        ],
        'tax_exemption_status' => [
            'single' => 'Single', 'married' => 'Married', 'head_of_family' => 'Head of Family',
        ],
    ];
    if ($value === null || $value === '') {
        return '—';
    }
    $map = $maps[$field] ?? [];
    return $map[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

function aw_getAdjustmentsForPeriod(int $userId, int $periodId): array {
    $db=aw_db();
    // Get period date range for NULL-period fallback
    $p = $db->prepare("SELECT start_date, end_date FROM payroll_periods WHERE period_id=:pid LIMIT 1");
    $p->execute([':pid'=>$periodId]); $period = $p->fetch(\PDO::FETCH_ASSOC);
    $start = $period['start_date'] ?? ''; $end = $period['end_date'] ?? '';

    $s=$db->prepare("SELECT
        SUM(CASE WHEN adjustment_type IN('bonus','allowance','thirteenth_month','holiday_bonus') THEN amount ELSE 0 END) AS additions,
        SUM(CASE WHEN adjustment_type IN('penalty','deduction','correction') THEN amount ELSE 0 END) AS deductions
        FROM salary_adjustments
        WHERE user_id=:uid
        AND status IN('approved','applied')
        AND (payroll_period_id=:pid OR (payroll_period_id IS NULL AND effective_date BETWEEN :start AND :end))");
    $s->execute([':uid'=>$userId,':pid'=>$periodId,':start'=>$start,':end'=>$end]);
    $r=$s->fetch(\PDO::FETCH_ASSOC);
    // Mark approved adjustments as applied
    $db->prepare("UPDATE salary_adjustments SET status='applied', applied_date=NOW() WHERE user_id=:uid AND payroll_period_id=:pid AND status='approved'")->execute([':uid'=>$userId,':pid'=>$periodId]);
    return ['additions'=>(float)($r['additions']??0),'deductions'=>(float)($r['deductions']??0)];
}
function aw_getDeductionsForPeriod(int $userId, string $startDate, string $endDate): float {
    $db=aw_db(); $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_deductions WHERE user_id=:uid AND status='processed' AND deduction_date BETWEEN :start AND :end");
    $s->execute([':uid'=>$userId,':start'=>$startDate,':end'=>$endDate]); return (float)$s->fetchColumn();
}
function aw_getCashAdvanceRepayment(int $userId, int $periodId): float {
    $db=aw_db();
    // Match by user_id OR by employee_profile_id (for unlinked employees)
    $s=$db->prepare("SELECT COALESCE(SUM(car.amount),0) FROM cash_advance_repayments car JOIN cash_advances ca ON ca.advance_id=car.advance_id WHERE (ca.user_id=:uid OR (ca.user_id=0 AND ca.employee_profile_id=(SELECT profile_id FROM employee_profiles WHERE user_id=:uid2 LIMIT 1))) AND car.payroll_period_id=:pid AND car.status IN('pending','deducted')");
    $s->execute([':uid'=>$userId,':uid2'=>$userId,':pid'=>$periodId]);
    $amount = (float)$s->fetchColumn();
    // Mark repayments as deducted after computing
    if ($amount > 0) {
        $db->prepare("UPDATE cash_advance_repayments car JOIN cash_advances ca ON ca.advance_id=car.advance_id SET car.status='deducted' WHERE (ca.user_id=:uid OR (ca.user_id=0 AND ca.employee_profile_id=(SELECT profile_id FROM employee_profiles WHERE user_id=:uid2 LIMIT 1))) AND car.payroll_period_id=:pid AND car.status='pending'")->execute([':uid'=>$userId,':uid2'=>$userId,':pid'=>$periodId]);
    }
    return $amount;
}

// ── Office Location entity handlers ──

function aw_cap_entity_list_office_location_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 50);
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $stmt = $db->prepare("SELECT location_id AS id, name, address, latitude, longitude, radius_meters, is_active FROM office_locations WHERE tenant_id = :tid ORDER BY name ASC LIMIT " . (int)$limit);
        $stmt->execute([':tid' => $tid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $cnt = $db->prepare("SELECT COUNT(*) FROM office_locations WHERE tenant_id = :tid");
        $cnt->execute([':tid' => $tid]);
        $total = (int)$cnt->fetchColumn();
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_get_office_location_1(mixed $payload): array
{
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) return [];
    $db = aw_db();
    $tid = app()->tenant()->current() ?? '';
    $s = $db->prepare('SELECT * FROM office_locations WHERE location_id = :id AND tenant_id = :tid LIMIT 1');
    $s->execute([':id' => $id, ':tid' => $tid]);
    $r = $s->fetch(\PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}

// ── Geo-fence helpers ──

/**
 * Calculate the Haversine distance in meters between two lat/lng points.
 */
function aw_haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/**
 * Find the closest active office location to a given coordinate,
 * even if outside the geo-fence. Returns location with distance.
 * Used for tolerance-based matching.
 */
function aw_findClosestLocation(float $latitude, float $longitude): ?array
{
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $stmt = $db->prepare("SELECT * FROM office_locations WHERE tenant_id = :tid AND is_active = 1");
        $stmt->execute([':tid' => $tid]);
        $locations = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $closest = null;
        $closestDist = PHP_FLOAT_MAX;
        foreach ($locations as $loc) {
            $dist = aw_haversineDistance($latitude, $longitude, (float)$loc['latitude'], (float)$loc['longitude']);
            if ($dist < $closestDist) {
                $closestDist = $dist;
                $closest = $loc;
                $closest['distance_meters'] = round($dist, 1);
            }
        }
        return $closest;
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) {
            \write_log('geo_fence: findClosest error ' . $e->getMessage(), 'warning');
        }
        return null;
    }
}

/**
 * Check if a given (lat,lng) falls within any active office location's geo-fence.
 * Returns the matching location row or null.
 */
function aw_findLocationByGeo(float $latitude, float $longitude): ?array
{
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $stmt = $db->prepare("SELECT * FROM office_locations WHERE tenant_id = :tid AND is_active = 1");
        $stmt->execute([':tid' => $tid]);
        $locations = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (\function_exists('write_log')) {
            \write_log('geo_fence: check ' . $latitude . ',' . $longitude . ' against ' . count($locations) . ' locations for tenant ' . ($tid ?: '(none)'), 'info');
        }

        foreach ($locations as $loc) {
            $dist = aw_haversineDistance($latitude, $longitude, (float)$loc['latitude'], (float)$loc['longitude']);
            $radius = (float)($loc['radius_meters'] ?? 100);
            if (\function_exists('write_log')) {
                \write_log('geo_fence: ' . ($loc['name'] ?? '?') . ' dist=' . round($dist, 1) . 'm radius=' . $radius . 'm match=' . ($dist <= $radius ? 'YES' : 'no'), 'info');
            }
            if ($dist <= $radius) {
                $loc['distance_meters'] = round($dist, 1);
                return $loc;
            }
        }
        return null;
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) {
            \write_log('geo_fence: error ' . $e->getMessage(), 'warning');
        }
        return null;
    }
}

/**
 * Module-level CSRF token provider for entity list POST forms.
 * Uses the JWT auth cookie value (Double Submit Cookie pattern)
 * instead of the session-based csrf_token(), avoiding session mismatch.
 *
 * Returns hash_hmac('sha256', cookie, 'csrf') so the raw JWT is never
 * exposed in the form body — only a server-side derivation that requires
 * possession of the original cookie to forge.
 */
function entity_csrf_token(): string
{
    return csrfTokenFromJwt('attendance_wage_token');
}

/**
 * Handle inline hours update for attendance records.
 *
 * Capability: attendance.record.hours.update@1
 * Validates and persists manual hours adjustment.
 */
function aw_cap_attendance_hours_update_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $entityId = (int)($payload['entity_id'] ?? 0);
    $field = (string)($payload['field'] ?? '');
    $value = (string)($payload['value'] ?? '');
    $expectedVersion = isset($payload['expected_version']) ? (int)$payload['expected_version'] : null;

    if ($entityId <= 0 || $field !== 'hours') {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    $hours = (float)$value;
    if ($hours < 0 || $hours > 24) {
        return ['ok' => false, 'error' => 'Hours must be between 0 and 24.'];
    }

    try {
        $db = aw_db();

        $stmt = $db->prepare('SELECT attendance_id, clock_in, clock_out FROM attendance_records WHERE attendance_id = :id');
        $stmt->execute([':id' => $entityId]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($current)) {
            return ['ok' => false, 'error' => 'Attendance record not found.'];
        }

        if ($expectedVersion !== null) {
            $currentVersion = (int)strtotime((string)$current['clock_in']);
            if ($currentVersion !== $expectedVersion) {
                return ['ok' => false, 'error' => 'Modified by another user.', 'code' => 'VERSION_CONFLICT'];
            }
        }

        // Recalculate clock_out from clock_in + new hours
        $clockInTs = strtotime((string)$current['clock_in']);
        if ($clockInTs === false) {
            return ['ok' => false, 'error' => 'Invalid clock_in timestamp.'];
        }
        $newClockOut = date('Y-m-d H:i:s', (int)$clockInTs + (int)($hours * 3600));

        $updateStmt = $db->prepare('UPDATE attendance_records SET clock_out = :clock_out WHERE attendance_id = :id');
        $updateStmt->execute([':clock_out' => $newClockOut, ':id' => $entityId]);

        if (function_exists('app') && ($app = app()) !== null && method_exists($app, 'cap')) {
            try {
                $app->cap()->call('kernel.audit.record@1', [
                    'module' => 'attendance-wage',
                    'action' => 'inline_update',
                    'entity_type' => 'attendance_record',
                    'entity_id' => (string)$entityId,
                    'old_data' => ['hours' => $current['clock_out'] ?? null],
                    'new_data' => ['hours' => $hours],
                ], ['caller' => ['module' => 'attendance-wage'], 'mode' => 'first']);
            } catch (\Throwable $e) {}
        }

        return [
            'ok' => true,
            'data' => [
                'raw_value' => $hours,
                'display_html' => '',
                'version' => (int)strtotime((string)$current['clock_in']),
                'updated_at' => date('c'),
            ],
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
