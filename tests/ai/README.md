# ARK Workbench Test Harness

Browser-driven E2E testing + AI-assisted failure diagnosis for Ikabud Kernel OS modules.

## Quick Start

```bash
# 1. Set credentials (never commit these)
export ADMIN_USER=pAladmin
export ADMIN_PASS=pal123456

# 2. Optional: enable AI diagnosis
export ARK_AI_API_KEY=gsk_...
# Edit tests/ai/config.json: "enabled": true

# 3. Run the full pipeline
npm run test:ark
```

## What it does

```
Playwright browser tests
    ↓
WorkbenchReporter (manifest + issues + fingerprints)
    ↓
ARK Test Steward
    ├── Deterministic classifier (patterns: timeout, assertion, network, DB)
    └── AI fallback (if confidence < 75% or undetermined)
    ↓
steward-diagnosis.json + coverage-report.json
```

## Commands

| Command | What it does |
|---|---|
| `npm run test` | Playwright tests only |
| `npm run test:ark` | Full pipeline (tests + Steward triage) |
| `npm run test:ark:coverage` | AI coverage review (no tests run) |
| `npm run test:ark:triage` | Tests + failure diagnosis |
| `npm run test:steward` | Steward only (uses existing results) |
| `npm run test:steward:coverage` | Coverage review only |

## File structure

```
tests/
├── browser/
│   ├── WorkbenchFixture.js        # Playwright fixture (auth, integrity, diagnostics)
│   ├── WorkbenchReporter.js       # Custom reporter (manifest, issues, fingerprints)
│   └── modules/pal/workflows/
│       └── pal-lifecycle-interactive.spec.js  # E2E JO workflow test
│
├── ai/
│   ├── ArkTestSteward.js          # Deterministic + AI failure analyst
│   ├── ArkTestSteward.spec.js     # Steward unit tests
│   ├── config.json                # AI provider settings
│   ├── prompts/
│   │   ├── triage.md              # AI prompt for failure analysis
│   │   └── coverage-review.md     # AI prompt for coverage gaps
│   ├── policies/
│   │   └── healing-policy.json    # Auto/manual/forbidden repair rules
│   ├── schemas/
│   │   └── steward-result.schema.json  # Output validation schema
│   └── fixtures/                  # Test fixtures for Steward unit tests
│
└── pal/                           # PHP seed scripts (dev convenience)
    ├── pal_seed_lifecycle.php
    └── pal_seed_interactive.php
```

## Configuration

### AI providers

Edit `tests/ai/config.json`:

```json
{
  "ai": {
    "enabled": true,
    "provider": "groq",              // or "deepseek"
    "model": "llama-3.3-70b-versatile",  // or "deepseek-v4-pro"
    "endpoint": "https://api.groq.com/openai/v1/chat/completions"
  }
}
```

API key via `ARK_AI_API_KEY` env var — **never committed**.

### Quality gates

| Env var | Values | Effect |
|---|---|---|
| `WB_ISSUE_GATE` | `off`, `critical`, `major` | Fails CI on blocking issues |
| `WB_FINGERPRINT_MODE` | `check`, `update`, `off` | Source fingerprint validation |

## Adding a new module

1. Create adapter in `tests/browser/` using `createWorkbenchTest()`
2. Write Playwright specs using `data-wb-*` selectors (from `?wb_inspect=1`)
3. Use `integrity.issue()` / `.friction()` / `.perf()` / `.a11y()` for diagnostics
4. Run `npm run test:ark` to validate

## Test patterns

```js
// Stable selectors — prefer data-wb-* attributes
page.locator('[data-wb-action="save-as-draft"]')
page.locator('[data-wb-entity-type="project"][data-wb-entity-id="123"]')

// Required elements — hard fail if missing
await expect(element).toBeVisible()

// Non-blocking issues — collect without aborting
integrity.issue({ kind: 'bug', severity: 'major', detail: '...' })
integrity.friction('Button hidden behind modal')
integrity.perf('page load', 4200)
integrity.a11y('Missing aria-label')

// Web-first assertions with custom messages
await expect(page.locator('#wb-main'), 'Status should be Pending')
    .toContainText('Pending')
```
