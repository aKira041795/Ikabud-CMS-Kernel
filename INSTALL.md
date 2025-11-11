# Ikabud Kernel - Installation Guide

**Version**: 1.0.0  
**Last Updated**: November 2025

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Quick Installation](#quick-installation)
3. [Manual Installation](#manual-installation)
4. [Post-Installation](#post-installation)
5. [Verification](#verification)
6. [Troubleshooting](#troubleshooting)
7. [Uninstallation](#uninstallation)

---

## Prerequisites

### System Requirements

- **OS**: Linux (Ubuntu 20.04+, Debian 10+, CentOS 8+, or similar)
- **PHP**: 8.1 or higher
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Memory**: Minimum 2GB RAM (4GB+ recommended)
- **Disk Space**: Minimum 2GB free space

### Required PHP Extensions

```bash
php-cli
php-fpm
php-mysql
php-json
php-mbstring
php-xml
php-curl
php-zip
php-gd
```

### Required System Tools

```bash
composer
git
curl
systemctl (for process management)
```

### Optional but Recommended

```bash
redis-server (for caching)
memcached (alternative caching)
nodejs & npm (for admin UI development)
```

---

## Quick Installation

### Option 1: PHP CLI Installer (Recommended for Shared Hosting)

Perfect for shared hosting environments where shell scripts aren't allowed:

```bash
# Via command line
php install.php

# Or via web browser
# Navigate to: http://yourdomain.com/install.php
```

**Features:**
- Works on shared hosting
- Web-based installation wizard
- No shell access required
- Interactive configuration
- Automatic database setup

### Option 2: Shell Script Installer (VPS/Dedicated Servers)

```bash
# Download the installer
curl -O https://raw.githubusercontent.com/yourusername/ikabud-kernel/main/install.sh

# Make it executable
chmod +x install.sh

# Run the installer
sudo ./install.sh
```

Both installers will:
1. Check system requirements
2. Install dependencies
3. Configure the database
4. Set up file permissions
5. Configure the web server (shell script only)
6. Create systemd services (shell script only)
7. Initialize the kernel

---

## Manual Installation

### Step 1: Clone the Repository

```bash
# Navigate to your web root
cd /var/www/html

# Clone the repository
git clone https://github.com/yourusername/ikabud-kernel.git
cd ikabud-kernel

# Or download and extract the release package
wget https://github.com/yourusername/ikabud-kernel/archive/v1.0.0.tar.gz
tar -xzf v1.0.0.tar.gz
cd ikabud-kernel-1.0.0
```

### Step 2: Install PHP Dependencies

```bash
# Install Composer if not already installed
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install dependencies
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

```bash
# Copy the example environment file
cp .env.example .env

# Edit the configuration
nano .env
```

**Required Configuration:**

```env
# Application
APP_NAME="Ikabud Kernel"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-domain.com

# JWT Secret (IMPORTANT: Generate a secure key!)
JWT_SECRET=$(openssl rand -base64 32)

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ikabud_kernel
DB_USERNAME=ikabud_user
DB_PASSWORD=your_secure_password

# Admin Credentials (Change after first login!)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=change_this_password
ADMIN_EMAIL=admin@yourdomain.com
```

### Step 4: Create Database

```bash
# Login to MySQL
mysql -u root -p

# Create database and user
CREATE DATABASE ikabud_kernel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ikabud_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON ikabud_kernel.* TO 'ikabud_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Import the schema
mysql -u ikabud_user -p ikabud_kernel < database/schema.sql
```

### Step 5: Set File Permissions

```bash
# Set ownership (adjust www-data to your web server user)
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel

# Set directory permissions
sudo find /var/www/html/ikabud-kernel -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/html/ikabud-kernel -type f -exec chmod 644 {} \;

# Make CLI tool executable
sudo chmod +x /var/www/html/ikabud-kernel/ikabud
sudo chmod +x /var/www/html/ikabud-kernel/bin/*

# Set writable directories
sudo chmod -R 775 storage instances themes logs
sudo chmod -R 775 public/admin/assets

# Create required directories if they don't exist
mkdir -p storage/cache storage/logs
mkdir -p instances themes logs
```

### Step 6: Configure Web Server

#### Apache Configuration

```bash
# Create virtual host configuration
sudo nano /etc/apache2/sites-available/ikabud-kernel.conf
```

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/html/ikabud-kernel/public

    <Directory /var/www/html/ikabud-kernel/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/ikabud-kernel-error.log
    CustomLog ${APACHE_LOG_DIR}/ikabud-kernel-access.log combined

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
```

```bash
# Enable required modules
sudo a2enmod rewrite headers

# Enable the site
sudo a2ensite ikabud-kernel.conf

# Disable default site (optional)
sudo a2dissite 000-default.conf

# Test configuration
sudo apache2ctl configtest

# Restart Apache
sudo systemctl restart apache2
```

#### Nginx Configuration

```bash
# Create server block
sudo nano /etc/nginx/sites-available/ikabud-kernel
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/html/ikabud-kernel/public;

    index index.php index.html;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Logging
    access_log /var/log/nginx/ikabud-kernel-access.log;
    error_log /var/log/nginx/ikabud-kernel-error.log;
}
```

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/ikabud-kernel /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Restart Nginx
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm
```

### Step 7: Install CLI Tool

```bash
# Create symbolic link for global access
sudo ln -s /var/www/html/ikabud-kernel/ikabud /usr/local/bin/ikabud

# Verify installation
ikabud help
```

### Step 8: Initialize the Kernel

```bash
# Test kernel boot
php public/index.php

# Or via web
curl http://your-domain.com/api/health
```

---

## Post-Installation

### 1. Secure Your Installation

```bash
# Change default admin password immediately
# Login to admin panel and update credentials

# Generate a new JWT secret
openssl rand -base64 32

# Update .env file with the new secret
nano .env
```

### 2. Configure SSL/TLS (Recommended)

```bash
# Using Let's Encrypt (Certbot)
sudo apt install certbot python3-certbot-apache  # For Apache
# OR
sudo apt install certbot python3-certbot-nginx   # For Nginx

# Obtain certificate
sudo certbot --apache -d your-domain.com -d www.your-domain.com
# OR
sudo certbot --nginx -d your-domain.com -d www.your-domain.com

# Auto-renewal is configured automatically
```

### 3. Set Up Cron Jobs (Optional)

```bash
# Edit crontab
crontab -e

# Add these lines:
# Clean old logs daily at 2 AM
0 2 * * * /usr/local/bin/ikabud cleanup-logs

# Monitor instance health every 5 minutes
*/5 * * * * /usr/local/bin/ikabud health-check-all

# Backup database daily at 3 AM
0 3 * * * /var/www/html/ikabud-kernel/bin/backup-database.sh
```

### 4. Configure Firewall

```bash
# Allow HTTP and HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Enable firewall
sudo ufw enable
```

### 5. Install Admin UI (Optional)

```bash
cd admin

# Install dependencies
npm install

# Build for production
npm run build

# The built files are already in admin/dist
# They will be served automatically
```

---

## Verification

### 1. Check Kernel Status

```bash
# Via CLI
ikabud status

# Via API
curl http://your-domain.com/api/health
```

Expected response:
```json
{
  "status": "ok",
  "kernel": {
    "version": "1.0.0",
    "booted": true,
    "uptime": 0.123,
    "syscalls_registered": 10,
    "processes_running": 0
  }
}
```

### 2. Access Admin Panel

Open your browser and navigate to:
```
http://your-domain.com/admin
```

Login with your admin credentials.

### 3. Create Test Instance

```bash
# Create a WordPress test instance
ikabud create wp-test-001

# Start the instance
ikabud start wp-test-001

# Check status
ikabud status wp-test-001
```

### 4. Run System Tests

```bash
# Test kernel boot
php test-instance-boot.php

# Test CMS adapters
php test-cms-adapters.php

# Test DSL compiler
php test-dsl.php
```

---

## Troubleshooting

### Common Issues

#### 1. Database Connection Failed

```bash
# Check database credentials in .env
nano .env

# Test database connection
mysql -u ikabud_user -p ikabud_kernel

# Check if database exists
mysql -u root -p -e "SHOW DATABASES LIKE 'ikabud_kernel';"
```

#### 2. Permission Denied Errors

```bash
# Reset permissions
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel
sudo chmod -R 775 storage instances themes logs
```

#### 3. 500 Internal Server Error

```bash
# Check PHP error logs
sudo tail -f /var/log/apache2/ikabud-kernel-error.log
# OR
sudo tail -f /var/log/nginx/ikabud-kernel-error.log

# Check application logs
tail -f storage/logs/kernel.log

# Enable debug mode temporarily
nano .env
# Set: APP_DEBUG=true
```

#### 4. Composer Install Fails

```bash
# Update Composer
composer self-update

# Clear cache
composer clear-cache

# Install with verbose output
composer install -vvv
```

#### 5. Instance Won't Start

```bash
# Check instance status
ikabud status <instance-id>

# Check systemd logs
sudo journalctl -u ikabud-<instance-id> -n 50

# Restart the instance
ikabud restart <instance-id>
```

### Getting Help

- **Documentation**: Check `/docs` directory
- **GitHub Issues**: https://github.com/yourusername/ikabud-kernel/issues
- **Community Forum**: https://community.ikabud.com
- **Email Support**: support@ikabud.com

---

## Uninstallation

### Complete Removal

```bash
# Stop all instances
ikabud list
ikabud stop <instance-id>  # For each running instance

# Remove systemd services
sudo systemctl stop ikabud-*
sudo systemctl disable ikabud-*
sudo rm /etc/systemd/system/ikabud-*.service
sudo systemctl daemon-reload

# Remove CLI tool
sudo rm /usr/local/bin/ikabud

# Remove web server configuration
sudo a2dissite ikabud-kernel.conf  # Apache
sudo rm /etc/apache2/sites-available/ikabud-kernel.conf
# OR
sudo rm /etc/nginx/sites-enabled/ikabud-kernel  # Nginx
sudo rm /etc/nginx/sites-available/ikabud-kernel

# Restart web server
sudo systemctl restart apache2  # OR nginx

# Remove application files
sudo rm -rf /var/www/html/ikabud-kernel

# Drop database (CAUTION: This deletes all data!)
mysql -u root -p -e "DROP DATABASE ikabud_kernel; DROP USER 'ikabud_user'@'localhost';"
```

### Keep Data, Remove Application Only

```bash
# Backup database first
mysqldump -u ikabud_user -p ikabud_kernel > ikabud_kernel_backup.sql

# Remove only application files
sudo rm -rf /var/www/html/ikabud-kernel

# Keep database for future reinstallation
```

---

## Next Steps

After successful installation:

1. **Read the Documentation**: Explore `/docs` for detailed guides
2. **Create Your First Instance**: Follow the Quick Start guide
3. **Build Custom Themes**: Learn the DSL system
4. **Configure Multi-CMS Setup**: Run WordPress, Joomla, and Drupal together
5. **Monitor Performance**: Use the admin dashboard

---

## Support

For installation support:
- Check the [FAQ](docs/FAQ.md)
- Visit [Documentation](docs/)
- Open an [Issue](https://github.com/yourusername/ikabud-kernel/issues)

---

**Congratulations! Ikabud Kernel is now installed and ready to use.** 🚀
