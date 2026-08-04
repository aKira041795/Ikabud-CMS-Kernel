<?php

declare(strict_types=1);

function cms_akira_core_capability_handlers(): array
{
    return [
        'akira.content.get@1' => 'cac_cap_akira_content_get_1',
        'akira.content.list@1' => 'cac_cap_akira_content_list_1',
        'akira.content.create@1' => 'cac_cap_akira_content_create_1',
        'akira.content.update@1' => 'cac_cap_akira_content_update_1',
    ];
}

function cac_cap_akira_content_get_1(mixed $payload, string $capabilityId = 'akira.content.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id is required'];
    }

    return cacLegacyCmsContentAdapter()->get($payload);
}

function cac_cap_akira_content_list_1(mixed $payload, string $capabilityId = 'akira.content.list@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    return cacLegacyCmsContentAdapter()->list($payload);
}

function cac_cap_akira_content_create_1(mixed $payload, string $capabilityId = 'akira.content.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    return cacLegacyCmsContentAdapter()->create($payload);
}

function cac_cap_akira_content_update_1(mixed $payload, string $capabilityId = 'akira.content.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id is required'];
    }

    return cacLegacyCmsContentAdapter()->update($payload);
}
