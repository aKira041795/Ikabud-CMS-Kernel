<?php
/**
 * Kernel OS 5.0 — Proof of Concept: End-to-End Render Test
 *
 * Verifies that the new governed DiSyL components render correctly
 * in real template contexts.
 *
 * Usage: php tests/poc_render_test.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/cms/helpers/58-entity-views.php';

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { echo "  ✓ {$label}\n"; $pass++; }
    else { echo "  ✗ {$label}"; if ($detail) echo " — {$detail}"; echo "\n"; $fail++; }
}

$pass = 0; $fail = 0;
$engine = app()->templates();

echo "╔══════════════════════════════════════════════════╗\n";
echo "║   Kernel OS 5.0 — POC Render Test              ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── 1. Entity list renders error state (no data source) ──
echo "── 1. Entity Components ──\n";
$r = $engine->renderString('{ikb_entity_list source="cms.post.recent" view="compact" limit="3" /}', []);
t('ikb_entity_list renders without crash', is_string($r) && !str_contains($r, 'Fatal'), 'Length: ' . strlen($r));
t('ikb_entity_list produces error state gracefully', str_contains($r, 'ikb-entity-error') || str_contains($r, 'ikb-entity-list'), substr($r, 0, 80));

$r = $engine->renderString('{ikb_entity_detail source="cms.page" id="1" view="detailed" /}', []);
t('ikb_entity_detail renders without crash', is_string($r), 'Length: ' . strlen($r));

// ── 2. Stat cards ──
echo "── 2. Dashboard Components ──\n";
$r = $engine->renderString(
    '{ikb_stat_card label="Revenue" value="$12,430" trend="up" trend_value="+8.2%" icon="chart-line" /}' .
    '{ikb_stat_card label="Users" value="1,204" icon="users" /}',
    []
);
t('ikb_stat_card renders metric values', str_contains($r, '$12,430') && str_contains($r, '1,204'), '');
t('ikb_stat_card renders trend indicator', str_contains($r, '+8.2%'), '');

// ── 3. Forms ──
echo "── 3. Form Components ──\n";
$r = $engine->renderString(
    '{ikb_form action="ticket.create" layout="stacked"}{ikb_input name="subject" placeholder="Describe the issue" /}{/ikb_form}',
    ['csrf_token' => 'poc-test-token']
);
t('ikb_form renders CSRF token', str_contains($r, '_token'), '');
t('ikb_form wraps input fields', str_contains($r, '<input') && str_contains($r, 'subject'), '');
t('ikb_form has stacked layout class', str_contains($r, 'ikb-form--stacked'), '');

// ── 4. Export button ──
echo "── 4. Export Components ──\n";
$r = $engine->renderString('{ikb_export_button source="cms.post" format="csv" label="Download CSV" /}', []);
t('ikb_export_button renders download link', str_contains($r, 'Download CSV') && str_contains($r, '/api/v1/export'), '');
t('ikb_export_button has SVG icon', str_contains($r, '<svg'), '');

// ── 5. Confirm action ──
echo "── 5. Safety Components ──\n";
$r = $engine->renderString(
    '{ikb_confirm_action message="Delete this item?" variant="danger"}<button>Delete</button>{/ikb_confirm_action}',
    []
);
t('ikb_confirm_action wraps child button', str_contains($r, 'Delete'), '');
t('ikb_confirm_action includes message', str_contains($r, 'Delete this item?'), '');
t('ikb_confirm_action uses Alpine.js', str_contains($r, 'x-data'), '');

// ── 6. Panel with tokens ──
echo "── 6. Theme Token Components ──\n";
$r = $engine->renderString('{ikb_panel tone="elevated" spacing="lg" radius="lg"}<p>Content</p>{/ikb_panel}', []);
t('ikb_panel applies tone (shadow)', str_contains($r, 'shadow-md'), '');
t('ikb_panel applies spacing (lg=p-8)', str_contains($r, 'p-8'), '');
t('ikb_panel applies radius (lg=rounded-2xl)', str_contains($r, 'rounded-2xl'), '');

// ── 7. Report + signature ──
echo "── 7. Report Components ──\n";
$r = $engine->renderString(
    '{ikb_report title="Daily Summary" format="official"}{ikb_entity_list source="cms.post.recent" view="compact" limit="3" /}{ikb_signature_block roles="Prepared By,Reviewed By" /}{/ikb_report}',
    []
);
t('ikb_report renders title', str_contains($r, 'Daily Summary'), '');
t('ikb_signature_block renders role labels', str_contains($r, 'Prepared By') && str_contains($r, 'Reviewed By'), '');
t('ikb_signature_block has signature lines', str_contains($r, 'Date:'), '');

// ── 8. AI components ──
echo "── 8. AI Components ──\n";
$r = $engine->renderString('{ikb_ai_summary source="cms.post.recent" review="required" /}', []);
t('ikb_ai_summary renders AI badge', str_contains($r, 'AI Summary'), '');
t('ikb_ai_summary shows draft badge when review=required', str_contains($r, 'Draft'), '');

$r = $engine->renderString('{ikb_ai_assist capability="case.draft_report" mode="draft_only" /}', []);
t('ikb_ai_assist renders without crash', is_string($r), 'Length: ' . strlen($r));

// ── 9. Drawer + timeline ──
echo "── 9. Layout Components ──\n";
$r = $engine->renderString('{ikb_drawer id="info" title="Details"}<p>Info</p>{/ikb_drawer}', []);
t('ikb_drawer renders content', str_contains($r, 'Info'), '');
t('ikb_drawer uses teleport', str_contains($r, 'x-teleport'), '');

$r = $engine->renderString('{ikb_timeline}<div>Step 1</div>{/ikb_timeline}', []);
t('ikb_timeline renders child', str_contains($r, 'Step 1'), '');

// ── 10. View contracts resolve ──
echo "── 10. Entity-View Contracts ──\n";
$resolver = app()->entityViews();
$views = $resolver->registeredViews();
t('View contracts registered', count($views) >= 13, count($views) . ' contracts');

$contract = $resolver->viewContract('cms.post', 'card_grid');
t('cms.post.card_grid has fields', !empty($contract['fields']) && $contract['fields'] !== '*', implode(',', (array)$contract['fields']));
t('cms.post.card_grid has actions', !empty($contract['actions']), '');
t('cms.page.default has capability gate', ($resolver->viewContract('cms.page', 'default')['capability'] ?? '') === 'cms.content.list@1', '');

// ── 11. Template files use new components ──
echo "── 11. Template Adoption ──\n";
$dashContent = file_get_contents(__DIR__ . '/../templates/modules/cms/admin/dashboard.disyl');
t('Dashboard uses ikb_entity_list', str_contains($dashContent, 'ikb_entity_list'), '');
t('Dashboard uses ikb_stat_card', str_contains($dashContent, 'ikb_stat_card'), '');

$listContent = file_get_contents(__DIR__ . '/../templates/modules/cms/admin/content-list.disyl');
t('Content list uses ikb_confirm_action', str_contains($listContent, 'ikb_confirm_action'), '');
t('Content list uses ikb_export_button', str_contains($listContent, 'ikb_export_button'), '');

$gdContent = file_get_contents(__DIR__ . '/../templates/modules/guidance/pages/dashboard.disyl');
t('Guidance dashboard uses ikb_panel', str_contains($gdContent, 'ikb_panel'), '');
t('Guidance dashboard uses ikb_entity_list', str_contains($gdContent, 'ikb_entity_list'), '');

// ── Summary ──
echo "\n" . str_repeat('─', 55) . "\n";
$total = $pass + $fail;
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) echo ", {$fail} FAILED";
echo "\n\n";

exit($fail > 0 ? 1 : 0);
