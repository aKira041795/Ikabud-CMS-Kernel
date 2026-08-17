<?php

declare(strict_types=1);

function palPageSettings(): void
{
    $u = palRequireRole('admin');
    $tab = $_GET['tab'] ?? 'overview';
    $tid = (int)($u['tenant_id'] ?? 0);
    $db = palDb();
    $ctx = ['current_user' => $u, 'page_title' => 'Settings', 'page_content' => 'settings-' . $tab];

    if ($tab === 'categories') {
        $s1 = $db->prepare("SELECT * FROM pal_expense_categories WHERE tenant_id=:tid ORDER BY name"); $s1->execute([':tid'=>$tid]); $ctx['expense_cats'] = $s1->fetchAll(PDO::FETCH_ASSOC);
        $s2 = $db->prepare("SELECT * FROM pal_material_categories WHERE tenant_id=:tid ORDER BY name"); $s2->execute([':tid'=>$tid]); $ctx['material_cats'] = $s2->fetchAll(PDO::FETCH_ASSOC);
        $s3 = $db->prepare("SELECT * FROM pal_project_types WHERE tenant_id=:tid ORDER BY name"); $s3->execute([':tid'=>$tid]); $ctx['project_types'] = $s3->fetchAll(PDO::FETCH_ASSOC);
        $s4 = $db->prepare("SELECT * FROM pal_units WHERE tenant_id=:tid ORDER BY name"); $s4->execute([':tid'=>$tid]); $ctx['units'] = $s4->fetchAll(PDO::FETCH_ASSOC);
        $s5 = $db->prepare("SELECT * FROM pal_team_leads WHERE tenant_id=:tid ORDER BY name"); $s5->execute([':tid'=>$tid]); $ctx['team_leads'] = $s5->fetchAll(PDO::FETCH_ASSOC);
        $s6 = $db->prepare("SELECT * FROM pal_inventory_locations WHERE tenant_id=:tid ORDER BY name"); $s6->execute([':tid'=>$tid]); $ctx['locations'] = $s6->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tab === 'suppliers') {
        $s1 = $db->prepare("SELECT * FROM pal_suppliers WHERE tenant_id=:tid ORDER BY name"); $s1->execute([':tid'=>$tid]); $ctx['suppliers'] = $s1->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tab === 'materials') {
        $s1 = $db->prepare("SELECT m.*, mc.name AS category_name, u.name AS unit_name, s.name AS supplier_name, COALESCE(b.quantity,0) AS stock_qty FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id=mc.id LEFT JOIN pal_units u ON m.unit_id=u.id LEFT JOIN pal_suppliers s ON m.preferred_supplier_id=s.id LEFT JOIN pal_inventory_balances b ON m.id=b.material_id WHERE m.tenant_id=:tid ORDER BY m.name"); $s1->execute([':tid'=>$tid]); $ctx['materials'] = $s1->fetchAll(PDO::FETCH_ASSOC);
        $s2 = $db->prepare("SELECT id,name FROM pal_material_categories WHERE tenant_id=:tid AND is_active=1 ORDER BY name"); $s2->execute([':tid'=>$tid]); $ctx['mat_categories'] = $s2->fetchAll(PDO::FETCH_ASSOC);
        $s3 = $db->prepare("SELECT id,name,abbreviation FROM pal_units WHERE tenant_id=:tid ORDER BY name"); $s3->execute([':tid'=>$tid]); $ctx['mat_units'] = $s3->fetchAll(PDO::FETCH_ASSOC);
        $s4 = $db->prepare("SELECT id,name FROM pal_suppliers WHERE tenant_id=:tid AND is_active=1 ORDER BY name"); $s4->execute([':tid'=>$tid]); $ctx['mat_suppliers'] = $s4->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ctx['settings'] = palSettings();
    }

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', $ctx);
}

function palApiSettingsCategoryStore(): void
{
    palResponseGuard(function(): void {
        $u = palRequireRole('admin'); palEnforceCsrf();
        $type = $_POST['type'] ?? ''; $name = $_POST['name'] ?? '';
        if ($name === '') { palJsonError('Name is required.'); return; }
        $table = match($type) {
            'expense_category' => 'pal_expense_categories',
            'material_category' => 'pal_material_categories',
            'project_type' => 'pal_project_types',
            'unit' => 'pal_units',
            'team_lead' => 'pal_team_leads',
            'inventory_location' => 'pal_inventory_locations',
            default => null,
        };
        if (!$table) { palJsonError('Invalid category type.'); return; }
        $tid = (int)($u['tenant_id'] ?? 0);
        $db = palDb();
        if ($type === 'unit') {
            $db->prepare("INSERT INTO {$table} (tenant_id, name, abbreviation) VALUES (:t, :n, :ab)")->execute([':t'=>$tid, ':n'=>$name, ':ab'=>$_POST['abbreviation']??null]);
        } elseif ($type === 'team_lead') {
            $db->prepare("INSERT INTO {$table} (tenant_id, name, contact_number, email) VALUES (:t, :n, :cn, :e)")->execute([':t'=>$tid, ':n'=>$name, ':cn'=>$_POST['contact_number']??null, ':e'=>$_POST['email']??null]);
        } else {
            $db->prepare("INSERT INTO {$table} (tenant_id, name) VALUES (:t, :n)")->execute([':t'=>$tid, ':n'=>$name]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiSettingsSupplierStore(): void
{
    palResponseGuard(function(): void {
        $u = palRequireRole('admin'); palEnforceCsrf();
        $name = $_POST['name'] ?? ''; if ($name === '') { palJsonError('Name is required.'); return; }
        $tid = (int)($u['tenant_id'] ?? 0); $db = palDb();
        $db->prepare("INSERT INTO pal_suppliers (tenant_id, name, contact_person, email, phone, address, payment_terms, created_by) VALUES (:t,:n,:cp,:e,:p,:a,:pt,:cb)")
            ->execute([':t'=>$tid, ':n'=>$name, ':cp'=>$_POST['contact_person']??null, ':e'=>$_POST['email']??null, ':p'=>$_POST['phone']??null, ':a'=>$_POST['address']??null, ':pt'=>$_POST['payment_terms']??null, ':cb'=>(int)$u['id']]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiSettingsMaterialUpdate(array $rp = []): void
{
    palResponseGuard(function() use ($rp): void {
        $u = palRequireRole('admin'); palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid material ID.'); return; }
        $tid = (int)($u['tenant_id'] ?? 0); $db = palDb();
        $fields = []; $params = [':id' => $id, ':tid' => $tid];
        $nullableInt = ['category_id','unit_id','conversion_unit_id','preferred_supplier_id'];
        $nullableDec = ['reorder_level','conversion_factor'];
        foreach (['material_code','name','category_id','description','unit_id','conversion_unit_id','conversion_factor','current_avg_cost','reorder_level','preferred_supplier_id','storage_location'] as $f) {
            if (isset($_POST[$f])) {
                $val = $_POST[$f];
                if (in_array($f, $nullableInt, true) && $val === '') { $val = null; }
                if (in_array($f, $nullableDec, true) && $val === '') { $val = null; }
                $fields[] = "$f = :$f"; $params[":$f"] = $val;
            }
        }
        if (isset($_POST['is_active'])) { $fields[] = 'is_active = :is_active'; $params[':is_active'] = (int)$_POST['is_active']; }
        if (empty($fields)) { palJsonError('No fields to update.'); return; }
        $fields[] = 'updated_at = NOW()'; $fields[] = 'updated_by = :ub'; $params[':ub'] = (int)$u['id'];
        $db->prepare("UPDATE pal_materials SET " . implode(', ', $fields) . " WHERE id = :id AND tenant_id = :tid")->execute($params);
        palAudit('pal.material.updated', (int)$u['id'], 'pal_materials', (string)$id, null, []);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiSettingsToggleStatus(array $rp = []): void
{
    palResponseGuard(function() use ($rp): void {
        $u = palRequireRole('admin'); palEnforceCsrf();
        $type = $_POST['type'] ?? ''; $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0 || $type === '') { palJsonError('Invalid request.'); return; }
        $table = match($type) {
            'expense_category' => 'pal_expense_categories',
            'material_category' => 'pal_material_categories',
            'project_type' => 'pal_project_types',
            'unit' => 'pal_units',
            'team_lead' => 'pal_team_leads',
            'inventory_location' => 'pal_inventory_locations',
            'supplier' => 'pal_suppliers',
            default => null,
        };
        if (!$table) { palJsonError('Invalid type.'); return; }
        $tid = (int)($u['tenant_id'] ?? 0); $db = palDb();
        $chk = $db->prepare("SELECT is_active FROM {$table} WHERE id=:id AND tenant_id=:tid"); $chk->execute([':id'=>$id, ':tid'=>$tid]); $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { palJsonError('Not found.'); return; }
        $new = (int)$row['is_active'] === 1 ? 0 : 1;
        $db->prepare("UPDATE {$table} SET is_active=:ia WHERE id=:id AND tenant_id=:tid")->execute([':ia'=>$new, ':id'=>$id, ':tid'=>$tid]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'is_active' => $new]);
    });
}

function palApiSettingsSave(): void { palResponseGuard(function(){ $u=palRequireRole('admin'); palEnforceCsrf(); $tid=(int)($u['tenant_id']??0); $db=palDb(); // Handle text fields
foreach($_POST as $key=>$val){$s=$db->prepare("INSERT INTO pal_settings (tenant_id, setting_key, setting_value) VALUES (:t, :k, :v) ON DUPLICATE KEY UPDATE setting_value=:v2"); $s->execute([':t'=>$tid, ':k'=>$key, ':v'=>$val, ':v2'=>$val]);} // Handle logo upload
if(isset($_FILES['logo'])&&$_FILES['logo']['error']===UPLOAD_ERR_OK){$f=$_FILES['logo'];$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));if(in_array($ext,['png','jpg','jpeg','gif','svg'])){$name='logo-'.$tid.'.'.$ext;$dir=PUBLIC_PATH.'/uploads/pal/'.$tid;if(!is_dir($dir)){mkdir($dir,0755,true);}move_uploaded_file($f['tmp_name'],$dir.'/'.$name);$relPath='uploads/pal/'.$tid.'/'.$name;$db->prepare("INSERT INTO pal_settings (tenant_id, setting_key, setting_value) VALUES (:t,'logo_path',:v) ON DUPLICATE KEY UPDATE setting_value=:v2")->execute([':t'=>$tid,':v'=>$relPath,':v2'=>$relPath]);}} palAudit('pal.settings.updated', (int)$u['id'], 'pal_settings', null, null, []); header('Content-Type: application/json'); echo json_encode(['ok'=>true]); }); }

function palApiSettingsLogoUpload(): void { palResponseGuard(function(){ $u=palRequireRole('admin'); palEnforceCsrf(); $tid=(int)($u['tenant_id']??0); if(!isset($_FILES['logo'])||$_FILES['logo']['error']!==UPLOAD_ERR_OK){palJsonError('Upload failed.',422);return;} $f=$_FILES['logo']; $ext=strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); if(!in_array($ext,['png','jpg','jpeg','gif','svg'])){palJsonError('Invalid image type.');return;} $name='logo-'.$tid.'.'.$ext; $dir=PUBLIC_PATH.'/uploads/pal/'.$tid; if(!is_dir($dir)){mkdir($dir,0755,true);} move_uploaded_file($f['tmp_name'],$dir.'/'.$name); $relPath='uploads/pal/'.$tid.'/'.$name; $db=palDb(); $db->prepare("INSERT INTO pal_settings (tenant_id, setting_key, setting_value) VALUES (:t,'logo_path',:v) ON DUPLICATE KEY UPDATE setting_value=:v2")->execute([':t'=>$tid,':v'=>$relPath,':v2'=>$relPath]); header('Content-Type: application/json'); echo json_encode(['ok'=>true, 'path'=>$relPath]); }); }

function palApiAutocomplete(): void
{
    $u = palCurrentUser();
    $tid = (int)($u['tenant_id'] ?? 0);
    $type = $_GET['type'] ?? '';
    $q = $_GET['q'] ?? '';
    if ($q === '' || $type === '') { header('Content-Type: application/json'); echo json_encode([]); return; }

    $db = palDb();
    $like = '%' . $q . '%';
    $results = [];

    $queries = [
        'supplier' => "SELECT id, name AS label, name FROM pal_suppliers WHERE tenant_id=:tid AND is_active=1 AND name LIKE :q ORDER BY name LIMIT 10",
        'material' => "SELECT id, name AS label, CONCAT(material_code, ' — ', name) AS sublabel FROM pal_materials WHERE tenant_id=:tid AND is_active=1 AND (name LIKE :q OR material_code LIKE :q2) ORDER BY name LIMIT 10",
        'project' => "SELECT id, title AS label, project_id AS sublabel FROM pal_projects WHERE tenant_id=:tid AND (title LIKE :q OR project_id LIKE :q2) ORDER BY title LIMIT 10",
        'client' => "SELECT id, name AS label, email AS sublabel FROM pal_clients WHERE tenant_id=:tid AND is_active=1 AND name LIKE :q ORDER BY name LIMIT 10",
        'sale' => "SELECT id, sales_number AS label, CONCAT('₱', net_amount) AS sublabel FROM pal_sales WHERE tenant_id=:tid AND status IN('issued','partially_paid') AND (sales_number LIKE :q OR CAST(id AS CHAR) LIKE :q2) ORDER BY sales_number LIMIT 10",
        'expense_category' => "SELECT id, name AS label FROM pal_expense_categories WHERE tenant_id=:tid AND is_active=1 AND name LIKE :q ORDER BY name LIMIT 10",
        'material_category' => "SELECT id, name AS label FROM pal_material_categories WHERE tenant_id=:tid AND is_active=1 AND name LIKE :q ORDER BY name LIMIT 10",
        'unit' => "SELECT id, CONCAT(name, ' (', abbreviation, ')') AS label, abbreviation AS sublabel FROM pal_units WHERE tenant_id=:tid AND name LIKE :q ORDER BY name LIMIT 10",
        'team_lead' => "SELECT id, name AS label FROM pal_team_leads WHERE tenant_id=:tid AND is_active=1 AND name LIKE :q ORDER BY name LIMIT 10",
    ];

    if (isset($queries[$type])) {
        $sql = $queries[$type];
        $stmt = $db->prepare($sql);
        $stmt->execute([':tid' => $tid, ':q' => $like, ':q2' => $like]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: application/json');
    echo json_encode($results);
}

function palApiQuickCreate(): void
{
    palResponseGuard(function(): void {
        $u = palCurrentUser(); palEnforceCsrf(); $tid = (int)($u['tenant_id'] ?? 0); $db = palDb();
        $type = $_POST['type'] ?? ''; $name = $_POST['name'] ?? '';
        if ($name === '') { palJsonError('Name is required.'); return; }

        $id = null;
        switch ($type) {
            case 'supplier':
                $s = $db->prepare("INSERT INTO pal_suppliers (tenant_id, name, created_by) VALUES (:t,:n,:cb)");
                $s->execute([':t'=>$tid, ':n'=>$name, ':cb'=>(int)$u['id']]);
                $id = (int)$db->lastInsertId();
                break;
            case 'client':
                $s = $db->prepare("INSERT INTO pal_clients (tenant_id, name, created_by) VALUES (:t,:n,:cb)");
                $s->execute([':t'=>$tid, ':n'=>$name, ':cb'=>(int)$u['id']]);
                $id = (int)$db->lastInsertId();
                break;
            case 'project':
                $s = $db->prepare("INSERT INTO pal_projects (tenant_id, title, project_id, status, created_by) VALUES (:t,:n,:pid,'draft',:cb)");
                $s->execute([':t'=>$tid, ':n'=>$name, ':pid'=>'P-'.time(), ':cb'=>(int)$u['id']]);
                $id = (int)$db->lastInsertId();
                break;
            case 'material':
                $s = $db->prepare("INSERT INTO pal_materials (tenant_id, name, material_code, created_by) VALUES (:t,:n,:code,:cb)");
                $s->execute([':t'=>$tid, ':n'=>$name, ':code'=>'MAT-'.time(), ':cb'=>(int)$u['id']]);
                $id = (int)$db->lastInsertId();
                break;
            case 'material_category':
            case 'expense_category':
            case 'project_type':
            case 'unit':
            case 'team_lead':
            case 'inventory_location':
                $table = match($type) {
                    'material_category' => 'pal_material_categories',
                    'expense_category' => 'pal_expense_categories',
                    'project_type' => 'pal_project_types',
                    'unit' => 'pal_units',
                    'team_lead' => 'pal_team_leads',
                    'inventory_location' => 'pal_inventory_locations',
                };
                $s = $db->prepare("INSERT INTO {$table} (tenant_id, name) VALUES (:t,:n)");
                $s->execute([':t'=>$tid, ':n'=>$name]);
                $id = (int)$db->lastInsertId();
                break;
            default:
                palJsonError('Invalid type.');
                return;
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id, 'label' => $name]);
    });
}
