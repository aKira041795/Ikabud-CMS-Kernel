<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/project-audit-ledger/helpers.php';

$ctx = [
    'current_user' => ['full_name' => 'Admin User', 'tenant_id' => 1],
    'page_title'   => 'Dashboard',
    'page_content' => 'dashboard',
    'dashboard'    => ['active_projects' => 0, 'total_projects' => 2],
];

$settings = palSettings();
$ctx['settings'] = $settings;
$ctx['pal_app_name'] = $settings['app_name'] ?? 'Project Audit Ledger';
$ctx['pal_logo_path'] = $settings['logo_path'] ?? '';
$ctx['shell_ctx'] = palBuildShellContext($ctx);
$ctx['page_body'] = '<h1>Dashboard Content</h1>';

echo "shell_ctx exists: " . (isset($ctx['shell_ctx']) ? 'YES' : 'NO') . "\n";

// Use standalone engine
$e = new Ikabud\Kernel\DiSyL\TemplateEngine(__DIR__ . '/../templates', sys_get_temp_dir() . '/disyl_sim', false);
$e->addComponentDirectory('workbench', __DIR__ . '/../storage/application-profiles/ark-workbench/components');
$e->setGlobals(['csrf_field' => '<input type="hidden">']);

$output = $e->render(__DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl', $ctx);

if (preg_match('/<aside[^>]*id="wb-sidebar"[^>]*>(.*?)<\/aside>/s', $output, $m)) {
    $s = $m[1];
    echo "Sidebar: " . strlen($s) . " bytes\n";
    echo "  app_name: " . (str_contains($s, 'Project Audit Ledger') ? 'YES' : 'NO') . "\n";
    echo "  Dashboard: " . (str_contains($s, 'Dashboard') ? 'YES' : 'NO') . "\n";
    echo "  Sign Out: " . (str_contains($s, 'Sign Out') ? 'YES' : 'NO') . "\n";
} else {
    echo "NO SIDEBAR FOUND\n";
    echo "Output sample: " . substr($output, 0, 500) . "\n";
}

