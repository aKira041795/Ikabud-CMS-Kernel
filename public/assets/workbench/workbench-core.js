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

    // ── Sidebar Sections ──
    function initSidebarSections() {
        document.querySelectorAll('.wb-sidebar-section__trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var section = this.closest('.wb-sidebar-section');
                var items = section.querySelector('.wb-sidebar-section__items');
                var isOpen = items.style.maxHeight !== '0px' && items.style.maxHeight !== '';

                if (isOpen) {
                    items.style.maxHeight = '0px';
                    this.setAttribute('aria-expanded', 'false');
                } else {
                    items.style.maxHeight = items.scrollHeight + 'px';
                    this.setAttribute('aria-expanded', 'true');
                }
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
        toast.className = 'wb-badge wb-badge--' + variant;
        toast.style.cssText = 'padding:0.75rem 1rem;font-size:var(--wb-text-sm);box-shadow:0 4px 6px rgba(0,0,0,0.1);';
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 300ms ease';
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

            // Clickable rows and cards
            var clickable = e.target.closest('[data-wb-href]');
            if (clickable && e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT') {
                window.location = clickable.getAttribute('data-wb-href');
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
