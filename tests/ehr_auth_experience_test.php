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
require_once modulePathForId('reporting') . '/helpers.php';

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

et('kernel forgot password page route declared', ($coreRoutes['GET']['/forgot-password'] ?? '') === 'authForgotPasswordPage');
et('kernel reset password page route declared', ($coreRoutes['GET']['/reset-password'] ?? '') === 'authResetPasswordPage');
et('kernel forgot password API route declared', ($coreRoutes['POST']['/api/v1/auth/forgot-password'] ?? '') === 'authForgotPassword');
et('kernel reset password API route declared', ($coreRoutes['POST']['/api/v1/auth/reset-password'] ?? '') === 'authResetPassword');
et('ehr forgot password page route declared', ($ehrRoutes['GET']['/ehr/forgot-password'] ?? '') === 'ehr:ehrForgotPasswordPage');
et('ehr reset password page route declared', ($ehrRoutes['GET']['/ehr/reset-password'] ?? '') === 'ehr:ehrResetPasswordPage');
et('ehr auth login API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/login'] ?? '') === 'ehr:ehrAuthLogin');
et('ehr forgot password API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/forgot-password'] ?? '') === 'ehr:ehrApiForgotPassword');
et('ehr reset password API route declared', ($ehrRoutes['POST']['/api/v1/ehr/auth/reset-password'] ?? '') === 'ehr:ehrApiResetPassword');
et('ehr settings page route declared', ($ehrRoutes['GET']['/admin/ehr/settings'] ?? '') === 'ehr:ehrSettingsPage');
et('encounters page route declared', ($encounterRoutes['GET']['/admin/ehr/encounters'] ?? '') === 'encounters:encPageIndex');
et('patient registry page route declared', ($patientRegistryRoutes['GET']['/admin/ehr/patients'] ?? '') === 'patient-registry:prPageIndex');
et('ehr module db helper returns ModuleDB contract', ehrDb() instanceof \Ikabud\Kernel\Contracts\ModuleDB, get_debug_type(ehrDb()));

$loginContext = ehrLoginPageContext([
    'login_favicon_url' => '/assets/ehr-favicon.ico',
]);

$validEhrToken = app()->jwt()->generate([
    'sub' => 'ehr:1',
    'id' => 1,
    'username' => 'ehradmin',
    'name' => 'EHR Admin',
    'email' => 'charlienacario884@gmail.com',
    'role' => 'admin',
    'source' => 'ehr',
    'tenant_id' => 426,
    'token_version' => 1,
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

$tenantEnabledModuleIds = array_keys(getEnabledModules());
et('ehr tenant enabled modules include entry shell', in_array('ehr', $tenantEnabledModuleIds, true), json_encode($tenantEnabledModuleIds));

$ehrAdminNavItems = ehrAdminNavItems($ehrAdminUser);
$ehrAdminNavByKey = [];
foreach ($ehrAdminNavItems as $item) {
    $ehrAdminNavByKey[(string)($item['key'] ?? '')] = $item;
}

$tenantEntryRouter = new \Ikabud\Kernel\Http\TenantEntryRouter();
et('ehr tenant root resolves to module entry login route', $tenantEntryRouter->rewriteUri('/') === '/ehr/login', $tenantEntryRouter->rewriteUri('/'));

et('ehr admin nav derives from module manifests', count($ehrAdminNavItems) >= 5 && isset($ehrAdminNavByKey['ehr_settings'], $ehrAdminNavByKey['ehr_patient_registry'], $ehrAdminNavByKey['ehr_encounters'], $ehrAdminNavByKey['ehr_reporting_summary'], $ehrAdminNavByKey['ehr_reporting_compliance']));
et('ehr admin nav keeps manifest keys', ($ehrAdminNavByKey['ehr_settings']['module'] ?? '') === 'ehr' && ($ehrAdminNavByKey['ehr_patient_registry']['module'] ?? '') === 'patient-registry' && ($ehrAdminNavByKey['ehr_encounters']['module'] ?? '') === 'encounters' && ($ehrAdminNavByKey['ehr_reporting_summary']['module'] ?? '') === 'reporting' && ($ehrAdminNavByKey['ehr_reporting_compliance']['module'] ?? '') === 'reporting');

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
et('encounters page renders through EHR workspace shell', str_contains($encountersAdminHtml, 'Encounters') && str_contains($encountersAdminHtml, 'Filter Encounters'));
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
et('ehr reporting page renders through EHR workspace shell', str_contains($reportAdminHtml, 'EHR Workspace') && str_contains($reportAdminHtml, 'Operations Report'));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);