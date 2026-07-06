/**
 * ARK V3 — Optional JavaScript
 * =============================
 * Declared in theme.manifest.json as optional_assets.js.
 * Loaded on demand only when components require Alpine.js/HTMX bridges.
 *
 * Architecture:
 *   - Zero global DOM manipulation on load
 *   - Alpine.js enhancements via event listeners
 *   - HTMX bridge for slot reload on demand
 *   - Customizer postMessage listener (legacy support)
 *
 * Theme Doctrine:
 *   - NO mandatory JS — theme must work without it
 *   - NO inline onclick handlers in templates
 *   - NO direct DOM queries of module tables
 *   - Use 'ark:' custom events for coordinated interactions
 */

(function () {
    'use strict';

    // ── Skip-link focus management ──
    document.addEventListener('DOMContentLoaded', function () {
        var skipLink = document.querySelector('.ark-skip');
        if (skipLink) {
            skipLink.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.setAttribute('tabindex', '-1');
                    target.focus();
                    // Remove tabindex after focus so it doesn't persist in tab order
                    setTimeout(function () { target.removeAttribute('tabindex'); }, 300);
                }
            });
        }
    });

    // ── Mobile menu: close on escape ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var mobilePanel = document.querySelector('.ark-header__mobile-panel[style*="display: block"], .ark-header__mobile-panel[style*="display:block"]');
            // Let Alpine handle visibility — this is a fallback for non-Alpine contexts
        }
    });

    // ── Breadcrumb structured data ──
    function injectBreadcrumbLD() {
        var nav = document.querySelector('.ark-breadcrumbs');
        if (!nav || document.getElementById('ark-ld-breadcrumb')) return;

        var items = nav.querySelectorAll('.ark-breadcrumbs__item');
        if (items.length < 2) return;

        var itemList = [];
        items.forEach(function (el, idx) {
            var link = el.querySelector('a');
            var nameEl = link || el.querySelector('.ark-breadcrumbs__current');
            if (!nameEl) return;
            itemList.push({
                '@type': 'ListItem',
                'position': idx + 1,
                'name': nameEl.textContent.trim(),
                'item': link ? link.getAttribute('href') : undefined
            });
        });

        if (itemList.length > 0) {
            var script = document.createElement('script');
            script.id = 'ark-ld-breadcrumb';
            script.type = 'application/ld+json';
            script.textContent = JSON.stringify({
                '@context': 'https://schema.org',
                '@type': 'BreadcrumbList',
                'itemListElement': itemList
            });
            document.head.appendChild(script);
        }
    }

    // ── Gallery: keyboard navigation ──
    document.addEventListener('click', function (e) {
        var thumb = e.target.closest('.ark-gallery__thumb');
        if (!thumb) return;

        var gallery = thumb.closest('.ark-gallery');
        if (!gallery) return;

        var thumbs = gallery.querySelectorAll('.ark-gallery__thumb');
        thumbs.forEach(function (t, i) {
            t.classList.toggle('ark-gallery__thumb--active', t === thumb);
            t.setAttribute('aria-selected', t === thumb ? 'true' : 'false');
        });

        var mainImg = gallery.querySelector('.ark-gallery__main-image');
        if (mainImg) {
            var thumbImg = thumb.querySelector('img');
            if (thumbImg) {
                mainImg.src = thumbImg.src;
            }
        }
    });

    // ── Initialize on load ──
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectBreadcrumbLD);
    } else {
        injectBreadcrumbLD();
    }

    // ── HTMX bridge: re-init after HTMX swaps ──
    document.addEventListener('htmx:afterSwap', function () {
        injectBreadcrumbLD();
    });

    // ── Customizer preview bridge (legacy) ──
    if (window.parent && window.parent !== window) {
        window.addEventListener('message', function (event) {
            if (!event.data || event.data.type !== 'cms:customizer:update') return;
            var vars = event.data.variables || {};
            var root = document.documentElement;
            Object.keys(vars).forEach(function (key) {
                root.style.setProperty(key, vars[key]);
            });
            window.dispatchEvent(new CustomEvent('ark:preview:updated', { detail: vars }));
        });
    }

})();
