#!/bin/bash

# Joomla Instance Creator for Ikabud Kernel
# Usage: ./create-joomla-instance.sh <instance_id> <domain> <db_name> <db_user> <db_pass>

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check arguments
if [ "$#" -lt 5 ]; then
    echo -e "${RED}Usage: $0 <instance_id> <domain> <db_name> <db_user> <db_pass> [db_prefix]${NC}"
    echo "Example: $0 joomla-002 joomla2.test ikabud_joomla2 root password jml_"
    exit 1
fi

INSTANCE_ID="$1"
DOMAIN="$2"
DB_NAME="$3"
DB_USER="$4"
DB_PASS="$5"
DB_PREFIX="${6:-jml_}"

INSTANCE_PATH="instances/$INSTANCE_ID"
SHARED_CORE="shared-cores/joomla"

echo -e "${YELLOW}Creating Joomla instance: $INSTANCE_ID${NC}"

# Step 1: Create instance directory structure
echo -e "${YELLOW}[1/6]${NC} Creating instance directory structure..."
mkdir -p "$INSTANCE_PATH"
mkdir -p "$INSTANCE_PATH/administrator/cache"
mkdir -p "$INSTANCE_PATH/administrator/logs"
mkdir -p "$INSTANCE_PATH/administrator/manifests"
mkdir -p "$INSTANCE_PATH/cache"
mkdir -p "$INSTANCE_PATH/tmp"
mkdir -p "$INSTANCE_PATH/images"

echo -e "${GREEN}✓${NC} Directory structure created"

# Step 2: Copy template files
echo -e "${YELLOW}[2/6]${NC} Copying template files..."

# Copy defines.php
cp templates/joomla-defines.php "$INSTANCE_PATH/defines.php"

# Copy site index.php
cp templates/joomla-site-index.php "$INSTANCE_PATH/index.php"

# Copy .htaccess
cp templates/instance.htaccess "$INSTANCE_PATH/.htaccess"

echo -e "${GREEN}✓${NC} Template files copied"

# Step 3: Create administrator directory with custom index.php
echo -e "${YELLOW}[3/6]${NC} Setting up administrator directory..."

mkdir -p "$INSTANCE_PATH/administrator"
cp templates/joomla-admin-index.php "$INSTANCE_PATH/administrator/index.php"

echo -e "${GREEN}✓${NC} Administrator setup complete"

# Step 4: Create symlinks to shared core
echo -e "${YELLOW}[4/6]${NC} Creating symlinks to shared core..."

# Symlink administrator directories
ln -sf "../../$SHARED_CORE/administrator/components" "$INSTANCE_PATH/administrator/components"
ln -sf "../../$SHARED_CORE/administrator/help" "$INSTANCE_PATH/administrator/help"
ln -sf "../../$SHARED_CORE/administrator/includes" "$INSTANCE_PATH/administrator/includes"
ln -sf "../../$SHARED_CORE/administrator/language" "$INSTANCE_PATH/administrator/language"
ln -sf "../../$SHARED_CORE/administrator/manifests" "$INSTANCE_PATH/administrator/manifests"
ln -sf "../../$SHARED_CORE/administrator/modules" "$INSTANCE_PATH/administrator/modules"
ln -sf "../../$SHARED_CORE/administrator/templates" "$INSTANCE_PATH/administrator/templates"

# Symlink site directories
ln -sf "../$SHARED_CORE/components" "$INSTANCE_PATH/components"
ln -sf "../$SHARED_CORE/language" "$INSTANCE_PATH/language"
ln -sf "../$SHARED_CORE/layouts" "$INSTANCE_PATH/layouts"
ln -sf "../$SHARED_CORE/media" "$INSTANCE_PATH/media"
ln -sf "../$SHARED_CORE/modules" "$INSTANCE_PATH/modules"
ln -sf "../$SHARED_CORE/plugins" "$INSTANCE_PATH/plugins"
ln -sf "../$SHARED_CORE/templates" "$INSTANCE_PATH/templates"

# Symlink additional Joomla files and directories
ln -sf "../$SHARED_CORE/api" "$INSTANCE_PATH/api"
ln -sf "../$SHARED_CORE/cli" "$INSTANCE_PATH/cli"
ln -sf "../$SHARED_CORE/includes" "$INSTANCE_PATH/includes"
ln -sf "../$SHARED_CORE/libraries" "$INSTANCE_PATH/libraries"
ln -sf "../$SHARED_CORE/installation" "$INSTANCE_PATH/installation"
ln -sf "../$SHARED_CORE/htaccess.txt" "$INSTANCE_PATH/htaccess.txt"
ln -sf "../$SHARED_CORE/LICENSE.txt" "$INSTANCE_PATH/LICENSE.txt"
ln -sf "../$SHARED_CORE/README.txt" "$INSTANCE_PATH/README.txt"
ln -sf "../$SHARED_CORE/robots.txt.dist" "$INSTANCE_PATH/robots.txt.dist"
ln -sf "../$SHARED_CORE/web.config.txt" "$INSTANCE_PATH/web.config.txt"

# Create symlink for autoload_psr4.php in shared core
mkdir -p "$SHARED_CORE/administrator/cache"
ln -sf ../../../../instances/$INSTANCE_ID/administrator/cache/autoload_psr4.php "$SHARED_CORE/administrator/cache/autoload_psr4.php"

echo -e "${GREEN}✓${NC} Symlinks created"

# Step 5: Create instance manifest
echo -e "${YELLOW}[5/6]${NC} Creating instance manifest..."

cat > "$INSTANCE_PATH/instance.json" << MANIFEST
{
  "instance_id": "$INSTANCE_ID",
  "cms_type": "joomla",
  "domain": "$DOMAIN",
  "admin_subdomain": "admin.$DOMAIN",
  "database": {
    "name": "$DB_NAME",
    "user": "$DB_USER",
    "host": "localhost",
    "prefix": "$DB_PREFIX"
  },
  "created_at": "$(date -Iseconds)",
  "version": "1.0"
}
MANIFEST

echo -e "${GREEN}✓${NC} Instance manifest created"

# Step 6: Set permissions
echo -e "${YELLOW}[6/6]${NC} Setting permissions..."

chmod -R 755 "$INSTANCE_PATH"
chmod -R 777 "$INSTANCE_PATH/administrator/cache"
chmod -R 777 "$INSTANCE_PATH/administrator/logs"
chmod -R 777 "$INSTANCE_PATH/cache"
chmod -R 777 "$INSTANCE_PATH/tmp"
chmod -R 777 "$INSTANCE_PATH/images"

echo -e "${GREEN}✓${NC} Permissions set"

# Summary
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Joomla Instance Created Successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "Instance ID: ${YELLOW}$INSTANCE_ID${NC}"
echo -e "Domain: ${YELLOW}$DOMAIN${NC}"
echo -e "Admin URL: ${YELLOW}admin.$DOMAIN${NC}"
echo -e "Database: ${YELLOW}$DB_NAME${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Create database: mysql -u root -p -e \"CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
echo "2. Configure Apache virtual hosts for $DOMAIN and admin.$DOMAIN"
echo "3. Add entries to /etc/hosts if testing locally"
echo "4. Access http://admin.$DOMAIN/installation/ to complete Joomla installation"
echo "5. After installation, remove the installation directory"
echo ""
