#!/usr/bin/env bash
# Slice 7 GATE — the product modal's scope control must cover consignees, not just branches.
#
# Must FAIL on the unchanged tree (the column, the helpers and the consignee payload do not exist yet).
# PASS means a product can be scoped to "all active branches and consignees" or "only selected", that a
# MISSING mode reads as all_active (never as "strip everything"), and that all_active is ADDITIVE.
#
# The rendered consignee list is NOT tested here on purpose: storage/cache/compiled is www-data-owned, so
# the CLI cannot compile products.disyl once the slice edits it, and any render criterion would be a
# permanent false red. The chair verifies the emitted list in a real browser.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-scope-probe.php
