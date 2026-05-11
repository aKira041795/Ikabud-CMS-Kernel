<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'ehr.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/login';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../src/http/page-handlers.php';
require_once __DIR__ . '/../src/http/auth-handlers.php';
require_once __DIR__ . '/../kernel/Http/TenantEntryRouter.php';
require_once modulePathForId('ehr') . '/helpers.php';
require_once modulePathForId('ehr') . '/handlers.php';
require_once modulePathForId('encounters') . '/helpers.php';
require_once modulePathForId('patient-registry') . '/helpers.php';
require_once modulePathForId('scheduling') . '/helpers.php';
require_once modulePathForId('orders') . '/helpers.php';
require_once modulePathForId('reporting') . '/helpers.php';

loadModuleRoutes([
    'GET' => [],
    'POST' => [],
    'PUT' => [],
    'DELETE' => [],
]);

$pass = 0;
$fail = 0;
$errors = [];

function et(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function etResetCurrentUser(): void
{
    static $currentUserProperty = null;
    if (!$currentUserProperty instanceof ReflectionProperty) {
        $currentUserProperty = new ReflectionProperty(app(), 'currentUser');
        $currentUserProperty->setAccessible(true);
    }

    $currentUserProperty->setValue(app(), null);
}

echo "=== EHR AUTH EXPERIENCE TEST ===\n\n";

$coreRoutes = kernelCoreRoutes();
$ehrRoutes = require modulePathForId('ehr') . '/routes.php';
$encounterRoutes = require modulePathForId('encounters') . '/routes.php';
$patientRegistryRoutes = require modulePathForId('patient-registry') . '/routes.php';
$schedulingRoutes = require modulePathForId('scheduling') . '/routes.php';
$clinicalNotesRoutes = require modulePathForId('clinical-notes') . '/routes.php';
$ordersRoutes = require modulePathForId('orders') . '/routes.php';
$resultsRoutes = require modulePathForId('results') . '/routes.php';
$prescriptionsRoutes = require modulePathForId('prescriptions') . '/routes.php';
$documentsRoutes = require modulePathForId('documents') . '/routes.php';
$privacyRoutes = require modulePathForId('privacy-consent') . '/routes.php';
$auditRoutes = require modulePathForId('audit') . '/routes.php';
$billingRoutes = require modulePathForId('billing-bridge') . '/routes.php';

et('kernel forgot password page route declared', ($coreRoutes['GET']['/forgot-password'] ?? '') === 'authForgotPasswordPage');
et('kernel reset password page route declared', ($coreRoutes['GET']['/reset-password'] ?? '') === 'authResetPasswordPage');
et('kernel forgot password API route declared', ($coreRoutes['POST']['/api/v1/auth/forgot-password'] ?? '') === 'authForgotPassword');
et('kernel reset password API route declared', ($coreRoutes['POST']['/api/v1/auth/reset-password'] ?? '') === 'authResetPassword');
et('ehr forgot password page route declared', ($ehrRoutes['GET']['/ehr/forgot-password'] ?? '') === 'ehr:ehrForgotPasswordPage');
et('ehr reset password page route declared', ($ehrRoutes['GET']['/ehr/reset-password'] ?? '') === 'ehr:ehrResetPasswordPage');
et('ehr auth login API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/login'] ?? '') === 'ehr:ehrAuthLogin');
et('ehr forgot password API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/forgot-password'] ?? '') === 'ehr:ehrApiForgotPassword');
et('ehr reset password API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/reset-password'] ?? '') === 'ehr:ehrApiResetPassword');
et('ehr dashboard page route declared', ($ehrRoutes['GET']['/admin/ehr'] ?? '') === 'ehr:ehrDashboardPage');
et('ehr settings page route declared', ($ehrRoutes['GET']['/admin/ehr/settings'] ?? '') === 'ehr:ehrSettingsPage');
et('appointments page route declared', ($schedulingRoutes['GET']['/admin/ehr/appointments'] ?? '') === 'scheduling:schedPageIndex');
et('appointments queue monitor route declared', ($schedulingRoutes['GET']['/ehr/queue-monitor'] ?? '') === 'scheduling:schedPageMonitor');
et('appointments save route declared', ($schedulingRoutes['POST']['/admin/ehr/appointments'] ?? '') === 'scheduling:schedSaveAppointment');
et('appointments handoff route declared', ($schedulingRoutes['POST']['/admin/ehr/appointments/handoff'] ?? '') === 'scheduling:schedHandoffAppointment');
et('encounters page route declared', ($encounterRoutes['GET']['/admin/ehr/encounters'] ?? '') === 'encounters:encPageIndex');
et('patient registry page route declared', ($patientRegistryRoutes['GET']['/admin/ehr/patients'] ?? '') === 'patient-registry:prPageIndex');
et('patient registry save route declared', ($patientRegistryRoutes['POST']['/admin/ehr/patients'] ?? '') === 'patient-registry:prSavePatient');
et('clinical notes page route declared', ($clinicalNotesRoutes['GET']['/admin/ehr/notes'] ?? '') === 'clinical-notes:cnPageIndex');
et('orders page route declared', ($ordersRoutes['GET']['/admin/ehr/orders'] ?? '') === 'orders:ordPageIndex');
et('orders save route declared', ($ordersRoutes['POST']['/admin/ehr/orders'] ?? '') === 'orders:ordSaveOrder');
et('results page route declared', ($resultsRoutes['GET']['/admin/ehr/results'] ?? '') === 'results:resPageIndex');
et('prescriptions page route declared', ($prescriptionsRoutes['GET']['/admin/ehr/prescriptions'] ?? '') === 'prescriptions:rxPageIndex');
et('documents page route declared', ($documentsRoutes['GET']['/admin/ehr/documents'] ?? '') === 'documents:docPageIndex');
et('privacy page route declared', ($privacyRoutes['GET']['/admin/ehr/privacy'] ?? '') === 'privacy-consent:pcPageIndex');
et('audit page route declared', ($auditRoutes['GET']['/admin/ehr/audit'] ?? '') === 'audit:audPageIndex');
et('billing signals page route declared', ($billingRoutes['GET']['/admin/ehr/billing'] ?? '') === 'billing-bridge:bbPageIndex');
et('encounters save route declared', ($encounterRoutes['POST']['/admin/ehr/encounters'] ?? '') === 'encounters:encSaveEncounter');
et('encounters vitals route declared', ($encounterRoutes['POST']['/admin/ehr/encounters/vitals'] ?? '') === 'encounters:encSaveVitals');
et('clinical notes save route declared', ($clinicalNotesRoutes['POST']['/admin/ehr/notes'] ?? '') === 'clinical-notes:cnSaveNote');
et('prescriptions save route declared', ($prescriptionsRoutes['POST']['/admin/ehr/prescriptions'] ?? '') === 'prescriptions:rxSavePrescription');
et('results save route declared', ($resultsRoutes['POST']['/admin/ehr/results'] ?? '') === 'results:resSaveResult');
et('results transition route declared', ($resultsRoutes['POST']['/admin/ehr/results/transition'] ?? '') === 'results:resTransitionResult');
et('documents save route declared', ($documentsRoutes['POST']['/admin/ehr/documents'] ?? '') === 'documents:docSaveDocument');
et('privacy consent save route declared', ($privacyRoutes['POST']['/admin/ehr/privacy/consents'] ?? '') === 'privacy-consent:pcSaveConsent');
et('privacy break-glass save route declared', ($privacyRoutes['POST']['/admin/ehr/privacy/break-glass'] ?? '') === 'privacy-consent:pcSaveBreakGlass');
et('ehr module db helper returns ModuleDB contract', ehrDb() instanceof \Ikabud\Kernel\Contracts\ModuleDB, get_debug_type(ehrDb()));
et('front desk landing points to front desk lane', ehrRoleLandingUrl('front_desk') === '/admin/ehr/appointments?lane=front_desk');
et('nurse landing points to nurse lane', ehrRoleLandingUrl('nurse') === '/admin/ehr/appointments?lane=nurse');
et('physician landing points to physician lane', ehrRoleLandingUrl('physician') === '/admin/ehr/appointments?lane=physician');
et('pharmacist landing points to pharmacist lane', ehrRoleLandingUrl('pharmacist') === '/admin/ehr/appointments?lane=pharmacist');

$loginContext = ehrLoginPageContext([
    'login_favicon_url' => '/assets/ehr-favicon.ico',
]);

try {
    $tvStmt = app()->db()->prepare('SELECT COALESCE(token_version, 0) AS token_version FROM ehr_users WHERE id = ? LIMIT 1');
    $tvStmt->execute([1]);
    $ehrTokenVersion = (int)($tvStmt->fetchColumn() ?: 0);
} catch (\Throwable $ignored) {
    $ehrTokenVersion = 0;
}

$validEhrToken = app()->jwt()->generate([
    'sub' => 'ehr:1',
    'id' => 1,
    'username' => 'ehradmin',
    'name' => 'EHR Admin',
    'email' => 'charlienacario884@gmail.com',
    'role' => 'admin',
    'source' => 'ehr',
    'tenant_id' => 426,
    'token_version' => $ehrTokenVersion,
]);

$originalCookies = $_COOKIE;
etResetCurrentUser();
$_COOKIE = [
    (string)config('app.cookie_name', 'applicationos_test_token') => 'definitely.invalid.jwt',
    ehrCookieName() => $validEhrToken,
];
$resolvedUser = app()->user();
et('ehr user resolution falls through stale kernel cookie to valid ehr token', is_array($resolvedUser) && ($resolvedUser['source'] ?? '') === 'ehr', json_encode($resolvedUser, JSON_UNESCAPED_SLASHES));
$_COOKIE = $originalCookies;
etResetCurrentUser();

$loginHtml = app()->render('pages/login.disyl', $loginContext);
et('ehr login context renders EHR CTA', str_contains($loginHtml, 'Open EHR'));
et('ehr login context renders forgot password link', str_contains($loginHtml, '/forgot-password'));
et('ehr login context posts to module auth endpoint', str_contains($loginHtml, '/api/v1/ehr/auth/login'));
et('ehr login context renders custom favicon hook', str_contains($loginHtml, '/assets/ehr-favicon.ico'));

$forgotHtml = app()->render('pages/forgot-password.disyl', array_merge($loginContext, [
    'forgot_password_endpoint' => '/api/v1/ehr/auth/forgot-password',
    'login_page_url' => '/login',
]));
et('forgot password template posts to module auth reset API', str_contains($forgotHtml, '/api/v1/ehr/auth/forgot-password'));
et('forgot password template links back to login', str_contains($forgotHtml, 'Back to sign in'));

$resetHtml = app()->render('pages/reset-password.disyl', array_merge($loginContext, [
    'reset_password_endpoint' => '/api/v1/ehr/auth/reset-password',
    'login_page_url' => '/login',
    'reset_token' => str_repeat('a', 64),
    'token_valid' => true,
]));
et('reset password template posts to module auth reset endpoint', str_contains($resetHtml, '/api/v1/ehr/auth/reset-password'));
et('reset password template renders reset action', str_contains($resetHtml, 'Reset Password'));

$ehrAdminUser = [
    'id' => 1,
    'username' => 'ehradmin',
    'full_name' => 'EHR Admin',
    'role' => 'admin',
    'source' => 'ehr',
];

$crudSeed = 'ehrtest_' . bin2hex(random_bytes(4));
$createdPatient = patient_registry_cap_ehr_patient_create_1([
    'first_name' => 'Create',
    'last_name' => 'Coverage-' . $crudSeed,
    'birth_date' => '1988-05-09',
    'sex' => 'female',
    'status' => 'active',
    'primary_phone' => '09175550000',
    'email' => $crudSeed . '@example.test',
    'identifiers' => [[
        'type' => 'MRN',
        'value' => strtoupper($crudSeed),
        'issuing_authority' => 'EHR TEST',
        'is_primary' => true,
        'status' => 'active',
    ]],
], 'ehr.patient.create@1', 'patient-registry');
$createdPatientId = is_array($createdPatient) ? (int)($createdPatient['patient']['id'] ?? 0) : 0;
et('patient create capability succeeds', is_array($createdPatient) && !empty($createdPatient['ok']) && $createdPatientId > 0, json_encode($createdPatient));

$searchEmpty = patient_registry_cap_ehr_patient_search_1(['q' => '', 'limit' => 5], 'ehr.patient.search@1', 'patient-registry');
et('patient search capability succeeds with empty q', is_array($searchEmpty) && !empty($searchEmpty['ok']) && isset($searchEmpty['results']), json_encode($searchEmpty));

$searchNeedle = strtolower(substr($crudSeed, 0, 4));
$searchHit = patient_registry_cap_ehr_patient_search_1(['q' => $searchNeedle, 'limit' => 5], 'ehr.patient.search@1', 'patient-registry');
et('patient search capability succeeds with non-empty q (regression: PDO native prepares disallow reused :q placeholder)', is_array($searchHit) && !empty($searchHit['ok']) && isset($searchHit['results']), json_encode($searchHit));

$portalEmail = 'portal_' . $crudSeed . '@example.test';
$portalPassword = 'P0rtal-' . $crudSeed . '!';
$portalProvision = patient_portal_cap_ehr_portal_account_provision_1([
    'patient_id' => $createdPatientId,
    'email' => $portalEmail,
    'password' => $portalPassword,
    'provisioned_by_user_id' => 1,
], 'ehr.portal.account.provision@1', 'patient-portal');
et('patient portal account provision succeeds', is_array($portalProvision) && !empty($portalProvision['ok']) && !empty($portalProvision['account']['account_uuid']), json_encode($portalProvision));

$portalDuplicate = patient_portal_cap_ehr_portal_account_provision_1([
    'patient_id' => $createdPatientId,
    'email' => 'second_' . $crudSeed . '@example.test',
    'password' => $portalPassword,
], 'ehr.portal.account.provision@1', 'patient-portal');
et('patient portal account provision rejects second account for same patient', is_array($portalDuplicate) && empty($portalDuplicate['ok']) && stripos((string)($portalDuplicate['error'] ?? ''), 'already') !== false, json_encode($portalDuplicate));

$portalView = patient_portal_cap_ehr_portal_account_view_1(['patient_id' => $createdPatientId], 'ehr.portal.account.view@1', 'patient-portal');
et('patient portal account view returns account without password hash', is_array($portalView) && !empty($portalView['ok']) && isset($portalView['account']) && !isset($portalView['account']['password_hash']), json_encode($portalView));

$portalDeactivate = patient_portal_cap_ehr_portal_account_deactivate_1([
    'patient_id' => $createdPatientId,
    'reason' => 'regression test cleanup',
    'actor_user_id' => 1,
], 'ehr.portal.account.deactivate@1', 'patient-portal');
et('patient portal account deactivate succeeds', is_array($portalDeactivate) && !empty($portalDeactivate['ok']) && (string)($portalDeactivate['account']['status'] ?? '') === 'inactive', json_encode($portalDeactivate));

$createdAppointment = scheduling_cap_ehr_appointment_schedule_1([
    'patient_id' => $createdPatientId,
    'appointment_type' => 'Coverage Visit',
    'scheduled_start' => '2026-05-14 09:00:00',
    'scheduled_end' => '2026-05-14 09:30:00',
    'status' => 'scheduled',
    'reason_for_visit' => 'Coverage appointment create check',
    'created_by_user_id' => 1,
], 'ehr.appointment.schedule@1', 'scheduling');
$createdAppointmentId = is_array($createdAppointment) ? (int)($createdAppointment['appointment']['id'] ?? 0) : 0;
et('appointment create capability succeeds', is_array($createdAppointment) && !empty($createdAppointment['ok']) && $createdAppointmentId > 0, json_encode($createdAppointment));

$createdEncounter = encounters_cap_ehr_encounter_create_1([
    'patient_id' => $createdPatientId,
    'encounter_type' => 'outpatient',
    'service_line' => 'ambulatory',
    'status' => 'open',
    'reason_for_visit' => 'Coverage order create check',
], 'ehr.encounter.create@1', 'encounters');
$createdEncounterId = is_array($createdEncounter) ? (int)($createdEncounter['encounter']['id'] ?? 0) : 0;
et('encounter create capability succeeds', is_array($createdEncounter) && !empty($createdEncounter['ok']) && $createdEncounterId > 0, json_encode($createdEncounter));

$createdOrder = orders_cap_ehr_order_create_1([
    'patient_id' => $createdPatientId,
    'encounter_id' => $createdEncounterId,
    'order_type' => 'lab',
    'priority' => 'routine',
    'status' => 'requested',
    'destination_module' => 'results',
    'clinical_question' => 'Coverage order create check',
    'items' => [[
        'item_label' => 'Coverage create order item',
        'status' => 'requested',
    ]],
], 'ehr.order.create@1', 'orders');
$createdOrderId = is_array($createdOrder) ? (int)($createdOrder['order']['id'] ?? 0) : 0;
et('order create capability succeeds', is_array($createdOrder) && !empty($createdOrder['ok']) && $createdOrderId > 0, json_encode($createdOrder));

$healthcareDir = __DIR__ . '/../modules/healthcare';
$manifestDepends = [];
$manifestExposes = [];
foreach (glob($healthcareDir . '/*/module.json') ?: [] as $manifestPath) {
    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($decoded)) {
        continue;
    }
    $moduleId = (string)($decoded['id'] ?? basename(dirname($manifestPath)));
    $depends = is_array($decoded['capabilities']['depends'] ?? null) ? $decoded['capabilities']['depends'] : [];
    $exposes = [];
    foreach (($decoded['capabilities']['exposes'] ?? []) as $exposed) {
        if (is_array($exposed) && isset($exposed['id'])) {
            $exposes[] = (string)$exposed['id'];
        }
    }
    $manifestDepends[$moduleId] = array_values(array_unique(array_map('strval', $depends)));
    $manifestExposes[$moduleId] = $exposes;
}

$callPattern = "/cap\\(\\)->call\\(\\s*'([^']+)'[^)]*'caller_module'\\s*=>\\s*'([^']+)'/s";
$wiringDrift = [];
foreach (glob($healthcareDir . '/*/{handlers,helpers}.php', GLOB_BRACE) ?: [] as $sourcePath) {
    $contents = (string)file_get_contents($sourcePath);
    if ($contents === '' || !preg_match_all($callPattern, $contents, $matches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($matches as $match) {
        $capId = $match[1];
        $caller = $match[2];
        if (!isset($manifestDepends[$caller])) {
            continue;
        }
        if (in_array($capId, $manifestExposes[$caller] ?? [], true)) {
            continue;
        }
        if (in_array($capId, $manifestDepends[$caller], true)) {
            continue;
        }
        $wiringDrift[] = $caller . ' -> ' . $capId . ' (' . basename(dirname($sourcePath)) . '/' . basename($sourcePath) . ')';
    }
}
et('healthcare capability calls match manifest depends', $wiringDrift === [], implode('; ', $wiringDrift));

foreach ($manifestDepends as $moduleId => $deps) {
    $exposes = $manifestExposes[$moduleId] ?? [];
    foreach ($deps as $dep) {
        if (in_array($dep, $exposes, true)) {
            $wiringDrift[] = $moduleId . ' depends on its own exposed capability ' . $dep;
        }
    }
}

$tenantEnabledModuleIds = array_keys(getEnabledModules());
et('ehr tenant enabled modules include entry shell', in_array('ehr', $tenantEnabledModuleIds, true), json_encode($tenantEnabledModuleIds));

$ehrAdminNavItems = ehrAdminNavItems($ehrAdminUser);
$ehrAdminNavByKey = [];
foreach ($ehrAdminNavItems as $item) {
    $ehrAdminNavByKey[(string)($item['key'] ?? '')] = $item;
}
$ehrAdminNavKeys = array_values(array_map(static fn(array $item): string => (string)($item['key'] ?? ''), $ehrAdminNavItems));

$tenantEntryRouter = new \Ikabud\Kernel\Http\TenantEntryRouter();
et('ehr tenant root resolves to module entry login route', $tenantEntryRouter->rewriteUri('/') === '/ehr/login', $tenantEntryRouter->rewriteUri('/'));

et('ehr admin nav derives from module manifests', count($ehrAdminNavItems) >= 15 && isset($ehrAdminNavByKey['ehr_dashboard'], $ehrAdminNavByKey['ehr_scheduling'], $ehrAdminNavByKey['ehr_patient_registry'], $ehrAdminNavByKey['ehr_encounters'], $ehrAdminNavByKey['ehr_clinical_notes'], $ehrAdminNavByKey['ehr_orders'], $ehrAdminNavByKey['ehr_results'], $ehrAdminNavByKey['ehr_prescriptions'], $ehrAdminNavByKey['ehr_documents'], $ehrAdminNavByKey['ehr_privacy_consent'], $ehrAdminNavByKey['ehr_patient_portal'], $ehrAdminNavByKey['ehr_audit'], $ehrAdminNavByKey['ehr_reporting_summary'], $ehrAdminNavByKey['ehr_reporting_compliance'], $ehrAdminNavByKey['ehr_billing_bridge'], $ehrAdminNavByKey['ehr_settings']));
et('ehr admin nav keeps manifest keys', ($ehrAdminNavByKey['ehr_settings']['module'] ?? '') === 'ehr' && ($ehrAdminNavByKey['ehr_scheduling']['module'] ?? '') === 'scheduling' && ($ehrAdminNavByKey['ehr_patient_registry']['module'] ?? '') === 'patient-registry' && ($ehrAdminNavByKey['ehr_encounters']['module'] ?? '') === 'encounters' && ($ehrAdminNavByKey['ehr_clinical_notes']['module'] ?? '') === 'clinical-notes' && ($ehrAdminNavByKey['ehr_orders']['module'] ?? '') === 'orders' && ($ehrAdminNavByKey['ehr_results']['module'] ?? '') === 'results' && ($ehrAdminNavByKey['ehr_prescriptions']['module'] ?? '') === 'prescriptions' && ($ehrAdminNavByKey['ehr_documents']['module'] ?? '') === 'documents' && ($ehrAdminNavByKey['ehr_privacy_consent']['module'] ?? '') === 'privacy-consent' && ($ehrAdminNavByKey['ehr_patient_portal']['module'] ?? '') === 'patient-portal' && ($ehrAdminNavByKey['ehr_audit']['module'] ?? '') === 'audit' && ($ehrAdminNavByKey['ehr_reporting_summary']['module'] ?? '') === 'reporting' && ($ehrAdminNavByKey['ehr_reporting_compliance']['module'] ?? '') === 'reporting' && ($ehrAdminNavByKey['ehr_billing_bridge']['module'] ?? '') === 'billing-bridge');
et('ehr admin nav orders dashboard first and settings last', $ehrAdminNavKeys === ['ehr_dashboard', 'ehr_scheduling', 'ehr_patient_registry', 'ehr_encounters', 'ehr_clinical_notes', 'ehr_orders', 'ehr_results', 'ehr_prescriptions', 'ehr_documents', 'ehr_privacy_consent', 'ehr_patient_portal', 'ehr_audit', 'ehr_hospital_adt', 'ehr_interop_bridge', 'ehr_reporting_summary', 'ehr_analytics_cds', 'ehr_reporting_compliance', 'ehr_billing_bridge', 'ehr_users', 'ehr_settings'], json_encode($ehrAdminNavKeys));

$navGroups = ehrDashboardNavGroups($ehrAdminNavItems);
$sidebarGroups = ehrSidebarNavGroups($ehrAdminNavItems);
$dashboardSummary = ehrDashboardSummary();
$dashboardHtml = ehrRender('admin/dashboard.disyl', array_merge(
    ehrAdminContext($ehrAdminUser, 'ehr_dashboard', ['page_title' => 'EHR Dashboard']),
    [
        'workspace_nav_items' => $navGroups['workspace'],
        'workspace_nav_groups' => $navGroups['workspace_groups'],
        'admin_nav_items' => $navGroups['administration'],
        'workspace_item_count' => count($navGroups['workspace']),
        'today_label' => date('l, F j, Y'),
        'today_iso' => date('Y-m-d'),
        'summary' => $dashboardSummary,
        'patient_flow' => ehrDashboardPatientFlow($dashboardSummary),
        'worklists' => ehrDashboardWorklists($dashboardSummary),
        'quick_actions' => ehrDashboardQuickActions(),
    ]
));
et('ehr dashboard renders workspace links', str_contains($dashboardHtml, '/admin/ehr/appointments') && str_contains($dashboardHtml, '/admin/ehr/patients') && str_contains($dashboardHtml, '/admin/ehr/notes'));
et('ehr dashboard surfaces settings entry', str_contains($dashboardHtml, '/admin/ehr/settings'));
et('ehr dashboard renders patient flow + worklists', str_contains($dashboardHtml, 'Patient Flow') && str_contains($dashboardHtml, 'My Worklists') && str_contains($dashboardHtml, 'Workspace Modules'));
et('ehr sidebar nav groups use new clinical structure', array_values(array_map(static fn(array $group): string => (string)($group['title'] ?? ''), $sidebarGroups)) === ['Today', 'Patients', 'Clinical', 'Governance', 'Operations', 'System'], json_encode($sidebarGroups));

// Role-aware nav: verify each role only sees its intended groups/keys.
$roleNavCases = [
    'physician' => [
        'expected_groups' => ['Today', 'Patients', 'Clinical', 'Operations'],
        'must_have_keys' => ['ehr_dashboard', 'ehr_clinical_notes', 'ehr_orders', 'ehr_results', 'ehr_analytics_cds'],
        'must_not_have_keys' => ['ehr_settings', 'ehr_users', 'ehr_audit', 'ehr_billing_bridge'],
    ],
    'nurse' => [
        'expected_groups' => ['Today', 'Patients', 'Clinical'],
        'must_have_keys' => ['ehr_clinical_notes', 'ehr_documents', 'ehr_hospital_adt'],
        'must_not_have_keys' => ['ehr_settings', 'ehr_audit', 'ehr_analytics_cds', 'ehr_billing_bridge'],
    ],
    'front_desk' => [
        'expected_groups' => ['Today', 'Patients'],
        'must_have_keys' => ['ehr_scheduling', 'ehr_patient_registry', 'ehr_patient_portal'],
        'must_not_have_keys' => ['ehr_orders', 'ehr_audit', 'ehr_billing_bridge', 'ehr_settings'],
    ],
    'billing' => [
        'expected_groups' => ['Today', 'Operations'],
        'must_have_keys' => ['ehr_billing_bridge', 'ehr_reporting_summary'],
        'must_not_have_keys' => ['ehr_clinical_notes', 'ehr_audit', 'ehr_settings'],
    ],
    'auditor' => [
        'expected_groups' => ['Today', 'Governance', 'Operations'],
        'must_have_keys' => ['ehr_audit', 'ehr_privacy_consent', 'ehr_reporting_compliance'],
        'must_not_have_keys' => ['ehr_clinical_notes', 'ehr_orders', 'ehr_settings', 'ehr_billing_bridge'],
    ],
    'admin' => [
        'expected_groups' => ['Today', 'Patients', 'Clinical', 'Governance', 'Operations', 'System'],
        'must_have_keys' => ['ehr_settings', 'ehr_audit', 'ehr_billing_bridge', 'ehr_patient_portal'],
        'must_not_have_keys' => [],
    ],
];
foreach ($roleNavCases as $role => $expect) {
    $roleGroups = ehrSidebarNavGroups($ehrAdminNavItems, $role);
    $groupTitles = array_values(array_map(static fn(array $g): string => (string)($g['title'] ?? ''), $roleGroups));
    $keys = [];
    foreach ($roleGroups as $g) {
        foreach (($g['items'] ?? []) as $it) {
            $keys[] = (string)($it['key'] ?? '');
        }
    }
    $titlesOk = $groupTitles === $expect['expected_groups'];
    $hasOk = true;
    foreach ($expect['must_have_keys'] as $k) {
        if (!in_array($k, $keys, true)) {
            $hasOk = false;
            break;
        }
    }
    $absentOk = true;
    foreach ($expect['must_not_have_keys'] as $k) {
        if (in_array($k, $keys, true)) {
            $absentOk = false;
            break;
        }
    }
    et("ehr sidebar role={$role} shows correct groups and keys", $titlesOk && $hasOk && $absentOk, json_encode(['titles' => $groupTitles, 'keys' => $keys]));
}

// Route → nav-key resolution drives URL-level enforcement.
$routeKeyOk = ehrCurrentRouteNavKey('/admin/ehr/notes') === 'ehr_clinical_notes'
    && ehrCurrentRouteNavKey('/admin/ehr/reports/compliance') === 'ehr_reporting_compliance'
    && ehrCurrentRouteNavKey('/admin/ehr/reports/summary') === 'ehr_reporting_summary'
    && ehrCurrentRouteNavKey('/admin/ehr/audit/something') === 'ehr_audit'
    && ehrCurrentRouteNavKey('/admin/ehr/unknown') === null;
et('ehr route→nav-key resolver matches longest prefix', $routeKeyOk);

// Status tabs: green/success codes must be excluded from the main strip and surfaced via done_url.
$apptTabs = ehrStatusTabs(
    'appointment',
    ['scheduled', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'],
    ['scheduled' => 4, 'checked_in' => 2, 'in_progress' => 1, 'completed' => 9, 'cancelled' => 3, 'no_show' => 0],
    '',
    '/admin/ehr/appointments'
);
$apptStatusCodes = array_map(static fn(array $t): string => (string)$t['status'], $apptTabs['tabs']);
et('status tabs exclude completed/success state for appointments', !in_array('completed', $apptStatusCodes, true));
et('status tabs surface success state via done_url for appointments', $apptTabs['done_count'] === 9 && str_contains((string)$apptTabs['done_url'], 'status=completed'));
et('status tabs prepend "All open" with sum of non-success counts', ($apptTabs['tabs'][0]['status'] ?? null) === '' && (int)($apptTabs['tabs'][0]['count'] ?? -1) === (4 + 2 + 1 + 3 + 0));

$encTabs = ehrStatusTabs(
    'encounter',
    ['open', 'in_progress', 'completed', 'cancelled'],
    ['open' => 3, 'in_progress' => 2, 'completed' => 7, 'cancelled' => 1],
    'open',
    '/admin/ehr/encounters'
);
$encCodes = array_map(static fn(array $t): string => (string)$t['status'], $encTabs['tabs']);
et('status tabs exclude completed/success state for encounters', !in_array('completed', $encCodes, true) && $encTabs['done_count'] === 7);

$settingsAdminHtml = ehrRender('admin/settings.disyl', array_merge(
    ehrAdminContext($ehrAdminUser, 'ehr_settings', ['page_title' => 'Branding & Access']),
    [
        'settings' => [
            'app_name' => 'EHR Suite',
            'login_subtitle' => 'Clinical operations, records access, and compliance workflows in one secure workspace.',
            'logo_url' => '',
            'favicon_url' => '',
            'resolved_favicon_url' => ehrResolvedFaviconUrl(),
            'brand_initial' => ehrBrandInitial(),
        ],
        'forgot_password_url' => '/forgot-password',
        'login_url' => '/login',
    ]
));
et('ehr admin layout renders grouped sidebar headings', str_contains($settingsAdminHtml, 'Today') && str_contains($settingsAdminHtml, 'Clinical') && str_contains($settingsAdminHtml, 'Governance'));
et('ehr admin layout forces 6px sidebar radius', str_contains($settingsAdminHtml, '.ehr-shell-sidebar [class*="rounded"]') && str_contains($settingsAdminHtml, 'border-radius: 6px !important;'));
et('ehr settings admin page renders through EHR workspace shell', str_contains($settingsAdminHtml, 'EHR Workspace') && str_contains($settingsAdminHtml, 'Branding and Access'));
et('ehr settings admin page renders module logout action', str_contains($settingsAdminHtml, '/ehr/logout') && str_contains($settingsAdminHtml, 'Sign Out'));
et('ehr settings admin logout renders csrf input markup', str_contains($settingsAdminHtml, '<input type="hidden" name="_token"') && !str_contains($settingsAdminHtml, '&lt;input type=&quot;hidden&quot; name=&quot;_token&quot;'));

$encountersAdminHtml = ehrRender('modules/encounters/admin/index.disyl', array_merge(
    ehrAdminContext($ehrAdminUser, 'ehr_encounters', ['page_title' => 'Encounters']),
    [
        'status_filter' => 'open',
        'result_count' => 1,
        'encounters' => [
            [
                'id' => 77,
                'encounter_uuid' => 'enc_demo_001',
                'patient_id' => 42,
                'encounter_type' => 'outpatient',
                'service_line' => 'ambulatory',
                'start_at' => '2026-05-09 09:30:00',
                'end_at' => null,
                'status' => 'open',
                'reason_for_visit' => 'Follow-up consultation',
                'patient_summary' => [
                    'id' => 42,
                    'patient_uuid' => 'pat_demo_001',
                    'first_name' => 'Charlie',
                    'last_name' => 'Nacario',
                    'birth_date' => '1990-04-12',
                ],
            ],
        ],
        'selected_encounter' => [
            'encounter_uuid' => 'enc_demo_001',
            'service_line' => 'ambulatory',
            'start_at' => '2026-05-09 09:30:00',
            'end_at' => null,
            'status' => 'open',
            'patient_summary' => [
                'first_name' => 'Charlie',
                'last_name' => 'Nacario',
            ],
            'vitals' => [
                [
                    'captured_at' => '2026-05-09 09:45:00',
                    'temperature_c' => '36.8',
                    'pulse_bpm' => 76,
                    'systolic_bp' => 118,
                    'diastolic_bp' => 77,
                    'spo2' => '98.0',
                ],
            ],
        ],
    ]
));
et('encounters page renders through EHR workspace shell', str_contains($encountersAdminHtml, 'Patient visits') && str_contains($encountersAdminHtml, 'encounters in current view'));
et('encounters page exposes sidebar nav item from manifest', str_contains($encountersAdminHtml, '/admin/ehr/encounters') && str_contains($encountersAdminHtml, 'Track active and completed visits'));

$patientRegistryHtml = ehrRender('modules/patient-registry/admin/index.disyl', array_merge(
    ehrAdminContext($ehrAdminUser, 'ehr_patient_registry', ['page_title' => 'Patient Registry']),
    [
        'search_query' => 'nacario',
        'result_count' => 1,
        'patients' => [
            [
                'id' => 42,
                'patient_uuid' => 'pat_demo_001',
                'first_name' => 'Charlie',
                'last_name' => 'Nacario',
                'birth_date' => '1990-04-12',
                'status' => 'active',
                'primary_phone' => '09171234567',
                'email' => 'charlie@example.test',
            ],
        ],
        'selected_patient' => [
            'patient_uuid' => 'pat_demo_001',
            'first_name' => 'Charlie',
            'last_name' => 'Nacario',
            'birth_date' => '1990-04-12',
            'status' => 'active',
            'sex' => 'male',
            'primary_phone' => '09171234567',
            'email' => 'charlie@example.test',
            'identifiers' => [
                [
                    'identifier_type' => 'mrn',
                    'identifier_value' => 'MRN-0001',
                    'issuing_authority' => 'EHR Demo',
                ],
            ],
        ],
    ]
));
et('patient registry page renders through EHR workspace shell', str_contains($patientRegistryHtml, 'Patient Registry') && str_contains($patientRegistryHtml, 'Search Registry'));
et('patient registry page exposes sidebar nav item from manifest', str_contains($patientRegistryHtml, 'Patients') && str_contains($patientRegistryHtml, '/admin/ehr/patients'));

$reportAdminHtml = ehrRender('modules/reporting/admin/summary.disyl', array_merge(
    ehrAdminContext($ehrAdminUser, 'ehr_reporting_summary', ['page_title' => 'EHR Operational Reporting']),
    [
        'filters' => ['facility_id' => 0, 'department_id' => 0, 'date_from' => '', 'date_to' => ''],
        'report_ok' => true,
        'report_error' => '',
        'report_summary' => [
            'appointment_flow' => ['total' => 0, 'by_status' => ['scheduled' => 0, 'completed' => 0]],
            'encounter_volume' => ['total' => 0, 'open_count' => 0, 'completed_count' => 0],
            'results' => ['pending' => 0, 'released' => 0, 'avg_turnaround_hours' => 0],
            'audit' => ['total_events' => 0, 'denied_events' => 0],
        ],
        'api_url' => '/api/v1/ehr/reporting/summary',
        'csv_url' => '/api/v1/ehr/reporting/summary?format=csv',
    ]
));
et('ehr reporting page renders through EHR workspace shell', str_contains($reportAdminHtml, 'EHR Workspace') && str_contains($reportAdminHtml, 'Clinic Activity'));

$workspaceTemplateCases = [
    [
        'label' => 'appointments page renders through EHR workspace shell',
        'template' => 'modules/scheduling/admin/index.disyl',
        'nav_key' => 'ehr_scheduling',
        'page_title' => 'Appointments',
        'context' => [
            'result_count' => 1,
            'status_counts' => [
                ['status' => 'scheduled', 'total' => 4],
            ],
            'appointments' => [
                [
                    'appointment_uuid' => 'appt_demo_001',
                    'appointment_type' => 'Follow-up',
                    'scheduled_start' => '2026-05-09 08:30:00',
                    'scheduled_end' => '2026-05-09 09:00:00',
                    'status' => 'scheduled',
                    'reason_for_visit' => 'Medication review',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
        ],
        'needles' => ['Appointments', '/admin/ehr/appointments', 'Medication review'],
    ],
    [
        'label' => 'clinical notes page renders through EHR workspace shell',
        'template' => 'modules/clinical-notes/admin/index.disyl',
        'nav_key' => 'ehr_clinical_notes',
        'page_title' => 'Clinical Notes',
        'context' => [
            'result_count' => 1,
            'notes' => [
                [
                    'note_type' => 'progress-note',
                    'status' => 'signed',
                    'restricted_flag' => 1,
                    'version_no' => 2,
                    'version_kind' => 'signed',
                    'signed_at' => '2026-05-09 10:00:00',
                    'updated_at' => '2026-05-09 10:00:00',
                    'excerpt' => 'Patient improving after treatment.',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                    'encounter_summary' => ['encounter_uuid' => 'enc_demo_001'],
                ],
            ],
        ],
        'needles' => ['Clinical Notes', '/admin/ehr/notes', 'Patient improving after treatment.'],
    ],
    [
        'label' => 'orders page renders through EHR workspace shell',
        'template' => 'modules/orders/admin/index.disyl',
        'nav_key' => 'ehr_orders',
        'page_title' => 'Orders',
        'context' => [
            'result_count' => 1,
            'orders' => [
                [
                    'order_uuid' => 'ord_demo_001',
                    'first_item_label' => 'CBC',
                    'order_type' => 'lab',
                    'priority' => 'urgent',
                    'item_count' => 1,
                    'status' => 'requested',
                    'ordered_at' => '2026-05-09 10:30:00',
                    'clinical_question' => 'Rule out infection.',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
        ],
        'needles' => ['Orders', '/admin/ehr/orders', 'Rule out infection.'],
    ],
    [
        'label' => 'results page renders through EHR workspace shell',
        'template' => 'modules/results/admin/index.disyl',
        'nav_key' => 'ehr_results',
        'page_title' => 'Results',
        'context' => [
            'result_count' => 1,
            'results' => [
                [
                    'item_label' => 'CBC',
                    'order_uuid' => 'ord_demo_001',
                    'result_status' => 'released',
                    'restricted_flag' => 0,
                    'observed_at' => '2026-05-09 12:00:00',
                    'value_text' => 'Normal',
                    'value_numeric' => '',
                    'unit' => '',
                    'abnormal_flag' => '',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                    'encounter_summary' => ['encounter_uuid' => 'enc_demo_001'],
                ],
            ],
        ],
        'needles' => ['Results', '/admin/ehr/results', 'Normal'],
    ],
    [
        'label' => 'prescriptions page renders through EHR workspace shell',
        'template' => 'modules/prescriptions/admin/index.disyl',
        'nav_key' => 'ehr_prescriptions',
        'page_title' => 'Prescriptions',
        'context' => [
            'result_count' => 1,
            'prescriptions' => [
                [
                    'prescription_uuid' => 'rx_demo_001',
                    'medication_text' => 'Amoxicillin 500mg',
                    'dose_text' => '500 mg',
                    'route' => 'oral',
                    'frequency' => 'TID',
                    'status' => 'issued',
                    'issued_at' => '2026-05-09 13:00:00',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
        ],
        'needles' => ['Prescriptions', '/admin/ehr/prescriptions', 'Amoxicillin 500mg'],
    ],
    [
        'label' => 'documents page renders through EHR workspace shell',
        'template' => 'modules/documents/admin/index.disyl',
        'nav_key' => 'ehr_documents',
        'page_title' => 'Documents',
        'context' => [
            'result_count' => 1,
            'documents' => [
                [
                    'document_uuid' => 'doc_demo_001',
                    'title' => 'Discharge Summary',
                    'document_type' => 'summary',
                    'sensitivity_level' => 'standard',
                    'mime_type' => 'application/pdf',
                    'consent_required_flag' => 1,
                    'break_glass_only_flag' => 0,
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
        ],
        'needles' => ['Documents', '/admin/ehr/documents', 'Discharge Summary'],
    ],
    [
        'label' => 'privacy page renders through EHR workspace shell',
        'template' => 'modules/privacy-consent/admin/index.disyl',
        'nav_key' => 'ehr_privacy_consent',
        'page_title' => 'Privacy & Consent',
        'context' => [
            'consents' => [
                [
                    'consent_type' => 'record-share',
                    'status' => 'granted',
                    'granted_at' => '2026-05-09 08:00:00',
                    'expires_at' => '',
                    'revoked_at' => '',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
            'break_glass_events' => [
                [
                    'object_type' => 'patient',
                    'status' => 'active',
                    'reason_text' => 'Emergency access during triage.',
                    'granted_at' => '2026-05-09 08:15:00',
                    'granted_until' => '2026-05-09 09:15:00',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                ],
            ],
        ],
        'needles' => ['Privacy &amp; Consent', '/admin/ehr/privacy', 'Emergency access during triage.'],
    ],
    [
        'label' => 'audit page renders through EHR workspace shell',
        'template' => 'modules/audit/admin/index.disyl',
        'nav_key' => 'ehr_audit',
        'page_title' => 'Access Activity',
        'context' => [
            'result_count' => 1,
            'entries' => [
                [
                    'action' => 'ehr.note.viewed',
                    'event_label' => 'Note viewed',
                    'is_break_glass' => false,
                    'module' => 'clinical-notes',
                    'entity_type' => 'ehr_note',
                    'entity_id' => '77',
                    'created_at' => '2026-05-09 14:00:00',
                    'actor_source' => 'ehr',
                    'actor_user_id' => 1,
                    'context' => ['patient_id' => 42],
                ],
            ],
        ],
        'needles' => ['Access Activity', '/admin/ehr/audit', 'Note viewed'],
    ],
    [
        'label' => 'billing signals page renders through EHR workspace shell',
        'template' => 'modules/billing-bridge/admin/index.disyl',
        'nav_key' => 'ehr_billing_bridge',
        'page_title' => 'Billing Signals',
        'context' => [
            'result_count' => 1,
            'candidates' => [
                [
                    'label' => 'Consultation',
                    'billing_code' => 'consultation.completed',
                    'candidate_type' => 'consultation',
                    'quantity' => 1,
                    'event_at' => '2026-05-09 15:00:00',
                    'source_action' => 'ehr.appointment.updated',
                    'patient_summary' => ['first_name' => 'Charlie', 'last_name' => 'Nacario'],
                    'encounter_summary' => ['encounter_uuid' => 'enc_demo_001'],
                ],
            ],
        ],
        'needles' => ['Billing Signals', '/admin/ehr/billing', 'consultation.completed'],
    ],
];

foreach ($workspaceTemplateCases as $case) {
    $html = ehrRender($case['template'], array_merge(
        ehrAdminContext($ehrAdminUser, $case['nav_key'], ['page_title' => $case['page_title']]),
        $case['context']
    ));

    $ok = str_contains($html, 'EHR Workspace');
    foreach ($case['needles'] as $needle) {
        $ok = $ok && str_contains($html, $needle);
    }

    et($case['label'], $ok);
}

// ---------------------------------------------------------------------------
// Phase 5/6/7 capability smoke tests
// ---------------------------------------------------------------------------

try {
    $cap = app()->cap();

    $wardRes = $cap->call('ehr.adt.ward.list@1', [], ['caller_module' => 'hospital-adt']);
    et('hospital-adt ward.list cap responds ok', is_array($wardRes) && !empty($wardRes['ok']));

    $msgRes = $cap->call('ehr.interop.message.log@1', [
        'direction' => 'outbound',
        'protocol' => 'fhir',
        'message_type' => 'Patient',
        'payload' => ['resourceType' => 'Patient', 'id' => 'smoke'],
    ], ['caller_module' => 'interoperability-bridge']);
    et('interop message.log cap accepts a fhir message', is_array($msgRes) && !empty($msgRes['ok']) && !empty($msgRes['message_id']));

    $mapRes = $cap->call('ehr.interop.identifier.map@1', [
        'local_entity' => 'patient',
        'local_id' => 1,
        'external_system' => 'lab-vendor-1',
        'external_id' => 'EXT-001',
    ], ['caller_module' => 'interoperability-bridge']);
    et('interop identifier.map cap upserts mapping', is_array($mapRes) && !empty($mapRes['ok']));

    $lookupRes = $cap->call('ehr.interop.identifier.lookup@1', [
        'local_entity' => 'patient',
        'local_id' => 1,
        'external_system' => 'lab-vendor-1',
    ], ['caller_module' => 'interoperability-bridge']);
    et(
        'interop identifier.lookup returns mapped external_id',
        is_array($lookupRes) && !empty($lookupRes['ok']) && is_array($lookupRes['mapping'] ?? null) && ($lookupRes['mapping']['external_id'] ?? '') === 'EXT-001'
    );

    $ruleCode = 'smoke-rule-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $ruleRes = $cap->call('ehr.cds.rule.add@1', [
        'code' => $ruleCode,
        'name' => 'Smoke Rule',
        'domain' => 'labs',
        'severity' => 'warning',
        'expression' => ['field' => 'lab.value', 'op' => '>', 'value' => 100],
    ], ['caller_module' => 'analytics-cds']);
    et('cds rule.add cap returns rule id', is_array($ruleRes) && !empty($ruleRes['ok']) && !empty($ruleRes['rule_id']));

    $evalRes = $cap->call('ehr.cds.evaluate@1', [
        'domain' => 'labs',
        'context' => ['lab' => ['value' => 250]],
    ], ['caller_module' => 'analytics-cds']);
    et('cds evaluate cap fires alert when threshold exceeded', is_array($evalRes) && !empty($evalRes['ok']) && !empty($evalRes['alerts']));

    $summaryRes = $cap->call('ehr.analytics.summary@1', ['since_days' => 30], ['caller_module' => 'analytics-cds']);
    et('analytics summary cap returns active rules count', is_array($summaryRes) && !empty($summaryRes['ok']) && isset($summaryRes['summary']['active_rules']));
} catch (\Throwable $e) {
    et('phase 5/6/7 capability smoke tests threw', false, $e->getMessage());
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);