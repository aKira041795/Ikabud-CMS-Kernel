# Change Log

All notable changes to the DiSyL extension will be documented in this file.

## [0.5.0] - 2025-11-16

### 🚀 Major Release: Language Server Protocol Implementation

This is a major release that transforms DiSyL from a simple syntax highlighter into a full-featured language extension with LSP support.

### Added
- **Language Server Protocol (LSP)** implementation
  - IntelliSense with smart auto-completion for components, filters, and control structures
  - Real-time syntax validation with error detection
  - Hover documentation for all components and filters
  - Document formatting with proper indentation
  - Signature help for component attributes
  - Go to definition for included files
  - Document symbols for navigation
- **Commands**
  - `DiSyL: Format Document` - Format with proper indentation
  - `DiSyL: Show Component Preview` - Preview components in webview
  - `DiSyL: Validate Document` - Manual validation trigger
  - `DiSyL: Insert Component` - Quick component insertion
- **Settings**
  - `disyl.maxNumberOfProblems` - Control max reported issues
  - `disyl.validateOnType` - Toggle real-time validation
  - `disyl.formatOnSave` - Auto-format on save
- **TypeScript Architecture**
  - Complete rewrite in TypeScript
  - Separate client and server architecture
  - Comprehensive test suite with Mocha
  - ESLint configuration for code quality
- **CI/CD Pipeline**
  - GitHub Actions for automated testing
  - Multi-platform testing (Ubuntu, Windows, macOS)
  - Automated release workflow
  - VSIX packaging automation
- **Documentation**
  - Comprehensive LSP features guide
  - Updated README with new features
  - Troubleshooting guide
  - Performance metrics

### Changed
- Extension now requires activation (loads on `.disyl` file open)
- Main entry point moved from `extension.js` to compiled `out/extension.js`
- Build process now uses TypeScript compilation
- Package structure reorganized for LSP architecture

### Technical Details
- **Language Server**: Full LSP implementation with validation, completion, hover, and formatting
- **Client**: VS Code extension client with command registration and webview support
- **Build System**: TypeScript compilation with separate client/server configs
- **Testing**: Mocha test framework with VS Code test runner
- **Packaging**: Custom build script to work around Node 18 compatibility issues

### Performance
- Startup time: < 100ms
- Memory usage: < 50MB
- Real-time validation with minimal CPU impact

## [0.4.0] - 2025-01-16

### Added
- 🌊 **Full Windsurf IDE compatibility**
- 🎨 Extension icon (gradient purple with DiSyL braces)
- 🚀 Automated installation script (`install.sh`)
- 📝 Enhanced language configuration with better auto-closing pairs
- 🔧 Improved indentation rules and on-enter behavior
- 📖 Windsurf-specific troubleshooting documentation
- ✨ Better pattern matching for control structures

### Changed
- Improved expression pattern to avoid conflicts with control structures
- Enhanced grammar file with better pattern ordering
- Updated README with Windsurf installation instructions
- Bumped version to 0.4.0

### Fixed
- Language detection issues in Windsurf
- Auto-closing pairs now respect context (strings, comments)
- Better folding markers for DiSyL components
- Improved syntax highlighting for nested structures

## [0.3.0] - 2025-11-15

### Added
- Initial release of DiSyL language support
- Syntax highlighting for DiSyL v0.3 grammar
- Support for components (`ikb_*`)
- Support for control structures (`if`, `for`, `include`)
- Support for expressions and filter pipelines
- Support for comments (`{!-- --}`)
- 30+ code snippets for common patterns
- Auto-closing pairs for braces, brackets, and quotes
- Code folding for components and control structures
- Smart indentation
- HTML integration

### Features
- **Components**: Full syntax highlighting for all ikb_ components
- **Filters**: Syntax highlighting for filter pipelines with multiple arguments
- **Control Structures**: if/else, for loops, include directives
- **Expressions**: Property access, method calls, literals
- **Comments**: Block comments with proper highlighting
- **Snippets**: Quick insertion of common DiSyL patterns

### Grammar Support
- DiSyL Grammar v0.3 (Production-ready)
- Filter argument ordering (positional first, then named)
- Unified control structure syntax
- Expression context distinction
- Unicode support

## [Unreleased]

### Planned
- IntelliSense / Autocomplete
- Hover documentation
- Go to definition
- Linting / Error detection
- Code formatting
- Refactoring support
- Debugging support
- Language server protocol
