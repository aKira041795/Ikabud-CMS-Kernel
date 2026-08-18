<?php

declare(strict_types=1);

// ── OTP Helpers ──

function palOtpTtlSeconds(): int
{
    return 600; // 10 minutes
}

function palOtpSessionTtlHours(): int
{
    try {
        return (int)(palSettings()['tl_session_hours'] ?? 8);
    } catch (Throwable) {
        return 8;
    }
}

function palOtpMaskedEmail(string $email): string
{
    $parts = explode('@', $email);
    $name = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $visible = min(3, (int)ceil(strlen($name) / 2));
    return substr($name, 0, $visible) . str_repeat('*', max(0, strlen($name) - $visible)) . '@' . $domain;
}

function palOtpRateLimitKey(string $action, string $identifier): string
{
    return 'pal_otp_' . $action . '_' . $identifier;
}

function palOtpSendEmail(string $email, string $code, int $ttl): bool
{
    // sendEmail (SMTP) lives in src/helpers/email.php — the PHP mail() transport
    // is not configured on the target host, so OTPs must go through SMTP.
    if (!function_exists('sendEmail')) {
        require_once __DIR__ . '/../../../src/helpers/email.php';
    }

    $subject = 'Your ZAP-ARTS Team Lead Verification Code';
    $minutes = (int)ceil($ttl / 60);
    $body = "
    <html><body style='font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;'>
    <div style='max-width:480px;margin:0 auto;background:#fff;border-radius:8px;padding:30px;border:1px solid #e0e0e0;'>
        <h2 style='margin:0 0 5px;color:#1e293b;'>ZAP-ARTS</h2>
        <p style='color:#64748b;margin:0 0 20px;font-size:13px;'>Signage & Printing Solutions</p>
        <hr style='border:none;border-top:1px solid #e2e8f0;'>
        <p style='color:#334155;font-size:14px;'>Your verification code:</p>
        <div style='text-align:center;margin:20px 0;'>
            <span style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#2563eb;font-family:monospace;'>{$code}</span>
        </div>
        <p style='color:#64748b;font-size:12px;'>This code expires in {$minutes} minutes.</p>
        <p style='color:#94a3b8;font-size:11px;'>If you didn't request this code, ignore this email.</p>
    </div></body></html>";

    try {
        return sendEmail($email, $subject, $body);
    } catch (Throwable) {
        return false;
    }
}

// ── OTP Auth Handlers ──

function palPageTeamLeadLogin(): void
{
    $redirect = $_GET['redirect'] ?? '';
    // Validate that redirect is a local absolute path (no open redirect).
    // Reject: empty, not starting with /, or protocol-relative // prefix.
    if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')) {
        $redirect = '';
    }
    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-login.disyl';
    echo app()->render($t, [
        'page_title' => 'Team Lead Login',
        'redirect' => $redirect,
    ]);
}

function palPageTeamLeadOtpVerify(): void
{
    $ticket = $_GET['ticket'] ?? '';
    if (!$ticket) {
        app()->redirect(external_base_url() . '/project-audit-ledger/team-lead/login');
        return;
    }
    // Read ticket to show masked email
    $ticketData = palOtpReadTicket($ticket, 'team_lead_login');
    if (!$ticketData) {
        echo app()->render(__DIR__ . '/../templates/project-audit-ledger/team-lead-login.disyl', [
            'page_title' => 'Team Lead Login',
            'error' => 'Invalid or expired session. Please request a new code.',
        ]);
        return;
    }
    echo app()->render(__DIR__ . '/../templates/project-audit-ledger/team-lead-otp-verify.disyl', [
        'page_title' => 'Verify Code',
        'ticket' => $ticket,
        'masked_email' => $ticketData['masked_email'] ?? palOtpMaskedEmail($ticketData['email'] ?? ''),
    ]);
}

/**
 * API: Request OTP code (Step 1)
 * POST /api/v1/project-audit-ledger/tl/otp-request
 * Body: { email: "team.lead@example.com" }
 */
function palApiTeamLeadOtpRequest(): void
{
    try {
        $email = trim($_POST['email'] ?? '');
        $redirect = $_POST['redirect'] ?? '';
        // Validate redirect is a local absolute path (no open redirect).
        // Reject: empty, not starting with /, or protocol-relative // prefix.
        if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')) {
            $redirect = '';
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            palJsonError('Invalid email address.');
            return;
        }

        // Look up team lead by email
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT id, name, email FROM pal_team_leads WHERE email = :email AND tenant_id = :tid AND is_active = 1 LIMIT 1");
        $stmt->execute([':email' => $email, ':tid' => $tid]);
        $teamLead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$teamLead) {
            // Don't reveal whether email exists
            palJsonError('Invalid email address.');
            return;
        }

        // Rate limit: max 3 requests per 10 minutes per email
        $rlKey = palOtpRateLimitKey('request', $email);
        $rlOk = palRateLimitCheck($rlKey, 3, 600);
        if (!$rlOk) {
            palJsonError('Too many requests. Please try again later.', 429);
            return;
        }

        // Generate 6-digit code
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = palOtpTtlSeconds();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        // Store in DB
        $stmt = $db->prepare("INSERT INTO pal_otp_codes (email, code, purpose, expires_at) VALUES (:email, :code, 'team_lead_login', :exp)");
        $stmt->execute([':email' => $email, ':code' => $code, ':exp' => $expiresAt]);
        $otpId = (int)$db->lastInsertId();

        // Send email
        $sent = palOtpSendEmail($email, $code, $ttl);
        if (!$sent) {
            // Rollback OTP
            $db->prepare("DELETE FROM pal_otp_codes WHERE id = :id")->execute([':id' => $otpId]);
            palJsonError('Failed to send verification email.');
            return;
        }

        // Create JWT ticket (binds OTP challenge)
        $ticket = palOtpCreateTicket('team_lead_login', [
            'otp_id' => $otpId,
            'email' => $email,
            'team_lead_id' => (int)$teamLead['id'],
            'masked_email' => palOtpMaskedEmail($email),
            'redirect' => $redirect,
        ], $ttl);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'ticket' => $ticket,
            'masked_email' => palOtpMaskedEmail($email),
            'expires_in' => $ttl,
        ]);
    } catch (Throwable $e) {
        palJsonError('An error occurred. Please try again.');
    }
}

/**
 * API: Verify OTP code (Step 2)
 * POST /api/v1/project-audit-ledger/tl/otp-verify
 * Body: { ticket, code }
 */
function palApiTeamLeadOtpVerify(): void
{
    try {
        $ticket = trim($_POST['ticket'] ?? '');
        $code = trim($_POST['code'] ?? '');

        if (!$ticket || !$code) {
            palJsonError('Missing ticket or code.');
            return;
        }

        // Read ticket
        $ticketData = palOtpReadTicket($ticket, 'team_lead_login');
        if (!$ticketData) {
            palJsonError('Session expired. Please request a new code.');
            return;
        }

        $otpId = (int)($ticketData['otp_id'] ?? 0);
        $email = $ticketData['email'] ?? '';

        if (!$otpId || !$email) {
            palJsonError('Invalid session.');
            return;
        }

        // Rate limit: max 5 attempts per OTP
        $db = palDb();
        $stmt = $db->prepare("SELECT * FROM pal_otp_codes WHERE id = :id AND email = :email AND purpose = 'team_lead_login' LIMIT 1");
        $stmt->execute([':id' => $otpId, ':email' => $email]);
        $otp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$otp) {
            palJsonError('Code not found.');
            return;
        }

        if ($otp['verified_at'] !== null) {
            palJsonError('Code already used.');
            return;
        }

        if (strtotime($otp['expires_at']) < time()) {
            palJsonError('Code expired. Please request a new one.');
            return;
        }

        if ((int)$otp['attempts'] >= 5) {
            palJsonError('Too many failed attempts. Please request a new code.');
            return;
        }

        // Increment attempts
        $db->prepare("UPDATE pal_otp_codes SET attempts = attempts + 1 WHERE id = :id")
            ->execute([':id' => $otpId]);

        // Verify code
        if (!hash_equals($otp['code'], $code)) {
            palJsonError('Invalid code.');
            return;
        }

        // Mark as verified
        $db->prepare("UPDATE pal_otp_codes SET verified_at = NOW() WHERE id = :id")
            ->execute([':id' => $otpId]);

        // Create session
        $tlStmt = $db->prepare("SELECT id, name, email FROM pal_team_leads WHERE email = :email AND tenant_id = :tid LIMIT 1");
        $tlStmt->execute([':email' => $email, ':tid' => (int)(app()->tenant()->current() ?? 0)]);
        $teamLead = $tlStmt->fetch(PDO::FETCH_ASSOC);

        // Get associated projects
        $projStmt = $db->prepare("SELECT COUNT(*) FROM pal_projects WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid AND status IN ('pending','approved','started','ongoing')");
        $projStmt->execute([':tlid' => $teamLead['id'], ':tid' => (int)(app()->tenant()->current() ?? 0)]);
        $projectCount = (int)$projStmt->fetchColumn();

        // Store session
        $sessionTtl = palOtpSessionTtlHours() * 3600;
        $session = [
            'sub' => 'tl-' . $teamLead['id'],
            'team_lead_id' => (int)$teamLead['id'],
            'name' => $teamLead['name'],
            'email' => $teamLead['email'],
            'role' => 'team_lead',
            'source' => 'pal-team-lead',
            'project_count' => $projectCount,
            'exp' => time() + $sessionTtl,
        ];

        // Encode JWT (using existing kernel helper)
        $token = app()->jwt()->generate($session);

        // Set cookie
        $cookieName = 'pal_tl_token';
        $cookiePath = '/';
        $cookieDomain = '';
        $secure = !empty($_SERVER['HTTPS']);
        setcookie($cookieName, $token, time() + $sessionTtl, $cookiePath, $cookieDomain, $secure, true);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'redirect' => $ticketData['redirect'] ?: '/admin/project-audit-ledger/team-lead',
            'token' => $token,
        ]);
    } catch (Throwable $e) {
        palJsonError('Verification failed. Please try again.');
    }
}

/**
 * API: Resend OTP code
 * POST /api/v1/project-audit-ledger/tl/otp-resend
 */
function palApiTeamLeadOtpResend(): void
{
    // Read ticket to get email
    $ticket = trim($_POST['ticket'] ?? '');
    $ticketData = palOtpReadTicket($ticket, 'team_lead_login');
    if (!$ticketData) {
        palJsonError('Session expired. Please go back and request a new code.');
        return;
    }

    $email = $ticketData['email'] ?? '';
    if (!$email) {
        palJsonError('Invalid session.');
        return;
    }

    // Rate limit: max 2 resends per email per 5 minutes
    $rlKey = palOtpRateLimitKey('resend', $email);
    $rlOk = palRateLimitCheck($rlKey, 2, 300);
    if (!$rlOk) {
        palJsonError('Too many resend requests. Please try again later.', 429);
        return;
    }

    // Generate new code
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl = palOtpTtlSeconds();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $db = palDb();
    $stmt = $db->prepare("INSERT INTO pal_otp_codes (email, code, purpose, expires_at) VALUES (:email, :code, 'team_lead_login', :exp)");
    $stmt->execute([':email' => $email, ':code' => $code, ':exp' => $expiresAt]);
    $otpId = (int)$db->lastInsertId();

    $sent = palOtpSendEmail($email, $code, $ttl);
    if (!$sent) {
        $db->prepare("DELETE FROM pal_otp_codes WHERE id = :id")->execute([':id' => $otpId]);
        palJsonError('Failed to send email.');
        return;
    }

    // Create new ticket
    $newTicket = palOtpCreateTicket('team_lead_login', [
        'otp_id' => $otpId,
        'email' => $email,
        'team_lead_id' => $ticketData['team_lead_id'] ?? 0,
        'masked_email' => palOtpMaskedEmail($email),
    ], $ttl);

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'ticket' => $newTicket,
        'masked_email' => palOtpMaskedEmail($email),
        'expires_in' => $ttl,
    ]);
}

/**
 * API: Team lead logout
 */
function palApiTeamLeadLogout(): void
{
    setcookie('pal_tl_token', '', time() - 3600, '/');
    app()->redirect(external_base_url() . '/project-audit-ledger/team-lead/login');
}

// ── OTP Utility Functions ──

function palOtpCreateTicket(string $kind, array $payload, int $ttl): string
{
    $jwtPayload = $payload + [
        'kind' => $kind,
        'module' => 'project-audit-ledger',
        'iat' => time(),
        'exp' => time() + $ttl,
    ];
    return app()->jwt()->generate($jwtPayload);
}

function palOtpReadTicket(string $token, string $expectedKind): ?array
{
    try {
        $data = app()->jwt()->verify($token);
        if (!is_array($data)) return null;
        if (($data['kind'] ?? '') !== $expectedKind) return null;
        if (($data['module'] ?? '') !== 'project-audit-ledger') return null;
        return $data;
    } catch (Throwable) {
        return null;
    }
}

function palRateLimitCheck(string $key, int $max, int $windowSeconds): bool
{
    try {
        $cache = app()->cache();
        $key = 'pal_rl_' . $key;
        $current = (int)$cache->get($key);
        if ($current >= $max) return false;
        $cache->set($key, $current + 1, $windowSeconds);
        return true;
    } catch (Throwable) {
        return true; // Fail open if cache unavailable
    }
}

/**
 * Get current team lead from cookie
 */
function palTeamLeadFromCookie(): ?array
{
    $cookieName = 'pal_tl_token';
    $token = $_COOKIE[$cookieName] ?? '';
    if (!$token) return null;

    try {
        $data = app()->jwt()->verify($token);
        if (!is_array($data)) return null;
        if (($data['source'] ?? '') !== 'pal-team-lead') return null;
        if (($data['role'] ?? '') !== 'team_lead') return null;
        if (isset($data['exp']) && $data['exp'] < time()) return null;

        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '') {
            $teamLead = palTeamLeadFromEmail($email);
            if ($teamLead !== null) {
                return $teamLead;
            }
            return null;
        }

        return null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Look up a PAL team lead by email, auto-creating a minimal record
 * if one doesn't exist. Only called after kernel delegation token
 * validation succeeds, so the email identity is trusted.
 *
 * Returns a session-shaped array, or null if the PAL team lead
 * table doesn't exist yet (migration not applied).
 */
function palTeamLeadFromEmail(string $email): ?array
{
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    // Check if pal_team_leads table exists yet
    try {
        $db->query("SELECT 1 FROM pal_team_leads LIMIT 0");
    } catch (\Throwable) {
        return null; // Migration not applied — controlled unavailable
    }

    // Look up existing active team lead (case-insensitive, consistent with AW bridge)
    $stmt = $db->prepare("
        SELECT id, name, email FROM pal_team_leads
        WHERE LOWER(email) = LOWER(:email) AND tenant_id = :tid AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':tid' => $tid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Auto-create if no existing record (lazy provisioning via delegation bridge)
    if (!$row) {
        try {
            // Look up the employee's real name from AW via the shared resolver.
            $displayName = $email;
            try {
                $awTid = function_exists('palResolveAwTenantId') ? palResolveAwTenantId() : $tid;
                $awDb = app()->dbForTenant($awTid);
                if ($awDb && $awDb !== $db) {
                    $awDb->query("SELECT 1 FROM attendance_groups LIMIT 0");
                }
                $awNameStmt = ($awDb ?? $db)->prepare("
                    SELECT CONCAT_WS(' ', NULLIF(ep.first_name,''), NULLIF(ep.last_name,'')) AS full_name
                    FROM attendance_groups ag
                    JOIN employee_profiles ep ON ag.leader_profile_id = ep.profile_id
                        AND ag.tenant_id = ep.tenant_id
                    WHERE LOWER(ag.pal_team_lead_email) = LOWER(:email)
                      AND ag.tenant_id = :awtid
                      AND ag.is_active = 1
                    LIMIT 1
                ");
                $awNameStmt->execute([':email' => $email, ':awtid' => $awTid]);
                $awName = $awNameStmt->fetch(\PDO::FETCH_ASSOC);
                if ($awName && !empty($awName['full_name'])) {
                    $displayName = trim($awName['full_name']);
                }
            } catch (\Throwable) {
                // AW tables may not exist yet — use email as fallback
            }

            $insertStmt = $db->prepare("
                INSERT INTO pal_team_leads (tenant_id, name, email, is_active, created_at)
                VALUES (:tid, :name, :email, 1, NOW())
            ");
            $insertStmt->execute([
                ':tid' => $tid,
                ':name' => $displayName,
                ':email' => $email,
            ]);
            $newId = (int)$db->lastInsertId();

            // Audit the auto-creation
            if (function_exists('palAudit')) {
                palAudit('pal.team_lead.auto_created', 0, 'pal_team_leads', (string)$newId,
                    null, ['email' => $email, 'source' => 'delegation_bridge']);
            }

            $row = [
                'id' => $newId,
                'name' => $displayName,
                'email' => $email,
            ];
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log('palTeamLeadFromEmail: auto-create failed: ' . $e->getMessage(), 'error');
            }
            return null;
        }
    }

    // Count associated projects
    $projStmt = $db->prepare("
        SELECT COUNT(*) FROM pal_projects
        WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid
    ");
    $projStmt->execute([':tlid' => $row['id'], ':tid' => $tid]);

    $sessionTtl = palOtpSessionTtlHours() * 3600;
    $session = [
        'sub' => 'tl-' . $row['id'],
        'team_lead_id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => 'team_lead',
        'source' => 'pal-team-lead',
        'project_count' => (int)$projStmt->fetchColumn(),
        'tenant_id' => $tid,
        'exp' => time() + $sessionTtl,
    ];

    // Issue PAL auth cookie so subsequent requests don't re-delegate
    $token = app()->jwt()->generate($session);
    $cookieName = 'pal_tl_token';
    $cookiePath = '/';
    $cookieDomain = '';
    $secure = !empty($_SERVER['HTTPS']);
    setcookie($cookieName, $token, time() + $sessionTtl, $cookiePath, $cookieDomain, $secure, true);

    return [
        'team_lead_id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => 'team_lead',
        'source' => 'pal-team-lead',
        'tenant_id' => $tid,
    ];
}

function palStripDelegationTokenFromUri(string $uri): string
{
    $parts = parse_url($uri);
    $path = (string)($parts['path'] ?? '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        unset($query['_dgt']);
    }

    $clean = $path;
    if (!empty($query)) {
        $clean .= '?' . http_build_query($query);
    }
    if (!empty($parts['fragment'])) {
        $clean .= '#' . $parts['fragment'];
    }

    return $clean;
}

/**
 * Guard: require team lead session, accept delegation token, redirect to login if absent
 */
function palTeamLeadGuard(): array
{
    // 1. Try PAL cookie first (existing behavior)
    $tl = palTeamLeadFromCookie();
    if ($tl) return $tl;

    // 2. Try kernel delegation token (cross-module identity bridge)
    $delegationToken = $_GET['_dgt'] ?? '';
    if ($delegationToken !== '') {
        try {
            $result = app()->cap()->call('kernel.auth.validate_delegate@1', [
                'delegation_token' => $delegationToken,
                'expected_module' => 'project-audit-ledger',
                'expected_purpose' => 'mobilization',
            ], [
                'caller' => ['module' => 'project-audit-ledger'],
                'mode' => 'first',
            ]);

            if (is_array($result) && !empty($result['valid'])) {
                $email = $result['identity_email'] ?? '';
                $tokenTenantId = (string)($result['tenant_id'] ?? '');
                $currentTenantId = (string)(app()->tenant()->current() ?? '');

                // Accept delegation when:
                //   a) Tenant matches exactly (same-tenant deployment), OR
                //   b) Token tenant is the resolved AW tenant (cross-tenant: AW→PAL)
                $tokenTenantOk = ($tokenTenantId !== '' && $currentTenantId !== '')
                    && ($tokenTenantId === $currentTenantId
                        || (function_exists('palResolveAwTenantId') && (string)palResolveAwTenantId() === $tokenTenantId));

                if ($email !== '' && $tokenTenantOk) {
                    $tl = palTeamLeadFromEmail($email);
                    // Auto-provision if team lead doesn't exist in PAL yet
                    if (!$tl && function_exists('palAutoProvisionTeamLead')) {
                        $tl = palAutoProvisionTeamLead($email);
                    }
                    if ($tl) {
                        // Strip _dgt from the current URL to avoid leaking the token
                        // in browser history / referrer headers on subsequent navigation.
                        $cleanUri = palStripDelegationTokenFromUri($_SERVER['REQUEST_URI'] ?? '/');
                        if ($cleanUri !== ($_SERVER['REQUEST_URI'] ?? '')) {
                            header('Location: ' . $cleanUri);
                            exit;
                        }
                        return $tl;
                    }
                } elseif (function_exists('write_log')) {
                    write_log('pal_team_lead_guard: delegation tenant mismatch or missing identity', 'warning');
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log('pal_team_lead_guard: delegation validation failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    // 3. Redirect to login (existing fallback)
    $base = external_base_url();
    header('Location: ' . $base . '/project-audit-ledger/team-lead/login');
    exit;
}
