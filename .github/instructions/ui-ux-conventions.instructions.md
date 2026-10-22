---
description: "Owner UI/UX principles for Ikabud templates. Alignment grids, one row per real record, compact-but-complete density, action placement, no bulk money/stock actions, colour from the design system, honest labels, and visibility of every record that exists. Each rule carries the measured trap that produced it."
applyTo: "**/*.disyl"
---
# UI/UX CONVENTIONS — owner principles

These are the owner's standing rules, learned from direct correction. They are not
stylistic preferences to weigh against other options; they are the acceptance bar.

Every rule below ends with **how to verify it**. Verify by measurement, never by
eyeballing a screenshot: this repository has produced four "looks aligned" claims that
were 4px, 9px and 22px wrong.

---

## 1. Alignment is a grid, not an approximation

A row of controls aligns on **one horizontal line**, and repeated columns align on
**one vertical edge**.

- Within a row: labels share a top edge, and every input, dropdown **and button** shares
  the same top and the same height. A button is not a special case.
- Across repeated data rows: the same input must land on the same x on every line. A
  column that drifts by the width of its own suffix is not a column.

**How to verify:** `getBoundingClientRect()` every element in the group. All labels must
report one distinct `top`; all controls one distinct `top`; all heights identical. Across
rows, all inputs one distinct `left` and one distinct `right`.

### Measured traps
- `.form-input` carries `margin-top: .25rem` **and** `height: 2.5rem`. `.btn` carries
  neither. A button placed beside inputs renders 4px high and 3px short unless it is
  given `.form-input`'s metrics or given a matching label spacer.
- `align-items: flex-end` on a grid of columns with different content heights produces a
  staircase of control tops (measured: 121 / 153 / 189 / 192). Use `flex-start` and make
  the columns structurally identical.
- `justify-end` plus a suffix whose width varies ("of 5" vs "of 300") pushes a
  right-aligned input left and right per line. Give the suffix a fixed width and
  `tabular-nums`.

---

## 2. One visual row per real record

A record is one row. Its lines are **nested inside** that row, never exploded into rows
that repeat the record's identity.

A DR with 26 products is **one row with 26 lines**, not 26 rows with the same DR number.
Repeating the identity floods the table and forces one click per line.

**How to verify:** group the underlying rows by the record's real key (document number +
date + origin), then assert group count ≪ row count on real data. Measured here: 219
delivery rows → 32 DR groups.

### Measured trap
Grouping keyed on the child id (`'delivery:' + delivery_id`) looks correct in code and is
wrong in data: a sheet entry writes one delivery row **per product**, so every product
showed up as its own row. Group on the identity the operator reads, and make the record's
action cover every child in the group.

---

## 3. Compact, complete, searchable — in that order of sacrifice

Reduce vertical scroll, but **never** by dropping data. If a field exists, it stays.

- Prefer a dense table over tall cards for anything with more than ~10 records.
- Give the user a scope control (today / range / all) rather than a wall of history.
- Search stays, and it matches what the operator physically holds — the document number.

**How to verify:** count visible fields before and after a density change; they must be
equal. Then measure total scroll height and row height.

### Measured win
Widening a modal from 768px → 1024px removed a horizontal scrollbar **and** dropped row
height 103px → 83px, because labels stopped wrapping. Widening is usually the cheaper
density fix.

---

## 4. No horizontal scroll — widen the container

Content that does not fit means the container is too narrow, not that the content should
be squeezed. Widen the modal/table, then re-measure.

**How to verify:** `scrollWidth > clientWidth + 1` on the scroll container must be false.

---

## 5. Actions live where the work is

A trigger belongs beside the thing it acts on, at the top of the surface —
not in a block below the content it creates. Related actions are grouped so a wrap can
never separate them.

### Measured trap
Placing a trigger beside "Print Daily Sheet" in a flex bar let the bar wrap and drop Print
alone onto the next row: adjacent in the DOM, never adjacent on screen. Group such a pair
as **one flex item** so they move together.

---

## 6. Never bulk-post money or stock

Each record is completed by its **own** action, on its own click. No "receive all",
no mass accept. Financial and stock rows must be individually confirmed.

Bulk operations are allowed only for **flags/settings**, and only with explicit scope:
`All` vs `Selected` — never an implicit "everything".

**How to verify:** the endpoint that posts stock/money accepts one record per request;
the UI has no control that loops.

---

## 7. Colour follows the design system; never invent it

Find the existing convention before styling anything. If the documentation is
incomplete, **fix the documentation** — do not guess and do not add a new token.

For daily-ledger: `btn-primary` = confirm/create action · `btn-accent` = every other
action including modal Close/Cancel · `btn-outline` = tertiary section control ·
`btn-danger` = destructive.

**How to verify:** read the design-system note first, then confirm against two existing
views before writing a class.

---

## 8. Labels state the real meaning

A control whose name is the opposite of its behaviour will be implemented backwards. Name
the switch for what turning it ON does, and say what stays always available.

**How to verify:** read the label alone, without the code, and ask what a new operator
would expect it to do. If the answer differs from the behaviour, the label is the defect.

---

## 9. Nothing that exists may be silently hidden

If a record exists, the operator can see it. A filter that omits real data must say so,
with counts — never quietly show a subset.

**How to verify:** for any "list" surface, compare rendered count against a direct DB
count for the same scope. A mismatch must produce a visible message, not an empty list.

### Measured trap
A list built from a cumulative-balance filter dropped a product that still had stock,
because one historical day went negative. The quantity existed on the sheet and the modal
showed nothing at all. Derive visibility from the record's existence, not from a derived
total.

---

## 10. Scope is resolved from the record, not from the request

The branch/consignee selected decides its commissary; the value is looked up server-side
and never taken from the client. A destination that is not assigned to the chosen source
is an invalid state and must be refused, not rendered.

**How to verify:** the handler resolves the parent from the child's own foreign key; a
forged parent id changes nothing.

---

## 11. Visual guides for long rows

When a row is tall enough that the eye must travel across columns, add a repeating guide:
alternating shades, a baseline rule, or a shared band. The shading index must come from
the **same loop array** in every cell so a name and its field can never disagree.

**How to verify:** every banded line's paired cell reports the identical background, and
the gap between the two cells' bands is 0px so they read as one stripe.

---

## Definition of done for any UI change

1. Measured: alignment distinct-value counts as above.
2. Measured: no horizontal overflow.
3. Counted: fields before == fields after.
4. Verified in a real browser at real data volume — not a stub fixture of 3 rows.
5. Verified in the role that actually uses the screen, not only as admin.
