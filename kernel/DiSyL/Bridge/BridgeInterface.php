<?php
/**
 * DiSyL Component Bridge Interface
 *
 * Defines the contract for frontend framework bridge renderers.
 * Each bridge translates server-side component/state data into
 * framework-specific HTML attributes and markup.
 *
 * Bridges declare supported features via supports() so the compiler
 * can catch incompatible template usage at compile time (e.g. using
 * two-way binding with an HTMX bridge).
 *
 * @package Ikabud\Kernel\DiSyL\Bridge
 * @version 1.1.0
 */

namespace Ikabud\Kernel\DiSyL\Bridge;

interface BridgeInterface
{
    /**
     * Unique identifier for this bridge (e.g. 'alpine', 'htmx', 'custom').
     */
    public function name(): string;

    /**
     * Check whether this bridge supports a given feature.
     *
     * Standard feature identifiers:
     *   - local_state        Client-side reactive state (Alpine x-data)
     *   - two_way_binding    Two-way data binding (Alpine x-model)
     *   - text_binding       One-way text binding (Alpine x-text)
     *   - server_actions     Server-triggered actions (HTMX hx-*)
     *   - client_actions     Client-side event handlers (@click)
     *   - persistence        State persistence across navigation
     *   - island_hydration   Progressive hydration
     *   - lazy_hydration     Hydrate on idle/visible/interaction
     *   - event_dispatch     Custom event dispatch
     *   - optimistic_updates  Optimistic UI updates
     *
     * @param string $feature Feature identifier
     * @return bool
     */
    public function supports(string $feature): bool;

    /**
     * Render a component wrapper with the given data.
     *
     * @param string $componentName Component identifier (e.g. 'employee-profile')
     * @param string $json JSON-encoded data payload
     * @param string $children Rendered child content
     * @param array $attrs Raw template attributes (class, etc.)
     * @return string HTML output
     */
    public function renderComponent(string $componentName, string $json, string $children, array $attrs): string;

    /**
     * Render a state container with initial state.
     *
     * @param string $stateName State namespace identifier
     * @param string $json JSON-encoded initial state
     * @param string $body Rendered body content (with {variable} tags stripped)
     * @param array $attrs Raw template attributes (class, source, etc.)
     * @return string HTML output
     */
    public function renderState(string $stateName, string $json, string $body, array $attrs): string;

    /**
     * Get a list of all supported features for this bridge.
     *
     * @return array<string, bool> Feature map
     */
    public function capabilities(): array;

    /**
     * Render a one-way text binding attribute for a variable.
     *
     * Alpine:  x-text="varName"
     * HTMX:    data-ikb-bind="varName"
     * Custom:  data-ikb-bind="varName"
     *
     * @param string $variable Variable name to bind
     * @return string HTML attribute string (including leading space if non-empty)
     */
    public function renderBind(string $variable): string;

    /**
     * Render a two-way model binding attribute for a variable.
     *
     * Alpine:  x-model="varName"
     * HTMX:    (not supported — emits data-ikb-model as fallback)
     * Custom:  data-ikb-model="varName"
     *
     * @param string $variable Variable name to bind
     * @return string HTML attribute string (including leading space if non-empty)
     */
    public function renderModel(string $variable): string;
}
