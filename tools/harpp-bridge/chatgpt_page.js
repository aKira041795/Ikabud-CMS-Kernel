#!/usr/bin/env node
/**
 * ChatGPT-page adapter for the HARPP ChatGPT Advisor (ideation) lane.
 *
 * Drives the real ChatGPT web (chatgpt.com) using the owner's logged-in Pro/Plus
 * account, so ideation uses the subscription's ChatGPT chat quota — separate from
 * Codex and from OpenAI API billing. It is inherently a web-UI adapter (fragile by
 * design); every failure fails closed and is reported as JSON so the Python lane can
 * stage + notify without dropping work.
 *
 * Modes:
 *   login  — open a HEADED browser with the persistent profile so the owner can log in
 *            once (session persists for later runs). Exit 0 = logged in.
 *   run    — open the profile browser (HEADED by default because chatgpt.com sits behind
 *            Cloudflare bot detection that blocks headless; add --headless to opt out),
 *            start a fresh chat, submit the prompt file, wait for the reply to settle,
 *            print one JSON line to stdout.
 *
 * Usage:
 *   node chatgpt_page.js login [--profile DIR] [--wait SECONDS]
 *   node chatgpt_page.js run --prompt FILE [--profile DIR] [--timeout SECONDS] [--headless]
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

const DEFAULT_PROFILE = process.env.CHAIR_CONSULT_PROFILE
  || path.join(os.homedir(), ".config", "chair-consult", "chatgpt-profile");
const HOME_URL = "https://chatgpt.com/";

function argValue(args, name, fallback) {
    const i = args.indexOf(name);
    return i !== -1 && args[i + 1] ? args[i + 1] : fallback;
}

function usage() {
    console.error("usage: node chatgpt_page.js <login|run> [--profile DIR] [--prompt FILE] [--timeout SECONDS] [--wait SECONDS]");
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

// ChatGPT's web UI is not a stable API surface (the advisor contract flags this). Measured
// 2026-10-05: the app-shell build renders turns with data-conversation-role /
// data-markdown-text-style, so `[data-message-author-role="assistant"]` matched NOTHING and every
// advisor run died on a 100s locator timeout while the reply sat on screen. Keep the classic
// attribute first and fall back to the current ones; a stale selector must not silently kill the
// lane again.
const ASSISTANT_REPLY_SELECTORS = [
    '[data-message-author-role="assistant"]',
    '[data-conversation-role="assistant"]',
    '[data-markdown-text-style="assistant-message"]',
    '[data-content-search-unit-key*="assistant"]',
];
const STOP_BUTTON_SELECTORS = [
    'button[data-testid="stop-button"]',
    'button[aria-label*="Stop streaming"]',
    'button[aria-label*="Stop"]',
];

function assistantLocator(page) {
    return page.locator(ASSISTANT_REPLY_SELECTORS.join(", ")).last();
}

function stopButtonLocator(page) {
    return page.locator(STOP_BUTTON_SELECTORS.join(", ")).first();
}

async function isLoggedIn(page) {
    // ChatGPT shows the composer when logged in; a "Log in" button when not.
    const composer = page.locator('#prompt-textarea, div[contenteditable="true"]').first();
    try {
        await composer.waitFor({ state: "visible", timeout: 4000 });
        return true;
    } catch {
        return false;
    }
}

async function waitForLoggedIn(page, timeoutMs) {
    // chatgpt.com sits behind Cloudflare ("Just a moment...") which can delay the real
    // page several seconds even for an already-logged-in browser. Poll until the composer
    // appears (logged in) or the timeout expires.
    const composer = page.locator('#prompt-textarea, div[contenteditable="true"]').first();
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            await composer.waitFor({ state: "visible", timeout: 2000 });
            return true;
        } catch {
            // keep polling through the Cloudflare challenge / slow load
        }
    }
    return false;
}

async function waitForComposer(page, timeoutMs) {
    const composer = page.locator('#prompt-textarea, div[contenteditable="true"]').first();
    await composer.waitFor({ state: "visible", timeout: timeoutMs });
    return composer;
}

async function startFreshChat(page) {
    // Best-effort: open a fresh thread so the opinion is not biased by prior context.
    for (const sel of ['a[href="/"]', 'button[aria-label*="New chat"]', 'nav a[href="/"]']) {
        const el = page.locator(sel).first();
        if (await el.count()) {
            try {
                await el.click({ timeout: 1500 });
                return;
            } catch { /* ignore */ }
        }
    }
    try {
        await page.goto(HOME_URL, { waitUntil: "domcontentloaded", timeout: 15000 });
    } catch { /* ignore */ }
}

async function submitPrompt(page, composer, prompt) {
    await composer.fill(prompt);
    await composer.press("Enter");
    // Some layouts render Enter as a newline until the first keystroke; retry submit.
    try {
        await page.locator('button[data-testid="send-button"], button[aria-label*="Send"]').first()
            .waitFor({ state: "visible", timeout: 3000 });
        await page.keyboard.press("Enter");
    } catch { /* submit already in flight */ }
}

async function waitForAssistantReply(page, timeoutMs) {
    const assistant = assistantLocator(page);
    await assistant.waitFor({ state: "attached", timeout: timeoutMs });
    let lastLen = -1;
    let lastText = "";
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const stop = stopButtonLocator(page);
        const stopping = await stop.count().then((n) => n > 0).catch(() => false);
        const text = (await assistant.innerText().catch(() => "")) || "";
        if (text) {
            lastText = text;
        }
        const stable = text.length > 0 && text.length === lastLen;
        if (stable && !stopping) {
            return { text, partial: false };
        }
        lastLen = text.length;
        try {
            await page.waitForTimeout(1500);
        } catch (err) {
            // The page/context went away mid-wait — the profile was closed by hand or taken over by
            // another launch on the same persistent profile. Measured 2026-10-06: ChatGPT had already
            // answered and the reply was readable, but this throw discarded it and the lane reported
            // failure, so the harness's own model answered instead. Return what was already read, and
            // mark it partial: the caller must not present a half-streamed opinion as the full one.
            if (lastText.trim()) {
                return { text: lastText, partial: true };
            }
            throw err;
        }
    }
    const finalText = (await assistant.innerText().catch(() => "")) || lastText;
    if (finalText.trim()) {
        return { text: finalText, partial: true };
    }
    throw new Error("timed out waiting for a ChatGPT reply in any known reply element");
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
            console.log("ChatGPT login confirmed; session saved in " + profile);
            out({ ok: true, profile });
            return 0;
        }
        out({ ok: false, error: "not logged in after waiting; profile saved for next login attempt" });
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
            out({ ok: false, error: "not logged in to ChatGPT (or Cloudflare did not clear); run the 'login' subcommand - see extras/chatgpt-advisor/README.md" });
            return 1;
        }
        await startFreshChat(page);
        const composer = await waitForComposer(page, 15000);
        await submitPrompt(page, composer, prompt);
        const reply = await waitForAssistantReply(page, timeout);
        out(reply.partial ? { ok: true, text: reply.text, partial: true } : { ok: true, text: reply.text });
        return 0;
    } catch (err) {
        out({ ok: false, error: String(err && err.message ? err.message : err) });
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
