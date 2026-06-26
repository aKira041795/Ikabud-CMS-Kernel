<?php
$json = file_get_contents(__DIR__ . '/modules/bakeshop/module.json');
$data = json_decode($json, true);
if ($data === null) {
    echo "INVALID JSON: " . json_last_error_msg() . "\n";
    exit(1);
}
echo "Valid JSON\n";
echo "Migrations: " . count($data['migrations'] ?? []) . "\n";
echo "Nav entries: " . count($data['nav'] ?? []) . "\n";
foreach ($data['nav'] ?? [] as $n) {
    echo "  - {$n['label']}: {$n['url']}\n";
}
echo "Owns tables:\n";
foreach ($data['owns_tables'] ?? [] as $t) {
    echo "  - $t\n";
}
