#!/usr/bin/env bash
# Slice 2 acceptance GATE. Must FAIL on the unchanged tree; PASS means the declared criterion
# ("consignee deliveries can be applied/verified at admin Deliveries as evidence only") became true.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-slice2-probe.php
