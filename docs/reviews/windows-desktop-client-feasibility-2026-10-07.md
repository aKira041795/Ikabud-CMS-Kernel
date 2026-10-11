# Windows Desktop Client — Feasibility & Decision Report

> **Date:** 2026-10-07
> **Question asked:** "Can we create an app for Windows OS — executable, maybe installable? What do we need to do and prepare to make it doable in a Linux vibe-coding environment?"
> **Method:** Chair-led, repository-grounded consultation with ChatGPT (two rounds), plus independent verification in the repository.
> **Companion documents:** `docs/reviews/mobile-api-platform-implementation-plan-2026-07-10.md`, `docs/reviews/mobile-offline-db-readiness-review-2026-07-10.md`
> **Raw transcripts:** `.ai/consult/windows-desktop-app.md`, `.ai/consult/windows-desktop-app-round2.md`
> **Status:** Feasibility confirmed. **Not started. Three decisions required first (Section 11).**

---

## How to read this document

Claims are labelled, because they are not all the same kind of thing:

| Label | Meaning |
|---|---|
| **[FACT]** | Verified directly in this repository during this session. Reproducible with the command shown. |
| **[ADVISOR]** | ChatGPT's position. Reasoned, cited where possible, not verified here. |
| **[CHAIR]** | My judgement as chair, given the verified facts. |
| **[UNVERIFIED]** | Neither verified nor refuted. Must be checked before it is acted on. |

---

## 1. Bottom line

**Yes — a downloadable, installable Windows application is achievable from this Linux-only environment, without owning a Windows machine.**

But the consultation changed the first question that should be asked. In sequence:

1. **You may already have a Windows app.** The Daily Ledger web client is already an installable PWA with an offline shell, service worker, and manifest. On Windows, Edge installs it as a real application with a Start Menu entry, its own window, and offline operation — at approximately zero cost. See Section 3.
2. **If that satisfies the requirement, do nothing.** The entire native build programme is unnecessary.
3. **If it does not** — because the requirement is specifically a downloadable `.exe` handed to a customer — then the work is real: roughly **4–6 weeks to a credible MVP**, and it must be built as an *independent* client, not as a Kotlin-sharing exercise with the Android apps.

**[CHAIR]** The single highest-value action available today is not a technology decision. It is to establish, with the actual customer or operator, which of these two sentences they mean:

> "I want it to open like an app on my PC." → already true, today.

> "I want a setup file I can download and install on machines we control." → this report's native path.

---

## 2. Verified starting state

All of the following was measured in this repository on 2026-10-07.

### 2.1 The Android clients are not in version control

**[FACT]** The entire `android/` directory is gitignored:

```
.gitignore:3:android/

$ git ls-files android/            →  0 files
$ git status --porcelain -uall android/  →  0 entries
```

Two clients — `android/daily-ledger/` and `android/inventory-scanner/` — exist **only as files in a local working tree**. They have no git history, no CI, no review trail, and are not part of the repository in any meaningful sense. `/bakeshopapp/` is ignored as well.

**Why this mattered:** it invalidated the strongest argument in the advisor's first recommendation. See Section 6.

### 2.2 Cleanup debt in that ignored tree

**[FACT]**

| Finding | Detail |
|---|---|
| Leftover template | `android/settings.gradle.kts` is an unmodified Android Studio template: `rootProject.name = "My Application"`, `include(":app")` only |
| Points at nothing | It references `android/app/`, a bare scaffold with no application code — carrying a **42 MB** `build/` directory |
| Duplicate include | `android/daily-ledger/settings.gradle.kts` lists `include(":app")` **twice** |
| Missing wrapper | `android/inventory-scanner/` has no `gradlew` of its own |
| Build debris | `android/daily-ledger/app/build` is **127 MB** on disk |

### 2.3 The Android clients' shape (reference material, not a build dependency)

**[FACT]** `android/daily-ledger/` is a complete, layered offline-first client:

| Layer | Files |
|---|---|
| `auth/` | `AuthRepository`, `AuthViewModel`, `SecureTokenStore` |
| `network/` | `ApiService`, `AuthInterceptor`, `TokenRefreshAuthenticator`, `ConnectivityMonitor` |
| `db/` | `AppDatabase`, `Dao`, `Entities`, `DbModule` |
| `sync/` | `LedgerSyncWorker`, `ProductionSyncWorker`, `WorkManagerModule` |
| `core/` `ui/` `user/` `prefs/` | `AppConfig`, ViewModels, Compose screens |

Outbox tables are `pending_row_changes` and `pending_production_operations`. `LedgerSyncWorker` refreshes auth best-effort, flushes pending changes, and **bounds retries** so a permanently invalid batch does not churn the queue forever. Base URL and host header live in one file, `core/AppConfig.kt`.

Endpoint surface it consumes (tenant-prefixed):

```
POST /daily-ledger/auth/login            POST /daily-ledger/auth/refresh
GET  /daily-ledger/api/v1/me             GET  /daily-ledger/api/v1/cashier/ledger/rows
GET  /daily-ledger/api/v1/cashier/ledger/day-status
POST /daily-ledger/api/v1/cashier/ledger/save-batch
POST /daily-ledger/api/v1/cashier/ledger/close-day
POST /daily-ledger/api/v1/admin/reopen-day
POST /daily-ledger/api/v1/production/sync-batch
```

**[CHAIR]** The *design* here is valuable and proven. The *code* is not currently an asset you can build on, because it is not in the repository.

### 2.4 CI is entirely Linux

**[FACT]** `.github/workflows/` contains only `ci.yml` and `ark-workbench.yml`. All six jobs are `runs-on: ubuntu-latest`. **No Windows runner exists today.**

### 2.5 A distributable-package convention already exists

**[FACT]** `packages/lane-harness/` is a mature precedent for shipping a versioned artefact: `build.sh` (allowlist payload, secret scan that can fail, coupling scan), `VERSION`, `dist/*.zip` + `dist/*.tar.gz`, `install.sh`, `uninstall.sh`.

Notably, its `WINDOWS-QUICKSTART.txt` states the repo's existing Windows stance:

> "Short version: use WSL2. It is one command, VS Code supports it natively, and it is the only Windows option that gives the harness full behaviour."

**[CHAIR]** That is the correct answer for *developer tooling*. It is not an answer for a *product* delivered to customers. Do not confuse the two.

### 2.6 The client/server contract is already specified

**[FACT]** `docs/reviews/mobile-api-platform-implementation-plan-2026-07-10.md` Phase 4 ("Offline Synchronization", ~1.5–2 weeks) already defines: sync cursors, server revisions, tombstones, conflict responses, and idempotent batch push/pull.

**[CHAIR]** A new Windows client should implement **that** contract. The contract is the source of stability; the clients are replaceable. This is also why a native client is not blocked by the Android situation — it can target the documented contract rather than the Android implementation.

---

## 3. The option nobody costed: you already ship a Windows app

**[FACT]** Daily Ledger is already a fully-formed installable Progressive Web App:

| Artefact | Evidence |
|---|---|
| Manifest | `public/daily-ledger/manifest.webmanifest` — `"display": "standalone"`, `id`, `start_url`, `scope`, 192 px and 512 px `maskable` icons |
| Service worker | `public/daily-ledger/sw.js` (157 lines) — precaches **only** the token-free static offline shell; explicitly never caches authenticated HTML, tokens, CSRF, or cookies |
| Offline layer | `public/daily-ledger/assets/offline-app.js`, `offline-vault.js` |
| Offline auth UI | `templates/modules/daily-ledger/cashier/offline_auth.disyl` |
| Wiring | `templates/modules/daily-ledger/layouts/app.disyl:8` (`rel="manifest"`), `:106` (service worker registration) |

**The same pattern is already used elsewhere in this codebase:**

- HARPP — `templates/modules/harpp/login.disyl` loads `/harpp/manifest.webmanifest` and `/assets/modules/harpp/pwa.js`
- moto-inventory — `public/moto-inventory/manifest.webmanifest`, `public/moto-inventory/service-worker.js`

### What Windows does with this

An operator opens Microsoft Edge → **Install this site as an app**. They get:

- A Start Menu entry and taskbar/tile presence
- Its own window, no browser chrome or address bar
- Offline operation via the cached shell
- **Per-user install, no administrator rights**
- **No SmartScreen prompt** — nothing is downloaded, so nothing is reputation-gated

### Honest limits

A PWA does **not** give: a downloadable setup file to hand to a customer, file-type associations, a background/tray service, auto-start at login, native printer control, or a machine-wide install.

### [CHAIR] Cost comparison

| Path | Effort | Delivers a downloadable installer? |
|---|---|---|
| **Point users at the existing PWA** | ~0 — documentation only | No |
| **Add missing install affordance** (in-app "Install app" button, offline-status UX) | days | No |
| **Native client** (Section 10) | ~4–6 weeks MVP, 6–10 hardened | Yes |

**[CHAIR]** This is the cheapest option that satisfies a real reading of the request, and it was not raised by either consultation round because the question was framed to the advisor as "build a client." It is the first thing to test with the user of the feature.

---

## 4. Option comparison

**[ADVISOR]** assessment, with the correction from round 2 applied.

| Option | Develop on Linux | Produce Windows binary from Linux | Produce installer from Linux | Windows runner required | Reuse of existing work |
|---|---|---|---|---|---|
| **Kotlin + Compose Desktop** | Yes | JVM code yes; Windows runtime packaging is OS-specific | Generally no for normal packaging | Yes | Design only (see note) |
| **Rust + Tauri** | Yes | Yes (`cargo-xwin`) | NSIS yes; **MSI no** | Recommended | None |
| **Electron** | Yes | Yes | NSIS EXE yes, via Wine | Recommended | None |
| **.NET WPF** | Partial | Cross-targeting possible | Problematic | Yes | None |
| **.NET WinUI 3** | Poor | Not a supported practical Linux workflow | No | Required | None |
| **Flutter Desktop** | Yes (coding) | **No** — tooling refuses to build Windows off-Windows | No | Required | None |
| **Qt / C++** | Yes | Yes, with a cross toolchain | Possible | Strongly recommended | None |

**Note on the reuse column:** in round 1, Compose ranked first specifically because it could share Kotlin with the Android clients. **[FACT]** that reuse is not available — the Android clients are not in the repository (Section 2.1). The advisor revised its own position to **Compose ≈ Tauri**, with the choice resting on whether Kotlin is wanted as the long-term native-client language.

**[CHAIR]** Two observations the advisor did not raise, both from this codebase:

1. **The "web skills" advantage of Electron/Tauri is smaller here than it looks.** The React/TypeScript in this repo is `modules/cms/builder-ui` — a page-builder admin surface, not a general application framework. It cannot be lifted into a desktop client.
2. **The tenant-host model is a real design constraint for any client.** Endpoints are tenant-prefixed (`/daily-ledger/...`) and the backend is multi-tenant with per-tenant databases and Bluehost MySQL 5.7. A desktop client must resolve tenant host identity at login, exactly as the Android client does via `AppConfig.kt`.

---

## 5. Packaging and installer formats

**[ADVISOR]**

| Format | Verdict |
|---|---|
| Bare `.exe` (no installer) | Do not ship to customers. No install directory, no Start Menu entry, no uninstaller, no upgrade path. |
| **NSIS `.exe` installer** | **Recommended for the first release.** `Ikabud-Setup-x64.exe`, familiar download-and-run, supports silent install, per-user *or* per-machine, CI-friendly. |
| MSI / WiX | Add later, only when a customer needs SCCM/Intune/GPO deployment. Tauri cannot produce its MSI from Linux at all. |
| MSIX | Not needed for self-distribution. Adds package identity, certificate requirements, and Windows-specific testing. |

**Per-user vs per-machine:** start **per-user** (`%LOCALAPPDATA%\Programs\Ikabud\`) — no administrator rights, fewer UAC and support problems, easier upgrades. Move to per-machine (`C:\Program Files\`) only when a customer requires it; that necessarily introduces elevation.

---

## 6. Code signing

**[ADVISOR]** Signing is **not optional** for a professional downloadable Windows application. Authenticode signatures are required on at least the application executable and the installer, with timestamping.

| Item | Position |
|---|---|
| **EV vs OV** | EV no longer buys preferential SmartScreen treatment. Do not pay for EV on that basis. |
| **OV certificate** | Roughly **US$150–500/year** depending on provider. Allow **several days** for organisation validation, identity documents, issuance, and token/HSM setup. Do not leave procurement to release week. |
| **Azure Trusted Signing** | Roughly **US$9.99/month**, integrates cleanly with CI, no USB token to maintain. |
| **SmartScreen** | Signing does **not** guarantee zero warnings on day one. Reputation accrues with publisher/download history. Initial warnings on a proper signature are normal. |

### Two things that matter most here

1. **[UNVERIFIED — verify before procurement]** Azure Trusted Signing's current organisational availability is listed by Microsoft as US, Canada, EU and UK, i.e. **not the Philippines**. If correct, it cannot be the primary signing mechanism for this project. Regional availability changes, so check immediately before committing.
2. **[ADVISOR] Signing is cross-platform in principle.** `osslsigncode` and `jsign` can sign EXE/DLL/MSI on Linux and support PKCS#11 and cloud-HSM scenarios. **The obstacle is the private key, not the algorithm** — modern certificates keep it in a hardware token, HSM, or cloud service. If the vendor exposes PKCS#11 or an API usable from Linux, Linux CI signing works. If the vendor ships Windows-only middleware, signing moves to a Windows runner.

---

## 7. What can never be done on Linux

**[ADVISOR]** The distinction that matters is **technically impossible** versus **release-engineering unacceptable**.

Cross-building binaries is often possible. **Proving Windows behaviour is not.** Linux cannot validate:

- Real Windows GUI behaviour, font and rendering differences
- UAC elevation, SmartScreen, Windows Defender
- Start Menu integration, file associations, uninstall behaviour
- Windows path semantics, user profile/roaming behaviour
- Sleep/resume and network-switching behaviour
- Real Windows auto-update

**[ADVISOR] Most important sentence in this section:**

> "It launches under Wine" is not evidence that "it works on Windows 11." Those are different claims.

Wine is useful for *executing installer tooling* on Linux (NSIS and similar). It is not a validation environment.

**[CHAIR]** The good news: none of this requires owning a Windows PC. A GitHub Actions `windows-latest` runner performs the build and the automated checks. But before calling the product **supported**, someone must perform one real interactive acceptance pass on actual Windows (a VM is fine):

```
download → install → SmartScreen/UAC → login → offline transaction
        → reconnect → sync → upgrade → uninstall
```

---

## 8. What ChatGPT said — round 1

**[ADVISOR]** Recommendation:

- **Kotlin Multiplatform + Compose Desktop**, reusing/refactoring the existing Android client into shared Kotlin modules
- **`windows-latest` GitHub Actions as the canonical Windows builder**
- Signed **per-user NSIS EXE** installer, downloaded from the project website
- No MSIX, MSI, Microsoft Store, ARM64, or auto-update in the first release
- Pilot on **Daily Ledger** — the business module whose Android client already defines the authentication and offline-sync contract
- Reject WPF/WinUI (Linux environment), Electron (a second client ecosystem), Flutter (no Windows build from Linux), Qt (maintenance cost)
- Effort: 1–2 weeks prototype · 4–6 weeks MVP · 6–10 weeks hardened

It explicitly rejected a Linux-only Windows release pipeline, while conceding that "100% Linux cross-compilation" should not be a design requirement.

---

## 9. The correction — round 2, and the advisor's retraction

I put the repository measurements from Section 2.1 to the advisor and asked whether its recommendation survived. It did not, and the retraction was direct:

> **"The correction materially changes my recommendation. The weakest part of my Round 1 answer was treating the Android clients as reusable repository assets. They are not."**

> **"My Compose-first ranking depended heavily on extracting shared Kotlin from those clients... Given your measurements, that premise is gone."**

### Revised position

| Question | Round 1 | Round 2 (corrected) |
|---|---|---|
| Compose vs Tauri | Compose clearly first | **Compose ≈ Tauri** — no decisive winner |
| Android consolidation | Implicit prerequisite | **Explicitly withdraws it.** Not a prerequisite. |
| Desktop architecture | "KMP shared Android/Desktop client" | **"Independent Windows client against the same Ikabud client/server contract"** |
| First decision | "How much Kotlin can we reuse?" | **"What implementation stack do we own for desktop independently of Android?"** |

It also separated two kinds of reuse that round 1 had conflated:

- **Design/contract reuse** — API shapes, JWT refresh behaviour, tenant selection, outbox concepts, retry/idempotency semantics, sync rules. **[CHAIR]** Credible today; the contract is documented (Section 2.6).
- **Source-code reuse** — Kotlin data classes, repositories, Room models, sync engine. **Not credible today** without first making the Android trees governed repository assets.

### The sequencing it refuses

> **"I would not undertake Android repository cleanup just to justify Compose Desktop."**

And it refuses to run these in parallel:

> "You would be changing three boundaries simultaneously: bringing previously unmanaged applications under repository governance; restructuring multiple Gradle builds; defining the desktop architecture. A failure would be difficult to attribute, and desktop progress would become hostage to unrelated Android cleanup."

One thing it permits in parallel: **read-only examination of the Android implementations** to document synchronisation behaviour. No Gradle consolidation, no shared module, no source moves.

### On placement

**[ADVISOR]** Prefers **`packages/desktop/`** over `desktop/`, because that says "separately buildable, versioned, distributable Ikabud product artefact" rather than "a source tree alongside `android/`". **Definitely not under the ignored `android/` hierarchy.** CI should emit the Windows artefact into a generated output area, not commit installers.

**[UNVERIFIED]** It explicitly flagged that it could not verify the `packages/` convention from the context it was given, and that if `packages/` turns out to be reserved for shell-distributed operational tooling rather than application products, the placement decision changes. **The convention has not been confirmed by a document; it was inferred from `packages/lane-harness/`.**

---

## 10. What to prepare in the Linux repository

**[CHAIR]** Consolidated from both rounds, ordered by necessity. Nothing here is written yet.

### Structure

```
packages/desktop/
    src/
    tests/
    settings.gradle.kts        # owns its own build; inherits NOTHING from android/
    build.gradle.kts
    packaging/windows/
        installer/             # NSIS script
        icons/                 # generated, not hand-maintained
        assets/
    scripts/
    VERSION
    README.md
```

### Toolchain and CI

| Item | Purpose |
|---|---|
| `.github/workflows/desktop-release.yml` on `windows-latest` | The Windows build machine you do not have to own |
| Pinned versions committed | Kotlin, Compose Multiplatform, Gradle wrapper, JDK, SQLite, HTTP client — release runners must not float independently |
| Version stamping from the git tag | `v1.2.3` becomes application version, installer version, file metadata, and release name. One authority, not four hand-edited numbers. |
| Icon pipeline from one source | One SVG → 16/24/32/48/64/128/256/512 px + `.ico`. Do not maintain fifteen files by hand. |
| Checksums and release manifest | `Ikabud-1.2.3-x64-Setup.exe`, `.sha256`, `SHA256SUMS.txt`, plus a `release.json` (`version`, `platform`, `arch`, `url`, `sha256`, `published_at`) — which is also the groundwork for auto-update later. |

### The first CI job — and what it must prove

**[ADVISOR]** The smallest useful job is **not** "compile some Kotlin." It must prove one real Windows packaging path end to end:

```
checkout → toolchain → tests/build → invoke Windows packaging
        → assert the expected installer artefact exists → upload
```

**[CHAIR]** Heed this qualification: **"installer file exists" is not evidence a customer can use it.** Only a subsequent acceptance test — on a clean Windows environment — that installs, starts, reports its identity/version, and uninstalls, turns a packaging milestone into a validated distributable. **Do not let the first green runner be reported as "we have a Windows app."**

### Recommended work order

```
freeze the client/server contract
        ↓
independent desktop skeleton (own build, own packaging, own CI)
        ↓
Windows CI / packaging proof          ← answers: can we repeatedly produce an installer?
        ↓
Daily Ledger vertical slice           ← login → tenant → fetch → persist → offline action
        ↓                                → outbox → reconnect → sync → server confirmation
installer acceptance (install/start/uninstall on clean Windows)
        ↓
operational hardening (logging, recovery UX, version stamping, upgrade behaviour)
        ↓
code signing  ←  when distributing beyond internal testing
        ↓
ONLY THEN: decide whether Android is worth importing and whether KMP sharing is worthwhile
```

### Effort

**[ADVISOR]** revised, with the Android work shown as *separate* rather than omitted:

| Milestone | Estimate |
|---|---|
| Phase 0 — contract extraction into a written client contract | 2–4 days |
| Phase 1 — desktop skeleton + first Windows CI job + generated installer | 3–5 days |
| **Windows packaging proof (milestone)** | **~1 week** |
| Phase 2 — Daily Ledger vertical slice | 1–2 weeks |
| **Daily Ledger functional pilot** | **~2–3 weeks total** |
| Phase 3 — installer acceptance + operational hardening | ~1 week |
| **Credible desktop MVP** | **~4–6 weeks** |
| **Hardened, signable release** | **~6–10 weeks** |
| *Android rehabilitation — separate, later, not on this critical path* | *~1–3 weeks, no tighter estimate possible* |

**[ADVISOR]** On the top-line number: "the MVP estimate is not dramatically different from Round 1. What changes is what those weeks contain." Previously that architectural capital was spent on KMP extraction; now it goes into documenting the real API/sync contract, making desktop autonomous, proving Windows CI, proving installer behaviour, and hardening one vertical slice.

**[CHAIR]** The Android rehabilitation estimate cannot be tightened because there is no tracked build history, no test history, and no CI evidence for those clients — a direct consequence of Section 2.1.

---

## 11. Decisions required

None of these can be delegated to an implementation lane. They are the owner's.

### Decision 1 — PWA or native?

**[CHAIR]** Do not skip this by proceeding to Decision 2.

- If the requirement is *"I want it to open like an app on my PC"* → **already satisfied today** (Section 3). Close the request.
- If the requirement is *"I want a setup file we can download and install on machines we control"* → proceed to Decision 2.

### Decision 2 — Independent client, or shared-code client?

**[ADVISOR]** Its recommendation, in its own words:

> "GO, but change the architecture from 'KMP shared Android/Desktop client' to **'independent Windows client against the same Ikabud client/server contract.'**"

**[CHAIR]** Agreed, and the reasoning is stronger than the conclusion suggests: the contract is documented (Section 2.6), the Android *design* is proven, and the Android *code* is unavailable. Building desktop against the documented contract makes it correct by construction and removes Android from the critical path entirely.

### Decision 3 — Which stack do we own for desktop, independently of Android?

**[ADVISOR]** Compose and Tauri are now equivalent on evidence. Choose on **which stack the team wants to own long-term**, not on reuse that does not exist.

**[CHAIR]** Where the tie breaks, for this codebase specifically: if Kotlin is the intended long-term native-client language (mobile *and* desktop), Compose keeps that option open. If the priority is the smallest, most self-contained independent Windows client, Tauri is at least equally defensible — and Tauri is the only mainstream option that can produce a Windows NSIS installer **directly from Linux**, which is a genuine fit for this development environment.

### Decision 4 (separate governance) — do the Android clients belong in version control?

**[CHAIR]** This is **not** a desktop prerequisite, and the advisor explicitly withdrew it as one. It is a legitimate question on its own merits: two clients are in production use and are not in the repository, so they have no history, no review trail, and no CI.

> **[ADVISOR]** "If yes, bring them under source control **for their own reasons** — not as payment for permission to build Windows."

---

## 12. Open items to verify before they are acted on

| # | Item | Why it matters |
|---|---|---|
| 1 | **Azure Trusted Signing organisational availability in the Philippines** | If unavailable, the CI-native signing route is closed and procurement changes to an OV certificate with a PKCS#11 token. **[UNVERIFIED]** |
| 2 | **Is `packages/` reserved for operational tooling or does it cover application products?** | Decides `packages/desktop/` vs `desktop/`. The convention was inferred from one example. **[UNVERIFIED]** |
| 3 | **Which signing vendor exposes PKCS#11 or an API usable from Linux?** | Decides whether signing runs in Linux CI or on a Windows runner. **[UNVERIFIED]** |
| 4 | **Real certificate cost and lead time for this organisation** | Advisor ranges (US$150–500/yr, several days) are market ranges, not quotes. **[UNVERIFIED]** |

---

## 13. Chair summary

**[CHAIR]** The feasibility question is answered: yes, with a Windows CI runner substituting for a Windows machine, and with the explicit understanding that Linux can *build* Windows artefacts but cannot *prove* Windows behaviour.

Three things went differently from the original framing:

1. **A cheaper answer exists and was not being considered.** The Daily Ledger PWA is already installable on Windows today. The honest first move is to find out whether that already satisfies the requirement, because if it does the remaining 4–10 weeks are unnecessary.
2. **The advisor's first recommendation rested on a premise that measurement destroyed.** It retracted cleanly rather than defending it. The durable lesson is not about Kotlin or Tauri; it is that a reuse-based argument must have its reuse *verified* before it is weighed.
3. **The Android situation is a finding in its own right.** Two production clients are not in version control. That is worth a decision regardless of whether a Windows app is ever built.

The recommended sequence deliberately keeps Android off the critical path: **contract → independent desktop skeleton → Windows packaging proof → one vertical slice → installer acceptance → hardening → signing.** Prove Windows is a worthwhile client of this platform before reorganising anything else around the assumption that it is.

---

## Appendix A — Tool defects found during this session

Recorded because they caused wasted cycles and will recur. Stored in repository memory at
`/memories/repo/chair-consult-profile-auth.md`.

1. **`chair_consult.py` defaults to an unauthenticated profile.** `tools/harpp-bridge/chair_consult.py` defaults to `~/.config/chair-consult/chatgpt-profile` (4.1 MB, never logged in), while `~/.config/harpp/chatgpt-profile` (17 MB) **is** authenticated. Every consultation fails with `not logged in to ChatGPT (or Cloudflare did not clear)` until `CHAIR_CONSULT_PROFILE` is set. Profile size is the quick tell.
2. **`--session` is broken.** The flag appends the new turn to the transcript file and then passes **that whole file** to the browser as `--prompt` (`chatgpt_page.js` `composer.fill()`s it into a chat started at `page.goto(HOME_URL)`). Round 2 therefore pasted a 49 KB transcript into a fresh chat and returned nothing after a full ~300 s timeout. **Workaround: one-shot mode only** — one fresh `--out` file per round, continuity restated in `--instruction`.
3. **`--json` hides the adapter error.** `_emit()` prints the JSON payload instead of the stderr line, and the payload carries no `error` field, so a `--json` failure is indistinguishable from an empty reply. Diagnose with `--json` off, or run the adapter directly.

**[CHAIR]** Defect 2 is the instructive one: a broken "continue the discussion" path is indistinguishable from a model with nothing to say. Continuity features must be proven with a two-turn exchange before they are trusted.

## Appendix B — Raw transcripts

| File | Contents |
|---|---|
| `.ai/consult/windows-desktop-app.md` | Round 1: prompt, retrieved repository facts, full reply |
| `.ai/consult/windows-desktop-app-round2.md` | Round 2: corrected premise, full reply and retraction |

Both are under `.ai/*`, which is gitignored (`.gitignore:119`). They are working artefacts; this document is the durable record.
