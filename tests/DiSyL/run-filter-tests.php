#!/usr/bin/env php
<?php
/**
 * Test Runner for DiSyL v0.2 Filter Pipeline Tests
 */

// Load kernel autoloader
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../kernel/autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    // Manual class loading if no autoloader
    require_once __DIR__ . '/../../kernel/DiSyL/Token.php';
    require_once __DIR__ . '/../../kernel/DiSyL/Exceptions/LexerException.php';
    require_once __DIR__ . '/../../kernel/DiSyL/Lexer.php';
    require_once __DIR__ . '/../../kernel/DiSyL/Exceptions/ParserException.php';
    require_once __DIR__ . '/../../kernel/DiSyL/Parser.php';
}

// Load test class
require_once __DIR__ . '/FilterPipelineTest.php';

// Run tests
$test = new IkabudKernel\Tests\DiSyL\FilterPipelineTest();
$test->runAll();
