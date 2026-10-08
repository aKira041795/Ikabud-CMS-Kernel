#!/usr/bin/env bash
# Slice 8 GATE — commercial data leaves the stock sheet.
#
# Must FAIL on the unchanged tree (the sheet still renders SOLD / FOR COLLECTION and the report does not
# exist). PASS means no money figure reaches the production sheet — at QUERY level, not merely by hiding
# markup — and that a dispatch-valued report carries it instead.
#
# Rendering is NOT tested here on purpose: storage/cache/compiled is www-data-owned, so the CLI cannot
# compile an edited template and any render criterion would be a permanent false red. The chair verifies
# rendering in a real browser.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-commercial-probe.php
