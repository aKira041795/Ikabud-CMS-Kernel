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
        'LegacyCmsContentAdapter' => CacLegacyCmsContentAdapterInterface::class,
    ];
}
