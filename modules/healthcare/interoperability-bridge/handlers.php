<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function interopAdminPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    $messages = ibDb()->query(
        'SELECT id, direction, protocol, message_type, patient_id, status, occurred_at FROM ehr_interop_messages ORDER BY occurred_at DESC LIMIT 50'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $maps = ibDb()->query(
        'SELECT * FROM ehr_interop_identifier_map ORDER BY updated_at DESC LIMIT 50'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $context = ehrAdminContext($user, 'ehr_interop_bridge', [
        'page_title' => 'External Systems',
        'messages' => $messages,
        'identifier_maps' => $maps,
        'identifier_endpoint' => '/admin/ehr/interop/identifier',
        'export_endpoint' => '/admin/ehr/interop/export-patient',
    ]);
    echo ehrRender('modules/interoperability-bridge/admin/index.disyl', $context);
}

function interopAdminExportPatient(array $params = []): void
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
    $result = app()->cap()->call('ehr.interop.fhir.patient.export@1', [
        'patient_id' => (int)($input['patient_id'] ?? 0),
    ], ['caller_module' => 'interoperability-bridge']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}

function interopAdminMapIdentifier(array $params = []): void
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
    $result = app()->cap()->call('ehr.interop.identifier.map@1', [
        'local_entity' => (string)($input['local_entity'] ?? ''),
        'local_id' => (int)($input['local_id'] ?? 0),
        'external_system' => (string)($input['external_system'] ?? ''),
        'external_id' => (string)($input['external_id'] ?? ''),
    ], ['caller_module' => 'interoperability-bridge']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}
