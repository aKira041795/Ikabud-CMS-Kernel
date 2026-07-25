<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Adapters;

use Ikabud\Kernel\Contracts\RenderEngine;

/**
 * AppRenderEngine — adapts App::render() and buildRenderBaseContext() to RenderEngine contract.
 *
 * Step 2 of the App decomposition roadmap. Wraps rendering methods
 * behind the narrow RenderEngine interface for service injection.
 *
 * @package Ikabud\Kernel\Adapters
 */
final class AppRenderEngine implements RenderEngine
{
    private \Ikabud\Kernel\App $app;

    public function __construct(?\Ikabud\Kernel\App $app = null)
    {
        $this->app = $app ?? \Ikabud\Kernel\App::getInstance();
    }

    public function render(string $template, array $context = []): string
    {
        return $this->app->render($template, $context);
    }

    public function buildRenderBaseContext(string $template = ''): array
    {
        return $this->app->buildRenderBaseContext($template);
    }
}
