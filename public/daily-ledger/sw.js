'use strict';

/**
 * Daily Ledger — Service Worker (static offline shell)
 * =====================================================
 * Replaces the old cached-authenticated-HTML model. This worker:
 *
 *   * Pre-caches ONLY the token-free static offline shell and its local
 *     assets. It NEVER caches authenticated HTML, tokens, CSRF, cookies, or
 *     business payloads, and performs NO string rewriting.
 *   * Uses the offline shell as a fallback for the Daily Ledger document
 *     (network-first) — from the installed start URL or a failed
 *     /daily-ledger/ledger navigation. It is NEVER served for APIs or
 *     unrelated routes.
 *   * Activates atomically: the new cache is fully installed before old
 *     versions are deleted; clients are claimed so the first launch works
 *     without a manual reload.
 *   * Required entries are verified at install; optional asset failures are
 *     tolerated so installation never breaks over a single optional file.
 */

const CACHE_VERSION = 'daily-ledger-pwa-v6';
const OFFLINE_SHELL = '/daily-ledger/offline.html';
const LEDGER_PATH = '/daily-ledger/ledger';

// Required: the offline shell cannot work without these.
const REQUIRED_PRECACHE = [
  OFFLINE_SHELL,
  '/daily-ledger/manifest.webmanifest',
  '/daily-ledger/assets/offline-app.js',
  '/daily-ledger/assets/offline-vault.js'
];

// Optional: local runtime assets. A failure here must not break install.
const OPTIONAL_PRECACHE = [
  '/daily-ledger/icons/icon-192.png',
  '/daily-ledger/icons/icon-512.png',
  '/daily-ledger/assets/tailwindcss.js',
  '/daily-ledger/assets/fontawesome/all.min.css',
  '/daily-ledger/assets/htmx-1.9.10.min.js',
  '/daily-ledger/assets/alpine-3.min.js',
  '/daily-ledger/assets/webfonts/fa-brands-400.woff2',
  '/daily-ledger/assets/webfonts/fa-regular-400.woff2',
  '/daily-ledger/assets/webfonts/fa-solid-900.woff2',
  '/daily-ledger/assets/webfonts/fa-v4compatibility.woff2'
];

self.addEventListener('message', event => {
  const data = event.data;
  if (!data || !data.type) return;
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }
  // Forward launch messages (e.g. dl-offline-activated / dl-offline-locked)
  // from a controlled online page to all clients, including the offline shell.
  if (data.type === 'dl-offline-activated' || data.type === 'dl-offline-locked') {
    self.clients.matchAll({ includeUncontrolled: true }).then(clients => {
      clients.forEach(client => client.postMessage({ type: data.type }));
    });
  }
});

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(cache => cache.addAll(REQUIRED_PRECACHE))
      .then(() => {
        // Optional entries added one-by-one; a single failure is ignored.
        return caches.open(CACHE_VERSION).then(cache => {
          return Promise.allSettled(OPTIONAL_PRECACHE.map(url =>
            cache.add(url).catch(() => { })
          ));
        });
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => key.startsWith('daily-ledger-pwa-') && key !== CACHE_VERSION)
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

function isLedgerNavigation(request, url) {
  return request.mode === 'navigate' &&
    url.origin === self.location.origin &&
    url.pathname === LEDGER_PATH;
}

function isExcluded(url) {
  return url.pathname.startsWith('/api/v1/') ||
    url.pathname.startsWith('/daily-ledger/api/') ||
    /\/(login|logout|forgot-password|reset-password)(\/|$)/.test(url.pathname);
}

// Local static assets that belong to the offline shell (cache-first).
function isLocalStaticAsset(url) {
  return url.origin === self.location.origin && (
    url.pathname.startsWith('/daily-ledger/assets/') ||
    url.pathname.startsWith('/daily-ledger/icons/') ||
    url.pathname === '/daily-ledger/manifest.webmanifest' ||
    url.pathname === '/daily-ledger/offline.html'
  );
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_VERSION);
  const cached = await cache.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok && response.type === 'basic') {
    cache.put(request, response.clone());
  }
  return response;
}

async function networkFirstLedger(request) {
  const cache = await caches.open(CACHE_VERSION);
  try {
    return await fetch(request);
  } catch (error) {
    // Offline: fall back to the token-free static shell. Only the Daily
    // Ledger document path reaches here (guarded by the caller), so this
    // never fakes success for APIs or unrelated routes.
    const shell = await cache.match(OFFLINE_SHELL);
    if (shell) return shell;
    throw error;
  }
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (isExcluded(url)) return;

  // Serve self-hosted shell assets from cache while offline.
  if (isLocalStaticAsset(url)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Network-first for the ledger document; fall back to the offline shell.
  if (isLedgerNavigation(request, url)) {
    event.respondWith(networkFirstLedger(request));
    return;
  }
});
