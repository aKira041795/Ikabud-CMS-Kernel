# Daily Ledger Movement Scenarios

This document turns the current Daily Ledger design into concrete movement stories.
It is meant to answer one practical question: when stock moves, which document owns the movement, which ledger receives the quantity, and which price snapshot is used for value reporting?

## 1. Core Rules

1. **Usage and commissary are not the same thing.**
   Usage records production facts such as yield, ingredients, eggs, and kilos. Commissary owns finished-goods custody and dispatch.
2. **A branch-directed commissary output with formal workflow enabled must carry a DR number.**
   The DR is the handoff key that ties the production run, delivery, receiving, and activity trace together.
3. **Branch stock should enter through the receiving boundary when the formal workflow is enabled.**
   The branch does not get stock just because commissary logged output. It gets stock when the branch posts the receiving.
4. **Pricing and quantity movement are related but not identical.**
   Quantity lands in the destination ledger. Sales value later uses the destination ledger's price snapshot rules.

## 2. Movement Matrix

| Scenario | When to use it | Quantity lands in | DR required | Pricing owner |
| --- | --- | --- | --- | --- |
| Commissary to branch | Commissary produced goods for a branch | `dl_daily_ledger.addtl` after branch receiving | Yes, when formal workflow is enabled and destination branch is set | Branch regular ledger price snapshot |
| Standalone branch | Branch is self-managed and owns its own stock | `dl_daily_ledger.addtl` in that branch | No commissary DR | Branch regular ledger price snapshot |
| Regular pricing | Branch sells through its normal cashier ledger | `dl_daily_ledger` | Depends on how stock arrived | Branch price group or default price |
| Mall pricing | Stock is sent to a selling account such as a kiosk or mall cart | `dl_selling_account_ledger.delivered_qty` | Use delivery document; no branch receiving step for selling account posting | Selling account price group |

## 3. Sample Flow: Branch X Receives Production Delivery With DR

Example:

- Commissary produces 120 Pandesal for Branch X on `2026-05-03`
- DR number: `DR-COM-2026-05-03-001`
- Branch X is a normal branch ledger, not a selling account
- Formal Delivery Workflow is enabled

### Step 1. Usage records the production fact

The commissary user saves a production run in **Usage** with:

- `ledger_date = 2026-05-03`
- `product = Pandesal`
- `yield_qty = 120`
- `destination_branch_id = Branch X`
- `dr_number = DR-COM-2026-05-03-001`

What the system records:

- `dl_production_runs` keeps the production fact row
- ingredient and yield trace stay in the usage side of the model
- the DR becomes part of the production trace

### Step 2. Commissary sync creates the pending delivery

Because the run is branch-directed and formal workflow is enabled, the system groups matching run rows by:

- delivery date
- destination branch
- DR number

From that group it creates or updates:

- `dl_deliveries`
- `dl_delivery_items`

Important boundary:

- Branch X stock is **not** increased yet
- the delivery is the commissary-side custody document

### Step 3. Branch X sees the incoming delivery

In the cashier receive modal, Branch X sees a pending incoming group for:

- origin: Commissary
- DR: `DR-COM-2026-05-03-001`
- date: `2026-05-03`
- item: Pandesal `120`

At this point the delivery is traceable but still unreceived.

### Step 4. Branch X posts the receiving

When the cashier accepts the pending delivery, the system creates:

- `dl_branch_receivings`
- `dl_branch_receiving_items`

Then it applies the quantity into Branch X regular ledger:

- `dl_daily_ledger.addtl += 120`

This is the moment Branch X officially owns the stock.

### Step 5. Branch X sells from its regular ledger

Later in the day, cashier activity updates:

- `beg_bal`
- `withdraw`
- `bal_end`

The regular sales formula is:

```text
sales = beg_bal + addtl - withdraw - bal_end
sales_value = sales x price_snapshot
```

The `price_snapshot` used here comes from the branch pricing resolver, not from the commissary usage row itself.

### Traceability for this example

The same DR can be followed across:

1. `dl_production_runs.dr_number`
2. `dl_deliveries.dr_number`
3. `dl_branch_receivings.dr_number`
4. activity audit filters using the DR value

## 4. Scenario A: From Commissary

Use this when a branch is supplied by commissary and the stock physically moves from commissary custody to a branch.

### Recommended flow

1. Commissary records the run in **Usage**.
2. Destination branch is set.
3. DR number is entered.
4. System syncs the run into a posted commissary-origin delivery.
5. Branch receives the delivery.
6. Quantity lands in `dl_daily_ledger.addtl` for that branch.

### What this solves

- production trace stays in Usage
- finished-goods dispatch stays in Commissary and Deliveries
- branch stock is not inflated before receipt
- DR ties the whole handoff together

### Tables involved

- `dl_production_runs`
- `dl_deliveries`
- `dl_delivery_items`
- `dl_branch_receivings`
- `dl_branch_receiving_items`
- `dl_daily_ledger`

## 5. Scenario B: Standalone

Use this when the branch is self-managed and does not rely on commissary for that stock.

Example:

- Branch Y bakes its own Spanish Bread
- no commissary is involved
- there is no inter-location handoff document

### Movement rule

Stock is owned by the branch from the start, so there is no commissary delivery boundary to cross.

### Recommended handling

1. Keep the branch configured as `self_managed` for that stock path.
2. Encode the branch-owned quantity directly into the branch ledger flow that owns local stock.
3. Use the branch regular ledger for sales and ending balance.

### What does not happen here

- no commissary-origin delivery
- no branch receiving against commissary
- no DR required just to move stock inside the same branch-owned flow

### Pricing effect

The branch regular ledger still uses the branch's resolved price snapshot for value reporting.

## 6. Scenario C: Regular Pricing

Use this when the destination is the branch's normal cashier ledger.

Example:

- Branch X receives 120 Pandesal
- Branch X is mapped to the `Default` price group
- Pandesal default price on `2026-05-03` is `3.00`

### Quantity movement

- quantity lands in `dl_daily_ledger.addtl`
- cashier sales for the day are computed from the regular branch ledger

### Price movement

When the branch ledger row is created or updated, the price snapshot is resolved for the branch.

Result:

- `sales_value = sold_qty x 3.00`

### Interpretation

Regular pricing is a branch ledger concern.
It does not change the physical movement path.
The stock may arrive from commissary or from a self-managed branch flow, but once it is in the regular ledger, the branch's regular price rules own the valuation.

## 7. Scenario D: Mall Pricing

Use this when stock is meant for a selling account such as a mall kiosk, event booth, or cart rather than the branch's regular cashier ledger.

Example:

- Branch X has a selling account named `Mall Kiosk`
- `Mall Kiosk` is assigned the `Mall Kiosk` price group
- Pandesal mall price on `2026-05-03` is `4.00`
- 80 pieces are delivered to that selling account

### Movement rule

This is not a regular branch-ledger sale path.
The destination is the selling account.

### Recommended flow

1. Create a delivery whose destination type is `selling_account`.
2. Post the delivery.
3. The system writes delivered quantity into `dl_selling_account_ledger.delivered_qty`.
4. Kiosk operations then maintain `beg_qty`, `return_qty`, and `end_qty` on the selling-account ledger.

### Sales formula

```text
sold_qty = beg_qty + delivered_qty - return_qty - end_qty
gross_amount = sold_qty x price_snapshot
```

### Pricing effect

The price snapshot is resolved from the selling account's assigned price group.

Result:

- regular branch ledger can stay at `3.00`
- mall kiosk selling account can value the same product at `4.00`

That is the main reason to treat mall pricing as a selling-account flow instead of forcing it through the branch's regular ledger.

## 8. Practical Decision Guide

Use **commissary to branch** when custody changes between locations and you need DR-backed receiving.

Use **standalone** when the branch already owns the stock and no inter-location handoff exists.

Use **regular pricing** when stock is meant for the branch's normal cashier ledger.

Use **mall pricing** when stock is meant for a selling account with its own price group and separate sales accountability.

## 9. Anti-Patterns To Avoid

1. Do not let commissary output increase branch stock immediately when the formal delivery workflow is enabled.
2. Do not mix usage facts with branch receiving facts in the same operational step.
3. Do not use the branch regular ledger for mall-kiosk pricing if the kiosk should report separately.
4. Do not treat DR as optional for branch-directed commissary output when the formal workflow is on.