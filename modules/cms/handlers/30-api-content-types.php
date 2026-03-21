<?php

declare(strict_types=1);

function cmsApiContentTypesList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    try {
        $stmt = cmsDb()->query(
            "SELECT id, slug, label, icon, supports, is_active, sort_order, created_at
             FROM cms_content_types
             ORDER BY sort_order ASC, slug ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

function cmsApiContentTypeFieldsList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    $typeId = (int)($params['id'] ?? 0);
    if ($typeId <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    try {
        $stmt = cmsDb()->prepare(
            "SELECT id, content_type_id, field_key, field_type, label, placeholder,
                    options_json, validation_json, sort_order, created_at, updated_at
             FROM cms_field_definitions
             WHERE content_type_id = :id
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':id' => $typeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}

function cmsApiContentTypeUpsert(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    $input = cmsInput();
    $id = (int)($input['id'] ?? 0);
    $slug = trim((string)($input['slug'] ?? ''));
    $label = trim((string)($input['label'] ?? ''));
    $icon = trim((string)($input['icon'] ?? 'file-text'));
    $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

    if ($id <= 0) {
        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'slug is required (lowercase letters, numbers, hyphen)']);
            exit;
        }
    }
    if ($label === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'label is required']);
        exit;
    }

    $db = cmsDb();
    try {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE cms_content_types SET label = :label, icon = :icon, is_active = :a WHERE id = :id");
            $stmt->execute([':label' => $label, ':icon' => $icon, ':a' => $isActive ? 1 : 0, ':id' => $id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO cms_content_types (slug, label, icon, is_active, sort_order, created_at)
             VALUES (:slug, :label, :icon, :a, 100, NOW())"
        );
        $stmt->execute([':slug' => $slug, ':label' => $label, ':icon' => $icon, ':a' => $isActive ? 1 : 0]);
        $newId = (int)$db->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    } catch (Throwable $e) {
        http_response_code(422);
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Content type slug already exists' : 'Failed to save content type';
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}

function cmsApiContentTypeDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $db = cmsDb();
    $stmt = $db->prepare("SELECT slug FROM cms_content_types WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $slug = (string)($stmt->fetchColumn() ?: '');
    if (in_array($slug, ['post', 'page'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Built-in content types cannot be deleted']);
        exit;
    }

    $db->prepare("DELETE FROM cms_content_types WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

function cmsApiFieldDefinitionUpsert(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    $typeId = (int)($params['id'] ?? 0);
    if ($typeId <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $input = cmsInput();
    $id = (int)($input['id'] ?? 0);
    $fieldKey = trim((string)($input['field_key'] ?? ''));
    $fieldType = trim((string)($input['field_type'] ?? 'text'));
    $label = trim((string)($input['label'] ?? ''));
    $placeholder = trim((string)($input['placeholder'] ?? ''));
    $optionsJson = trim((string)($input['options_json'] ?? ''));
    $validationJson = trim((string)($input['validation_json'] ?? ''));

    $allowedTypes = ['text','textarea','number','select','boolean','date','url'];
    if (!in_array($fieldType, $allowedTypes, true)) {
        $fieldType = 'text';
    }
    if ($label === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'label is required']);
        exit;
    }
    if ($id <= 0) {
        if ($fieldKey === '' || !preg_match('/^[a-z0-9_]+$/', $fieldKey)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'field_key is required (lowercase letters, numbers, underscore)']);
            exit;
        }
    }

    $opt = null;
    if ($optionsJson !== '') {
        $d = json_decode($optionsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'options_json must be valid JSON']);
            exit;
        }
        $opt = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $val = null;
    if ($validationJson !== '') {
        $d = json_decode($validationJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'validation_json must be valid JSON']);
            exit;
        }
        $val = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $db = cmsDb();
    try {
        if ($id > 0) {
            $stmt = $db->prepare(
                "UPDATE cms_field_definitions
                 SET field_type = :t, label = :l, placeholder = :p, options_json = :o, validation_json = :v
                 WHERE id = :id AND content_type_id = :ct"
            );
            $stmt->execute([
                ':t' => $fieldType, ':l' => $label, ':p' => ($placeholder !== '' ? $placeholder : null),
                ':o' => $opt, ':v' => $val,
                ':id' => $id, ':ct' => $typeId,
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO cms_field_definitions (content_type_id, field_key, field_type, label, placeholder, options_json, validation_json, sort_order, created_at)
             VALUES (:ct, :k, :t, :l, :p, :o, :v, 100, NOW())"
        );
        $stmt->execute([
            ':ct' => $typeId,
            ':k' => $fieldKey,
            ':t' => $fieldType,
            ':l' => $label,
            ':p' => ($placeholder !== '' ? $placeholder : null),
            ':o' => $opt,
            ':v' => $val,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    } catch (Throwable $e) {
        http_response_code(422);
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'field_key already exists for this content type' : 'Failed to save field';
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}

function cmsApiFieldDefinitionDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('content_types.manage');

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    cmsDb()->prepare("DELETE FROM cms_field_definitions WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}
