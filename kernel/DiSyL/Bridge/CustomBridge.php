<?php
/**
 * Custom JS Bridge
 *
 * Emits generic data-* attributes without any framework-specific markup.
 * Useful for a custom JS framework, or for SSR-only fallback where
 * the data is available to scripts via data attributes.
 *
 * Capabilities:
 *   - text_binding (via data-ikb-bind attributes)
 *   - local_state (generic, consumer-defined)
 *
 * Generated HTML:
 *   {ikb_component} → <div data-ikb-component="name" data-ikb-data='{json}'>
 *   {state}         → <div data-state="name" data-ikb-data='{json}'>
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.1.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

class CustomBridge implements BridgeInterface
{
    public function name(): string
    {
        return 'custom';
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'local_state', 'text_binding' => true,
            'two_way_binding', 'server_actions',
            'client_actions', 'persistence',
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
            'server_actions' => false,
            'client_actions' => false,
            'persistence' => false,
            'island_hydration' => false,
            'lazy_hydration' => false,
            'event_dispatch' => false,
            'optimistic_updates' => false,
        ];
    }

    public function renderComponent(string $componentName, string $json, string $children, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);

        return sprintf(
            '<div data-ikb-component="%s" data-ikb-data=\'%s\'%s>%s</div>',
            $componentName,
            $json,
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
        $var = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        return " data-ikb-model=\"{$var}\"";
    }

    public function renderState(string $stateName, string $json, string $body, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);

        return sprintf(
            '<div data-state="%s" data-ikb-data=\'%s\'%s>%s</div>',
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
