<?php
/**
 * DiSyL Grammar Tests
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Grammar;

class GrammarTest extends TestCase
{
    private Grammar $grammar;
    
    protected function setUp(): void
    {
        $this->grammar = new Grammar();
    }
    
    /**
     * Test string type validation
     */
    public function testStringTypeValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertFalse($this->grammar->validate(123, $schema));
        $this->assertFalse($this->grammar->validate(true, $schema));
    }
    
    /**
     * Test integer type validation
     */
    public function testIntegerTypeValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_INTEGER];
        
        $this->assertTrue($this->grammar->validate(123, $schema));
        $this->assertFalse($this->grammar->validate(3.14, $schema));
        $this->assertFalse($this->grammar->validate('123', $schema));
    }
    
    /**
     * Test float type validation
     */
    public function testFloatTypeValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_FLOAT];
        
        $this->assertTrue($this->grammar->validate(3.14, $schema));
        $this->assertFalse($this->grammar->validate(123, $schema));
    }
    
    /**
     * Test boolean type validation
     */
    public function testBooleanTypeValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_BOOLEAN];
        
        $this->assertTrue($this->grammar->validate(true, $schema));
        $this->assertTrue($this->grammar->validate(false, $schema));
        $this->assertFalse($this->grammar->validate(1, $schema));
        $this->assertFalse($this->grammar->validate('true', $schema));
    }
    
    /**
     * Test number type validation (int or float)
     */
    public function testNumberTypeValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_NUMBER];
        
        $this->assertTrue($this->grammar->validate(123, $schema));
        $this->assertTrue($this->grammar->validate(3.14, $schema));
        $this->assertTrue($this->grammar->validate('456', $schema)); // numeric string
        $this->assertFalse($this->grammar->validate('abc', $schema));
    }
    
    /**
     * Test required validation
     */
    public function testRequiredValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING, 'required' => true];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertFalse($this->grammar->validate(null, $schema));
    }
    
    /**
     * Test optional validation
     */
    public function testOptionalValidation(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING, 'required' => false];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertTrue($this->grammar->validate(null, $schema));
    }
    
    /**
     * Test enum validation
     */
    public function testEnumValidation(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'enum' => ['small', 'medium', 'large']
        ];
        
        $this->assertTrue($this->grammar->validate('small', $schema));
        $this->assertTrue($this->grammar->validate('medium', $schema));
        $this->assertFalse($this->grammar->validate('extra-large', $schema));
    }
    
    /**
     * Test min/max validation for numbers
     */
    public function testMinMaxValidation(): void
    {
        $schema = [
            'type' => Grammar::TYPE_INTEGER,
            'min' => 1,
            'max' => 10
        ];
        
        $this->assertTrue($this->grammar->validate(5, $schema));
        $this->assertTrue($this->grammar->validate(1, $schema));
        $this->assertTrue($this->grammar->validate(10, $schema));
        $this->assertFalse($this->grammar->validate(0, $schema));
        $this->assertFalse($this->grammar->validate(11, $schema));
    }
    
    /**
     * Test minLength/maxLength validation for strings
     */
    public function testLengthValidation(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'minLength' => 3,
            'maxLength' => 10
        ];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertTrue($this->grammar->validate('abc', $schema));
        $this->assertTrue($this->grammar->validate('abcdefghij', $schema));
        $this->assertFalse($this->grammar->validate('ab', $schema));
        $this->assertFalse($this->grammar->validate('abcdefghijk', $schema));
    }
    
    /**
     * Test pattern validation
     */
    public function testPatternValidation(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'pattern' => '/^[a-z]+$/'
        ];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertFalse($this->grammar->validate('Hello', $schema));
        $this->assertFalse($this->grammar->validate('hello123', $schema));
    }
    
    /**
     * Test default value normalization
     */
    public function testDefaultNormalization(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'default' => 'default-value'
        ];
        
        $this->assertEquals('default-value', $this->grammar->normalize(null, $schema));
        $this->assertEquals('custom', $this->grammar->normalize('custom', $schema));
    }
    
    /**
     * Test type coercion
     */
    public function testTypeCoercion(): void
    {
        $schema = [
            'type' => Grammar::TYPE_INTEGER,
            'coerce' => true
        ];
        
        $this->assertEquals(123, $this->grammar->normalize('123', $schema));
        $this->assertIsInt($this->grammar->normalize('123', $schema));
    }
    
    /**
     * Test validation error messages
     */
    public function testValidationErrorMessages(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING, 'required' => true];
        $error = $this->grammar->getValidationError(null, $schema, 'title');
        
        $this->assertStringContainsString('required', $error);
        $this->assertStringContainsString('title', $error);
    }
    
    /**
     * Test type error messages
     */
    public function testTypeErrorMessages(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING];
        $error = $this->grammar->getValidationError(123, $schema, 'name');
        
        $this->assertStringContainsString('type', $error);
        $this->assertStringContainsString('string', $error);
    }
    
    /**
     * Test enum error messages
     */
    public function testEnumErrorMessages(): void
    {
        $schema = ['type' => Grammar::TYPE_STRING, 'enum' => ['a', 'b', 'c']];
        $error = $this->grammar->getValidationError('d', $schema, 'option');
        
        $this->assertStringContainsString('one of', $error);
    }
    
    /**
     * Test validateAttributes method
     */
    public function testValidateAttributes(): void
    {
        $schemas = [
            'title' => ['type' => Grammar::TYPE_STRING, 'required' => true],
            'count' => ['type' => Grammar::TYPE_INTEGER, 'min' => 1, 'max' => 10]
        ];
        
        $validAttrs = ['title' => 'Hello', 'count' => 5];
        $errors = $this->grammar->validateAttributes($validAttrs, $schemas);
        $this->assertEmpty($errors);
        
        $invalidAttrs = ['count' => 15];
        $errors = $this->grammar->validateAttributes($invalidAttrs, $schemas);
        $this->assertNotEmpty($errors);
        $this->assertCount(2, $errors); // missing title + count out of range
    }
    
    /**
     * Test normalizeAttributes method
     */
    public function testNormalizeAttributes(): void
    {
        $schemas = [
            'title' => ['type' => Grammar::TYPE_STRING, 'default' => 'Untitled'],
            'count' => ['type' => Grammar::TYPE_INTEGER, 'default' => 1]
        ];
        
        $attrs = ['count' => 5];
        $normalized = $this->grammar->normalizeAttributes($attrs, $schemas);
        
        $this->assertEquals('Untitled', $normalized['title']);
        $this->assertEquals(5, $normalized['count']);
    }
    
    /**
     * Test normalizeAttributes preserves unknown attributes
     */
    public function testNormalizeAttributesPreservesUnknown(): void
    {
        $schemas = [
            'title' => ['type' => Grammar::TYPE_STRING]
        ];
        
        $attrs = ['title' => 'Hello', 'custom' => 'value'];
        $normalized = $this->grammar->normalizeAttributes($attrs, $schemas);
        
        $this->assertEquals('Hello', $normalized['title']);
        $this->assertEquals('value', $normalized['custom']);
    }
}
