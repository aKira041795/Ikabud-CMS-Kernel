#!/bin/bash

echo "🔍 DiSyL Extension Installation Verification"
echo "=============================================="
echo ""

EXTENSION_DIR="$HOME/.config/Windsurf/User/extensions/ikabud.disyl-0.4.0"

if [ -d "$EXTENSION_DIR" ]; then
    echo "✅ Extension directory found: $EXTENSION_DIR"
    echo ""
    echo "📁 Installed files:"
    ls -1 "$EXTENSION_DIR"
    echo ""
    echo "✅ DiSyL extension is installed!"
    echo ""
    echo "📝 Next steps:"
    echo "   1. Reload Windsurf: Ctrl+Shift+P → 'Reload Window'"
    echo "   2. Open any .disyl file"
    echo "   3. Check bottom-right corner shows 'DiSyL'"
    echo "   4. Try typing 'section' and press Tab"
    echo ""
else
    echo "❌ Extension not found in: $EXTENSION_DIR"
    echo ""
    echo "Run the installation again:"
    echo "   cd /var/www/html/ikabud-kernel/vscode-disyl"
    echo "   ./install.sh"
fi
