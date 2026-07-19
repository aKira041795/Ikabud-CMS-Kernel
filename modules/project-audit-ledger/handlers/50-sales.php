<?php

declare(strict_types=1);

function palPageSalesList(): void { $u=palCurrentUser(); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Sales', 'page_content'=>'sales-list']); }
function palPageSalesDetail(array $rp=[]): void { $u=palCurrentUser(); $id=(int)($rp['id']??$_GET['id']??0); $s=new palSalesService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $sale=$s->get($id); if(!$sale){palJsonError('Sale not found.',404);return;} $displayNum=$sale['invoice_number']?:$sale['sales_number']; $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Invoice #'.$displayNum, 'page_content'=>'sales-detail', 'sale'=>$sale]); }
function palPageSalesForm(array $rp = []): void { $u=palCurrentUser(); $id=(int)($rp['id']??$_GET['id']??0); $sale=null; if($id>0){$s=new palSalesService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $sale=$s->get($id); if($sale && empty($sale['invoice_number'])){$sale['invoice_number']=$sale['sales_number'];} } $db=palDb(); $tid=(int)($u['tenant_id']??0); $proj=$db->prepare("SELECT id,title,job_order_number FROM pal_projects WHERE tenant_id=:tid AND status IN('approved','started','ongoing','completed') ORDER BY title"); $proj->execute([':tid'=>$tid]); $clients=$db->prepare("SELECT id,name FROM pal_clients WHERE tenant_id=:tid AND is_active=1 ORDER BY name"); $clients->execute([':tid'=>$tid]); $materials=$db->prepare("SELECT m.id, m.name, m.material_code, m.price_per_unit, m.price_per_sqft, m.default_width, m.default_height, mc.name AS category_name FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id=mc.id WHERE m.tenant_id=:tid AND m.is_active=1 ORDER BY m.name"); $materials->execute([':tid'=>$tid]); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>$sale?'Edit Sale':'Create Sale', 'page_content'=>'sales-form', 'sale'=>$sale, 'is_edit'=>$sale!==null, 'projects'=>$proj->fetchAll(PDO::FETCH_ASSOC), 'clients'=>$clients->fetchAll(PDO::FETCH_ASSOC), 'materials'=>$materials->fetchAll(PDO::FETCH_ASSOC)]); }
function palPageCollectionList(): void { $u=palCurrentUser(); $db=palDb(); $tid=(int)($u['tenant_id']??0); $tab=$_GET['tab']??'all'; $statusFilter=$tab!=='all'?($tab==='paid'?'approved':$tab):''; $search=$_GET['search']??''; // Stats
$stats=$db->query("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) AS pending_count, COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amount, COALESCE(SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END),0) AS approved_count, COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount, COALESCE(SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END),0) AS rejected_count, COALESCE(SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END),0) AS voided_count FROM pal_collections WHERE tenant_id=$tid")->fetch(PDO::FETCH_ASSOC); // List with filtering
$where='c.tenant_id=:tid'; $params=[':tid'=>$tid]; if($statusFilter){$where.=' AND c.status=:st'; $params[':st']=$statusFilter;} if($search){$where.=' AND (c.collection_number LIKE :sq OR cl.name LIKE :sq2)'; $params[':sq']="%$search%"; $params[':sq2']="%$search%";} $rows=$db->prepare("SELECT c.id, c.collection_number, c.amount, c.payment_method, c.status, c.payment_date, c.created_at, COALESCE(s.invoice_number, s.sales_number) AS sales_number, p.title AS project_title, cl.name AS client_name FROM pal_collections c LEFT JOIN pal_sales s ON c.sales_id=s.id LEFT JOIN pal_projects p ON c.project_id=p.id LEFT JOIN pal_clients cl ON c.client_id=cl.id WHERE $where ORDER BY c.created_at DESC LIMIT 100"); $rows->execute($params); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Collections', 'page_content'=>'collections-list', 'stats'=>$stats, 'tab'=>$tab, 'search'=>$search, 'collections'=>$rows->fetchAll(PDO::FETCH_ASSOC)]); }
function palPageCollectionDetail(array $rp=[]): void { $u=palCurrentUser(); $id=(int)($rp['id']??$_GET['id']??0); $db=palDb(); $tid=(int)($u['tenant_id']??0); $stmt=$db->prepare("SELECT c.*, COALESCE(s.invoice_number, s.sales_number) AS sales_number, (s.gross_amount + COALESCE(s.installation_charge,0) + COALESCE(s.mobilization_charge,0) + COALESCE(s.other_charges,0) - COALESCE(s.discount_amount,0) + COALESCE(s.tax_amount,0)) AS sale_invoice_total, s.status AS sale_status, p.title AS project_title, cl.name AS client_name, cb.full_name AS created_by_name FROM pal_collections c LEFT JOIN pal_sales s ON c.sales_id=s.id LEFT JOIN pal_projects p ON c.project_id=p.id LEFT JOIN pal_clients cl ON c.client_id=cl.id LEFT JOIN pal_users cb ON c.created_by=cb.id WHERE c.id=:id AND c.tenant_id=:tid"); $stmt->execute([':id'=>$id,':tid'=>$tid]); $col=$stmt->fetch(PDO::FETCH_ASSOC); if(!$col){palJsonError('Collection not found.',404);return;} $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Collection #'.$id, 'page_content'=>'collections-detail', 'collection'=>$col]); }
function palPageCollectionForm(): void { $u=palCurrentUser(); $db=palDb(); $tid=(int)($u['tenant_id']??0); $proj=$db->prepare("SELECT id,title,job_order_number FROM pal_projects WHERE tenant_id=:tid AND status IN('approved','started','ongoing','completed') ORDER BY title"); $proj->execute([':tid'=>$tid]); $sales=$db->prepare("SELECT s.id, COALESCE(s.invoice_number, s.sales_number) AS sales_number, (s.gross_amount + COALESCE(s.installation_charge,0) + COALESCE(s.mobilization_charge,0) + COALESCE(s.other_charges,0) - COALESCE(s.discount_amount,0) + COALESCE(s.tax_amount,0)) AS invoice_total, s.gross_amount, s.project_id, p.title AS project_title, p.job_order_number, cl.name AS client_name, COALESCE((SELECT SUM(amount) FROM pal_collections WHERE sales_id=s.id AND status='approved'),0) AS collected_amt FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id=p.id AND p.tenant_id=s.tenant_id LEFT JOIN pal_clients cl ON s.client_id=cl.id AND cl.tenant_id=s.tenant_id WHERE s.tenant_id=:tid AND s.status IN('issued','partially_paid') ORDER BY p.title, s.created_at DESC"); $sales->execute([':tid'=>$tid]); $t=__DIR__.'/../templates/project-audit-ledger/shell.disyl'; palRender($t, ['current_user'=>$u, 'page_title'=>'Record Collection', 'page_content'=>'collection-form', 'projects'=>$proj->fetchAll(PDO::FETCH_ASSOC), 'sales'=>$sales->fetchAll(PDO::FETCH_ASSOC)]); }
function palPageSalesPrint(array $rp=[]): void { $u=palCurrentUser(); $id=(int)($rp['id']??$_GET['id']??0); $s=new palSalesService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $sale=$s->get($id); if(!$sale){palJsonError('Sale not found.',404);return;} $settings=palSettings(); $t=__DIR__.'/../templates/project-audit-ledger/prints/invoice-print.disyl'; echo app()->render($t, ['sale'=>$sale, 'settings'=>$settings]); }
function palApiSalesStore(): void { palResponseGuard(function(){ $u=palCurrentUser(); palEnforceCsrf(); $data=$_POST; if(!empty($data['items']) && is_string($data['items'])){$data['items']=json_decode($data['items'],true)??[];} $s=new palSalesService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $id=$s->create($data); header('Content-Type: application/json'); echo json_encode(['ok'=>true, 'id'=>$id]); }); }
function palApiSalesUpdate(array $rp = []): void { palResponseGuard(function() use ($rp): void { $u=palCurrentUser(); palEnforceCsrf(); $id=(int)($rp['id']??$_GET['id']??$_POST['id']??0); if($id<=0){palJsonError('Invalid ID.');return;} $data=$_POST; if(!empty($data['items']) && is_string($data['items'])){$data['items']=json_decode($data['items'],true)??[];} $s=new palSalesService(palDb(), (int)($u['tenant_id']??0), (int)$u['id']); $s->update($id, $data); palAudit('pal.sale.updated', (int)$u['id'], 'pal_sales', (string)$id, null, []); header('Content-Type: application/json'); echo json_encode(['ok'=>true]); }); }
function palApiSalesSendEmail(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid sales ID.'); return; }

        $svc = new palSalesService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $sale = $svc->get($id);
        if (!$sale) { palJsonError('Sale not found.', 404); return; }

        $db = palDb();
        $settings = palSettings();
        $companyName = $settings['company_name'] ?? 'ZAP-ARTS Signage & Printing Solutions';

        $clientStmt = $db->prepare("SELECT email,name,contact_person FROM pal_clients WHERE id=:id AND tenant_id=:tid");
        $clientStmt->execute([':id' => $sale['client_id'], ':tid' => (int)($u['tenant_id'] ?? 0)]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

        if (!$client || empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
            palJsonError('Client has no valid email address on file.');
            return;
        }

        $clientEmail = $client['email'];
        $invoiceNum = !empty($sale['invoice_number']) ? $sale['invoice_number'] : ($sale['sales_number'] ?? (string)$id);

        // Build charges array for template
        $charges = [];
        if ((float)($sale['installation_charge'] ?? 0) > 0) {
            $charges[] = ['label' => 'Installation', 'amount' => (float)$sale['installation_charge']];
        }
        if ((float)($sale['mobilization_charge'] ?? 0) > 0) {
            $charges[] = ['label' => 'Mobilization', 'amount' => (float)$sale['mobilization_charge']];
        }
        if ((float)($sale['other_charges'] ?? 0) > 0) {
            $charges[] = ['label' => 'Other Charges', 'amount' => (float)$sale['other_charges']];
        }

        // Render email via DiSyL template (not PHP string concatenation)
        $template = __DIR__ . '/../templates/project-audit-ledger/_email_invoice.disyl';
        $body = app()->render($template, [
            'company_name'    => $companyName,
            'client_name'     => $client['name'] ?? 'Valued Client',
            'invoice_number'  => $invoiceNum,
            'project_title'   => $sale['project_title'] ?? '',
            'gross_amount'    => number_format((float)($sale['gross_amount'] ?? 0), 2),
            'tax_amount'      => (float)($sale['tax_amount'] ?? 0),
            'discount_amount' => (float)($sale['discount_amount'] ?? 0),
            'down_payment'    => (float)($sale['down_payment'] ?? 0),
            'outstanding'     => '₱' . number_format((float)($sale['outstanding'] ?? 0), 2),
            'total_collected' => '₱' . number_format((float)($sale['total_collected'] ?? 0), 2),
            'mode_of_payment' => !empty($sale['mode_of_payment']) ? str_replace('_', ' ', ucfirst($sale['mode_of_payment'])) : '',
            'scope_of_work'   => !empty($sale['scope_of_work']) ? str_replace('_', ' ', ucfirst($sale['scope_of_work'])) : '',
            'charges'          => $charges,
            'items'            => $sale['items'] ?? [],
        ]);

        $subject = 'Invoice — ' . $invoiceNum . ' — ' . $companyName;
        $sent = sendEmail($clientEmail, $subject, $body);

        if (!$sent) {
            palJsonError('Failed to send email. Check SMTP configuration.');
            return;
        }

        palAudit('pal.sale.emailed', (int)$u['id'], 'pal_sales', (string)$id, null, ['to' => $clientEmail]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'to' => $clientEmail]);
    });
}
function palApiCollectionStore(): void { palResponseGuard(function(){ $u=palCurrentUser(); palEnforceCsrf(); $data=$_POST; $saleId=(int)($data['sales_id']??0); $amount=(float)($data['amount']??0); $method=(string)($data['payment_method']??'cash'); $date=(string)($data['payment_date']??date('Y-m-d')); $ref=$data['reference_number']??null; $notes=$data['notes']??null; $svc=new palPaymentService(palDb(),(int)($u['tenant_id']??0),(int)$u['id']); $id=$svc->record($saleId,$amount,$method,$date,$ref,$notes); header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$id]); }); }
function palApiCollectionStatus(array $rp=[]): void { palResponseGuard(function() use ($rp): void { $u=palCurrentUser(); palEnforceCsrf(); $id=(int)($rp['id']??0); if($id<=0){palJsonError('Invalid collection ID.');return;} $status=$_POST['status']??''; $tid=(int)($u['tenant_id']??0); $uid=(int)$u['id']; try{ $svc=new palPaymentService(palDb(),$tid,$uid); $reason=$_POST['notes']??''; match($status){ 'approved'=>$svc->approve($id), 'rejected'=>$svc->reject($id,$reason), default=>throw new InvalidArgumentException('Invalid status: '.$status.'. Use approved or rejected.'), }; header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>'Payment '.$status]); }catch(Throwable $e){ palJsonError($e->getMessage(),400); } }); }
function palApiCollectionsGenerate(): void { palResponseGuard(function(){ $u=palCurrentUser(); palEnforceCsrf(); $db=palDb(); $tid=(int)($u['tenant_id']??0); // Find invoices without any receivable
$sql="SELECT s.id, s.sales_number, s.gross_amount, s.discount_amount, s.tax_amount, s.installation_charge, s.mobilization_charge, s.other_charges, s.sales_date, s.project_id, s.client_id, s.due_date, s.mode_of_payment, s.created_by FROM pal_sales s LEFT JOIN pal_receivables r ON s.id=r.sales_id AND r.tenant_id=s.tenant_id WHERE s.tenant_id=:tid AND s.status IN('issued','partially_paid') AND r.id IS NULL ORDER BY s.id"; $stmt=$db->prepare($sql); $stmt->execute([':tid'=>$tid]); $invoices=$stmt->fetchAll(PDO::FETCH_ASSOC); if(empty($invoices)){header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>'All invoices already have receivables.','created'=>0]); return;} $created=0; $errors=0; $rcvSvc=new palReceivableService($db,$tid,(int)$u['id']); foreach($invoices as $inv){try{ $total=palInvoiceTotalCalculator::total($inv); $due=$inv['due_date']??date('Y-m-d',strtotime('+30 days')); $rcvSvc->createFromInvoice((int)$inv['id'],$inv['project_id']?(int)$inv['project_id']:null,$inv['client_id']?(int)$inv['client_id']:null,$total,$due); $created++; }catch(Throwable $e){$errors++; write_log('pal.receivables.generate failed for invoice #'.$inv['id'].': '.$e->getMessage(),'error');}} palAudit('pal.receivables.generate',(int)$u['id'],'pal_receivables',null,null,['created'=>$created,'errors'=>$errors]); header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>"Generated {$created} receivable(s).",'created'=>$created,'errors'=>$errors]); }); }
