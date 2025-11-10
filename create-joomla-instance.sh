#!/bin/bash

# Joomla Instance Creator for Ikabud Kernel
# Usage: ./create-joomla-instance.sh <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> [db_prefix]

set -e  # Exit on error
set -u  # Exit on undefined variable

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check arguments
if [ "$#" -lt 6 ]; then
    echo -e "${RED}Usage: $0 <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> [db_prefix]${NC}"
    echo "Example: $0 joomla-002 'My Joomla Site' joomla2.test ikabud_joomla2 root password jml_"
    exit 1
fi

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

INSTANCE_ID="$1"
INSTANCE_NAME="$2"
DOMAIN="$3"
DB_NAME="$4"
DB_USER="$5"
DB_PASS="$6"
DB_PREFIX="${7:-jml_}"

INSTANCE_PATH="instances/$INSTANCE_ID"
SHARED_CORE="shared-cores/joomla"
SECRET_KEY="ikabud_${INSTANCE_ID}_secret_$(openssl rand -hex 16)"

# Validate instance doesn't already exist
if [ -d "$INSTANCE_PATH" ]; then
    echo -e "${RED}Error: Instance directory already exists: $INSTANCE_PATH${NC}"
    exit 1
fi

# Validate shared core exists
if [ ! -d "$SHARED_CORE" ]; then
    echo -e "${RED}Error: Shared Joomla core not found: $SHARED_CORE${NC}"
    exit 1
fi

echo -e "${YELLOW}Creating Joomla instance: $INSTANCE_ID${NC}"
echo -e "Instance Name: $INSTANCE_NAME"
echo -e "Domain: $DOMAIN"
echo -e "Database: $DB_NAME"

# Step 1: Create instance directory structure
echo -e "${YELLOW}[1/9]${NC} Creating instance directory structure..."
mkdir -p "$INSTANCE_PATH"
mkdir -p "$INSTANCE_PATH/administrator/cache"
mkdir -p "$INSTANCE_PATH/administrator/logs"
mkdir -p "$INSTANCE_PATH/administrator/manifests"
mkdir -p "$INSTANCE_PATH/images/banners"
mkdir -p "$INSTANCE_PATH/images/headers"
mkdir -p "$INSTANCE_PATH/images/sampledata"
mkdir -p "$INSTANCE_PATH/tmp"

echo -e "${GREEN}✓${NC} Directory structure created"

# Step 2: Copy template files
echo -e "${YELLOW}[2/9]${NC} Copying template files..."

# Copy defines.php
cp templates/joomla-defines.php "$INSTANCE_PATH/defines.php"

# Copy site index.php
cp templates/joomla-site-index.php "$INSTANCE_PATH/index.php"

# Copy .htaccess
cp templates/instance.htaccess "$INSTANCE_PATH/.htaccess"

echo -e "${GREEN}✓${NC} Template files copied"

# Step 3: Create administrator directory with custom index.php
echo -e "${YELLOW}[3/9]${NC} Setting up administrator directory..."

mkdir -p "$INSTANCE_PATH/administrator"
cp templates/joomla-admin-index.php "$INSTANCE_PATH/administrator/index.php"

echo -e "${GREEN}✓${NC} Administrator setup complete"

# Step 4: Create symlinks to shared core
echo -e "${YELLOW}[4/9]${NC} Creating symlinks to shared Joomla core..."

# Symlink administrator directories (use ../../../ - three levels up from administrator/)
ln -sf "../../../$SHARED_CORE/administrator/components" "$INSTANCE_PATH/administrator/components"
ln -sf "../../../$SHARED_CORE/administrator/help" "$INSTANCE_PATH/administrator/help"
ln -sf "../../../$SHARED_CORE/administrator/includes" "$INSTANCE_PATH/administrator/includes"
ln -sf "../../../$SHARED_CORE/administrator/language" "$INSTANCE_PATH/administrator/language"
ln -sf "../../../$SHARED_CORE/administrator/manifests" "$INSTANCE_PATH/administrator/manifests"
ln -sf "../../../$SHARED_CORE/administrator/modules" "$INSTANCE_PATH/administrator/modules"
ln -sf "../../../$SHARED_CORE/administrator/templates" "$INSTANCE_PATH/administrator/templates"

# Symlink site directories (use ../../ - two levels up from instance root)
ln -sf "../../$SHARED_CORE/components" "$INSTANCE_PATH/components"
ln -sf "../../$SHARED_CORE/language" "$INSTANCE_PATH/language"
ln -sf "../../$SHARED_CORE/layouts" "$INSTANCE_PATH/layouts"
ln -sf "../../$SHARED_CORE/modules" "$INSTANCE_PATH/modules"
ln -sf "../../$SHARED_CORE/plugins" "$INSTANCE_PATH/plugins"

# Symlink cache to shared core (read-only cache)
ln -sf "../../$SHARED_CORE/cache" "$INSTANCE_PATH/cache"

# Copy media directory from shared core (instance-specific for user uploads)
cp -r "$SHARED_CORE/media" "$INSTANCE_PATH/media"

# Copy templates directory from shared core (instance-specific for customizations)
cp -r "$SHARED_CORE/templates" "$INSTANCE_PATH/templates"

# Copy default images from shared core
cp "$SHARED_CORE/images/"*.png "$INSTANCE_PATH/images/" 2>/dev/null || true
cp "$SHARED_CORE/images/"*.html "$INSTANCE_PATH/images/" 2>/dev/null || true
cp -r "$SHARED_CORE/images/banners/"* "$INSTANCE_PATH/images/banners/" 2>/dev/null || true
cp -r "$SHARED_CORE/images/headers/"* "$INSTANCE_PATH/images/headers/" 2>/dev/null || true
cp -r "$SHARED_CORE/images/sampledata/"* "$INSTANCE_PATH/images/sampledata/" 2>/dev/null || true

# Symlink additional Joomla files and directories
ln -sf "../../$SHARED_CORE/api" "$INSTANCE_PATH/api"
ln -sf "../../$SHARED_CORE/cli" "$INSTANCE_PATH/cli"
ln -sf "../../$SHARED_CORE/includes" "$INSTANCE_PATH/includes"
ln -sf "../../$SHARED_CORE/libraries" "$INSTANCE_PATH/libraries"
ln -sf "../../$SHARED_CORE/installation" "$INSTANCE_PATH/installation"
ln -sf "../../$SHARED_CORE/htaccess.txt" "$INSTANCE_PATH/htaccess.txt"
ln -sf "../../$SHARED_CORE/LICENSE.txt" "$INSTANCE_PATH/LICENSE.txt"
ln -sf "../../$SHARED_CORE/README.txt" "$INSTANCE_PATH/README.txt"
ln -sf "../../$SHARED_CORE/robots.txt.dist" "$INSTANCE_PATH/robots.txt.dist"
ln -sf "../../$SHARED_CORE/web.config.txt" "$INSTANCE_PATH/web.config.txt"

echo -e "${GREEN}✓${NC} Symlinks created"

# Step 5: Create database
echo -e "${YELLOW}[5/9]${NC} Creating database..."

if mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null; then
    echo -e "${GREEN}✓${NC} Database created: $DB_NAME"
else
    echo -e "${YELLOW}⚠${NC} Database creation failed or already exists. Continuing..."
fi

# Step 6: Create instance manifest
echo -e "${YELLOW}[6/9]${NC} Creating instance manifest..."

cat > "$INSTANCE_PATH/instance.json" << MANIFEST
{
  "instance_id": "$INSTANCE_ID",
  "instance_name": "$INSTANCE_NAME",
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

# Step 7: Create initial configuration.php
echo -e "${YELLOW}[7/9]${NC} Creating initial configuration.php..."

cat > "$INSTANCE_PATH/configuration.php" << CONFIG
<?php
class JConfig
{
	public \$offline = false;
	public \$offline_message = 'This site is down for maintenance.<br>Please check back again soon.';
	public \$display_offline_message = 1;
	public \$offline_image = '';
	public \$sitename = 'Joomla Site - $INSTANCE_ID';
	public \$editor = 'tinymce';
	public \$captcha = '0';
	public \$list_limit = 20;
	public \$access = 1;
	public \$frontediting = 1;
	public \$debug = false;
	public \$debug_lang = false;
	public \$debug_lang_const = true;
	public \$dbtype = 'mysqli';
	public \$host = 'localhost';
	public \$user = '$DB_USER';
	public \$password = '$DB_PASS';
	public \$db = '$DB_NAME';
	public \$dbprefix = '$DB_PREFIX';
	public \$dbencryption = 0;
	public \$dbsslverifyservercert = false;
	public \$dbsslkey = '';
	public \$dbsslcert = '';
	public \$dbsslca = '';
	public \$dbsslcipher = '';
	public \$secret = '$SECRET_KEY';
	public \$gzip = false;
	public \$error_reporting = 'default';
	public \$helpurl = 'https://help.joomla.org/proxy?keyref=Help{major}{minor}:{keyref}&lang={langcode}';
	public \$tmp_path = '$SCRIPT_DIR/$INSTANCE_PATH/tmp';
	public \$log_path = '$SCRIPT_DIR/$INSTANCE_PATH/administrator/logs';
	public \$live_site = '';
	public \$force_ssl = 0;
	public \$offset = 'UTC';
	public \$mailonline = true;
	public \$mailer = 'mail';
	public \$mailfrom = 'admin@$DOMAIN';
	public \$fromname = 'Joomla Site - $INSTANCE_ID';
	public \$sendmail = '/usr/sbin/sendmail';
	public \$smtpauth = false;
	public \$smtpuser = '';
	public \$smtppass = '';
	public \$smtphost = 'localhost';
	public \$smtpsecure = 'none';
	public \$smtpport = 25;
	public \$caching = 0;
	public \$cache_handler = 'file';
	public \$cachetime = 15;
	public \$cache_platformprefix = false;
	public \$MetaDesc = '';
	public \$MetaAuthor = true;
	public \$MetaVersion = false;
	public \$robots = '';
	public \$sef = true;
	public \$sef_rewrite = true;
	public \$sef_suffix = false;
	public \$unicodeslugs = false;
	public \$feed_limit = 10;
	public \$feed_email = 'none';
	public \$lifetime = 15;
	public \$session_handler = 'database';
	public \$shared_session = false;
	public \$session_filesystem_path = '';
	public \$session_memcached_server_host = 'localhost';
	public \$session_memcached_server_port = 11211;
	public \$session_metadata = true;
	public \$session_redis_persist = 1;
	public \$session_redis_server_auth = '';
	public \$session_redis_server_db = 0;
	public \$session_redis_server_host = 'localhost';
	public \$session_redis_server_port = 6379;
	public \$proxy_enable = false;
	public \$proxy_host = '';
	public \$proxy_port = '';
	public \$proxy_user = '';
	public \$proxy_pass = '';
	public \$massmailoff = false;
	public \$replyto = '';
	public \$replytoname = '';
	public \$MetaRights = '';
	public \$sitename_pagetitles = 0;
	public \$cookie_domain = '';
	public \$cookie_path = '';
	public \$asset_id = 1;
}
CONFIG

echo -e "${GREEN}✓${NC} Configuration file created"

# Step 8: Set permissions
echo -e "${YELLOW}[8/9]${NC} Setting permissions..."

chmod -R 755 "$INSTANCE_PATH"
chmod 644 "$INSTANCE_PATH/configuration.php"
chmod -R 775 "$INSTANCE_PATH/administrator/cache"
chmod -R 775 "$INSTANCE_PATH/administrator/logs"
chmod -R 775 "$INSTANCE_PATH/administrator/manifests"
chmod -R 775 "$INSTANCE_PATH/images"
chmod -R 775 "$INSTANCE_PATH/tmp"
chmod -R 775 "$INSTANCE_PATH/media"
chmod -R 755 "$INSTANCE_PATH/templates"
chown -R www-data:www-data "$INSTANCE_PATH/administrator/cache" 2>/dev/null || true
chown -R www-data:www-data "$INSTANCE_PATH/administrator/logs" 2>/dev/null || true
chown -R www-data:www-data "$INSTANCE_PATH/administrator/manifests" 2>/dev/null || true
chown -R www-data:www-data "$INSTANCE_PATH/images" 2>/dev/null || true
chown -R www-data:www-data "$INSTANCE_PATH/tmp" 2>/dev/null || true
chown -R www-data:www-data "$INSTANCE_PATH/media" 2>/dev/null || true

echo -e "${GREEN}✓${NC} Permissions set"

# Step 9: Register instance as a process
echo -e "${YELLOW}[9/9]${NC} Registering instance as process..."

if [ -f "bin/register-instance-process" ]; then
    ./bin/register-instance-process "$INSTANCE_ID" "$INSTANCE_NAME" "joomla" 2>&1 | sed 's/^/  /'
else
    echo -e "${YELLOW}⚠${NC} Process registration script not found (skipping)"
fi

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
echo "1. Configure Apache virtual hosts for $DOMAIN"
echo "2. Add entries to /etc/hosts if testing locally:"
echo "   127.0.0.1 $DOMAIN"
echo "3. Complete Joomla installation:"
echo -e "   ${GREEN}http://$DOMAIN/installation/setup${NC}"
echo "4. After installation completes, the installation directory will be automatically removed"
echo ""
echo -e "${YELLOW}Database Details:${NC}"
echo "   Database Name: $DB_NAME"
echo "   Database User: $DB_USER"
echo "   Database Prefix: $DB_PREFIX"
echo "   Database Host: localhost"
echo ""
