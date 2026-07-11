<?php

declare(strict_types=1);

/**
 * Page: Bill of Materials (BOM) view
 * Shows all materials linked to a project via quotations, sales, issuances.
 */
function palPageBillOfMaterials(): void
{
    $u = palCurrentUser(['admin', 'supervisor', 'encoder']);
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);
    $projectId = (int)($_GET['project_id'] ?? 0);

    $projects = $db->prepare("SELECT id, title FROM pal_projects WHERE tenant_id = :tid ORDER BY title");
    $projects->execute([':tid' => $tid]);

    $bom = [];
    $selectedProject = null;

    if ($projectId > 0) {
        $pjStmt = $db->prepare("SELECT id, title, job_order_number FROM pal_projects WHERE id = :id AND tenant_id = :tid");
        $pjStmt->execute([':id' => $projectId, ':tid' => $tid]);
        $selectedProject = $pjStmt->fetch(PDO::FETCH_ASSOC);

        // BOM from quotation items (materials quoted for this project)
        $bom = $db->prepare("
            SELECT 
                m.id AS material_id,
                m.name AS material_name,
                m.material_code,
                mc.name AS category_name,
                qi.particulars,
                qi.width,
                qi.height,
                qi.uom,
                qi.quantity,
                qi.price_per_unit,
                qi.price_per_sqft,
                qi.line_total,
                q.quotation_number AS source_ref,
                'quotation' AS source_type,
                q.created_at AS source_date
            FROM pal_quotation_items qi
            JOIN pal_quotations q ON qi.quotation_id = q.id
            LEFT JOIN pal_materials m ON qi.material_id = m.id
            LEFT JOIN pal_material_categories mc ON m.category_id = mc.id
            WHERE q.project_id = :pid AND q.tenant_id = :tid AND q.status NOT IN ('rejected','expired')
            UNION ALL
            SELECT 
                m.id AS material_id,
                m.name AS material_name,
                m.material_code,
                mc.name AS category_name,
                si.particulars,
                si.width,
                si.height,
                si.uom,
                si.quantity,
                si.price_per_unit,
                si.price_per_sqft,
                si.line_total,
                s.sales_number AS source_ref,
                'sales' AS source_type,
                s.created_at AS source_date
            FROM pal_sale_items si
            JOIN pal_sales s ON si.sale_id = s.id
            LEFT JOIN pal_materials m ON si.material_id = m.id
            LEFT JOIN pal_material_categories mc ON m.category_id = mc.id
            WHERE s.project_id = :pid2 AND s.tenant_id = :tid2 AND s.status NOT IN ('cancelled','voided')
            ORDER BY source_date DESC, material_name ASC
        ");
        $bom->execute([':pid' => $projectId, ':tid' => $tid, ':pid2' => $projectId, ':tid2' => $tid]);
        $bom = $bom->fetchAll(PDO::FETCH_ASSOC);
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Bill of Materials',
        'page_content' => 'bill-of-materials',
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
        'selected_project' => $selectedProject,
        'bom' => $bom,
    ]);
}

/**
 * API: Export BOM as CSV
 */
function palApiBomExport(): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);
    $projectId = (int)($_GET['project_id'] ?? 0);

    if (!$projectId) {
        palJsonError('Project ID required.');
        return;
    }

    $pjStmt = $db->prepare("SELECT title FROM pal_projects WHERE id = :id AND tenant_id = :tid");
    $pjStmt->execute([':id' => $projectId, ':tid' => $tid]);
    $project = $pjStmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) { palJsonError('Project not found.'); return; }

    $bom = $db->prepare("
        SELECT 
            COALESCE(m.name, qi.particulars) AS material_name,
            mc.name AS category_name,
            qi.particulars,
            qi.width, qi.height, qi.uom, qi.quantity,
            qi.price_per_unit, qi.price_per_sqft, qi.line_total,
            q.quotation_number AS source_ref, 'quotation' AS source_type
        FROM pal_quotation_items qi
        JOIN pal_quotations q ON qi.quotation_id = q.id
        LEFT JOIN pal_materials m ON qi.material_id = m.id
        LEFT JOIN pal_material_categories mc ON m.category_id = mc.id
        WHERE q.project_id = :pid AND q.tenant_id = :tid AND q.status NOT IN ('rejected','expired')
        UNION ALL
        SELECT 
            COALESCE(m.name, si.particulars) AS material_name,
            mc.name AS category_name,
            si.particulars,
            si.width, si.height, si.uom, si.quantity,
            si.price_per_unit, si.price_per_sqft, si.line_total,
            s.sales_number AS source_ref, 'sales' AS source_type
        FROM pal_sale_items si
        JOIN pal_sales s ON si.sale_id = s.id
        LEFT JOIN pal_materials m ON si.material_id = m.id
        LEFT JOIN pal_material_categories mc ON m.category_id = mc.id
        WHERE s.project_id = :pid2 AND s.tenant_id = :tid2 AND s.status NOT IN ('cancelled','voided')
        ORDER BY source_type, material_name
    ");
    $bom->execute([':pid' => $projectId, ':tid' => $tid, ':pid2' => $projectId, ':tid2' => $tid]);
    $rows = $bom->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bom-' . sanitizeFilename($project['title']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Material', 'Category', 'Particulars', 'Width', 'Height', 'UOM', 'QTY', 'Price/Unit', 'Price/SqFt', 'Total', 'Source']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['material_name'], $r['category_name'], $r['particulars'],
            $r['width'], $r['height'], $r['uom'], $r['quantity'],
            $r['price_per_unit'], $r['price_per_sqft'], $r['line_total'],
            $r['source_ref'] . ' (' . $r['source_type'] . ')',
        ]);
    }
    fclose($out);
    exit;
}

function sanitizeFilename(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
}
