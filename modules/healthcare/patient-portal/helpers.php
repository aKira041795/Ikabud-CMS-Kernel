<?php

declare(strict_types=1);

function portalCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('patient-portal');
    if (!$ctx) {
        throw new \RuntimeException('Patient portal module context unavailable');
    }

    return $ctx;
}

function portalDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return portalCtx()->db();
}

function portalCookieName(): string
{
    return 'ipo_portal_session';
}

function portalSessionTtlSeconds(): int
{
    return 1800;
}

function portalNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function portalHashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function portalGenerateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function portalFetchAccountByEmail(string $email): ?array
{
    $normalized = portalNormalizeEmail($email);
    if ($normalized === '') {
        return null;
    }

    $row = portalDb()->query(
        'SELECT * FROM ehr_portal_accounts WHERE email = :email LIMIT 1',
        [':email' => $normalized]
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function portalFetchAccountByPatientId(int $patientId): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $row = portalDb()->query(
        'SELECT * FROM ehr_portal_accounts WHERE patient_id = :pid LIMIT 1',
        [':pid' => $patientId]
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function portalFetchAccountById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $row = portalDb()->query(
        'SELECT * FROM ehr_portal_accounts WHERE id = :id LIMIT 1',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function portalRecordLoginAttempt(string $email, bool $succeeded): void
{
    try {
        portalDb()->execute(
            'INSERT INTO ehr_portal_login_attempts (email, requester_ip, succeeded) VALUES (:email, :ip, :ok)',
            [
                ':email' => portalNormalizeEmail($email),
                ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                ':ok' => $succeeded ? 1 : 0,
            ]
        );
    } catch (\Throwable $e) {
        write_log('portal login attempt log failed: ' . $e->getMessage(), 'warn');
    }
}

function portalRecentFailedAttempts(string $email, int $windowSeconds = 900): int
{
    $normalized = portalNormalizeEmail($email);
    if ($normalized === '') {
        return 0;
    }

    try {
        $row = portalDb()->query(
            'SELECT COUNT(*) AS n FROM ehr_portal_login_attempts WHERE email = :email AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL :win SECOND)',
            [':email' => $normalized, ':win' => $windowSeconds]
        )->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['n'] ?? 0) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function portalSetSessionCookie(string $token): void
{
    setcookie(
        portalCookieName(),
        $token,
        [
            'expires' => time() + portalSessionTtlSeconds(),
            'path' => '/portal',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    $_COOKIE[portalCookieName()] = $token;
}

function portalClearSessionCookie(): void
{
    setcookie(
        portalCookieName(),
        '',
        [
            'expires' => time() - 3600,
            'path' => '/portal',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    unset($_COOKIE[portalCookieName()]);
}

function portalCurrentSession(): ?array
{
    $token = (string)($_COOKIE[portalCookieName()] ?? '');
    if ($token === '') {
        return null;
    }

    try {
        $payload = app()->jwt()->verify($token);
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($payload) || (string)($payload['source'] ?? '') !== 'patient-portal') {
        return null;
    }

    $accountId = (int)($payload['account_id'] ?? 0);
    $patientId = (int)($payload['patient_id'] ?? 0);
    if ($accountId <= 0 || $patientId <= 0) {
        return null;
    }

    $account = portalFetchAccountById($accountId);
    if (!$account || (string)($account['status'] ?? '') !== 'active') {
        return null;
    }

    if ((int)($account['token_version'] ?? 0) !== (int)($payload['token_version'] ?? 0)) {
        return null;
    }

    return [
        'account_id' => $accountId,
        'patient_id' => $patientId,
        'email' => (string)$account['email'],
        'account_uuid' => (string)$account['account_uuid'],
    ];
}

function portalRequireSession(): array
{
    $session = portalCurrentSession();
    if (!$session) {
        app()->redirect('/portal/login');
        exit;
    }

    return $session;
}

function portalIssueSessionToken(array $account): string
{
    $tenantId = app()->tenant()->current();
    $payload = [
        'sub' => 'portal:' . (int)$account['id'],
        'source' => 'patient-portal',
        'account_id' => (int)$account['id'],
        'account_uuid' => (string)$account['account_uuid'],
        'patient_id' => (int)$account['patient_id'],
        'email' => (string)$account['email'],
        'token_version' => (int)($account['token_version'] ?? 0),
    ];
    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }
    return app()->jwt()->generate($payload);
}

function portalPatientSummary(int $patientId): array
{
    $result = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'patient-portal']);
    if (!is_array($result) || empty($result['ok']) || !is_array($result['patient'] ?? null)) {
        return [];
    }

    $patient = $result['patient'];
    return [
        'id' => (int)($patient['id'] ?? 0),
        'patient_uuid' => (string)($patient['patient_uuid'] ?? ''),
        'first_name' => (string)($patient['first_name'] ?? ''),
        'last_name' => (string)($patient['last_name'] ?? ''),
        'birth_date' => (string)($patient['birth_date'] ?? ''),
        'sex' => (string)($patient['sex'] ?? ''),
        'email' => (string)($patient['email'] ?? ''),
        'primary_phone' => (string)($patient['primary_phone'] ?? ''),
    ];
}

function portalPatientAppointments(int $patientId, int $limit = 20): array
{
    $result = app()->cap()->call(
        'ehr.appointment.list@1',
        ['patient_id' => $patientId, 'limit' => max(1, min(50, $limit))],
        ['caller_module' => 'patient-portal']
    );
    if (!is_array($result) || empty($result['ok']) || !is_array($result['appointments'] ?? null)) {
        return [];
    }
    return array_values($result['appointments']);
}

function portalAuditRecord(string $action, array $payload): void
{
    try {
        app()->cap()->call(
            'kernel.audit.record@1',
            array_merge([
                'action' => $action,
                'actor_source' => 'patient-portal',
            ], $payload),
            ['caller_module' => 'patient-portal']
        );
    } catch (\Throwable $e) {
        write_log('portal audit failed: ' . $e->getMessage(), 'warn');
    }
}

function patient_portal_cap_ehr_portal_account_provision_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $email = portalNormalizeEmail((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $provisionedBy = (int)($data['provisioned_by_user_id'] ?? 0);

    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid email is required'];
    }
    if (strlen($password) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters'];
    }

    $patientLookup = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'patient-portal']);
    if (!is_array($patientLookup) || empty($patientLookup['ok'])) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    if (portalFetchAccountByPatientId($patientId)) {
        return ['ok' => false, 'error' => 'Patient already has a portal account'];
    }
    if (portalFetchAccountByEmail($email)) {
        return ['ok' => false, 'error' => 'Email already in use by another portal account'];
    }

    $uuid = portalGenerateUuid();
    portalDb()->execute(
        'INSERT INTO ehr_portal_accounts (account_uuid, patient_id, email, password_hash, status, provisioned_by_user_id) '
        . 'VALUES (:uuid, :pid, :email, :hash, :status, :prov)',
        [
            ':uuid' => $uuid,
            ':pid' => $patientId,
            ':email' => $email,
            ':hash' => portalHashPassword($password),
            ':status' => 'active',
            ':prov' => $provisionedBy > 0 ? $provisionedBy : null,
        ]
    );

    $account = portalFetchAccountByEmail($email);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account creation failed'];
    }

    portalAuditRecord('ehr.portal.account.provisioned', [
        'patient_id' => $patientId,
        'new_data' => ['account_uuid' => $uuid, 'email' => $email],
        'actor_module_user_id' => $provisionedBy > 0 ? $provisionedBy : null,
    ]);

    unset($account['password_hash']);
    return ['ok' => true, 'account' => $account];
}

function patient_portal_cap_ehr_portal_account_deactivate_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    $actorId = (int)($data['actor_user_id'] ?? 0);

    $account = portalFetchAccountByPatientId($patientId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account not found'];
    }
    if ((string)($account['status'] ?? '') === 'inactive') {
        return ['ok' => true, 'account' => $account];
    }

    portalDb()->execute(
        'UPDATE ehr_portal_accounts SET status = :status, deactivated_at = NOW(), deactivation_reason = :reason, token_version = token_version + 1 WHERE id = :id',
        [
            ':status' => 'inactive',
            ':reason' => $reason !== '' ? $reason : null,
            ':id' => (int)$account['id'],
        ]
    );

    portalAuditRecord('ehr.portal.account.deactivated', [
        'patient_id' => $patientId,
        'old_data' => ['status' => 'active'],
        'new_data' => ['status' => 'inactive', 'reason' => $reason],
        'actor_module_user_id' => $actorId > 0 ? $actorId : null,
    ]);

    $fresh = portalFetchAccountById((int)$account['id']);
    if (is_array($fresh)) {
        unset($fresh['password_hash']);
    }
    return ['ok' => true, 'account' => $fresh];
}

function patient_portal_cap_ehr_portal_account_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $accountId = (int)($data['id'] ?? 0);

    $account = $accountId > 0 ? portalFetchAccountById($accountId) : portalFetchAccountByPatientId($patientId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account not found'];
    }

    unset($account['password_hash']);
    return ['ok' => true, 'account' => $account];
}
