#!/bin/bash
#===============================================================================
# DiSyL Extension Setup Script
# One-command installation for VSCode and Windsurf
#===============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Banner
echo -e "${CYAN}"
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                                                               ║"
echo "║   ██████╗ ██╗███████╗██╗   ██╗██╗                            ║"
echo "║   ██╔══██╗██║██╔════╝╚██╗ ██╔╝██║                            ║"
echo "║   ██║  ██║██║███████╗ ╚████╔╝ ██║                            ║"
echo "║   ██║  ██║██║╚════██║  ╚██╔╝  ██║                            ║"
echo "║   ██████╔╝██║███████║   ██║   ███████╗                       ║"
echo "║   ╚═════╝ ╚═╝╚══════╝   ╚═╝   ╚══════╝                       ║"
echo "║                                                               ║"
echo "║   Declarative Ikabud Syntax Language                         ║"
echo "║   Extension Installer                                         ║"
echo "║                                                               ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Get version
VERSION=$(grep '"version"' package.json | head -1 | sed 's/.*"version".*"\([^"]*\)".*/\1/')
echo -e "${BLUE}Version: ${VERSION}${NC}"
echo ""

#-------------------------------------------------------------------------------
# Step 1: Check/Build VSIX
#-------------------------------------------------------------------------------
echo -e "${YELLOW}[1/4] Checking extension package...${NC}"

VSIX_FILE="$SCRIPT_DIR/disyl-${VERSION}.vsix"

if [ ! -f "$VSIX_FILE" ]; then
    echo -e "  ${YELLOW}⚠ VSIX not found. Building...${NC}"
    
    # Clean previous builds
    rm -f *.vsix
    rm -rf .vsix-package
    
    # Create package structure
    mkdir -p .vsix-package/extension
    
    # Copy files
    cp -r syntaxes snippets .vsix-package/extension/
    cp package.json README.md CHANGELOG.md icon.png language-configuration.json .vsix-package/extension/
    
    # Remove backup files
    find .vsix-package -name "*.backup" -delete 2>/dev/null || true
    
    # Create Content_Types.xml
    cat > .vsix-package/\[Content_Types\].xml << 'CTXML'
<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension=".json" ContentType="application/json"/>
  <Default Extension=".vsixmanifest" ContentType="text/xml"/>
  <Default Extension=".md" ContentType="text/markdown"/>
  <Default Extension=".png" ContentType="image/png"/>
</Types>
CTXML

    # Create vsixmanifest
    cat > .vsix-package/extension.vsixmanifest << MANIFEST
<?xml version="1.0" encoding="utf-8"?>
<PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011">
  <Metadata>
    <Identity Language="en-US" Id="disyl" Version="${VERSION}" Publisher="ikabud"/>
    <DisplayName>DiSyL - Declarative Ikabud Syntax Language</DisplayName>
    <Description>Syntax highlighting and snippets for DiSyL templates</Description>
    <Tags>disyl,template,cms,wordpress,drupal,joomla,ikabud,windsurf,__ext_disyl</Tags>
    <Categories>Programming Languages,Snippets</Categories>
    <Icon>extension/icon.png</Icon>
  </Metadata>
  <Installation>
    <InstallationTarget Id="Microsoft.VisualStudio.Code"/>
  </Installation>
  <Dependencies/>
  <Assets>
    <Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json" Addressable="true"/>
  </Assets>
</PackageManifest>
MANIFEST

    # Create VSIX
    cd .vsix-package
    zip -r "../disyl-${VERSION}.vsix" * -x "*.map" -x "*.backup" > /dev/null
    cd ..
    rm -rf .vsix-package
    
    echo -e "  ${GREEN}✓ Built disyl-${VERSION}.vsix${NC}"
else
    echo -e "  ${GREEN}✓ Found disyl-${VERSION}.vsix${NC}"
fi

#-------------------------------------------------------------------------------
# Step 2: Install for Windsurf
#-------------------------------------------------------------------------------
echo -e "\n${YELLOW}[2/4] Installing for Windsurf...${NC}"

if command -v windsurf &> /dev/null; then
    # Uninstall old version
    windsurf --uninstall-extension ikabud.disyl 2>/dev/null || true
    
    # Install new version
    windsurf --install-extension "$VSIX_FILE" --force > /dev/null 2>&1
    echo -e "  ${GREEN}✓ Installed extension${NC}"
    
    # Register in all Windsurf profiles
    WINDSURF_PROFILES_DIR="$HOME/.config/Windsurf/User/profiles"
    
    if [ -d "$WINDSURF_PROFILES_DIR" ]; then
        PROFILE_COUNT=0
        for profile_dir in "$WINDSURF_PROFILES_DIR"/*/; do
            if [ -f "${profile_dir}extensions.json" ]; then
                profile_name=$(basename "$profile_dir")
                
                # Update profile extensions.json
                python3 << PYEOF
import json
import os

profile_path = '${profile_dir}extensions.json'
version = '${VERSION}'
home = os.environ['HOME']

with open(profile_path, 'r') as f:
    data = json.load(f)

# Remove existing disyl entries
data = [x for x in data if 'disyl' not in x.get('identifier', {}).get('id', '')]

# Add new entry
disyl_ext = {
    'identifier': {'id': 'ikabud.disyl'},
    'version': version,
    'location': {
        '\$mid': 1,
        'fsPath': f'{home}/.windsurf/extensions/ikabud.disyl-{version}',
        'external': f'file://{home}/.windsurf/extensions/ikabud.disyl-{version}',
        'path': f'{home}/.windsurf/extensions/ikabud.disyl-{version}',
        'scheme': 'file'
    },
    'relativeLocation': f'ikabud.disyl-{version}',
    'metadata': {
        'installedTimestamp': $(date +%s)000,
        'pinned': True,
        'source': 'vsix'
    }
}
data.append(disyl_ext)

with open(profile_path, 'w') as f:
    json.dump(data, f)
PYEOF
                PROFILE_COUNT=$((PROFILE_COUNT + 1))
            fi
        done
        echo -e "  ${GREEN}✓ Registered in ${PROFILE_COUNT} profile(s)${NC}"
    fi
else
    echo -e "  ${YELLOW}⚠ Windsurf not found, skipping${NC}"
fi

#-------------------------------------------------------------------------------
# Step 3: Install for VSCode
#-------------------------------------------------------------------------------
echo -e "\n${YELLOW}[3/4] Installing for VSCode...${NC}"

if command -v code &> /dev/null; then
    code --uninstall-extension ikabud.disyl 2>/dev/null || true
    code --install-extension "$VSIX_FILE" --force > /dev/null 2>&1
    echo -e "  ${GREEN}✓ Installed extension${NC}"
else
    echo -e "  ${YELLOW}⚠ VSCode not found, skipping${NC}"
fi

#-------------------------------------------------------------------------------
# Step 4: Verify Installation
#-------------------------------------------------------------------------------
echo -e "\n${YELLOW}[4/4] Verifying installation...${NC}"

INSTALLED=false

if command -v windsurf &> /dev/null; then
    if windsurf --list-extensions 2>/dev/null | grep -q "ikabud.disyl"; then
        echo -e "  ${GREEN}✓ Windsurf: ikabud.disyl installed${NC}"
        INSTALLED=true
    fi
fi

if command -v code &> /dev/null; then
    if code --list-extensions 2>/dev/null | grep -q "ikabud.disyl"; then
        echo -e "  ${GREEN}✓ VSCode: ikabud.disyl installed${NC}"
        INSTALLED=true
    fi
fi

#-------------------------------------------------------------------------------
# Done
#-------------------------------------------------------------------------------
echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
if [ "$INSTALLED" = true ]; then
    echo -e "${GREEN}  ✅ Installation Complete!${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${CYAN}Next Steps:${NC}"
    echo "  1. Reload your editor:"
    echo "     - Press Ctrl+Shift+P (or Cmd+Shift+P on Mac)"
    echo "     - Type 'Developer: Reload Window' and press Enter"
    echo ""
    echo "  2. Open any .disyl file - it should now have:"
    echo "     - Syntax highlighting"
    echo "     - Code snippets (type 'ikb' and press Tab)"
    echo "     - Bracket matching and auto-closing"
    echo ""
    echo -e "${CYAN}Troubleshooting:${NC}"
    echo "  - If .disyl files show as 'Plain Text', click the language"
    echo "    indicator in the status bar and select 'DiSyL'"
    echo "  - Run './diagnose.sh' for detailed diagnostics"
else
    echo -e "${RED}  ⚠ Installation may have issues${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Please check:"
    echo "  - Is VSCode or Windsurf installed?"
    echo "  - Run './diagnose.sh' for detailed diagnostics"
fi
echo ""
