#!/usr/bin/env bash
# Slice 3 acceptance GATE. Must FAIL on the unchanged tree; PASS means a consignee carries area/
# address/price group (persisted through the real save handler) and Show in Consignees is backed.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-slice3-probe.php
