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
        echo "  + {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  - {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$fields = [
    [
        'id' => 101,
        'field_type' => 'select',
        'label' => 'Department',
        'name' => 'department',
        'options_text' => "sales|Sales\nsupport|Support\npriority|Priority",
        'required' => 1,
    ],
    [
        'id' => 102,
        'field_type' => 'checkbox',
        'label' => 'Urgent',
        'name' => 'urgent',
        'options_text' => '',
        'required' => 0,
    ],
    [
        'id' => 103,
        'field_type' => 'email',
        'label' => 'Email',
        'name' => 'email_address',
        'required' => 1,
    ],
    [
        'id' => 104,
        'field_type' => 'textarea',
        'label' => 'Message',
        'name' => 'message',
        'required' => 1,
    ],
];

echo "\n=== CONDITIONAL CONFIRMATIONS ===\n";

$confirmationForm = [
    'confirmation_rules' => [
        [
            'type' => 'message',
            'message' => 'Sales will contact you shortly.',
            'redirect_url' => '',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
        [
            'type' => 'redirect',
            'message' => '',
            'redirect_url' => '/priority-thank-you',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 102, 'operator' => 'equals', 'value' => '1'],
            ],
        ],
    ],
];

$salesConfirmation = contactFormResolveConditionalConfirmation(
    $confirmationForm,
    $fields,
    ['department' => 'sales', 'email_address' => 'lead@example.com', 'message' => 'Need pricing'],
    'Default success message.'
);
t(
    'message confirmation overrides the default success text',
    ($salesConfirmation['message'] ?? '') === 'Sales will contact you shortly.'
        && (($salesConfirmation['redirect_url'] ?? null) === null),
    json_encode($salesConfirmation)
);

$redirectConfirmation = contactFormResolveConditionalConfirmation(
    $confirmationForm,
    $fields,
    ['department' => 'support', 'urgent' => '1', 'email_address' => 'lead@example.com', 'message' => 'Need help fast'],
    'Default success message.'
);
t(
    'redirect confirmation returns the redirect target when its rule matches',
    ($redirectConfirmation['redirect_url'] ?? '') === '/priority-thank-you'
        && ($redirectConfirmation['message'] ?? '') === 'Default success message.',
    json_encode($redirectConfirmation)
);

$defaultConfirmation = contactFormResolveConditionalConfirmation(
    $confirmationForm,
    $fields,
    ['department' => 'support', 'email_address' => 'lead@example.com', 'message' => 'Standard follow-up'],
    'Default success message.'
);
t(
    'default confirmation is preserved when no workflow rule matches',
    ($defaultConfirmation['message'] ?? '') === 'Default success message.'
        && (($defaultConfirmation['redirect_url'] ?? null) === null),
    json_encode($defaultConfirmation)
);

echo "\n=== NOTIFICATION ROUTING ===\n";

$notificationForm = [
    'notification_rules' => [
        [
            'recipient_email' => 'sales@example.com',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
        [
            'recipient_email' => 'priority@example.com',
            'match' => 'any',
            'conditions' => [
                ['field_id' => 102, 'operator' => 'equals', 'value' => '1'],
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'priority'],
            ],
        ],
        [
            'recipient_email' => 'sales@example.com',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ],
];

$matchedRecipients = contactFormResolveConditionalNotificationRecipients(
    $notificationForm,
    $fields,
    ['department' => 'sales', 'urgent' => '1', 'email_address' => 'lead@example.com', 'message' => 'Need pricing'],
    'default@example.com'
);
sort($matchedRecipients);
t(
    'all matched notification recipients are returned without duplicates',
    $matchedRecipients === ['priority@example.com', 'sales@example.com'],
    json_encode($matchedRecipients)
);

$fallbackRecipients = contactFormResolveConditionalNotificationRecipients(
    $notificationForm,
    $fields,
    ['department' => 'support', 'email_address' => 'lead@example.com', 'message' => 'Standard request'],
    'default@example.com'
);
t(
    'default recipient is used when no notification rule matches',
    $fallbackRecipients === ['default@example.com'],
    json_encode($fallbackRecipients)
);

echo "\n=== STRUCTURED FIELD ERRORS ===\n";

$legacyInvalid = contactFormPrepareLegacySubmission([
    'name' => '',
    'email' => 'not-an-email',
    'message' => 'short',
]);

$legacyFieldErrors = is_array($legacyInvalid['field_errors'] ?? null) ? $legacyInvalid['field_errors'] : [];
t(
    'legacy submission validation returns a structured field_errors payload',
    ($legacyInvalid['error'] ?? '') === 'Please correct the highlighted fields and try again.'
        && ($legacyFieldErrors['name'] ?? '') === 'Name is required.'
        && ($legacyFieldErrors['email'] ?? '') === 'Please enter a valid email address.'
        && ($legacyFieldErrors['message'] ?? '') === 'Message must be at least 10 characters.',
    json_encode($legacyInvalid)
);

$dynamicInvalid = contactFormPrepareDynamicSubmission($fields, [
    'department' => 'invalid-choice',
    'email_address' => 'broken-address',
    'message' => '',
]);

$dynamicFieldErrors = is_array($dynamicInvalid['field_errors'] ?? null) ? $dynamicInvalid['field_errors'] : [];
t(
    'dynamic submission validation maps errors to exact saved-form field names',
    ($dynamicInvalid['error'] ?? '') === 'Please correct the highlighted fields and try again.'
        && ($dynamicFieldErrors['department'] ?? '') === 'Please choose a valid option for Department.'
        && ($dynamicFieldErrors['email_address'] ?? '') === 'Please enter a valid email for Email.'
        && ($dynamicFieldErrors['message'] ?? '') === 'Message is required.',
    json_encode($dynamicInvalid)
);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('no app.log critical errors', !str_contains($appLog, '[critical]'), trim($appLog));
t('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n==========================================\n";
echo "PASS: {$pass}  FAIL: {$fail}\n";
echo "==========================================\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);