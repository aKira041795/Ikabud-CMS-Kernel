<?php
/**
 * Alpine.js Bridge
 *
 * Renders components and state as Alpine.js x-data attributes
 * using the ikbComponent() reactive function.
 *
 * This is the default bridge — matches existing behavior exactly.
 *
 * Capabilities:
 *   - local_state, two_way_binding, text_binding
 *   - client_actions, event_dispatch
 *   - persistence (via sessionStorage)
 *   - island_hydration, lazy_hydration
 *
 * Generated HTML:
 *   {ikb_component} → <div data-ikb-component="name" x-data="ikbComponent({...})">
 *   {state}         → <div data-state="name" x-data="ikbComponent({...})">
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.1.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

class AlpineBridge implements BridgeInterface
{
    public function name(): string
    {
        return 'alpine';
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'local_state', 'two_way_binding', 'text_binding',
            'client_actions', 'event_dispatch',
            'persistence', 'island_hydration', 'lazy_hydration' => true,
            'server_actions', 'optimistic_updates' => false,
            default => false,
        };
    }

    public function capabilities(): array
    {
        return [
            'local_state' => true,
            'two_way_binding' => true,
            'text_binding' => true,
            'server_actions' => false,
            'client_actions' => true,
            'persistence' => true,
            'island_hydration' => true,
            'lazy_hydration' => true,
            'event_dispatch' => true,
            'optimistic_updates' => false,
        ];
    }

    public function renderComponent(string $componentName, string $json, string $children, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);

        return sprintf(
            '<div data-ikb-component="%s" x-data="ikbComponent(%s)"%s>%s</div>',
            $componentName,
            $json,
            $classAttr,
            $children
        );
    }

    public function renderBind(string $variable): string
    {
        $var = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        return " x-text=\"{$var}\"";
    }

    public function renderModel(string $variable): string
    {
        $var = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        return " x-model=\"{$var}\"";
    }

    public function renderState(string $stateName, string $json, string $body, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);

        return sprintf(
            '<div data-state="%s" x-data="ikbComponent(%s)"%s>%s</div>',
            $stateName,
            $json,
            $classAttr,
            $body
        );
    }

    private function classAttr(array $attrs): string
    {
        $class = $attrs['class'] ?? '';
        return $class !== '' ? " class=\"{$class}\"" : '';
    }
}
