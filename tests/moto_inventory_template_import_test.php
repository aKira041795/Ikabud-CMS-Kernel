<?php

declare(strict_types=1);

/**
 * Moto Inventory — Brand Import Template Integration Test (disposable tenant DB).
 *
 * Exercises ImportTemplateService (bundled presets + tenant custom template
 * CRUD) and ImportService::stage with templates: preset mapping application,
 * preferred-sheet selection, stored-code (code_attr) alongside a price column,
 * part-number synthesis (description / composite), and the custom-template
 * save/list/update/delete lifecycle. Runs against real files and a real MySQL
 * tenant DB.
 *
 * Run: php tests/moto_inventory_template_import_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';
require_once __DIR__ . '/moto_inventory_xlsx_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-template-import', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/services/ImportTemplateService.php');
$h->fingerprint('modules/moto-inventory/services/ImportService.php');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenant provisioned', false, $e->getMessage());
    $h->gap('Import template integration requires MySQL — skipped');
    $h->done();
}

$tid = $tenant['tenant_id'];
$pdo = $tenant['pdo'];
$ctx = moto_test_admin_ctx($tid);

$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid, ':k' => 'main', ':n' => 'Main']);
$branchId = (int)$pdo->lastInsertId();
$ctx['branch_ids'] = [$branchId];
$brand = CatalogService::createBrand($ctx, 'MOM Cycle');
$brandId = $brand['id'];

$tmpDir = sys_get_temp_dir() . '/moto_tpl_' . substr(bin2hex(random_bytes(4)), 0, 6);
@mkdir($tmpDir, 0777, true);

$mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

// ── Bundled presets ───────────────────────────────────────────────
$h->section('Bundled presets');

$all = ImportTemplateService::all($ctx);
$h->test('16 bundled brand presets', count($all['presets'] ?? []) === 16);
$h->test('no custom templates initially', count($all['custom'] ?? []) === 0);

$honda = ImportTemplateService::get($ctx, 'preset:honda_gen');
$h->test('preset:honda_gen resolves', is_array($honda) && ($honda['kind'] ?? '') === 'preset');
$h->test('preset:honda_gen maps PARTS NUMBER column', ($honda['mapping'][2] ?? null) === 'part_number');
$h->test('preset:honda_gen maps PRICE column', ($honda['mapping'][6] ?? null) === 'price');
$h->test('preset:honda_gen treats CODE as stored attribute', ($honda['mapping'][5] ?? null) === 'code_attr');
$h->test('preset:honda_gen preferred sheet', ($honda['sheet'] ?? '') === 'HONDA GEN');
$h->test('unknown template resolves to null', ImportTemplateService::get($ctx, 'preset:nope') === null);

$tire = ImportTemplateService::get($ctx, 'tire');
$h->test('bare preset key resolves (tire)', is_array($tire) && ($tire['part_number_source'] ?? '') === 'composite');

// ── Multi-sheet supplier workbook (MOM CYCLE style) ───────────────
$h->section('Preset staging on a multi-sheet workbook');

$momFile = $tmpDir . '/mom.xlsx';
moto_test_build_multi_xlsx($momFile, [
    [
        'name' => 'HONDA GEN',
        'rows' => [
            ['DESCRIPTION', 'UNIT MODEL', 'PARTS NUMBER', 'QTY DISPLAY', 'QTY STOCK', 'CODE', 'PRICE', 'DATE OF GIVEN PRICE'],
            ['ARM COMP CAM', 'XRM110/W100', '14500-035-020', '8', '', '', '500', ''],
            ['BRAKE PAD', 'CB125', '14500-KYY-900', '6', '', 'ASN', '650', 'JULY 2026'],
        ],
    ],
    [
        'name' => 'HONDA REP',
        'rows' => [
            ['DESCRIPTION', 'UNIT MODEL', 'BRAND', 'SIZE', 'COLOR', 'QTY DISPLAY', 'QTY STOCK', 'CODE', 'PRICE'],
            ['AXLE PIVOT / AXLE SWING ARM', 'XRM/WAVE', 'ASSTD BRAND', '', '', '11', '', '', '100'],
            ['CABLE CLUTCH', 'SG125', 'SKYGO 46200H', '', '', '1', '', '', ''],
        ],
    ],
    [
        'name' => 'TIRE',
        'rows' => [
            ['SIZE', 'BRAND', 'PATTERN', 'TYPE', 'QUANTITY', 'CODE', 'PRICE'],
            ['8X400-', 'MTL', 'W/TUBE', 'TT', '3', '', '1350'],
            ['10X90/90', 'DRIVEMAXX', '', '', '4', '', '950'],
        ],
    ],
    [
        'name' => 'YAMAHA GEN',
        'rows' => [
            ['DESCRIPTION', 'UNIT MODEL', 'PARTS NUMBER', 'QTY DISPLAY', 'QTY STOCK', 'CODE', 'PRICE', 'DATE OF GIVEN PRICE'],
            ['AIR SHROUD CYLINDER 1', 'MIO125', '2PH-E2651-00', '3', '', 'MAA', '700', ''],
        ],
    ],
]);

// HONDA GEN preset: part no. column, stored code alongside price, custom fields.
$hondaStage = ImportService::stage($ctx, $branchId, $brandId, $momFile, 'mom.xlsx', $mime, null, 0, 0, 1, null, 'tpl-honda', $honda);
$hondaRow = static function (array $rows, string $part): ?array {
    foreach ($rows as $r) {
        if (($r['part_number'] ?? '') === $part) {
            return $r;
        }
    }
    return null;
};
$arm = $hondaRow($hondaStage['rows'] ?? [], '14500-035-020');
$h->test('preset honda_gen imports part number', is_array($arm));
$h->test('preset honda_gen maps description', is_array($arm) && ($arm['description'] ?? '') === 'ARM COMP CAM');
$h->test('preset honda_gen maps quantity (QTY DISPLAY)', is_array($arm) && (float)($arm['qty'] ?? 0) === 8.0);
$h->test('preset honda_gen maps price', is_array($arm) && (float)($arm['price'] ?? 0) === 500.0);
$h->test('preset honda_gen stores UNIT MODEL as custom field', is_array($arm) && ($arm['extra']['Unit Model'] ?? '') === 'XRM110/W100');
$pad = $hondaRow($hondaStage['rows'] ?? [], '14500-KYY-900');
$h->test('preset honda_gen stores code as attribute with a price', is_array($pad) && ($pad['code'] ?? '') === 'ASN' && (float)($pad['price'] ?? 0) === 650.0);
$h->test('preset honda_gen keeps DATE OF GIVEN PRICE as custom field', is_array($pad) && ($pad['extra']['Date of Given Price'] ?? '') === 'JULY 2026');
$h->test('preset honda_gen stage has no validation errors', ($hondaStage['errors'] ?? []) === []);

// HONDA REP preset: no part-number column → description becomes the part number.
$hondaRep = ImportTemplateService::get($ctx, 'preset:honda_rep');
$repStage = ImportService::stage($ctx, $branchId, $brandId, $momFile, 'mom.xlsx', $mime, null, 0, 0, 1, null, 'tpl-rep', $hondaRep);
$axle = $hondaRow($repStage['rows'] ?? [], 'AXLE PIVOT / AXLE SWING ARM');
$h->test('preset honda_rep uses description as part number', is_array($axle));
$h->test('preset honda_rep keeps BRAND as custom field', is_array($axle) && ($axle['extra']['Brand'] ?? '') === 'ASSTD BRAND');
$h->test('preset honda_rep maps quantity', is_array($axle) && (float)($axle['qty'] ?? 0) === 11.0);

// TIRE preset: composite part number = SIZE + BRAND + PATTERN.
$tireStage = ImportService::stage($ctx, $branchId, $brandId, $momFile, 'mom.xlsx', $mime, null, 0, 0, 1, null, 'tpl-tire', $tire);
$tire1 = $hondaRow($tireStage['rows'] ?? [], '8X400- MTL W/TUBE');
$h->test('preset tire builds composite part number', is_array($tire1));
$h->test('preset tire composite used as description', is_array($tire1) && ($tire1['description'] ?? '') === '8X400- MTL W/TUBE');
$h->test('preset tire maps quantity', is_array($tire1) && (float)($tire1['qty'] ?? 0) === 3.0);
$tire2 = $hondaRow($tireStage['rows'] ?? [], '10X90/90 DRIVEMAXX');
$h->test('preset tire composite omits blank parts', is_array($tire2));

// YAMAHA GEN preset: preferred sheet is picked by name even when not index 0.
$yamaha = ImportTemplateService::get($ctx, 'preset:yamaha_gen');
$yamStage = ImportService::stage($ctx, $branchId, $brandId, $momFile, 'mom.xlsx', $mime, null, 0, 0, 1, null, 'tpl-yamaha', $yamaha);
$h->test('preset yamaha_gen auto-selects its sheet by name', count($yamStage['rows'] ?? []) === 1 && str_starts_with((string)($yamStage['rows'][0]['part_number'] ?? ''), '2PH-E2651'));

// ── Fuzzy sheet-name matching (new / renamed sheets) ──────────────
$h->section('Fuzzy sheet-name matching');

// Sheets whose names are close but not identical to the preset names, plus one
// that matches nothing. Auto-selection must be case/spacing/punctuation
// tolerant (prefix + contains scoring, not exact equality).
$fuzzyFile = $tmpDir . '/fuzzy.xlsx';
moto_test_build_multi_xlsx($fuzzyFile, [
    ['name' => 'MOTORWORKS - MISCELLANEOUS', 'rows' => [['DESCRIPTION', 'PN'], ['Filler', 'X-0']]],
    ['name' => 'HONDA GEN 2026 PRICES', 'rows' => [
        ['DESCRIPTION', 'UNIT MODEL', 'PARTS NUMBER', 'QTY DISPLAY', 'QTY STOCK', 'CODE', 'PRICE', 'DATE OF GIVEN PRICE'],
        ['ARM COMP CAM', 'XRM110', '14500-035-020', '8', '', '', '500', ''],
    ]],
    ['name' => 'Tire - All sizes', 'rows' => [
        ['SIZE', 'BRAND', 'PATTERN', 'TYPE', 'QUANTITY', 'CODE', 'PRICE'],
        ['8X400-', 'MTL', 'W/TUBE', 'TT', '3', '', '1350'],
    ]],
]);

$fuzzyHonda = ImportService::stage($ctx, $branchId, $brandId, $fuzzyFile, 'fuzzy.xlsx', $mime, null, 0, 0, 1, null, 'tpl-fz-honda', $honda);
$h->test('fuzzy prefix sheet auto-selected (HONDA GEN 2026 PRICES)', count($fuzzyHonda['rows'] ?? []) === 1 && ($fuzzyHonda['rows'][0]['part_number'] ?? '') === '14500-035-020');

$fuzzyTire = ImportService::stage($ctx, $branchId, $brandId, $fuzzyFile, 'fuzzy.xlsx', $mime, null, 0, 0, 1, null, 'tpl-fz-tire', $tire);
$h->test('fuzzy prefix sheet auto-selected (Tire - All sizes)', count($fuzzyTire['rows'] ?? []) === 1 && str_contains((string)($fuzzyTire['rows'][0]['part_number'] ?? ''), '8X400-'));

// ── Parser alignment & explicit sheet choice ──────────────────────
$h->section('Row alignment & explicit sheet choice');

// An empty <row> element (blank separator) between the header and the data
// must not shift the row indices the client uses (dense row indexing on both
// sides, matching the wizard's client parser).
$gapFile = $tmpDir . '/gap.xlsx';
moto_test_build_xlsx($gapFile, [
    ['PART NO.', 'Description', 'Price'],
    [],
    ['P-100', 'Axle Front', '1200'],
    ['P-101', 'Cable Throttle', '450'],
]);
$gapStage = ImportService::stage($ctx, $branchId, $brandId, $gapFile, 'gap.xlsx', $mime, ['part_number' => 0, 'description' => 1, 'price' => 2], 0, 1, 2, null, 'tpl-gap');
$h->test('empty row element does not shift data rows', count($gapStage['rows'] ?? []) === 2 && ($gapStage['rows'][0]['part_number'] ?? '') === 'P-100' && ($gapStage['rows'][1]['part_number'] ?? '') === 'P-101');

// An explicit client sheet_index must be honored even when the template's
// preferred sheet name fuzzy-matches a different sheet.
$chooseFile = $tmpDir . '/choose.xlsx';
moto_test_build_multi_xlsx($chooseFile, [
    ['name' => 'HONDA GEN', 'rows' => [['DESCRIPTION', 'PARTS NUMBER'], ['HONDA-1', 'H100']]],
    ['name' => 'YAMAHA GEN', 'rows' => [['DESCRIPTION', 'PARTS NUMBER'], ['YAMAHA-1', 'Y100']]],
]);
$m1Stage = ImportService::stage($ctx, $branchId, $brandId, $chooseFile, 'choose.xlsx', $mime, ['part_number' => 1, 'description' => 0], 1, 0, 1, null, 'tpl-m1', $honda);
$h->test('explicit client sheet choice is honored over template match', count($m1Stage['rows'] ?? []) === 1 && ($m1Stage['rows'][0]['part_number'] ?? '') === 'Y100');

// ── Empty-column pruning ──────────────────────────────────────────
$h->section('Empty-column pruning');

// QTY STOCK (col 4) and DATE OF GIVEN PRICE (col 7) are blank in every data
// row → the honda_gen template's custom fields for them must be dropped.
// Unit Model (col 1) and CODE (col 5, row 2 = ASN) are populated → kept.
$pruneFile = $tmpDir . '/prune.xlsx';
moto_test_build_xlsx($pruneFile, [
    ['DESCRIPTION', 'UNIT MODEL', 'PARTS NUMBER', 'QTY DISPLAY', 'QTY STOCK', 'CODE', 'PRICE', 'DATE OF GIVEN PRICE'],
    ['ARM COMP CAM', 'XRM110', '14500-035-020', '8', '', '', '500', ''],
    ['BRAKE PAD', 'CB125', '14500-KYY-900', '6', '', 'ASN', '650', ''],
]);
$pruneStage = ImportService::stage($ctx, $branchId, $brandId, $pruneFile, 'prune.xlsx', $mime, null, 0, 0, 1, null, 'tpl-prune', $honda);
$pArm = $hondaRow($pruneStage['rows'] ?? [], '14500-035-020');
$h->test('empty custom column dropped from extra', is_array($pArm) && !array_key_exists('Qty Stock', $pArm['extra']) && !array_key_exists('Date of Given Price', $pArm['extra']));
$h->test('populated custom column kept in extra', is_array($pArm) && ($pArm['extra']['Unit Model'] ?? '') === 'XRM110');
$h->test('part number column always kept', is_array($pArm) && ($pArm['part_number'] ?? '') === '14500-035-020');
$h->test('partially populated code column kept', is_array($pArm) && ($pArm['code'] ?? '') === '');
$pPad = $hondaRow($pruneStage['rows'] ?? [], '14500-KYY-900');
$h->test('populated code preserved when column has any data', is_array($pPad) && ($pPad['code'] ?? '') === 'ASN');

// ── Sell Price + Code Price (decode) still rejected ───────────────
$h->section('Code semantics');

$bothRejected = false;
try {
    ImportService::stage($ctx, $branchId, $brandId, $momFile, 'both.xlsx', $mime, ['part_number' => 2, 'price' => 6, 'code' => 5], 0, 0, 1, null, 'tpl-both');
} catch (\InvalidArgumentException $e) {
    $bothRejected = str_contains($e->getMessage(), 'pick one');
}
$h->test('Sell Price + decode Code Price still rejected', $bothRejected);

// ── Custom template lifecycle ─────────────────────────────────────
$h->section('Custom templates (save / list / stage / update / delete)');

$saved = ImportTemplateService::saveCustom($ctx, [
    'name'   => 'RUSI 2026',
    'sheet'  => 'RUSI',
    'header_row' => 1,
    'data_start_row' => 2,
    'mapping' => ['part_number' => 0, 'description' => 1, 'price' => 2, 'custom:Model' => 3],
    'code_mode' => 'attribute',
    'part_number_source' => 'column',
]);
$h->test('custom template saved', ($saved['id'] ?? 0) > 0 && ($saved['kind'] ?? '') === 'custom');
$h->test('custom template resolves by key', ImportTemplateService::get($ctx, 'custom:' . $saved['id']) !== null);
$all2 = ImportTemplateService::all($ctx);
$h->test('custom template listed with presets', count($all2['custom'] ?? []) === 1 && count($all2['presets'] ?? []) === 16);
$custom = ImportTemplateService::get($ctx, 'custom:' . $saved['id']);
$h->test('custom template mapping returned col→field', ($custom['mapping'][0] ?? null) === 'part_number');

// Stage an import using the saved custom template (single-sheet file).
$rusiFile = $tmpDir . '/rusi.xlsx';
moto_test_build_xlsx($rusiFile, [
    ['PART NO.', 'Description', 'Price', 'Model'],
    ['R-100', 'Axle Front', '1200', 'RUSI 125'],
    ['R-101', 'Cable Throttle', '450', 'RUSI 125'],
]);
$customStage = ImportService::stage($ctx, $branchId, $brandId, $rusiFile, 'rusi.xlsx', $mime, null, 0, 0, 1, null, 'tpl-rusi', $custom);
$r100 = $hondaRow($customStage['rows'] ?? [], 'R-100');
$h->test('custom template stages its mapping', is_array($r100) && (float)($r100['price'] ?? 0) === 1200.0);
$h->test('custom template keeps custom field', is_array($r100) && ($r100['extra']['Model'] ?? '') === 'RUSI 125');

// Save with a description part-number source (no part_number column).
// rusiFile columns: 0=PART NO., 1=Description, 2=Price, 3=Model.
$descTpl = ImportTemplateService::saveCustom($ctx, [
    'name' => 'DESC-BASED',
    'mapping' => ['description' => 1, 'price' => 2],
    'part_number_source' => 'description',
]);
$descStage = ImportService::stage($ctx, $branchId, $brandId, $rusiFile, 'rusi.xlsx', $mime, null, 0, 0, 1, null, 'tpl-desc', ImportTemplateService::get($ctx, 'custom:' . $descTpl['id']));
$h->test('custom description-as-part-number template stages', ($descStage['rows'][0]['part_number'] ?? '') === 'Axle Front');

// A custom template that can never produce a part number is refused.
$badRejected = false;
try {
    ImportTemplateService::saveCustom($ctx, ['name' => 'BAD', 'mapping' => ['description' => 0]]);
} catch (\InvalidArgumentException $e) {
    $badRejected = true;
}
$h->test('custom template without part number source rejected', $badRejected);

// Update renames the template.
$updated = ImportTemplateService::saveCustom($ctx, [
    'id' => $saved['id'], 'name' => 'RUSI 2027', 'mapping' => ['part_number' => 0, 'price' => 2],
]);
$h->test('custom template updated', ($updated['name'] ?? '') === 'RUSI 2027');

// Delete removes it (DESC-BASED from earlier remains).
ImportTemplateService::deleteCustom($ctx, $saved['id']);
$h->test('custom template deleted', count(ImportTemplateService::customTemplates($ctx)) === 1);
$h->test('deleted template no longer resolves', ImportTemplateService::get($ctx, 'custom:' . $saved['id']) === null);

$delRejected = false;
try {
    ImportTemplateService::deleteCustom($ctx, $saved['id']);
} catch (\InvalidArgumentException $e) {
    $delRejected = true;
}
$h->test('re-deleting a template is refused', $delRejected);

// A custom template whose preferred sheet name is absent from the workbook
// must not auto-match an unrelated sheet — the caller's sheet index wins.
$h->section('Unmatched template sheet');

$ghost = ImportTemplateService::saveCustom($ctx, [
    'name' => 'GHOST BRAND', 'sheet' => 'RUSI 3000',
    'mapping' => ['part_number' => 0, 'description' => 1],
]);
$ghostTpl = ImportTemplateService::get($ctx, 'custom:' . $ghost['id']);
$ghostStage = ImportService::stage($ctx, $branchId, $brandId, $fuzzyFile, 'fuzzy.xlsx', $mime, null, 0, 0, 1, null, 'tpl-fz-ghost', $ghostTpl);
$h->test('unmatched template sheet stays on caller sheet', ($ghostStage['rows'][0]['part_number'] ?? '') === 'Filler');

// Cleanup
@unlink($momFile);
@unlink($rusiFile);
@unlink($fuzzyFile);
@unlink($pruneFile);
@unlink($gapFile);
@unlink($chooseFile);
@rmdir($tmpDir);
$tenant['cleanup']();
$h->done();
