#!/bin/bash
# Build script for DiSyL extension

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Get version from package.json
VERSION=$(grep '"version"' package.json | head -1 | sed 's/.*"version".*"\([^"]*\)".*/\1/')

echo "🔨 Building DiSyL Extension v$VERSION..."

# Clean previous builds
rm -f *.vsix
rm -rf .vsix-package

# Create package directory with proper VSIX structure
echo "📁 Creating package structure..."
mkdir -p .vsix-package/extension

# Copy necessary files to extension folder
cp -r syntaxes .vsix-package/extension/
cp -r snippets .vsix-package/extension/
cp package.json .vsix-package/extension/
cp README.md .vsix-package/extension/
cp CHANGELOG.md .vsix-package/extension/
cp icon.png .vsix-package/extension/
cp language-configuration.json .vsix-package/extension/

# Remove backup files
find .vsix-package -name "*.backup" -delete

# Create [Content_Types].xml
echo "📄 Creating VSIX metadata..."
cat > .vsix-package/\[Content_Types\].xml << 'EOF'
<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension=".json" ContentType="application/json"/>
  <Default Extension=".vsixmanifest" ContentType="text/xml"/>
  <Default Extension=".md" ContentType="text/markdown"/>
  <Default Extension=".png" ContentType="image/png"/>
</Types>
EOF

# Create extension.vsixmanifest
cat > .vsix-package/extension.vsixmanifest << EOF
<?xml version="1.0" encoding="utf-8"?>
<PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011">
  <Metadata>
    <Identity Language="en-US" Id="disyl" Version="$VERSION" Publisher="ikabud"/>
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
EOF

# Create vsix using zip
echo "🗜️  Creating VSIX package..."
cd .vsix-package
zip -r "../disyl-$VERSION.vsix" * -x "*.map" -x "*.backup"
cd ..

# Cleanup
rm -rf .vsix-package

echo "✅ Extension packaged successfully: disyl-$VERSION.vsix"
echo ""
echo "To install, run:"
echo "  ./install.sh"
echo ""
echo "Or manually:"
echo "  windsurf --install-extension disyl-$VERSION.vsix"
echo "  code --install-extension disyl-$VERSION.vsix"
