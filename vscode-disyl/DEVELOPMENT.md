# DiSyL Extension Development Guide

This guide covers development, building, testing, and publishing the DiSyL VS Code extension.

## 🏗️ Architecture

The extension uses a **Language Server Protocol (LSP)** architecture:

```
vscode-disyl/
├── src/
│   ├── extension.ts          # Extension client (VS Code side)
│   └── test/                  # Test suite
├── server/
│   └── server.ts              # Language server (LSP implementation)
├── out/                       # Compiled JavaScript
│   ├── extension.js
│   └── server/
│       └── server.js
├── syntaxes/                  # TextMate grammar
├── snippets/                  # Code snippets
└── package.json               # Extension manifest
```

### Components

1. **Extension Client** (`src/extension.ts`)
   - Activates on `.disyl` files
   - Starts the language server
   - Registers commands (format, preview, validate, insert)
   - Manages webview panels

2. **Language Server** (`server/server.ts`)
   - Provides IntelliSense completions
   - Validates syntax in real-time
   - Offers hover documentation
   - Formats documents
   - Handles signature help

3. **TextMate Grammar** (`syntaxes/disyl.tmLanguage.json`)
   - Syntax highlighting rules
   - Token classification

4. **Snippets** (`snippets/disyl.json`)
   - Pre-built code templates

## 🛠️ Development Setup

### Prerequisites

- Node.js 18+ or 20+
- npm 9+
- VS Code or Windsurf IDE

### Install Dependencies

```bash
npm install
```

### Compile TypeScript

```bash
# Compile once
npm run compile

# Watch mode (auto-recompile on changes)
npm run watch
```

### Run Extension in Development

1. Open the project in VS Code
2. Press `F5` to launch Extension Development Host
3. Open a `.disyl` file to test

### Debugging

**Client (Extension):**
- Set breakpoints in `src/extension.ts`
- Press `F5` to start debugging
- Extension runs in new VS Code window

**Server (Language Server):**
- Set breakpoints in `server/server.ts`
- Add to launch config:
  ```json
  {
    "name": "Attach to Server",
    "type": "node",
    "request": "attach",
    "port": 6009,
    "restart": true,
    "outFiles": ["${workspaceFolder}/out/server/**/*.js"]
  }
  ```
- Start extension with `F5`
- Attach debugger to server

## 🧪 Testing

### Run Tests

```bash
npm test
```

### Test Structure

- `src/test/runTest.ts` - Test runner
- `src/test/suite/index.ts` - Test suite loader
- `src/test/suite/extension.test.ts` - Extension tests

### Writing Tests

```typescript
import * as assert from 'assert';
import * as vscode from 'vscode';

suite('My Test Suite', () => {
    test('My test', () => {
        assert.ok(true);
    });
});
```

## 📦 Building & Packaging

### Build for Production

```bash
npm run compile
```

### Package Extension

```bash
# Using custom build script (recommended for Node 18)
./build-vsix.sh

# Using vsce (requires Node 20+)
npm run package
```

Output: `disyl-0.5.0.vsix`

### Install Locally

```bash
# VS Code
code --install-extension disyl-0.5.0.vsix

# Windsurf
windsurf --install-extension disyl-0.5.0.vsix
```

## 🚀 Publishing

### Publish to Open VSX (Windsurf)

1. Create account at https://open-vsx.org
2. Generate access token
3. Publish:

```bash
npx ovsx publish disyl-0.5.0.vsix -p YOUR_TOKEN
```

### Publish to VS Code Marketplace

1. Create publisher at https://marketplace.visualstudio.com
2. Generate Personal Access Token
3. Publish:

```bash
npx vsce publish -p YOUR_TOKEN
```

### Automated Publishing (CI/CD)

The extension includes GitHub Actions workflows:

**`.github/workflows/ci.yml`** - Runs on every push/PR
- Lints code
- Compiles TypeScript
- Runs tests on multiple platforms
- Packages extension

**`.github/workflows/release.yml`** - Runs on version tags
- Builds extension
- Creates GitHub release
- Publishes to Open VSX
- Publishes to VS Code Marketplace

To release:
```bash
git tag v0.5.0
git push origin v0.5.0
```

## 🔧 Configuration Files

### `package.json`
Extension manifest with metadata, dependencies, and contributions.

### `tsconfig.json`
TypeScript config for client code.

### `tsconfig.server.json`
TypeScript config for server code.

### `.eslintrc.json`
ESLint rules for code quality.

### `.vscodeignore`
Files to exclude from VSIX package.

## 📝 Code Style

- **TypeScript**: Strict mode enabled
- **Indentation**: 4 spaces
- **Quotes**: Single quotes
- **Semicolons**: Required
- **Naming**: camelCase for variables/functions, PascalCase for classes

### Lint Code

```bash
npm run lint
```

## 🐛 Troubleshooting

### "Cannot find module 'vscode'"

Install dependencies:
```bash
npm install
```

### Compilation Errors

Clean and rebuild:
```bash
rm -rf out node_modules
npm install
npm run compile
```

### Extension Not Loading

1. Check Output panel: `DiSyL Language Server`
2. Check Developer Console: `Help > Toggle Developer Tools`
3. Reload window: `Developer: Reload Window`

### VSIX Packaging Fails (Node 18)

Use the custom build script:
```bash
./build-vsix.sh
```

## 📚 Resources

### LSP Documentation
- [Language Server Protocol](https://microsoft.github.io/language-server-protocol/)
- [VS Code Extension API](https://code.visualstudio.com/api)
- [vscode-languageserver-node](https://github.com/microsoft/vscode-languageserver-node)

### DiSyL Resources
- [DiSyL Grammar Spec](https://ikabud.com/disyl/grammar)
- [Component Reference](https://ikabud.com/disyl/components)
- [Filter Reference](https://ikabud.com/disyl/filters)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Run linter and tests
6. Submit a pull request

### Commit Messages

Follow conventional commits:
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation
- `test:` Tests
- `refactor:` Code refactoring
- `chore:` Maintenance

Example:
```
feat: add semantic highlighting support
fix: resolve validation error for nested components
docs: update LSP features documentation
```

## 📄 License

MIT License - see LICENSE file for details.
