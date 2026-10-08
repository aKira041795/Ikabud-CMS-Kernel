#!/usr/bin/env bash
# Gate — commissary stock depletes on consignee dispatch (one owner for "dispatched").
#
# Must FAIL on the unchanged tree: the dispatch matrix filters destination_type='branch', so a consignee
# dispatch never reduces the commissary balance, and the projection excludes it too.
#
# Rendering is NOT tested here: storage/cache/compiled is www-data-owned, so an edited template cannot be
# compiled from CLI and a render criterion would be a permanent false red.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-depletion-probe.php
