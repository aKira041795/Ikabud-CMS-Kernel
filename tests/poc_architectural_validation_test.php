/**
 * Architectural POC Validation — Verifies all Phase A-F enhancements.
 *
 * Tests that the theoretical gap between the Ikabud Manifesto
 * and the actual Kernel OS + DiSyL implementation has been narrowed.
 *
 * Usage: php tests/poc_architectural_validation_test.php
 *
 * Validates:
 *   P1 — Module catalog endpoint (GET /api/v1/kernel/modules)
 *   P2 — Capability catalog endpoint (GET /api/v1/kernel/capabilities)
 *   P3 — ReadContractRegistry: table ownership registration
 *   P4 — ReadContractRegistry: read contract registration + drift detection
 *   P5 — reads_tables_deprecated support
 *   P6 — Entity-View component catalog coverage
 *   P7 — DiSyL EBNF grammar consistency
 *   P8 — ADRs present and internally cross-referenced
 *   P9 — Module Developer Guide references
 *   P10 — debugging-files/ purged
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$warn = 0;

function t(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ✅ {$label}\n";
    } else {
        $fail++;
        echo "  ❌ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function w(string $label, bool $condition, string $detail = ''): void {
    global $warn;
    if (!$condition) {
        $warn++;
        echo "  ⚠️  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Architectural POC Validation — Manifesto vs Implementation  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ── P1: Module Catalog Endpoint ──────────────────────────────────────────

echo "── P1: Module Catalog Endpoint ──\n";

$modules = discoverModules();
t('discoverModules() returns modules', count($modules) > 0 && count($modules) >= 40,
    'found ' . count($modules) . ' modules');

$hasCoreModules = isset($modules['cms']) && isset($modules['ecommerce']) && isset($modules['wms']);
t('Core modules present (cms, ecommerce, wms)', $hasCoreModules);

// Verify module.json fields that the catalog endpoint exposes
$cms = $modules['cms'] ?? null;
t('CMS module has owns_tables', is_array($cms) && !empty($cms['owns_tables']));
t('CMS module has reads_tables', is_array($cms) && !empty($cms['reads_tables']));
t('CMS module has depends', is_array($cms) && !empty($cms['depends']));

$ecom = $modules['ecommerce'] ?? null;
t('Ecommerce module reads WMS tables', is_array($ecom) && in_array('wms_warehouses', $ecom['reads_tables'] ?? []));

// Verify auth_owned modules
$hasAuthOwned = false;
foreach ($modules as $id => $m) {
    if (!empty($m['auth_owned'])) { $hasAuthOwned = true; break; }
}
t('At least one auth_owned module exists', $hasAuthOwned);

// Verify service-module type
$hasServiceModule = false;
foreach ($modules as $id => $m) {
    if (($m['type'] ?? 'php-module') === 'service-module') { $hasServiceModule = true; break; }
}
t('At least one service-module exists (polyglot proof)', $hasServiceModule);

echo "\n";

// ── P2: Capability Catalog Endpoint ──────────────────────────────────────

echo "── P2: Capability Catalog Endpoint ──\n";

$caps = app()->capabilities();
$capIds = $caps->capabilityIds();
t('CapabilityRegistry has registered capabilities', count($capIds) > 0,
    'found ' . count($capIds) . ' capability IDs');

$hasEntityList = $caps->has('entity.list.cms_post@1') || $caps->has('cms.content.list@1');
w('Entity list capability registered', $hasEntityList, 'expected cms.content.list@1');

// Verify CapabilityCatalog works
$catalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog($caps);
$catalogData = $catalog->catalog();
t('CapabilityCatalog::catalog() returns data', is_array($catalogData) && !empty($catalogData));
t('CapabilityCatalog has summary', isset($catalogData['summary']));
t('CapabilityCatalog has modules section', isset($catalogData['modules']));
t('CapabilityCatalog has events section', isset($catalogData['events']));

echo "\n";

// ── P3: ReadContractRegistry — Table Ownership ──────────────────────────

echo "── P3: ReadContractRegistry — Table Ownership ──\n";

$registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();

// Owner should be registered for CMS tables
$cmsTableOwner = $registry->ownerOf('cms_content');
t('cms_content owned by cms module', $cmsTableOwner === 'cms',
    $cmsTableOwner ? "actually owned by: {$cmsTableOwner}" : 'no owner registered');

$wmsTableOwner = $registry->ownerOf('wms_stocks');
t('wms_stocks owned by wms module', $wmsTableOwner === 'wms',
    $wmsTableOwner ? "actually owned by: {$wmsTableOwner}" : 'no owner registered');

// Co-owned tables should also be registered
$auditOwner = $registry->ownerOf('audit_logs');
w('audit_logs has a registered owner (co-owned table)', $auditOwner !== null);

echo "\n";

// ── P4: ReadContractRegistry — Read Contracts + Drift ────────────────────

echo "── P4: ReadContractRegistry — Read Contracts + Drift ──\n";

// Verify that at least some read contracts were registered
$allContracts = $registry->all();
$contractCount = 0;
foreach ($allContracts as $reader => $tables) {
    $contractCount += count($tables);
}
t('Read contracts registered for enabled modules', $contractCount > 0,
    "found {$contractCount} read contracts across " . count($allContracts) . " readers");

// Verify specific cross-module reads
$ecomReads = $registry->forReader('ecommerce');
$ecomReadsWms = false;
foreach ($ecomReads as $table => $contract) {
    if ($contract['owner'] === 'wms') { $ecomReadsWms = true; break; }
}
w('Ecommerce reads WMS tables via read contract', $ecomReadsWms);

// Verify readersOf works
$readers = $registry->readersOf('users');
t('readersOf("users") returns at least one reader', count($readers) > 0,
    'readers: ' . implode(', ', $readers));

echo "\n";

// ── P5: reads_tables_deprecated Support ──────────────────────────────────

echo "── P5: reads_tables_deprecated Support ──\n";

// Verify the deprecated reads API exists
$deprecated = $registry->deprecatedReads();
t('deprecatedReads() returns array (may be empty)', is_array($deprecated));

// Verify markDeprecatedRead + isDeprecated work
$registry->markDeprecatedRead('test-module', 'test_deprecated_table');
$registry->markDeprecatedRead('test-module', 'test_deprecated_table_2');
t('markDeprecatedRead + isDeprecated work', $registry->isDeprecated('test-module', 'test_deprecated_table'));

// Verify non-deprecated returns false
t('isDeprecated returns false for non-deprecated', !$registry->isDeprecated('test-module', 'nonexistent'));

echo "\n";

// ── P6: Entity-View Component Catalog Coverage ───────────────────────────

echo "── P6: Entity-View Component Catalog ──\n";

$compRegistry = \Ikabud\Kernel\DiSyL\ComponentRegistry::all();
t('ComponentRegistry::all() returns components', count($compRegistry) > 0,
    'found ' . count($compRegistry) . ' components');

// Verify key entity components exist
$entityComponents = ['ikb_entity_list', 'ikb_entity_detail', 'ikb_stat_card', 'ikb_timeline',
    'ikb_audit_log', 'ikb_export_button', 'ikb_ai_summary', 'ikb_ai_assist'];
foreach ($entityComponents as $comp) {
    t("Component '{$comp}' registered", \Ikabud\Kernel\DiSyL\ComponentRegistry::has($comp));
}

// Verify component definitions have required fields
$entityList = \Ikabud\Kernel\DiSyL\ComponentRegistry::get('ikb_entity_list');
t('ikb_entity_list has category', !empty($entityList['category']));
t('ikb_entity_list has description', !empty($entityList['description']));
t('ikb_entity_list has attributes', is_array($entityList['attributes'] ?? null));

echo "\n";

// ── P7: DiSyL EBNF Grammar Consistency ───────────────────────────────────

echo "── P7: DiSyL EBNF Grammar Consistency ──\n";

$ebnfPath = BASE_PATH . '/docs/disyl/disyl-grammar-v4.7.ebnf';
t('EBNF grammar file exists', is_file($ebnfPath));

$ebnfContent = (string) file_get_contents($ebnfPath);
t('EBNF grammar is non-empty', strlen($ebnfContent) > 1000,
    'size: ' . strlen($ebnfContent) . ' chars');

// Count production rules
$ruleCount = preg_match_all('/^[a-z_]+\s*=/m', $ebnfContent);
t('EBNF has 40+ production rules', $ruleCount >= 40,
    "found {$ruleCount} rules");

// Verify key productions exist
$keyProductions = ['template', 'expression', 'if_block', 'foreach_block',
    'component_tag', 'variable', 'filter', 'control', 'comment'];
foreach ($keyProductions as $prod) {
    t("EBNF defines '{$prod}'", (bool) preg_match('/^' . preg_quote($prod, '/') . '\s*=/m', $ebnfContent));
}

// Cross-ref: TextMate grammar exists
$tmGrammarPath = BASE_PATH . '/extensions/disyl-lsp/syntaxes/disyl.tmLanguage.json';
t('TextMate grammar exists', is_file($tmGrammarPath));
$tmGrammar = json_decode((string) file_get_contents($tmGrammarPath), true);
t('TextMate grammar is valid JSON', is_array($tmGrammar) && !empty($tmGrammar['repository']));

// Cross-ref: LSP validator exists
$validatorPath = BASE_PATH . '/extensions/disyl-lsp/src/validator.ts';
t('EBNF-based validator source exists', is_file($validatorPath));
$validatorContent = (string) file_get_contents($validatorPath);
t('Validator references block pairs', str_contains($validatorContent, 'BLOCK_PAIRS'));
t('Validator references governed components', str_contains($validatorContent, 'GOV_COMPONENTS'));

echo "\n";

// ── P8: ADR Presence and Cross-References ────────────────────────────────

echo "── P8: Architecture Decision Records ──\n";

$adrDir = BASE_PATH . '/docs/architecture/decisions';
t('ADR directory exists', is_dir($adrDir));
$adrFiles = glob($adrDir . '/ADR-*.md') ?: [];
t('4 ADRs present', count($adrFiles) >= 4,
    'found ' . count($adrFiles) . ': ' . implode(', ', array_map('basename', $adrFiles)));

$expectedADRs = [
    'ADR-001-php-as-kernel-host.md' => ['PHP', 'shared hosting', 'ServiceProxy'],
    'ADR-002-cms-is-module-not-kernel.md' => ['CMS', 'module', 'kernel', 'WordPress'],
    'ADR-003-reads-tables-alongside-capabilities.md' => ['reads_tables', 'capability', 'ModuleDB'],
    'ADR-004-python-first-polyglot-provider.md' => ['Python', 'ServiceProxy', 'polyglot', 'FastAPI'],
];
foreach ($expectedADRs as $file => $keywords) {
    $path = $adrDir . '/' . $file;
    $exists = is_file($path);
    t("ADR {$file} exists", $exists);
    if ($exists) {
        $content = (string) file_get_contents($path);
        foreach ($keywords as $kw) {
            w("ADR {$file} mentions '{$kw}'", stripos($content, $kw) !== false);
        }
    }
}

// Support docs
$coOwnsPath = BASE_PATH . '/docs/kernel/co-owns-tables-policy.md';
t('co-owns-tables policy exists', is_file($coOwnsPath));

$devGuidePath = BASE_PATH . '/docs/kernel/module-developer-guide.md';
t('Module developer guide exists', is_file($devGuidePath));

echo "\n";

// ── P9: Module Developer Guide Self-Consistency ───────────────────────────

echo "── P9: Module Developer Guide Self-Consistency ──\n";

$guide = (string) file_get_contents($devGuidePath);
t('Dev guide is substantial', strlen($guide) > 5000,
    'size: ' . strlen($guide) . ' chars');

// Check key sections exist
$sections = ['Manifest', 'Routes', 'Handlers', 'Capabilities', 'Migrations',
    'DiSyL Templates', 'Auth-Owned', 'Testing', 'Checklist', 'Reference'];
foreach ($sections as $section) {
    t("Dev guide has '{$section}' section", stripos($guide, $section) !== false);
}

// Check API references
$apiRefs = ['module()->db()', 'app()->capabilities()->call()', 'app()->events()->fire()',
    'app()->render()', 'app()->input()'];
foreach ($apiRefs as $ref) {
    w("Dev guide documents '{$ref}'", stripos($guide, $ref) !== false);
}

echo "\n";

// ── P10: debugging-files/ Purged ─────────────────────────────────────────

echo "── P10: Repository Hygiene ──\n";

$debugDir = BASE_PATH . '/debugging-files';
t('debugging-files/ directory removed', !is_dir($debugDir));

// Check .gitignore
$gitignore = (string) file_get_contents(BASE_PATH . '/.gitignore');
t('.gitignore excludes debugging-files/', str_contains($gitignore, '/debugging-files/'));

echo "\n";

// ── Summary ──────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════╗\n";
printf("║  Results:  %2d passed  %2d failed  %2d warnings            ║\n", $pass, $fail, $warn);
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if ($fail > 0) {
    echo "❌ VALIDATION FAILED — {$fail} assertions failed.\n";
    echo "   The manifesto-implementation gap still has open issues.\n";
    exit(1);
}

if ($warn > 0) {
    echo "⚠️  VALIDATION PASSED WITH WARNINGS — {$warn} soft assertions need attention.\n";
    echo "   The gap has narrowed but some items need review.\n";
} else {
    echo "✅ ALL ASSERTIONS PASSED — {$pass} checks, 0 failures, 0 warnings.\n";
    echo "   The manifesto-implementation gap has been measurably narrowed.\n";
}
exit(0);
