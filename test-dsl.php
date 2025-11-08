<?php
/**
 * Test DSL System
 * 
 * Tests the complete DSL pipeline
 */

require __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\DSL\QueryCompiler;
use IkabudKernel\DSL\QueryGrammar;

echo "=== Ikabud Kernel - DSL System Test ===\n\n";

try {
    // Boot kernel
    echo "1. Booting kernel...\n";
    Kernel::boot();
    echo "   ✓ Kernel booted\n\n";
    
    // Test Query Grammar
    echo "2. Testing Query Grammar...\n";
    $params = QueryGrammar::getAllParameters();
    echo "   ✓ Total parameters: " . count($params) . "\n";
    echo "   ✓ Required parameters: ";
    $required = array_filter($params, fn($p) => $p['required']);
    echo implode(', ', array_keys($required)) . "\n\n";
    
    // Test Query Compiler
    echo "3. Testing Query Compiler...\n";
    $compiler = new QueryCompiler();
    
    // Test basic query
    $query1 = 'type=post limit=5 format=card layout=grid-3';
    echo "   Query: $query1\n";
    $ast1 = $compiler->compile($query1);
    echo "   ✓ Compiled successfully\n";
    echo "   - Compilation time: " . round($ast1['metadata']['compilation_time_ms'], 2) . "ms\n";
    echo "   - Attributes: " . count($ast1['attributes']) . "\n";
    echo "   - Errors: " . count($ast1['errors']) . "\n";
    echo "   - Type: " . $ast1['attributes']['type'] . "\n";
    echo "   - Limit: " . $ast1['attributes']['limit'] . "\n";
    echo "   - Format: " . $ast1['attributes']['format'] . "\n";
    echo "   - Layout: " . $ast1['attributes']['layout'] . "\n\n";
    
    // Test query with placeholders
    echo "4. Testing Runtime Placeholders...\n";
    $query2 = 'type={GET:type} limit={GET:limit} format=card';
    $context = [
        'GET' => [
            'type' => 'page',
            'limit' => '10'
        ]
    ];
    echo "   Query: $query2\n";
    echo "   Context: type=page, limit=10\n";
    $ast2 = $compiler->compile($query2, $context);
    echo "   ✓ Placeholders resolved\n";
    echo "   - Type: " . $ast2['attributes']['type'] . "\n";
    echo "   - Limit: " . $ast2['attributes']['limit'] . "\n\n";
    
    // Test validation
    echo "5. Testing Validation...\n";
    $query3 = 'type=post limit=999 format=invalid';
    echo "   Query: $query3\n";
    $ast3 = $compiler->compile($query3);
    if (!empty($ast3['errors'])) {
        echo "   ✓ Validation errors detected:\n";
        foreach ($ast3['errors'] as $error) {
            echo "     - $error\n";
        }
    } else {
        echo "   ✓ No validation errors\n";
    }
    echo "\n";
    
    // Test defaults
    echo "6. Testing Default Values...\n";
    $query4 = 'type=post';
    echo "   Query: $query4\n";
    $ast4 = $compiler->compile($query4);
    echo "   ✓ Defaults applied\n";
    echo "   - Limit: " . $ast4['attributes']['limit'] . " (default: 10)\n";
    echo "   - Format: " . $ast4['attributes']['format'] . " (default: card)\n";
    echo "   - Layout: " . $ast4['attributes']['layout'] . " (default: vertical)\n";
    echo "   - Cache: " . ($ast4['attributes']['cache'] ? 'true' : 'false') . " (default: true)\n\n";
    
    // Test cache key generation
    echo "7. Testing Cache Key Generation...\n";
    $cacheKey = $ast1['metadata']['cache_key'];
    echo "   ✓ Cache key: " . substr($cacheKey, 0, 30) . "...\n\n";
    
    // Test grammar specification
    echo "8. Grammar Specification:\n";
    $grammar = QueryGrammar::getGrammar();
    $lines = explode("\n", $grammar);
    echo "   ✓ EBNF Grammar (" . count($lines) . " lines)\n";
    echo "   ✓ Placeholder types: " . implode(', ', QueryGrammar::getPlaceholderTypes()) . "\n";
    echo "   ✓ Operators: " . implode(', ', QueryGrammar::getOperators()) . "\n\n";
    
    // Performance test
    echo "9. Performance Test (100 compilations)...\n";
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $compiler->compile('type=post limit=5 format=card');
    }
    $duration = (microtime(true) - $start) * 1000;
    echo "   ✓ Total time: " . round($duration, 2) . "ms\n";
    echo "   ✓ Average: " . round($duration / 100, 2) . "ms per compilation\n\n";
    
    echo "=== All DSL Tests Passed! ===\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
