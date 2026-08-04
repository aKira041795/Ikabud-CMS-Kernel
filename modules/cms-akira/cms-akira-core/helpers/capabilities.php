<?php

declare(strict_types=1);

function cms_akira_core_capability_handlers(): array
{
    return [
        'akira.content.get@1' => 'cac_cap_akira_content_get_1',
        'akira.content.list@1' => 'cac_cap_akira_content_list_1',
        'akira.content.create@1' => 'cac_cap_akira_content_create_1',
        'akira.content.update@1' => 'cac_cap_akira_content_update_1',
        'akira.content.enrich@1' => 'cac_cap_akira_content_enrich_1',
        'akira.content.compose@1' => 'cac_cap_akira_content_compose_1',
        'akira.providers.status@1' => 'cac_cap_akira_providers_status_1',
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

    $body = (string)($payload['body'] ?? '');
    $editor = cacEditorPrepareContent(['content' => $body]);
    if (($editor['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string)($editor['error'] ?? 'Invalid content body')];
    }

    $payload['body'] = (string)($editor['data']['content'] ?? $body);

    $result = cacLegacyCmsContentAdapter()->create($payload);
    if (($result['ok'] ?? false) !== true) {
        return $result;
    }

    $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
    $result['data']['provider_mode'] = [
        'editor' => (string)($editor['mode'] ?? 'fallback'),
    ];
    return $result;
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

    $editorMode = 'fallback';
    if (array_key_exists('body', $payload)) {
        $body = (string)($payload['body'] ?? '');
        $editor = cacEditorPrepareContent(['content' => $body]);
        if (($editor['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string)($editor['error'] ?? 'Invalid content body')];
        }
        $payload['body'] = (string)($editor['data']['content'] ?? $body);
        $editorMode = (string)($editor['mode'] ?? 'fallback');
    }

    $result = cacLegacyCmsContentAdapter()->update($payload);
    if (($result['ok'] ?? false) !== true) {
        return $result;
    }

    $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
    $result['data']['provider_mode'] = [
        'editor' => $editorMode,
    ];

    return $result;
}

function cac_cap_akira_providers_status_1(mixed $payload, string $capabilityId = 'akira.providers.status@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    return [
        'ok' => true,
        'data' => cacProviderRuntimeStatus(),
    ];
}

function cac_cap_akira_content_enrich_1(mixed $payload, string $capabilityId = 'akira.content.enrich@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    $seo = cacSeoMetaForContent($payload);
    $ai = cacAiSummaryForContent($payload);

    return [
        'ok' => true,
        'data' => [
            'seo' => $seo['data'] ?? [],
            'ai' => $ai['data'] ?? [],
            'provider_mode' => [
                'seo' => $seo['mode'] ?? 'fallback',
                'ai' => $ai['mode'] ?? 'fallback',
            ],
        ],
    ];
}

function cac_cap_akira_content_compose_1(mixed $payload, string $capabilityId = 'akira.content.compose@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    $seo = cacSeoMetaForContent($payload);
    $ai = cacAiSummaryForContent($payload);
    $theme = cacThemeResolveForContent($payload);
    $navigation = cacNavigationResolveForContent($payload);
    $workflow = cacWorkflowEvaluateForContent($payload);
    $search = cacSearchDocumentBuildForContent($payload);
    $media = cacMediaResolveForContent($payload);

    return [
        'ok' => true,
        'data' => [
            'seo' => $seo['data'] ?? [],
            'ai' => $ai['data'] ?? [],
            'theme' => $theme['data'] ?? [],
            'navigation' => $navigation['data'] ?? [],
            'workflow' => $workflow['data'] ?? [],
            'search' => $search['data'] ?? [],
            'media' => $media['data'] ?? [],
            'provider_mode' => [
                'seo' => $seo['mode'] ?? 'fallback',
                'ai' => $ai['mode'] ?? 'fallback',
                'theme' => $theme['mode'] ?? 'fallback',
                'navigation' => $navigation['mode'] ?? 'fallback',
                'workflow' => $workflow['mode'] ?? 'fallback',
                'search' => $search['mode'] ?? 'fallback',
                'media' => $media['mode'] ?? 'fallback',
            ],
        ],
    ];
}
