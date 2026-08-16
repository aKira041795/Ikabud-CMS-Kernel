/*
 * Moto Inventory — Service Worker
 *
 * Same-origin, narrowly scoped. Caches only the versioned static shell and
 * safe GET responses under /moto-inventory/assets/. It never caches POST
 * responses, API responses, authenticated HTML pages, or arbitrary URLs.
 * Offline launches render the cached shell; the app shows a read-only
 * offline note and mutation controls cannot falsely complete.
 */
'use strict';

const CACHE_NAME = 'moto-inventory-shell-v1';

const SHELL_FILES = [
    '/moto-inventory/manifest.json',
    '/moto-inventory/manifest.webmanifest',
    '/moto-inventory/offline.html',
    '/moto-inventory/favicon.png',
    '/moto-inventory/icon-192.png',
    '/moto-inventory/icon-512.png',
    '/moto-inventory/logo-splash.png',
    '/moto-inventory/assets/moto-inventory.css',
    '/moto-inventory/assets/moto-inventory.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(SHELL_FILES))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME && key.startsWith('moto-inventory-'))
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Never intercept non-GET.
    if (request.method !== 'GET') return;

    // Never cache API responses or authenticated module pages.
    if (url.pathname.startsWith('/api/')) return;

    // Authenticated navigations remain network-only, but an offline launch
    // must still receive the committed, non-sensitive fallback shell.
    if (request.mode === 'navigate' && (url.pathname === '/moto-inventory' || url.pathname.startsWith('/moto-inventory/'))) {
        event.respondWith(
            fetch(request).catch(() => caches.match('/moto-inventory/offline.html'))
        );
        return;
    }

    if (url.pathname === '/moto-inventory' || url.pathname.startsWith('/moto-inventory/')) {
        const isAsset = url.pathname.startsWith('/moto-inventory/assets/')
            || url.pathname.endsWith('.png')
            || url.pathname.endsWith('.webmanifest')
            || url.pathname === '/moto-inventory/manifest.json'
            || url.pathname.endsWith('.css')
            || url.pathname.endsWith('.js');
        // Do not cache the server-rendered pages (they are authenticated HTML).
        if (!isAsset) return;
    }

    // Network-first for assets with cache fallback; never store failed auth responses.
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                }
                return response;
            })
            .catch(() => caches.match(request).then((cached) => {
                if (cached) return cached;
                return Response.error();
            }))
    );
});
