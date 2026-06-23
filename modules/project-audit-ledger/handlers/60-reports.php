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
