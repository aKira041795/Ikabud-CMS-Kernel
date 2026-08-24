<?php

declare(strict_types=1);

// E2E-only state reader. It performs no mutation or verification bypass and
// emits only the requested code to the calling test process (never app logs).
$tenant = getenv('AW_E2E_TENANT_ID') ?: '';
$email = strtolower(trim((string)(getenv('AW_E2E_TEAM_LEAD_EMAIL') ?: '')));
if ($tenant !== '441' || getenv('AW_E2E_ALLOW_RESET') !== '1' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Refusing OTP read: guarded tenant and scoped email are required.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = parse_url(getenv('APP_URL') ?: '', PHP_URL_HOST) ?: '';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$basePath = dirname(__DIR__, 5);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/modules/attendance-wage/helpers.php';
require_once $basePath . '/modules/attendance-wage/handlers/150-team-lead.php';
if (aw_tenant_id() !== 441) {
    fwrite(STDERR, "Tenant scope mismatch.\n");
    exit(2);
}
$stmt = aw_db()->prepare(
    "SELECT o.code_ciphertext FROM attendance_team_lead_otps o
     JOIN attendance_groups g ON g.group_id = o.group_id AND g.tenant_id = o.tenant_id
     WHERE o.tenant_id = :tid AND o.email = :email AND g.pal_team_lead_email = :email2
       AND o.consumed_at IS NULL AND o.expires_at > NOW()
     ORDER BY o.otp_id DESC LIMIT 1"
);
$stmt->execute([':tid' => '441', ':email' => $email, ':email2' => $email]);
$code = tl_otp_decrypt((string)($stmt->fetchColumn() ?: ''));
if ($code === null || !preg_match('/^\d{6}$/', $code)) {
    fwrite(STDERR, "Scoped OTP record unavailable.\n");
    exit(1);
}
echo $code;
