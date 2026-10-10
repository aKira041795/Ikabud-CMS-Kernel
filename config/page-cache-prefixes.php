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
    // daily-ledger was missing from this list while every sibling module (cms, ecommerce,
    // attendance-wage, harpp, portal) was registered, so its pages remained page-cacheable. That
    // served a STALE page after each deploy: the fix was on the server, the operator kept seeing the
    // old render, and the only way through was the developer-only ?disyl_nocache flag - which no
    // user knows about, and which also happens to be this cache's own bypass. Every daily-ledger
    // page is per-user operational data (ledger sheets, cashier views, area-scoped admin), so it
    // must never be cached. Prefixed on '/daily-ledger/' to cover the whole module, including any
    // route added later.
    '/daily-ledger/',
    '/assets/',
    // HARPP pages embed a session-bound CSRF token in a <meta name="csrf-token">
    // tag (modules/harpp templates layout.disyl). Caching them would serve a stale
    // per-session token to another session and cause 419 on the auto mark-read POST.
    '/harpp',
];