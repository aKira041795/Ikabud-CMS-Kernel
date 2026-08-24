<?php

declare(strict_types=1);

function palRenderPrintingShell(array $context): void
{
    $settings = palSettings();
    $context['settings'] = $settings;
    $context['pal_app_name'] = $settings['app_name'] ?? 'Project Audit Ledger';
    $context['pal_logo_path'] = $settings['logo_path'] ?? '';
    $context['printing_shell_ctx'] = palBuildPrintingShellContext($context);
    $pageContent = $context['page_content'] ?? '';
    if ($pageContent !== '') {
        $pageTemplate = __DIR__ . '/../templates/project-audit-ledger/pages/' . $pageContent . '.disyl';
        $context['page_body'] = app()->render($pageTemplate, $context);
    }
    echo app()->render(__DIR__ . '/../templates/project-audit-ledger/printing-shell.disyl', $context);
}

function palPrintJobSizeLabel(?float $width, ?float $height, ?string $unit, ?string $fallback = null): ?string
{
    if (($width ?? 0) > 0 && ($height ?? 0) > 0) {
        $label = rtrim(rtrim(number_format((float)$width, 2, '.', ''), '0'), '.');
        $label .= ' x ' . rtrim(rtrim(number_format((float)$height, 2, '.', ''), '0'), '.');
        if ($unit !== null && $unit !== '') {
            $label .= ' ' . $unit;
        }
        return $label;
    }
    return $fallback;
}

function palLoadPrintJob(int $tenantId, int $jobId): ?array
{
    $stmt = palDb()->prepare(
        "SELECT pj.*, cb.full_name AS completed_by_name, cr.full_name AS created_by_name,
                p.title AS project_title, s.invoice_number, s.sales_number, m.name AS material_name
         FROM pal_print_jobs pj
         LEFT JOIN pal_users cb ON cb.id = pj.completed_by
         LEFT JOIN pal_users cr ON cr.id = pj.created_by
         LEFT JOIN pal_projects p ON p.id = pj.project_id AND p.tenant_id = pj.tenant_id
         LEFT JOIN pal_sales s ON s.id = pj.sale_id AND s.tenant_id = pj.tenant_id
         LEFT JOIN pal_materials m ON m.id = pj.material_id AND m.tenant_id = pj.tenant_id
         WHERE pj.tenant_id = :tid AND pj.id = :id
         LIMIT 1"
    );
    $stmt->execute([':tid' => $tenantId, ':id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return null;
    }
    $job['comment_option_label'] = palPrintCommentLabel($job['comment_option_key'] ?? null);
    $job['display_size'] = $job['size_label'] ?: palPrintJobSizeLabel(
        isset($job['width']) ? (float)$job['width'] : null,
        isset($job['height']) ? (float)$job['height'] : null,
        $job['size_unit'] ?? null,
        null
    );
    $job['display_cost'] = number_format((float)($job['cost'] ?? 0), 2);
    return $job;
}

function palFetchPrintJobs(int $tenantId, bool $pendingOnly): array
{
    $sql = "SELECT pj.*, cb.full_name AS completed_by_name, cr.full_name AS created_by_name,
                   p.title AS project_title, COALESCE(s.invoice_number, s.sales_number) AS sale_number,
                   m.name AS material_name
            FROM pal_print_jobs pj
            LEFT JOIN pal_users cb ON cb.id = pj.completed_by
            LEFT JOIN pal_users cr ON cr.id = pj.created_by
            LEFT JOIN pal_projects p ON p.id = pj.project_id AND p.tenant_id = pj.tenant_id
            LEFT JOIN pal_sales s ON s.id = pj.sale_id AND s.tenant_id = pj.tenant_id
            LEFT JOIN pal_materials m ON m.id = pj.material_id AND m.tenant_id = pj.tenant_id
            WHERE pj.tenant_id = :tid";
    if ($pendingOnly) {
        $sql .= " AND pj.status = 'pending'";
    }
    $sql .= ' ORDER BY (pj.status = \'pending\') DESC, pj.created_at DESC, pj.id DESC';
    $stmt = palDb()->prepare($sql);
    $stmt->execute([':tid' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['comment_option_label'] = palPrintCommentLabel($row['comment_option_key'] ?? null);
        $row['display_size'] = $row['size_label'] ?: palPrintJobSizeLabel(
            isset($row['width']) ? (float)$row['width'] : null,
            isset($row['height']) ? (float)$row['height'] : null,
            $row['size_unit'] ?? null,
            null
        );
    }
    unset($row);
    return $rows;
}

function palBuildPrintJobPayload(array $user, array $input): array
{
    $db = palDb();
    $tenantId = (int)($user['tenant_id'] ?? 0);
    $saleItemId = (int)($input['sale_item_id'] ?? 0);
    if ($saleItemId > 0) {
        $stmt = $db->prepare(
            "SELECT si.id, si.sale_id, si.material_id, si.particulars, si.width, si.height, si.uom, si.quantity, si.line_total,
                    s.project_id, COALESCE(s.client_name, c.name) AS client_name, m.name AS material_name
             FROM pal_sale_items si
             INNER JOIN pal_sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
             LEFT JOIN pal_clients c ON c.id = s.client_id AND c.tenant_id = s.tenant_id
             LEFT JOIN pal_materials m ON m.id = si.material_id AND m.tenant_id = si.tenant_id
             WHERE si.tenant_id = :tid AND si.id = :id
             LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $saleItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Sale item not found.');
        }

        return [
            'job_number' => trim((string)($input['job_number'] ?? '')),
            'project_id' => !empty($row['project_id']) ? (int)$row['project_id'] : null,
            'sale_id' => (int)$row['sale_id'],
            'sale_item_id' => (int)$row['id'],
            'material_id' => !empty($row['material_id']) ? (int)$row['material_id'] : null,
            'client_name' => trim((string)($row['client_name'] ?? '')),
            'material_label' => trim((string)($row['material_name'] ?: $row['particulars'] ?: '')),
            'size_label' => palPrintJobSizeLabel(
                isset($row['width']) ? (float)$row['width'] : null,
                isset($row['height']) ? (float)$row['height'] : null,
                $row['uom'] ?? null,
                trim((string)($input['size_label'] ?? '')) ?: null
            ),
            'width' => $row['width'] !== null ? (float)$row['width'] : null,
            'height' => $row['height'] !== null ? (float)$row['height'] : null,
            'size_unit' => $row['uom'] ?: null,
            'quantity' => (float)$row['quantity'],
            'cost' => (float)$row['line_total'],
        ];
    }

    $payload = [
        'job_number' => trim((string)($input['job_number'] ?? '')),
        'project_id' => !empty($input['project_id']) ? (int)$input['project_id'] : null,
        'sale_id' => !empty($input['sale_id']) ? (int)$input['sale_id'] : null,
        'sale_item_id' => null,
        'material_id' => !empty($input['material_id']) ? (int)$input['material_id'] : null,
        'client_name' => trim((string)($input['client_name'] ?? '')),
        'material_label' => trim((string)($input['material_label'] ?? '')),
        'size_label' => trim((string)($input['size_label'] ?? '')) ?: null,
        'width' => ($input['width'] ?? '') !== '' ? (float)$input['width'] : null,
        'height' => ($input['height'] ?? '') !== '' ? (float)$input['height'] : null,
        'size_unit' => trim((string)($input['size_unit'] ?? '')) ?: null,
        'quantity' => (float)($input['quantity'] ?? 1),
        'cost' => (float)($input['cost'] ?? 0),
    ];

    if ($payload['material_id'] !== null && $payload['material_label'] === '') {
        $m = $db->prepare('SELECT name FROM pal_materials WHERE tenant_id = :tid AND id = :id LIMIT 1');
        $m->execute([':tid' => $tenantId, ':id' => $payload['material_id']]);
        $payload['material_label'] = (string)($m->fetchColumn() ?: '');
    }
    if ($payload['size_label'] === null) {
        $payload['size_label'] = palPrintJobSizeLabel($payload['width'], $payload['height'], $payload['size_unit'], null);
    }

    if ($payload['job_number'] === '' || $payload['client_name'] === '' || $payload['material_label'] === '' || $payload['quantity'] <= 0) {
        throw new InvalidArgumentException('Job number, client, material, and quantity are required.');
    }

    return $payload;
}

function palPagePrintingHome(): void
{
    $user = palCurrentUser(['admin', 'supervisor', 'printer']);
    if (($user['role'] ?? '') === 'printer') {
        palRenderPrintingShell([
            'current_user' => $user,
            'page_title' => 'Printing',
            'page_content' => 'printing-jobs-printer',
            'jobs' => palFetchPrintJobs((int)($user['tenant_id'] ?? 0), true),
            'comment_options' => palPrintCommentOptions(),
        ]);
        return;
    }
    app()->redirect('/admin/project-audit-ledger/printing/jobs');
}

function palPagePrintJobList(): void
{
    $user = palCurrentUser(['admin', 'supervisor', 'printer']);
    $tenantId = (int)($user['tenant_id'] ?? 0);
    if (($user['role'] ?? '') === 'printer') {
        palRenderPrintingShell([
            'current_user' => $user,
            'page_title' => 'Pending Print Jobs',
            'page_content' => 'printing-jobs-printer',
            'jobs' => palFetchPrintJobs($tenantId, true),
            'comment_options' => palPrintCommentOptions(),
        ]);
        return;
    }

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title' => 'Print Jobs',
        'page_content' => 'printing-jobs-admin',
        'jobs' => palFetchPrintJobs($tenantId, false),
        'comment_options' => palPrintCommentOptions(),
        'can_create_print_jobs' => ($user['role'] ?? '') === 'admin',
    ]);
}

function palPagePrintJobForm(): void
{
    $user = palRequireRole('admin');
    $tenantId = (int)($user['tenant_id'] ?? 0);
    $db = palDb();

    $projects = $db->prepare('SELECT id, title, job_order_number FROM pal_projects WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 100');
    $projects->execute([':tid' => $tenantId]);
    $sales = $db->prepare("SELECT id, COALESCE(invoice_number, sales_number) AS sale_number, COALESCE(client_name, '') AS client_name FROM pal_sales WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 100");
    $sales->execute([':tid' => $tenantId]);
    $saleItems = $db->prepare(
        "SELECT si.id, COALESCE(s.invoice_number, s.sales_number) AS sale_number, COALESCE(s.client_name, c.name) AS client_name,
                COALESCE(m.name, si.particulars) AS material_label, si.width, si.height, si.uom, si.quantity, si.line_total
         FROM pal_sale_items si
         INNER JOIN pal_sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
         LEFT JOIN pal_clients c ON c.id = s.client_id AND c.tenant_id = s.tenant_id
         LEFT JOIN pal_materials m ON m.id = si.material_id AND m.tenant_id = si.tenant_id
         WHERE si.tenant_id = :tid
         ORDER BY si.id DESC
         LIMIT 100"
    );
    $saleItems->execute([':tid' => $tenantId]);
    $materials = $db->prepare('SELECT id, name FROM pal_materials WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $materials->execute([':tid' => $tenantId]);

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title' => 'Create Print Job',
        'page_content' => 'printing-job-form',
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
        'sales' => $sales->fetchAll(PDO::FETCH_ASSOC),
        'sale_items' => array_map(static function (array $row): array {
            $row['size_label'] = palPrintJobSizeLabel(
                isset($row['width']) ? (float)$row['width'] : null,
                isset($row['height']) ? (float)$row['height'] : null,
                $row['uom'] ?? null,
                null
            );
            return $row;
        }, $saleItems->fetchAll(PDO::FETCH_ASSOC)),
        'materials' => $materials->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palApiPrintCommentOptions(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser(['admin', 'supervisor', 'printer']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'options' => palPrintCommentOptions(), 'role' => $user['role'] ?? '']);
    });
}

function palApiPrintJobStore(): void
{
    palResponseGuard(function (): void {
        $user = palRequireRole('admin');
        palEnforceCsrf();
        $payload = palBuildPrintJobPayload($user, $_POST);
        if ($payload['job_number'] === '') {
            throw new InvalidArgumentException('Job number is required.');
        }

        $db = palDb();
        $stmt = $db->prepare(
            'INSERT INTO pal_print_jobs
             (tenant_id, job_number, project_id, sale_id, sale_item_id, material_id, client_name, material_label, size_label, width, height, size_unit, quantity, cost, created_by)
             VALUES
             (:tenant_id, :job_number, :project_id, :sale_id, :sale_item_id, :material_id, :client_name, :material_label, :size_label, :width, :height, :size_unit, :quantity, :cost, :created_by)'
        );
        $stmt->execute([
            ':tenant_id' => (int)($user['tenant_id'] ?? 0),
            ':job_number' => $payload['job_number'],
            ':project_id' => $payload['project_id'],
            ':sale_id' => $payload['sale_id'],
            ':sale_item_id' => $payload['sale_item_id'],
            ':material_id' => $payload['material_id'],
            ':client_name' => $payload['client_name'],
            ':material_label' => $payload['material_label'],
            ':size_label' => $payload['size_label'],
            ':width' => $payload['width'],
            ':height' => $payload['height'],
            ':size_unit' => $payload['size_unit'],
            ':quantity' => $payload['quantity'],
            ':cost' => $payload['cost'],
            ':created_by' => (int)$user['id'],
        ]);

        $jobId = (int)$db->lastInsertId();
        palAudit('pal.print_job.created', (int)$user['id'], 'pal_print_jobs', (string)$jobId, null, $payload);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $jobId]);
    });
}

function palApiPrintJobComplete(array $routeParams = []): void
{
    palResponseGuard(function () use ($routeParams): void {
        $user = palRequireRole('printer');
        palEnforceCsrf();
        $jobId = (int)($routeParams['id'] ?? 0);
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Invalid print job ID.');
        }
        $tenantId = (int)($user['tenant_id'] ?? 0);
        $existing = palLoadPrintJob($tenantId, $jobId);
        if (!$existing) {
            palJsonError('Print job not found.', 404);
            return;
        }
        $commentOptionKey = trim((string)($_POST['comment_option_key'] ?? '')) ?: null;
        $commentText = trim((string)($_POST['comment_text'] ?? '')) ?: null;
        $stmt = palDb()->prepare(
            "UPDATE pal_print_jobs
             SET status = 'done', comment_option_key = :comment_option_key, comment_text = :comment_text,
                 completed_by = :completed_by, completed_at = NOW()
             WHERE tenant_id = :tid AND id = :id"
        );
        $stmt->execute([
            ':comment_option_key' => $commentOptionKey,
            ':comment_text' => $commentText,
            ':completed_by' => (int)$user['id'],
            ':tid' => $tenantId,
            ':id' => $jobId,
        ]);
        palAudit('pal.print_job.completed', (int)$user['id'], 'pal_print_jobs', (string)$jobId, $existing, [
            'status' => 'done',
            'comment_option_key' => $commentOptionKey,
            'comment_text' => $commentText,
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'job' => palLoadPrintJob($tenantId, $jobId)]);
    });
}

function palApiPrintJobComment(array $routeParams = []): void
{
    palResponseGuard(function () use ($routeParams): void {
        $user = palRequireRole('printer');
        palEnforceCsrf();
        $jobId = (int)($routeParams['id'] ?? 0);
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Invalid print job ID.');
        }
        $tenantId = (int)($user['tenant_id'] ?? 0);
        $existing = palLoadPrintJob($tenantId, $jobId);
        if (!$existing) {
            palJsonError('Print job not found.', 404);
            return;
        }
        $commentOptionKey = trim((string)($_POST['comment_option_key'] ?? '')) ?: null;
        $commentText = trim((string)($_POST['comment_text'] ?? '')) ?: null;
        $stmt = palDb()->prepare(
            'UPDATE pal_print_jobs
             SET comment_option_key = :comment_option_key, comment_text = :comment_text
             WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->execute([
            ':comment_option_key' => $commentOptionKey,
            ':comment_text' => $commentText,
            ':tid' => $tenantId,
            ':id' => $jobId,
        ]);
        palAudit('pal.print_job.comment_updated', (int)$user['id'], 'pal_print_jobs', (string)$jobId, $existing, [
            'comment_option_key' => $commentOptionKey,
            'comment_text' => $commentText,
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'job' => palLoadPrintJob($tenantId, $jobId)]);
    });
}
