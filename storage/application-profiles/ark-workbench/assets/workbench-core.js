/**
 * ARK Workbench v0.1 — Core JavaScript
 *
 * Provides: shell, mobile drawer, bottom navigation, sidebar sections,
 * skip-link, toast notifications, validation summary focus.
 *
 * Dependencies: none (vanilla JS, no framework required)
 */
(function () {
    'use strict';

    // ── Mobile Drawer ──
    function initMobileDrawer() {
        const toggleBtn = document.getElementById('wb-menu-btn');
        const sidebar = document.getElementById('wb-sidebar');
        const overlay = document.getElementById('wb-overlay');

        if (!toggleBtn || !sidebar || !overlay) return;

        function open() {
            sidebar.classList.add('is-open');
            overlay.classList.add('is-visible');
            toggleBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
            // Focus first nav item
            const firstLink = sidebar.querySelector('.wb-nav-item');
            if (firstLink) firstLink.focus();
        }

        function close() {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            toggleBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
            toggleBtn.focus();
        }

        toggleBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
        });

        overlay.addEventListener('click', close);

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                close();
            }
        });
    }

    // ── Sidebar Sections (CSS-only — toggle class, no inline maxHeight) ──
    function initSidebarSections() {
        document.querySelectorAll('.wb-sidebar-section__trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var section = this.closest('.wb-sidebar-section');
                if (!section) return;
                var isCollapsed = section.classList.toggle('wb-sidebar-section--collapsed');
                this.setAttribute('aria-expanded', String(!isCollapsed));
            });
        });
    }

    // ── Toast Notifications ──
    window.wbToast = function (message, variant) {
        variant = variant || 'informational';
        var container = document.getElementById('wb-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'wb-toast-container';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            container.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:100;display:flex;flex-direction:column;gap:0.5rem;';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'wb-badge wb-badge--' + variant + ' wb-toast';
        toast.style.cssText = 'padding:0.75rem 1rem 0.75rem 2.5rem;font-size:var(--wb-text-sm);box-shadow:0 6px 16px rgba(0,0,0,0.15);border-radius:0.5rem;position:relative;animation:wbToastIn 250ms ease-out;';
        // Add icon based on variant
        var iconMap = { 'success': '✅', 'danger': '❌', 'warning': '⚠️', 'informational': 'ℹ️' };
        toast.textContent = message;
        toast.style.paddingLeft = '2.5rem';
        var icon = document.createElement('span');
        icon.textContent = iconMap[variant] || 'ℹ️';
        icon.style.cssText = 'position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);font-size:1rem;line-height:1;';
        toast.insertBefore(icon, toast.firstChild);
        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(1rem)';
            toast.style.transition = 'opacity 300ms ease, transform 300ms ease';
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    };

    // ── Validation Summary Focus ──
    function initValidationSummary() {
        var summary = document.getElementById('wb-validation-summary');
        if (summary) {
            summary.focus();
            // Link clicks: scroll to field
            summary.addEventListener('click', function (e) {
                var link = e.target.closest('a[href^="#"]');
                if (link) {
                    e.preventDefault();
                    var target = document.getElementById(link.getAttribute('href').substring(1));
                    if (target) {
                        target.focus();
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }
    }

    // ── cms-toast listener — bridges ajaxSubmit CustomEvent to wbToast ──
    document.addEventListener('cms-toast', function (e) {
        var detail = e.detail || {};
        var variant = detail.type === 'error' || detail.type === 'danger' ? 'danger'
            : detail.type === 'success' ? 'success'
                : detail.type === 'warning' ? 'warning'
                    : 'informational';
        wbToast(detail.message || detail.title || '', variant);
    });

    // ── Init ──
    document.addEventListener('DOMContentLoaded', function () {
        initMobileDrawer();
        initSidebarSections();
        initValidationSummary();
        initDelegatedClicks();
    });

    // ── Delegated click handlers (no inline onclick) ──
    function initDelegatedClicks() {
        document.addEventListener('click', function (e) {
            // Collapse toggle
            var toggle = e.target.closest('.wb-collapse-toggle');
            if (toggle) {
                var targetSelector = toggle.getAttribute('data-wb-collapse-target');
                var container = toggle.closest('fieldset') || toggle.closest('.wb-form-section');
                if (container && targetSelector) {
                    var target = container.querySelector(targetSelector);
                    if (target) {
                        target.classList.toggle('hidden');
                        var isHidden = target.classList.contains('hidden');
                        toggle.setAttribute('aria-expanded', String(!isHidden));
                    }
                }
            }

            // Clickable rows and cards — skip if click originated from interactive element
            var clickable = e.target.closest('[data-wb-href]');
            if (clickable) {
                var interactive = e.target.closest(
                    'a, button, input, select, textarea, [role="button"], [data-wb-stop-row-click]'
                );
                if (!interactive) {
                    window.location.assign(clickable.getAttribute('data-wb-href'));
                }
            }
        });

        // Keyboard activation for role="link" elements
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                var el = e.target.closest('[data-wb-href][role="link"]');
                if (el) {
                    e.preventDefault();
                    window.location = el.getAttribute('data-wb-href');
                }
            }
        });
    }
})();
