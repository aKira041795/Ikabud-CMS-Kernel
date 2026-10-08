#!/usr/bin/env bash
# Consignee feature-toggle GATE. Must FAIL on the unchanged tree. PASS means the capability can be
# switched off at admin settings, that off REFUSES new consignee dispatches, and that off destroys no
# recorded history.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-toggle-probe.php
