/**
 * ikb-react.js — DiSyL React Bridge Mount Script
 *
 * Lazy-loads React CDN only when [data-react-component] elements are found
 * in the DOM. No runtime cost on pages without React bridge usage.
 *
 * Components are registered via window.__ikbReactComponents[name] = fn(props).
 *
 * Usage:
 *   <div data-react-component="CasesTable" data-props='{"items":[...]}'></div>
 *
 * Re-mounts after HTMX swaps (via htmx:afterSettle event).
 */

(function () {
    'use strict';

    var registry = window.__ikbReactComponents = window.__ikbReactComponents || {};
    var reactLoading = false;
    var pendingRoots = [];
    var COMPONENT_SCRIPTS = ['/assets/js/ikb-react-cases-table.js'];

    function loadReact(callback) {
        if (window.React && window.ReactDOM) {
            loadComponents(callback);
            return;
        }
        if (reactLoading) {
            pendingRoots.push(callback);
            return;
        }
        reactLoading = true;

        var reactScript = document.createElement('script');
        reactScript.src = 'https://unpkg.com/react@18/umd/react.production.min.js';
        reactScript.crossOrigin = 'anonymous';
        reactScript.onload = function () {
            var domScript = document.createElement('script');
            domScript.src = 'https://unpkg.com/react-dom@18/umd/react-dom.production.min.js';
            domScript.crossOrigin = 'anonymous';
            domScript.onload = function () {
                loadComponents(callback);
            };
            document.head.appendChild(domScript);
        };
        document.head.appendChild(reactScript);
    }

    function loadComponents(callback) {
        if (COMPONENT_SCRIPTS.length === 0) {
            callback();
            return;
        }
        var loaded = 0;
        COMPONENT_SCRIPTS.forEach(function (src) {
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing) {
                loaded++;
                if (loaded === COMPONENT_SCRIPTS.length) {
                    callback();
                    pendingRoots.forEach(function (fn) { fn(); });
                    pendingRoots = [];
                }
                return;
            }
            var s = document.createElement('script');
            s.src = src;
            s.onload = function () {
                loaded++;
                if (loaded === COMPONENT_SCRIPTS.length) {
                    callback();
                    pendingRoots.forEach(function (fn) { fn(); });
                    pendingRoots = [];
                }
            };
            document.head.appendChild(s);
        });
    }

    function mountAll(root) {
        root = root || document;
        var elements = root.querySelectorAll('[data-react-component], [data-react-state]');
        if (elements.length === 0) return;

        loadReact(function () {
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
        });
    }

    // Check on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { mountAll(document); });
    } else {
        mountAll(document);
    }

    // Check after HTMX swaps
    document.addEventListener('htmx:afterSettle', function (e) {
        mountAll(e.detail.elt || document);
    });

    window.__ikbReactMount = mountAll;
})();
