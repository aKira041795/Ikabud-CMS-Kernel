#!/bin/bash

# DiSyL VSIX Installer for Windsurf
# This script installs the DiSyL extension from VSIX

set -e

echo "🚀 DiSyL VSIX Installer"
echo "======================="
echo ""

# Check if VSIX exists
if [ ! -f "disyl-0.5.0.vsix" ]; then
    echo "❌ Error: disyl-0.5.0.vsix not found"
    echo "   Run: npm run package"
    exit 1
fi

# Install to Windsurf
if command -v code &> /dev/null; then
    echo "💻 Installing to Windsurf/VSCode using 'code' command..."
    code --install-extension disyl-0.5.0.vsix --force
    echo "✅ Installed via code command"
else
    echo "⚠️  'code' command not found"
    echo "   Manual installation required:"
    echo ""
    echo "   1. Open Windsurf"
    echo "   2. Press Ctrl+Shift+P"
    echo "   3. Type 'Extensions: Install from VSIX'"
    echo "   4. Select: $(pwd)/disyl-0.5.0.vsix"
fi

echo ""
echo "✅ Installation complete!"
echo ""
echo "📝 Next steps:"
echo "   1. Reload Windsurf (Ctrl+Shift+P → 'Developer: Reload Window')"
echo "   2. Open a .disyl file to test syntax highlighting"
echo "   3. Try typing 'section' or 'if' to test snippets"
echo ""
echo "🎨 Enjoy coding with DiSyL!"
