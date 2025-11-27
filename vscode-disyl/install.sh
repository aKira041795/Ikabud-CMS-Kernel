#!/bin/bash
# DiSyL Extension Installer for VSCode and Windsurf
# Handles profile-based extension registration

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VSIX_FILE="$SCRIPT_DIR/disyl-0.8.0.vsix"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}🚀 DiSyL Extension Installer${NC}"
echo "================================"

# Check if VSIX exists
if [ ! -f "$VSIX_FILE" ]; then
    echo -e "${YELLOW}⚠️  VSIX not found. Building...${NC}"
    cd "$SCRIPT_DIR"
    bash build-vsix.sh
fi

# Get version from package.json
VERSION=$(grep '"version"' "$SCRIPT_DIR/package.json" | head -1 | sed 's/.*"version".*"\([^"]*\)".*/\1/')
echo "Version: $VERSION"

# Install for Windsurf
if command -v windsurf &> /dev/null; then
    echo -e "\n${GREEN}📦 Installing for Windsurf...${NC}"
    windsurf --uninstall-extension ikabud.disyl 2>/dev/null || true
    windsurf --install-extension "$VSIX_FILE" --force
    
    # Register extension in all Windsurf profiles
    echo -e "${GREEN}📝 Registering in Windsurf profiles...${NC}"
    WINDSURF_PROFILES_DIR="$HOME/.config/Windsurf/User/profiles"
    
    if [ -d "$WINDSURF_PROFILES_DIR" ]; then
        for profile_dir in "$WINDSURF_PROFILES_DIR"/*/; do
            if [ -f "${profile_dir}extensions.json" ]; then
                profile_name=$(basename "$profile_dir")
                echo "  - Profile: $profile_name"
                
                # Check if already registered
                if grep -q "ikabud.disyl" "${profile_dir}extensions.json" 2>/dev/null; then
                    echo "    Already registered, updating..."
                    # Remove old entry and add new one
                    python3 -c "
import json
import sys

with open('${profile_dir}extensions.json', 'r') as f:
    data = json.load(f)

# Remove existing disyl entries
data = [x for x in data if 'disyl' not in x.get('identifier', {}).get('id', '')]

# Add new entry
disyl_ext = {
    'identifier': {'id': 'ikabud.disyl'},
    'version': '$VERSION',
    'location': {
        '\$mid': 1,
        'fsPath': '$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'external': 'file://$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'path': '$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'scheme': 'file'
    },
    'relativeLocation': 'ikabud.disyl-$VERSION',
    'metadata': {
        'installedTimestamp': $(date +%s)000,
        'pinned': True,
        'source': 'vsix'
    }
}
data.append(disyl_ext)

with open('${profile_dir}extensions.json', 'w') as f:
    json.dump(data, f)
"
                else
                    echo "    Registering new..."
                    python3 -c "
import json

with open('${profile_dir}extensions.json', 'r') as f:
    data = json.load(f)

disyl_ext = {
    'identifier': {'id': 'ikabud.disyl'},
    'version': '$VERSION',
    'location': {
        '\$mid': 1,
        'fsPath': '$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'external': 'file://$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'path': '$HOME/.windsurf/extensions/ikabud.disyl-$VERSION',
        'scheme': 'file'
    },
    'relativeLocation': 'ikabud.disyl-$VERSION',
    'metadata': {
        'installedTimestamp': $(date +%s)000,
        'pinned': True,
        'source': 'vsix'
    }
}
data.append(disyl_ext)

with open('${profile_dir}extensions.json', 'w') as f:
    json.dump(data, f)
"
                fi
            fi
        done
    fi
    echo -e "${GREEN}✅ Windsurf installation complete${NC}"
else
    echo -e "${YELLOW}⚠️  Windsurf not found, skipping${NC}"
fi

# Install for VSCode
if command -v code &> /dev/null; then
    echo -e "\n${GREEN}📦 Installing for VSCode...${NC}"
    code --uninstall-extension ikabud.disyl 2>/dev/null || true
    code --install-extension "$VSIX_FILE" --force
    echo -e "${GREEN}✅ VSCode installation complete${NC}"
else
    echo -e "${YELLOW}⚠️  VSCode not found, skipping${NC}"
fi

echo -e "\n${GREEN}�� Installation complete!${NC}"
echo ""
echo "Please reload your editor:"
echo "  - Windsurf: Ctrl+Shift+P → 'Developer: Reload Window'"
echo "  - VSCode:   Ctrl+Shift+P → 'Developer: Reload Window'"
echo ""
echo "After reload, .disyl files should be recognized as DiSyL language."
