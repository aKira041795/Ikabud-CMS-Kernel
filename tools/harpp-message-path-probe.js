#!/usr/bin/env node
/**
 * Bounded end-to-end probe: does a message actually flow through the LOCAL HARPP?
 *
 * Why this exists: the local install (tenant 1232) had harpp_conversations = 0,
 * harpp_messages = 0, harpp_notifications = 0 even though 310 unit tests pass and all 11 pages
 * render. Unit tests prove the pieces; they do not prove a message travels. This sends exactly
 * ONE message through the real UI (not a raw API call - the API correctly requires CSRF, so
 * bypassing the UI would not be evidence of the path a user takes) and then the caller checks
 * the resulting rows.
 */
const nodePath = require('path');
const { createRequire } = require('module');
const repoRequire = createRequire(nodePath.join(__dirname, '..', 'package.json'));
const { chromium } = repoRequire('playwright');

const BASE = process.env.HARPP_BASE || 'http://harpp.test';
const USER = process.env.HARPP_USER || 'owner@harpp.local';
const PASS = process.env.HARPP_PASS || 'admin1234';
const STAMP = 'chair-probe-' + Date.now();

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });

  // The UI uses native prompt() for the conversation title and the harness session id, and
  // createConversation silently returns when either is null. Playwright DISMISSES dialogs by
  // default, so an earlier version of this probe clicked, the prompt was dismissed, the function
  // returned silently, and the probe still reported "sent: true" while writing NO rows at all.
  // A probe that asserts a click instead of an outcome is worthless - handle the dialogs.
  const dialogs = [];
  page.on('dialog', async (d) => {
    const msg = d.message().slice(0, 40);
    dialogs.push(d.type() + ':' + msg);
    try {
      if (d.type() !== 'prompt') return await d.accept();
      // The two prompts need DIFFERENT answers. harness_session_id is validated against
      // /^[A-Za-z0-9._:-]+$/, which REJECTS SPACES. Answering both with a human-readable title
      // containing spaces made the API return 422 and looked like a product defect - it was this
      // probe sending invalid input. Always check the instrument before believing its finding.
      const isSession = /session/i.test(msg);
      await d.accept(isSession ? 'chair-probe-' + Date.now() : 'chair probe ' + Date.now());
    } catch (_) { /* already gone */ }
  });

  await page.goto(BASE + '/harpp/login?disyl_nocache=1', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', USER);
  await page.fill('input[name="password"]', PASS);
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForTimeout(2500);
  console.log('1. logged in, at ' + page.url());

  // The Messenger page is /harpp
  await page.goto(BASE + '/harpp?disyl_nocache=1', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  // Create a conversation using the UI's own control.
  const hadNew = await page.locator('#new-conversation').count();
  console.log('2. #new-conversation present: ' + hadNew);
  if (hadNew) {
    await page.click('#new-conversation');
    await page.waitForTimeout(2500);
  }
  console.log('   url now ' + page.url());

  // Send one message through the compose form.
  const compose = page.locator('#compose');
  const nCompose = await compose.count();
  console.log('3. #compose present: ' + nCompose);
  let sent = false;
  if (nCompose) {
    const field = page.locator('#compose textarea, #compose input[name="body"]').first();
    if (await field.count()) {
      await field.fill(STAMP);
      await page.locator('#compose button[type="submit"], #compose button').first().click().catch(() => {});
      await page.waitForTimeout(2500);
      sent = true;
    }
  }
  console.log('4. message sent through UI: ' + sent + '  (body=' + STAMP + ')');
  // Assert the OUTCOME, not the click. A clicked button proves nothing.
  const status = await page.locator('#messenger-status').innerText().catch(() => '');
  console.log('5. #messenger-status says: ' + JSON.stringify(status.trim().slice(0, 80)));
  console.log('6. dialogs handled: ' + dialogs.length + (dialogs.length ? ' -> ' + dialogs.join(' | ') : ''));
  console.log('7. console errors: ' + errors.length + (errors.length ? ' -> ' + errors.join(' | ') : ''));
  console.log('STAMP=' + STAMP);
  await browser.close();
})().catch((e) => { console.error('FATAL: ' + e.message); process.exit(1); });
