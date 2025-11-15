# DiSyL Conversion & AI Integration Roadmap

**Version:** 1.0.0  
**Status:** Planning Phase  
**Target Timeline:** 13 weeks  
**Last Updated:** November 14, 2025

---

## 🎯 Executive Summary

This document outlines the strategic roadmap for expanding DiSyL's capabilities through:

1. **Multi-CMS Renderer Support** - Joomla and Drupal adapters
2. **Automated Theme Conversion** - WordPress PHP → DiSyL transformation
3. **AI-Powered Development** - Intelligent code generation and optimization

### Strategic Vision

```
DiSyL Kernel (Universal Template Engine)
    ↓
┌───────────┬──────────────┬─────────────┬─────────────┐
│ WordPress │ Ikabud CMS   │ Joomla      │ Drupal      │
│ (Active)  │ (Native)     │ (Phase 3)   │ (Phase 3)   │
└───────────┴──────────────┴─────────────┴─────────────┘
    ↓
AI-Powered Conversion & Generation Layer
```

**Goal:** Write once in DiSyL, deploy to any PHP CMS/framework.

---

## 📋 Table of Contents

1. [Phase 1: Core Converter (4 weeks)](#phase-1-core-converter)
2. [Phase 2: AI Integration (3 weeks)](#phase-2-ai-integration)
3. [Phase 3: Multi-CMS Support (6 weeks)](#phase-3-multi-cms-support)
4. [Technical Architecture](#technical-architecture)
5. [Implementation Details](#implementation-details)
6. [Success Metrics](#success-metrics)

---

## Phase 1: Core Converter

**Duration:** 4 weeks  
**Goal:** Build automated WordPress theme → DiSyL converter

### Week 1-2: Parser & Rule Engine

#### Deliverables
- PHP parser integration (nikic/php-parser)
- AST analysis for WordPress functions
- Conversion rule engine
- Basic mapping system

#### Technical Components

```php
/kernel/DiSyL/Converter/
├── WPAnalyzer.php          # Analyzes WordPress theme structure
├── ASTParser.php           # Parses PHP into AST
├── ConversionRules.php     # Mapping rules WP → DiSyL
├── DiSyLGenerator.php      # Generates .disyl files
└── ConversionReport.php    # Human-readable report
```

#### Conversion Rule Examples

```php
class ConversionRules
{
    private array $simpleMappings = [
        // Direct function mappings
        'the_title()' => '{post.title}',
        'the_content()' => '{post.content}',
        'the_excerpt()' => '{post.excerpt}',
        'get_permalink()' => '{post.url}',
        'the_author()' => '{post.author}',
        'the_date()' => '{post.date}',
        
        // Conditional functions
        'has_post_thumbnail()' => 'post.thumbnail',
        'is_home()' => 'is_home',
        'is_single()' => 'is_single',
        
        // Filters
        'esc_html($x)' => '$x | esc_html',
        'esc_url($x)' => '$x | esc_url',
        'esc_attr($x)' => '$x | esc_attr',
        'wp_trim_words($x, $n)' => '$x | wp_trim_words:num_words=$n',
    ];
    
    private array $complexPatterns = [
        // WP_Query → ikb_query
        'WP_Query' => [
            'pattern' => '/new WP_Query\((.*?)\)/',
            'handler' => 'convertWPQuery'
        ],
        
        // Loop structures
        'have_posts_loop' => [
            'pattern' => '/while\s*\(\s*have_posts\(\)\s*\)/',
            'handler' => 'convertLoop'
        ],
        
        // Template parts
        'get_template_part' => [
            'pattern' => '/get_template_part\((.*?)\)/',
            'handler' => 'convertInclude'
        ]
    ];
}
```

### Week 3: Core Conversions

#### Focus Areas
1. **Template Structure**
   - header.php → header.disyl
   - footer.php → footer.disyl
   - sidebar.php → sidebar.disyl

2. **Loop Conversions**
   - WP_Query → {ikb_query}
   - have_posts() loops → DiSyL iteration
   - Custom queries → Component-based queries

3. **Conditional Logic**
   - if/else → {if condition="..."}
   - has_post_thumbnail() → {if condition="post.thumbnail"}
   - WordPress conditional tags → DiSyL conditions

#### Example Conversion

**Input (WordPress PHP):**
```php
<?php if (have_posts()): while (have_posts()): the_post(); ?>
    <article class="post">
        <?php if (has_post_thumbnail()): ?>
            <div class="thumbnail">
                <img src="<?php echo get_the_post_thumbnail_url(); ?>" 
                     alt="<?php echo esc_attr(get_the_title()); ?>">
            </div>
        <?php endif; ?>
        
        <h2><?php the_title(); ?></h2>
        
        <div class="meta">
            <span><?php the_date('F j, Y'); ?></span>
            <span><?php the_author(); ?></span>
        </div>
        
        <div class="excerpt">
            <?php echo wp_trim_words(get_the_excerpt(), 25); ?>
        </div>
        
        <a href="<?php the_permalink(); ?>">Read More</a>
    </article>
<?php endwhile; endif; ?>
```

**Output (DiSyL):**
```disyl
{ikb_query type="post"}
    <article class="post">
        {if condition="item.thumbnail"}
            <div class="thumbnail">
                {ikb_image 
                    src="{item.thumbnail | esc_url}"
                    alt="{item.title | esc_attr}"
                    lazy=true
                /}
            </div>
        {/if}
        
        {ikb_text size="xl" weight="bold"}
            {item.title | esc_html}
        {/ikb_text}
        
        <div class="meta">
            <span>{item.date | date:format='F j, Y'}</span>
            <span>{item.author | esc_html}</span>
        </div>
        
        <div class="excerpt">
            {item.excerpt | wp_trim_words:num_words=25}
        </div>
        
        <a href="{item.url | esc_url}">Read More</a>
    </article>
{/ikb_query}
```

### Week 4: Testing & Validation

#### Test Targets
- Twenty Twenty-Four theme
- Astra theme
- GeneratePress theme
- Custom theme samples

#### Validation Criteria
- ✅ Syntax correctness (100%)
- ✅ Semantic equivalence (95%+)
- ✅ Performance parity
- ⚠️ Manual review required for complex logic

#### Deliverables
- CLI tool: `disyl convert`
- Conversion report generator
- Test suite (50+ test cases)
- Documentation

---

## Phase 2: AI Integration

**Duration:** 3 weeks  
**Goal:** Add AI-powered conversion and generation capabilities

### Week 1: LLM API Integration

#### Components

```php
/kernel/DiSyL/AI/
├── LLMClient.php           # OpenAI/Claude API wrapper
├── PromptEngine.php        # Prompt templates & engineering
├── ContextBuilder.php      # Build context for LLM
├── ValidationEngine.php    # Validate AI-generated code
└── FeedbackLoop.php        # Learn from corrections
```

#### LLM Integration

```php
class LLMClient
{
    private string $apiKey;
    private string $model = 'gpt-4-turbo'; // or 'claude-3-opus'
    
    public function convertComplexLogic(string $phpCode, array $context = []): string
    {
        $prompt = $this->buildPrompt($phpCode, $context);
        
        $response = $this->callAPI([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2, // Low for consistency
            'max_tokens' => 2000
        ]);
        
        return $this->extractCode($response);
    }
    
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an expert at converting WordPress PHP templates to DiSyL syntax.

DiSyL Grammar:
- Tags: {tagname attr="value"}...{/tagname}
- Expressions: {variable.property | filter:param=value}
- Conditionals: {if condition="expression"}...{/if}
- Loops: {ikb_query type="post"}...{/ikb_query}

Rules:
1. Preserve semantic meaning
2. Use DiSyL components (ikb_image, ikb_text, etc.)
3. Apply security filters (esc_html, esc_url, esc_attr)
4. Optimize for readability
5. Add comments for complex logic

Output only valid DiSyL code, no explanations.
PROMPT;
    }
}
```

### Week 2: Training Data & Prompt Engineering

#### Training Dataset Structure

```
/training-data/
├── conversions/
│   ├── simple/              # 100+ simple conversions
│   ├── medium/              # 50+ medium complexity
│   └── complex/             # 20+ complex patterns
├── examples/
│   ├── wordpress-php/       # Source files
│   └── disyl/               # Target files
└── validation/
    └── test-cases.json      # Validation pairs
```

#### Prompt Templates

```php
class PromptEngine
{
    public function buildConversionPrompt(string $code, array $context): string
    {
        $examples = $this->getRelevantExamples($code);
        
        return <<<PROMPT
Convert this WordPress PHP template to DiSyL syntax.

Context:
- Theme: {$context['theme_name']}
- File: {$context['file_name']}
- Dependencies: {$context['dependencies']}

Examples of similar conversions:
{$examples}

Code to convert:
```php
{$code}
```

Output DiSyL code:
PROMPT;
    }
    
    private function getRelevantExamples(string $code): string
    {
        // Use embeddings to find similar examples
        $embedding = $this->getEmbedding($code);
        $similar = $this->vectorSearch($embedding, limit: 3);
        
        return $this->formatExamples($similar);
    }
}
```

### Week 3: Validation & Feedback Loop

#### AI Validation Pipeline

```
User Code (PHP)
    ↓
AI Conversion
    ↓
Syntax Validation (Parser)
    ↓
Semantic Validation (Render Test)
    ↓
Security Scan (XSS, SQL Injection)
    ↓
Performance Check
    ↓
Human Review (if confidence < 90%)
    ↓
Final DiSyL Code
```

#### Confidence Scoring

```php
class ValidationEngine
{
    public function scoreConversion(string $original, string $converted): array
    {
        return [
            'syntax_valid' => $this->validateSyntax($converted),
            'semantic_match' => $this->compareSemantics($original, $converted),
            'security_score' => $this->securityScan($converted),
            'performance_score' => $this->performanceCheck($converted),
            'confidence' => $this->calculateConfidence(),
            'requires_review' => $this->confidence < 0.90
        ];
    }
}
```

---

## Phase 3: Multi-CMS Support

**Duration:** 6 weeks  
**Goal:** Add Joomla and Drupal renderers + converters

### Week 1-3: Joomla Support

#### Architecture

```php
/kernel/DiSyL/Renderers/
└── JoomlaRenderer.php

/kernel/DiSyL/Adapters/
└── JoomlaAdapter.php

/kernel/DiSyL/Converter/
└── JoomlaConverter.php
```

#### Joomla Renderer Implementation

```php
namespace IkabudKernel\Core\DiSyL\Renderers;

class JoomlaRenderer extends BaseRenderer
{
    protected function renderQuery(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'article';
        $limit = $attrs['limit'] ?? 10;
        
        // Joomla database query
        $db = \JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__content')
            ->where('state = 1')
            ->setLimit($limit);
        
        $db->setQuery($query);
        $items = $db->loadObjectList();
        
        return $this->renderLoop($items, $children);
    }
    
    protected function evaluateExpression(string $expr): mixed
    {
        // Map DiSyL expressions to Joomla data
        if (preg_match('/^item\.(\w+)$/', $expr, $matches)) {
            $field = $matches[1];
            
            return match($field) {
                'title' => $this->currentItem->title,
                'content' => $this->currentItem->introtext,
                'url' => \JRoute::_('index.php?option=com_content&view=article&id=' . $this->currentItem->id),
                'date' => $this->currentItem->created,
                'author' => $this->getAuthorName($this->currentItem->created_by),
                default => $this->currentItem->$field ?? ''
            };
        }
        
        return parent::evaluateExpression($expr);
    }
}
```

#### Joomla Mapping

| DiSyL | Joomla |
|-------|--------|
| `{ikb_query type="post"}` | `SELECT * FROM #__content` |
| `{item.title}` | `$article->title` |
| `{item.content}` | `$article->introtext` |
| `{item.url}` | `JRoute::_('index.php?...')` |
| `{item.author}` | `JFactory::getUser($article->created_by)->name` |

### Week 4-6: Drupal Support

#### Architecture

```php
/kernel/DiSyL/Renderers/
└── DrupalRenderer.php

/kernel/DiSyL/Adapters/
└── DrupalAdapter.php

/kernel/DiSyL/Converter/
└── DrupalConverter.php
```

#### Drupal Renderer Implementation

```php
namespace IkabudKernel\Core\DiSyL\Renderers;

class DrupalRenderer extends BaseRenderer
{
    protected function renderQuery(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'node';
        $limit = $attrs['limit'] ?? 10;
        
        // Drupal Entity Query
        $query = \Drupal::entityQuery('node')
            ->condition('type', $type)
            ->condition('status', 1)
            ->range(0, $limit)
            ->sort('created', 'DESC');
        
        $nids = $query->execute();
        $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
        
        return $this->renderLoop($nodes, $children);
    }
    
    protected function evaluateExpression(string $expr): mixed
    {
        // Map DiSyL expressions to Drupal entities
        if (preg_match('/^item\.(\w+)$/', $expr, $matches)) {
            $field = $matches[1];
            $node = $this->currentItem;
            
            return match($field) {
                'title' => $node->getTitle(),
                'content' => $node->get('body')->value,
                'url' => $node->toUrl()->toString(),
                'date' => $node->getCreatedTime(),
                'author' => $node->getOwner()->getDisplayName(),
                'thumbnail' => $this->getThumbnailUrl($node),
                default => $node->get($field)->value ?? ''
            };
        }
        
        return parent::evaluateExpression($expr);
    }
}
```

#### Drupal Mapping

| DiSyL | Drupal |
|-------|--------|
| `{ikb_query type="post"}` | `entityQuery('node')->condition('type', 'article')` |
| `{item.title}` | `$node->getTitle()` |
| `{item.content}` | `$node->get('body')->value` |
| `{item.url}` | `$node->toUrl()->toString()` |
| `{item.author}` | `$node->getOwner()->getDisplayName()` |

---

## Technical Architecture

### System Overview

```
┌─────────────────────────────────────────────────────────┐
│                    DiSyL Converter CLI                   │
│  $ disyl convert <theme-path> --cms=wordpress --ai      │
└─────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────┐
│                   Theme Analyzer                         │
│  • Detect CMS type                                       │
│  • Parse file structure                                  │
│  • Identify dependencies                                 │
│  • Extract template logic                                │
└─────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────┐
│                 Conversion Engine                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ Rule-Based   │  │ AI-Powered   │  │ Hybrid       │  │
│  │ Converter    │  │ Converter    │  │ Approach     │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
└─────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────┐
│                 Validation Pipeline                      │
│  • Syntax validation (Parser)                            │
│  • Semantic validation (Render test)                     │
│  • Security scan (XSS, SQLi)                             │
│  • Performance check                                     │
│  • Confidence scoring                                    │
└─────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────┐
│                   Output Generator                       │
│  • Generate .disyl files                                 │
│  • Create component classes                              │
│  • Generate conversion report                            │
│  • Flag items for manual review                          │
└─────────────────────────────────────────────────────────┘
```

### Directory Structure

```
/kernel/DiSyL/
├── Converter/
│   ├── Analyzers/
│   │   ├── WordPressAnalyzer.php
│   │   ├── JoomlaAnalyzer.php
│   │   └── DrupalAnalyzer.php
│   ├── Converters/
│   │   ├── RuleBasedConverter.php
│   │   ├── AIConverter.php
│   │   └── HybridConverter.php
│   ├── Rules/
│   │   ├── WordPressRules.php
│   │   ├── JoomlaRules.php
│   │   └── DrupalRules.php
│   ├── Validators/
│   │   ├── SyntaxValidator.php
│   │   ├── SemanticValidator.php
│   │   └── SecurityValidator.php
│   └── Generators/
│       ├── DiSyLGenerator.php
│       ├── ComponentGenerator.php
│       └── ReportGenerator.php
├── AI/
│   ├── LLMClient.php
│   ├── PromptEngine.php
│   ├── ContextBuilder.php
│   ├── ValidationEngine.php
│   └── FeedbackLoop.php
├── Renderers/
│   ├── BaseRenderer.php
│   ├── WordPressRenderer.php
│   ├── JoomlaRenderer.php
│   └── DrupalRenderer.php
└── CLI/
    ├── ConvertCommand.php
    ├── AnalyzeCommand.php
    └── ValidateCommand.php
```

---

## Implementation Details

### CLI Tool Interface

```bash
# Convert WordPress theme
$ disyl convert /path/to/wp-theme \
    --output=my-disyl-theme \
    --cms=wordpress \
    --ai-assist \
    --confidence-threshold=0.85

# Analyze theme without conversion
$ disyl analyze /path/to/theme --cms=wordpress

# Validate DiSyL theme
$ disyl validate /path/to/disyl-theme

# Generate conversion report
$ disyl report /path/to/conversion-log.json --format=html
```

### Conversion Workflow

```php
// CLI Command: disyl convert
class ConvertCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $themePath = $input->getArgument('theme-path');
        $cms = $input->getOption('cms');
        $useAI = $input->getOption('ai-assist');
        
        // 1. Analyze theme
        $analyzer = $this->getAnalyzer($cms);
        $analysis = $analyzer->analyze($themePath);
        
        // 2. Choose conversion strategy
        $converter = $useAI 
            ? new HybridConverter($analysis)
            : new RuleBasedConverter($analysis);
        
        // 3. Convert files
        $results = [];
        foreach ($analysis->getTemplateFiles() as $file) {
            $result = $converter->convert($file);
            $results[] = $result;
            
            // Show progress
            $output->writeln(sprintf(
                '%s %s → %s (confidence: %.1f%%)',
                $result->isSuccess() ? '✓' : '⚠',
                $file->getRelativePath(),
                $result->getOutputPath(),
                $result->getConfidence() * 100
            ));
        }
        
        // 4. Generate report
        $report = new ConversionReport($results);
        $report->save($input->getOption('output') . '/conversion-report.html');
        
        // 5. Summary
        $output->writeln("\nConversion Summary:");
        $output->writeln(sprintf("Total files: %d", count($results)));
        $output->writeln(sprintf("Successful: %d", $report->getSuccessCount()));
        $output->writeln(sprintf("Needs review: %d", $report->getReviewCount()));
        
        return Command::SUCCESS;
    }
}
```

### AI-Assisted Conversion Flow

```php
class HybridConverter
{
    public function convert(TemplateFile $file): ConversionResult
    {
        // Try rule-based conversion first
        $ruleResult = $this->ruleConverter->convert($file);
        
        // If confidence is high, use rule-based result
        if ($ruleResult->getConfidence() > 0.90) {
            return $ruleResult;
        }
        
        // Otherwise, use AI for complex parts
        $complexParts = $this->identifyComplexLogic($file);
        
        foreach ($complexParts as $part) {
            $aiResult = $this->aiConverter->convert($part);
            $ruleResult->replace($part, $aiResult);
        }
        
        // Validate combined result
        $validation = $this->validator->validate($ruleResult);
        
        return $ruleResult->withValidation($validation);
    }
}
```

---

## Success Metrics

### Phase 1: Core Converter

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Conversion Accuracy** | 95%+ | Semantic equivalence tests |
| **Syntax Correctness** | 100% | Parser validation |
| **Performance** | < 5s per file | Benchmark suite |
| **Theme Coverage** | 3+ popular themes | Real-world testing |
| **Manual Review Rate** | < 20% | Confidence scoring |

### Phase 2: AI Integration

| Metric | Target | Measurement |
|--------|--------|-------------|
| **AI Accuracy** | 90%+ | Human evaluation |
| **Complex Logic Handling** | 80%+ | Edge case tests |
| **API Response Time** | < 3s | Performance monitoring |
| **Cost per Conversion** | < $0.10 | API usage tracking |
| **User Satisfaction** | 4.5/5 | User surveys |

### Phase 3: Multi-CMS Support

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Joomla Compatibility** | 95%+ | Template rendering tests |
| **Drupal Compatibility** | 95%+ | Entity API integration |
| **Cross-CMS Consistency** | 90%+ | Output comparison |
| **Documentation Coverage** | 100% | Doc completeness |
| **Community Adoption** | 100+ conversions | Usage analytics |

---

## Risk Mitigation

### Technical Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| **AI hallucinations** | High | Validation pipeline, confidence scoring |
| **Complex PHP logic** | Medium | Hybrid approach, manual review |
| **CMS API changes** | Medium | Version detection, adapter pattern |
| **Performance degradation** | Low | Caching, optimization |
| **Security vulnerabilities** | High | Automated security scanning |

### Business Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Low adoption** | High | Marketing, documentation, examples |
| **API costs** | Medium | Caching, rate limiting, local models |
| **Support burden** | Medium | Comprehensive docs, community forum |
| **Competition** | Low | First-mover advantage, quality focus |

---

## Next Steps

### Immediate Actions (Week 1)

1. **Set up development environment**
   - Install nikic/php-parser
   - Configure OpenAI/Claude API
   - Create test suite structure

2. **Create project structure**
   - Implement base classes
   - Set up CLI framework (Symfony Console)
   - Initialize documentation

3. **Build MVP converter**
   - Simple WordPress function mappings
   - Basic template conversion
   - Validation pipeline

### Short-term Goals (Month 1)

- ✅ Complete Phase 1 (Core Converter)
- ✅ Test with 3+ WordPress themes
- ✅ Generate comprehensive reports
- ✅ Document conversion patterns

### Long-term Goals (Quarter 1)

- ✅ Complete Phase 2 (AI Integration)
- ✅ Complete Phase 3 (Multi-CMS Support)
- ✅ Public beta release
- ✅ Community feedback integration

---

## Resources

### Dependencies

```json
{
  "require": {
    "nikic/php-parser": "^5.0",
    "symfony/console": "^7.0",
    "openai-php/client": "^0.8",
    "anthropic/anthropic-sdk-php": "^0.1",
    "pinecone/pinecone-php": "^1.0"
  }
}
```

### External Services

- **OpenAI GPT-4 Turbo** - Complex logic conversion
- **Anthropic Claude 3 Opus** - Alternative LLM
- **Pinecone** - Vector database for semantic search
- **GitHub Actions** - CI/CD pipeline

### Documentation

- [PHP Parser Documentation](https://github.com/nikic/PHP-Parser/blob/master/doc/0_Introduction.markdown)
- [OpenAI API Reference](https://platform.openai.com/docs/api-reference)
- [Symfony Console Component](https://symfony.com/doc/current/components/console.html)

---

## Appendix

### A. Conversion Examples

See [DISYL_CONVERSION_EXAMPLES.md](DISYL_CONVERSION_EXAMPLES.md) for detailed examples.

### B. API Documentation

See [DISYL_CONVERTER_API.md](DISYL_CONVERTER_API.md) for API reference.

### C. Testing Strategy

See [DISYL_CONVERTER_TESTING.md](DISYL_CONVERTER_TESTING.md) for test plans.

---

**Document Version:** 1.0.0  
**Last Updated:** November 14, 2025  
**Maintained By:** Ikabud Kernel Team  
**Status:** Planning Phase
