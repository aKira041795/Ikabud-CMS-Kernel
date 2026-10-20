#!/usr/bin/env node
/**
 * Claude.ai page adapter for the HARPP consult lane.
 *
 * Drives the real claude.ai web UI with a persistent, logged-in Chromium profile so a
 * consultation can use the owner's Claude subscription instead of API credits. This is a
 * web-UI adapter, not an API: claude.ai is not a stable integration surface, so every
 * failure is reported as one JSON line and the caller fails closed.
 *
 * Prefer the Anthropic API backend when a key is available. Use this adapter only when
 * the subscription is the intended transport, and never commit the profile directory.
 *
 * Modes:
 *   login  — open a HEADED browser with the persistent profile so the owner can sign in
 *            once. The session is saved for later runs.
 *   run    — open the profile browser (HEADED by default; add --headless to opt out),
 *            open a new chat, submit the prompt file, wait for the reply to settle,
 *            print one JSON line to stdout.
 *
 * Usage:
 *   node claude_page.js login [--profile DIR] [--wait SECONDS]
 *   node claude_page.js run --prompt FILE [--profile DIR] [--timeout SECONDS] [--headless]
 *
 * Output (single JSON line on stdout):
 *   {"ok": true, "text": "..."}          on success (run)
 *   {"ok": false, "error": "..."}        on any failure (non-zero exit)
 */
"use strict";

const fs = require("fs");
const os = require("os");
const path = require("path");
const { chromium } = require("playwright");

const XDG_CONFIG_HOME = process.env.XDG_CONFIG_HOME || path.join(os.homedir(), ".config");
const HARPP_PROFILE = path.join(XDG_CONFIG_HOME, "harpp", "claude-profile");
const LEGACY_PROFILE = path.join(XDG_CONFIG_HOME, "chair-consult", "claude-profile");
const DEFAULT_PROFILE = process.env.CLAUDE_CONSULT_PROFILE
    || (fs.existsSync(HARPP_PROFILE) || !fs.existsSync(LEGACY_PROFILE) ? HARPP_PROFILE : LEGACY_PROFILE);
const HOME_URL = "https://claude.ai/new";

const COMPOSER_SELECTORS = [
    'div[contenteditable="true"]',
    '[data-testid="chat-input"]',
    'div.ProseMirror[contenteditable="true"]',
];
const SEND_BUTTON_SELECTORS = [
    'button[aria-label*="Send"]',
    'button[data-testid="send-button"]',
    'button[type="submit"]',
];
// claude.ai has changed its message markup more than once. Keep the specific selectors
// first and end with a generic turn selector so a rename degrades instead of failing.
const ASSISTANT_REPLY_SELECTORS = [
    '[data-testid="assistant-message"]',
    'div.font-claude-message',
    'div[data-message-author-role="assistant"]',
    '[data-testid="chat-message"]',
    '[data-testid="conversation-turn"]',
];
const STOP_BUTTON_SELECTORS = [
    'button[aria-label*="Stop"]',
    'button[data-testid="stop-button"]',
];
const LOGIN_SELECTORS = [
    'a[href*="/login"]',
    'button:has-text("Log in")',
    'button:has-text("Sign in")',
    'button:has-text("Continue with Google")',
    'button:has-text("Continue with email")',
    'a:has-text("Log in")',
    'a:has-text("Sign in")',
    'a:has-text("Continue with Google")',
];
// A free account hits a message cap. Treat it as a distinct, reportable state so the
// caller does not silently retry (which would consume the next window's quota).
const LIMIT_REGEX = /usage limit|message limit|limit reached|reached your (?:free )?limit|out of (?:free )?messages|free messages|message cap/i;

function argValue(args, name, fallback) {
    const i = args.indexOf(name);
    return i !== -1 && args[i + 1] ? args[i + 1] : fallback;
}

function usage() {
    console.error("usage: node claude_page.js <login|run> [--profile DIR] [--prompt FILE] [--timeout SECONDS] [--wait SECONDS] [--headless]");
    process.exit(2);
}

function out(obj) {
    process.stdout.write(JSON.stringify(obj) + "\n");
}

async function launch(profile, headless) {
    const opts = {
        headless,
        viewport: { width: 1280, height: 900 },
        args: ["--disable-blink-features=AutomationControlled"],
    };
    try {
        return await chromium.launchPersistentContext(profile, opts);
    } catch (err) {
        // Fall back to system Chrome if the Playwright chromium build is unavailable.
        return await chromium.launchPersistentContext(profile, { ...opts, channel: "chrome" });
    }
}

function firstVisible(page, selectors) {
    return page.locator(selectors.join(", ")).first();
}

async function visibleCount(locator) {
    return locator.count().then((n) => n > 0).catch(() => false);
}

async function detectLimit(page) {
    try {
        // Prefer an alert/dialog: a persistent upgrade banner or a help link must not be
        // mistaken for the account's message cap.
        const alert = page.locator('[role="dialog"], [role="alert"]').filter({ hasText: LIMIT_REGEX }).first();
        if ((await alert.count()) && (await alert.isVisible())) {
            return (await alert.innerText()).trim().slice(0, 240);
        }
        const main = page.locator('main').getByText(LIMIT_REGEX).first();
        if ((await main.count()) && (await main.isVisible())) {
            return (await main.innerText()).trim().slice(0, 240);
        }
    } catch { /* not a limit state */ }
    return "";
}

async function isLoggedIn(page) {
    // A visible composer is the positive signal, but claude.ai may show a composer to a
    // signed-out visitor too. A visible Log in / Sign in affordance wins.
    const login = firstVisible(page, LOGIN_SELECTORS);
    if (await visibleCount(login)) {
        try {
            if (await login.isVisible({ timeout: 1000 })) return false;
        } catch { /* ignore */ }
    }
    const composer = firstVisible(page, COMPOSER_SELECTORS);
    try {
        await composer.waitFor({ state: "visible", timeout: 4000 });
        return true;
    } catch {
        return false;
    }
}

async function waitForLoggedIn(page, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (await isLoggedIn(page)) return true;
        try { await page.waitForTimeout(2000); } catch { return false; }
    }
    return false;
}

async function waitForComposer(page, timeoutMs) {
    const composer = firstVisible(page, COMPOSER_SELECTORS);
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            await composer.waitFor({ state: "visible", timeout: 2000 });
            return composer;
        } catch {
            // keep polling through transient navigation / challenge states
        }
    }
    throw new Error(`composer did not appear within ${timeoutMs}ms`);
}

async function startFreshChat(page) {
    try {
        await page.goto(HOME_URL, { waitUntil: "domcontentloaded", timeout: 20000 });
    } catch { /* the caller may already be on a new chat */ }
}

async function submitPrompt(page, composer, prompt) {
    const fillTimeout = Number(process.env.CLAUDE_FILL_TIMEOUT_MS || 15000);
    let filled = false;
    try {
        await composer.fill(prompt, { timeout: fillTimeout });
        filled = true;
    } catch { /* fall through to the keyboard path */ }
    if (!filled) {
        await composer.click({ force: true, timeout: 5000 }).catch(() => { });
        await page.keyboard.insertText(prompt);
    }
    // Prefer an explicit send button; Enter is the fallback for layouts where the button
    // is hidden until the composer has focus.
    const send = firstVisible(page, SEND_BUTTON_SELECTORS);
    let sent = false;
    try {
        if (await send.isVisible({ timeout: 2000 })) {
            await send.click({ timeout: 3000 });
            sent = true;
        }
    } catch { /* fall back to Enter */ }
    if (!sent) {
        await page.keyboard.press("Enter");
    }
}

async function waitForAssistantReply(page, timeoutMs, prompt) {
    const assistant = page.locator(ASSISTANT_REPLY_SELECTORS.join(", ")).last();
    const promptText = String(prompt || "").trim();
    let lastLen = -1;
    let lastText = "";
    const deadline = Date.now() + timeoutMs;
    // Poll for EITHER a reply element OR a limit alert. Waiting only on the reply
    // element would turn a free-account cap into a generic timeout and hide the cause.
    let attached = false;
    while (Date.now() < deadline && !attached) {
        const limit = await detectLimit(page);
        if (limit) {
            throw new Error("Claude usage limit reached: " + limit);
        }
        attached = await assistant.count().then((n) => n > 0).catch(() => false);
        if (!attached) {
            await page.waitForTimeout(1000);
        }
    }
    if (!attached) {
        throw new Error("no Claude reply element appeared before the timeout");
    }
    while (Date.now() < deadline) {
        const limit = await detectLimit(page);
        if (limit) {
            throw new Error("Claude usage limit reached: " + limit);
        }
        const stop = firstVisible(page, STOP_BUTTON_SELECTORS);
        const stopping = await visibleCount(stop);
        const text = (await assistant.innerText().catch(() => "")) || "";
        // A generic message selector can match the user's own turn before the assistant
        // renders. Never return the prompt as the reply: wait for text that is not an echo.
        const echo = Boolean(text.trim()) && Boolean(promptText) && (
            text.trim() === promptText || (text.trim().length > 120 && promptText.includes(text.trim()))
        );
        if (text) lastText = echo ? "" : text;
        const stable = text.length > 0 && text.length === lastLen && !echo;
        if (stable && !stopping) {
            return { text, partial: false };
        }
        lastLen = echo ? -1 : text.length;
        try {
            await page.waitForTimeout(1500);
        } catch (err) {
            // The page/context went away mid-wait. Keep whatever was already read and mark
            // it partial rather than discarding a reply that is on screen.
            if (lastText.trim()) {
                return { text: lastText, partial: true };
            }
            throw err;
        }
    }
    const finalText = lastText || (await assistant.innerText().catch(() => "")) || "";
    if (finalText.trim()) {
        return { text: finalText, partial: true };
    }
    throw new Error("timed out waiting for a Claude reply in any known reply element");
}

async function cmdLogin(args) {
    const profile = argValue(args, "--profile", DEFAULT_PROFILE);
    const wait = parseInt(argValue(args, "--wait", "420"), 10);
    let ctx;
    try {
        ctx = await launch(profile, false);
        const page = ctx.pages()[0] || (await ctx.newPage());
        await page.goto(HOME_URL, { waitUntil: "domcontentloaded", timeout: 30000 });
        const deadline = Date.now() + wait * 1000;
        let loggedIn = false;
        while (Date.now() < deadline) {
            if (await isLoggedIn(page)) {
                loggedIn = true;
                break;
            }
            await page.waitForTimeout(2000);
        }
        if (loggedIn) {
            console.log("Claude login confirmed; session saved in " + profile);
            out({ ok: true, profile });
            return 0;
        }
        out({ ok: false, error: "not logged in after waiting; profile saved for the next login attempt" });
        return 1;
    } catch (err) {
        out({ ok: false, error: String(err && err.message ? err.message : err) });
        return 1;
    } finally {
        if (ctx) {
            try { await ctx.close(); } catch { /* ignore */ }
        }
    }
}

async function cmdRun(args) {
    const profile = argValue(args, "--profile", DEFAULT_PROFILE);
    const promptFile = argValue(args, "--prompt", "");
    const timeout = parseInt(argValue(args, "--timeout", "300"), 10) * 1000;
    const headless = args.includes("--headless");
    if (!promptFile || !fs.existsSync(promptFile)) {
        out({ ok: false, error: "no --prompt FILE (or file missing)" });
        return 2;
    }
    const prompt = fs.readFileSync(promptFile, "utf8");
    let ctx;
    try {
        ctx = await launch(profile, headless);
        const page = ctx.pages()[0] || (await ctx.newPage());
        await page.goto(HOME_URL, { waitUntil: "domcontentloaded", timeout: 30000 });
        if (!(await waitForLoggedIn(page, 30000))) {
            out({ ok: false, error: `not logged in to claude.ai (profile: ${profile}); run the 'login' subcommand` });
            return 1;
        }
        await startFreshChat(page);
        const composer = await waitForComposer(page, 30000);
        await submitPrompt(page, composer, prompt);
        const reply = await waitForAssistantReply(page, timeout, prompt);
        out(reply.partial ? { ok: true, text: reply.text, partial: true } : { ok: true, text: reply.text });
        return 0;
    } catch (err) {
        const message = String(err && err.message ? err.message : err);
        const limit = /usage limit|message limit|limit reached|reached your|out of .*messages/i.test(message);
        out(limit
            ? { ok: false, error: `${message} (profile: ${profile})`, limit: true }
            : { ok: false, error: `${message} (profile: ${profile})` });
        return 1;
    } finally {
        if (ctx) {
            try { await ctx.close(); } catch { /* ignore */ }
        }
    }
}

async function main() {
    const args = process.argv.slice(2);
    const mode = args[0];
    if (mode === "login") {
        process.exit(await cmdLogin(args));
    } else if (mode === "run") {
        process.exit(await cmdRun(args));
    }
    usage();
}

main().catch((err) => {
    out({ ok: false, error: String(err && err.message ? err.message : err) });
    process.exit(1);
});
