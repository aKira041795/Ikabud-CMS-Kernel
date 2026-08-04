<?php

declare(strict_types=1);

interface CacMediaGatewayInterface {}
interface CacEditorProviderInterface {}
interface CacPresentationProviderInterface {}
interface CacThemeProviderInterface {}
interface CacNavigationProviderInterface {}
interface CacSeoProviderInterface {}
interface CacSearchIndexerInterface {}
interface CacWorkflowProviderInterface {}
interface CacIdentityResolverInterface {}
interface CacAiAssistantProviderInterface {}

interface CacProviderRuntimeStatusInterface
{
    public function key(): string;
    public function contract(): string;
    public function dependencyModuleId(): ?string;
    public function available(): bool;
    public function mode(): string;
}

interface CacLegacyCmsContentAdapterInterface
{
    public function get(array $payload): array;
    public function list(array $payload): array;
    public function create(array $payload): array;
    public function update(array $payload): array;
}

final class CacLegacyCmsContentAdapter implements CacLegacyCmsContentAdapterInterface
{
    public function get(array $payload): array
    {
        return app()->cap()->call('cms.content.get@1', $payload);
    }

    public function list(array $payload): array
    {
        return app()->cap()->call('cms.content.list@1', $payload);
    }

    public function create(array $payload): array
    {
        return app()->cap()->call('cms.content.create@1', $payload);
    }

    public function update(array $payload): array
    {
        return app()->cap()->call('cms.content.update@1', $payload);
    }
}

function cacLegacyCmsContentAdapter(): CacLegacyCmsContentAdapterInterface
{
    static $instance = null;
    if ($instance instanceof CacLegacyCmsContentAdapterInterface) {
        return $instance;
    }

    $instance = new CacLegacyCmsContentAdapter();
    return $instance;
}

function cacProviderBoundaryContracts(): array
{
    return [
        'MediaGateway' => CacMediaGatewayInterface::class,
        'EditorProvider' => CacEditorProviderInterface::class,
        'PresentationProvider' => CacPresentationProviderInterface::class,
        'ThemeProvider' => CacThemeProviderInterface::class,
        'NavigationProvider' => CacNavigationProviderInterface::class,
        'SeoProvider' => CacSeoProviderInterface::class,
        'SearchIndexer' => CacSearchIndexerInterface::class,
        'WorkflowProvider' => CacWorkflowProviderInterface::class,
        'IdentityResolver' => CacIdentityResolverInterface::class,
        'AiAssistantProvider' => CacAiAssistantProviderInterface::class,
        'LegacyCmsContentAdapter' => CacLegacyCmsContentAdapterInterface::class,
    ];
}

final class CacCoreProviderRuntimeStatus implements CacProviderRuntimeStatusInterface
{
    public function __construct(
        private string $key,
        private string $contract,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function contract(): string
    {
        return $this->contract;
    }

    public function dependencyModuleId(): ?string
    {
        return null;
    }

    public function available(): bool
    {
        return true;
    }

    public function mode(): string
    {
        return 'core';
    }
}

final class CacOptionalModuleProviderRuntimeStatus implements CacProviderRuntimeStatusInterface
{
    public function __construct(
        private string $key,
        private string $contract,
        private string $dependencyModuleId,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function contract(): string
    {
        return $this->contract;
    }

    public function dependencyModuleId(): ?string
    {
        return $this->dependencyModuleId;
    }

    public function available(): bool
    {
        return cacIsModuleEnabledSafe($this->dependencyModuleId);
    }

    public function mode(): string
    {
        return $this->available() ? 'provider' : 'fallback';
    }
}

function cacIsModuleEnabledSafe(string $moduleId): bool
{
    try {
        if (!function_exists('isModuleEnabled')) {
            return false;
        }
        return isModuleEnabled($moduleId);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Runtime provider map for Phase 2 provider boundary gating.
 *
 * Core providers are always available. Optional providers expose
 * provider/fallback mode based on module enablement.
 *
 * @return CacProviderRuntimeStatusInterface[]
 */
function cacProviderRuntimeMap(): array
{
    return [
        new CacCoreProviderRuntimeStatus('MediaGateway', CacMediaGatewayInterface::class),
        new CacOptionalModuleProviderRuntimeStatus('EditorProvider', CacEditorProviderInterface::class, 'cms-akira-editor'),
        new CacCoreProviderRuntimeStatus('PresentationProvider', CacPresentationProviderInterface::class),
        new CacOptionalModuleProviderRuntimeStatus('ThemeProvider', CacThemeProviderInterface::class, 'cms-akira-theme'),
        new CacOptionalModuleProviderRuntimeStatus('NavigationProvider', CacNavigationProviderInterface::class, 'cms-akira-navigation'),
        new CacOptionalModuleProviderRuntimeStatus('SeoProvider', CacSeoProviderInterface::class, 'cms-akira-seo'),
        new CacOptionalModuleProviderRuntimeStatus('SearchIndexer', CacSearchIndexerInterface::class, 'cms-akira-search-adapter'),
        new CacOptionalModuleProviderRuntimeStatus('WorkflowProvider', CacWorkflowProviderInterface::class, 'cms-akira-workflow'),
        new CacCoreProviderRuntimeStatus('IdentityResolver', CacIdentityResolverInterface::class),
        new CacOptionalModuleProviderRuntimeStatus('AiAssistantProvider', CacAiAssistantProviderInterface::class, 'cms-akira-ai'),
    ];
}

function cacProviderRuntimeStatus(): array
{
    $rows = [];
    foreach (cacProviderRuntimeMap() as $provider) {
        $rows[] = [
            'provider' => $provider->key(),
            'contract' => $provider->contract(),
            'dependency_module' => $provider->dependencyModuleId(),
            'enabled' => $provider->available(),
            'mode' => $provider->mode(),
        ];
    }

    return $rows;
}

function cacSeoMetaForContent(array $payload): array
{
    if (cacIsModuleEnabledSafe('cms-akira-seo')) {
        try {
            $result = app()->cap()->call('akira.seo.meta.build@1', $payload);
            if (($result['ok'] ?? false) === true) {
                return [
                    'mode' => 'provider',
                    'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
                ];
            }
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    $title = trim((string)($payload['title'] ?? ''));
    $excerpt = trim((string)($payload['excerpt'] ?? ''));
    $slug = trim((string)($payload['slug'] ?? ''));

    $metaTitle = $title !== '' ? mb_substr($title, 0, 60) : 'Untitled';
    $metaDescription = $excerpt !== ''
        ? mb_substr($excerpt, 0, 160)
        : mb_substr(strip_tags((string)($payload['body'] ?? '')), 0, 160);

    return [
        'mode' => 'fallback',
        'data' => [
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'canonical_path' => $slug !== '' ? '/content/' . $slug : null,
        ],
    ];
}

function cacAiSummaryForContent(array $payload): array
{
    if (cacIsModuleEnabledSafe('cms-akira-ai')) {
        try {
            $result = app()->cap()->call('akira.ai.summary.suggest@1', $payload);
            if (($result['ok'] ?? false) === true) {
                return [
                    'mode' => 'provider',
                    'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
                ];
            }
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    $text = trim((string)($payload['excerpt'] ?? ''));
    if ($text === '') {
        $text = trim(strip_tags((string)($payload['body'] ?? '')));
    }

    $summary = $text !== '' ? mb_substr($text, 0, 200) : 'No summary available.';

    return [
        'mode' => 'fallback',
        'data' => [
            'summary' => $summary,
            'keywords' => [],
        ],
    ];
}

function cacEditorPrepareContent(array $payload): array
{
    $content = (string)($payload['content'] ?? '');

    if (cacIsModuleEnabledSafe('cms-akira-editor')) {
        try {
            $normalized = app()->cap()->call('editor.normalize@1', ['content' => $content]);
            if (($normalized['ok'] ?? false) === true) {
                $content = (string)($normalized['data']['content'] ?? $content);
            }

            $sanitized = app()->cap()->call('editor.sanitize@1', ['content' => $content]);
            if (($sanitized['ok'] ?? false) === true) {
                $content = (string)($sanitized['data']['content'] ?? $content);
            }

            $validated = app()->cap()->call('editor.validate@1', ['content' => $content]);
            if (($validated['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'error' => (string)($validated['error'] ?? 'editor validation failed'),
                ];
            }

            return [
                'ok' => true,
                'mode' => 'provider',
                'data' => [
                    'content' => $content,
                ],
            ];
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    $fallback = preg_replace('~<script\b[^>]*>.*?</script>~isu', '', $content) ?? $content;

    if (trim($fallback) === '') {
        return [
            'ok' => false,
            'error' => 'content is required',
        ];
    }

    return [
        'ok' => true,
        'mode' => 'fallback',
        'data' => [
            'content' => $fallback,
        ],
    ];
}
