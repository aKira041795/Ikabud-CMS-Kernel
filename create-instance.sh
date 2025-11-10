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
    echo -e "${RED}Usage: $0 <instance_id> <instance_name> <database_name> <domain> [cms_type] [db_user] [db_pass] [db_host] [db_prefix]${NC}"
    echo "Example: $0 wp-shop-001 'My Shop' ikabud_shop shop.example.com wordpress root password localhost wp_"
    exit 1
fi

INSTANCE_ID=$1
INSTANCE_NAME=$2
DB_NAME=$3
DOMAIN=$4
CMS_TYPE=${5:-wordpress}
DB_USER_PARAM=${6:-}
DB_PASS_PARAM=${7:-}
DB_HOST=${8:-localhost}
DB_PREFIX=${9:-wp_}
INSTANCE_PATH="instances/$INSTANCE_ID"

echo "========================================="
echo "Ikabud Kernel - Instance Creator"
echo "Symlink Approach (Shared Core)"
echo "========================================="
echo ""
echo "Instance ID:   $INSTANCE_ID"
echo "Instance Name: $INSTANCE_NAME"
echo "CMS Type:      $CMS_TYPE"
echo "Database:      $DB_NAME"
echo "Domain:        $DOMAIN"
echo "DB Host:       $DB_HOST"
echo "DB Prefix:     $DB_PREFIX"
echo ""

if [ -d "$INSTANCE_PATH" ]; then
    echo -e "${RED}✗ Instance already exists: $INSTANCE_PATH${NC}"
    exit 1
fi

# Use provided credentials or fall back to .env
if [ -z "$DB_USER_PARAM" ]; then
    DB_USER=$(grep "^DB_USERNAME=" .env | cut -d '=' -f2)
else
    DB_USER=$DB_USER_PARAM
fi

if [ -z "$DB_PASS_PARAM" ]; then
    DB_PASS=$(grep "^DB_PASSWORD=" .env | cut -d '=' -f2)
else
    DB_PASS=$DB_PASS_PARAM
fi

KERNEL_DB=$(grep "^DB_DATABASE=" .env | cut -d '=' -f2)

# Determine shared core path based on CMS type
case $CMS_TYPE in
    wordpress)
        SHARED_CORE="wordpress"
        ;;
    joomla)
        SHARED_CORE="joomla"
        ;;
    drupal)
        SHARED_CORE="drupal"
        ;;
    *)
        echo -e "${RED}✗ Unsupported CMS type: $CMS_TYPE${NC}"
        echo "Supported types: wordpress, joomla, drupal"
        exit 1
        ;;
esac

echo "Shared Core:   shared-cores/$SHARED_CORE"
echo ""

# Step 1: Create instance directory structure
echo -e "${YELLOW}[1/7]${NC} Creating instance directory..."
mkdir -p "$INSTANCE_PATH/wp-content"/{plugins,themes,uploads,mu-plugins}
echo -e "${GREEN}✓${NC} Created: $INSTANCE_PATH"

# Step 2: Create wp-config.php with instance-specific wp-content paths
echo -e "${YELLOW}[2/7]${NC} Creating wp-config.php..."

# Generate unique WordPress security keys
SALT_KEYS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/ 2>/dev/null || echo "")

# If API call fails, generate random keys
if [ -z "$SALT_KEYS" ]; then
    AUTH_KEY=$(openssl rand -base64 64 | tr -d '\n')
    SECURE_AUTH_KEY=$(openssl rand -base64 64 | tr -d '\n')
    LOGGED_IN_KEY=$(openssl rand -base64 64 | tr -d '\n')
    NONCE_KEY=$(openssl rand -base64 64 | tr -d '\n')
    AUTH_SALT=$(openssl rand -base64 64 | tr -d '\n')
    SECURE_AUTH_SALT=$(openssl rand -base64 64 | tr -d '\n')
    LOGGED_IN_SALT=$(openssl rand -base64 64 | tr -d '\n')
    NONCE_SALT=$(openssl rand -base64 64 | tr -d '\n')
    
    SALT_KEYS="define('AUTH_KEY',         '$AUTH_KEY');
define('SECURE_AUTH_KEY',  '$SECURE_AUTH_KEY');
define('LOGGED_IN_KEY',    '$LOGGED_IN_KEY');
define('NONCE_KEY',        '$NONCE_KEY');
define('AUTH_SALT',        '$AUTH_SALT');
define('SECURE_AUTH_SALT', '$SECURE_AUTH_SALT');
define('LOGGED_IN_SALT',   '$LOGGED_IN_SALT');
define('NONCE_SALT',       '$NONCE_SALT');"
fi

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
define('DB_HOST', '$DB_HOST');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
$SALT_KEYS

// WordPress Database Table prefix
\$table_prefix = '$DB_PREFIX';

// WordPress Debugging
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Load instance manifest for configuration
\$manifest_file = __DIR__ . '/instance.json';
\$manifest = file_exists(\$manifest_file) ? json_decode(file_get_contents(\$manifest_file), true) : null;

// Dynamic URLs based on current host to prevent redirects
\$current_host = \$_SERVER['HTTP_HOST'] ?? '$DOMAIN';

// Get admin subdomain from manifest or detect from current host
\$admin_subdomain = \$manifest['admin_subdomain'] ?? 'admin.$DOMAIN';
\$frontend_domain = \$manifest['domain'] ?? '$DOMAIN';

// Determine if this is a backend/admin subdomain
\$is_backend = (
    strpos(\$current_host, 'backend.') === 0 || 
    strpos(\$current_host, 'admin.') === 0 || 
    strpos(\$current_host, 'dashboard.') === 0
);

// Check if WordPress is installed and get URLs from database
\$wp_installed = false;
\$db_siteurl = null;
\$db_home = null;

try {
    \$check_pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", '$DB_USER', '$DB_PASS');
    \$check_result = \$check_pdo->query("SHOW TABLES LIKE '${DB_PREFIX}options'");
    \$wp_installed = (\$check_result && \$check_result->rowCount() > 0);
    
    // If installed, get URLs from database
    if (\$wp_installed) {
        \$url_query = \$check_pdo->query("SELECT option_name, option_value FROM ${DB_PREFIX}options WHERE option_name IN ('siteurl', 'home')");
        while (\$row = \$url_query->fetch(PDO::FETCH_ASSOC)) {
            if (\$row['option_name'] === 'siteurl') {
                \$db_siteurl = \$row['option_value'];
            } elseif (\$row['option_name'] === 'home') {
                \$db_home = \$row['option_value'];
            }
        }
    }
} catch (Exception \$e) {
    \$wp_installed = false;
}

// If accessing frontend and WP not installed, redirect to admin installation
if (!\$is_backend && !\$wp_installed && !defined('WP_INSTALLING')) {
    // Use admin subdomain from manifest
    header('Location: http://' . \$admin_subdomain . '/wp-admin/install.php');
    exit;
}

// Set WP_SITEURL and WP_HOME
if (\$db_siteurl && \$db_home) {
    // WordPress is installed - use URLs from database
    define('WP_SITEURL', \$db_siteurl);
    define('WP_HOME', \$db_home);
} else {
    // WordPress not installed yet - use URLs from manifest
    if (\$is_backend) {
        // Backend subdomain - WordPress admin/API access
        define('WP_SITEURL', 'http://' . \$current_host);
        define('WP_HOME', 'http://' . \$frontend_domain);
    } else {
        // Frontend domain - public site access
        define('WP_SITEURL', 'http://' . \$admin_subdomain);
        define('WP_HOME', 'http://' . \$current_host);
    }
}

// Define admin cookie path
define('ADMIN_COOKIE_PATH', '/wp-admin');

// ** CRITICAL: Cookie Configuration **
// Ensure cookies work correctly when served through Kernel
// Use base domain for cookie sharing across subdomains
\$base_domain = preg_replace('/^(backend|admin|dashboard)\./', '', \$current_host);
define('COOKIE_DOMAIN', '.' . \$base_domain);
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
// Use current host for wp-content URL to avoid cross-domain issues
define('WP_CONTENT_URL', 'http://' . \$current_host . '/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', '$INSTANCE_ID');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/$SHARED_CORE/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
WPCONFIG

echo -e "${GREEN}✓${NC} wp-config.php created with instance-specific wp-content"

# Step 2b: Create instance manifest
echo -e "${YELLOW}[2b/7]${NC} Creating instance manifest..."

cat > "$INSTANCE_PATH/instance.json" << MANIFEST
{
  "instance_id": "$INSTANCE_ID",
  "instance_name": "$INSTANCE_NAME",
  "cms_type": "$CMS_TYPE",
  "domain": "$DOMAIN",
  "admin_subdomain": "admin.$DOMAIN",
  "database": {
    "name": "$DB_NAME",
    "user": "$DB_USER",
    "host": "$DB_HOST",
    "prefix": "$DB_PREFIX"
  },
  "created_at": "$(date -Iseconds)",
  "version": "1.0"
}
MANIFEST

echo -e "${GREEN}✓${NC} Instance manifest created"

# Step 3: Copy CORS configuration files
echo -e "${YELLOW}[3/7]${NC} Setting up CORS configuration..."

# Copy .htaccess template
cp templates/instance.htaccess "$INSTANCE_PATH/.htaccess"
echo -e "${GREEN}✓${NC} Copied .htaccess with CORS configuration"

# Copy WordPress CORS plugin to mu-plugins
cp templates/ikabud-cors.php "$INSTANCE_PATH/wp-content/mu-plugins/ikabud-cors.php"
echo -e "${GREEN}✓${NC} Installed CORS handler plugin (mu-plugins)"

# Copy cache invalidation plugin to mu-plugins
cp templates/ikabud-cache-invalidation.php "$INSTANCE_PATH/wp-content/mu-plugins/ikabud-cache-invalidation.php"
echo -e "${GREEN}✓${NC} Installed cache invalidation plugin (mu-plugins)"

# Step 4: Create symlinks from instance to shared CMS core
echo -e "${YELLOW}[4/7]${NC} Creating symlinks to shared $CMS_TYPE core..."
cd "$INSTANCE_PATH"

# Symlink all CMS core files except config files, content directory, and index.php
for file in ../../shared-cores/$SHARED_CORE/*; do
    filename=$(basename "$file")
    # Skip config files, content directory, and index.php (instance-specific)
    if [[ "$filename" != "wp-config"* ]] && [[ "$filename" != "wp-content" ]] && [[ "$filename" != "index.php" ]] && \
       [[ "$filename" != "configuration.php" ]] && [[ "$filename" != "settings.php" ]]; then
        ln -sf "../../shared-cores/$SHARED_CORE/$filename" "$filename"
    fi
done

# Create instance-specific index.php that ensures correct wp-config is loaded
cat > "index.php" << 'INDEXPHP'
<?php
/**
 * Front to the WordPress application. This file does not do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

// Ensure we load the instance-specific wp-config.php
if ( ! file_exists( __DIR__ . '/wp-config.php' ) ) {
    die( 'wp-config.php not found in instance directory' );
}

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
INDEXPHP

cd - > /dev/null
echo -e "${GREEN}✓${NC} Symlinks created: instance → shared $CMS_TYPE core"

# Step 5: Create database
echo -e "${YELLOW}[5/7]${NC} Creating database..."
mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null
echo -e "${GREEN}✓${NC} Database: $DB_NAME"

# Step 6: Register in kernel
echo -e "${YELLOW}[6/7]${NC} Registering in kernel..."
mysql -u "$DB_USER" -p"$DB_PASS" "$KERNEL_DB" << SQLEOF 2>/dev/null
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

# Step 7: Set permissions
echo -e "${YELLOW}[7/7]${NC} Setting permissions..."
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
echo "  ✓ CORS configuration: $INSTANCE_PATH/.htaccess + mu-plugins/ikabud-cors.php"
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

# Register instance as a process
echo ""
echo "Registering instance as process..."
if [ -f "bin/register-instance-process" ]; then
    ./bin/register-instance-process "$INSTANCE_ID" "$INSTANCE_NAME" "wordpress"
else
    echo "⚠ Process registration script not found (skipping)"
fi
