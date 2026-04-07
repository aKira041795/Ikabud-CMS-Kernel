<?php

declare(strict_types=1);

function ecEmailTemplateDefaults(): array
{
    return [
        'admin_order_notification' => [
            'subject' => 'New Order #{order_number}',
            'message' => "A new order has been placed{source_suffix}.\n\nCustomer: {customer_line}\nOrder total: {order_total}\n\nThe order summary is included below for quick review.",
        ],
        'customer_order_confirmation' => [
            'subject' => 'Order Confirmation #{order_number}',
            'message' => "Hi {customer_greeting},\n\nThank you for your order. We have received it and are processing it now.\n\nYour order summary is included below for your records.",
        ],
    ];
}

function ecEmailTemplateSettingMap(): array
{
    return [
        'admin_order_notification' => [
            'subject' => 'email_tpl_admin_order_subject',
            'message' => 'email_tpl_admin_order_message',
        ],
        'customer_order_confirmation' => [
            'subject' => 'email_tpl_customer_order_subject',
            'message' => 'email_tpl_customer_order_message',
        ],
    ];
}

function ecLegacyEmailTemplateBodySettingMap(): array
{
    return [
        'admin_order_notification' => 'email_tpl_admin_order_body',
        'customer_order_confirmation' => 'email_tpl_customer_order_body',
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

    foreach (ecLegacyEmailTemplateBodySettingMap() as $templateKey => $legacySettingKey) {
        if (trim((string)($templates[$templateKey]['message'] ?? '')) !== '') {
            $currentMessageKey = ecEmailTemplateSettingMap()[$templateKey]['message'] ?? '';
            if ($currentMessageKey !== '' && trim((string)($settings[$currentMessageKey] ?? '')) !== '') {
                continue;
            }
        }

        if (isset($settings[$legacySettingKey]) && trim((string)$settings[$legacySettingKey]) !== '') {
            $templates[$templateKey]['message'] = ecExtractLegacyEmailTemplateMessage($templateKey, (string)$settings[$legacySettingKey]);
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

function ecExtractLegacyEmailTemplateMessage(string $templateKey, string $legacyBody): string
{
    $normalized = trim($legacyBody);
    if ($normalized === '') {
        return (string)(ecEmailTemplateDefaults()[$templateKey]['message'] ?? '');
    }

    $normalized = preg_replace('/<\s*!doctype[^>]*>/i', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/<\/?(?:html|body)[^>]*>/i', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/<table\b.*?<\/table>/is', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/<p>\s*<a\b[^>]*>.*?<\/a>\s*<\/p>/is', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/<h[1-6]\b.*?<\/h[1-6]>/is', '', $normalized) ?? $normalized;
    $normalized = str_replace(['{items_table}', '{account_instructions}'], '', $normalized);
    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $normalized) ?? $normalized;
    $normalized = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $normalized) ?? $normalized;
    $normalized = preg_replace('/<\/?p[^>]*>/i', '', $normalized) ?? $normalized;
    $normalized = trim((string)html_entity_decode(strip_tags($normalized), ENT_QUOTES));
    $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

    return $normalized !== ''
        ? $normalized
        : (string)(ecEmailTemplateDefaults()[$templateKey]['message'] ?? '');
}

function ecCompileEmailTemplate(string $templateKey, array $vars = []): array
{
    $templates = ecEmailTemplates();
    $defaults = ecEmailTemplateDefaults();
    $template = $templates[$templateKey] ?? $defaults[$templateKey] ?? null;

    if (!is_array($template)) {
        throw new InvalidArgumentException('Unknown ecommerce email template: ' . $templateKey);
    }

    $subjectTemplate = (string)($template['subject'] ?? '');
    $messageTemplate = (string)($template['message'] ?? '');
    $subjectReplacements = [];
    $messageReplacements = [];

    foreach ($vars as $name => $value) {
        $token = '{' . trim((string)$name) . '}';
        $plainValue = (string)(preg_replace('/[\r\n]+/', ' ', (string)$value) ?? '');
        $subjectReplacements[$token] = $plainValue;
        $messageReplacements[$token] = $plainValue;
    }

    $subject = trim(strtr($subjectTemplate, $subjectReplacements));
    $message = trim(strtr($messageTemplate, $messageReplacements));

    return [
        'subject' => $subject !== '' ? $subject : (string)($defaults[$templateKey]['subject'] ?? ''),
        'message' => $message,
        'message_html' => ecRenderEmailTemplateMessage($message),
    ];
}

function ecRenderEmailTemplateMessage(string $message): string
{
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", $message));
    if ($normalized === '') {
        return '';
    }

    $paragraphs = preg_split("/\n{2,}/", $normalized) ?: [];
    $chunks = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim((string)$paragraph);
        if ($paragraph === '') {
            continue;
        }

        $chunks[] = '<p style="margin:0 0 16px;">'
            . nl2br(htmlspecialchars($paragraph, ENT_QUOTES), false)
            . '</p>';
    }

    return implode('', $chunks);
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