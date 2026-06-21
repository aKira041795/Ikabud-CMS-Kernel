<?php
/**
 * Student status settings handlers.
 *
 * Dedicated admin CRUD for the student_status case field options.
 *
 * @package Guidance\Routes
 */

function studentStatusError(string $message, int $status = 400): void
{
    if (app()->isHtmx()) {
        http_response_code($status);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
        echo '';
        exit;
    }

    app()->json(['error' => $message], $status);
}

function studentStatusDefaultOptions(): array
{
    return ['Active', 'At Risk', 'On Leave', 'Transferred', 'Dropped', 'Graduated'];
}

function normalizeStudentStatusLabel(string $label): string
{
    $label = trim($label);
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

    return trim($label);
}

function normalizeStudentStatusOptions(array $options): array
{
    $normalized = [];
    $seen = [];

    foreach ($options as $option) {
        $label = normalizeStudentStatusLabel((string) $option);
        if ($label === '') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($label, 'UTF-8')
            : strtolower($label);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $label;
    }

    return array_values($normalized);
}

function studentStatusCasesColumnExists(PDO $db): bool
{
    static $exists = [];
    $tid = app()->tenant()->current();

    if (array_key_exists($tid, $exists)) {
        return $exists[$tid];
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) as count
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['gm_cases', 'student_status']);
    $exists[$tid] = ((int) $stmt->fetchColumn()) > 0;

    return $exists[$tid];
}

function ensureStudentStatusField(PDO $db): array
{
    $stmt = $db->prepare('SELECT * FROM gm_form_fields WHERE form_type = ? AND field_name = ? LIMIT 1');
    $stmt->execute(['case', 'student_status']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($field) {
        return $field;
    }

    $defaults = studentStatusDefaultOptions();
    $nextSort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gm_form_fields WHERE form_type = 'case'")->fetchColumn();

    $insert = $db->prepare(
        'INSERT INTO gm_form_fields (
            form_type, field_name, field_label, field_type, field_group,
            field_options, placeholder, default_value, is_required, is_enabled,
            is_system, sort_order, grid_column
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'case',
        'student_status',
        'Student Status',
        'select',
        'Student Information',
        json_encode($defaults, JSON_UNESCAPED_UNICODE),
        null,
        $defaults[0],
        0,
        1,
        1,
        max(1, $nextSort),
        'half',
    ]);

    $stmt->execute(['case', 'student_status']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$field) {
        throw new RuntimeException('Failed to initialize student status field');
    }

    return $field;
}

function getStudentStatusConfig(PDO $db): array
{
    $field = ensureStudentStatusField($db);

    $options = [];
    if (!empty($field['field_options'])) {
        $decoded = json_decode((string) $field['field_options'], true);
        if (is_array($decoded)) {
            $options = $decoded;
        } else {
            $options = array_map('trim', explode(',', (string) $field['field_options']));
        }
    }

    $statuses = normalizeStudentStatusOptions($options);
    $default = normalizeStudentStatusLabel((string) ($field['default_value'] ?? ''));
    if ($default === '' || !in_array($default, $statuses, true)) {
        $default = $statuses[0] ?? '';
    }

    $usageCounts = [];
    if (studentStatusCasesColumnExists($db)) {
        $usageStmt = $db->query(
            "SELECT student_status, COUNT(*) AS status_count
             FROM gm_cases
             WHERE student_status IS NOT NULL AND TRIM(student_status) != ''
             GROUP BY student_status"
        );
        foreach ($usageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usageCounts[(string) $row['student_status']] = (int) $row['status_count'];
        }
    }

    $items = [];
    foreach ($statuses as $index => $status) {
        $items[] = [
            'id' => (string) $index,
            'name' => $status,
            'sort_order' => $index + 1,
            'is_default' => $status === $default,
            'in_use_count' => (int) ($usageCounts[$status] ?? 0),
        ];
    }

    return [
        'field' => $field,
        'statuses' => $statuses,
        'default' => $default,
        'items' => $items,
    ];
}

function studentStatusOptionExists(array $statuses, string $label, ?int $excludeIndex = null): bool
{
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($label, 'UTF-8')
        : strtolower($label);

    foreach ($statuses as $index => $status) {
        if ($excludeIndex !== null && $index === $excludeIndex) {
            continue;
        }

        $existing = function_exists('mb_strtolower')
            ? mb_strtolower((string) $status, 'UTF-8')
            : strtolower((string) $status);

        if ($existing === $normalized) {
            return true;
        }
    }

    return false;
}

function moveStudentStatusToPosition(array $statuses, int $index, int $sortOrder): array
{
    if (!isset($statuses[$index])) {
        return array_values($statuses);
    }

    $item = $statuses[$index];
    array_splice($statuses, $index, 1);

    $target = max(0, min(count($statuses), $sortOrder - 1));
    array_splice($statuses, $target, 0, [$item]);

    return array_values($statuses);
}

function saveStudentStatusConfig(PDO $db, array $field, array $statuses, ?string $defaultValue): array
{
    $statuses = normalizeStudentStatusOptions($statuses);
    if (empty($statuses)) {
        studentStatusError('At least one student status is required', 400);
    }

    $defaultValue = normalizeStudentStatusLabel((string) $defaultValue);
    if ($defaultValue === '' || !in_array($defaultValue, $statuses, true)) {
        $defaultValue = $statuses[0];
    }

    $stmt = $db->prepare('UPDATE gm_form_fields SET field_options = ?, default_value = ?, is_enabled = 1 WHERE id = ?');
    $stmt->execute([
        json_encode($statuses, JSON_UNESCAPED_UNICODE),
        $defaultValue,
        $field['id'],
    ]);

    return [
        'statuses' => $statuses,
        'default' => $defaultValue,
    ];
}

function apiGuidanceListStudentStatuses(): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $context = $_GET['context'] ?? '';

    try {
        $config = getStudentStatusConfig($db);
    } catch (Throwable $e) {
        app()->log('Student status list error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to load student statuses', 500);
    }

    if (app()->isHtmx() && $context === 'settings') {
        echo guidanceRender('partials/student-statuses-settings.disyl', [
            'student_statuses' => $config['items'],
            'default_student_status' => $config['default'],
            'next_sort_order' => count($config['items']) + 1,
        ]);
        return;
    }

    app()->json(['success' => true, 'data' => $config['items'], 'default' => $config['default']]);
}

function apiGuidanceCreateStudentStatus(): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $input = app()->input();

    $name = normalizeStudentStatusLabel((string) ($input['name'] ?? ''));
    $sortOrder = max(1, (int) ($input['sort_order'] ?? PHP_INT_MAX));
    $setDefault = !empty($input['is_default']);

    if ($name === '') {
        studentStatusError('Student status name is required', 400);
    }

    try {
        $config = getStudentStatusConfig($db);
        if (studentStatusOptionExists($config['statuses'], $name)) {
            studentStatusError('That student status already exists', 409);
        }

        $statuses = $config['statuses'];
        $target = max(0, min(count($statuses), $sortOrder - 1));
        array_splice($statuses, $target, 0, [$name]);
        $default = $setDefault ? $name : ($config['default'] !== '' ? $config['default'] : $name);

        $db->beginTransaction();
        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status create error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to create student status', 500);
    }

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status added', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true], 201);
}

function apiGuidanceUpdateStudentStatus(string $id): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $input = app()->input();
    $index = (int) $id;

    try {
        $config = getStudentStatusConfig($db);
        if (!isset($config['statuses'][$index])) {
            studentStatusError('Student status not found', 404);
        }

        $oldName = $config['statuses'][$index];
        $name = normalizeStudentStatusLabel((string) ($input['name'] ?? $oldName));
        $sortOrder = array_key_exists('sort_order', $input)
            ? max(1, (int) $input['sort_order'])
            : ($index + 1);
        $setDefault = !empty($input['is_default']);

        if ($name === '') {
            studentStatusError('Student status name is required', 400);
        }

        if (studentStatusOptionExists($config['statuses'], $name, $index)) {
            studentStatusError('That student status already exists', 409);
        }

        $statuses = $config['statuses'];
        $statuses[$index] = $name;
        $statuses = moveStudentStatusToPosition($statuses, $index, $sortOrder);

        $default = $config['default'];
        if ($setDefault || $default === '') {
            $default = $name;
        } elseif ($default === $oldName) {
            $default = $name;
        }

        $db->beginTransaction();

        if (studentStatusCasesColumnExists($db) && $oldName !== $name) {
            $caseStmt = $db->prepare('UPDATE gm_cases SET student_status = ? WHERE student_status = ?');
            $caseStmt->execute([$name, $oldName]);
        }

        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status update error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to update student status', 500);
    }

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status updated', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true]);
}

function apiGuidanceDeleteStudentStatus(string $id): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $index = (int) $id;

    try {
        $config = getStudentStatusConfig($db);
        if (!isset($config['statuses'][$index])) {
            studentStatusError('Student status not found', 404);
        }

        if (count($config['statuses']) <= 1) {
            studentStatusError('At least one student status must remain', 400);
        }

        $name = $config['statuses'][$index];
        if (studentStatusCasesColumnExists($db)) {
            $usageStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE student_status = ?');
            $usageStmt->execute([$name]);
            if ((int) $usageStmt->fetchColumn() > 0) {
                studentStatusError('This student status is already in use. Rename it instead.', 409);
            }
        }

        $statuses = $config['statuses'];
        array_splice($statuses, $index, 1);
        $default = $config['default'] === $name ? ($statuses[0] ?? '') : $config['default'];

        $db->beginTransaction();
        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status delete error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to delete student status', 500);
    }

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status deleted', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true]);
}