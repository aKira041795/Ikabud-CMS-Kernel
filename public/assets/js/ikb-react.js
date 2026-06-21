/**
 * ikb-react.js — DiSyL React Bridge Mount Script
 *
 * Discovers [data-react-component] and [data-react-state] elements
 * in the DOM and mounts React components using a component registry.
 *
 * Components are registered via window.__ikbReactComponents[name] = fn(props).
 * The mount function receives the element and parsed props.
 *
 * Usage:
 *   <div data-react-component="CasesTable" data-props='{"items":[...]}'></div>
 *
 * Script discovers the element, looks up "CasesTable" in the registry,
 * and calls it with {element, props}.
 *
 * Re-mounts after HTMX swaps (via htmx:afterSettle event).
 */

(function () {
    'use strict';

    var registry = window.__ikbReactComponents = window.__ikbReactComponents || {};

    function mountAll(root) {
        root = root || document;
        var elements = root.querySelectorAll('[data-react-component], [data-react-state]');
        elements.forEach(function (el) {
            var name = el.getAttribute('data-react-component') || el.getAttribute('data-react-state');
            var rawProps = el.getAttribute('data-props');
            if (!name || !registry[name]) return;

            var props = { element: el };
            try {
                if (rawProps) {
                    var parsed = JSON.parse(rawProps);
                    if (typeof parsed === 'object' && parsed !== null) {
                        for (var k in parsed) {
                            if (parsed.hasOwnProperty(k)) props[k] = parsed[k];
                        }
                    }
                }
            } catch (e) {
                console.warn('[ikb-react] Failed to parse data-props for', name, e);
            }

            try {
                registry[name](props);
            } catch (e) {
                console.error('[ikb-react] Error mounting', name, e);
            }
        });
    }

    // Mount on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { mountAll(document); });
    } else {
        mountAll(document);
    }

    // Re-mount after HTMX swaps
    document.addEventListener('htmx:afterSettle', function (e) {
        mountAll(e.detail.elt || document);
    });

    // Expose for manual triggering
    window.__ikbReactMount = mountAll;
})();
