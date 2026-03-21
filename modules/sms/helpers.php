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
    return smsCtx()->render($template, $context);
}

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
