#!/bin/bash

# DiSyL Extension Installer for VSCode/Windsurf
# This script installs the DiSyL extension from VSIX

set -e

echo "🚀 DiSyL Extension Installer"
echo "=============================="
echo ""

# Get the current version from package.json
VERSION=$(grep '"version"' package.json | head -1 | sed 's/.*"version": "\(.*\)".*/\1/')
VSIX_FILE="disyl-${VERSION}.vsix"

# Check if VSIX exists
if [ ! -f "$VSIX_FILE" ]; then
    echo "❌ Error: $VSIX_FILE not found"
    echo "   Building extension..."
    npm run compile
    npm run package
    
    if [ ! -f "$VSIX_FILE" ]; then
        echo "❌ Failed to build VSIX"
        exit 1
    fi
fi

echo "📦 Installing DiSyL v${VERSION}..."
echo ""

# Try Windsurf CLI first
if command -v windsurf &> /dev/null; then
    echo "🌊 Installing to Windsurf..."
    windsurf --install-extension "$VSIX_FILE" --force
    echo "✅ Installed to Windsurf"
    echo ""
elif command -v code &> /dev/null; then
    echo "💻 Installing to VS Code..."
    code --install-extension "$VSIX_FILE" --force
    echo "✅ Installed to VS Code"
    echo ""
else
    echo "⚠️  No CLI found (windsurf or code)"
    echo ""
    echo "   Manual installation:"
    echo "   1. Open Windsurf/VS Code"
    echo "   2. Press Ctrl+Shift+P"
    echo "   3. Type 'Extensions: Install from VSIX'"
    echo "   4. Select: $(pwd)/$VSIX_FILE"
    echo ""
    exit 0
fi

echo "✅ Installation complete!"
echo ""
echo "📝 Next steps:"
echo "   1. Reload your IDE (Ctrl+Shift+P → 'Developer: Reload Window')"
echo "   2. Open a .disyl file to test syntax highlighting"
echo "   3. Try typing 'section' or 'if' to test snippets"
echo ""
echo "🎨 Enjoy coding with DiSyL!"
