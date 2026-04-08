<?php
$baseUrl = 'http://wms.test';
$cookieJar = tempnam(sys_get_temp_dir(), 'cookie');

function req($method, $path, $data = null) {
    global $baseUrl, $cookieJar;
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        }
    }
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $resp];
}

echo "Testing login...\n";
$login = req('POST', '/api/v1/wms/auth/login', ['username' => 'wmsadmin', 'password' => 'wmsadmin123']);
echo "Login Status: " . $login['status'] . "\n";
echo "Login Body: " . substr($login['body'], 0, 200) . "\n\n";

echo "Testing Onboarding Status...\n";
$onboarding = req('GET', '/api/v1/wms/onboarding/status');
echo "Onboarding Status: " . $onboarding['status'] . "\n";
echo "Onboarding Body: " . $onboarding['body'] . "\n\n";

echo "Testing Valuation...\n";
$valuation = req('GET', '/api/v1/wms/financial/valuation');
echo "Valuation Status: " . $valuation['status'] . "\n";
echo "Valuation Body: " . substr($valuation['body'], 0, 300) . "\n\n";

unlink($cookieJar);
