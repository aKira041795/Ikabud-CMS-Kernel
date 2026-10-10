# Daily-ledger area rollout — plan (2026-10-10)

Client is deploying tablets to new areas. Requirement: Pagadian (commissary + supplied branches),
Molave (one branch, no commissary), Mahayag (one branch, no commissary); cashiers/production
assigned per area branch; admins keep global visibility but can scope their view by area; and
"data properly identified by area".

Reviewed with the ChatGPT chair (`--session dl-areas-rollout`, transcript `.ai/consult/`).

## 1. What already exists (measured, baronledger tenant)

`dl_branches` already carries the whole supply dimension:

| column | from | note |
|---|---|---|
| `area VARCHAR(100) NULL` | migration 009 | comment: "grouping branches by geographic area (e.g. Dipolog, Rizal, Pagadian)" |
| `default_supply_mode` ENUM(`commissary_supplied`,`self_managed`,`hybrid`) | 022 | |
| `assigned_commissary_id` (self-FK) | 022 | |
| `is_commissary` | 022 | |
| `price_group_id`, `sort_order` | 030, 065 | |

Users: `dl_users.role` (admin, supervisor, cashier, production_in_charge, + auditor, viewer) and
`dl_user_branches (user_id, branch_id)` — multi-branch binding already exists.

**Live data:** Dapitan 3 branches, Dipolog 9 (1 commissary RIZAL-COMMIS id 18), **Molave 1
(MOL-MOLAVE1 id 100015)**, **Pagadian 1 (PAG-COMMIS1 id 100016, is_commissary=1)**. Mahayag does not
exist. 35 cashiers all bound to exactly one branch; 27 admins all with ZERO bindings; **no user
straddles two areas.** Three synthetic area-NULL branches (99401-3 Overview Alpha/Beta/Gamma).

**So the model is ~80% there.** The work is identity, data, scoping and UI — not a new dimension.

## 2. Two findings that change the shape of the work

**(a) `area` is free text typed by hand** — no `dl_areas`, no FK, no canonical list, no validation.
The admin UI is a text input whose placeholder is literally "e.g. Dipolog, Rizal, Pagadian".

**(b) `default_supply_mode` is `self_managed` on EVERY branch**, including RIZAL-COMMIS and the eight
Dapitan/Dipolog branches that all carry `assigned_commissary_id = 18`. The two fields contradict each
other today, and nothing in this session has yet established which one the runtime actually obeys.

## 3. Chair verdict (decisions to implement)

1. **Canonical `dl_areas` table, numeric FK — GO.** Codes DIPOLOG, DAPITAN, PAGADIAN, MOLAVE,
   MAHAYAG; names editable; `is_active`, `sort_order`. **Do NOT put `commissary_id` on `dl_areas`** —
   one area may have zero or many suppliers. Backfill from distinct trimmed existing values, verify
   each nonempty historical value maps to exactly one ID, add `area_id` to `dl_branches` and
   `dl_consignees`, keep the legacy text columns during a compatibility phase, replace the text box
   with a picker, remove legacy columns only after all consumers migrate. **Do not convert NULL into
   an area** — the synthetic Overview branches stay unassigned.
   *Counter-argument the chair rejected:* a validated dropdown over the text column is simpler for a
   handful of areas, but treats a mutable label as an identifier forever.

2. **Outlet-level assignment is authoritative.** Keep `assigned_commissary_id` on branches AND on
   consignees; do not centralise it on the area. Area groups recipients for filtering/reporting;
   each outlet names its own supplier. **Cross-area supply is legitimate** — Dapitan branches are
   supplied by the Dipolog commissary, which proves area and supply topology are not equivalent.

3. **HOLD on fixing `default_supply_mode` — highest risk item.** Do not bulk-update the eight
   branches. A live mismatch may encode undocumented behaviour. First **trace every reader and
   writer** of `default_supply_mode`, `assigned_commissary_id` and `is_commissary` across production
   sheets, beginning balances, stock dispatch, stock receipt, branch product assignment, inventory
   calculation and Android/offline reconciliation; capture baselines; then test proposed reconciled
   records against the same fixtures. Target invariant: `self_managed` → NULL supplier;
   `commissary_supplied` → required supplier; `hybrid` → required for the commissary component. A
   commissary itself may be `self_managed` — it is a production source; do not confuse
   `is_commissary` with `commissary_supplied`.

4. **Admin view scope is first-class and session-persisted; authorization stays separate.**
   One resolver, e.g. `AdminAreaScope::resolve($request, $session, $user)`, with states
   `ALL | AREA(id) | COMMISSARY(id)`. Precedence: explicit validated parameter → session → ALL.
   Require an explicit ALL rather than treating a missing parameter as a reset. Every admin handler
   and export consumes the same resolved scope, and exports carry the same filter criteria as the
   displayed report. **Never derive cashier/production access from view scope** — their authorization
   remains their branch binding. Admins need an explicit global-scope grant, since they have no
   bindings. `COMMISSARY(id)` is a *supply-network* view (the commissary + its assigned recipients),
   which is deliberately not the same set as the area's branches.

5. **Five guards the chair added.** (i) never require area equality between branch and commissary;
   (ii) audit existing tablet/offline clients — area IDs, cached data, device access and
   reconciliation endpoints must not break or silently broaden; (iii) decide whether reassigning an
   area restates historical reports or preserves area-at-transaction-time; (iv) reconcile the legacy
   `dl_production_incharge_branches` against `dl_user_branches` before declaring the latter
   authoritative; (v) inventory every admin screen, aggregate, export and report and verify the same
   predicate is applied to the same dataset.

6. **Do NOT build now:** a separate area-role hierarchy, automatic reassignment by area, or a full
   area-settings subsystem. Keep the registry deliberately small.

## 4. Phases

| # | Phase | Deliverable | Gate |
|---|---|---|---|
| 0 | **Behaviour audit** | every reader/writer of the three supply fields, with production/delivery baselines | chair's HOLD is lifted only here |
| 1 | Canonical areas | `dl_areas` + `area_id` on branches/consignees + backfill + validation, legacy columns kept | each historical value maps to exactly one ID |
| 2 | New operational records | Mahayag branch; Pagadian recipient branches; keep existing Molave/Pagadian; no duplicate commissaries | real identities/codes confirmed |
| 3 | Staff assignment | cashiers + production bound per branch via `dl_user_branches`; legacy binding table reconciled | no user straddles areas unintentionally |
| 4 | Admin area scope | resolver + picker + scope indicators + exports carrying the same criteria | every admin screen/export inventoried |
| 5 | Verification | baselines vs unchanged data, then reconciled records vs same fixtures | no unexplained diff |

## 5. Blocking questions for the owner

1. **Pagadian's supplied branches** — how many, and what are their names/codes? (Only the commissary
   exists today.)
2. **Mahayag** — branch name, code, address; is it truly self-managed with no supplier?
3. **Molave** — the existing `MOL-MOLAVE1` (100015) is already the single branch; confirm it is the
   real Molave branch and not a placeholder.
4. **Users** — who are the cashiers and production staff for each new area? Or should staff be
   created later in the UI?
5. **Cross-area supply** — will any new branch be supplied by a commissary in a *different* area?
   (The chair allows it; the data may not need it.)
6. **The three synthetic Overview branches** (99401-3) — leave area-NULL, or hide from area scope?
7. **Does an area filter ever need to include consignees?** `COMMISSARY(id)` spans both branches and
   consignees, so the answer changes the resolver's dataset definition.

## 6. Verification approach

- Baselines captured against the **unchanged** data before touching supply fields.
- Every acceptance criterion measured against the current tree first; a criterion that already
  passes cannot discriminate the change.
- Admin scope: prove BOTH directions — that scoping filters correctly, and that it does **not**
  reduce a cashier's or production user's authorization.
