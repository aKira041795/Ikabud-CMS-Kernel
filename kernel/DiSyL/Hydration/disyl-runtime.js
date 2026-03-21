/**
 * DiSyL v11.0 Islands Hydration Runtime
 * 
 * Client-side JavaScript for progressive hydration:
 * - Intersection Observer for visibility-based hydration
 * - requestIdleCallback for idle hydration
 * - Media query listeners for responsive hydration
 * - Event delegation for interaction hydration
 * 
 * @version 11.0.0
 */

// Module cache for loaded components
const componentCache = new Map();

// Hydration state
const hydratedIslands = new Set();

/**
 * Main hydration entry point
 */
export async function hydrateIslands(manifest) {
    if (!manifest || !manifest.islands) {
        console.warn('[DiSyL] No islands manifest found');
        return;
    }

    const islands = document.querySelectorAll('[data-island]');
    
    for (const element of islands) {
        const id = element.dataset.island;
        const strategy = element.dataset.hydrate || 'load';
        
        if (hydratedIslands.has(id)) continue;
        
        await scheduleHydration(element, manifest, strategy);
    }
}

/**
 * Schedule hydration based on strategy
 */
async function scheduleHydration(element, manifest, strategy) {
    switch (strategy) {
        case 'load':
            await hydrateElement(element, manifest);
            break;
            
        case 'idle':
            scheduleIdleHydration(element, manifest);
            break;
            
        case 'visible':
            scheduleVisibleHydration(element, manifest);
            break;
            
        case 'media':
            scheduleMediaHydration(element, manifest);
            break;
            
        case 'interaction':
            scheduleInteractionHydration(element, manifest);
            break;
            
        case 'never':
            // Do nothing - static only
            break;
            
        default:
            console.warn(`[DiSyL] Unknown hydration strategy: ${strategy}`);
    }
}

/**
 * Hydrate an element immediately
 */
async function hydrateElement(element, manifest) {
    const id = element.dataset.island;
    const componentName = element.dataset.component;
    
    if (hydratedIslands.has(id)) return;
    
    try {
        const Component = await loadComponent(componentName, manifest);
        
        if (!Component) {
            console.error(`[DiSyL] Component not found: ${componentName}`);
            return;
        }
        
        const props = JSON.parse(element.dataset.props || '{}');
        const isSSR = element.dataset.ssr === 'true';
        
        // Mount component
        if (typeof Component.hydrate === 'function' && isSSR) {
            // Hydrate existing SSR content
            Component.hydrate(element, props);
        } else if (typeof Component.mount === 'function') {
            // Mount fresh component
            Component.mount(element, props);
        } else if (typeof Component === 'function') {
            // Simple function component
            const html = Component(props);
            if (!isSSR) {
                element.innerHTML = html;
            }
        }
        
        hydratedIslands.add(id);
        element.setAttribute('data-hydrated', 'true');
        element.dispatchEvent(new CustomEvent('disyl:hydrated', { detail: { id, componentName } }));
        
    } catch (error) {
        console.error(`[DiSyL] Hydration failed for ${id}:`, error);
        element.setAttribute('data-hydration-error', error.message);
    }
}

/**
 * Load a component module
 */
async function loadComponent(name, manifest) {
    if (componentCache.has(name)) {
        return componentCache.get(name);
    }
    
    const modulePath = manifest.modules?.[name];
    
    if (!modulePath) {
        console.error(`[DiSyL] No module path for component: ${name}`);
        return null;
    }
    
    try {
        const module = await import(modulePath);
        const Component = module.default || module[name] || module;
        componentCache.set(name, Component);
        return Component;
    } catch (error) {
        console.error(`[DiSyL] Failed to load component ${name}:`, error);
        return null;
    }
}

/**
 * Schedule hydration when browser is idle
 */
function scheduleIdleHydration(element, manifest) {
    const callback = () => hydrateElement(element, manifest);
    
    if ('requestIdleCallback' in window) {
        requestIdleCallback(callback, { timeout: 2000 });
    } else {
        // Fallback for Safari
        setTimeout(callback, 200);
    }
}

/**
 * Schedule hydration when element becomes visible
 */
function scheduleVisibleHydration(element, manifest) {
    if (!('IntersectionObserver' in window)) {
        // Fallback: hydrate immediately
        hydrateElement(element, manifest);
        return;
    }
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                observer.disconnect();
                hydrateElement(element, manifest);
            }
        });
    }, {
        rootMargin: '50px',
        threshold: 0.01
    });
    
    observer.observe(element);
}

/**
 * Schedule hydration on media query match
 */
function scheduleMediaHydration(element, manifest) {
    const mediaQuery = element.dataset.media;
    
    if (!mediaQuery) {
        hydrateElement(element, manifest);
        return;
    }
    
    const mql = window.matchMedia(mediaQuery);
    
    const handleChange = (e) => {
        if (e.matches) {
            mql.removeEventListener('change', handleChange);
            hydrateElement(element, manifest);
        }
    };
    
    if (mql.matches) {
        hydrateElement(element, manifest);
    } else {
        mql.addEventListener('change', handleChange);
    }
}

/**
 * Schedule hydration on first interaction
 */
function scheduleInteractionHydration(element, manifest) {
    const events = ['click', 'focus', 'touchstart', 'mouseenter'];
    
    const handleInteraction = (e) => {
        events.forEach(event => element.removeEventListener(event, handleInteraction));
        hydrateElement(element, manifest);
    };
    
    events.forEach(event => {
        element.addEventListener(event, handleInteraction, { once: true, passive: true });
    });
}

/**
 * Force hydrate a specific island
 */
export async function hydrateIsland(id, manifest) {
    const element = document.querySelector(`[data-island="${id}"]`);
    if (element) {
        await hydrateElement(element, manifest);
    }
}

/**
 * Check if an island is hydrated
 */
export function isHydrated(id) {
    return hydratedIslands.has(id);
}

/**
 * Get hydration stats
 */
export function getHydrationStats() {
    const total = document.querySelectorAll('[data-island]').length;
    const hydrated = hydratedIslands.size;
    
    return {
        total,
        hydrated,
        pending: total - hydrated,
        percentage: total > 0 ? Math.round((hydrated / total) * 100) : 100
    };
}

/**
 * Auto-initialize on DOM ready
 */
if (typeof document !== 'undefined') {
    const init = () => {
        const manifestEl = document.getElementById('disyl-islands-manifest');
        if (manifestEl) {
            try {
                const manifest = JSON.parse(manifestEl.textContent);
                hydrateIslands(manifest);
            } catch (e) {
                console.error('[DiSyL] Failed to parse islands manifest:', e);
            }
        }
    };
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}

// Export for manual use
export default {
    hydrateIslands,
    hydrateIsland,
    isHydrated,
    getHydrationStats
};
