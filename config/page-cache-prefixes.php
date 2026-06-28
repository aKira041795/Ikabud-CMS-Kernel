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
];