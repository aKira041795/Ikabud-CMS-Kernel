<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/sms-gateway.php';

function smsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('sms');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function smsUser(): ?array
{
    return smsCtx()->user();
}

function smsInput(): array
{
    $input = smsCtx()->input();
    return is_array($input) ? $input : [];
}

function smsRender(string $template, array $context = []): string
{
    return smsCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function smsNormalizeLogPageRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'current_page' => '',
        'page_title' => '',
        'sms_configured' => false,
        'sms_settings' => [],
    ], ['current_page', 'page_title', 'sms_configured', 'sms_settings'], $missingKeys, $typeMismatches);
}

function smsNormalizeComposeRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'sms_configured' => false,
        'test_mode' => false,
    ], ['sms_configured', 'test_mode'], $missingKeys, $typeMismatches);
}

function smsNormalizeTemplatesRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'templates' => [],
    ], ['templates'], $missingKeys, $typeMismatches);
}

function smsNormalizeSettingsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'fields' => [],
    ], ['fields'], $missingKeys, $typeMismatches);
}

function smsNormalizeLogTableRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'logs' => [],
        'total' => 0,
        'page' => 1,
        'limit' => 25,
        'pages' => 1,
    ], ['logs', 'total', 'page', 'limit', 'pages'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('sms.page.log', [
    'template' => 'modules/sms/pages/sms-log.disyl',
    'priority' => 20,
    'normalize' => 'smsNormalizeLogPageRenderContext',
    'log_event' => 'sms.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('sms.partial.compose', [
    'template' => 'modules/sms/partials/compose.disyl',
    'priority' => 20,
    'normalize' => 'smsNormalizeComposeRenderContext',
    'log_event' => 'sms.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('sms.partial.templates', [
    'template' => 'modules/sms/partials/templates.disyl',
    'priority' => 20,
    'normalize' => 'smsNormalizeTemplatesRenderContext',
    'log_event' => 'sms.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('sms.partial.settings', [
    'template' => 'modules/sms/partials/settings.disyl',
    'priority' => 20,
    'normalize' => 'smsNormalizeSettingsRenderContext',
    'log_event' => 'sms.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('sms.partial.log-table', [
    'template' => 'modules/sms/partials/log-table.disyl',
    'priority' => 20,
    'normalize' => 'smsNormalizeLogTableRenderContext',
    'log_event' => 'sms.render_context.contract_mismatch',
]);

function smsIsHtmx(): bool
{
    return smsCtx()->isHtmx();
}

function sms_capability_handlers(): array
{
    return [
        'sms.send@1' => 'sms_cap_sms_send_1',
    ];
}

function sms_cap_sms_send_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    $to = trim((string)($payload['to'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    $recipientName = trim((string)($payload['recipient_name'] ?? ''));

    if ($to === '' || $message === '') {
        return ['ok' => false, 'error' => 'to and message are required'];
    }

    if (strlen($message) > 320) {
        return ['ok' => false, 'error' => 'Message too long (max 320 characters)'];
    }

    $meta = [];
    if (is_string($payload['trigger_event'] ?? null) && trim((string)$payload['trigger_event']) !== '') {
        $meta['trigger_event'] = trim((string)$payload['trigger_event']);
    }
    if (is_scalar($payload['trigger_ref_id'] ?? null) && (string)$payload['trigger_ref_id'] !== '') {
        $meta['trigger_ref_id'] = (string)$payload['trigger_ref_id'];
    }
    if ($recipientName !== '') {
        $meta['recipient_name'] = $recipientName;
    }

    return smsSend($to, $message, $meta);
}
