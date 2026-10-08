#!/usr/bin/env bash
# Slice 4 acceptance GATE. Must FAIL on the unchanged tree; PASS means Activity's details/changes read
# as human text (no raw JSON), no record label is duplicated, and the filter offers consignees.
set -uo pipefail
cd /var/www/html/applicationostest || exit 2
php tools/lane-consignee-slice4-probe.php
