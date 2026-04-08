<?php
$f = 'modules/wms/handlers/10-pages.php';
$c = file_get_contents($f);

$newPages = <<<'EOT'

function wmsPageOnboarding(array $params = []): void
{
    $user = wmsRequireStaff(['admin']);
    echo wmsRender('admin/onboarding.disyl', wmsAdminContext($user, 'onboarding', [
        'page_title' => 'WMS Onboarding',
    ]));
}

function wmsPageDiagnostics(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor']);
    echo wmsRender('admin/diagnostics.disyl', wmsAdminContext($user, 'diagnostics', [
        'page_title' => 'Diagnostics & Observability',
    ]));
}
EOT;

$c .= "\n" . $newPages . "\n";
file_put_contents($f, $c);

