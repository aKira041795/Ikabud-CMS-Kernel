<?php
/**
 * HTMX Bridge
 *
 * Renders components and state as HTMX-powered partials.
 * Instead of client-side reactive state, the bridge emits:
 *
 * - A generic data attribute so JS can still reference the component
 * - hx-vals with the JSON payload for server interactions
 * - Optional hx-trigger/hx-get for lazy-loading patterns
 *
 * Capabilities:
 *   - server_actions
 *   - text_binding, local_state (via server round-trips)
 *   - persistence (server-side)
 *
 * NOT supported:
 *   - two_way_binding (Alpine x-model style)
 *   - client_actions
 *   - island_hydration, lazy_hydration
 *
 * Generated HTML:
 *   {ikb_component} → <div data-ikb-component="name" data-ikb-data='{json}'
 *                          hx-vals='{"ikb":json}'>
 *   {state}         → <div data-state="name" data-ikb-data='{json}'
 *                          hx-vals='{"ikb_state":json}'>
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.1.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

class HtmxBridge implements BridgeInterface
{
    public function name(): string
    {
        return 'htmx';
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'server_actions', 'text_binding' => true,
            'local_state', 'persistence' => true,
            'two_way_binding', 'client_actions',
            'island_hydration', 'lazy_hydration',
            'event_dispatch', 'optimistic_updates' => false,
            default => false,
        };
    }

    public function capabilities(): array
    {
        return [
            'local_state' => true,
            'two_way_binding' => false,
            'text_binding' => true,
            'server_actions' => true,
            'client_actions' => false,
            'persistence' => true,
            'island_hydration' => false,
            'lazy_hydration' => false,
            'event_dispatch' => false,
            'optimistic_updates' => false,
        ];
    }

    public function renderComponent(string $componentName, string $json, string $children, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);
        $extraHtmx = $this->buildExtraHtmx($attrs);

        // hx-vals nests the data under the component name for server routing
        $hxVals = htmlspecialchars(
            json_encode(['ikb_component' => $componentName, 'data' => json_decode($json, true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        return sprintf(
            '<div data-ikb-component="%s" data-ikb-data=\'%s\' hx-vals=\'%s\'%s%s>%s</div>',
            $componentName,
            $json,
            $hxVals,
            $extraHtmx,
            $classAttr,
            $children
        );
    }

    public function renderBind(string $variable): string
    {
        $var = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        return " data-ikb-bind=\"{$var}\"";
    }

    public function renderModel(string $variable): string
    {
        // HTMX does not support two-way binding; emit generic attribute as fallback
        $var = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        return " data-ikb-model=\"{$var}\"";
    }

    public function renderState(string $stateName, string $json, string $body, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);
        $extraHtmx = $this->buildExtraHtmx($attrs);

        $hxVals = htmlspecialchars(
            json_encode(['ikb_state' => $stateName, 'data' => json_decode($json, true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        return sprintf(
            '<div data-state="%s" data-ikb-data=\'%s\' hx-vals=\'%s\'%s%s>%s</div>',
            $stateName,
            $json,
            $hxVals,
            $extraHtmx,
            $classAttr,
            $body
        );
    }

    private function classAttr(array $attrs): string
    {
        $class = $attrs['class'] ?? '';
        return $class !== '' ? " class=\"{$class}\"" : '';
    }

    /**
     * Build additional HTMX attributes passed through from the template.
     * Supports: hx-get, hx-post, hx-trigger, hx-target, hx-swap, hx-select, etc.
     */
    private function buildExtraHtmx(array $attrs): string
    {
        $htmxAttrs = [];
        $htmxKeys = [
            'hx-get', 'hx-post', 'hx-put', 'hx-delete', 'hx-patch',
            'hx-trigger', 'hx-target', 'hx-swap', 'hx-push-url',
            'hx-select', 'hx-indicator', 'hx-confirm',
            'hx-boost', 'hx-ext', 'hx-headers', 'hx-include',
            'hx-params', 'hx-preserve', 'hx-prompt', 'hx-replace-url',
        ];

        foreach ($htmxKeys as $key) {
            $camelKey = str_replace('-', '_', $key);
            $value = $attrs[$key] ?? $attrs[$camelKey] ?? null;
            if ($value !== null) {
                $htmxAttrs[] = "{$key}=\"" . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . "\"";
            }
        }

        return !empty($htmxAttrs) ? ' ' . implode(' ', $htmxAttrs) : '';
    }
}
