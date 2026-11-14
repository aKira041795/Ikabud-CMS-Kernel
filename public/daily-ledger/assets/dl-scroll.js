/*
 * dl-scroll.js — make a wide table usable without hunting for its scrollbar.
 *
 * WHY THIS EXISTS (client report, 2026-11-14):
 *   "sa Variance area pwede ang scroll bar makita ra permi kanang dili nami muadto ug ubos para
 *    imove to the left/right" — the scrollbar was at the bottom of a tall table, so reaching it meant
 *   leaving the row being read. The Daily Sheet already solved this and the owner asked for that
 *   behaviour to be DE FACTO for every view that scrolls sideways, not copy-pasted per page.
 *
 * WHAT IT DOES, per horizontally-overflowing scroll container:
 *   1. drag-to-pan on both axes (MOUSE ONLY, so tablets keep native touch panning), and Shift+wheel
 *      for sideways scrolling;
 *   2. a horizontal scrollbar pinned to the bottom of the WINDOW, kept in step with the container in
 *      both directions, and hidden when there is nothing to scroll or the container is off screen.
 *
 * HOW IT FINDS THEM: every <table> whose nearest horizontally scrollable ancestor actually overflows,
 * plus anything explicitly marked [data-dl-hscroll]. That is what makes it de facto - a new view
 * inherits the behaviour by being a page, with no per-template wiring to remember.
 *
 * It does NOT take over a container another script already handles: the Daily Sheet sets
 * `dataset.panReady`, and this module sets `dataset.dlPanReady`. Either one means "already bound", so
 * the sheet's initialiser and this module can never double-bind the same container.
 */
(function () {
    'use strict';

    if (window.__dlScrollLoaded) return;
    window.__dlScrollLoaded = true;

    var BAR_ID = 'dl-hscroll-bar';
    var INNER_ID = 'dl-hscroll-inner';
    var INTERACTIVE = 'button, input, select, textarea, a, label, [contenteditable="true"]';
    var targets = [];
    var activeTarget = null;
    var bar = null;
    var inner = null;
    var refreshQueued = false;

    function scrollableAncestor(node) {
        var el = node.parentElement;
        while (el && el !== document.body && el !== document.documentElement) {
            var style = window.getComputedStyle(el);
            var overflowX = style.overflowX;
            if ((overflowX === 'auto' || overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    /* A table can overflow its wrapper only after fonts, Alpine x-for rows, or a resize settle, so the
       check is repeated rather than trusted once at load. */
    function discover() {
        var found = [];
        var explicit = document.querySelectorAll('[data-dl-hscroll]');
        for (var i = 0; i < explicit.length; i++) {
            // A container the Daily Sheet already drives (panReady) has its own pinned bar. Including
            // it here would put a SECOND scrollbar under the same table.
            if (explicit[i].dataset.panReady === '1') continue;
            found.push(explicit[i]);
        }

        var tables = document.querySelectorAll('table');
        for (var t = 0; t < tables.length; t++) {
            if (tables[t].scrollWidth <= tables[t].clientWidth + 1 &&
                !(tables[t].parentElement && tables[t].parentElement.scrollWidth > tables[t].parentElement.clientWidth + 1)) {
                continue;
            }
            var host = scrollableAncestor(tables[t]);
            if (host && host.dataset.panReady !== '1' && found.indexOf(host) === -1) found.push(host);
        }
        return found;
    }

    function overflows(el) {
        return !!el && el.scrollWidth > el.clientWidth + 1;
    }

    function visibleArea(el) {
        var r = el.getBoundingClientRect();
        var w = window.innerWidth || document.documentElement.clientWidth;
        var h = window.innerHeight || document.documentElement.clientHeight;
        var x = Math.max(0, Math.min(r.right, w) - Math.max(r.left, 0));
        var y = Math.max(0, Math.min(r.bottom, h) - Math.max(r.top, 0));
        return x * y;
    }

    /* Drag-to-pan. Bound to the CONTAINER, never to window: an Alpine re-render replaces children and
       window listeners would pile up one set per render. */
    function initPan(el) {
        // panReady: the Daily Sheet's own initialiser. dlPanReady: this module. Either means bound.
        if (el.dataset.dlPanReady === '1' || el.dataset.panReady === '1') return;
        el.dataset.dlPanReady = '1';
        // Marked as well as bound: the grab/hand cursor is keyed off the attribute, and an
        // auto-discovered table deserves the same affordance as an explicitly marked one.
        el.setAttribute('data-dl-hscroll', '');

        var panning = false;
        var startX = 0;
        var startY = 0;
        var startLeft = 0;
        var startTop = 0;
        var moved = false;

        el.addEventListener('pointerdown', function (e) {
            // Mouse only: touch and trackpad gestures must stay native.
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            var target = e.target;
            if (target && target.closest && target.closest(INTERACTIVE)) return;
            panning = true;
            moved = false;
            startX = e.clientX;
            startY = e.clientY;
            startLeft = el.scrollLeft;
            startTop = el.scrollTop;
            el.classList.add('dl-panning');
            if (el.setPointerCapture) el.setPointerCapture(e.pointerId);
        });

        el.addEventListener('pointermove', function (e) {
            if (!panning) return;
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            if (!moved && Math.abs(dx) + Math.abs(dy) < 4) return; // a click, not a drag
            moved = true;
            el.scrollLeft = startLeft - dx;
            el.scrollTop = startTop - dy;
            e.preventDefault();
        });

        function endPan() {
            if (!panning) return;
            panning = false;
            el.classList.remove('dl-panning');
        }
        el.addEventListener('pointerup', endPan);
        el.addEventListener('pointerleave', endPan);
        el.addEventListener('pointercancel', endPan);

        el.addEventListener('wheel', function (e) {
            if (!e.shiftKey) return;
            var delta = e.deltaY || e.deltaX;
            if (!delta) return;
            el.scrollLeft += delta;
            e.preventDefault();
        }, { passive: false });
    }

    function ensureBar() {
        if (bar && document.body.contains(bar)) return bar;
        bar = document.createElement('div');
        bar.id = BAR_ID;
        bar.setAttribute('aria-hidden', 'true');
        // A REAL overflow container, not a drawn thumb: the platform supplies the scrollbar the user
        // already knows how to drag, including its own keyboard and assistive behaviour.
        bar.style.cssText = 'position:fixed;z-index:45;bottom:0;left:0;width:0;height:16px;' +
            'overflow-x:auto;overflow-y:hidden;display:none;background:#fff;' +
            'border-top:1px solid rgba(17,24,39,.12);box-shadow:0 -1px 4px rgba(17,24,39,.06)';
        inner = document.createElement('div');
        inner.id = INNER_ID;
        inner.style.cssText = 'height:1px;width:1px';
        bar.appendChild(inner);
        document.body.appendChild(bar);

        bar.addEventListener('scroll', function () {
            if (!activeTarget) return;
            // Writing scrollLeft fires the other element's scroll event too; each side writes only
            // when the two actually disagree, so this settles instead of echoing.
            if (Math.abs(bar.scrollLeft - activeTarget.scrollLeft) > 1) {
                activeTarget.scrollLeft = bar.scrollLeft;
            }
        }, { passive: true });
        return bar;
    }

    function syncFromTarget(el) {
        if (!bar || activeTarget !== el) return;
        if (Math.abs(el.scrollLeft - bar.scrollLeft) > 1) bar.scrollLeft = el.scrollLeft;
    }

    function positionBar(el) {
        var rect = el.getBoundingClientRect();
        var width = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(rect.left, 0));
        bar.style.left = Math.round(Math.max(rect.left, 0)) + 'px';
        bar.style.width = Math.round(width) + 'px';
        inner.style.width = Math.max(el.scrollWidth, Math.round(width)) + 'px';
    }

    function refresh() {
        refreshQueued = false;
        var discovered = discover();

        // Bind pan to anything new; remember everything that can overflow.
        for (var i = 0; i < discovered.length; i++) {
            initPan(discovered[i]);
            if (targets.indexOf(discovered[i]) === -1) {
                (function (el) {
                    el.addEventListener('scroll', function () { syncFromTarget(el); }, { passive: true });
                })(discovered[i]);
                targets.push(discovered[i]);
            }
        }

        // One bar serves the table the reader is actually looking at. Picking the LARGEST visible
        // overflowing container keeps a sticky page (filters, cards, then the table) honest without
        // stacking one scrollbar per table on top of the last.
        var best = null;
        var bestArea = 0;
        for (var t = 0; t < targets.length; t++) {
            var el = targets[t];
            if (!document.body.contains(el) || !overflows(el)) continue;
            var area = visibleArea(el);
            if (area > bestArea) { bestArea = area; best = el; }
        }

        if (!best) {
            if (bar) { bar.style.display = 'none'; }
            activeTarget = null;
            return;
        }

        ensureBar();
        activeTarget = best;
        positionBar(best);
        bar.style.display = '';
        bar.scrollLeft = best.scrollLeft;
    }

    function scheduleRefresh() {
        if (refreshQueued) return;
        refreshQueued = true;
        window.requestAnimationFrame ? window.requestAnimationFrame(refresh) : setTimeout(refresh, 16);
    }

    function start() {
        // The cursor is the only feedback that a drag is in progress, and it has to exist in every
        // shell this module is loaded into - so it ships with the module rather than in one stylesheet.
        if (!document.getElementById('dl-scroll-style')) {
            var style = document.createElement('style');
            style.id = 'dl-scroll-style';
            style.textContent =
                '[data-dl-hscroll]{cursor:grab}' +
                '[data-dl-hscroll].dl-panning{cursor:grabbing;user-select:none}' +
                '[data-dl-hscroll].dl-panning *{cursor:grabbing}';
            document.head.appendChild(style);
        }
        scheduleRefresh();
        window.addEventListener('resize', scheduleRefresh);
        // CAPTURE, not bubble: the module pages scroll inside #main-content (and the Daily Sheet inside
        // its own box), so a plain window listener never fires and the bar would never appear when the
        // reader actually reached the table. Measured 2026-11-14: it did not.
        document.addEventListener('scroll', scheduleRefresh, { capture: true, passive: true });

        // Alpine re-renders rows, tabs and filters without a navigation. Watching the document for
        // child changes keeps the bar honest, debounced to one animation frame.
        if (window.MutationObserver) {
            var observer = new MutationObserver(scheduleRefresh);
            observer.observe(document.body, { childList: true, subtree: true });
        }
        if (window.ResizeObserver) {
            var sizes = new ResizeObserver(scheduleRefresh);
            var tables = document.querySelectorAll('table');
            for (var i = 0; i < tables.length; i++) sizes.observe(tables[i]);
        }
        // Entering/leaving the viewport is what decides whether a bar is useful, and a scroll event
        // alone misses the first paint (the table can start below the fold).
        if (window.IntersectionObserver) {
            var watcher = new IntersectionObserver(scheduleRefresh, { threshold: [0, 0.01] });
            var candidates = document.querySelectorAll('table');
            for (var c = 0; c < candidates.length; c++) watcher.observe(candidates[c]);
        }
    }

    window.dlScrollRefresh = scheduleRefresh;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
