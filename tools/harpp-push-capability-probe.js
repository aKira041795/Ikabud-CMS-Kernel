#!/usr/bin/env node
/**
 * Probe: is Web Push structurally possible on this host?
 *
 * Both the Service Worker API and the Push API require a SECURE CONTEXT (HTTPS, or a
 * localhost/127.0.0.1 origin). If HARPP is served over plain http on a non-localhost hostname,
 * the browser will never expose PushManager - so "the push never arrives" is not a bug in HARPP's
 * push code, it is a property of the origin. That distinction decides where the fix goes.
 */
const nodePath = require('path');
const { createRequire } = require('module');
const repoRequire = createRequire(nodePath.join(__dirname, '..', 'package.json'));
const { chromium } = repoRequire('playwright');

const BASE = process.env.HARPP_BASE || 'http://harpp.test';
const USER = process.env.HARPP_USER || 'owner@harpp.local';
const PASS = process.env.HARPP_PASS || 'admin1234';

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });

    await page.goto(BASE + '/harpp/login?disyl_nocache=1', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', USER);
    await page.fill('input[name="password"]', PASS);
    await page.click('button[type="submit"]').catch(() => { });
    await page.waitForTimeout(2500);

    const probe = await page.evaluate(async () => {
        const out = {
            origin: location.origin,
            isSecureContext: window.isSecureContext,
            hasServiceWorker: 'serviceWorker' in navigator,
            hasPushManager: 'PushManager' in window,
            hasNotification: 'Notification' in window,
            notificationPermission: window.Notification ? Notification.permission : null,
            swRegistration: null,
            swError: null,
        };
        if (out.hasServiceWorker) {
            try {
                const reg = await navigator.serviceWorker.getRegistration('/harpp/');
                out.swRegistration = reg ? (reg.active ? 'active' : (reg.installing ? 'installing' : 'registered')) : null;
            } catch (e) { out.swError = String(e).slice(0, 200); }
        }
        return out;
    });

    console.log('=== Web Push capability probe ===');
    for (const [k, v] of Object.entries(probe)) console.log('  ' + k.padEnd(24) + ': ' + v);
    console.log('  console errors          : ' + consoleErrors.length + (consoleErrors.length ? ' -> ' + consoleErrors.join(' | ') : ''));

    const possible = probe.isSecureContext && probe.hasPushManager;
    console.log('\n  VERDICT: Web Push is ' + (possible ? 'POSSIBLE on this origin' : 'IMPOSSIBLE on this origin'));
    if (!possible) {
        const why = [];
        if (!probe.isSecureContext) why.push('the origin is not a secure context (needs HTTPS, or localhost/127.0.0.1)');
        if (!probe.hasPushManager) why.push('window.PushManager is not exposed');
        console.log('  Reason: ' + why.join('; '));
        console.log('  => the server-side push code cannot be exercised from this origin at all.');
    }
    await browser.close();
})().catch((e) => { console.error('FATAL: ' + e.message); process.exit(1); });
