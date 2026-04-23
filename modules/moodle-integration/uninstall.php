<?php

declare(strict_types=1);

echo "Moodle Integration uninstall helper\n";
echo "This module follows manifest-driven migrations and tenant-scoped settings.\n";
echo "Recommended removal sequence:\n";
echo "1. Disable the module for affected tenants.\n";
echo "2. Remove or archive Moodle settings if required.\n";
echo "3. Drop moodle_* tables manually only after confirming no tenant still needs the data.\n";

exit(0);