#!/usr/bin/env bash
# Slice 10 GATE — the Consignees SUB-TAB of the Daily Sheet takes the Branches shape.
#
# Must FAIL on the unchanged tree: the sub-tab is still a custody ledger (consignee as a ROW, its own
# BEG/ADDTL), so there is no consignee-keyed cell helper and the custody columns still render.
#
# NOTE the model this gate encodes was corrected by the owner on 2026-10-08. An earlier version of this
# gate required a "commissary-only" dispatch source; that was WRONG and has been deleted. Dispatch
# legitimately happens at the CASHIER LEDGER, so origin_type='branch' is normal (handlers.php:9083,
# cashier/ledger.disyl:344). Pin D now protects exactly that.
#
# Rendering is NOT tested here on purpose: storage/cache/compiled is www-data-owned, so the CLI cannot
# compile an edited template and any render criterion would be a permanent false red.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-branchflow-probe.php
