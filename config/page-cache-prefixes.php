<?php

// ─────────────────────────────────────────────────────────────────────────
// Shared skip-prefixes for page caching (both fast-path and standard).
// Single source of truth — never duplicate in cache implementations.
// ─────────────────────────────────────────────────────────────────────────

return [
    '/api/',
    '/admin/',
    '/login',
    '/logout',
    '/register',
    '/lock.php',
    '/superadmin',
    '/ecommerce/cart',
    '/ecommerce/checkout',
    '/ecommerce/my-orders',
    '/ecommerce/my-wishlist',
    '/ecommerce/recover-cart',
    '/ecommerce/compare',
    '/ecommerce/admin',
    '/ecommerce/store-admin',
    '/cms/login',
    '/cms/register',
    '/cms/admin',
    '/cms/auth',
    '/portal',
    '/ehr/queue-monitor',
    '/attendance-wage/',
    '/assets/',
    // HARPP pages embed a session-bound CSRF token in a <meta name="csrf-token">
    // tag (modules/harpp templates layout.disyl). Caching them would serve a stale
    // per-session token to another session and cause 419 on the auto mark-read POST.
    '/harpp',
];