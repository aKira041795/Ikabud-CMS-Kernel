# Case Study: Baron Bakeshop — Daily Ledger

## Organization

Baron Bakeshop is a multi-branch bakery operation with a central commissary
and multiple retail branches. Each branch tracks daily production, deliveries,
sales, cash deposits, and inventory variances.

## The problem

Before the Daily Ledger system, each branch recorded daily operations on paper:

- Branch cashiers maintained handwritten sales sheets
- The commissary tracked production output on separate paper forms
- End-of-day reconciliation required manual comparison of branch sheets
  against commissary delivery records
- Variance detection was reactive — discrepancies were noticed days or weeks
  after they occurred
- Owners had no real-time visibility into branch performance

The manual process was slow, error-prone, and made it difficult to scale to
additional branches.

## The solution

Baron Bakeshop deployed the Ikabud Daily Ledger module, a tenant-scoped
operational system running under the Kernel OS. Each branch has its own daily
ledger within the same tenant, with role-based access controls.

### Key features deployed

- **5 user roles** — Cashier (single-branch), Production In-charge
  (commissary), Supervisor (multi-branch view), Administrator (full access),
  Owner (read-only oversight)
- **Daily sales entry** — Beginning inventory, deliveries, ending inventory,
  actual sales and expenses
- **Auto-save** — Values persist to the database as the user types
- **Variance tracking** — Automatic calculation of over/short variances
- **Production output flow** — Commissary records production by branch,
  linked to delivery receipt numbers
- **Paper DR capture** — Branches can receive stock against paper delivery
  receipts when the source hasn't encoded the dispatch yet
- **Offline Android app** — Cashiers can continue working without internet,
  sync when connection returns
- **Reports and exports** — CSV and PDF export for each ledger period
- **Audit trail** — Every change logged via `kernel.audit.record@1`

## Implementation

The deployment followed an incremental rollout:

1. **Single branch pilot** — One branch ran the digital ledger alongside
   paper records for 4 weeks
2. **Correction and hardening** — Auto-save behavior, variance calculation,
   and offline sync were refined based on cashier feedback
3. **Multi-branch rollout** — All branches adopted the system over 6 weeks
4. **Commissary integration** — Production output tracking and delivery
   handoff were added after branches were stable

The module evolved through **114 commits** over approximately 18 months of
active development. It is tested by **~15 test files** covering ledger entry,
variance calculation, offline sync, role-based access, and export formats.

## Measurable results

| Metric | Before | After |
|---|---|---|
| Daily reconciliation time | 30–45 min per branch | 5–10 min per branch |
| Variance detection | 2–5 days after occurrence | Same-day automatic |
| Cross-branch visibility | None (paper silos) | Real-time dashboard |
| Report generation | Manual (hours) | One-click CSV/PDF |
| Data loss risk | High (paper) | Low (database + backups) |
| Active branches | 3 | 5+ |

## Lessons learned

1. **Auto-save was the most appreciated feature.** Cashiers did not need to
   remember to click "Save" — data persisted as they worked.
2. **Paper DR capture was essential.** Branches sometimes received stock before
   the commissary encoded it. The system needed to accommodate real-world
   workflows, not ideal ones.
3. **Offline mode reduced resistance.** Cashiers who had experienced internet
   outages in the past were the strongest initial skeptics. Offline support
   converted them.
4. **Role-based permissions reduced training time.** Each role sees only what
   it needs. Cashiers are not distracted by administrative features.
5. **Incremental rollout built trust.** The single-branch pilot let users
   validate the system before the organization committed fully.

## Current status

**Active.** Baron Bakeshop runs the Daily Ledger on all branches and the
commissary. The system processes daily entries for **5+ locations** with
**15+ active users**. Ongoing development focuses on inventory specification
and movement tracking (see [inventory spec](../../docs/daily-ledger/inventory-spec.md)).

---

*Data sourced from CI tenant configuration, git history, and operational
documentation. User counts and branch numbers are approximate real-world
figures from the deployment.*
