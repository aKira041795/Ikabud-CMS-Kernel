<?php
/**
 * DiSyL Grammar v1.2.0 Unit Tests
 * 
 * Comprehensive test suite for Grammar validation layer.
 * Run with: vendor/bin/phpunit tests/DiSyL/GrammarTest.php
 * 
 * @version 1.2.0
 */

namespace IkabudKernel\Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Grammar;
use IkabudKernel\Core\DiSyL\ValidationError;
use IkabudKernel\Core\DiSyL\ValidationResult;
use IkabudKernel\Core\DiSyL\ComponentRegistry;

class GrammarTest extends TestCase
{
    private Grammar $grammar;
    
    protected function setUp(): void
    {
        $this->grammar = new Grammar();
        Grammar::clearCache();
    }
    
    // =========================================================================
    // TYPE VALIDATION TESTS
    // =========================================================================
    
    /**
     * @dataProvider primitiveTypeProvider
     */
    public function testValidatePrimitiveTypes(mixed $value, string $type, bool $expected): void
    {
        $result = $this->grammar->validateType($value, $type);
        $this->assertSame($expected, $result, "Failed for value: " . var_export($value, true) . " type: $type");
    }
    
    public static function primitiveTypeProvider(): array
    {
        return [
            // String
            ['hello', Grammar::TYPE_STRING, true],
            ['', Grammar::TYPE_STRING, true],
            [123, Grammar::TYPE_STRING, false],
            [null, Grammar::TYPE_STRING, false],
            
            // Number (Grammar allows numeric strings for flexibility)
            [123, Grammar::TYPE_NUMBER, true],
            [12.5, Grammar::TYPE_NUMBER, true],
            ['123', Grammar::TYPE_NUMBER, true], // numeric string coerced
            ['abc', Grammar::TYPE_NUMBER, false], // non-numeric string
            
            // Integer (Grammar allows numeric strings for flexibility)
            [123, Grammar::TYPE_INTEGER, true],
            [12.5, Grammar::TYPE_INTEGER, false],
            ['123', Grammar::TYPE_INTEGER, true], // numeric string coerced
            ['12.5', Grammar::TYPE_INTEGER, false], // float string not valid int
            
            // Float (Grammar allows numeric strings for flexibility)
            [12.5, Grammar::TYPE_FLOAT, true],
            [123, Grammar::TYPE_FLOAT, true], // int is valid float
            ['12.5', Grammar::TYPE_FLOAT, true], // numeric string coerced
            ['abc', Grammar::TYPE_FLOAT, false], // non-numeric string
            
            // Boolean (Grammar allows string 'true'/'false' for flexibility)
            [true, Grammar::TYPE_BOOLEAN, true],
            [false, Grammar::TYPE_BOOLEAN, true],
            [1, Grammar::TYPE_BOOLEAN, false], // integers are not booleans
            ['true', Grammar::TYPE_BOOLEAN, true], // string 'true' coerced
            ['invalid', Grammar::TYPE_BOOLEAN, false], // invalid string
            
            // Null
            [null, Grammar::TYPE_NULL, true],
            ['', Grammar::TYPE_NULL, false],
            [0, Grammar::TYPE_NULL, false],
            
            // Array
            [[], Grammar::TYPE_ARRAY, true],
            [[1, 2, 3], Grammar::TYPE_ARRAY, true],
            ['[]', Grammar::TYPE_ARRAY, false],
            
            // Object
            [['key' => 'value'], Grammar::TYPE_OBJECT, true],
            [new \stdClass(), Grammar::TYPE_OBJECT, true],
            [[], Grammar::TYPE_OBJECT, false], // empty array is not object
            
            // Any
            ['anything', Grammar::TYPE_ANY, true],
            [123, Grammar::TYPE_ANY, true],
            [null, Grammar::TYPE_ANY, true],
        ];
    }
    
    /**
     * @dataProvider extendedTypeProvider
     */
    public function testValidateExtendedTypes(mixed $value, string $type, bool $expected): void
    {
        $result = $this->grammar->validateType($value, $type);
        $this->assertSame($expected, $result);
    }
    
    public static function extendedTypeProvider(): array
    {
        return [
            // URL
            ['https://example.com', Grammar::TYPE_URL, true],
            ['http://localhost:8080/path', Grammar::TYPE_URL, true],
            ['not-a-url', Grammar::TYPE_URL, false],
            
            // Email
            ['test@example.com', Grammar::TYPE_EMAIL, true],
            ['invalid-email', Grammar::TYPE_EMAIL, false],
            
            // Color (Grammar is permissive - accepts hex, rgb, hsl, and named colors)
            ['#fff', Grammar::TYPE_COLOR, true],
            ['#ffffff', Grammar::TYPE_COLOR, true],
            ['rgb(255, 0, 0)', Grammar::TYPE_COLOR, true],
            ['red', Grammar::TYPE_COLOR, true],
            // Note: Grammar accepts any string as potential named color
            // Only non-strings would fail
            [123, Grammar::TYPE_COLOR, false],
            
            // Date
            ['2025-11-26', Grammar::TYPE_DATE, true],
            ['11/26/2025', Grammar::TYPE_DATE, false],
            
            // JSON
            ['{"key": "value"}', Grammar::TYPE_JSON, true],
            ['[1, 2, 3]', Grammar::TYPE_JSON, true],
            ['invalid json', Grammar::TYPE_JSON, false],
        ];
    }
    
    public function testValidateUnionTypes(): void
    {
        // string|number
        $this->assertTrue($this->grammar->validateType('hello', 'string|number'));
        $this->assertTrue($this->grammar->validateType(123, 'string|number'));
        $this->assertFalse($this->grammar->validateType(true, 'string|number'));
        
        // string|null
        $this->assertTrue($this->grammar->validateType('hello', 'string|null'));
        $this->assertTrue($this->grammar->validateType(null, 'string|null'));
        $this->assertFalse($this->grammar->validateType(123, 'string|null'));
    }
    
    // =========================================================================
    // SCHEMA VALIDATION TESTS
    // =========================================================================
    
    public function testValidateWithSchema(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'required' => true,
            'minLength' => 3,
            'maxLength' => 10,
        ];
        
        $this->assertTrue($this->grammar->validate('hello', $schema));
        $this->assertFalse($this->grammar->validate('hi', $schema)); // too short
        $this->assertFalse($this->grammar->validate('hello world!', $schema)); // too long
        $this->assertFalse($this->grammar->validate(null, $schema)); // required
    }
    
    public function testValidateWithEnum(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'enum' => ['small', 'medium', 'large'],
        ];
        
        $this->assertTrue($this->grammar->validate('small', $schema));
        $this->assertTrue($this->grammar->validate('medium', $schema));
        $this->assertFalse($this->grammar->validate('xlarge', $schema));
    }
    
    public function testValidateWithPattern(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'pattern' => '/^[A-Z]{3}$/',
        ];
        
        $this->assertTrue($this->grammar->validate('ABC', $schema));
        $this->assertFalse($this->grammar->validate('abc', $schema));
        $this->assertFalse($this->grammar->validate('ABCD', $schema));
    }
    
    public function testNormalizeWithDefaults(): void
    {
        $schema = [
            'type' => Grammar::TYPE_STRING,
            'default' => 'default_value',
        ];
        
        $this->assertSame('default_value', $this->grammar->normalize(null, $schema));
        $this->assertSame('custom', $this->grammar->normalize('custom', $schema));
    }
    
    // =========================================================================
    // EXPRESSION PARSING TESTS
    // =========================================================================
    
    public function testParseSimpleExpression(): void
    {
        $result = $this->grammar->parseExpression('{ title }');
        
        $this->assertSame('title', $result['variable']);
        $this->assertEmpty($result['filters']);
        $this->assertFalse($result['hasEscaping']);
    }
    
    public function testParseExpressionWithFilters(): void
    {
        $result = $this->grammar->parseExpression('{ title | truncate:100 | esc_html }');
        
        $this->assertSame('title', $result['variable']);
        $this->assertCount(2, $result['filters']);
        $this->assertSame('truncate', $result['filters'][0]['name']);
        $this->assertSame('esc_html', $result['filters'][1]['name']);
        $this->assertTrue($result['hasEscaping']);
    }
    
    public function testParseVariablePathDotNotation(): void
    {
        $result = $this->grammar->parseVariablePath('user.profile.name');
        
        $this->assertCount(3, $result);
        $this->assertSame('user', $result[0]['name']);
        $this->assertSame('profile', $result[1]['name']);
        $this->assertSame('name', $result[2]['name']);
    }
    
    public function testParseVariablePathArrayAccess(): void
    {
        $result = $this->grammar->parseVariablePath('items[0].title');
        
        $this->assertCount(3, $result);
        $this->assertSame('items', $result[0]['name']);
        $this->assertSame('index', $result[1]['type']);
        $this->assertSame(0, $result[1]['value']);
        $this->assertSame('title', $result[2]['name']);
    }
    
    public function testParseVariablePathKeyAccess(): void
    {
        $result = $this->grammar->parseVariablePath('data["key"]');
        
        $this->assertCount(2, $result);
        $this->assertSame('data', $result[0]['name']);
        $this->assertSame('key', $result[1]['type']);
        $this->assertSame('key', $result[1]['value']);
    }
    
    public function testParseVariablePathSafeNavigation(): void
    {
        $result = $this->grammar->parseVariablePath('user?.profile?.name');
        
        $this->assertCount(3, $result);
        $this->assertTrue($result[0]['safe'] ?? false);
        $this->assertTrue($result[1]['safe'] ?? false);
    }
    
    public function testExpressionCaching(): void
    {
        $expr = '{ test | esc_html }';
        
        // First call - not cached
        $result1 = $this->grammar->parseExpression($expr);
        
        // Second call - should be cached
        $result2 = $this->grammar->parseExpression($expr);
        
        $this->assertEquals($result1, $result2);
    }
    
    // =========================================================================
    // FILTER VALIDATION TESTS
    // =========================================================================
    
    public function testValidateKnownFilter(): void
    {
        $errors = $this->grammar->validateFilterChain('| esc_html');
        $this->assertEmpty($errors);
    }
    
    public function testValidateUnknownFilter(): void
    {
        $errors = $this->grammar->validateFilterChain('| unknown_filter');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unknown filter', $errors[0]);
    }
    
    public function testValidateFilterPlatformCompatibility(): void
    {
        // wp_trim_words is WordPress-only
        $errors = $this->grammar->validateFilterChain('| wp_trim_words:10', Grammar::PLATFORM_DRUPAL);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not available on platform', $errors[0]);
        
        // Should work on WordPress
        $errors = $this->grammar->validateFilterChain('| wp_trim_words:10', Grammar::PLATFORM_WORDPRESS);
        $this->assertEmpty($errors);
    }
    
    public function testValidateFilterRequiredParams(): void
    {
        // truncate requires length parameter
        $errors = $this->grammar->validateFilterChain('| truncate');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires parameter', $errors[0]);
        
        // With parameter - should pass
        $errors = $this->grammar->validateFilterChain('| truncate:100');
        $this->assertEmpty($errors);
    }
    
    public function testValidateFilterChainRich(): void
    {
        $result = $this->grammar->validateFilterChainRich('| unknown_filter');
        
        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
        $this->assertSame('UNKNOWN_FILTER', $result->getErrors()[0]->code);
    }
    
    // =========================================================================
    // COMPONENT VALIDATION TESTS
    // =========================================================================
    
    public function testValidateComponentPropsRequired(): void
    {
        // ikb_image requires src and alt
        $errors = $this->grammar->validateComponentProps('ikb_image', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires prop "src"', $errors[0]);
    }
    
    public function testValidateComponentPropsValid(): void
    {
        $errors = $this->grammar->validateComponentProps('ikb_image', [
            'src' => 'https://example.com/image.jpg',
            'alt' => 'Test image',
        ]);
        $this->assertEmpty($errors);
    }
    
    public function testValidateComponentPropsUnknownAllowed(): void
    {
        // Unknown props should be allowed for extensibility
        $errors = $this->grammar->validateComponentProps('ikb_card', [
            'custom_prop' => 'value',
        ]);
        $this->assertEmpty($errors);
    }
    
    public function testValidateSlotsLeafComponent(): void
    {
        // ikb_image is a leaf component - cannot have children
        $errors = $this->grammar->validateSlots('ikb_image', ['default' => 'content']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('leaf component', $errors[0]);
    }
    
    public function testValidateSlotsNonLeafComponent(): void
    {
        // ikb_section can have children
        $errors = $this->grammar->validateSlots('ikb_section', ['default' => 'content']);
        $this->assertEmpty($errors);
    }
    
    // =========================================================================
    // TAG VALIDATION TESTS
    // =========================================================================
    
    public function testValidateTagValid(): void
    {
        $tag = [
            'name' => 'ikb_card',
            'attrs' => ['title' => 'Test'],
        ];
        
        $errors = $this->grammar->validateTag($tag);
        $this->assertEmpty($errors);
    }
    
    public function testValidateTagInvalidName(): void
    {
        $tag = [
            'name' => '123invalid',
            'attrs' => [],
        ];
        
        $errors = $this->grammar->validateTag($tag);
        $this->assertNotEmpty($errors);
    }
    
    public function testValidateTagSelfClosingWithChildren(): void
    {
        $tag = [
            'name' => 'ikb_image',
            'selfClosing' => true,
            'children' => [['type' => 'text', 'value' => 'content']],
            'attrs' => ['src' => 'test.jpg', 'alt' => 'test'],
        ];
        
        $errors = $this->grammar->validateTag($tag);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cannot have children', $errors[0]);
    }
    
    // =========================================================================
    // STRUCTURE VALIDATION TESTS
    // =========================================================================
    
    public function testValidateStructureValid(): void
    {
        $ast = [
            'type' => 'document',
            'children' => [
                [
                    'type' => 'tag',
                    'name' => 'ikb_section',
                    'attrs' => [],
                    'children' => [],
                ],
            ],
        ];
        
        $errors = $this->grammar->validateStructure($ast);
        $this->assertEmpty($errors);
    }
    
    public function testValidateStructureInvalidRoot(): void
    {
        $ast = [
            'type' => 'tag',
            'name' => 'ikb_section',
        ];
        
        $errors = $this->grammar->validateStructure($ast);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('document node', $errors[0]);
    }
    
    // =========================================================================
    // CMS/PLATFORM DECLARATION TESTS
    // =========================================================================
    
    public function testValidateCMSDeclarationValid(): void
    {
        $errors = $this->grammar->validateCMSDeclaration([
            'type' => 'wordpress',
            'set' => 'filters,components',
        ]);
        $this->assertEmpty($errors);
    }
    
    public function testValidateCMSDeclarationInvalidType(): void
    {
        $errors = $this->grammar->validateCMSDeclaration([
            'type' => 'invalid_cms',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid CMS type', $errors[0]);
    }
    
    public function testValidatePlatformDeclarationValid(): void
    {
        $errors = $this->grammar->validatePlatformDeclaration([
            'type' => 'web',
            'targets' => 'wordpress,joomla',
            'version' => '1.0.0',
        ]);
        $this->assertEmpty($errors);
    }
    
    public function testValidatePlatformDeclarationInvalidVersion(): void
    {
        $errors = $this->grammar->validatePlatformDeclaration([
            'type' => 'web',
            'version' => 'invalid',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid version format', $errors[0]);
    }
    
    // =========================================================================
    // IDENTIFIER VALIDATION TESTS
    // =========================================================================
    
    public function testValidateIdentifier(): void
    {
        $this->assertTrue($this->grammar->validateIdentifier('valid_name'));
        $this->assertTrue($this->grammar->validateIdentifier('_private'));
        $this->assertTrue($this->grammar->validateIdentifier('camelCase'));
        $this->assertFalse($this->grammar->validateIdentifier('123invalid'));
        $this->assertFalse($this->grammar->validateIdentifier(''));
    }
    
    public function testValidateNamespacedIdentifier(): void
    {
        $this->assertTrue($this->grammar->validateNamespacedIdentifier('wp:query'));
        $this->assertTrue($this->grammar->validateNamespacedIdentifier('mobile:list'));
        $this->assertTrue($this->grammar->validateNamespacedIdentifier('simple_name'));
        $this->assertFalse($this->grammar->validateNamespacedIdentifier(':invalid'));
    }
    
    public function testIsReservedKeyword(): void
    {
        $this->assertTrue($this->grammar->isReservedKeyword('if'));
        $this->assertTrue($this->grammar->isReservedKeyword('for'));
        $this->assertTrue($this->grammar->isReservedKeyword('else'));
        $this->assertFalse($this->grammar->isReservedKeyword('custom'));
    }
    
    // =========================================================================
    // PLATFORM VALIDATION TESTS
    // =========================================================================
    
    public function testValidatePlatform(): void
    {
        $this->assertTrue($this->grammar->validatePlatform('wordpress'));
        $this->assertTrue($this->grammar->validatePlatform('react_native'));
        $this->assertFalse($this->grammar->validatePlatform('invalid_platform'));
    }
    
    public function testIsComponentCompatible(): void
    {
        // Universal components work everywhere
        $this->assertTrue($this->grammar->isComponentCompatible('ikb_card', 'wordpress'));
        $this->assertTrue($this->grammar->isComponentCompatible('ikb_card', 'flutter'));
        
        // Namespaced components are platform-specific
        $this->assertTrue($this->grammar->isComponentCompatible('wp:query', 'wordpress'));
        $this->assertFalse($this->grammar->isComponentCompatible('wp:query', 'drupal'));
        
        // Mobile namespace
        $this->assertTrue($this->grammar->isComponentCompatible('mobile:list', 'react_native'));
        $this->assertTrue($this->grammar->isComponentCompatible('mobile:list', 'flutter'));
    }
    
    public function testGetPlatformCategory(): void
    {
        $this->assertSame('web', $this->grammar->getPlatformCategory('wordpress'));
        $this->assertSame('mobile', $this->grammar->getPlatformCategory('flutter'));
        $this->assertSame('desktop', $this->grammar->getPlatformCategory('electron'));
        $this->assertNull($this->grammar->getPlatformCategory('invalid'));
    }
    
    // =========================================================================
    // SECURITY VALIDATION TESTS
    // =========================================================================
    
    public function testValidateSecureOutputWithEscaping(): void
    {
        $result = $this->grammar->validateSecureOutput('{ title | esc_html }');
        $this->assertTrue($result->isValid());
    }
    
    public function testValidateSecureOutputWithoutEscaping(): void
    {
        $this->grammar->setMode(Grammar::MODE_STRICT);
        $result = $this->grammar->validateSecureOutput('{ title }');
        $this->assertFalse($result->isValid());
        $this->assertSame('MISSING_ESCAPING', $result->getErrors()[0]->code);
    }
    
    public function testValidateSecureOutputLenientMode(): void
    {
        $this->grammar->setMode(Grammar::MODE_LENIENT);
        $result = $this->grammar->validateSecureOutput('{ title }');
        // In lenient mode, missing escaping is a warning, not an error
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
    }
    
    // =========================================================================
    // VALIDATION MODE TESTS
    // =========================================================================
    
    public function testSetMode(): void
    {
        $this->grammar->setMode(Grammar::MODE_LENIENT);
        $this->assertSame(Grammar::MODE_LENIENT, $this->grammar->getMode());
        $this->assertFalse($this->grammar->isStrict());
        
        $this->grammar->setMode(Grammar::MODE_STRICT);
        $this->assertSame(Grammar::MODE_STRICT, $this->grammar->getMode());
        $this->assertTrue($this->grammar->isStrict());
    }
    
    public function testSetModeInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->grammar->setMode('invalid_mode');
    }
    
    // =========================================================================
    // RICH VALIDATION API TESTS
    // =========================================================================
    
    public function testValidateTemplateRich(): void
    {
        $ast = [
            'type' => 'document',
            'children' => [
                [
                    'type' => 'tag',
                    'name' => 'ikb_section',
                    'attrs' => [],
                    'line' => 1,
                    'column' => 1,
                    'children' => [
                        [
                            'type' => 'expression',
                            'value' => 'title',
                            'line' => 2,
                            'column' => 5,
                        ],
                    ],
                ],
            ],
        ];
        
        $result = $this->grammar->validateTemplateRich($ast, 'wordpress');
        
        // Should have warnings for unescaped expression
        $this->assertTrue($result->hasWarnings() || !$result->isValid());
    }
    
    public function testValidateComponentPropsRich(): void
    {
        $result = $this->grammar->validateComponentPropsRich('ikb_image', [], 10, 5);
        
        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertSame(10, $errors[0]->line);
        $this->assertSame(5, $errors[0]->column);
    }
    
    // =========================================================================
    // VISUAL BUILDER API TESTS
    // =========================================================================
    
    public function testGetAvailableComponents(): void
    {
        $components = Grammar::getAvailableComponents();
        
        $this->assertNotEmpty($components);
        $this->assertIsArray($components);
        
        // Check structure
        $first = $components[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('props', $first);
    }
    
    public function testGetAvailableComponentsFilterByCategory(): void
    {
        $structural = Grammar::getAvailableComponents(null, 'structural');
        
        foreach ($structural as $component) {
            $this->assertSame('structural', $component['category']);
        }
    }
    
    public function testGetAvailableFilters(): void
    {
        $filters = Grammar::getAvailableFilters();
        
        $this->assertNotEmpty($filters);
        $this->assertIsArray($filters);
        
        // Check structure
        $first = $filters[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('params', $first);
        $this->assertArrayHasKey('returnType', $first);
    }
    
    public function testGetAvailableFiltersFilterByPlatform(): void
    {
        $wpFilters = Grammar::getAvailableFilters('wordpress');
        $drupalFilters = Grammar::getAvailableFilters('drupal');
        
        // WordPress should have wp_trim_words
        $wpNames = array_column($wpFilters, 'name');
        $this->assertContains('wp_trim_words', $wpNames);
        
        // Drupal should have t (translation)
        $drupalNames = array_column($drupalFilters, 'name');
        $this->assertContains('t', $drupalNames);
    }
    
    public function testExportJsonSchema(): void
    {
        $schema = Grammar::exportJsonSchema();
        
        $this->assertArrayHasKey('$schema', $schema);
        $this->assertArrayHasKey('version', $schema);
        $this->assertArrayHasKey('components', $schema);
        $this->assertArrayHasKey('filters', $schema);
        $this->assertArrayHasKey('platforms', $schema);
        $this->assertArrayHasKey('types', $schema);
        
        $this->assertSame(Grammar::SCHEMA_VERSION, $schema['version']);
    }
    
    // =========================================================================
    // VALIDATION ERROR/RESULT TESTS
    // =========================================================================
    
    public function testValidationErrorToString(): void
    {
        $error = new ValidationError(
            'Test error message',
            'TEST_CODE',
            'tag',
            'ikb_card',
            10,
            5
        );
        
        $str = (string) $error;
        $this->assertStringContainsString('TEST_CODE', $str);
        $this->assertStringContainsString('Test error message', $str);
        $this->assertStringContainsString('line 10', $str);
    }
    
    public function testValidationErrorJsonSerialize(): void
    {
        $error = new ValidationError(
            'Test error',
            'TEST',
            'tag',
            'ikb_card',
            10,
            5,
            '<ikb_card>',
            'warning'
        );
        
        $json = $error->jsonSerialize();
        
        $this->assertSame('Test error', $json['message']);
        $this->assertSame('TEST', $json['code']);
        $this->assertSame('warning', $json['severity']);
    }
    
    public function testValidationResultMerge(): void
    {
        $result1 = new ValidationResult();
        $result1->addError(new ValidationError('Error 1', 'E1'));
        
        $result2 = new ValidationResult();
        $result2->addError(new ValidationError('Error 2', 'E2'));
        
        $result1->merge($result2);
        
        $this->assertCount(2, $result1->getErrors());
    }
    
    public function testValidationResultJsonSerialize(): void
    {
        $result = new ValidationResult();
        $result->addError(new ValidationError('Error', 'ERR'));
        $result->addError(new ValidationError('Warning', 'WARN', severity: 'warning'));
        
        $json = $result->jsonSerialize();
        
        $this->assertFalse($json['valid']);
        $this->assertSame(1, $json['errorCount']);
        $this->assertSame(1, $json['warningCount']);
    }
}
