#!/bin/bash
# Setup Drupal versions - rename v11 and install v9 as default

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Setting up Drupal versions..."
echo ""

# Step 1: Rename current Drupal 11.0.5 to drupal11
if [ -d "shared-cores/drupal" ]; then
    echo "[1/3] Renaming Drupal 11.0.5 to drupal11..."
    mv shared-cores/drupal shared-cores/drupal11
    echo "✓ Renamed to drupal11"
else
    echo "[1/3] Drupal directory not found, skipping rename"
fi

# Step 2: Download Drupal 10.3.10 (supports MySQL 5.7 AND PHP 8.3)
echo ""
echo "[2/3] Downloading Drupal 10.3.10..."
cd shared-cores
wget -O drupal-10.3.10.tar.gz https://ftp.drupal.org/files/projects/drupal-10.3.10.tar.gz
echo "✓ Downloaded Drupal 10.3.10"

# Step 3: Extract Drupal 10.3.10
echo ""
echo "[3/3] Extracting Drupal 10.3.10..."
tar -xzf drupal-10.3.10.tar.gz
mv drupal-10.3.10 drupal
rm drupal-10.3.10.tar.gz
echo "✓ Extracted to shared-cores/drupal"

cd "$SCRIPT_DIR"

echo ""
echo "========================================="
echo "Drupal versions setup complete!"
echo "========================================="
echo ""
echo "Available versions:"
echo "  - shared-cores/drupal (v10.3.10 - default, MySQL 5.7+ & PHP 8.3 compatible)"
echo "  - shared-cores/drupal11 (v11.0.5 - requires MySQL 8.0+)"
echo ""
echo "Next steps:"
echo "1. Install Composer dependencies in shared-cores/drupal:"
echo "   cd shared-cores/drupal && composer install --no-dev"
echo ""
echo "2. Install Drush:"
echo "   cd shared-cores/drupal && composer require drush/drush --no-dev"
echo ""
