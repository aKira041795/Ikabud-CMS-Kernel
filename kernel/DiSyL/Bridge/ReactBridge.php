<?php

/**
 * React Bridge
 *
 * Renders components as React-compatible HTML with JSON-serialized props.
 * The bridge outputs a mounting point with data-react-component and data-props
 * attributes. A client-side script (ikb-react.js) discovers these elements
 * and mounts React components at runtime.
 *
 * This bridge does NOT require JSX compilation — the server serializes
 * component name + props, and React renders on the client side.
 *
 * Capabilities:
 *   - text_binding       (via data-props passthrough)
 *   - local_state        (React useState/useReducer internally)
 *   - client_actions     (React event handlers internally)
 *   - server_actions     (via fetch calls from React components)
 *
 * Generated HTML:
 *   {ikb_component} → <div data-react-component="Name" data-props='{...}'>
 *   {state}         → <div data-react-state="name" data-props='{...}'>
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

class ReactBridge implements BridgeInterface
{
    public function name(): string
    {
        return 'react';
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'text_binding', 'local_state', 'client_actions',
            'server_actions', 'event_dispatch' => true,
            'two_way_binding', 'persistence',
            'island_hydration', 'lazy_hydration', 'optimistic_updates' => false,
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
            'client_actions' => true,
            'persistence' => false,
            'island_hydration' => false,
            'lazy_hydration' => false,
            'event_dispatch' => true,
            'optimistic_updates' => false,
        ];
    }

    public function renderComponent(string $componentName, string $json, string $children, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);
        $encoded = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<div data-react-component="%s" data-props=\'%s\'%s></div>',
            $componentName,
            $encoded,
            $classAttr
        );
    }

    public function renderBind(string $variable): string
    {
        // React bridge doesn't support attribute-level bindings
        // Values are passed through data-props instead
        return '';
    }

    public function renderModel(string $variable): string
    {
        // React bridge doesn't support attribute-level two-way binding
        return '';
    }

    public function renderState(string $stateName, string $json, string $body, array $attrs): string
    {
        $classAttr = $this->classAttr($attrs);
        $encoded = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<div data-react-state="%s" data-props=\'%s\'%s>%s</div>',
            $stateName,
            $encoded,
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
