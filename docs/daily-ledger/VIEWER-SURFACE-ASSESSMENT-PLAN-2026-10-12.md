# VIEWER SURFACE — ASSESSMENT & REMEDIATION PLAN

**Date:** 2026-10-12 · **Module:** daily-ledger · **Role audited:** `viewer` (business owner, read-only)
**Status:** PLAN — nothing below is implemented yet except item W0.
**Reviewers landed:** Sol (full assessment, claims verified) and the chair (rulings in **§11**).
**Intended use:** hand this file to a new session as the work order.

---

## 1. THE BAR (owner's words, verbatim — judge every change against these)

> "recheck all viewer page wiring. there are buttons that don't make sense at all"
>
> "when i say doesn't make sense, it does not offer useful UI or purpose"
>
> "all buttons with no bg color, need to be understood as to what its purpose. why it exists, what
> data it emits. how useful are these"

The standard is **not** "does it work". It is "does it earn its place in front of an owner who only reads".

---

## 2. SCOPE

Five pages are visible to `viewer`:

| Page | Route |
|---|---|
| Business Health (overview) | `/daily-ledger/admin/overview` |
| Products Overview | `/daily-ledger/admin/products/performance` |
| Production Efficiency | `/daily-ledger/admin/production-efficiency` |
| Reports (6 types + PDF/CSV) | `/daily-ledger/admin/reports` |
| Production Forecast | `/daily-ledger/admin/forecast` |

Plus the shared layout (`layouts/app.disyl`: sidebar + header) and the login page.

**Nav currently rendered for viewer:** Business Health · Products Overview · Production Efficiency ·
Reports · Production Forecast · Logout. Production Forecast was added to this role on 2026-10-12 (W0).

---

## 3. EVIDENCE BASE

**Session:** logged in as `admin_view` (role `viewer`) on `baronledger.test`, tenant 207, 22 branches,
**only 2 of which carry official sales**.

Reproduce the neutral-control inventory (every control whose computed background is transparent):

```js
// in the browser console, or via Playwright page.evaluate on each viewer route
Array.from(document.querySelectorAll('a, button, input[type=submit]'))
  .filter(el => {
    const bg = getComputedStyle(el).backgroundColor;
    return (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') && (el.innerText || el.value || '').trim();
  })
  .map(el => ({ t: el.innerText.trim(), cls: el.getAttribute('class'),
                href: el.getAttribute('href'),
                sends: el.closest('form') ? [...el.closest('form').querySelectorAll('input,select')]
                        .map(i => i.getAttribute('name')).filter(Boolean).join(',') : '' }));
```

Page weight is logged by the renderer — `storage/logs/app.log`, `disyl.render.breakdown`,
`output_bytes`. Forecast measured **426,015 bytes** with no paging.

**Reviewers (advice, not evidence):**
- **Sol** — full assessment at `/tmp/lane-sol-viewer-ui-openai-codex_gpt-5.6-sol.log`. ⚠️ `/tmp` is
  volatile: if it is gone, re-dispatch with `/tmp/sol-viewer-ui.md` (the brief) via
  `lane_model_run`. Its claims are marked **[VERIFIED]** below only where I checked the code.
- **Chair (ChatGPT)** — session `viewer-surface-2026-10-12`, brief `/tmp/chair-viewer-ui.md`, reply
  `--out /tmp/chair-viewer-ui-reply.md`. **PENDING at time of writing** — see §7.

> **RULE:** a reviewer's claim is not evidence. Each finding below is marked VERIFIED (with
> `file:line` or a measured URL) or CLAIM (must be checked before implementing).

---

## 4. FINDINGS

### W0 — Forecast exposed to the viewer role ✅ DONE (commit pending in this session)
Handler guard and sidebar gate both changed; the page renders for `admin_view · viewer`.
Evidence: nav shows "Production Forecast"; `/admin/forecast` returned 200 as viewer.

| # | Finding | Evidence | Verdict |
|---|---|---|---|
| **F1** | Forecast sort links **omit `product_id`**, so clicking a sort CLEARS a selected product filter | [VERIFIED] the two links I wrote emit `scope,branch_id,date_from,date_to,target_date,window,empty_rows,sort_dir`; the form sends `…,product_id,…` | **Fix** — merge sort into the form |
| **F2** | The forecast filter form has **no `sort_dir` field**, so `Recalculate` silently resets the sort to descending | [VERIFIED] form sends `scope,target_date,window,branch_id,product_id,empty_rows` (measured) | **Fix** — same work item as F1 |
| **F3** | The inactive sort direction renders `class="btn btn-sm "` with **no background**, so it looks disabled | [VERIFIED] computed style transparent; sibling is `btn-primary` | **Fix** — removed by F1/F2 |
| **F4** | `sort_by=cumulative` is **not a cumulative sort**: it maps to `amount` and the direction re-ranks the whole list, discarding a "Rank by: Units sold" choice | [VERIFIED] `modules/daily-ledger/helpers/reporting.php:2037-2039` (`if ($by === 'cumulative') { $by = 'amount'; }`), field declared at `:1593` | **Fix** — remove the prose link + clickable heading |
| **F5** | `Detailed sales analysis` opens a **0-row** report on a fresh load (period defaults to today) beside a lifetime-sales headline | [VERIFIED] link emits today→today; destination measured **"0 rows"**, empty table | **Fix** — remove or move beside the period |
| **F6** | `Inspect {branch}` **always** links to Variances, even when the verdict's cause is pending endings or an open day | [VERIFIED] measured href `/admin/reports/variances?…` while the verdict was "0 recorded activity · 22 silent" | **Fix** — cause-specific destination |
| **F7** | Operator instructions leaked onto a read-only surface: "Resolve what blocks the verdict", "Complete pending endings", "Finalize pending endings…" | [VERIFIED] rendered on Business Health for a viewer | **Fix** — rename to read-only phrasing |
| **F8** | Products Overview empty state says "…or include pending rows" — **that control does not exist** on this page (handler forces `pending_rows_mode = exclude`) | [VERIFIED] `templates/…/products-performance.disyl:62`; `handleAdminProductsPerformance()` sets exclude | **Fix** — remove the phrase |
| **F9** | Production Efficiency's `Movement` select **auto-submits** (`onchange="this.form.submit()"`) while every other field waits for `Apply` | [VERIFIED] `templates/…/production-efficiency.disyl:37` | **Fix** — use the single Apply |
| **F10** | `Detailed forecast` from Business Health carries **no scope/branch/period**, so it resets the analysis | [VERIFIED] measured href `/daily-ledger/admin/forecast` with no query | **Fix** — carry context |
| **F11** | "Business Health" **overstates** what is measured: the verdict only reflects ledger finalization, unresolved variance flags, and day closure — reporting/control health, not financial health | [VERIFIED] `handleAdminOverview()` computes `healthVerdict` from `completeness['pending']`, `unresolvedVarianceCount`, `openCards` | **Rename** (see D4) |
| **F12** | Login marks `Full Name` **required for every role**; for a personal account the profile update is refused, but the typed value still becomes the session/audit actor name | [VERIFIED] `login.disyl` `required` on `#full_name`; server refuses overwrite for non-shared accounts yet assigns `payload['name']` | **Fix** (see D6/W10) |
| **F13** | 22 branch-name links are styled as plain indigo text — weak affordance in a dense table | [VERIFIED] `text-sm font-semibold text-indigo-700` | **Restyle** (Sol disagrees with my "remove": keep, make clickable) |
| **F14** | 20 of 22 branches are "silent", so the no-activity section can dominate the page | [VERIFIED] "0 recorded activity · 22 silent" | **Reduce weight** — collapse behind a count |
| **F15** | Report archive table shows title/format/generated-by/archive-ID with **no open/download action** | CLAIM — Sol inspected the template; not yet reproduced by me | **Verify then decide** |
| **F16** | Exports create an archived report + audit rows, on a read-only role | CLAIM (Sol) + design question | **Decide** — see D3 |
| **F17** | Forecast is 426 KB with 454 rows, no paging and no "showing X of Y" | [VERIFIED] `output_bytes: 426015` | **Decide** — see D7 |

---

## 5. DECISIONS (with the falsifier that would reverse each)

**D1 — Sort control pattern.** Sol recommends a `sort_dir` `<select>` inside the existing form
(rejecting two links, segmented toggle, and a flipping link). *Falsifier:* if the owner needs
immediate feedback without an extra click, a link-based toggle wins — but only once state
serialization is generated from one place rather than hand-written per link.

**D2 — One apply model per page.** The activity checkbox already requires a submit. If sorting also
submits, the page has ONE model. Two models (checkbox=submit, sort=link) is what produced F1–F3.

**D3 — Read-only policy.** May a viewer's export create archive + audit rows? *Decision input:* Sol
judges it an accountability side effect of reading, acceptable for a viewer. If the answer is "no",
viewers lose PDF/CSV entirely or need a non-archiving download path. **Owner decision.**

**D4 — Naming.** Rename the verdict from "Business Health" to reporting/control health (e.g.
"Reporting health" / "Ledger control status"). *Falsifier:* if the owner reads "healthy" as financial
health, the rename is mandatory — the page has no target, budget, margin or cost data.

**D5 — Operator leakage.** Remove / rename / keep, per control: see F7 and §6 W5.

**D6 — Login `Full Name`.** Minimum fix: not `required` for personal accounts, and never let the typed
value replace the authenticated session identity. *Risk:* shared cashier accounts legitimately use
this field (the name of the current holder) — the change must not break that workflow.

**D7 — Forecast scale.** No paging today. *Decision input:* is 454 rows a wall for the owner, or does
the activity checkbox already reduce it enough? Consider "showing X of Y" + a branch grouping before
adding pagination.

---

## 6. WORK ITEMS (ordered by value per unit of change)

Each item needs: criterion written and proven RED first, then implementation, then the criterion green.

**W1 — Forecast: one sort control inside the form** (fixes F1, F2, F3)
- Add `sort_dir` as a control in the existing filter form; **delete the two handcrafted links**.
- The form must submit: `sort_dir`, `product_id`, `empty_rows`, `target_date`, `window`, scope, branch,
  dates.
- Criterion: changing the sort preserves a selected product; pressing the form's submit does not reset
  the sort; no rendered control is background-less except Logout.

**✅ DONE 2026-10-12** — Sol lane `viewer-forecast-sort-control` (landed, acceptance=PASS, run
`20261012T095113-34759`). Criterion `tools/acceptance-forecast-sort-control.php` (7 RED → 0 RED, 7
checks on the rendered page as a viewer). Note: the older `tools/acceptance-forecast-activity-filter.php`
F5/F7 asserted the two REMOVED links (it had encoded the replaced mechanism) and went 2 RED; it was
re-anchored to pin the select basis and verified RED on the base tree (2 RED) / GREEN on the fix. The
link-only context keys (`empty_rows_param`, `view_scope`, `date_from`, `date_to`) were proven unread
and removed from `handleAdminForecast`.

**W2 — Remove the duplicate sorting mechanisms on Products Overview** (F4)
- Delete the prose "Build it from the least instead" link and the clickable "Cumulative share" heading.
- Keep `Rank by` + `Order` only; explain the cumulative column in text beneath it.
- Criterion: exactly two sorting controls render; `sort_by=cumulative` is never emitted.

**✅ DONE 2026-10-12 (W2 · W7 · W8)** — Sol lane `viewer-products-pe-controls` (landed, acceptance=PASS,
run `20261012T101021-50382`). Criterion `tools/acceptance-viewer-products-pe-controls.php` (3 RED → 0
RED; regression pins kept: the cumulative explainer stays, Movement still round-trips through Apply).
Landed: both `sort_by=cumulative` emissions gone (prose link deleted, header de-linked with a reworded
non-interactive tooltip); `include pending rows` no longer named in the empty state; PE's Movement
select no longer auto-submits. Regressions: products-overview acceptance 0 RED, production-efficiency
acceptance 0 RED, efficiency-standards 0 RED, lint 703/703.

**W3 — Business Health: stop the dead-end and the wrong-cause link** (F5, F6)
- Remove or relocate `Detailed sales analysis` (label it `View sales for selected period` if kept).
- Point the "inspect" action at the cause the verdict actually names (pending → data-integrity,
  variance → variances, closure → branch summary).
- Criterion: for each verdict cause, the action's destination matches that cause.

**W4 — Read-only phrasing for operator instructions** (F7, D5)
- Replace "Resolve/Complete/Finalize…" CTAs with read-only equivalents ("View exceptions",
  "Pending endings at {branch}", "View supporting report").
- Remove duplicate links to the same report.
- Criterion: no viewer-visible control text instructs the owner to perform an operational action.

**W5 — Rename the verdict** (F11, D4) — pending owner confirmation.

**W6 — Carry context in the forecast link** (F10)
- `Detailed forecast` must pass scope/branch and derive `target_date` from the selected period end.
- Criterion: following the link produces a forecast scoped to the branch under review.

**✅ DONE 2026-10-12 (W3 · W4 · W5 · W6 · W15 core)** — Sol lane `viewer-overview-honesty` (landed,
acceptance=PASS, run `20261012T100435-44837`). Criterion `tools/acceptance-viewer-overview-honesty.php`
(8 RED → 0 RED, 8 checks incl. every check listed under W3-W6). Landed: section 2 renamed **Ledger
Control Status**; verdict vocabulary → Complete / Attention required / Incomplete / Unknown with a
coverage citation in the card; verdict action now cause-routed (closure → branch-summary, pending →
data-integrity, variance → variances) labelled `View branch details`; section 5 reads
`Issues affecting ledger completion` with descriptive body and the blanket `Variance report` button
dropped; `View sales report` moved to section 5 carrying the page period; `View production forecast`
carries scope/branch/target_date; `N of M branches recorded activity` + completeness qualifier on the
Branches-in-scope card; opportunity titles descriptive. Regression evidence: all-periods acceptance
0 RED, overview suite 106/106, DiSyL lint 703/703.

**W7 — Products Overview empty-state copy** (F8) — remove "or include pending rows".

**W8 — Production Efficiency: single apply** (F9) — remove `onchange="this.form.submit()"`.

**W9 — Login `Full Name`** (F12, D6) — stop requiring it for personal accounts; do not let it replace
the session identity. **Do not break the shared-cashier name-capture workflow.**

**W10 — Branch link affordance + silent-branch weight** (F13, F14) — make drill-downs visibly
clickable; collapse the silent-branch list behind a count.

**✅ DONE 2026-10-12 (W10)** — Sol lane `viewer-branch-affordance` (landed, acceptance=PASS, run
verified). All branch drill-downs (chart bars, working cards, silent cards) now carry
`underline decoration-dotted underline-offset-2 hover:text-indigo-900`; the silent list renders
inside a closed native `<details>` whose summary states "N branches with no recorded activity",
every silent branch link stays inside the collapse, and the zero case keeps the existing message.
Battery: affordance oracle 0 RED; business_health_view_test 15/15; overview suite 106/106;
overview-honesty oracle 0 RED; DiSyL lint 703/703.

**W11 — Report archive** (F15) — verify first; then either add a governed open/download control or
label it explicitly as an audit register.

**✅ DONE 2026-10-12 (W11 + W17)** — Sol lane `viewer-reports-archive` (landed, acceptance=PASS).
Verified truth: the archived bytes ARE retained (`ReportManager::archiveReport()` copies to
`storage/report-archive/<id>.<format>`; the module's `@unlink` is only the audit-rollback path), so
the missing action was a missing governed control, not missing data. Landed: a new GET route
`/daily-ledger/admin/reports/archive-download` + handler with endpoint authorization (viewer-level
`dlRequireAuth`; anonymous -> 401; disallowed role -> 403), `rpt_` id allowlist, the existing tenant
predicate `dl_reportArchiveVisibleToTenant`, `ReportManager::canExport` gate, realpath containment
inside `storage/report-archive`, a sanitized Content-Disposition filename, and a
`report_archive_download` audit row written before streaming; plus a per-row **Download** control in
the archive table. Criterion `tools/acceptance-viewer-reports-archive.php` (3 RED -> 0 RED) proves
end to end: viewer HTTP export succeeds AND archives (P1), the control appears (P2), the download
serves IDENTICAL bytes (P3), anonymous is refused 401/403 (P4).

**W12 — Forecast scale** (F17) — only if D7 says it is needed. **Superseded by W13 ✅ (2026-10-12):**
D7 said it was needed and W13 delivered it (bounded pages + total statement).

---

## 7. CHAIR CONSULT — LANDED

Session `viewer-surface-2026-10-12`, reply at `/tmp/chair-viewer-ui-reply.md` (818 lines). Its
rulings are in **§11** and supersede the open questions in §5 where they overlap. The chair flagged its
own evidence limit (it received no `path:line` citations), so its repository claims still need
verification — the ones that mattered I checked, and Sol's were verified in §4.

**Re-dispatch if the file is missing:**
```bash
cd /var/www/html/applicationostest
setsid nohup python3 tools/harpp-bridge/chair_consult.py \
  --session viewer-surface-2026-10-12 --out /tmp/chair-viewer-ui-reply.md \
  --query "$(cat /tmp/chair-viewer-ui.md)" >/tmp/chair-viewer-ui-run.log 2>&1 </dev/null &
```

---

## 8. VERIFICATION PROTOCOL

```bash
cd /var/www/html/applicationostest
php _lint_disyl.php 2>&1 | tail -1                 # must stay 703/703 (or count + N new)
for f in tools/acceptance-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL $f"; done
for t in daily_ledger_overview_test daily_ledger_forecast_test daily_ledger_reporting_test; do
  php tests/daily-ledger/$t.php >/dev/null 2>&1 || echo "FAIL $t"; done
: > storage/logs/app.log; : > storage/logs/error.log   # then re-run; error.log must stay EMPTY
```

**Environment trap (cost me time today):** `storage/cache/compiled` can be owned by `www-data` and
unwritable by the CLI user, which aborts **every** suite with "Failed to write compiled template
cache". Fix without sudo — renaming only needs write on the parent:
`mv storage/cache/compiled storage/cache/compiled.foreign-owned.bak`

**Browser check (the only real proof for this work):** log in as `admin_view` on `baronledger.test`
and re-run the §3 inventory snippet on each route. Assertions about templates are not assertions about
what renders.

---

## 9. DEFINITION OF DONE

1. No control on any viewer page renders with no background except Logout, and every such control has
   a stated purpose, an emitted-data description and a verdict recorded here.
2. One apply model per page (no mixed auto-submit + Apply).
3. No viewer-visible text instructs the owner to perform an operational action.
4. Every action's destination matches the cause it claims to address.
5. All criteria green, DiSyL lint unchanged, `error.log` empty after a full re-run, browser inventory
   re-measured and pasted into this file.

---

## 10. DELIBERATELY EXCLUDED / NEEDS THE OWNER

- **An owner-level financial surface** (targets vs actual, margin, cost, cash position) — the module
  holds no cost or budget data (0 of 3,209 delivery items and 0 of 2,433 receiving items carry a
  `unit_cost_snapshot`), so this is a data gap, not a UI gap.
- **Export policy** (D3) — a contract decision, not a code preference.
- **Renaming "Business Health"** (D4) — owner-facing vocabulary.

---

## 11. CHAIR RULINGS (session `viewer-surface-2026-10-12`)

> The chair's own framing, which is the standard to build against: **"Every viewer control must have a
> defensible purpose, a predictable state transition, and an observable result. If it cannot satisfy all
> three, remove it."** And its diagnosis of the root cause: *"The central architectural problem is not
> that some buttons lack background colors. It is that the UI sometimes promises an action, comparison,
> or diagnosis that the underlying data or navigation does not deliver."*

### D1/D2 — Sort pattern: AGREE WITH SOL, and one failure mode he missed
Use the form: one `<select>` for direction, the activity checkbox, and ONE apply button. The checkbox
must **not** auto-submit.

The chair's added failure mode — **worth a criterion**: if one control submits immediately while another
control has an unapplied change, the immediate submit **silently applies the pending change too**, and
the operator experiences an apparently unrelated result.

Required state contract (now **W14**): every apply must preserve or explicitly update `product_id`,
`sort_by`, `sort_dir`, `active_only`; the server must validate both sort params against **allowlists**
with deterministic defaults, echo the selected values back into the form, and use a **stable secondary
tie-break** (e.g. product id) so equal rows cannot jump between requests. Rename the submit button
`Recalculate` → **`Apply filters`** unless it genuinely recomputes or persists something.

### D3 — Read-only: REFINES Sol (this is a contract ruling, not a preference)
The boundary is **business-state mutation, not SQL write count.** A viewer may cause technical writes
without acquiring any operational privilege:

| Category | Viewer policy |
|---|---|
| business ledger / production / stock / delivery / verification writes | **forbidden** |
| variance resolution and operational status | **forbidden** |
| access + audit logging | **allowed** |
| session and cache maintenance | **allowed** |
| report-export tracking | **conditionally allowed** |
| persistent report creation in a **shared archive** | **requires explicit separate authorization** |

Its sharpest point: *"An audit row documenting a download is compatible with read-only access. However,
creating an archived business report is not automatically equivalent to reading one"* — if the archive is
shared state, a viewer's export **modifies a resource other users see**, which deserves its own
capability (`reports.view` → `reports.export` → `reports.archive.create`, conceptual names). Also:
**"Hiding write controls is not authorization"** — verify the export endpoint authorizes the viewer
itself, not just that the button renders.

### D4 — Rename the verdict: **"Ledger Control Status"**
Keep the page name if it carries sales metrics; rename the **verdict section**. Explicitly forbidden
words: Business Health, Financial Health, Profitability, Business Performance Score, Operational
Efficiency. The reason is the contract the owner already demands: *"A completed ledger can describe a
financially struggling business. An incomplete ledger can belong to a commercially successful one.
Neither condition can be inferred from the other."*

### D4b — The statistics-that-do-not-lie contract (NEW REQUIREMENT)
Every metric must carry a defined **time range, population, numerator, denominator, and
data-completeness status.** Measured example to fix: with **22 branches and only 2 recording official
sales**, an aggregate headline implies coverage it does not have. Required shape:

> Recorded sales — ₱X · Period: <range> · **2 of 22 branches have recorded sales · completeness not
> established.**

And: **do not treat missing records as zero**, and do not imply the other 20 branches failed to operate —
they may be inactive, closed, genuinely zero, or unreported. Verdict states must distinguish
**Complete / Attention required / Incomplete / Unknown-not-assessed**, with no green overall verdict
while coverage is unestablished.

### D5 — Operator instructions: RENAME, don't just delete
The distinction: *"an instruction to operate"* vs *"a description of an unresolved operational
condition"*. An owner legitimately needs to know 14 endings are pending and which branches own them.

| Current | Action | Replacement |
|---|---|---|
| `Resolve what blocks the verdict` | rename | `Issues affecting ledger completion` |
| `Complete pending endings` | rename | `X branch endings awaiting completion` |
| `Finalize pending endings…` | rename | `X endings awaiting staff finalization` |
| `Include pending rows` | **remove** | no replacement (no such control) |
| `Inspect` | rename + fix route | `View branch details`, linked to the relevant evidence |
| `Recalculate` | rename | `Apply filters` |
| `Build it from the least instead` | **remove** | Rank-by + Order suffice |
| `Detailed sales analysis` | keep, fix period | `View sales report` |
| `Detailed forecast` | keep, fix context | `View production forecast` |

On `Inspect`: *"A variance report cannot adequately explain a pending closure."* Either route to the real
cause or offer a neutral branch-detail view; **until that target exists, remove the link rather than send
every problem to Variances.**

### D7 — The 426 KB page is the bigger problem
> *"454 default rows in 426 KB is a more serious usability problem than the unfilled sort link."*

Add a result count and pagination or an explicit "show more". **Apply filters and sort BEFORE pagination**
(never paginate first and sort each page), and never sort client-side over only the visible page.
→ **W13**.

### The defect both reviewers missed: context preservation is a product-wide contract
Isolated findings F1/F10 are symptoms. The real gap is that **nothing defines which viewing context a
drill-down must carry**. Establish the contract — `period + branch/area scope + product scope +
applicable sort/filter state` — and make every navigational link deliberately **preserve, translate, or
discard** each dimension, stating which rather than silently pretending the destination supports it.
→ **W16**.

### What the module CAN and CANNOT honestly compute (chair, with its own caveats)
Potentially computable: branch sales contribution + coverage; production-vs-sales alignment (produced /
dispatched / sold / remaining); waste units and rate with a declared denominator; delivery
acknowledgement + receipt latency; product sales velocity and unsold-balance trend; run-level actual
yield vs expected.

**NOT computable from these tables — do not present as established facts:** net profit, gross margin or
EBITDA without trustworthy cost data; cash flow or bank reconciliation; customer conversion / repeat /
basket size; **demand lost through stockouts** without lost-demand evidence; true inventory valuation;
business-wide sales completeness without operating expectations; and **forecast accuracy unless
predictions were retained before the outcome** — *"comparing today's regenerated forecast with historical
sales is not historical forecast accuracy."*

⚠️ Chair caveats to honour: do not subtract sales from production across commissary/branch/consignee —
**transfers are not sales** and double counting is a real risk; a waste rate needs one declared
denominator (and production-stage vs branch-stage waste are different things); sales coverage must
distinguish **missing reporting from a measured zero**.

---

## 12. WORK ITEMS ADDED BY THE RULINGS

**W13 — Result count + paging on the forecast** (D7). Count first, then page; sort/filter before paging.
Criterion: the page states "showing X of Y"; page size is bounded; changing page preserves filters+sort.

**✅ DONE 2026-10-12 (W13)** — Sol lane `viewer-forecast-paging` (landed, acceptance=PASS, run
`20261012T101635-56153`). Criterion `tools/acceptance-forecast-paging.php` (5 RED → 0 RED): page size
50 (`DL_FORECAST_PAGE_SIZE`), one `http_build_query` pager generator carrying the full state
(scope/branch/product/empty_rows/target/window/dates/sort/page), `page` allowlisted with clamp, and
"Showing X–Y of Z results" + total in the card header. `dl_forecastRows()` kept its full-list
contract (Overview aggregation untouched). Two oracles had pinned the unpaginated rendering and were
re-anchored after the lane, each proven RED on the pre-paging tree:
`acceptance-forecast-sort-control.php` (A3 now targets the removed sort-toggle links only - pager
links legitimately carry sort state - and A6 now checks every rendered row is the selected product
plus the stated total vs the helper count) and `acceptance-forecast-activity-filter.php` (F7 now
compares the page's STATED totals: 453 default vs 788 with the activity filter off). A pager-href
HTML-entity workaround that had only existed to satisfy A3's letter was removed with it.

**W14 — Sort/filter state contract** (D1/D2). Allowlisted `sort_by`/`sort_dir` with deterministic
defaults, echoed form state, stable secondary tie-break, and no control that submits while another has
an unapplied change. Criterion: submit the form from every state and assert the resulting URL/state.

**W15 — Coverage and completeness beside every aggregate** (D4b). Criterion: the sales headline names how
many branches recorded, and no verdict renders "healthy" while coverage is unestablished.

**W16 — Context-preservation contract for navigation** (both reviewers). Criterion: for each drill-down
link, the destination receives the dimensions it can honour, and the ones it cannot are named in the UI.

**✅ Contract delivered 2026-10-12 (implemented where it binds, stated here where it is policy):**
The viewing context is `period + branch/area scope + product scope + sort/filter state`. Where a
destination can honour a dimension it MUST receive it: `View production forecast` carries
scope/branch/target_date (W6); the forecast pager carries the entire state and re-rendering a
followed pager link is asserted to land on the right slice (W13); every branch drill-down keeps its
date range (W10/B3). Where it cannot, the UI names it rather than implying support (the aggregate
forecast section states its own window; item-level detail is stated to live on Forecast/Reports).

**W17 — Export authorization + archive policy** (D3). Verify the export endpoint authorizes the viewer
(not just hides the button); then either split archive-creation from export or document that export is a
permitted viewer operation with its persistence stated.

**✅ DONE 2026-10-12 (with W11).** Verified at the endpoint, not by button-hiding: the export route
admits the viewer (`dlRequireAuth` includes viewer) and `ReportManager::canExport` gates generation;
the criterion proves it end to end (P1: HTTP 200 + archived row). Persistence is stated where the
viewer exports (a note beside the Download controls: each download is archived - listed in Recent
Archived Reports - and audited). **The capability SPLIT (reports.export / reports.archive.create)
remains OWNER-FACING** per §10 and §11 D3: a viewer's export writes into SHARED state (the archive
others see), so a separate authorization for persistent archive creation is a contract decision, not
a code preference. Until that decision the behaviour is: viewer export permitted, always audited,
always disclosed.
