<?php
/**
 * DSL Test Suite
 * 
 * Comprehensive tests for DSL components:
 * - FormatRenderer (all 10 formats)
 * - DSLBridge integration
 * - DSLLogger functionality
 * - RuntimeResolver sanitization
 * - LayoutEngine wrapping
 * 
 * @version 1.2.0
 */

// Autoload DSL classes
spl_autoload_register(function ($class) {
    $prefix = 'IkabudKernel\\DSL\\';
    $base_dir = __DIR__ . '/../';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

use IkabudKernel\DSL\FormatRenderer;
use IkabudKernel\DSL\LayoutEngine;
use IkabudKernel\DSL\DSLBridge;
use IkabudKernel\DSL\DSLLogger;
use IkabudKernel\DSL\RuntimeResolver;

class DSLTest
{
    private array $testData = [];
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    
    public function __construct()
    {
        // Sample test data
        $this->testData = [
            [
                'id' => 1,
                'title' => 'Test Post 1',
                'excerpt' => 'This is a test excerpt for post 1',
                'content' => 'Full content of test post 1',
                'permalink' => 'https://example.com/post-1',
                'date' => '2025-11-22',
                'author' => 'John Doe',
                'thumbnail' => 'https://example.com/image1.jpg',
                'categories' => ['Technology', 'PHP']
            ],
            [
                'id' => 2,
                'title' => 'Test Post 2',
                'excerpt' => 'This is a test excerpt for post 2',
                'content' => 'Full content of test post 2',
                'permalink' => 'https://example.com/post-2',
                'date' => '2025-11-21',
                'author' => 'Jane Smith',
                'thumbnail' => 'https://example.com/image2.jpg',
                'categories' => ['Web Development']
            ],
            [
                'id' => 3,
                'title' => 'Test Post 3',
                'excerpt' => 'This is a test excerpt for post 3',
                'content' => 'Full content of test post 3',
                'permalink' => 'https://example.com/post-3',
                'date' => '2025-11-20',
                'author' => 'Bob Johnson',
                'thumbnail' => 'https://example.com/image3.jpg',
                'categories' => ['Programming', 'Testing']
            ]
        ];
    }
    
    /**
     * Run all tests
     */
    public function runAll(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║           DSL Component Test Suite v1.2.0                 ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        // Format Renderer Tests
        echo "📦 Testing FormatRenderer...\n";
        $this->testCardFormat();
        $this->testListFormat();
        $this->testGridFormat();
        $this->testHeroFormat();
        $this->testMinimalFormat();
        $this->testFullFormat();
        $this->testTimelineFormat();
        $this->testCarouselFormat();
        $this->testTableFormat();
        $this->testAccordionFormat();
        $this->testEmptyData();
        
        // DSLBridge Tests
        echo "\n🌉 Testing DSLBridge...\n";
        $this->testDSLBridgeRenderItems();
        $this->testDSLBridgeNormalization();
        $this->testDSLBridgeShouldUseDSL();
        $this->testDSLBridgeValidation();
        
        // DSLLogger Tests
        echo "\n📝 Testing DSLLogger...\n";
        $this->testDSLLoggerBasic();
        $this->testDSLLoggerLevels();
        $this->testDSLLoggerStats();
        
        // RuntimeResolver Tests
        echo "\n🔒 Testing RuntimeResolver...\n";
        $this->testRuntimeResolverSanitization();
        
        // LayoutEngine Tests
        echo "\n📐 Testing LayoutEngine...\n";
        $this->testLayoutEngine();
        
        // Print results
        $this->printResults();
    }
    
    /**
     * Test card format
     */
    private function testCardFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'card');
        
        $this->assert(
            str_contains($html, 'ikb-dsl-card'),
            'Card format should contain ikb-dsl-card class'
        );
        
        $this->assert(
            str_contains($html, 'Test Post 1'),
            'Card format should contain post title'
        );
        
        $this->assert(
            str_contains($html, 'ikb-dsl-card-title'),
            'Card format should contain title class'
        );
    }
    
    /**
     * Test list format
     */
    private function testListFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'list');
        
        $this->assert(
            str_contains($html, 'ikb-list-item'),
            'List format should contain ikb-list-item class'
        );
        
        $this->assert(
            str_contains($html, 'Test Post 1'),
            'List format should contain post title'
        );
    }
    
    /**
     * Test grid format
     */
    private function testGridFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'grid');
        
        $this->assert(
            str_contains($html, 'ikb-dsl-card'),
            'Grid format should use card rendering (layout handled by LayoutEngine)'
        );
    }
    
    /**
     * Test hero format
     */
    private function testHeroFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'hero');
        
        $this->assert(
            str_contains($html, 'ikb-hero'),
            'Hero format should contain ikb-hero class'
        );
        
        $this->assert(
            str_contains($html, 'Test Post 1'),
            'Hero format should render first item'
        );
    }
    
    /**
     * Test minimal format
     */
    private function testMinimalFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'minimal');
        
        $this->assert(
            str_contains($html, 'ikb-minimal'),
            'Minimal format should contain ikb-minimal class'
        );
    }
    
    /**
     * Test full format
     */
    private function testFullFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'full');
        
        $this->assert(
            str_contains($html, 'ikb-full'),
            'Full format should contain ikb-full class'
        );
        
        $this->assert(
            str_contains($html, 'Full content'),
            'Full format should contain full content'
        );
    }
    
    /**
     * Test timeline format
     */
    private function testTimelineFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'timeline');
        
        $this->assert(
            str_contains($html, 'ikb-timeline'),
            'Timeline format should contain ikb-timeline class'
        );
        
        $this->assert(
            str_contains($html, 'ikb-timeline-marker'),
            'Timeline format should contain marker'
        );
        
        $this->assert(
            str_contains($html, 'ikb-timeline-content'),
            'Timeline format should contain content wrapper'
        );
    }
    
    /**
     * Test carousel format
     */
    private function testCarouselFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'carousel');
        
        $this->assert(
            str_contains($html, 'ikb-carousel'),
            'Carousel format should contain ikb-carousel class'
        );
        
        $this->assert(
            str_contains($html, 'ikb-carousel-prev'),
            'Carousel format should contain prev button'
        );
        
        $this->assert(
            str_contains($html, 'ikb-carousel-next'),
            'Carousel format should contain next button'
        );
        
        $this->assert(
            str_contains($html, 'ikb-carousel-indicators'),
            'Carousel format should contain indicators'
        );
    }
    
    /**
     * Test table format
     */
    private function testTableFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'table');
        
        $this->assert(
            str_contains($html, 'ikb-table'),
            'Table format should contain ikb-table class'
        );
        
        $this->assert(
            str_contains($html, '<thead>'),
            'Table format should contain thead'
        );
        
        $this->assert(
            str_contains($html, '<tbody>'),
            'Table format should contain tbody'
        );
        
        $this->assert(
            str_contains($html, 'John Doe'),
            'Table format should contain author name'
        );
    }
    
    /**
     * Test accordion format
     */
    private function testAccordionFormat(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render($this->testData, 'accordion');
        
        $this->assert(
            str_contains($html, 'ikb-accordion'),
            'Accordion format should contain ikb-accordion class'
        );
        
        $this->assert(
            str_contains($html, 'ikb-accordion-header'),
            'Accordion format should contain header'
        );
        
        $this->assert(
            str_contains($html, 'ikb-accordion-content'),
            'Accordion format should contain content'
        );
        
        $this->assert(
            str_contains($html, 'aria-expanded'),
            'Accordion format should have ARIA attributes'
        );
    }
    
    /**
     * Test empty data
     */
    private function testEmptyData(): void
    {
        $renderer = new FormatRenderer();
        $html = $renderer->render([], 'card');
        
        $this->assert(
            str_contains($html, 'No results found'),
            'Empty data should show no results message'
        );
    }
    
    /**
     * Test DSLBridge renderItems
     */
    private function testDSLBridgeRenderItems(): void
    {
        $attrs = ['format' => 'card', 'layout' => 'grid-3'];
        $html = DSLBridge::renderItems($this->testData, $attrs);
        
        $this->assert(
            !empty($html),
            'DSLBridge should render items'
        );
        
        $this->assert(
            str_contains($html, 'ikb-dsl-card'),
            'DSLBridge should use format renderer'
        );
    }
    
    /**
     * Test DSLBridge normalization
     */
    private function testDSLBridgeNormalization(): void
    {
        $rawItem = [
            'ID' => 123,
            'post_title' => 'Raw Post',
            'post_excerpt' => 'Raw excerpt'
        ];
        
        $normalized = DSLBridge::normalizeItem($rawItem, 'wordpress');
        
        $this->assert(
            $normalized['id'] === 123,
            'DSLBridge should normalize ID field'
        );
        
        $this->assert(
            $normalized['title'] === 'Raw Post',
            'DSLBridge should normalize title field'
        );
    }
    
    /**
     * Test DSLBridge shouldUseDSL
     */
    private function testDSLBridgeShouldUseDSL(): void
    {
        $this->assert(
            DSLBridge::shouldUseDSL(['format' => 'card']),
            'DSLBridge should detect format attribute'
        );
        
        $this->assert(
            !DSLBridge::shouldUseDSL(['type' => 'post']),
            'DSLBridge should not use DSL without format'
        );
    }
    
    /**
     * Test DSLBridge validation
     */
    private function testDSLBridgeValidation(): void
    {
        $this->assert(
            DSLBridge::isValidCombination('card', 'grid-3'),
            'Card with grid-3 should be valid'
        );
        
        $this->assert(
            !DSLBridge::isValidCombination('hero', 'grid-3'),
            'Hero with grid-3 should be invalid'
        );
        
        $this->assert(
            !DSLBridge::isValidCombination('table', 'slider'),
            'Table with slider should be invalid'
        );
    }
    
    /**
     * Test DSLLogger basic functionality
     */
    private function testDSLLoggerBasic(): void
    {
        DSLLogger::clear();
        DSLLogger::enable();
        
        DSLLogger::info('Test message', ['key' => 'value']);
        
        $logs = DSLLogger::getLogs();
        
        $this->assert(
            count($logs) === 1,
            'DSLLogger should store log entry'
        );
        
        $this->assert(
            $logs[0]['message'] === 'Test message',
            'DSLLogger should store correct message'
        );
        
        $this->assert(
            $logs[0]['level'] === 'info',
            'DSLLogger should store correct level'
        );
    }
    
    /**
     * Test DSLLogger levels
     */
    private function testDSLLoggerLevels(): void
    {
        DSLLogger::clear();
        DSLLogger::enable();
        DSLLogger::setLevel('warning');
        
        DSLLogger::debug('Debug message');
        DSLLogger::info('Info message');
        DSLLogger::warning('Warning message');
        DSLLogger::error('Error message');
        
        $logs = DSLLogger::getLogs();
        
        $this->assert(
            count($logs) === 2,
            'DSLLogger should filter by level (warning and error only)'
        );
    }
    
    /**
     * Test DSLLogger stats
     */
    private function testDSLLoggerStats(): void
    {
        DSLLogger::clear();
        DSLLogger::enable();
        DSLLogger::setLevel('debug');
        
        DSLLogger::info('Info 1');
        DSLLogger::info('Info 2');
        DSLLogger::error('Error 1');
        
        $stats = DSLLogger::getStats();
        
        $this->assert(
            $stats['total'] === 3,
            'DSLLogger stats should show total count'
        );
        
        $this->assert(
            $stats['by_level']['info'] === 2,
            'DSLLogger stats should count by level'
        );
    }
    
    /**
     * Test RuntimeResolver sanitization
     */
    private function testRuntimeResolverSanitization(): void
    {
        $resolver = new RuntimeResolver();
        
        // Test with XSS attempt
        $context = [
            'GET' => ['name' => '<script>alert("xss")</script>']
        ];
        
        $resolver->setContext($context);
        
        $ast = [
            'attributes' => [
                'title' => [
                    'type' => 'placeholder',
                    'source' => 'GET',
                    'key' => 'name'
                ]
            ]
        ];
        
        $resolved = $resolver->resolve($ast);
        
        $this->assert(
            !str_contains($resolved['attributes']['title'], '<script>'),
            'RuntimeResolver should sanitize XSS attempts'
        );
        
        $this->assert(
            str_contains($resolved['attributes']['title'], '&lt;script&gt;'),
            'RuntimeResolver should use htmlspecialchars'
        );
    }
    
    /**
     * Test LayoutEngine
     */
    private function testLayoutEngine(): void
    {
        $layoutEngine = new LayoutEngine();
        $content = '<div>Test content</div>';
        
        $wrapped = $layoutEngine->wrap($content, 'grid-3', ['gap' => 'medium']);
        
        $this->assert(
            str_contains($wrapped, 'ikb-layout'),
            'LayoutEngine should add layout class'
        );
        
        $this->assert(
            str_contains($wrapped, 'ikb-grid-cols-3'),
            'LayoutEngine should add grid columns class'
        );
        
        $this->assert(
            str_contains($wrapped, 'Test content'),
            'LayoutEngine should preserve content'
        );
    }
    
    /**
     * Assert helper
     */
    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✓ {$message}\n";
        } else {
            $this->failed++;
            $this->failures[] = $message;
            echo "  ✗ {$message}\n";
        }
    }
    
    /**
     * Print test results
     */
    private function printResults(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║                     Test Results                          ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "Total Tests:  {$total}\n";
        echo "Passed:       {$this->passed} ✓\n";
        echo "Failed:       {$this->failed} ✗\n";
        echo "Success Rate: {$percentage}%\n";
        
        if ($this->failed > 0) {
            echo "\n❌ Failed Tests:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
            echo "\n";
            exit(1);
        } else {
            echo "\n✅ All tests passed!\n\n";
            exit(0);
        }
    }
}

// Run tests
$test = new DSLTest();
$test->runAll();
