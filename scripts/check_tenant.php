<?php
require __DIR__ . '/../bootstrap.php';
echo "app()->tenant()->current(): " . (app()->tenant()->current() ?? 'null') . "\n";
// Without user context, this is the current request tenant
