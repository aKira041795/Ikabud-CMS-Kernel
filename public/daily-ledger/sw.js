'use strict';

const CACHE_VERSION = 'daily-ledger-pwa-v3';
const LEDGER_PATH = '/daily-ledger/ledger';
const PRECACHE_URLS = [
  '/daily-ledger/manifest.webmanifest',
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

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(async cache => {
        await cache.addAll(PRECACHE_URLS);
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key.startsWith('daily-ledger-pwa-') && key !== CACHE_VERSION).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

function isLedgerNavigation(request, url) {
  return request.mode === 'navigate' && url.origin === self.location.origin && url.pathname === LEDGER_PATH;
}

function isExcluded(url) {
  return url.pathname.startsWith('/api/v1/') ||
    url.pathname.startsWith('/daily-ledger/api/') ||
    /\/(login|logout)(\/|$)/.test(url.pathname);
}

// Local static files that are safe to serve from cache while offline. These are
// precached at install and, on a cache miss while online, are stored at runtime.
function isLocalStaticAsset(url) {
  return url.origin === self.location.origin && (
    url.pathname.startsWith('/daily-ledger/assets/') ||
    url.pathname.startsWith('/daily-ledger/icons/') ||
    url.pathname === '/daily-ledger/manifest.webmanifest'
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
    const response = await fetch(request);
    const finalUrl = new URL(response.url);
    const isLedgerHtml = response.ok &&
      !response.redirected &&
      response.type === 'basic' &&
      finalUrl.origin === self.location.origin &&
      finalUrl.pathname === LEDGER_PATH &&
      (response.headers.get('Content-Type') || '').includes('text/html');
    if (isLedgerHtml) {
      const html = await response.clone().text();
      const sanitized = html
        .replace(/window\.DL_CSRF\s*=\s*'[^']*';/, "window.DL_CSRF = '';")
        .replace(/window\.DL_TOKEN\s*=\s*'[^']*';/, "window.DL_TOKEN = '';")
        .replace('window.DL_OFFLINE_SHELL = false;', 'window.DL_OFFLINE_SHELL = true;');
      const headers = new Headers(response.headers);
      headers.delete('Content-Length');
      await cache.put(LEDGER_PATH, new Response(sanitized, {
        status: response.status,
        statusText: response.statusText,
        headers
      }));
    }
    return response;
  } catch (error) {
    const cached = await cache.match(LEDGER_PATH);
    if (cached) return cached;
    throw error;
  }
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin === self.location.origin && url.pathname === '/daily-ledger/logout') {
    event.waitUntil(caches.open(CACHE_VERSION).then(cache => cache.delete(LEDGER_PATH)));
    return;
  }
  if (isExcluded(url)) return;

  // Serve the self-hosted shell assets from cache so the cached ledger page
  // renders (CSS/JS/icons) while offline instead of breaking on network failures.
  if (isLocalStaticAsset(url)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  if (isLedgerNavigation(request, url)) {
    event.respondWith(networkFirstLedger(request));
    return;
  }
});
