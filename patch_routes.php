<?php
$f = 'modules/wms/routes.php';
$c = file_get_contents($f);

// Add GET routes
$c = str_replace(
    "        '/api/v1/wms/users' => 'wms:wmsApiUsersList'\n    ]",
    "        '/api/v1/wms/users' => 'wms:wmsApiUsersList',\n        '/api/v1/wms/configs' => 'wms:wmsApiConfigsList',\n        '/api/v1/wms/onboarding/status' => 'wms:wmsApiOnboardingStatus'\n    ]",
    $c
);

// Add POST routes
$c = str_replace(
    "        '/api/v1/wms/webhooks/register' => 'wms:wmsApiEventWebhookRegistration'\n    ]",
    "        '/api/v1/wms/webhooks/register' => 'wms:wmsApiEventWebhookRegistration',\n        '/api/v1/wms/configs' => 'wms:wmsApiConfigsUpdate',\n        '/api/v1/wms/onboarding/start' => 'wms:wmsApiOnboardingStart',\n        '/api/v1/wms/onboarding/complete' => 'wms:wmsApiOnboardingComplete'\n    ]",
    $c
);

file_put_contents($f, $c);
