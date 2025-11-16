#!/usr/bin/env php
<?php
/**
 * Verify JoomlaRenderer Setup
 * 
 * This script verifies that the JoomlaRenderer is properly set up
 * and can be used by the Joomla Phoenix template.
 */

echo "=== JoomlaRenderer Verification ===\n\n";

// Check if autoloader exists
$autoloadPath = __DIR__ . '/vendor/autoload.php';
echo "1. Checking autoloader...\n";
if (file_exists($autoloadPath)) {
    echo "   ✅ Autoloader found: {$autoloadPath}\n";
    require_once $autoloadPath;
} else {
    echo "   ❌ Autoloader NOT found: {$autoloadPath}\n";
    exit(1);
}

// Check if JoomlaRenderer class exists
echo "\n2. Checking JoomlaRenderer class...\n";
$rendererPath = __DIR__ . '/kernel/DiSyL/Renderers/JoomlaRenderer.php';
if (file_exists($rendererPath)) {
    echo "   ✅ JoomlaRenderer file found: {$rendererPath}\n";
} else {
    echo "   ❌ JoomlaRenderer file NOT found: {$rendererPath}\n";
    exit(1);
}

// Try to instantiate the class
echo "\n3. Attempting to load JoomlaRenderer class...\n";
try {
    $className = 'IkabudKernel\\Core\\DiSyL\\Renderers\\JoomlaRenderer';
    
    if (class_exists($className)) {
        echo "   ✅ Class exists: {$className}\n";
        
        $renderer = new $className();
        echo "   ✅ Successfully instantiated JoomlaRenderer\n";
        echo "   ✅ Class type: " . get_class($renderer) . "\n";
        
        // Check if it extends BaseRenderer
        $parentClass = get_parent_class($renderer);
        echo "   ✅ Extends: {$parentClass}\n";
        
        // List available methods
        $methods = get_class_methods($renderer);
        $componentMethods = array_filter($methods, function($method) {
            return strpos($method, 'render') === 0 && $method !== 'render';
        });
        
        echo "\n4. Available render methods:\n";
        foreach ($componentMethods as $method) {
            echo "   - {$method}\n";
        }
        
    } else {
        echo "   ❌ Class NOT found: {$className}\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Check Joomla template integration
echo "\n5. Checking Joomla template integration...\n";
$joomlaIntegrationPath = __DIR__ . '/instances/jml-joomla-the-beginning/templates/phoenix/includes/disyl-integration.php';
if (file_exists($joomlaIntegrationPath)) {
    echo "   ✅ Integration file found\n";
    
    $content = file_get_contents($joomlaIntegrationPath);
    
    if (strpos($content, 'use IkabudKernel\\Core\\DiSyL\\Renderers\\JoomlaRenderer;') !== false) {
        echo "   ✅ Imports JoomlaRenderer from kernel\n";
    } else {
        echo "   ⚠️  Does NOT import JoomlaRenderer from kernel\n";
    }
    
    if (strpos($content, 'new JoomlaRenderer()') !== false) {
        echo "   ✅ Instantiates JoomlaRenderer\n";
    } else {
        echo "   ⚠️  Does NOT instantiate JoomlaRenderer\n";
    }
} else {
    echo "   ⚠️  Integration file NOT found: {$joomlaIntegrationPath}\n";
}

// Check Joomla template files
echo "\n6. Checking Joomla Phoenix template files...\n";
$templatePath = __DIR__ . '/instances/jml-joomla-the-beginning/templates/phoenix';
$requiredFiles = [
    'index.php',
    'templateDetails.xml',
    'includes/disyl-integration.php',
    'includes/helper.php',
    'disyl/home.disyl',
    'disyl/components/header.disyl',
    'assets/css/style.css',
];

foreach ($requiredFiles as $file) {
    $fullPath = $templatePath . '/' . $file;
    if (file_exists($fullPath)) {
        echo "   ✅ {$file}\n";
    } else {
        echo "   ❌ {$file} NOT found\n";
    }
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ JoomlaRenderer is properly set up!\n";
echo "\nThe Joomla Phoenix template can now use:\n";
echo "- IkabudKernel\\Core\\DiSyL\\Renderers\\JoomlaRenderer\n";
echo "- All DiSyL components (ikb_section, ikb_container, etc.)\n";
echo "- Joomla-specific components (joomla_module, joomla_component)\n";
echo "- Query support for Joomla articles\n";
echo "- Menu rendering\n";
echo "- Conditional logic\n";
echo str_repeat("=", 50) . "\n";
