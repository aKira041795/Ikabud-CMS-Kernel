<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/contact-form/helpers.php';
require_once __DIR__ . '/../modules/contact-form/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CONDITIONAL LOGIC NORMALIZATION ===\n";

$normalized = contactFormNormalizeConditionalLogic([
    'enabled' => true,
    'action' => 'show',
    'match' => 'any',
    'rules' => [
        ['field_id' => '101', 'operator' => 'equal', 'value' => 'sales'],
        ['field_id' => '102', 'operator' => 'not_empty'],
    ],
]);

t('normalizes operator aliases to canonical names', ($normalized['rules'][0]['operator'] ?? '') === 'equals', json_encode($normalized));
t('preserves any/all match mode', ($normalized['match'] ?? '') === 'any', json_encode($normalized));
t('operators without values drop comparison payload', ($normalized['rules'][1]['value'] ?? 'x') === '', json_encode($normalized));

echo "\n=== VISIBILITY RESOLUTION ===\n";

$showHideFields = [
    [
        'id' => 101,
        'field_type' => 'select',
        'label' => 'Request Type',
        'name' => 'request_type',
        'options_text' => "sales|Sales\nsupport|Support",
        'required' => 1,
    ],
    [
        'id' => 201,
        'field_type' => 'text',
        'label' => 'Order Number',
        'name' => 'order_number',
        'required' => 1,
        'conditional_logic' => [
            'enabled' => true,
            'action' => 'show',
            'match' => 'all',
            'rules' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ],
];

$hiddenVisibility = contactFormResolveFieldVisibility($showHideFields, ['request_type' => 'support']);
$shownVisibility = contactFormResolveFieldVisibility($showHideFields, ['request_type' => 'sales']);

t('show-rule keeps dependent field hidden when condition fails', ($hiddenVisibility[201] ?? true) === false, json_encode($hiddenVisibility));
t('show-rule reveals dependent field when condition matches', ($shownVisibility[201] ?? false) === true, json_encode($shownVisibility));

$hideRuleFields = [
    [
        'id' => 301,
        'field_type' => 'checkbox',
        'label' => 'Internal Request',
        'name' => 'internal_request',
        'required' => 0,
    ],
    [
        'id' => 302,
        'field_type' => 'textarea',
        'label' => 'Public Notes',
        'name' => 'public_notes',
        'required' => 0,
        'conditional_logic' => [
            'enabled' => true,
            'action' => 'hide',
            'match' => 'all',
            'rules' => [
                ['field_id' => 301, 'operator' => 'equals', 'value' => '1'],
            ],
        ],
    ],
];

$hideVisible = contactFormResolveFieldVisibility($hideRuleFields, ['internal_request' => '']);
$hideHidden = contactFormResolveFieldVisibility($hideRuleFields, ['internal_request' => '1']);

t('hide-rule leaves field visible before trigger matches', ($hideVisible[302] ?? false) === true, json_encode($hideVisible));
t('hide-rule conceals field after trigger matches', ($hideHidden[302] ?? true) === false, json_encode($hideHidden));

$numericFields = [
    [
        'id' => 401,
        'field_type' => 'number',
        'label' => 'Guest Count',
        'name' => 'guest_count',
        'required' => 1,
    ],
    [
        'id' => 402,
        'field_type' => 'text',
        'label' => 'Large Party Notes',
        'name' => 'large_party_notes',
        'required' => 0,
        'conditional_logic' => [
            'enabled' => true,
            'action' => 'show',
            'match' => 'all',
            'rules' => [
                ['field_id' => 401, 'operator' => 'greater_than', 'value' => '5'],
            ],
        ],
    ],
];

$numericVisibility = contactFormResolveFieldVisibility($numericFields, ['guest_count' => '6']);
t('greater-than operator works for numeric conditions', ($numericVisibility[402] ?? false) === true, json_encode($numericVisibility));

echo "\n=== SUBMISSION PREPARATION ===\n";

$hiddenResult = contactFormPrepareDynamicSubmission($showHideFields, ['request_type' => 'support']);
$hiddenNames = array_map(static fn(array $record): string => (string) ($record['name'] ?? ''), is_array($hiddenResult['records'] ?? null) ? $hiddenResult['records'] : []);

t('hidden required field is skipped during dynamic submission prep', empty($hiddenResult['error']), json_encode($hiddenResult));
t('hidden dependent field is omitted from stored records', !in_array('order_number', $hiddenNames, true), json_encode($hiddenNames));

$visibleResult = contactFormPrepareDynamicSubmission($showHideFields, ['request_type' => 'sales']);
t('visible dependent field still enforces required validation', (string) ($visibleResult['error'] ?? '') === 'Order Number is required.', json_encode($visibleResult));

$containsFields = [
    [
        'id' => 501,
        'field_type' => 'text',
        'label' => 'Referral Source',
        'name' => 'referral_source',
        'required' => 1,
    ],
    [
        'id' => 502,
        'field_type' => 'text',
        'label' => 'Campaign Code',
        'name' => 'campaign_code',
        'required' => 0,
        'conditional_logic' => [
            'enabled' => true,
            'action' => 'show',
            'match' => 'all',
            'rules' => [
                ['field_id' => 501, 'operator' => 'contains', 'value' => 'facebook'],
            ],
        ],
    ],
];

$containsVisibility = contactFormResolveFieldVisibility($containsFields, ['referral_source' => 'Paid Facebook Ads']);
t('contains operator works for text conditions', ($containsVisibility[502] ?? false) === true, json_encode($containsVisibility));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('no app.log critical errors', !str_contains($appLog, '[critical]'), trim($appLog));
t('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);