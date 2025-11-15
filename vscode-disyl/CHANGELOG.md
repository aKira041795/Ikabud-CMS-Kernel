# Change Log

All notable changes to the "DiSyL" extension will be documented in this file.

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
