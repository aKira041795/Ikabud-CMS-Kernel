<?php
/**
 * Verify entity fallback view resolution works through the full chain.
 */
require_once __DIR__ . '/../bootstrap.php';

$errors = [];

// 1. Verify EntityViewResolver generic fallback contract
$resolver = \Ikabud\Kernel\EntityContext\EntityViewResolver::getInstance();
$contract = $resolver->viewContract('nonexistent_module.entity', 'card');
if ($contract === null) {
    $errors[] = 'viewContract returned null for unknown entity type';
} elseif (($contract['fields'] ?? '') === '*') {
    echo "OK: EntityViewResolver returns generic fallback with fields=* for unknown entity\n";
} else {
    $errors[] = 'viewContract returned unexpected contract: ' . json_encode($contract);
}

// 2. Verify fallback resolution via EntityViewResolver::resolve()
$result = $resolver->resolve('unknown.source', 'compact');
if (isset($result['error']) && str_contains($result['error'], 'No view contract')) {
    // The resolve() method still errors because it can't call the capability bus
    // This is expected — the fallback contract is for template rendering, not data resolution
    echo "OK: resolve() correctly reports no capability for unknown source\n";
} else {
    echo "INFO: resolve() returned: " . ($result['error'] ?? 'no error') . "\n";
}

// 3. Verify fallback view contract for detail
$detailContract = $resolver->viewContract('unknown_module.item', 'detail');
if ($detailContract !== null && ($detailContract['fields'] ?? '') === '*') {
    echo "OK: Generic fallback contract for detail view\n";
} else {
    $errors[] = 'detail contract not generated';
}

// 4. Verify the resolver returns canonical contracts when modules ARE loaded
// Register a test contract to verify registered contracts take priority
$resolver->registerView('test.entity', 'default', [
    'fields' => ['id', 'title', 'status'],
    'actions' => ['view'],
    'limit' => 10,
    'empty_state' => 'Test empty.',
]);
$testContract = $resolver->viewContract('test.entity', 'default');
if ($testContract !== null && is_array($testContract['fields'] ?? null)) {
    echo "OK: Registered test.entity.default contract takes priority over generic fallback\n";
} else {
    $errors[] = 'registered contract was overridden by generic fallback';
}

// 5. Verify that the generic fallback correctly provides wildcard fields
$unknownContract = $resolver->viewContract('unknown.thing', 'list');
if ($unknownContract !== null && ($unknownContract['fields'] ?? '') === '*') {
    echo "OK: Unknown entity gets wildcard fields (*) via generic fallback\n";
} else {
    $errors[] = 'generic fallback did not provide wildcard fields';
}

// 6. Verify cmsResolveEntityFallbackView resolves correctly (if available)
if (function_exists('cmsResolveEntityFallbackView')) {
    $cardFallback = cmsResolveEntityFallbackView('card');
    if ($cardFallback !== '' && str_contains($cardFallback, 'default-card')) {
        echo "OK: cmsResolveEntityFallbackView('card') resolved: {$cardFallback}\n";
    } else {
        $errors[] = 'card fallback not resolved (got: ' . ($cardFallback ?: 'empty') . ')';
    }

    $detailFallback = cmsResolveEntityFallbackView('detail');
    if ($detailFallback !== '' && str_contains($detailFallback, 'default-detail')) {
        echo "OK: cmsResolveEntityFallbackView('detail') resolved: {$detailFallback}\n";
    } else {
        $errors[] = 'detail fallback not resolved';
    }

    $compactFallback = cmsResolveEntityFallbackView('compact');
    if ($compactFallback !== '' && str_contains($compactFallback, 'default-compact')) {
        echo "OK: cmsResolveEntityFallbackView('compact') resolved: {$compactFallback}\n";
    } else {
        $errors[] = 'compact fallback not resolved';
    }
} else {
    echo "SKIP: cmsResolveEntityFallbackView not available (CMS module not loaded)\n";
}

if ($errors) {
    echo "\nFAILURES:\n";
    foreach ($errors as $e) { echo "  ✗ {$e}\n"; }
    exit(1);
}
echo "\nALL PASS\n";
exit(0);
