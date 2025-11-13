# DiSyL Integration Evaluation
**Declarative Ikabud Syntax Language (DiSyL) v0.1**

**Date**: November 12, 2025  
**Status**: 🔍 **EVALUATION & RECOMMENDATION**  
**Version**: 0.1 (draft)

---

## 🎯 Executive Summary

After evaluating the DiSyL grammar specification against the current Ikabud Kernel architecture, I recommend a **dual-layer integration approach**:

1. **Kernel Layer (Core DSL Engine)** - DiSyL parser, compiler, and AST generation
2. **Ikabud CMS Layer (Template System)** - DiSyL-powered standalone CMS with visual builder

This approach maximizes code reuse while maintaining architectural separation between the kernel OS and the CMS application layer.

---

## 📊 Current Architecture Analysis

### Existing Components

#### 1. **Ikabud Kernel** (CMS Operating System)
- **Location**: `/kernel/`, `/cms/`, `/dsl/`
- **Purpose**: Microkernel that boots first, manages CMS instances as OS processes
- **Current DSL**: Query-focused DSL for data operations
  - `QueryCompiler.php` - Lexer → Parser → Resolver → Validator → Optimizer
  - `QueryExecutor.php` - Executes compiled queries against CMS adapters
  - `QueryGrammar.php` - Grammar definitions for query syntax
  - `LayoutEngine.php` - Basic layout rendering
  - `FormatRenderer.php` - Output formatting

#### 2. **CMS Adapters** (Userland Processes)
- **Location**: `/cms/Adapters/`
- **Types**: WordPress, Drupal, Joomla, Native
- **Interface**: `CMSInterface.php` - Unified contract for all CMS operations
- **Factory**: `CMSAdapterFactory.php` - Creates appropriate adapter based on CMS type

#### 3. **Shared Core Architecture**
- **Location**: `/shared-cores/`
- **Purpose**: Single CMS core shared across multiple instances (81MB WordPress → 28KB per instance)
- **Mechanism**: Symlinks + instance-specific `wp-content/`

### Current DSL Capabilities

```php
// Existing query DSL (data-focused)
$query = "type:post limit:10 orderby:date order:desc";
$ast = $compiler->compile($query);
$results = $executor->execute($ast, $cmsAdapter);
```

**Limitations**:
- ✅ Data queries work well
- ❌ No UI component grammar
- ❌ No template/layout syntax
- ❌ No conditional rendering
- ❌ No cross-CMS component abstraction

---

## 🔍 DiSyL Requirements Analysis

### DiSyL Specification Overview

**Purpose**: Human-friendly, declarative language for CMS-driven pages and UI components

**Key Features**:
1. **Declarative Syntax** - What to render, not how
2. **Cross-CMS Compatible** - Single template works across WordPress, Joomla, Drupal
3. **Safe Execution** - No raw PHP/JS, controlled expressions only
4. **Component-Based** - Reusable `ikb_*` components
5. **AST-Driven** - Parses to JSON AST for CMS adapters

### Example DiSyL Template

```disyl
{ikb_section type="hero" title="Welcome"}
  {ikb_block type="grid" cols=3 gap="md"}
    {ikb_query type="post" limit=3}
      {ikb_card title="{item.title}" img="{item.thumbnail}" link="{item.url}" /}
    {/ikb_query}
  {/ikb_block}
{/ikb_section}

{if expr="user.logged_in"}
  {ikb_text}Welcome back, {user.name}!{/ikb_text}
{/if}
```

**Renders to**:
- WordPress: `WP_Query` + theme template parts
- Joomla: `JModelList` + module chrome
- Drupal: Views + block templates
- Native: Direct HTML rendering

---

## 🏗️ Integration Architecture Recommendation

### **Option 1: Kernel-Level Integration** ⭐ **RECOMMENDED**

**Rationale**: DiSyL is a **cross-CMS abstraction layer**, which aligns perfectly with the kernel's role as a CMS-agnostic orchestrator.

#### Implementation Plan

```
ikabud-kernel/
├── kernel/
│   └── DiSyL/                          # NEW: DiSyL Engine
│       ├── Lexer.php                   # Tokenizer for DiSyL syntax
│       ├── Parser.php                  # AST generator
│       ├── Compiler.php                # DiSyL → JSON AST
│       ├── Grammar.php                 # Tag/attribute definitions
│       ├── ComponentRegistry.php       # ikb_* component catalog
│       └── Renderer.php                # AST → CMS-native code
│
├── cms/
│   ├── Adapters/
│   │   ├── WordPressAdapter.php        # EXTEND: Add renderDisyl()
│   │   ├── DrupalAdapter.php           # EXTEND: Add renderDisyl()
│   │   └── NativeAdapter.php           # EXTEND: Add renderDisyl()
│   └── CMSInterface.php                # ADD: renderDisyl(array $ast): string
│
└── dsl/                                # EXISTING: Query DSL
    ├── QueryCompiler.php               # Keep for data queries
    └── [existing files...]
```

#### Benefits

✅ **Kernel-Level Abstraction**: DiSyL becomes a syscall-level service  
✅ **Unified API**: All CMS adapters implement `renderDisyl()`  
✅ **Code Reuse**: Single DiSyL engine serves all CMS types  
✅ **Performance**: Compiled AST cached at kernel level  
✅ **Security**: Kernel validates/sanitizes before CMS execution  
✅ **Extensibility**: Plugin system for custom `ikb_*` components  

#### Implementation Steps

1. **Phase 1: Core Engine** (Week 1-2)
   - Create `kernel/DiSyL/` namespace
   - Implement Lexer, Parser, Compiler per spec
   - Build AST schema (JSON format)
   - Add component registry

2. **Phase 2: CMS Adapters** (Week 2-3)
   - Extend `CMSInterface` with `renderDisyl(array $ast): string`
   - Implement WordPress renderer (AST → `WP_Query` + template parts)
   - Implement Drupal renderer (AST → Views + blocks)
   - Implement Native renderer (AST → HTML)

3. **Phase 3: Integration** (Week 3-4)
   - Add DiSyL syscall: `$kernel->syscall('disyl.render', $template)`
   - Integrate with existing `LayoutEngine.php`
   - Add caching layer for compiled templates
   - Create test suite

4. **Phase 4: Tooling** (Week 4+)
   - CLI tool: `ikabud disyl:compile template.disyl`
   - Visual builder integration
   - Syntax highlighting for editors

---

### **Option 2: Ikabud CMS Layer** (Complementary)

**Rationale**: Create a **standalone Ikabud CMS** that uses DiSyL as its native template language, similar to how WordPress uses PHP templates.

#### Architecture

```
ikabud-kernel/
├── cms/
│   └── Adapters/
│       └── IkabudCMSAdapter.php        # NEW: Native Ikabud CMS
│
└── ikabud-cms/                         # NEW: Standalone CMS (like shared-cores)
    ├── core/
    │   ├── TemplateEngine.php          # DiSyL-powered templates
    │   ├── ContentManager.php          # File-based content
    │   ├── ThemeSystem.php             # DiSyL themes
    │   └── PluginAPI.php               # Extension system
    │
    ├── templates/                      # DiSyL template files
    │   ├── index.disyl
    │   ├── single.disyl
    │   └── archive.disyl
    │
    ├── content/                        # File-based storage (JSON/Markdown)
    │   ├── posts/
    │   ├── pages/
    │   └── media/
    │
    └── themes/                         # DiSyL themes
        └── default/
            ├── theme.json              # Theme manifest
            ├── layout.disyl            # Main layout
            └── components/             # Reusable components
                ├── header.disyl
                ├── footer.disyl
                └── card.disyl
```

#### Key Differences from Shared-Core CMS

| Feature | Shared-Core (WP/Joomla/Drupal) | Ikabud CMS |
|---------|-------------------------------|------------|
| **Storage** | Database (MySQL) | File-based (JSON/Markdown) |
| **Templates** | PHP/Twig | DiSyL (native) |
| **Instances** | Symlinked core + instance config | Standalone or instanced |
| **Size** | 81MB core + 28KB instance | ~5MB core + 10KB instance |
| **Boot** | Full CMS bootstrap | Lightweight kernel integration |
| **Use Case** | Traditional CMS sites | Headless, JAMstack, static-first |

#### Benefits

✅ **Lightweight**: 5MB core vs 81MB WordPress  
✅ **DiSyL-Native**: Templates written in DiSyL from day one  
✅ **File-Based**: No database required (perfect for static/headless)  
✅ **Portable**: Export entire site as DiSyL templates  
✅ **Visual Builder**: DiSyL syntax ideal for drag-and-drop UI  
✅ **Standalone or Instanced**: Can run solo or as kernel-managed process  

---

## 🎨 Recommended Approach: **Dual-Layer Integration**

### Layer 1: Kernel DiSyL Engine (Core)

**Location**: `/kernel/DiSyL/`  
**Purpose**: Universal DiSyL parser, compiler, and renderer  
**Consumers**: All CMS adapters (WordPress, Drupal, Joomla, Native, Ikabud CMS)

```php
// Kernel-level DiSyL service
namespace IkabudKernel\Core\DiSyL;

class DiSyLEngine
{
    public function compile(string $template): array
    {
        $tokens = $this->lexer->tokenize($template);
        $ast = $this->parser->parse($tokens);
        return $this->compiler->compile($ast);
    }
    
    public function render(array $ast, CMSInterface $cms): string
    {
        return $cms->renderDisyl($ast);
    }
}
```

### Layer 2: Ikabud CMS (Application)

**Location**: `/ikabud-cms/` (new directory, peer to `shared-cores/`)  
**Purpose**: Standalone CMS that uses DiSyL as native template language  
**Integration**: Implements `CMSInterface`, managed by kernel like any other CMS

```php
// Ikabud CMS Adapter
namespace IkabudKernel\CMS\Adapters;

class IkabudCMSAdapter implements CMSInterface
{
    public function renderDisyl(array $ast): string
    {
        // Native DiSyL rendering (no translation needed)
        return $this->templateEngine->render($ast);
    }
    
    public function executeQuery(array $query): array
    {
        // File-based content queries
        return $this->contentManager->query($query);
    }
}
```

### Workflow Example

```php
// 1. User creates DiSyL template
$template = file_get_contents('themes/my-theme/index.disyl');

// 2. Kernel compiles to AST
$kernel = Kernel::getInstance();
$ast = $kernel->syscall('disyl.compile', $template);

// 3. CMS adapter renders
$cms = CMSAdapterFactory::create('ikabud-cms');
$html = $cms->renderDisyl($ast);

// 4. Same AST works on WordPress
$wpCms = CMSAdapterFactory::create('wordpress');
$wpHtml = $wpCms->renderDisyl($ast); // Translates to WP_Query + templates
```

---

## 🚀 Implementation Roadmap

### Phase 1: Kernel DiSyL Engine (4 weeks)

**Week 1-2: Core Parser**
- [ ] Create `/kernel/DiSyL/` namespace
- [ ] Implement `Lexer.php` (tokenizer)
- [ ] Implement `Parser.php` (AST generator)
- [ ] Implement `Grammar.php` (tag/attribute definitions)
- [ ] Write unit tests for parser

**Week 3-4: Compiler & Registry**
- [ ] Implement `Compiler.php` (AST optimization)
- [ ] Implement `ComponentRegistry.php` (ikb_* components)
- [ ] Add core components: `ikb_section`, `ikb_block`, `ikb_query`, `ikb_card`, etc.
- [ ] Implement caching layer for compiled ASTs
- [ ] Add `renderDisyl()` to `CMSInterface`

### Phase 2: CMS Adapter Integration (4 weeks)

**Week 5-6: WordPress Adapter**
- [ ] Extend `WordPressAdapter::renderDisyl()`
- [ ] Map `ikb_query` → `WP_Query`
- [ ] Map `ikb_section` → `<section>` with WP classes
- [ ] Map `ikb_card` → WordPress template part
- [ ] Test with real WordPress instance

**Week 7: Drupal & Joomla Adapters**
- [ ] Extend `DrupalAdapter::renderDisyl()`
- [ ] Extend `JoomlaAdapter::renderDisyl()` (create if needed)
- [ ] Map DiSyL components to CMS-native equivalents
- [ ] Cross-CMS compatibility tests

**Week 8: Native Adapter**
- [ ] Extend `NativeAdapter::renderDisyl()`
- [ ] Direct HTML rendering (no CMS translation)
- [ ] Optimize for performance

### Phase 3: Ikabud CMS (6 weeks)

**Week 9-10: Core CMS**
- [ ] Create `/ikabud-cms/` directory structure
- [ ] Implement `IkabudCMSAdapter.php`
- [ ] Build `TemplateEngine.php` (DiSyL-native)
- [ ] Build `ContentManager.php` (file-based storage)
- [ ] Implement `ThemeSystem.php`

**Week 11-12: Content & Storage**
- [ ] Design JSON schema for posts/pages
- [ ] Implement Markdown support for content
- [ ] Build media manager (file uploads)
- [ ] Add content versioning

**Week 13-14: Themes & Plugins**
- [ ] Create default DiSyL theme
- [ ] Build plugin API
- [ ] Add theme customizer
- [ ] Visual builder integration (basic)

### Phase 4: Tooling & Documentation (2 weeks)

**Week 15: CLI & Tools**
- [ ] `ikabud disyl:compile <template>`
- [ ] `ikabud disyl:validate <template>`
- [ ] `ikabud cms:create ikabud-cms <instance-id>`
- [ ] Syntax highlighting for VS Code

**Week 16: Documentation**
- [ ] DiSyL Language Reference
- [ ] Component Catalog
- [ ] Migration guides (WP → DiSyL, etc.)
- [ ] Video tutorials

---

## 📋 Technical Specifications

### DiSyL AST Schema (JSON)

```json
{
  "type": "document",
  "version": "0.1",
  "metadata": {
    "compilation_time_ms": 12.5,
    "cache_key": "ikb_query_abc123",
    "source_file": "index.disyl"
  },
  "children": [
    {
      "type": "tag",
      "name": "ikb_section",
      "attrs": {
        "type": "hero",
        "title": "Welcome",
        "bg": "#f0f0f0"
      },
      "children": [
        {
          "type": "tag",
          "name": "ikb_block",
          "attrs": {
            "type": "grid",
            "cols": 3,
            "gap": "md"
          },
          "children": [
            {
              "type": "tag",
              "name": "ikb_query",
              "attrs": {
                "type": "post",
                "limit": 3,
                "orderby": "date"
              },
              "children": [
                {
                  "type": "tag",
                  "name": "ikb_card",
                  "attrs": {
                    "title": "{item.title}",
                    "img": "{item.thumbnail}",
                    "link": "{item.url}"
                  },
                  "self_closing": true
                }
              ]
            }
          ]
        }
      ],
      "loc": {
        "line": 1,
        "column": 1,
        "start": 0,
        "end": 245
      }
    }
  ],
  "errors": []
}
```

### Component Registry Structure

```php
namespace IkabudKernel\Core\DiSyL;

class ComponentRegistry
{
    private static array $components = [
        'ikb_section' => [
            'category' => 'structural',
            'attributes' => [
                'type' => ['type' => 'string', 'enum' => ['hero', 'content', 'footer']],
                'title' => ['type' => 'string'],
                'bg' => ['type' => 'string'],
                'id' => ['type' => 'string'],
                'class' => ['type' => 'string']
            ],
            'renderer' => 'IkabudKernel\Core\DiSyL\Renderers\SectionRenderer',
            'leaf' => false
        ],
        
        'ikb_query' => [
            'category' => 'data',
            'attributes' => [
                'type' => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'int', 'default' => 10],
                'orderby' => ['type' => 'string', 'default' => 'date'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc']
            ],
            'renderer' => 'IkabudKernel\Core\DiSyL\Renderers\QueryRenderer',
            'leaf' => false,
            'loop' => true // Children repeat for each item
        ],
        
        'ikb_card' => [
            'category' => 'ui',
            'attributes' => [
                'title' => ['type' => 'string', 'required' => true],
                'subtitle' => ['type' => 'string'],
                'img' => ['type' => 'string'],
                'link' => ['type' => 'string'],
                'icon' => ['type' => 'string'],
                'variant' => ['type' => 'string', 'enum' => ['default', 'outlined', 'elevated']]
            ],
            'renderer' => 'IkabudKernel\Core\DiSyL\Renderers\CardRenderer',
            'leaf' => true,
            'self_closing' => true
        ]
    ];
    
    public static function register(string $name, array $config): void
    {
        self::$components[$name] = $config;
    }
    
    public static function get(string $name): ?array
    {
        return self::$components[$name] ?? null;
    }
}
```

---

## 🔒 Security Considerations

### 1. **No Raw Code Execution**
- DiSyL templates NEVER execute arbitrary PHP/JS
- All logic limited to controlled expressions
- Expressions validated against whitelist

### 2. **Expression Sandboxing**
```php
// Safe expressions
{if expr="user.logged_in"}        ✅ Allowed
{if expr="item.price > 100"}      ✅ Allowed

// Unsafe expressions
{if expr="system('rm -rf /')"}    ❌ Blocked
{if expr="eval($_GET['code'])"}   ❌ Blocked
```

### 3. **Attribute Validation**
- All attributes validated against schema
- Type checking enforced
- Enum values restricted
- XSS prevention on output

### 4. **Template Isolation**
- Templates cannot access kernel internals
- CMS adapter provides controlled context
- No direct file system access

---

## 📊 Performance Benchmarks (Projected)

### Compilation Performance

| Operation | Time | Cache Hit |
|-----------|------|-----------|
| Lexer (tokenize) | ~2ms | N/A |
| Parser (AST) | ~5ms | N/A |
| Compiler (optimize) | ~3ms | N/A |
| **Total (cold)** | **~10ms** | - |
| **Total (cached)** | **~0.5ms** | ✅ |

### Rendering Performance

| CMS | DiSyL → Native | Native Template | Overhead |
|-----|----------------|-----------------|----------|
| WordPress | ~15ms | ~12ms | +25% |
| Drupal | ~18ms | ~14ms | +28% |
| Joomla | ~16ms | ~13ms | +23% |
| Ikabud CMS | ~8ms | ~8ms | 0% (native) |

**Mitigation**: AST caching reduces overhead to <5% in production.

---

## 🎯 Success Metrics

### Technical Metrics
- [ ] DiSyL parser handles 100% of spec v0.1 grammar
- [ ] AST compilation < 10ms for typical template
- [ ] Cache hit rate > 95% in production
- [ ] Cross-CMS compatibility: WordPress, Drupal, Joomla, Native
- [ ] Zero security vulnerabilities in expression engine

### Developer Experience
- [ ] Template creation 50% faster than PHP templates
- [ ] Visual builder adoption > 60% of users
- [ ] Component library > 50 reusable components
- [ ] Documentation coverage > 90%

### Business Metrics
- [ ] Ikabud CMS instances > 100 in first 6 months
- [ ] Theme marketplace > 20 DiSyL themes
- [ ] Plugin ecosystem > 30 extensions
- [ ] Community contributions > 10 PRs/month

---

## 🤝 Migration Path

### For Existing Ikabud Kernel Users

**Backward Compatibility**: Existing query DSL remains unchanged.

```php
// Old query DSL (still works)
$query = "type:post limit:10";
$results = $executor->execute($query, $cms);

// New DiSyL templates (opt-in)
$template = "{ikb_query type='post' limit=10}...{/ikb_query}";
$html = $kernel->syscall('disyl.render', $template, $cms);
```

### For WordPress Users

**Step 1**: Install Ikabud Kernel + DiSyL plugin  
**Step 2**: Convert PHP templates to DiSyL (automated tool)  
**Step 3**: Test side-by-side  
**Step 4**: Switch to DiSyL rendering  

**Example Conversion**:

```php
// Before (WordPress PHP template)
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
  <article>
    <h2><?php the_title(); ?></h2>
    <div><?php the_content(); ?></div>
  </article>
<?php endwhile; endif; ?>

// After (DiSyL template)
{ikb_query type="post"}
  <article>
    <h2>{item.title}</h2>
    <div>{item.content}</div>
  </article>
{/ikb_query}
```

---

## 📚 Documentation Structure

```
docs/
├── disyl/
│   ├── GRAMMAR_REFERENCE.md          # Complete language spec
│   ├── COMPONENT_CATALOG.md          # All ikb_* components
│   ├── EXPRESSION_SYNTAX.md          # Conditional logic
│   ├── MIGRATION_GUIDE.md            # PHP → DiSyL conversion
│   ├── WORDPRESS_INTEGRATION.md      # WP-specific guide
│   ├── DRUPAL_INTEGRATION.md         # Drupal-specific guide
│   ├── IKABUD_CMS_GUIDE.md          # Native CMS documentation
│   └── VISUAL_BUILDER.md             # Drag-and-drop UI
```

---

## ✅ Recommendation Summary

### **Primary Recommendation: Kernel-Level Integration**

**Why**:
1. ✅ **Architectural Fit**: DiSyL is a cross-CMS abstraction → belongs in kernel
2. ✅ **Code Reuse**: Single engine serves all CMS types
3. ✅ **Performance**: Kernel-level caching benefits all instances
4. ✅ **Security**: Centralized validation and sandboxing
5. ✅ **Extensibility**: Plugin system at kernel level

**Implementation**: `/kernel/DiSyL/` namespace

### **Secondary Recommendation: Ikabud CMS**

**Why**:
1. ✅ **Showcase**: Demonstrates DiSyL's full potential
2. ✅ **Lightweight**: 5MB vs 81MB WordPress
3. ✅ **Modern**: File-based, headless-ready, JAMstack-friendly
4. ✅ **Differentiation**: Unique selling point vs WordPress/Joomla
5. ✅ **Visual Builder**: DiSyL syntax perfect for drag-and-drop

**Implementation**: `/ikabud-cms/` directory (peer to `shared-cores/`)

### **Timeline**: 16 weeks (4 months)

### **Team Requirements**:
- 1 Senior PHP Developer (kernel integration)
- 1 Frontend Developer (visual builder)
- 1 Technical Writer (documentation)
- 1 QA Engineer (testing)

---

## 🚦 Next Steps

1. **Approve Architecture** - Review and approve dual-layer approach
2. **Create GitHub Issues** - Break roadmap into actionable tasks
3. **Set Up Development Environment** - Create `/kernel/DiSyL/` namespace
4. **Write Grammar Tests** - TDD approach for parser
5. **Prototype Lexer** - First working tokenizer
6. **Weekly Standups** - Track progress against roadmap

---

## 📞 Questions & Clarifications

### Open Questions

1. **Namespace**: Should DiSyL be `IkabudKernel\Core\DiSyL` or `IkabudKernel\DiSyL`?
2. **Versioning**: How to handle DiSyL spec updates (v0.1 → v0.2)?
3. **Plugin API**: Should plugins register components at kernel or CMS level?
4. **Visual Builder**: React-based (admin UI) or standalone tool?
5. **Ikabud CMS**: Separate repo or monorepo with kernel?

### Decisions Needed

- [ ] Approve kernel-level integration
- [ ] Approve Ikabud CMS creation
- [ ] Allocate development resources
- [ ] Set launch timeline
- [ ] Define success metrics

---

**Prepared by**: Cascade AI  
**Date**: November 12, 2025  
**Version**: 1.0  
**Status**: Awaiting approval
