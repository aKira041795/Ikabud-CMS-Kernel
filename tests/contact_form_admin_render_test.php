<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/admin/contact-forms/9/edit';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/contact-form/helpers.php';

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
        'options_text' => "sales|Sales\nsupport|Support",
        'required' => 1,
        'sort_order' => 1,
    ],
    [
        'id' => 102,
        'field_type' => 'email',
        'label' => 'Email',
        'name' => 'email_address',
        'options_text' => '',
        'required' => 1,
        'sort_order' => 2,
    ],
];

$form = [
    'id' => 9,
    'name' => 'Lead Intake',
    'slug' => 'lead-intake',
    'success_message' => 'Default success message.',
    'submit_label' => 'Send Message',
    'captcha_enabled' => 0,
    'status' => 'active',
    'confirmation_rules' => [
        [
            'type' => 'message',
            'message' => 'Sales will reply shortly.',
            'redirect_url' => '',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ],
    'notification_rules' => [
        [
            'recipient_email' => 'sales@example.com',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ],
    'confirmation_rules_json' => contactFormRulesToJson([
        [
            'type' => 'message',
            'message' => 'Sales will reply shortly.',
            'redirect_url' => '',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ]),
    'notification_rules_json' => contactFormRulesToJson([
        [
            'recipient_email' => 'sales@example.com',
            'match' => 'all',
            'conditions' => [
                ['field_id' => 101, 'operator' => 'equals', 'value' => 'sales'],
            ],
        ],
    ]),
];

$html = '';
$renderError = '';

try {
    $html = app()->render('modules/contact-form/admin/form-edit.disyl', contactFormAdminPageContext(
        ['id' => 1, 'role' => 'administrator', 'source' => 'cms', 'email' => 'admin@example.com'],
        'contact_forms',
        'Edit Contact Form',
        [
            ['label' => 'Contact Forms', 'url' => '/cms/admin/contact-forms'],
            ['label' => 'Lead Intake', 'url' => ''],
        ],
        [
            'form' => $form,
            'fields' => $fields,
            'condition_fields_json' => contactFormConditionFieldsJson($fields),
            'message' => null,
            'error' => null,
            'is_edit' => true,
            'shortcode' => '[contact-form form="lead-intake"]',
            'field_type_options' => contactFormFieldTypeOptions(),
            'active_tab' => 'overview',
        ]
    ));
} catch (Throwable $e) {
    $renderError = $e->getMessage();
}

echo "\n=== ADMIN EDIT RENDER ===\n";

t('contact-form admin edit template renders successfully', $renderError === '', $renderError);
t('render includes workflow editor markers', str_contains($html, 'Conditional Confirmations') && str_contains($html, 'Conditional Notification Routing'));
t('render includes workflow roots for client hydration', str_contains($html, 'data-workflow-root="confirmation"') && str_contains($html, 'data-workflow-root="notification"'));

$leakedDisylControlTag = '';
if (preg_match('/\{(?:\/)?(?:if|foreach|for|block)\b[^}]*\}/', $html, $matches) === 1) {
    $leakedDisylControlTag = (string) ($matches[0] ?? '');
}

t('admin edit render does not leak raw Disyl control tags', $leakedDisylControlTag === '', $leakedDisylControlTag);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticalLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[critical]')));
$unexpectedErrorLines = array_values(array_filter(
    explode("\n", $errLog),
    static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
));

t('no app.log critical errors', empty($criticalLines), implode('; ', $criticalLines));
t('no PHP errors in error.log', empty($unexpectedErrorLines), implode('; ', $unexpectedErrorLines));

echo "\n==========================================\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo "==========================================\n";

if ($fail > 0) {
    exit(1);
}