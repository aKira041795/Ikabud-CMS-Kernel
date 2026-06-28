<?php
/**
 * Quick smoke test — verifies app boots and slot system is wired.
 */
require_once __DIR__ . '/../bootstrap.php';

$errors = [];
$slotComp = \Ikabud\Kernel\DiSyL\ComponentRegistry::get('ikb_slot');
if (!$slotComp) { $errors[] = 'ikb_slot not in ComponentRegistry'; }
if (($slotComp['attributes']['name']['required'] ?? false) !== true) { $errors[] = 'ikb_slot name attr not required'; }

$sr = \Ikabud\Kernel\Services\SlotRegistry::getInstance();
if (!$sr) { $errors[] = 'SlotRegistry not instantiated'; }

if (!method_exists(app(), 'slotRegistry')) { $errors[] = 'app()->slotRegistry() missing'; }

if ($errors) {
    echo "SMOKE FAIL:\n";
    foreach ($errors as $e) { echo "  ✗ {$e}\n"; }
    exit(1);
}
echo "SMOKE PASS: slot system fully wired ✓\n";
exit(0);
