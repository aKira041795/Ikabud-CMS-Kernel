<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function adtAdminPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $wards = adtDb()->query('SELECT * FROM ehr_wards ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $beds = adtDb()->query(
        'SELECT b.*, w.name AS ward_name FROM ehr_beds b LEFT JOIN ehr_wards w ON w.id = b.ward_id ORDER BY w.name ASC, b.code ASC'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $admissions = adtDb()->query(
        'SELECT a.*, w.name AS ward_name, b.code AS bed_code FROM ehr_admissions a '
        . 'LEFT JOIN ehr_wards w ON w.id = a.ward_id '
        . 'LEFT JOIN ehr_beds b ON b.id = a.bed_id '
        . 'ORDER BY a.admitted_at DESC LIMIT 50'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $context = ehrAdminContext($user, 'ehr_hospital_adt', [
        'page_title' => 'Hospital ADT',
        'wards' => $wards,
        'beds' => $beds,
        'admissions' => $admissions,
        'ward_create_endpoint' => '/admin/ehr/adt/wards',
        'bed_create_endpoint' => '/admin/ehr/adt/beds',
        'admit_endpoint' => '/admin/ehr/adt/admit',
        'transfer_endpoint' => '/admin/ehr/adt/transfer',
        'discharge_endpoint' => '/admin/ehr/adt/discharge',
    ]);

    echo ehrRender('modules/hospital-adt/admin/index.disyl', $context);
}

function adtAdminWardCreate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    ehrRequireAdmin();
    $input = app()->input();
    $code = trim((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $type = trim((string)($input['ward_type'] ?? 'general'));
    $cap = (int)($input['capacity'] ?? 0);

    if ($code === '' || $name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'code and name are required']);
        return;
    }

    try {
        adtDb()->execute(
            'INSERT INTO ehr_wards (code, name, ward_type, capacity) VALUES (:c, :n, :t, :cap)',
            [':c' => $code, ':n' => $name, ':t' => $type, ':cap' => $cap]
        );
    } catch (\Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Could not create ward', 'details' => $e->getMessage()]);
        return;
    }
    echo json_encode(['ok' => true]);
}

function adtAdminBedCreate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    ehrRequireAdmin();
    $input = app()->input();
    $wardId = (int)($input['ward_id'] ?? 0);
    $code = trim((string)($input['code'] ?? ''));
    if ($wardId <= 0 || $code === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'ward_id and code are required']);
        return;
    }
    try {
        adtDb()->execute(
            'INSERT INTO ehr_beds (ward_id, code) VALUES (:w, :c)',
            [':w' => $wardId, ':c' => $code]
        );
    } catch (\Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Could not create bed', 'details' => $e->getMessage()]);
        return;
    }
    echo json_encode(['ok' => true]);
}

function adtAdminAdmit(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();
    $result = app()->cap()->call('ehr.adt.admit@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'ward_id' => (int)($input['ward_id'] ?? 0),
        'bed_id' => (int)($input['bed_id'] ?? 0),
        'attending_user_id' => (int)($user['id'] ?? 0),
        'notes' => (string)($input['notes'] ?? ''),
    ], ['caller_module' => 'hospital-adt']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}

function adtAdminTransfer(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();
    $result = app()->cap()->call('ehr.adt.transfer@1', [
        'admission_id' => (int)($input['admission_id'] ?? 0),
        'to_bed_id' => (int)($input['to_bed_id'] ?? 0),
        'performed_by_user_id' => (int)($user['id'] ?? 0),
        'notes' => (string)($input['notes'] ?? ''),
    ], ['caller_module' => 'hospital-adt']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}

function adtAdminDischarge(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();
    $result = app()->cap()->call('ehr.adt.discharge@1', [
        'admission_id' => (int)($input['admission_id'] ?? 0),
        'discharge_disposition' => (string)($input['discharge_disposition'] ?? 'home'),
        'performed_by_user_id' => (int)($user['id'] ?? 0),
        'notes' => (string)($input['notes'] ?? ''),
    ], ['caller_module' => 'hospital-adt']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}
