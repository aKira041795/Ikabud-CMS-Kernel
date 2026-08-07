<?php
/**
 * Cms Akira Search Adapter Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

// ── Scoped Context Helpers ───────────────────────────────────────

function casaCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-search-adapter');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Search Adapter module context unavailable');
    }
    return $ctx;
}

function casaDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return casaCtx()->db();
}

function casaInput(?string $key = null, mixed $default = null): mixed
{
    return casaCtx()->input($key, $default);
}

function casaRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-search-adapter/')
        ? $template
        : 'modules/cms-akira-search-adapter/' . ltrim($template, '/');

    return casaCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_search_adapter_capability_handlers(): array
{
    return [
        'akira.search.document.build@1' => 'casa_cap_akira_search_document_build_1',
    ];
}

function casa_cap_akira_search_document_build_1(mixed $payload, string $capabilityId = 'akira.search.document.build@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    $slug = trim((string)($payload['slug'] ?? ''));
    $body = (string)($payload['body'] ?? '');
    $excerpt = trim((string)($payload['excerpt'] ?? ''));
    $type = (string)($payload['entity_type'] ?? ($payload['type'] ?? 'post'));
    $entityId = (string)($payload['entity_id'] ?? ($payload['id'] ?? ''));

    // Canonical document shape mirrors the search indexer contract
    // (modules/search: searchStrip + searchIndexUpsert).
    $text = $body;
    if (function_exists('searchStrip')) {
        try {
            $text = searchStrip($body);
            if ($excerpt === '') {
                $excerpt = searchStrip($body);
                if ($excerpt !== '') {
                    $excerpt = substr($excerpt, 0, 200);
                }
            } else {
                $excerpt = searchStrip($excerpt);
            }
        } catch (Throwable $e) {
            $text = trim(strip_tags($body));
        }
    } else {
        $text = trim(strip_tags($body));
        if ($excerpt === '') {
            $excerpt = mb_substr($text, 0, 200);
        }
    }

    $doc = [
        'title' => $title,
        'slug' => $slug,
        'text' => $text,
        'excerpt' => $excerpt,
        'search_text' => $text,
        'entity_type' => $type,
        'entity_id' => $entityId,
        'json_metadata' => [
            'slug' => $slug,
            'type' => $type,
        ],
    ];

    // Best-effort index when requested and the search indexer is available.
    $indexed = false;
    if (!empty($payload['index'])) {
        try {
            $res = app()->cap()->call('search.index.upsert@1', [
                'module' => 'cms',
                'entity_type' => $type,
                'entity_id' => $entityId,
                'title' => $title,
                'excerpt' => $excerpt,
                'search_text' => $text,
                'json_metadata' => $doc['json_metadata'],
            ], ['caller_module' => 'cms-akira-search-adapter']);
            $indexed = !empty($res['ok']);
        } catch (Throwable $e) {
            $indexed = false;
        }
    }

    return [
        'ok' => true,
        'data' => [
            'document' => $doc,
            'indexed' => $indexed,
            'provider' => 'cms-akira-search-adapter',
            'resolved_from' => function_exists('searchStrip') ? 'search' : 'fallback',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-search-adapter');
