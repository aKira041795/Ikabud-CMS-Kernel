# Bakeshop Weekly Sheet Reconciliation

Updated: May 14, 2026
Tenant checked: `juliesmodule` (tenant `juliesbakeshop`, id `334`)
Sheet compared: attached `JBS MAPANG` 1-week consumption sheet
Scope included: live `bakeshop_products`, `bakeshop_ingredients`, `bakeshop_product_recipe`, `bakeshop_units`
Scope excluded: historical paper formulas not yet encoded into the tenant database

## 1. Executive Summary

The live tenant data is yield-complete but not sheet-complete.

- Product yield is not the current defect. All live products have `default_yield_qty` and `default_yield_unit_id` set.
- Product yield in pieces is intentional and should remain the operational basis for sales and inventory flows such as beginning, ending, add, and pullout.
- The weekly sheet is a separate planning/reporting layer. It should be derived from the existing piece-based product model, not replace it.
- The real blockers are master-data alignment and recipe coverage.

Current live counts:

- Products: `71`
- Ingredients: `30`
- Recipe lines: `32`
- Branches: `11`
- Products missing yield: `0`

Reconciliation verdict:

- Yield basis is ready.
- Product name alignment to the weekly sheet is partial.
- Ingredient name alignment to the weekly sheet is partial.
- Recipe coverage for sheet-aligned products is weak.
- Only one confidently sheet-aligned product is currently recipe-backed well enough to support ingredient explosion: `SPANISH` -> `SPANISH BREAD`.

## 2. Yield Rule (Must Preserve)

The current product yield basis is intentional:

- `default_yield_qty` is stored in pieces (`pc`) for the live tenant data checked here.
- That piece-based yield supports sales and inventory behavior.
- The weekly sheet's `Total Kilos` should be treated as a planning/output-weight layer on top of the existing piece-based product model.

This means future implementation should not convert or repurpose product yield away from pieces just to fit the paper sheet.

## 3. Product Reconciliation

### 3.1 Exact or Near-Exact Product Matches

These sheet products already exist in the tenant DB under the same or effectively the same product name.

| Sheet Product | DB Product | Yield | Unit | Recipe Lines | Status |
| --- | --- | ---: | --- | ---: | --- |
| AMERICAN LOAF | AMERICAN LOAF | 4.0000 | pc | 0 | Exact |
| CHEESE STRUESSEL | CHEESE STRUESSEL | 69.0000 | pc | 0 | Exact |
| COCO BUN | COCO BUN | 60.0000 | pc | 0 | Exact |
| EVERLASTING | EVERLASTING | 47.0000 | pc | 0 | Exact |
| KING ROLL | KING ROLL | 41.0000 | pc | 0 | Exact |
| MARBLE LOAF | MARBLE LOAF | 5.0000 | pc | 0 | Exact |
| MINI BUN | MINI BUN | 8.0000 | pc | 0 | Exact |
| MONAY SMALL | MONAY SMALL | 50.0000 | pc | 0 | Exact |
| PAN DE JULIA | PAN DE JULIA | 37.0000 | pc | 0 | Exact |
| SIOPAO | SIOPAO | 25.0000 | pc | 0 | Exact |
| SWEET ROLL | SWEET ROLL | 38.0000 | pc | 0 | Exact |
| CHOCO CUPCAKE | CHOCO CUPCAKE | 70.0000 | pc | 0 | Exact |
| PINEAPPLE PIE | PINEAPPLE PIE | 80.0000 | pc | 0 | Exact |
| POLVORON | POLVORON | 83.0000 | pc | 0 | Exact |
| SUNFLOWER | SUNFLOWER | 52.0000 | pc | 0 | Exact |
| SWEET HEART | SWEET HEART | 50.0000 | pc | 0 | Exact |
| UBE BAR | UBE BAR | 35.0000 | pc | 0 | Exact |

### 3.2 Alias Product Matches

These look like the same item under a different naming convention and should be formalized through an alias map before reporting is automated.

| Sheet Product | DB Product | Yield | Unit | Recipe Lines | Confidence |
| --- | --- | ---: | --- | ---: | --- |
| SIACOY | SIAKOY | 12.0000 | pc | 0 | High |
| SPANISH | SPANISH BREAD | 48.0000 | pc | 7 | High |
| FRUITCAKE | FRUIT CAKE | 69.0000 | pc | 0 | High |
| CHOCOLATE CRINKLES | CHOCO CRINKLES | 112.0000 | pc | 0 | High |
| HALFMOON CHOCO | CHOCO HALF MOON | 76.0000 | pc | 0 | High |
| MARBLE CUP | MARBLE CUPCAKE | 64.0000 | pc | 0 | High |
| VIOLET CREAM | V-CREAM | 10.0000 | pc | 0 | High |

The following is present in DB but should not be assumed to match without business confirmation:

| Sheet Product | DB Product | Yield | Unit | Recipe Lines | Note |
| --- | --- | ---: | --- | ---: | --- |
| ENSAY MONGGO | ENSAYMADA | 56.0000 | pc | 7 | Not safe to auto-map without confirmation |

### 3.3 Missing or Unresolved Products

These sheet products do not currently have a safe live DB match:

- BICHOYX
- BINANGKAL
- CHOCO FLOWER
- DOUGHNUT
- ENSAY MONGGO
- RAMONA
- SLICED BREAD REGULAR (10KLS)
- SLICED BREAD SPECIAL (10KLS)
- UBE FRIED
- CHOCOLATE SLICED/ROUND CAKE
- HALFMOON BUTTER
- TORTA PLAIN
- TORTA RAISINS
- UBE POCKET
- YOYO

## 4. Ingredient Reconciliation

### 4.1 Exact or Near-Exact Ingredient Matches

| Sheet Ingredient | DB Ingredient | Status |
| --- | --- | --- |
| BAKING POWDER | BAKING POWDER | Exact |
| BROWN SUGAR | BROWN SUGAR | Exact |
| BUTTER OIL | BUTTER OIL | Exact |
| CHEESE | CHEESE (PCS) | Alias |
| COCOA POWDER | COCOA POWDER | Exact |
| CORNSTARCH | CORNSTARCH | Exact |
| EGG PCS | EGG PCS | Exact |
| EL POPA | EL POPA (POWDERED SUGAR) | Alias |
| HARD FLOUR | HARD FLOUR (SACK) | Alias |
| JULIES BLEND | JULIE'S BLEND | Alias |
| LOAF ADDITIVE | LOAF ADDITIVE | Exact |
| MARGARINE | MARGARINE (20KG) | Alias |
| OIL | OIL | Exact |
| SALT | SALT | Exact |
| SHORTENING | SHORTENING (20KG) | Alias |
| SOFT FLOUR | SOFT FLOUR | Exact |
| VARIETY | VARIETY (FLOUR) | Alias |
| YEAST | YEAST | Exact |

### 4.2 High-Confidence Alias Ingredient Matches

| Sheet Ingredient | DB Ingredient | Confidence |
| --- | --- | --- |
| ALL PURPOSE | ALL PURPOSE FLOUR | High |
| POWDER MILK | POWDERED MILK | High |
| REFINE SUGAR | REFINED SUGAR | High |

### 4.3 Missing or Unresolved Ingredients

These sheet ingredients do not currently have a safe live DB match:

- BAKING SODA
- BOS
- CONDENSE MILK
- EVAP MILK
- GENERIC WRAP
- HARD BREAD
- JULIES SWEET BLEND
- LUBI
- MONGO PASTE
- PINE CRUSHED
- RAISENS
- SB REGULAR
- SB SPECIAL
- SESAME SEEDS
- SIOPAO PORK
- UBE PASTE

Some of these are likely not plain raw ingredients and may need classification as one of the following:

- Packaging: `GENERIC WRAP`
- Intermediate or rework material: `HARD BREAD`
- Premix or branded sub-material: `SB REGULAR`, `SB SPECIAL`, `JULIES SWEET BLEND`
- Fillings or components: `SIOPAO PORK`, `MONGO PASTE`, `UBE PASTE`, `LUBI`, `PINE CRUSHED`

## 5. Recipe Readiness for Weekly Sheet Computation

The weekly sheet needs product-to-ingredient explosion. That requires recipe coverage on the mapped products.

Among the sheet-aligned or alias-aligned products reviewed here:

### Recipe-backed

- `SPANISH` -> `SPANISH BREAD` (`7` recipe lines)

### Present in DB but not recipe-backed

- `AMERICAN LOAF`
- `CHEESE STRUESSEL`
- `COCO BUN`
- `EVERLASTING`
- `KING ROLL`
- `MARBLE LOAF`
- `MINI BUN`
- `MONAY SMALL`
- `PAN DE JULIA`
- `SIOPAO`
- `SWEET ROLL`
- `CHOCO CUPCAKE`
- `PINEAPPLE PIE`
- `POLVORON`
- `SUNFLOWER`
- `SWEET HEART`
- `UBE BAR`
- `SIACOY` -> `SIAKOY`
- `FRUITCAKE` -> `FRUIT CAKE`
- `CHOCOLATE CRINKLES` -> `CHOCO CRINKLES`
- `HALFMOON CHOCO` -> `CHOCO HALF MOON`
- `MARBLE CUP` -> `MARBLE CUPCAKE`
- `VIOLET CREAM` -> `V-CREAM`

`ENSAYMADA` is recipe-backed in the DB, but that does not automatically make it a valid match for the sheet row `ENSAY MONGGO`.

## 6. What This Means Functionally

The live tenant is currently able to:

- keep product yield in pieces for sales and inventory
- record product output by branch
- compute ingredient usage only where recipe-backed production snapshots exist

The live tenant is not yet ready to fully generate the attached weekly sheet from DB truth because:

- many sheet products are still missing or unresolved
- many sheet ingredients are still missing or unresolved
- almost all currently aligned products still have zero recipe lines

## 7. Phase 1 Cleanup Order

### Phase 1A - Alias and master-data correction

1. Create a formal alias map for sheet product names to DB product names.
2. Create a formal alias map for sheet ingredient names to DB ingredient names.
3. Add the missing product masters from the sheet.
4. Add the missing ingredient, component, premix, and packaging masters.

### Phase 1B - Recipe coverage

1. Backfill recipe lines for the sheet-aligned products already present in DB.
2. Confirm whether `ENSAY MONGGO` is a separate SKU or a business alias for another existing product.
3. Encode recipes for the newly added product masters.

### Phase 1C - Weekly sheet computation layer

1. Keep `default_yield_qty` in pieces as-is for inventory and sales.
2. Add a separate planning/output-weight layer for the sheet's `Total Kilos` presentation.
3. Explode mapped products through recipe lines to compute ingredient totals.
4. Use existing pack metadata to render the `Per Pack` column.

## 8. Immediate Practical Priority

If the goal is to make the paper sheet reproducible from the DB with the least delay, the highest-return first targets are:

1. `SPANISH` -> `SPANISH BREAD` because it is already aligned and recipe-backed.
2. The exact-match products already present in DB but still missing recipes.
3. The high-confidence alias products.
4. The unresolved products and ingredients.

That order preserves the current inventory model while moving the tenant toward reliable weekly ingredient planning.