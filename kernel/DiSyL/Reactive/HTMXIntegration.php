<?php
/**
 * DiSyL v11.0 HTMX Integration
 * 
 * Server-side reactivity using HTMX patterns:
 * - Partial template updates
 * - Out-of-band swaps
 * - Server-sent events
 * - WebSocket support helpers
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * HTMX-aware template integration
 */
class HTMXTemplateIntegration
{
    private \Ikabud\Kernel\DiSyL\TemplateEngine $engine;
    
    public function __construct(\Ikabud\Kernel\DiSyL\TemplateEngine $engine)
    {
        $this->engine = $engine;
    }
    
    /**
     * Render template with HTMX response handling
     * 
     * If HTMX request, returns just the fragment.
     * Otherwise, returns full page.
     */
    public function render(
        string $template,
        array $context = [],
        ?string $fragmentTemplate = null
    ): HTMXResponse {
        $response = new HTMXResponse();
        
        if (HTMXRequest::isHTMX() && $fragmentTemplate !== null) {
            // Render just the fragment for HTMX requests
            $content = $this->engine->render($fragmentTemplate, $context);
        } else {
            // Render full template
            $content = $this->engine->render($template, $context);
        }
        
        $response->setContent($content);
        
        return $response;
    }
    
    /**
     * Render multiple fragments for OOB updates
     */
    public function renderFragments(array $fragments, array $context = []): HTMXResponse
    {
        $response = new HTMXResponse();
        $isFirst = true;
        
        foreach ($fragments as $targetId => $template) {
            $content = $this->engine->render($template, $context);
            
            if ($isFirst) {
                $response->setContent($content);
                $isFirst = false;
            } else {
                $response->addOOBSwap($targetId, $content);
            }
        }
        
        return $response;
    }
    
    /**
     * Register HTMX-specific filters
     */
    public function registerFilters(\Ikabud\Kernel\DiSyL\v4\FilterRegistry $filters): void
    {
        // {{ url | hx_get }} - Generate hx-get attribute
        $filters->register('hx_get', fn($url) => 'hx-get="' . htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ url | hx_post }} - Generate hx-post attribute
        $filters->register('hx_post', fn($url) => 'hx-post="' . htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ selector | hx_target }} - Generate hx-target attribute
        $filters->register('hx_target', fn($selector) => 'hx-target="' . htmlspecialchars((string) $selector, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ strategy | hx_swap }} - Generate hx-swap attribute
        $filters->register('hx_swap', fn($strategy) => 'hx-swap="' . htmlspecialchars((string) $strategy, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ event | hx_trigger }} - Generate hx-trigger attribute
        $filters->register('hx_trigger', fn($event) => 'hx-trigger="' . htmlspecialchars((string) $event, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ selector | hx_indicator }} - Generate hx-indicator attribute
        $filters->register('hx_indicator', fn($selector) => 'hx-indicator="' . htmlspecialchars((string) $selector, ENT_QUOTES, 'UTF-8') . '"');
        
        // {{ json | hx_vals }} - Generate hx-vals attribute
        $filters->register('hx_vals', function($data) {
            $json = is_string($data) ? $data : json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            return 'hx-vals="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
        });
    }
}
