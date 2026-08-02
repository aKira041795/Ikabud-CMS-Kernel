<?php

declare(strict_types=1);

function guidance_sms_capability_handlers(): array
{
    return ['guidance_sms.send@1' => 'guidance_sms_cap_send_1'];
}

function guidance_sms_cap_send_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $input = is_array($payload) ? $payload : [];
    $to = trim((string)($input['to'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    if ($to === '' || $message === '') {
        return ['ok' => false, 'error' => 'Both to and message are required.'];
    }

    $result = app()->cap()->call('sms.send@1', $input, ['caller_module' => 'guidance-sms']);
    return is_array($result) ? $result : ['ok' => true, 'result' => $result];
}
