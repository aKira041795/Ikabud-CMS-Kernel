/**
 * Native Default Theme JavaScript
 * Version: 1.0.0
 */

(function () {
    'use strict';

    /**
     * Mobile Menu Toggle
     */
    function initMobileMenu() {
        const toggle = document.querySelector('.mobile-menu-toggle');
        const nav = document.querySelector('.mobile-canvas-target') || document.querySelector('.main-navigation');
        const overlay = document.getElementById('mobile-menu-overlay');
        const isCanvas = !!document.querySelector('.mobile-canvas-target');
        const closeOnLink = (toggle?.getAttribute('data-close-on-link') ?? '1') !== '0';

        if (!toggle || !nav) return;

        const openMenu = function () {
            nav.classList.add('active');
            toggle.classList.add('active');
            if (isCanvas && overlay) {
                overlay.style.display = 'block';
                setTimeout(function () { overlay.style.opacity = '1'; }, 10);
                document.body.style.overflow = 'hidden';
            }
        };

        const closeMenu = function () {
            nav.classList.remove('active');
            toggle.classList.remove('active');
            if (isCanvas && overlay) {
                overlay.style.opacity = '0';
                setTimeout(function () { overlay.style.display = 'none'; }, 300);
                document.body.style.overflow = '';
            }
        };

        toggle.addEventListener('click', function () {
            if (nav.classList.contains('active')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        if (overlay) {
            overlay.addEventListener('click', function () {
                closeMenu();
            });
        }

        const closeBtn = nav.querySelector('.mobile-canvas-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closeMenu();
            });
        }

        if (closeOnLink) {
            nav.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    closeMenu();
                });
            });
        }

        // Close menu when clicking outside
        document.addEventListener('click', function (e) {
            if (!nav.contains(e.target) && !toggle.contains(e.target)) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeMenu();
            }
        });
    }

    /**
     * Smooth Scroll for Anchor Links
     */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
            anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');

                if (targetId === '#') return;

                const target = document.querySelector(targetId);

                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    /**
     * Lazy Load Images
     */
    function initLazyLoad() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        imageObserver.unobserve(img);
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(function (img) {
                imageObserver.observe(img);
            });
        }
    }

    /**
     * Sticky Header
     */
    function initStickyHeader() {
        const wrapper = document.querySelector('.header-wrapper--sticky');
        const header = wrapper ? wrapper.querySelector('.site-header') : document.querySelector('.site-header.site-header--sticky');

        if (!header) return;

        window.addEventListener('scroll', function () {
            const currentScroll = window.pageYOffset;
            const hasScrolled = currentScroll > 0;

            header.classList.toggle('scrolled', hasScrolled);
            if (wrapper) {
                wrapper.classList.toggle('scrolled', hasScrolled);
            }
        });
    }

    /**
     * Search Form Enhancement
     */
    function initSearchForm() {
        const searchForms = document.querySelectorAll('.native-search');

        searchForms.forEach(function (form) {
            const input = form.querySelector('input[type="search"]');

            if (input) {
                // Clear button functionality
                input.addEventListener('input', function () {
                    if (this.value.length > 0) {
                        this.classList.add('has-value');
                    } else {
                        this.classList.remove('has-value');
                    }
                });
            }
        });
    }

    /**
     * Card Hover Effects
     */
    function initCardEffects() {
        const cards = document.querySelectorAll('.ikb-card');

        cards.forEach(function (card) {
            card.addEventListener('mouseenter', function () {
                this.style.transform = 'translateY(-4px)';
            });

            card.addEventListener('mouseleave', function () {
                this.style.transform = '';
            });
        });
    }

    /**
     * Initialize all components
     */
    function init() {
        initMobileMenu();
        initSmoothScroll();
        initLazyLoad();
        initStickyHeader();
        initSearchForm();
        initCardEffects();
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
