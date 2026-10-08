#!/usr/bin/env bash
# Consignee sales-mode GATE. Must FAIL on the unchanged tree. PASS means the setting exists, defaults to
# consignment, coerces garbage safely, and the consignee ledger is byte-identical in both modes.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-sales-mode-probe.php
