#!/bin/bash

echo "🔍 DiSyL Extension Diagnostics"
echo "================================"
echo ""

echo "1. Extension Installation:"
echo "   ------------------------"
windsurf --list-extensions --show-versions | grep disyl
if [ $? -eq 0 ]; then
    echo "   ✅ Extension is installed"
else
    echo "   ❌ Extension NOT installed"
fi
echo ""

echo "2. Extension Location:"
echo "   -------------------"
EXT_DIR=$(find ~/.windsurf/extensions -name "ikabud.disyl-*" -type d 2>/dev/null | head -1)
if [ -n "$EXT_DIR" ]; then
    echo "   ✅ Found at: $EXT_DIR"
    echo ""
    echo "   Files present:"
    ls -1 "$EXT_DIR" | sed 's/^/      - /'
else
    echo "   ❌ Extension directory not found"
fi
echo ""

echo "3. Syntax Grammar File:"
echo "   --------------------"
if [ -f "$EXT_DIR/syntaxes/disyl.tmLanguage.json" ]; then
    echo "   ✅ Grammar file exists"
    SIZE=$(stat -f%z "$EXT_DIR/syntaxes/disyl.tmLanguage.json" 2>/dev/null || stat -c%s "$EXT_DIR/syntaxes/disyl.tmLanguage.json" 2>/dev/null)
    echo "   Size: $SIZE bytes"
else
    echo "   ❌ Grammar file missing"
fi
echo ""

echo "4. Language Configuration:"
echo "   -----------------------"
if [ -f "$EXT_DIR/language-configuration.json" ]; then
    echo "   ✅ Language config exists"
else
    echo "   ❌ Language config missing"
fi
echo ""

echo "5. User Settings:"
echo "   ---------------"
SETTINGS_FILE="$HOME/.config/Windsurf/User/settings.json"
if [ -f "$SETTINGS_FILE" ]; then
    ASSOC=$(grep -A 2 '"files.associations"' "$SETTINGS_FILE" | grep '\.disyl' || echo "   Not configured")
    echo "   File association: $ASSOC"
else
    echo "   ⚠️  Settings file not found"
fi
echo ""

echo "6. Package.json Language ID:"
echo "   --------------------------"
if [ -f "$EXT_DIR/package.json" ]; then
    grep -A 5 '"id": "disyl"' "$EXT_DIR/package.json" | head -6
else
    echo "   ❌ package.json not found"
fi
echo ""

echo "================================"
echo "📋 Recommendations:"
echo ""
echo "If extension is installed but not working:"
echo "  1. Close ALL Windsurf windows"
echo "  2. Kill any Windsurf processes: pkill -9 windsurf"
echo "  3. Restart Windsurf"
echo "  4. Open a .disyl file"
echo "  5. Check bottom-right corner - should say 'DiSyL'"
echo ""
echo "If still not working:"
echo "  1. Uninstall: windsurf --uninstall-extension ikabud.disyl"
echo "  2. Reinstall: ./install.sh"
echo "  3. Restart Windsurf completely"
