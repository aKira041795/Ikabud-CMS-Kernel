<?php

declare(strict_types=1);

// Ensure helpers are loaded (bypasses stale OPcache in handlers.php)
if (!function_exists('aw_db')) {
    require_once __DIR__ . '/../helpers.php';
}
// sendEmail / buildEmailTemplate live in src/helpers/email.php
if (!function_exists('sendEmail')) {
    require_once __DIR__ . '/../../../src/helpers/email.php';
}

/**
 * Team Lead Self-Service — light login flow with email OTP.
 * Team leads are identified by pal_team_lead_email on attendance_groups.
 * After OTP verification, they see their group's attendance (read-only).
 */

// ── Page: Team Lead Login ──

function attendancePageTeamLeadLogin(): void
{
    echo app()->render('modules/attendance-wage/auth/team-lead-login', [
        'page_title' => 'Team Lead — Attendance',
    ]);
}

// ── Signed token helpers (no session dependency for OTP transport) ──

function tl_sign(array $data): string
{
    $payload = json_encode($data);
    $sig = hash_hmac('sha256', $payload, $_ENV['APP_SECRET'] ?? 'ikabud-default-secret');
    return base64_encode($payload . '.' . $sig);
}

function tl_unsign(string $token): ?array
{
    $raw = base64_decode($token, true);
    if ($raw === false) return null;
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $payload = substr($raw, 0, $dot);
    $sig = substr($raw, $dot + 1);
    $expected = hash_hmac('sha256', $payload, $_ENV['APP_SECRET'] ?? 'ikabud-default-secret');
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode($payload, true);
    return is_array($data) ? $data : null;
}

// ── API: Send OTP ──

function attendanceApiTeamLeadSendOtp(): void
{
    $input = awInput();
    $email = trim(strtolower((string)($input['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        awJson(['ok' => false, 'error' => 'A valid email is required.'], 422);
        return;
    }

    // ── Rate limiting (session-based, no DB dependency) ──
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $now = time();
    $window = 900; // 15 minutes

    // Per-email tracking
    $emailKey = 'tl_otp_attempts_' . md5($email);
    $attempts = $_SESSION[$emailKey] ?? [];
    $attempts = array_filter($attempts, fn(int $ts) => ($now - $ts) < $window);
    if (count($attempts) >= 3) {
        awJson(['ok' => false, 'error' => 'Too many attempts. Please wait 15 minutes.'], 429);
        return;
    }
    // Cooldown: 60 seconds between sends for same email
    if (!empty($attempts) && ($now - max($attempts)) < 60) {
        awJson(['ok' => false, 'error' => 'Please wait 60 seconds before requesting another code.'], 429);
        return;
    }

    // Per-IP tracking
    $ipKey = 'tl_otp_ip_' . md5($ip);
    $ipAttempts = $_SESSION[$ipKey] ?? [];
    $ipAttempts = array_filter($ipAttempts, fn(int $ts) => ($now - $ts) < $window);
    if (count($ipAttempts) >= 5) {
        awJson(['ok' => false, 'error' => 'Too many attempts from this IP. Please wait 15 minutes.'], 429);
        return;
    }

    try {
        $db = aw_db();
        $tenantId = aw_tenant_id();

        // Check if any active group maps to this email
        $stmt = $db->prepare("
            SELECT group_id, name FROM attendance_groups
            WHERE LOWER(pal_team_lead_email) = :email AND tenant_id = :tid AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':email' => $email, ':tid' => $tenantId]);
        $group = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$group) {
            awJson(['ok' => false, 'error' => 'No active attendance group is linked to this email.'], 422);
            return;
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);

        // Send OTP email
        $name = htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8');
        $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Team Lead — ' . $name . '</p>'
            . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Your verification code is:</p>'
            . '<p style="margin:0 0 20px;font-size:32px;font-weight:700;letter-spacing:6px;color:#2563eb;text-align:center;padding:16px;background:#dbeafe;border-radius:8px;">' . $otp . '</p>'
            . '<p style="margin:0 0 16px;color:#9ca3af;font-size:14px;">This code expires in 10 minutes.</p>';
        $body = buildEmailTemplate('ZAP — Team Attendance Verification', $content, '', '');
        if (!sendEmail($email, 'ZAP Attendance — Verification Code: ' . $otp, $body)) {
            throw new RuntimeException('OTP email delivery failed.');
        }

        // Build signed token with OTP hash — no session dependency
        $token = tl_sign([
            'h' => $otpHash,
            'e' => $email,
            'g' => $group['group_id'],
            'n' => $group['name'],
            'exp' => time() + 600,
        ]);

        // Rate-limit tracking only (session is fine for this)
        $_SESSION[$emailKey] = array_merge($attempts, [$now]);
        $_SESSION[$ipKey] = array_merge($ipAttempts, [$now]);

        awJson(['ok' => true, 'token' => $token, 'message' => 'A verification code has been sent to your email.']);
    } catch (\Throwable $e) {
        write_log('attendance-wage team-lead otp-send failed: ' . $e->getMessage(), 'error');
        awJson(['ok' => false, 'error' => 'Unable to send verification code. Please try again.'], 500);
    }
}

// ── API: Verify OTP ──

function attendanceApiTeamLeadVerifyOtp(): void
{
    $input = awInput();
    $code = trim((string)($input['code'] ?? ''));
    $token = (string)($input['token'] ?? '');

    if ($code === '' || $token === '') {
        awJson(['ok' => false, 'error' => 'Invalid request. Please start over.'], 422);
        return;
    }

    $data = tl_unsign($token);
    if ($data === null || ($data['exp'] ?? 0) < time()) {
        awJson(['ok' => false, 'error' => 'Invalid or expired verification. Please request a new code.'], 422);
        return;
    }

    if (!password_verify($code, (string)($data['h'] ?? ''))) {
        awJson(['ok' => false, 'error' => 'Invalid code. Please try again.'], 422);
        return;
    }

    // OTP verified — issue a signed auth token for the dashboard
    $authToken = tl_sign([
        'e' => $data['e'],
        'g' => $data['g'],
        'n' => $data['n'],
        'exp' => time() + 3600,
    ]);

    awJson(['ok' => true, 'redirect' => awBaseUrl() . '/attendance-wage/team-lead/dashboard?t=' . urlencode($authToken)]);
}

// ── Page: Team Lead Dashboard ──

function attendancePageTeamLeadDashboard(): void
{
    $token = $_GET['t'] ?? '';
    $data = $token !== '' ? tl_unsign($token) : null;

    if ($data === null || ($data['exp'] ?? 0) < time()) {
        // Invalid or expired — redirect back to login
        header('Location: ' . awBaseUrl() . '/attendance-wage/team-lead');
        exit;
    }

    $email = $data['e'];
    $groupId = (int)($data['g'] ?? 0);
    $groupName = $data['n'] ?? 'Team';

    if ($groupId <= 0) {
        echo app()->render('modules/attendance-wage/auth/team-lead-login', [
            'page_title' => 'Team Lead — Attendance',
            'error' => 'No group found for your account.',
        ]);
        return;
    }

    $db = aw_db();
    $tenantId = (string)(app()->tenant()->current() ?? '');

    // Parse date range from query params — defaults to current month
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');
    if (!\DateTime::createFromFormat('Y-m-d', $dateFrom)) {
        $dateFrom = date('Y-m-01');
    }
    if (!\DateTime::createFromFormat('Y-m-d', $dateTo)) {
        $dateTo = date('Y-m-t');
    }

    require_once __DIR__ . '/../services/AttendanceGroupService.php';
    $svc = new AttendanceGroupService($db, (string)$tenantId, 0);
    $group = $svc->get($groupId);

    if (!$group) {
        echo app()->render('modules/attendance-wage/auth/team-lead-login', [
            'page_title' => 'Team Lead — Attendance',
            'error' => 'Group not found or has been deactivated.',
        ]);
        return;
    }

    // Fetch attendance via the group service (same bridge query PAL uses)
    $attendance = $svc->getGroupAttendance($groupId, $dateFrom, $dateTo);

    // Use separate file for salary calc — busts stale OPcache
    require_once __DIR__ . '/tl-salary-helpers.php';

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
    }
    unset($es);

    // Issue a kernel delegation token so the team lead can cross into PAL
    // without a second OTP. The token is scoped to this tenant, email, purpose,
    // and expires in 5 minutes.
    $delegationToken = '';
    try {
        $delegationResult = app()->cap()->call('kernel.auth.delegate@1', [
            'from_module' => 'attendance-wage',
            'to_module' => 'project-audit-ledger',
            'identity_email' => $email,
            'tenant_id' => $tenantId,
            'purpose' => 'mobilization',
            'ttl_seconds' => 300,
        ], [
            'caller' => ['module' => 'attendance-wage'],
            'mode' => 'first',
        ]);
        if (is_array($delegationResult) && !empty($delegationResult['ok'])) {
            $delegationToken = $delegationResult['delegation_token'] ?? '';
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('aw_team_lead_dashboard: delegation token issue failed: ' . $e->getMessage(), 'warning');
        }
    }

    echo app()->render('modules/attendance-wage/auth/team-lead-dashboard', [
        'page_title' => 'Team: ' . $groupName,
        'group' => $group,
        'attendance' => $attendance,
        'employee_summary' => $employeeSummary,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'auth_token' => $token,
        'delegation_token' => $delegationToken,
        'pal_mobilize_url' => '/admin/project-audit-ledger/team-lead/mobilization/create'
            . '?attendance_group_id=' . $groupId
            . '&date_from=' . urlencode($dateFrom)
            . '&date_to=' . urlencode($dateTo)
            . ($delegationToken !== '' ? '&_dgt=' . urlencode($delegationToken) : ''),
    ]);
}

// ── Team Lead Logout ──

function attendanceTeamLeadLogout(): void
{
    header('Location: ' . awBaseUrl() . '/attendance-wage/team-lead');
    exit;
}
