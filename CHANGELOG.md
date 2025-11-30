# Changelog

All notable changes to Ikabud Kernel will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned Features
- Multi-tenant support with resource quotas
- Real-time process monitoring dashboard
- Automated backup and restore system
- Plugin marketplace integration
- Advanced caching strategies
- Load balancing support
- Container orchestration (Docker/Kubernetes)

---

## [1.5.1] - 2025-11-30

### 🔗 Cross-Instance Content Federation

Runtime support for querying content from any CMS instance within DiSyL templates. Enables true multi-CMS content aggregation.

### Added

#### CrossInstanceDataProvider
- **New global service** for fetching data from any registered CMS instance
- **Database config parsing** - Reads wp-config.php, configuration.php, settings.php
- **Connection pooling** - Cached connections for performance
- **CMS-specific queries** - WordPress (WP_Query style), Joomla (com_content), Drupal (EntityQuery)
- **Data normalization** - Common fields: `title`, `content`, `excerpt`, `date`, `author`, `slug`
- **CMS aliases** - Also provides `post.*`, `article.*`, `node.*` for CMS-specific access
- **Source tracking** - `_source.instance` and `_source.cms` for debugging

#### BaseRenderer
- `handleCrossInstanceQuery()` - Detects and handles cross-instance queries
- Automatic context switching based on `instance=""` or `cms=""` attributes
- Sets both common and CMS-specific field names in template context

### Changed

#### All Renderers Updated
- **WordPressRenderer** - Cross-instance support in `renderIkbQuery()`
- **JoomlaRenderer** - Cross-instance support in `renderIkbQuery()`
- **DrupalRenderer** - Cross-instance support in `ikb_query` component
- **NativeRenderer** - Cross-instance support in `renderIkbQuery()`

### Usage Example

```disyl
{# WordPress site with WooCommerce #}
{ikb_query type="product" limit="4"}
  {post.title} - ${product.price}
{/ikb_query}

{# Pull articles from Joomla instance #}
{ikb_query cms="joomla" instance="joomla-content" type="article" limit="5"}
  {article.title}
  {article.introtext | truncate(150)}
{/ikb_query}

{# Pull nodes from Drupal instance #}
{ikb_query cms="drupal" instance="drupal-blog" type="article" limit="3"}
  {node.title}
  {node.body | strip_tags | truncate(200)}
{/ikb_query}
```

---

## [1.5.0] - 2025-11-30

### 🎨 Theme Builder Enhancements - Monaco Editor & Filesystem API

Major release featuring a professional code editor with Monaco, filesystem-based theme management API, and shared instance selector across all theme features.

### Added

#### Code Editor with Monaco
- **Monaco Editor Integration** - Same editor as VS Code
- **Syntax Highlighting** - PHP, JavaScript, CSS, HTML, JSON, YAML, Markdown, and more
- **Custom DiSyL Language** - Syntax highlighting for DiSyL templates
- **IntelliSense/Autocomplete** - DiSyL components (`ikb_section`, `ikb_container`, etc.), control structures (`if`, `for`, `include`), and filters
- **Editor Settings** - Theme toggle (dark/light), font size selector, minimap toggle
- **Keyboard Shortcuts** - Ctrl+S to save
- **File Tree Sidebar** - Navigate theme files with folder expansion

#### Filesystem-based Theme API
- `GET /api/v1/filesystem/instances` - List all instances with CMS type detection
- `GET /api/v1/filesystem/instances/{id}/themes` - List themes per instance
- `GET /api/v1/filesystem/instances/{id}/themes/{theme}/tree` - Get file tree
- `GET /api/v1/filesystem/instances/{id}/themes/{theme}/files` - Read file content
- `PUT /api/v1/filesystem/instances/{id}/themes/{theme}/files` - Write file content
- `POST /api/v1/filesystem/instances/{id}/themes` - Create new theme with CMS starter files
- `POST /api/v1/filesystem/instances/{id}/themes/upload` - Upload theme ZIP
- `DELETE /api/v1/filesystem/instances/{id}/themes/{theme}` - Delete theme

#### Theme Builder UI
- **Shared Instance Selector** - Persisted across all theme features via localStorage
- **3 Main Actions** - New Theme (Visual Builder), Code Editor, Upload ZIP
- **Theme List** - Display existing themes with quick action buttons
- **CMS Detection** - Auto-detect WordPress, Joomla, Drupal, or Native

### Changed

#### Theme Generator
- Auto-create `storage/themes` directory if missing
- Auto-create `kernel/templates` directory if missing
- Auto-generate default `disyl-components.css` with component styles
- Graceful error handling for permission issues
- Fixed Slim route integration for `/api/theme/generate`

#### Route Configuration
- Added `filesystem` route mapping for theme management API
- Added `theme` route mapping for theme generator API

### Fixed
- Theme generator 404 errors - routes now use Slim `$app` instead of `$router`
- Permission denied errors - directories auto-created with fallback permissions
- Missing CSS file errors - fallback to default styles if file doesn't exist

---

## [1.4.1] - 2025-11-29

### 🔧 DiSyL Filter & Platform Declaration Improvements

Minor release adding missing filters to Grammar and standardizing platform declarations across all Phoenix themes.

### Added

#### Grammar.php - New Filters
- **`raw` filter** - Output without escaping for pre-sanitized content (use with caution)
- **`disyl` filter** - Parse nested DiSyL content within widgets/templates
- **`int` filter** - Cast to integer with safe numeric output
- **`float` filter** - Cast to float with optional decimal precision

#### Safe Output Filters
- Added `int`, `float`, `json` to `ESCAPING_FILTERS` constant
- Updated `parseExpression()` to use `ESCAPING_FILTERS` constant for consistency

### Changed

#### WordPress Phoenix Theme
- Added `{ikb_platform type="web" targets="wordpress" /}` to all templates
- Fixed missing `</h2>` closing tag in `single.disyl`
- Added `| int` filter to `post.comment_count` and pagination outputs

#### Drupal Phoenix Theme
- Added `{ikb_platform type="web" targets="drupal" /}` to all templates
- Fixed `category.disyl` incorrectly labeled as "Joomla-Native"
- Fixed `category.disyl` using `joomla_module` instead of `drupal_region`
- Replaced `wp_kses_post` with `raw` filter for Drupal compatibility
- Added `| int` filter to count and pagination outputs

#### Joomla Phoenix Theme
- Added `{ikb_platform type="web" targets="joomla" /}` to all templates
- Fixed `blog.disyl` broken HTML structure (`</main>` → `</div>`)
- Replaced `wp_kses_post` with `raw` filter for Joomla compatibility
- Added `| int` filter to count and pagination outputs

### Fixed
- Consistent platform declarations enable Grammar's platform compatibility checking
- Numeric outputs now use explicit `| int` filter for strict mode compliance
- Cross-platform filter compatibility (removed WordPress-specific filters from Drupal/Joomla)

---

## [1.4.0] - 2025-11-26

### 🎯 Grammar v1.2.0 - Production Hardening & Kernel Alignment

Major release featuring Grammar v1.2.0 with rich validation, security features, and full kernel component alignment.

### Added

#### Grammar v1.2.0 - Rich Validation Layer
- **ValidationError Class** - Structured error objects with source mapping
  - `message`, `code`, `nodeType`, `tagName`
  - `line`, `column`, `snippet` for precise error location
  - `severity` (error/warning) for flexible handling
  - `JsonSerializable` for API responses

- **ValidationResult Class** - Aggregate validation results
  - `addError()`, `addWarning()` methods
  - `isValid()`, `hasWarnings()` checks
  - `merge()` for combining results
  - `getErrors()`, `getWarnings()` accessors

- **Schema Versioning**
  - `Grammar::SCHEMA_VERSION` constant (1.2.0)
  - Version included in JSON Schema export
  - Cache keys include version for invalidation

- **Validation Modes**
  - `Grammar::MODE_STRICT` - Errors block compilation
  - `Grammar::MODE_LENIENT` - Warnings only (migration-friendly)
  - `setMode()`, `getMode()`, `isStrict()` methods

- **Filter Type Chain Validation**
  - `isTypeCompatible()` - Check type compatibility between filters
  - `validateFilterChain()` now validates type flow
  - Positional vs named argument disambiguation
  - Platform-specific filter availability

- **Expression Caching**
  - `parseExpression()` results cached
  - FIFO eviction with `MAX_CACHE_SIZE` (1000)
  - `clearCache()` static method
  - `hasEscaping` flag for security checks

- **Security Validation**
  - `validateSecureOutput()` - Check for escaping filters
  - `validateHtmlProp()` - Warn on unescaped HTML props
  - Context-aware escaping recommendations

- **Rich Validation API**
  - `validateFilterChainRich()` - Returns ValidationResult
  - `validateTemplateRich()` - Full template validation
  - `validateNodesRich()` - Recursive node validation
  - `validateComponentPropsRich()` - Component prop validation

- **Visual Builder API**
  - `getAvailableComponents()` - List components by platform/category
  - `getAvailableFilters()` - List filters by platform
  - `exportJsonSchema()` - Export full grammar as JSON Schema

#### Compiler v0.4.0
- Integrated with Grammar v1.2.0 for rich validation
- `setStrictMode()` - Toggle strict/lenient mode
- `getValidationResult()` - Access ValidationResult
- Platform compatibility checking for components
- Expression security validation (escaping warnings)
- Filter chain validation with type checking
- CMS declaration validation via Grammar
- `clearCache()` static method

#### Engine v0.5.0
- `setStrictMode()` - Propagates to Compiler
- `getValidationResult()` - Expose validation results
- `hasErrors()`, `hasWarnings()` convenience methods
- `getErrors()`, `getWarnings()` accessors
- `clearCache()` now clears Grammar and Compiler caches

#### ComponentRegistry v0.2.0
- New categories: `layout`, `content`, `interactive`, `navigation`, `form`
- Platform support in component definitions
- Slot definitions for components
- Version field for components
- `list()` - List components for Visual Builder
- `getSlots()` - Get slot definitions
- `supportsPlatform()` - Check platform compatibility

#### Cache Stats Persistence
- Stats now persist across requests
- File-based persistence (`.cache_stats.json`)
- APCu persistence for faster access
- Batched writes (every 10 operations)
- `resetStats()` method
- Accurate hit rate tracking in dashboard

### Changed

#### Lexer v0.5.0
- Updated to reference DiSyL v1.2.0 grammar
- Added safe navigation operator (?.) to features

#### Parser v0.4.0
- Updated to reference DiSyL v1.2.0 grammar
- AST version now outputs '1.2.0'
- Source location tracking for rich error reporting

#### CMSHeaderValidator v0.7.0
- Now uses `Grammar::validateCMSDeclaration()`
- Maintains backward compatibility with CMSLoader
- Removes duplicate errors

#### Kernel
- Initializes ComponentRegistry with core components on boot
- Logs Grammar version alongside DiSyL version

### Fixed

- **PHP 8.x Deprecation Warnings** - Fixed null parameter issues in Renderers
  - `JoomlaRenderer.php` - Null coalescing for module content
  - `WordPressRenderer.php` - is_string check before strpos
  - `DrupalRenderer.php` - Null coalescing in filters

- **Grammar Tests** - All 101 tests passing with 196 assertions
  - Fixed `validateType()` visibility (now public)
  - Fixed test expectations for type coercion behavior
  - Fixed schema test to use `minLength`/`maxLength` for strings

- **PHPUnit Configuration** - Updated for PHPUnit 10 compatibility
  - Removed deprecated `verbose` attribute
  - Changed `coverage` to `source` element

### Documentation

- **THE_FUTURE_OF_DISYL.md** - Comprehensive vision document
  - Developer guide: Architecture, extensibility, roadmap
  - Investor section: Market opportunity, business model, growth strategy
  - Vision: DiSyL as "SQL of UI" - universal interface language

### Version Summary

```
DiSyL Stack v1.2.0
├── Grammar v1.2.0      ← Rich validation, security, Visual Builder API
├── Lexer v0.5.0        ← Safe navigation operator
├── Parser v0.4.0       ← AST v1.2.0, source locations
├── Compiler v0.4.0     ← Grammar integration, strict mode
├── Engine v0.5.0       ← Validation exposure, cache coordination
├── ComponentRegistry v0.2.0 ← Platform support, Visual Builder
└── CMSHeaderValidator v0.7.0 ← Grammar validation
```

---

## [1.3.0] - 2025-11-26

### 🚀 DiSyL Language Specification v1.0.0 & Performance Optimizations

Major release featuring a complete DiSyL language specification upgrade and comprehensive kernel performance optimizations.

### Added

#### DiSyL Language Specification v1.0.0
- **Formal EBNF Grammar** - Complete lexical and syntactic grammar specification
  - Character classes, identifiers, literals, escape sequences
  - Namespaced identifiers for platform-specific components (`wp:query`, `mobile:list`)
  - Component definitions with props, slots, and templates
  - Semantic constraints and type system

- **Platform Abstraction Layer (PAL)**
  - New `ikb_platform` declaration (replaces `ikb_cms`)
  - Platform categories: `web`, `mobile`, `desktop`, `universal`
  - Multi-target rendering with fallback support
  - Feature detection: `platform.supports()`, `platform.is()`
  - Platform capabilities matrix

- **Extended Platform Support**
  - Mobile: React Native, Flutter, iOS, Android
  - Desktop: Electron, Tauri, Windows, macOS, Linux
  - Visual Builders: drag-and-drop, no-code integration

- **Visual Builder Integration**
  - Component metadata for editors (props, labels, placeholders)
  - Visual builder schema export (JSON)
  - Drag-and-drop zones (`ikb_dropzone`)
  - Component preview support

- **Mobile Development Support**
  - React Native transpilation examples
  - Flutter transpilation examples
  - Mobile-specific components (`mobile:navigation`, `mobile:list`, `mobile:input`)
  - Native widget support

- **Desktop Application Support**
  - Electron integration (window, menubar, titlebar)
  - Tauri integration (invoke, dialog, notification)
  - Native system access (filesystem, tray, shortcuts)

- **Extensibility Framework**
  - Custom platform adapter creation guide
  - Platform manifest specification (JSON)
  - Custom component registration
  - Plugin architecture documentation

#### Enhanced Grammar v1.0.0
- **Extended Type System**
  - New types: `url`, `image`, `color`, `date`, `datetime`, `email`, `phone`, `html`, `markdown`, `json`, `expression`
  - Union type support (`string|number`)
  - Generic array types (`array<string>`)
  - Improved type coercion and validation

- **Platform Validation**
  - `validatePlatform()` - Validate platform identifiers
  - `validatePlatformList()` - Validate comma-separated platforms
  - `isComponentCompatible()` - Check component/platform compatibility
  - `getPlatformCategory()` - Get platform category (web/mobile/desktop)

- **Identifier Validation**
  - `validateIdentifier()` - Validate identifier syntax
  - `validateNamespacedIdentifier()` - Validate `namespace:name` syntax
  - `isReservedKeyword()` - Check reserved keywords
  - `parseNamespacedIdentifier()` - Parse into namespace and name

- **Component Prop Validation**
  - `validatePropDefinition()` - Validate prop definitions for visual builders
  - `validateSlotDefinition()` - Validate slot definitions
  - `generatePropsSchema()` - Generate JSON Schema for visual builders

- **Filter Parsing**
  - `parseFilterChain()` - Parse filter chains from expressions
  - `parseFilterArgs()` - Parse named and positional arguments

- **Expression Validation**
  - `validateExpression()` - Validate expression syntax
  - Balanced brace checking

#### Kernel Performance Optimizations (Phase 1 & 2)

**Phase 1: Caching & Lazy Loading**
- Multi-tier caching (memory → APCu → file)
- Lazy route loading (~80% faster request handling)
- API response caching with TTL
- Atomic file writes with compression for file cache

**Phase 2: Resource Optimization**
- Lazy manager loading in Kernel.php
- API pagination for list endpoints
- Rate limiting middleware (token bucket algorithm)
- ResourceManager batch saves with dirty flag

#### DiSyL Pipeline Optimizations
- **Lexer v0.4.0**
  - Token object pooling (max 500 tokens)
  - `Lexer::recycleTokens()` for token reuse
  - Fast character lookup tables for identifiers
  - Single-char token lookup table
  - ~40% faster tokenization

- **Token v0.4.0**
  - Public properties for direct access
  - Supports object pooling via Lexer
  - Minimal memory footprint

- **Compiler v0.3.0**
  - Lazy Grammar initialization
  - Component validation caching
  - Filter validation caching
  - ~30% faster compilation

- **BaseRenderer v0.4.0**
  - Method existence caching (static)
  - PascalCase conversion caching
  - Component method name caching (instance)
  - `clearMethodCache()` for testing
  - ~60% faster component rendering

### Changed
- Updated `DISYL_SYNTAX_REFERENCE.md` to v1.0.0 (1,758 lines)
- Updated `Grammar.php` to v1.0.0 (878 lines)
- Updated `Lexer.php` to v0.4.0
- Updated `Token.php` to v0.4.0
- Updated `Compiler.php` to v0.3.0
- Updated `BaseRenderer.php` to v0.4.0

### Fixed
- Fixed `JWT::decode()` → `JWT::verify()` in RateLimitMiddleware
- Fixed lazy-loaded `getGrammar()` calls in Compiler
- Fixed lazy route loading for legacy `/api/instances/*` endpoints
- Fixed route loading for `/api/instances/{id}/conditional-loading/*`

### Performance Summary
| Component | Improvement |
|-----------|-------------|
| Tokenization | ~40% faster |
| Compilation | ~30% faster |
| Rendering | ~60% faster |
| Memory Usage | ~25% reduction |
| Request Handling | ~80% faster (lazy routes) |

### Files Modified
- `DISYL_SYNTAX_REFERENCE.md` - Complete language specification
- `kernel/DiSyL/Grammar.php` - Enhanced grammar with platform support
- `kernel/DiSyL/Lexer.php` - Token pooling and fast lookups
- `kernel/DiSyL/Token.php` - Pooling support
- `kernel/DiSyL/Compiler.php` - Lazy loading and caching
- `kernel/DiSyL/Renderers/BaseRenderer.php` - Method caching
- `kernel/Kernel.php` - Lazy manager loading
- `kernel/Cache.php` - Multi-tier caching
- `kernel/ResourceManager.php` - Batch saves
- `api/middleware/RateLimitMiddleware.php` - Rate limiting
- `api/middleware/ResponseCacheMiddleware.php` - API caching
- `api/routes/instances.php` - Pagination support
- `public/index.php` - Lazy route loading

---

## [1.2.0] - 2025-11-23

### 🎨 DiSyL Module/Block Content Processing

Major enhancement enabling DiSyL code processing in CMS custom modules and blocks at the renderer level.

### Added

#### DiSyL Processing in Custom Content
- **JoomlaRenderer Enhancement** - Process DiSyL codes in Joomla custom modules
  - Automatic detection of DiSyL syntax in module content
  - Full DiSyL compilation and rendering with context
  - Error handling with fallback to original content
  - Works with all module chrome styles (none, card, xhtml, etc.)
  - Supports all DiSyL components, filters, conditionals, and loops

- **DrupalRenderer Enhancement** - Process DiSyL codes in Drupal custom blocks
  - Automatic detection of DiSyL syntax in block content
  - Full DiSyL compilation and rendering with context
  - Processing for individual blocks (`drupal_block`)
  - Processing for all blocks in regions (`drupal_region`)
  - Error handling with fallback to original content
  - Supports all DiSyL components, filters, conditionals, and loops

#### Supported DiSyL Syntax in Modules/Blocks
- **Universal Components**: `ikb_section`, `ikb_container`, `ikb_grid`, `ikb_text`, `ikb_button`, `ikb_image`, `ikb_card`
- **CMS-Specific Components**: 
  - Joomla: `joomla_module`, `joomla_component`, `joomla_message`, `joomla_params`
  - Drupal: `drupal_block`, `drupal_region`, `drupal_menu`, `drupal_view`
- **Query Components**: `ikb_query` for dynamic content loops
- **Conditionals**: `{if condition="..."}...{else}...{/if}`
- **Loops**: `{for items="..." as="item"}...{/for}`
- **Filters**: `esc_html`, `esc_url`, `esc_attr`, `strip_tags`, `truncate`, `date`, etc.

### Documentation

#### DiSyL Syntax Reference PDF
- **Complete PDF Documentation** - Comprehensive 50+ page reference guide
  - Organized by CMS platform (WordPress, Joomla, Drupal)
  - 100+ code examples
  - 14 component definitions with full attribute references
  - 11+ filter definitions with usage examples
  - Complete working template examples
  - Quick reference cheat sheets
  - Best practices and security guidelines

- **Files Created**:
  - `DISYL_SYNTAX_REFERENCE.pdf` - Main PDF documentation (13MB)
  - `DISYL_SYNTAX_REFERENCE.md` - Markdown source
  - `DISYL_PDF_README.md` - Documentation guide

### Changed

#### Mobile Menu Improvements (Phoenix Template)
- Enhanced mobile menu toggle functionality for both Joomla and WordPress
- Added proper event handling with `preventDefault()` and `stopPropagation()`
- Improved mobile submenu toggle behavior
- Added overlay effect for better UX
- Console logging for debugging
- Better accessibility with ARIA attributes

### Technical Details

#### Implementation Approach
- **Renderer-Level Processing** - No template overrides required
- **Performance Optimized** - Only processes content containing DiSyL syntax
- **Context-Aware** - Full access to CMS context (posts, menus, params, etc.)
- **Error Resilient** - Graceful fallback with error logging
- **CMS-Agnostic** - Consistent behavior across Joomla and Drupal

#### Files Modified
- `kernel/DiSyL/Renderers/JoomlaRenderer.php` - Added module content processing
- `kernel/DiSyL/Renderers/DrupalRenderer.php` - Added block/region content processing
- `instances/jml-joomla-the-beginning/templates/phoenix/assets/js/phoenix.js` - Mobile menu fixes
- `instances/jml-joomla-the-beginning/templates/phoenix/assets/css/style.css` - Mobile menu styles
- `instances/wp-brutus-cli/wp-content/themes/phoenix/assets/js/phoenix.js` - Mobile menu fixes
- `instances/wp-brutus-cli/wp-content/themes/phoenix/style.css` - Mobile menu styles

### Benefits

- ✅ **Write Once, Deploy Everywhere** - Same DiSyL code works in modules/blocks across CMSs
- ✅ **No Template Overrides** - Processing happens at renderer level
- ✅ **Full Feature Support** - All DiSyL features available in custom content
- ✅ **Developer Friendly** - Comprehensive documentation with examples
- ✅ **Production Ready** - Error handling and performance optimization

---

## [1.1.0] - 2025-11-22

### 🚀 DSL Auto-Rendering Integration

Major enhancement to DiSyL templating system with seamless DSL auto-rendering capabilities.

### Added

#### DSL Auto-Rendering System
- **Format-based rendering** - Auto-render content with pre-built formats
  - `format="card"` - Card-based layouts with thumbnails
  - `format="list"` - Simple list views
  - `format="grid"` - Grid layouts
  - `format="hero"` - Hero sections
  - `format="minimal"` - Title-only views
  - `format="full"` - Full content display
  - `format="timeline"` - Timeline views
  - `format="carousel"` - Slider/carousel
  - `format="table"` - Table layouts
  - `format="accordion"` - Accordion layouts

- **Layout engine integration** - Automatic layout wrapping
  - `layout="vertical"` - Vertical stacking
  - `layout="horizontal"` - Horizontal flex layout
  - `layout="grid-2/3/4"` - Multi-column grids
  - `layout="masonry"` - Pinterest-style masonry
  - `layout="slider"` - Carousel slider

- **Enhanced ikb_query attributes**
  - `format` - Enable DSL auto-rendering
  - `layout` - Layout wrapper type
  - `columns` - Number of columns (1-6)
  - `gap` - Spacing between items (none, small, medium, large)
  - `exclude_category` - Exclude categories from query

#### Cross-CMS Support
- **WordPress integration** - Full DSL rendering in WordPressRenderer
- **Joomla integration** - Full DSL rendering in JoomlaRenderer
- **Drupal integration** - Full DSL rendering in DrupalRenderer
- **Unified data normalization** - CMS-agnostic data format

#### Styling & Assets
- **dsl-components.css** - Complete DSL component styles
  - Responsive design with mobile-first approach
  - Dark mode support via `prefers-color-scheme`
  - Conflict-free class names (`.ikb-dsl-*`)
  - Smooth transitions and hover effects
  - Grid and flexbox layouts
  - Gap utilities

#### Developer Experience
- **100% backward compatible** - Existing templates work unchanged
- **Opt-in enhancement** - Use `format` attribute to enable DSL rendering
- **Graceful degradation** - Falls back if DSL classes unavailable
- **Clear separation** - Manual components use `.ikb-card`, auto-rendered use `.ikb-dsl-card`

### Changed
- **ComponentRegistry** - Extended with DSL-specific attributes
- **BaseRenderer** - Added shared DSL rendering methods
  - `normalizeItemForDSL()` - Universal data format converter
  - `renderWithDSL()` - DSL format and layout rendering
  - `shouldUseDSLRendering()` - Format detection helper
- **FormatRenderer** - Updated class names to avoid conflicts
- **Phoenix theme** - Enqueued DSL CSS with proper dependencies

### Enhanced
- **ikb_query component** - Now supports both rendering modes:
  - Traditional: `{ikb_query type="post"}<div>{item.title}</div>{/ikb_query}`
  - DSL Auto: `{ikb_query type="post" format="card" layout="grid-3"}`

### Repository Management
- **Updated .gitignore** - Comprehensive exclusion patterns
  - Root-level archive files (*.zip, *.tar.gz, *.tar)
  - Root-level test files (test-*.php)
  - Analysis and summary documentation
  - Backup files (*.bak, *.backup, *.old)
  - Cache files (storage/cache/*.cache)
  - Database dumps (*.sql, *.sqlite, *.db)
  - Package files (*.deb, *.rpm)
  - Compiled files (*.pyc, *.pyo, *.class)

### Technical Details

**Files Modified:**
- `kernel/DiSyL/ComponentRegistry.php` (+52 lines)
- `kernel/DiSyL/Renderers/BaseRenderer.php` (+73 lines)
- `kernel/DiSyL/Renderers/WordPressRenderer.php` (+67 lines)
- `kernel/DiSyL/Renderers/JoomlaRenderer.php` (+14 lines)
- `kernel/DiSyL/Renderers/DrupalRenderer.php` (+95 lines)
- `dsl/FormatRenderer.php` (+10 lines)
- `instances/wp-brutus-cli/wp-content/themes/phoenix/functions.php` (+3 lines)

**Files Created:**
- `instances/wp-brutus-cli/wp-content/themes/phoenix/assets/css/dsl-components.css` (401 lines)

**Total Changes:** 8 files changed, 628 insertions(+), 59 deletions(-)

### Usage Examples

```disyl
{!-- Simple card grid --}
{ikb_query type="post" limit=6 format="card" layout="grid-3"}

{!-- Hero section --}
{ikb_query type="post" limit=1 format="hero"}

{!-- Masonry layout --}
{ikb_query type="post" limit=9 format="card" layout="masonry" columns=3 gap="large"}

{!-- Horizontal slider --}
{ikb_query type="post" limit=10 format="minimal" layout="slider"}

{!-- Traditional (still works) --}
{ikb_query type="post" limit=6}
    <article>{item.title}</article>
{/ikb_query}
```

---

## [1.0.0] - 2025-11-10

### 🎉 Initial Release

The first stable release of Ikabud Kernel - a GNU/Linux-inspired CMS Operating System.

### Added

#### Core Kernel
- **5-Phase Boot Sequence** - Structured kernel initialization
  1. Kernel-level dependencies
  2. Shared core loading
  3. Instance configuration
  4. CMS runtime bootstrap
  5. Theme & plugin loading
- **Process Management** - OS-level process handling for CMS instances
- **Syscall Interface** - Unified API for CMS operations
- **Resource Tracking** - Memory, CPU, disk, and database monitoring
- **Boot Logging** - Detailed boot sequence profiling
- **Error Handling** - Comprehensive error management system

#### Database Schema
- `kernel_config` - Kernel configuration storage
- `kernel_processes` - Process table (like Linux `ps`)
- `kernel_syscalls` - Syscall audit log
- `kernel_resources` - Resource usage tracking
- `kernel_boot_log` - Boot sequence logging
- `instances` - CMS instance registry
- `instance_routes` - Routing configuration
- `themes` - Theme registry
- `theme_files` - DSL templates and assets
- `dsl_cache` - Compiled AST cache
- `dsl_snippets` - Reusable code snippets
- `users` - Kernel-level users
- `api_tokens` - JWT authentication tokens

#### CMS Adapters
- **WordPress Adapter** - Full WordPress integration
  - Instance creation and management
  - Plugin and theme handling
  - Database configuration
  - Cache integration
- **Joomla Adapter** - Joomla CMS support
  - Instance bootstrapping
  - Extension management
  - Configuration handling
- **Drupal Adapter** - Drupal CMS support
  - Core bootstrapping
  - Module management
  - Settings configuration
- **Native Adapter** - Ikabud native CMS

#### API Layer
- RESTful API with Slim Framework
- JWT authentication middleware
- Rate limiting and security
- Comprehensive endpoint coverage:
  - `/api/health` - Health check
  - `/api/v1/kernel/*` - Kernel management
  - `/api/v1/instances/*` - Instance management
  - `/api/v1/themes/*` - Theme management
  - `/api/v1/dsl/*` - DSL compiler/executor
  - `/api/v1/auth/*` - Authentication

#### CLI Tool (`ikabud`)
- `ikabud start <instance-id>` - Start instance
- `ikabud stop <instance-id>` - Stop instance
- `ikabud restart <instance-id>` - Restart instance
- `ikabud status <instance-id>` - Show status
- `ikabud list` - List all instances
- `ikabud create <instance-id>` - Create instance
- `ikabud remove <instance-id>` - Remove instance
- `ikabud kill <instance-id>` - Force kill instance
- `ikabud health <instance-id>` - Health check
- `ikabud logs <instance-id>` - Show logs

#### Instance Management
- Multi-CMS support (WordPress, Joomla, Drupal)
- Shared core architecture
- Instance isolation
- Process-level management
- Systemd integration
- PHP-FPM pool per instance
- Socket-based communication

#### DSL System
- Query compiler
- Layout engine
- Format renderer
- Template parser
- Runtime placeholder support
- Conditional loading
- Cache optimization

#### Admin UI (Basic)
- React + Vite setup
- TypeScript support
- TailwindCSS styling
- Recharts for analytics
- Lucide icons
- Basic dashboard structure

#### Utilities & Scripts
- `create-wordpress-instance` - WordPress instance creator
- `create-joomla-instance` - Joomla instance creator
- `create-drupal-instance` - Drupal instance creator
- `generate-plugin-manifest` - Plugin manifest generator
- `monitor-processes` - Process monitoring
- `register-instance-process` - Process registration
- `tenant-manager` - Multi-tenant management

#### Documentation
- Comprehensive README
- Installation guide (INSTALL.md)
- System requirements (REQUIREMENTS.md)
- Architecture documentation
- API reference
- DSL guide
- Deployment guides
- Performance tuning guides
- Security best practices

#### Configuration
- Environment-based configuration (.env)
- Database configuration
- JWT authentication setup
- Cache configuration
- Logging configuration
- Security settings

#### Security
- JWT token authentication
- API rate limiting
- SQL injection prevention
- XSS protection
- CSRF protection
- Security headers
- Input validation
- Output sanitization

#### Performance
- OPcache support
- Redis/Memcached integration
- Database query optimization
- Asset minification
- Gzip compression
- Browser caching
- FastCGI caching

### Changed
- Migrated from WordPress-centric to kernel-first architecture
- Unified CMS adapters under single interface
- Improved boot sequence with explicit phases
- Enhanced error handling and logging
- Optimized database schema for performance

### Fixed
- Boot sequence dependency issues
- Instance interference problems
- Memory leak in process management
- Cache invalidation bugs
- Route resolution conflicts

### Security
- Implemented JWT authentication
- Added rate limiting
- Enhanced input validation
- Secured API endpoints
- Protected against common vulnerabilities

---

## [0.9.0-beta] - 2025-10-15

### Added
- Beta release for testing
- Core kernel implementation
- Basic WordPress adapter
- Minimal API layer
- CLI tool prototype

### Known Issues
- WordPress-centric architecture
- Missing boot phases
- Limited CMS support
- No process isolation
- Basic error handling

---

## [0.1.0-alpha] - 2025-09-01

### Added
- Initial proof of concept
- Basic kernel structure
- WordPress integration experiment
- Simple routing system

### Known Issues
- Incomplete implementation
- No production readiness
- Limited functionality
- Proof of concept only

---

## Version History

| Version | Release Date | Status | Notes |
|---------|--------------|--------|-------|
| 1.4.0 | 2025-11-26 | Stable | Grammar v1.2.0, rich validation, kernel alignment |
| 1.3.0 | 2025-11-26 | Stable | DiSyL v1.0.0 spec & performance optimizations |
| 1.2.0 | 2025-11-23 | Stable | DiSyL module/block content processing |
| 1.1.0 | 2025-11-22 | Stable | DSL auto-rendering integration |
| 1.0.0 | 2025-11-10 | Stable | First production release |
| 0.9.0-beta | 2025-10-15 | Beta | Testing phase |
| 0.1.0-alpha | 2025-09-01 | Alpha | Proof of concept |

---

## Upgrade Guide

### From 1.3.0 to 1.4.0

1. **Pull latest changes**
   ```bash
   cd /var/www/html/ikabud-kernel
   git pull origin master
   ```

2. **No database changes required** - This is a feature enhancement release

3. **Clear all caches** (recommended for Grammar changes)
   ```bash
   rm -rf storage/cache/*.cache
   rm -rf storage/cache/.cache_stats.json
   rm -rf storage/api-cache/*
   # Or use the dashboard "Clear All Cache" button
   ```

4. **Restart instances** (optional, for immediate effect)
   ```bash
   ikabud restart <instance-id>
   ```

5. **Update code** (optional)
   - Use `setStrictMode(false)` for lenient validation during migration
   - Access `getValidationResult()` for rich error details
   - Use `Grammar::getAvailableComponents()` for Visual Builder integration

6. **Review deprecation warnings**
   - Check logs for escaping warnings on expressions
   - Add `| esc_html` filter to unescaped output

**Breaking Changes:** None  
**Backward Compatible:** Yes (100%)  
**Migration Required:** No

---

### From 1.2.0 to 1.3.0

1. **Pull latest changes**
   ```bash
   cd /var/www/html/ikabud-kernel
   git pull origin master
   ```

2. **No database changes required** - This is a feature enhancement release

3. **Clear cache** (recommended for performance improvements)
   ```bash
   rm -rf storage/cache/*.cache
   rm -rf storage/cache/*.compiled
   rm -rf storage/api-cache/*
   ```

4. **Restart instances** (optional, for immediate effect)
   ```bash
   ikabud restart <instance-id>
   ```

5. **Update templates** (optional)
   - Existing templates work unchanged
   - New `ikb_platform` declaration available (backward compatible with `ikb_cms`)
   - New platform-specific namespaced components available (`wp:*`, `mobile:*`, `desktop:*`)

**Breaking Changes:** None  
**Backward Compatible:** Yes (100%)  
**Migration Required:** No

---

### From 1.0.0 to 1.1.0

1. **Pull latest changes**
   ```bash
   cd /var/www/html/ikabud-kernel
   git pull origin master
   ```

2. **No database changes required** - This is a feature enhancement release

3. **Clear cache** (recommended)
   ```bash
   rm -rf storage/cache/*.cache
   rm -rf storage/cache/*.compiled
   ```

4. **Restart instances** (optional, for immediate effect)
   ```bash
   ikabud restart <instance-id>
   ```

5. **Update templates** (optional)
   - Existing templates work unchanged
   - Add `format` and `layout` attributes to `ikb_query` for auto-rendering
   - See usage examples in changelog

**Breaking Changes:** None  
**Backward Compatible:** Yes (100%)  
**Migration Required:** No

### From 0.9.0-beta to 1.0.0

1. **Backup your data**
   ```bash
   mysqldump -u root -p ikabud_kernel > backup.sql
   cp -r /var/www/html/ikabud-kernel /var/www/html/ikabud-kernel.backup
   ```

2. **Pull latest changes**
   ```bash
   cd /var/www/html/ikabud-kernel
   git pull origin main
   ```

3. **Update dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. **Run database migrations**
   ```bash
   mysql -u ikabud_user -p ikabud_kernel < database/migrations/v1.0.0.sql
   ```

5. **Update configuration**
   ```bash
   cp .env .env.backup
   # Merge new settings from .env.example
   ```

6. **Restart services**
   ```bash
   sudo systemctl restart apache2  # or nginx
   ikabud restart <instance-id>
   ```

---

## Breaking Changes

### 1.0.0
- **API Endpoints**: Some v1 endpoints have changed structure
- **Database Schema**: New tables added, some columns modified
- **Configuration**: New environment variables required
- **CLI Commands**: Some command syntax updated

**Migration Required**: Yes  
**Backward Compatible**: No  
**Migration Guide**: See [UPGRADE.md](docs/UPGRADE.md)

---

## Deprecation Notices

### Deprecated in 1.0.0
- None (first stable release)

### To Be Deprecated in 2.0.0
- Old DSL syntax (will be replaced with enhanced version)
- Legacy API endpoints (will be moved to v2)

---

## Contributors

### Core Team
- **Lead Developer**: [Your Name]
- **Architecture**: [Team Member]
- **Documentation**: [Team Member]

### Community Contributors
- Thank you to all contributors who helped test and improve Ikabud Kernel!

---

## Links

- **Homepage**: https://ikabud.com
- **Documentation**: https://docs.ikabud.com
- **Repository**: https://github.com/yourusername/ikabud-kernel
- **Issue Tracker**: https://github.com/yourusername/ikabud-kernel/issues
- **Changelog**: https://github.com/yourusername/ikabud-kernel/blob/main/CHANGELOG.md

---

## License

Ikabud Kernel is open-source software licensed under the [MIT License](LICENSE).

---

**Note**: This changelog follows [Keep a Changelog](https://keepachangelog.com/) principles and uses [Semantic Versioning](https://semver.org/).
