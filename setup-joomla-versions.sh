#!/bin/bash
# Setup Joomla versions - rename v5 and install v4.4.14 as default

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Setting up Joomla versions..."
echo ""

# Step 1: Rename current Joomla 5.2.1 to joomla5
if [ -d "shared-cores/joomla" ]; then
    echo "[1/3] Renaming Joomla 5.2.1 to joomla5..."
    mv shared-cores/joomla shared-cores/joomla5
    echo "✓ Renamed to joomla5"
else
    echo "[1/3] Joomla directory not found, skipping rename"
fi

# Step 2: Download Joomla 4.4.14
echo ""
echo "[2/3] Downloading Joomla 4.4.14..."
cd shared-cores
wget -O joomla-4.4.14.zip https://downloads.joomla.org/cms/joomla4/4-4-14/Joomla_4-4-14-Stable-Full_Package.zip
echo "✓ Downloaded Joomla 4.4.14"

# Step 3: Extract Joomla 4.4.14
echo ""
echo "[3/3] Extracting Joomla 4.4.14..."
mkdir -p joomla
unzip -q joomla-4.4.14.zip -d joomla
rm joomla-4.4.14.zip
echo "✓ Extracted to shared-cores/joomla"

cd "$SCRIPT_DIR"

echo ""
echo "========================================="
echo "Joomla versions setup complete!"
echo "========================================="
echo ""
echo "Available versions:"
echo "  - shared-cores/joomla (v4.4.14 - default)"
echo "  - shared-cores/joomla5 (v5.2.1)"
echo ""
echo "Next steps:"
echo "1. Run composer install in shared-cores/joomla/libraries:"
echo "   cd shared-cores/joomla/libraries && composer install --no-dev"
echo ""
