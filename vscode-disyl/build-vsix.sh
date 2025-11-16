#!/bin/bash

# Build script for DiSyL extension (workaround for Node 18 + vsce issues)

set -e

echo "🔨 Building DiSyL Extension..."

# Compile TypeScript
echo "📦 Compiling TypeScript..."
npm run compile

# Create package directory with proper VSIX structure
echo "📁 Creating package structure..."
rm -rf .vsix-package
mkdir -p .vsix-package/extension

# Copy necessary files to extension folder
cp -r out .vsix-package/extension/
cp -r syntaxes .vsix-package/extension/
cp -r snippets .vsix-package/extension/
cp package.json .vsix-package/extension/
cp README.md .vsix-package/extension/
cp CHANGELOG.md .vsix-package/extension/
cp LICENSE .vsix-package/extension/ 2>/dev/null || echo "No LICENSE file"
cp icon.png .vsix-package/extension/
cp language-configuration.json .vsix-package/extension/

# Copy node_modules (only runtime dependencies)
echo "📦 Copying runtime dependencies..."
mkdir -p .vsix-package/extension/node_modules
cp -r node_modules/vscode-languageclient .vsix-package/extension/node_modules/
cp -r node_modules/vscode-languageserver .vsix-package/extension/node_modules/
cp -r node_modules/vscode-languageserver-textdocument .vsix-package/extension/node_modules/
cp -r node_modules/vscode-languageserver-protocol .vsix-package/extension/node_modules/
cp -r node_modules/vscode-jsonrpc .vsix-package/extension/node_modules/

# Create [Content_Types].xml
echo "📄 Creating VSIX metadata..."
cat > .vsix-package/\[Content_Types\].xml << 'EOF'
<?xml version="1.0" encoding="utf-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension=".json" ContentType="application/json"/>
  <Default Extension=".vsixmanifest" ContentType="text/xml"/>
</Types>
EOF

# Create extension.vsixmanifest
cat > .vsix-package/extension.vsixmanifest << 'EOF'
<?xml version="1.0" encoding="utf-8"?>
<PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011">
  <Metadata>
    <Identity Language="en-US" Id="disyl" Version="0.5.0" Publisher="ikabud"/>
    <DisplayName>DiSyL - Declarative Ikabud Syntax Language</DisplayName>
    <Description>Full-featured Language Server Protocol extension for DiSyL templates</Description>
    <Tags>disyl,template,cms,wordpress,drupal,joomla,ikabud,windsurf</Tags>
    <Categories>Programming Languages,Snippets,Formatters</Categories>
    <License>extension/LICENSE</License>
    <Icon>extension/icon.png</Icon>
  </Metadata>
  <Installation>
    <InstallationTarget Id="Microsoft.VisualStudio.Code"/>
  </Installation>
  <Dependencies/>
  <Assets>
    <Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json"/>
  </Assets>
</PackageManifest>
EOF

# Create vsix using zip
echo "🗜️  Creating VSIX package..."
cd .vsix-package
zip -r ../disyl-0.5.0.vsix * -x "*.map"
cd ..

# Cleanup
rm -rf .vsix-package

echo "✅ Extension packaged successfully: disyl-0.5.0.vsix"
echo ""
echo "To install:"
echo "  code --install-extension disyl-0.5.0.vsix"
echo "  windsurf --install-extension disyl-0.5.0.vsix"
