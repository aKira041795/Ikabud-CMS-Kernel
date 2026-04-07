<?php

declare(strict_types=1);

function ecEmailTemplateDefaults(): array
{
    return [
        'admin_order_notification' => [
            'subject' => 'New Order #{order_number}',
            'body' => <<<'HTML'
<h2 style="color:#ea580c;">New Order Received — #{order_number}</h2>
<p style="margin:0 0 12px;">A new order has been placed{source_suffix}.</p>
<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <tr>
        <td style="padding:4px 8px;font-weight:600;width:40%;">Order Number</td>
        <td style="padding:4px 8px;">#{order_number}</td>
    </tr>
    <tr style="background:#f9fafb;">
        <td style="padding:4px 8px;font-weight:600;">Customer</td>
        <td style="padding:4px 8px;">{customer_line}</td>
    </tr>
    <tr>
        <td style="padding:4px 8px;font-weight:600;">Total</td>
        <td style="padding:4px 8px;">{order_total}</td>
    </tr>
</table>
{items_table}
<p><a href="{admin_order_url}" style="color:#ea580c;">View Order in Admin →</a></p>
HTML,
        ],
        'customer_order_confirmation' => [
            'subject' => 'Order Confirmation #{order_number}',
            'body' => <<<'HTML'
<h2 style="color:#2563eb;">Order Confirmation — #{order_number}</h2>
<p style="margin:0 0 12px;">Hi {customer_greeting},</p>
<p style="margin:0 0 16px;">Thank you for your order! We have received it and are processing it.</p>
<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <tr>
        <td style="padding:4px 8px;font-weight:600;width:40%;">Order Number</td>
        <td style="padding:4px 8px;">#{order_number}</td>
    </tr>
    <tr>
        <td style="padding:4px 8px;font-weight:600;">Total</td>
        <td style="padding:4px 8px;">{order_total}</td>
    </tr>
</table>
{items_table}
{account_instructions}
<p style="color:#6b7280;font-size:12px;margin-top:24px;">This is an automated receipt for your records.</p>
HTML,
        ],
    ];
}

function ecEmailTemplateSettingMap(): array
{
    return [
        'admin_order_notification' => [
            'subject' => 'email_tpl_admin_order_subject',
            'body' => 'email_tpl_admin_order_body',
        ],
        'customer_order_confirmation' => [
            'subject' => 'email_tpl_customer_order_subject',
            'body' => 'email_tpl_customer_order_body',
        ],
    ];
}

function ecEmailTemplates(): array
{
    $templates = ecEmailTemplateDefaults();
    $settings = getModuleSettings('ecommerce');

    foreach (ecEmailTemplateSettingMap() as $templateKey => $fieldMap) {
        foreach ($fieldMap as $field => $settingKey) {
            if (isset($settings[$settingKey]) && trim((string)$settings[$settingKey]) !== '') {
                $templates[$templateKey][$field] = (string)$settings[$settingKey];
            }
        }
    }

    return $templates;
}

function ecPersistEmailTemplates(array $input): void
{
    $defaults = ecEmailTemplateDefaults();
    $settings = getModuleSettings('ecommerce');

    foreach (ecEmailTemplateSettingMap() as $templateKey => $fieldMap) {
        foreach ($fieldMap as $field => $settingKey) {
            if (!array_key_exists($settingKey, $input)) {
                throw new RuntimeException('Missing email template field: ' . $settingKey);
            }

            $rawValue = (string)$input[$settingKey];
            if ($field === 'subject') {
                $normalized = trim((string)(preg_replace('/[\r\n]+/', ' ', $rawValue) ?? ''));
            } else {
                $normalized = trim(str_replace(["\r\n", "\r"], "\n", $rawValue));
            }

            if ($normalized === '') {
                throw new RuntimeException('Email template ' . str_replace('_', ' ', $templateKey) . ' ' . $field . ' is required.');
            }

            $settings[$settingKey] = $normalized !== ''
                ? $normalized
                : (string)($defaults[$templateKey][$field] ?? '');
        }
    }

    saveModuleSettings('ecommerce', $settings);
}

function ecCompileEmailTemplate(string $templateKey, array $vars = [], array $rawBodyVars = []): array
{
    $templates = ecEmailTemplates();
    $defaults = ecEmailTemplateDefaults();
    $template = $templates[$templateKey] ?? $defaults[$templateKey] ?? null;

    if (!is_array($template)) {
        throw new InvalidArgumentException('Unknown ecommerce email template: ' . $templateKey);
    }

    $subjectTemplate = (string)($template['subject'] ?? '');
    $bodyTemplate = (string)($template['body'] ?? '');
    $subjectReplacements = [];
    $bodyReplacements = [];

    foreach ($vars as $name => $value) {
        $token = '{' . trim((string)$name) . '}';
        $plainValue = (string)(preg_replace('/[\r\n]+/', ' ', (string)$value) ?? '');
        $subjectReplacements[$token] = $plainValue;
        $bodyReplacements[$token] = htmlspecialchars((string)$value, ENT_QUOTES);
    }

    foreach ($rawBodyVars as $name => $value) {
        $token = '{' . trim((string)$name) . '}';
        $subjectReplacements[$token] = '';
        $bodyReplacements[$token] = (string)$value;
    }

    $subject = trim(strtr($subjectTemplate, $subjectReplacements));
    $body = strtr($bodyTemplate, $bodyReplacements);

    return [
        'subject' => $subject !== '' ? $subject : (string)($defaults[$templateKey]['subject'] ?? ''),
        'body' => ecWrapEmailTemplateHtml($body),
    ];
}

function ecWrapEmailTemplateHtml(string $body): string
{
    $trimmedBody = trim($body);
    if ($trimmedBody === '') {
        return '';
    }

    if (preg_match('/<\s*!doctype|<html\b/i', $trimmedBody) === 1) {
        return $trimmedBody;
    }

    return '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#374151;max-width:600px;margin:auto;">'
        . $trimmedBody
        . '</body></html>';
}