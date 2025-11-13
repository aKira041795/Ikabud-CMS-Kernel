<?php
/**
 * DiSyL POC Theme Test Script
 * 
 * Run this to verify theme setup
 */

echo "=== DiSyL POC Theme Test ===\n\n";

// Test 1: Check DiSyL files exist
echo "Test 1: Checking DiSyL Engine Files...\n";
$kernel_path = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
$required_files = [
    'kernel/DiSyL/Lexer.php',
    'kernel/DiSyL/Parser.php',
    'kernel/DiSyL/Compiler.php',
    'kernel/DiSyL/Grammar.php',
    'kernel/DiSyL/ComponentRegistry.php',
    'kernel/DiSyL/Renderers/BaseRenderer.php',
    'kernel/DiSyL/Renderers/WordPressRenderer.php',
];

$all_exist = true;
foreach ($required_files as $file) {
    $full_path = $kernel_path . '/' . $file;
    if (file_exists($full_path)) {
        echo "  ✅ $file\n";
    } else {
        echo "  ❌ $file NOT FOUND\n";
        $all_exist = false;
    }
}

if ($all_exist) {
    echo "  ✅ All DiSyL engine files found!\n\n";
} else {
    echo "  ❌ Some files missing!\n\n";
    exit(1);
}

// Test 2: Check template files
echo "Test 2: Checking DiSyL Template Files...\n";
$template_files = [
    'disyl/home.disyl',
    'disyl/single.disyl',
    'disyl/archive.disyl',
    'disyl/page.disyl',
    'disyl/components/header.disyl',
    'disyl/components/footer.disyl',
];

$all_templates_exist = true;
foreach ($template_files as $file) {
    $full_path = __DIR__ . '/' . $file;
    if (file_exists($full_path)) {
        echo "  ✅ $file\n";
    } else {
        echo "  ❌ $file NOT FOUND\n";
        $all_templates_exist = false;
    }
}

if ($all_templates_exist) {
    echo "  ✅ All template files found!\n\n";
} else {
    echo "  ❌ Some templates missing!\n\n";
    exit(1);
}

// Test 3: Try to load DiSyL classes
echo "Test 3: Loading DiSyL Classes...\n";
try {
    require_once $kernel_path . '/kernel/DiSyL/Token.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/LexerException.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/ParserException.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/CompilerException.php';
    require_once $kernel_path . '/kernel/DiSyL/Lexer.php';
    require_once $kernel_path . '/kernel/DiSyL/Parser.php';
    require_once $kernel_path . '/kernel/DiSyL/Grammar.php';
    require_once $kernel_path . '/kernel/DiSyL/ComponentRegistry.php';
    require_once $kernel_path . '/kernel/DiSyL/Compiler.php';
    
    echo "  ✅ All classes loaded successfully!\n\n";
} catch (Exception $e) {
    echo "  ❌ Error loading classes: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 4: Test simple compilation
echo "Test 4: Testing DiSyL Compilation...\n";
try {
    $lexer = new \IkabudKernel\Core\DiSyL\Lexer();
    $parser = new \IkabudKernel\Core\DiSyL\Parser();
    $compiler = new \IkabudKernel\Core\DiSyL\Compiler();
    
    $template = '{ikb_text size="xl"}Hello DiSyL{/ikb_text}';
    
    $start = microtime(true);
    $tokens = $lexer->tokenize($template);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    $time = (microtime(true) - $start) * 1000;
    
    echo "  ✅ Compilation successful!\n";
    echo "  ⚡ Time: " . number_format($time, 2) . "ms\n";
    echo "  📊 Tokens: " . count($tokens) . "\n";
    echo "  📊 AST Nodes: " . count($compiled['children']) . "\n\n";
} catch (Exception $e) {
    echo "  ❌ Compilation error: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 5: Check theme files
echo "Test 5: Checking Theme Files...\n";
$theme_files = [
    'style.css',
    'functions.php',
    'index.php',
    'README.md',
];

foreach ($theme_files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "  ✅ $file\n";
    } else {
        echo "  ❌ $file NOT FOUND\n";
    }
}

echo "\n=== All Tests Passed! ===\n";
echo "\nTheme is ready for activation!\n";
echo "\nNext steps:\n";
echo "1. Go to WordPress admin\n";
echo "2. Navigate to Appearance → Themes\n";
echo "3. Activate 'DiSyL POC' theme\n";
echo "4. Visit your site to see DiSyL in action!\n";
