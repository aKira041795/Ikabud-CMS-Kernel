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

function portalPatientResults(int $patientId, int $limit = 25): array
{
    $result = app()->cap()->call(
        'ehr.result.list@1',
        ['patient_id' => $patientId, 'limit' => $limit, 'caller_module' => 'patient-portal'],
        ['caller_module' => 'patient-portal']
    );
    return is_array($result) && !empty($result['ok']) && is_array($result['results'] ?? null)
        ? array_values($result['results'])
        : [];
}

function portalPatientPrescriptions(int $patientId, int $limit = 25): array
{
    $result = app()->cap()->call(
        'ehr.prescription.list@1',
        ['patient_id' => $patientId, 'limit' => $limit],
        ['caller_module' => 'patient-portal']
    );
    return is_array($result) && !empty($result['ok']) && is_array($result['prescriptions'] ?? null)
        ? array_values($result['prescriptions'])
        : [];
}

function portalPatientDocuments(int $patientId, int $limit = 25): array
{
    $result = app()->cap()->call(
        'ehr.document.list@1',
        ['patient_id' => $patientId, 'limit' => $limit, 'caller_module' => 'patient-portal'],
        ['caller_module' => 'patient-portal']
    );
    return is_array($result) && !empty($result['ok']) && is_array($result['documents'] ?? null)
        ? array_values($result['documents'])
        : [];
}

function portalActiveConsent(int $patientId, string $consentType = 'general'): ?array
{
    $result = app()->cap()->call(
        'ehr.consent.active@1',
        ['patient_id' => $patientId, 'consent_type' => $consentType],
        ['caller_module' => 'patient-portal']
    );
    if (!is_array($result) || empty($result['ok']) || empty($result['active'])) {
        return null;
    }
    return is_array($result['consent'] ?? null) ? $result['consent'] : null;
}

function portalRecordConsent(int $patientId, string $consentType, string $status, array $scope = []): array
{
    $result = app()->cap()->call(
        'ehr.consent.record@1',
        [
            'patient_id' => $patientId,
            'consent_type' => $consentType,
            'status' => $status,
            'scope' => $scope,
        ],
        ['caller_module' => 'patient-portal']
    );
    return is_array($result) ? $result : ['ok' => false, 'error' => 'Consent record failed'];
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

function patient_portal_cap_ehr_portal_account_update_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $newEmail = portalNormalizeEmail((string)($data['email'] ?? ''));
    $actorId = (int)($data['actor_user_id'] ?? 0);

    $account = portalFetchAccountByPatientId($patientId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account not found'];
    }
    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid email is required'];
    }
    $oldEmail = (string)($account['email'] ?? '');
    if ($newEmail === $oldEmail) {
        unset($account['password_hash']);
        return ['ok' => true, 'account' => $account];
    }
    $existing = portalFetchAccountByEmail($newEmail);
    if (is_array($existing) && (int)($existing['id'] ?? 0) !== (int)$account['id']) {
        return ['ok' => false, 'error' => 'Email already in use by another portal account'];
    }

    portalDb()->execute(
        'UPDATE ehr_portal_accounts SET email = :email, token_version = token_version + 1 WHERE id = :id',
        [':email' => $newEmail, ':id' => (int)$account['id']]
    );

    portalAuditRecord('ehr.portal.account.updated', [
        'patient_id' => $patientId,
        'old_data' => ['email' => $oldEmail],
        'new_data' => ['email' => $newEmail],
        'actor_module_user_id' => $actorId > 0 ? $actorId : null,
    ]);

    $fresh = portalFetchAccountById((int)$account['id']);
    if (is_array($fresh)) {
        unset($fresh['password_hash']);
    }
    return ['ok' => true, 'account' => $fresh];
}

function patient_portal_cap_ehr_portal_account_reset_password_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $newPassword = (string)($data['password'] ?? '');
    $actorId = (int)($data['actor_user_id'] ?? 0);

    $account = portalFetchAccountByPatientId($patientId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account not found'];
    }
    if (strlen($newPassword) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters'];
    }

    portalUpdateAccountPassword((int)$account['id'], $newPassword);

    portalAuditRecord('ehr.portal.account.password_reset_by_admin', [
        'patient_id' => $patientId,
        'actor_module_user_id' => $actorId > 0 ? $actorId : null,
    ]);

    $fresh = portalFetchAccountById((int)$account['id']);
    if (is_array($fresh)) {
        unset($fresh['password_hash']);
    }
    return ['ok' => true, 'account' => $fresh];
}

function patient_portal_cap_ehr_portal_account_reactivate_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $actorId = (int)($data['actor_user_id'] ?? 0);

    $account = portalFetchAccountByPatientId($patientId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account not found'];
    }
    if ((string)($account['status'] ?? '') === 'active') {
        unset($account['password_hash']);
        return ['ok' => true, 'account' => $account];
    }

    portalDb()->execute(
        'UPDATE ehr_portal_accounts SET status = :status, deactivated_at = NULL, deactivation_reason = NULL, token_version = token_version + 1 WHERE id = :id',
        [':status' => 'active', ':id' => (int)$account['id']]
    );

    portalAuditRecord('ehr.portal.account.reactivated', [
        'patient_id' => $patientId,
        'old_data' => ['status' => 'inactive'],
        'new_data' => ['status' => 'active'],
        'actor_module_user_id' => $actorId > 0 ? $actorId : null,
    ]);

    $fresh = portalFetchAccountById((int)$account['id']);
    if (is_array($fresh)) {
        unset($fresh['password_hash']);
    }
    return ['ok' => true, 'account' => $fresh];
}

function portalPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function portalPasswordResetTtlMinutes(): int
{
    return 60;
}

function portalCreatePasswordResetToken(int $accountId, ?string $requesterIp = null): string
{
    $rawToken = bin2hex(random_bytes(32));
    $hash = portalPasswordResetTokenHash($rawToken);
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . portalPasswordResetTtlMinutes() . ' minutes')->format('Y-m-d H:i:s');

    portalDb()->execute(
        'UPDATE ehr_portal_password_resets SET used_at = NOW() WHERE account_id = :account_id AND used_at IS NULL',
        [':account_id' => $accountId]
    );

    portalDb()->execute(
        'INSERT INTO ehr_portal_password_resets (account_id, token_hash, requester_ip, expires_at) VALUES (:account_id, :token_hash, :requester_ip, :expires_at)',
        [
            ':account_id' => $accountId,
            ':token_hash' => $hash,
            ':requester_ip' => $requesterIp,
            ':expires_at' => $expiresAt,
        ]
    );

    return $rawToken;
}

function portalConsumePasswordResetToken(string $rawToken): ?array
{
    $hash = portalPasswordResetTokenHash($rawToken);
    $stmt = portalDb()->prepare(
        'SELECT id, account_id, expires_at, used_at FROM ehr_portal_password_resets WHERE token_hash = :hash LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at'])) {
        return null;
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return null;
    }

    return $row;
}

function portalMarkPasswordResetUsed(int $resetId): void
{
    portalDb()->execute(
        'UPDATE ehr_portal_password_resets SET used_at = NOW() WHERE id = :id',
        [':id' => $resetId]
    );
}

function portalUpdateAccountPassword(int $accountId, string $newPassword): void
{
    portalDb()->execute(
        'UPDATE ehr_portal_accounts SET password_hash = :hash, token_version = token_version + 1, updated_at = NOW() WHERE id = :id',
        [':hash' => portalHashPassword($newPassword), ':id' => $accountId]
    );
}

function portalFindPatientForRegistration(string $email, string $lastName, string $birthDate): ?array
{
    $email = portalNormalizeEmail($email);
    $lastName = trim($lastName);
    $birthDate = trim($birthDate);
    if ($email === '' || $lastName === '' || $birthDate === '') {
        return null;
    }

    $search = app()->cap()->call(
        'ehr.patient.search@1',
        ['q' => $email, 'limit' => 5],
        ['caller_module' => 'patient-portal']
    );
    if (!is_array($search) || empty($search['ok']) || !is_array($search['results'] ?? null)) {
        return null;
    }

    foreach ($search['results'] as $patient) {
        if (!is_array($patient)) {
            continue;
        }
        $patientEmail = portalNormalizeEmail((string)($patient['email'] ?? ''));
        $patientLast = strtolower(trim((string)($patient['last_name'] ?? '')));
        $patientBirth = trim((string)($patient['birth_date'] ?? ''));
        if (
            $patientEmail === $email
            && $patientLast === strtolower($lastName)
            && $patientBirth === $birthDate
        ) {
            return $patient;
        }
    }

    return null;
}

function patient_portal_cap_ehr_portal_reschedule_pending_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $statusFilter = strtolower(trim((string)($data['status'] ?? 'pending')));
    if (!in_array($statusFilter, ['pending', 'handled', 'dismissed', 'all'], true)) {
        $statusFilter = 'pending';
    }
    $appointmentUuids = [];
    if (isset($data['appointment_uuids']) && is_array($data['appointment_uuids'])) {
        foreach ($data['appointment_uuids'] as $u) {
            $u = trim((string)$u);
            if ($u !== '') {
                $appointmentUuids[] = $u;
            }
        }
    }
    $limit = max(1, min(500, (int)($data['limit'] ?? 200)));

    $sql = 'SELECT id, account_id, patient_id, appointment_uuid, appointment_type, scheduled_start,
                   preferred_window, contact_method, reason, status, requester_ip,
                   handled_at, handled_by, created_at
              FROM ehr_portal_reschedule_requests';
    $where = [];
    $params = [];
    if ($statusFilter !== 'all') {
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }
    if (!empty($appointmentUuids)) {
        $placeholders = [];
        foreach ($appointmentUuids as $i => $u) {
            $key = ':uuid' . $i;
            $placeholders[] = $key;
            $params[$key] = $u;
        }
        $where[] = 'appointment_uuid IN (' . implode(',', $placeholders) . ')';
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

    try {
        $stmt = portalDb()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Reschedule lookup failed', 'requests' => []];
    }

    $countByUuid = [];
    foreach ($rows as $r) {
        $u = (string)($r['appointment_uuid'] ?? '');
        if ($u !== '' && (string)$r['status'] === 'pending') {
            $countByUuid[$u] = ($countByUuid[$u] ?? 0) + 1;
        }
    }

    return [
        'ok' => true,
        'requests' => $rows,
        'pending_total' => count(array_filter($rows, static fn($r) => ($r['status'] ?? '') === 'pending')),
        'pending_by_appointment_uuid' => $countByUuid,
    ];
}
