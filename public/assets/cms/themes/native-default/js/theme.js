/**
 * Native Default Theme - JavaScript
 * Version: 2.0.0 (DiSyL 3.1)
 * 
 * Handles all interactive functionality for the theme.
 */

(function() {
    'use strict';

    // ========================================
    // Mobile Menu Toggle
    // ========================================
    const menuToggle = document.querySelector('.menu-toggle');
    const mobileNav = document.querySelector('.mobile-navigation');

    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', function() {
            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !isExpanded);
            mobileNav.setAttribute('aria-hidden', isExpanded);
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!menuToggle.contains(e.target) && !mobileNav.contains(e.target)) {
                menuToggle.setAttribute('aria-expanded', 'false');
                mobileNav.setAttribute('aria-hidden', 'true');
            }
        });

        // Close mobile menu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                menuToggle.setAttribute('aria-expanded', 'false');
                mobileNav.setAttribute('aria-hidden', 'true');
            }
        });
    }

    // ========================================
    // Search Toggle
    // ========================================
    const searchToggle = document.querySelector('.search-toggle');
    const searchOverlay = document.querySelector('.search-overlay');
    const searchClose = document.querySelector('.search-close');
    const searchInput = document.querySelector('.search-overlay .search-input');

    if (searchToggle && searchOverlay) {
        searchToggle.addEventListener('click', function() {
            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !isExpanded);
            searchOverlay.setAttribute('aria-hidden', isExpanded);
            
            if (!isExpanded && searchInput) {
                setTimeout(() => searchInput.focus(), 100);
            }
        });

        if (searchClose) {
            searchClose.addEventListener('click', function() {
                searchToggle.setAttribute('aria-expanded', 'false');
                searchOverlay.setAttribute('aria-hidden', 'true');
            });
        }

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && searchOverlay.getAttribute('aria-hidden') === 'false') {
                searchToggle.setAttribute('aria-expanded', 'false');
                searchOverlay.setAttribute('aria-hidden', 'true');
            }
        });

        // Close when clicking overlay background
        searchOverlay.addEventListener('click', function(e) {
            if (e.target === this) {
                searchToggle.setAttribute('aria-expanded', 'false');
                searchOverlay.setAttribute('aria-hidden', 'true');
            }
        });
    }

    // ========================================
    // Sticky Header
    // ========================================
    const header = document.querySelector('.site-header.is-sticky');
    
    if (header) {
        let lastScroll = 0;
        const headerHeight = header.offsetHeight;

        window.addEventListener('scroll', function() {
            const currentScroll = window.pageYOffset;

            if (currentScroll <= 0) {
                header.classList.remove('is-scrolled', 'is-hidden');
                return;
            }

            if (currentScroll > headerHeight) {
                header.classList.add('is-scrolled');
            } else {
                header.classList.remove('is-scrolled');
            }

            // Optional: Hide header on scroll down, show on scroll up
            // if (currentScroll > lastScroll && currentScroll > headerHeight * 2) {
            //     header.classList.add('is-hidden');
            // } else {
            //     header.classList.remove('is-hidden');
            // }

            lastScroll = currentScroll;
        }, { passive: true });
    }

    // ========================================
    // Back to Top Button
    // ========================================
    const backToTop = document.querySelector('.back-to-top');

    if (backToTop) {
        // Show/hide button based on scroll position
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTop.classList.add('is-visible');
            } else {
                backToTop.classList.remove('is-visible');
            }
        }, { passive: true });

        // Scroll to top on click
        backToTop.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    // ========================================
    // Smooth Scroll for Anchor Links
    // ========================================
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            if (href === '#') return;
            
            const target = document.querySelector(href);
            
            if (target) {
                e.preventDefault();
                
                const headerOffset = header ? header.offsetHeight : 0;
                const elementPosition = target.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });

    // ========================================
    // Copy Link Button
    // ========================================
    const copyButtons = document.querySelectorAll('.share-btn--copy');

    copyButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const url = this.dataset.url || window.location.href;
            
            try {
                await navigator.clipboard.writeText(url);
                
                // Show feedback
                const originalHTML = this.innerHTML;
                this.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                this.style.background = 'var(--color-success)';
                this.style.color = '#fff';
                
                setTimeout(() => {
                    this.innerHTML = originalHTML;
                    this.style.background = '';
                    this.style.color = '';
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
            }
        });
    });

    // ========================================
    // Dropdown Menu Keyboard Navigation
    // ========================================
    const navItems = document.querySelectorAll('.nav-menu > li');

    navItems.forEach(item => {
        const link = item.querySelector('a');
        const submenu = item.querySelector('.sub-menu');

        if (link && submenu) {
            // Show submenu on focus
            link.addEventListener('focus', function() {
                item.classList.add('is-focused');
            });

            // Hide submenu when focus leaves the item
            item.addEventListener('focusout', function(e) {
                if (!item.contains(e.relatedTarget)) {
                    item.classList.remove('is-focused');
                }
            });

            // Toggle submenu on Enter/Space
            link.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (link.getAttribute('href') === '#') {
                        e.preventDefault();
                        item.classList.toggle('is-open');
                    }
                }
            });
        }
    });

    // ========================================
    // Lazy Load Images (if native lazy loading not supported)
    // ========================================
    if ('loading' in HTMLImageElement.prototype) {
        // Native lazy loading supported
        const images = document.querySelectorAll('img[loading="lazy"]');
        images.forEach(img => {
            if (img.dataset.src) {
                img.src = img.dataset.src;
            }
        });
    } else {
        // Fallback for older browsers
        const lazyImages = document.querySelectorAll('img[loading="lazy"]');
        
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                        }
                        img.removeAttribute('loading');
                        observer.unobserve(img);
                    }
                });
            });

            lazyImages.forEach(img => imageObserver.observe(img));
        }
    }

    // ========================================
    // Animate on Scroll
    // ========================================
    const animateElements = document.querySelectorAll('.animate-fade-up');

    if (animateElements.length > 0 && 'IntersectionObserver' in window) {
        const animateObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                }
            });
        }, {
            threshold: 0.1
        });

        animateElements.forEach(el => {
            el.style.animationPlayState = 'paused';
            animateObserver.observe(el);
        });
    }

    // ========================================
    // Form Validation Feedback
    // ========================================
    const forms = document.querySelectorAll('form');

    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, textarea, select');

        inputs.forEach(input => {
            input.addEventListener('invalid', function() {
                this.classList.add('is-invalid');
            });

            input.addEventListener('input', function() {
                if (this.validity.valid) {
                    this.classList.remove('is-invalid');
                }
            });
        });
    });

    // ========================================
    // External Links
    // ========================================
    const externalLinks = document.querySelectorAll('a[href^="http"]:not([href*="' + window.location.hostname + '"])');

    externalLinks.forEach(link => {
        if (!link.hasAttribute('target')) {
            link.setAttribute('target', '_blank');
        }
        if (!link.hasAttribute('rel')) {
            link.setAttribute('rel', 'noopener noreferrer');
        }
    });

    // ========================================
    // Print Functionality
    // ========================================
    const printButtons = document.querySelectorAll('[data-print]');

    printButtons.forEach(button => {
        button.addEventListener('click', function() {
            window.print();
        });
    });

    // ========================================
    // Dark Mode Toggle (if implemented)
    // ========================================
    const darkModeToggle = document.querySelector('.dark-mode-toggle');

    if (darkModeToggle) {
        // Check for saved preference
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
        }

        darkModeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // ========================================
    // Table of Contents (for long articles)
    // ========================================
    const tocContainer = document.querySelector('.table-of-contents');
    const articleContent = document.querySelector('.article-content');

    if (tocContainer && articleContent) {
        const headings = articleContent.querySelectorAll('h2, h3');
        
        if (headings.length > 2) {
            const tocList = document.createElement('ul');
            tocList.className = 'toc-list';

            headings.forEach((heading, index) => {
                // Add ID to heading if not present
                if (!heading.id) {
                    heading.id = 'section-' + index;
                }

                const li = document.createElement('li');
                li.className = 'toc-item toc-item--' + heading.tagName.toLowerCase();
                
                const link = document.createElement('a');
                link.href = '#' + heading.id;
                link.textContent = heading.textContent;
                link.className = 'toc-link';

                li.appendChild(link);
                tocList.appendChild(li);
            });

            tocContainer.appendChild(tocList);
        }
    }

    // ========================================
    // Reading Progress Bar
    // ========================================
    const progressBar = document.querySelector('.reading-progress');
    const article = document.querySelector('.single-post');

    if (progressBar && article) {
        window.addEventListener('scroll', function() {
            const articleTop = article.offsetTop;
            const articleHeight = article.offsetHeight;
            const windowHeight = window.innerHeight;
            const scrollTop = window.pageYOffset;

            const progress = Math.min(
                Math.max((scrollTop - articleTop + windowHeight) / articleHeight, 0),
                1
            );

            progressBar.style.transform = `scaleX(${progress})`;
        }, { passive: true });
    }

})();
