<?php
/**
 * DiSyL v0.2 Filter Pipeline Tests
 * 
 * Tests for enhanced filter syntax with multiple arguments
 * 
 * @version 0.2.0
 */

namespace IkabudKernel\Tests\DiSyL;

use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Token;

class FilterPipelineTest
{
    private Lexer $lexer;
    private Parser $parser;
    
    public function __construct()
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
    }
    
    /**
     * Test: Simple filter with no arguments
     */
    public function testSimpleFilter(): void
    {
        $template = '{item.title | upper}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify tokens include PIPE
        $hasPipe = false;
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) {
                $hasPipe = true;
                break;
            }
        }
        
        assert($hasPipe, 'Lexer should recognize pipe operator');
        echo "✅ Simple filter test passed\n";
    }
    
    /**
     * Test: Filter with single positional argument
     */
    public function testFilterWithPositionalArgument(): void
    {
        $template = '{item.content | truncate:100}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify tokens include PIPE and COLON
        $hasPipe = false;
        $hasColon = false;
        
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) $hasPipe = true;
            if ($token->type === Token::COLON) $hasColon = true;
        }
        
        assert($hasPipe, 'Lexer should recognize pipe operator');
        assert($hasColon, 'Lexer should recognize colon for arguments');
        echo "✅ Filter with positional argument test passed\n";
    }
    
    /**
     * Test: Filter with named argument
     */
    public function testFilterWithNamedArgument(): void
    {
        $template = '{item.content | truncate:length=100}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify tokens include PIPE, COLON, and EQUAL
        $hasPipe = false;
        $hasColon = false;
        $hasEqual = false;
        
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) $hasPipe = true;
            if ($token->type === Token::COLON) $hasColon = true;
            if ($token->type === Token::EQUAL) $hasEqual = true;
        }
        
        assert($hasPipe, 'Lexer should recognize pipe operator');
        assert($hasColon, 'Lexer should recognize colon for arguments');
        assert($hasEqual, 'Lexer should recognize equals for named arguments');
        echo "✅ Filter with named argument test passed\n";
    }
    
    /**
     * Test: Filter with multiple arguments
     */
    public function testFilterWithMultipleArguments(): void
    {
        $template = '{item.content | truncate:length=100,append="..."}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify tokens include COMMA
        $hasComma = false;
        
        foreach ($tokens as $token) {
            if ($token->type === Token::COMMA) {
                $hasComma = true;
                break;
            }
        }
        
        assert($hasComma, 'Lexer should recognize comma for multiple arguments');
        echo "✅ Filter with multiple arguments test passed\n";
    }
    
    /**
     * Test: Chained filters
     */
    public function testChainedFilters(): void
    {
        $template = '{item.title | strip_tags | upper | esc_html}';
        $tokens = $this->lexer->tokenize($template);
        
        // Count pipe operators (should be 3)
        $pipeCount = 0;
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) {
                $pipeCount++;
            }
        }
        
        assert($pipeCount === 3, 'Should have 3 pipe operators for 3 filters');
        echo "✅ Chained filters test passed\n";
    }
    
    /**
     * Test: Complex filter chain with arguments
     */
    public function testComplexFilterChain(): void
    {
        $template = '{item.description | strip_tags | truncate:length=50,append="..." | upper}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify all necessary tokens
        $hasPipe = false;
        $hasColon = false;
        $hasComma = false;
        $hasEqual = false;
        
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) $hasPipe = true;
            if ($token->type === Token::COLON) $hasColon = true;
            if ($token->type === Token::COMMA) $hasComma = true;
            if ($token->type === Token::EQUAL) $hasEqual = true;
        }
        
        assert($hasPipe, 'Should have pipe operators');
        assert($hasColon, 'Should have colon for arguments');
        assert($hasComma, 'Should have comma for multiple arguments');
        assert($hasEqual, 'Should have equals for named arguments');
        echo "✅ Complex filter chain test passed\n";
    }
    
    /**
     * Test: Filter in attribute value
     */
    public function testFilterInAttribute(): void
    {
        $template = '{ikb_link href="{item.url | esc_url}"}Link{/ikb_link}';
        $tokens = $this->lexer->tokenize($template);
        
        // Should parse without errors
        $ast = $this->parser->parse($tokens);
        
        assert($ast['type'] === 'document', 'Should parse to document');
        assert($ast['version'] === '0.2', 'Should be version 0.2');
        echo "✅ Filter in attribute test passed\n";
    }
    
    /**
     * Test: Date filter with format argument
     */
    public function testDateFilter(): void
    {
        $template = '{item.date | date:format="F j, Y"}';
        $tokens = $this->lexer->tokenize($template);
        
        // Verify proper tokenization
        $hasPipe = false;
        $hasColon = false;
        
        foreach ($tokens as $token) {
            if ($token->type === Token::PIPE) $hasPipe = true;
            if ($token->type === Token::COLON) $hasColon = true;
        }
        
        assert($hasPipe && $hasColon, 'Date filter should be properly tokenized');
        echo "✅ Date filter test passed\n";
    }
    
    /**
     * Test: Number format filter with multiple arguments
     */
    public function testNumberFormatFilter(): void
    {
        $template = '{item.price | number_format:decimals=2,dec_point=".",thousands_sep=","}';
        $tokens = $this->lexer->tokenize($template);
        
        // Count commas (should be 2 for 3 arguments)
        $commaCount = 0;
        foreach ($tokens as $token) {
            if ($token->type === Token::COMMA) {
                $commaCount++;
            }
        }
        
        assert($commaCount === 2, 'Should have 2 commas for 3 named arguments');
        echo "✅ Number format filter test passed\n";
    }
    
    /**
     * Run all tests
     */
    public function runAll(): void
    {
        echo "\n";
        echo "=================================================\n";
        echo "  DiSyL v0.2 Filter Pipeline Tests\n";
        echo "=================================================\n\n";
        
        $this->testSimpleFilter();
        $this->testFilterWithPositionalArgument();
        $this->testFilterWithNamedArgument();
        $this->testFilterWithMultipleArguments();
        $this->testChainedFilters();
        $this->testComplexFilterChain();
        $this->testFilterInAttribute();
        $this->testDateFilter();
        $this->testNumberFormatFilter();
        
        echo "\n";
        echo "=================================================\n";
        echo "  ✅ All tests passed!\n";
        echo "=================================================\n\n";
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    
    $test = new FilterPipelineTest();
    $test->runAll();
}
