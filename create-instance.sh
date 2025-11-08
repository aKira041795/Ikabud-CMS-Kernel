#!/bin/bash
###############################################################################
# Ikabud Kernel - Instance Creator (Symlink Approach)
# 
# Creates a minimal WordPress instance with symlink to shared core
# Instance-specific wp-content for themes, plugins, and uploads
###############################################################################

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

if [ "$#" -lt 4 ]; then
    echo -e "${RED}Usage: $0 <instance_id> <instance_name> <database_name> <domain>${NC}"
    echo "Example: $0 wp-shop-001 'My Shop' ikabud_shop shop.example.com"
    exit 1
fi

INSTANCE_ID=$1
INSTANCE_NAME=$2
DB_NAME=$3
DOMAIN=$4
INSTANCE_PATH="instances/$INSTANCE_ID"

echo "========================================="
echo "Ikabud Kernel - Instance Creator"
echo "Symlink Approach (Shared Core)"
echo "========================================="
echo ""
echo "Instance ID:   $INSTANCE_ID"
echo "Instance Name: $INSTANCE_NAME"
echo "Database:      $DB_NAME"
echo "Domain:        $DOMAIN"
echo ""

if [ -d "$INSTANCE_PATH" ]; then
    echo -e "${RED}✗ Instance already exists: $INSTANCE_PATH${NC}"
    exit 1
fi

DB_USER=$(grep "^DB_USERNAME=" .env | cut -d '=' -f2)
DB_PASS=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)

# Step 1: Create instance directory structure
echo -e "${YELLOW}[1/6]${NC} Creating instance directory..."
mkdir -p "$INSTANCE_PATH/wp-content"/{plugins,themes,uploads}
echo -e "${GREEN}✓${NC} Created: $INSTANCE_PATH"

# Step 2: Create wp-config.php with instance-specific wp-content paths
echo -e "${YELLOW}[2/6]${NC} Creating wp-config.php..."
cat > "$INSTANCE_PATH/wp-config.php" << WPCONFIG
<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: $INSTANCE_ID
 * Generated: $(date)
 */

// Database Configuration
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASSWORD', '$DB_PASS');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
// TODO: Generate unique keys from https://api.wordpress.org/secret-key/1.1/salt/
define('AUTH_KEY',         'put your unique phrase here');
define('SECURE_AUTH_KEY',  'put your unique phrase here');
define('LOGGED_IN_KEY',    'put your unique phrase here');
define('NONCE_KEY',        'put your unique phrase here');
define('AUTH_SALT',        'put your unique phrase here');
define('SECURE_AUTH_SALT', 'put your unique phrase here');
define('LOGGED_IN_SALT',   'put your unique phrase here');
define('NONCE_SALT',       'put your unique phrase here');

// WordPress Database Table prefix
\$table_prefix = 'wp_';

// WordPress Debugging
define('WP_DEBUG', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder, not shared core
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://$DOMAIN/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', '$INSTANCE_ID');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
WPCONFIG

echo -e "${GREEN}✓${NC} wp-config.php created with instance-specific wp-content"

# Step 3: Create symlink from shared core to instance config
echo -e "${YELLOW}[3/6]${NC} Creating symlink..."
ln -sf ../../$INSTANCE_PATH/wp-config.php shared-cores/wordpress/wp-config.php
echo -e "${GREEN}✓${NC} Symlink: shared-cores/wordpress/wp-config.php → $INSTANCE_PATH/wp-config.php"

# Step 4: Create database
echo -e "${YELLOW}[4/6]${NC} Creating database..."
mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null
echo -e "${GREEN}✓${NC} Database: $DB_NAME"

# Step 5: Register in kernel
echo -e "${YELLOW}[5/6]${NC} Registering in kernel..."
mysql -u "$DB_USER" -p"$DB_PASS" ikabud_kernel << SQLEOF 2>/dev/null
INSERT INTO instances (
    instance_id, instance_name, cms_type, domain, 
    path_prefix, database_name, database_prefix, status
) VALUES (
    '$INSTANCE_ID', '$INSTANCE_NAME', 'wordpress', '$DOMAIN',
    '/', '$DB_NAME', 'wp_', 'active'
) ON DUPLICATE KEY UPDATE
    instance_name = '$INSTANCE_NAME',
    domain = '$DOMAIN',
    database_name = '$DB_NAME';
SQLEOF

echo -e "${GREEN}✓${NC} Registered in kernel"

# Step 6: Set permissions
echo -e "${YELLOW}[6/6]${NC} Setting permissions..."
chown -R www-data:www-data "$INSTANCE_PATH/wp-content"
chmod -R 755 "$INSTANCE_PATH"
chmod 644 "$INSTANCE_PATH/wp-config.php"
chmod -R 775 "$INSTANCE_PATH/wp-content"
echo -e "${GREEN}✓${NC} Permissions set (www-data owns wp-content)"

echo ""
echo "========================================="
echo -e "${GREEN}✓ Instance Created Successfully!${NC}"
echo "========================================="
echo ""
echo "Instance Details:"
echo "  ID:       $INSTANCE_ID"
echo "  Name:     $INSTANCE_NAME"
echo "  Path:     $(pwd)/$INSTANCE_PATH"
echo "  Database: $DB_NAME"
echo "  Domain:   $DOMAIN"
echo "  Size:     $(du -sh $INSTANCE_PATH | cut -f1)"
echo ""
echo "Architecture:"
echo "  ✓ Shared WordPress core: shared-cores/wordpress/ (81MB)"
echo "  ✓ Instance config: $INSTANCE_PATH/wp-config.php"
echo "  ✓ Instance content: $INSTANCE_PATH/wp-content/ (themes, plugins, uploads)"
echo "  ✓ Symlink: shared-cores/wordpress/wp-config.php → $INSTANCE_PATH/wp-config.php"
echo ""
echo "Next Steps:"
echo ""
echo "1. Add to /etc/hosts:"
echo "   127.0.0.1 $DOMAIN"
echo ""
echo "2. Create Apache vhost:"
echo ""
echo "   sudo nano /etc/apache2/sites-available/$DOMAIN.conf"
echo ""
echo "   <VirtualHost *:80>"
echo "       ServerName $DOMAIN"
echo "       DocumentRoot $(pwd)/shared-cores/wordpress"
echo "       "
echo "       <Directory $(pwd)/shared-cores/wordpress>"
echo "           AllowOverride All"
echo "           Require all granted"
echo "       </Directory>"
echo "   </VirtualHost>"
echo ""
echo "3. Enable site and reload Apache:"
echo "   sudo a2ensite $DOMAIN"
echo "   sudo systemctl reload apache2"
echo ""
echo "4. Install WordPress:"
echo "   http://$DOMAIN/wp-admin/install.php"
echo ""
echo "========================================="
