<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$handlers = cms_capability_handlers();
$expected = [
    'cms.content.get@1' => 'cms_cap_cms_content_get_1',
    'cms.content.list@1' => 'cms_cap_cms_content_list_1',
    'cms.content.create@1' => 'cms_cap_cms_content_create_1',
    'cms.content.update@1' => 'cms_cap_cms_content_update_1',
];

foreach ($expected as $capabilityId => $fn) {
    t("handler map includes {$capabilityId}", isset($handlers[$capabilityId]));
    t("handler map target for {$capabilityId} is {$fn}", ($handlers[$capabilityId] ?? '') === $fn);
    t("function {$fn} exists", function_exists($fn));
}

$getInvalid = cms_cap_cms_content_get_1([], 'cms.content.get@1', 'cms');
t('cms.content.get@1 invalid id returns ok=false', ($getInvalid['ok'] ?? true) === false);
t('cms.content.get@1 invalid id error stable', ($getInvalid['error'] ?? '') === 'id is required');

$createInvalidPayload = cms_cap_cms_content_create_1('bad-payload', 'cms.content.create@1', 'cms');
t('cms.content.create@1 non-object payload returns ok=false', ($createInvalidPayload['ok'] ?? true) === false);
t('cms.content.create@1 non-object error stable', ($createInvalidPayload['error'] ?? '') === 'payload must be an object');

$createMissingTitle = cms_cap_cms_content_create_1([], 'cms.content.create@1', 'cms');
t('cms.content.create@1 missing title returns ok=false', ($createMissingTitle['ok'] ?? true) === false);
t('cms.content.create@1 missing title error stable', ($createMissingTitle['error'] ?? '') === 'title is required');

$updateInvalidPayload = cms_cap_cms_content_update_1('bad-payload', 'cms.content.update@1', 'cms');
t('cms.content.update@1 non-object payload returns ok=false', ($updateInvalidPayload['ok'] ?? true) === false);
t('cms.content.update@1 non-object error stable', ($updateInvalidPayload['error'] ?? '') === 'payload must be an object');

$updateMissingId = cms_cap_cms_content_update_1([], 'cms.content.update@1', 'cms');
t('cms.content.update@1 missing id returns ok=false', ($updateMissingId['ok'] ?? true) === false);
t('cms.content.update@1 missing id error stable', ($updateMissingId['error'] ?? '') === 'id is required');

$listBasic = cms_cap_cms_content_list_1([], 'cms.content.list@1', 'cms');
t('cms.content.list@1 returns boolean ok key', array_key_exists('ok', $listBasic));
if (($listBasic['ok'] ?? false) === true) {
    t('cms.content.list@1 success returns array data', is_array($listBasic['data'] ?? null));
} else {
    t('cms.content.list@1 failure exposes error key', isset($listBasic['error']) && is_string($listBasic['error']));
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
