<?php

declare(strict_types=1);

function palPageReportsCenter(): void { $u=palCurrentUser(['admin','supervisor']); $db=palDb(); $tid=(int)($u['tenant_id']??0); $exports=$db->prepare("SELECT * FROM pal_report_exports WHERE tenant_id=:tid ORDER BY generated_at DESC LIMIT 20"); $exports->execute([':tid'=>$tid]); $preview=[]; $reportType=$_GET['preview']??''; if($reportType){$preview=palGenerateReportPreview($reportType, $tid);} $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Reports Center', 'page_content'=>'reports-center', 'exports'=>$exports->fetchAll(PDO::FETCH_ASSOC), 'preview_data'=>$preview, 'preview_type'=>$reportType]); }

function palGenerateReportPreview(string $type, int $tid): array
{
    $db = palDb();
    if ($type === 'financial') {
        $e = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM pal_expenses WHERE tenant_id=:tid AND status='approved'"); $e->execute([':tid'=>$tid]); $exp = (float)$e->fetchColumn();
        $c = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM pal_collections WHERE tenant_id=:tid AND status='approved'"); $c->execute([':tid'=>$tid]); $col = (float)$c->fetchColumn();
        $p = $db->prepare("SELECT COALESCE(SUM(contract_amount),0) FROM pal_projects WHERE tenant_id=:tid"); $p->execute([':tid'=>$tid]); $con = (float)$p->fetchColumn();
        return [['item'=>'Total Contract Amount','val'=>$con],['item'=>'Total Approved Expenses','val'=>$exp],['item'=>'Total Collections','val'=>$col],['item'=>'Net Profit/Loss','val'=>$col-$exp]];
    }
    if ($type === 'project') { $s = $db->prepare("SELECT id,title,status,contract_amount FROM pal_projects WHERE tenant_id=:tid ORDER BY title"); $s->execute([':tid'=>$tid]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    if ($type === 'inventory') { $s = $db->prepare("SELECT m.name,m.material_code,COALESCE(b.quantity,0) AS stock,m.current_avg_cost FROM pal_materials m LEFT JOIN pal_inventory_balances b ON m.id=b.material_id WHERE m.tenant_id=:tid ORDER BY m.name"); $s->execute([':tid'=>$tid]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    if ($type === 'sales') { $s = $db->prepare("SELECT s.sales_number,s.gross_amount,s.discount_amount,s.tax_amount,s.net_amount,s.status,p.title AS project FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id=p.id WHERE s.tenant_id=:tid ORDER BY s.created_at DESC LIMIT 50"); $s->execute([':tid'=>$tid]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    return [];
}

function palApiReportGenerate(): void { palResponseGuard(function(){ $u=palCurrentUser(['admin','supervisor']); palEnforceCsrf(); $type=$_POST['report_type']??''; $format=$_POST['format']??'html'; $db=palDb(); $tid=(int)($u['tenant_id']??0); $stmt=$db->prepare("INSERT INTO pal_report_exports (tenant_id, report_type, format, filters_json, generated_by, status) VALUES (:t, :rt, :f, :fj, :gb, 'completed')"); $filters=['date_from'=>$_POST['date_from']??null,'date_to'=>$_POST['date_to']??null,'project_id'=>$_POST['project_id']??null]; $stmt->execute([':t'=>$tid,':rt'=>$type,':f'=>$format,':fj'=>json_encode($filters),':gb'=>(int)$u['id']]); $exportId=(int)$db->lastInsertId(); palAudit('pal.report.generated', (int)$u['id'], 'pal_report_exports', (string)$exportId, null, ['type'=>$type,'format'=>$format]); header('Content-Type: application/json'); echo json_encode(['ok'=>true, 'id'=>$exportId, 'preview_url'=>"/admin/project-audit-ledger/reports?preview={$type}"]); }); }

/**
 * API: Export a report as a downloadable CSV file.
 * GET /api/v1/project-audit-ledger/reports/export?type=financial&date_from=...&date_to=...
 */
function palApiReportExport(): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);
    $type = $_GET['type'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $columns = [];
    $rows = [];

    if ($type === 'financial') {
        $columns = ['Item', 'Amount'];
        $where = '';
        $params = [':tid' => $tid];
        if ($dateFrom !== '') { $where .= ' AND DATE(e.expense_date) >= :df'; $params[':df'] = $dateFrom; }
        if ($dateTo !== '') { $where .= ' AND DATE(e.expense_date) <= :dt'; $params[':dt'] = $dateTo; }
        $e = $db->prepare("SELECT COALESCE(SUM(e.amount),0) FROM pal_expenses e WHERE e.tenant_id=:tid AND e.status='approved'{$where}");
        $e->execute($params); $exp = (float)$e->fetchColumn();
        $c = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM pal_collections WHERE tenant_id=:tid AND status='approved'");
        $c->execute([':tid' => $tid]); $col = (float)$c->fetchColumn();
        $p = $db->prepare("SELECT COALESCE(SUM(contract_amount),0) FROM pal_projects WHERE tenant_id=:tid");
        $p->execute([':tid' => $tid]); $con = (float)$p->fetchColumn();
        $rows = [
            ['Total Contract Amount', $con],
            ['Total Approved Expenses', $exp],
            ['Total Collections', $col],
            ['Net Profit/Loss', $col - $exp],
        ];
    } elseif ($type === 'project') {
        $columns = ['ID', 'Title', 'Status', 'Contract Amount'];
        $s = $db->prepare("SELECT id,title,status,contract_amount FROM pal_projects WHERE tenant_id=:tid ORDER BY title");
        $s->execute([':tid' => $tid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = [$r['id'], $r['title'], $r['status'], $r['contract_amount']]; }
    } elseif ($type === 'inventory') {
        $columns = ['Material', 'Code', 'Stock', 'Avg Cost'];
        $s = $db->prepare("SELECT m.name,m.material_code,COALESCE(b.quantity,0) AS stock,m.current_avg_cost FROM pal_materials m LEFT JOIN pal_inventory_balances b ON m.id=b.material_id WHERE m.tenant_id=:tid ORDER BY m.name");
        $s->execute([':tid' => $tid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = [$r['name'], $r['material_code'], $r['stock'], $r['current_avg_cost']]; }
    } elseif ($type === 'sales') {
        $columns = ['Sales #', 'Gross', 'Discount', 'Tax', 'Net', 'Status', 'Project'];
        $s = $db->prepare("SELECT s.sales_number,s.gross_amount,s.discount_amount,s.tax_amount,s.net_amount,s.status,p.title AS project FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id=p.id WHERE s.tenant_id=:tid ORDER BY s.created_at DESC LIMIT 50");
        $s->execute([':tid' => $tid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = [$r['sales_number'], $r['gross_amount'], $r['discount_amount'], $r['tax_amount'], $r['net_amount'], $r['status'], $r['project']]; }
    } else {
        palJsonError('Unknown report type.');
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pal-' . sanitizeFilename($type) . '-report.csv"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders headers/values correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $columns);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
}
