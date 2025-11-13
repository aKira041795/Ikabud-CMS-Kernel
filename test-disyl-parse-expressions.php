<?php
/**
 * DiSyL Expression Parsing Debug
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/kernel/DiSyL/Token.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/LexerException.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/ParserException.php';
require_once __DIR__ . '/kernel/DiSyL/Lexer.php';
require_once __DIR__ . '/kernel/DiSyL/Parser.php';

use IkabudKernel\Core\DiSyL\{Lexer, Parser};

$template = '{ikb_text}Title: {title}{/ikb_text}';

echo "Template: $template\n\n";

$lexer = new Lexer();
$tokens = $lexer->tokenize($template);

echo "Tokens:\n";
foreach ($tokens as $i => $token) {
    echo "  [$i] {$token->type}: '{$token->value}'\n";
}

echo "\n";

$parser = new Parser();
$ast = $parser->parse($tokens);

echo "AST:\n";
echo json_encode($ast, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
