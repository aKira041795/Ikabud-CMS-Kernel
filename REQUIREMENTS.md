# Ikabud Kernel - System Requirements

**Version**: 1.0.0  
**Last Updated**: November 2025

---

## Minimum Requirements

### Operating System

- **Linux Distributions** (Recommended):
  - Ubuntu 20.04 LTS or higher
  - Debian 10 (Buster) or higher
  - CentOS 8 or higher
  - RHEL 8 or higher
  - Fedora 33 or higher
  - Rocky Linux 8 or higher
  - AlmaLinux 8 or higher

- **Other Unix-like Systems** (May work but not officially supported):
  - FreeBSD 12+
  - OpenBSD 6.8+

### Hardware

| Component | Minimum | Recommended | Production |
|-----------|---------|-------------|------------|
| **CPU** | 1 core @ 2.0 GHz | 2 cores @ 2.5 GHz | 4+ cores @ 3.0 GHz |
| **RAM** | 2 GB | 4 GB | 8+ GB |
| **Disk Space** | 2 GB | 10 GB | 50+ GB SSD |
| **Network** | 10 Mbps | 100 Mbps | 1 Gbps |

### PHP

- **Version**: 8.1 or higher (8.2+ recommended)
- **Configuration**:
  - `memory_limit`: 256M minimum (512M+ recommended)
  - `max_execution_time`: 300 seconds minimum
  - `upload_max_filesize`: 64M minimum
  - `post_max_size`: 64M minimum

### Database

- **MySQL**: 8.0 or higher
- **MariaDB**: 10.5 or higher
- **Configuration**:
  - `max_connections`: 100 minimum
  - `innodb_buffer_pool_size`: 256M minimum (1G+ recommended)
  - Character set: UTF8MB4

### Web Server

One of the following:
- **Apache**: 2.4 or higher
  - Required modules: `mod_rewrite`, `mod_headers`
- **Nginx**: 1.18 or higher
  - With PHP-FPM configured

---

## Required PHP Extensions

### Core Extensions (Required)

```bash
php-cli           # Command-line interface
php-fpm           # FastCGI Process Manager
php-mysql         # MySQL/MariaDB support
php-json          # JSON support
php-mbstring      # Multibyte string support
php-xml           # XML support
php-curl          # cURL support
php-zip           # ZIP archive support
php-gd            # Image processing
```

### Installation Commands

#### Ubuntu/Debian
```bash
sudo apt-get install -y \
    php8.1-cli \
    php8.1-fpm \
    php8.1-mysql \
    php8.1-json \
    php8.1-mbstring \
    php8.1-xml \
    php8.1-curl \
    php8.1-zip \
    php8.1-gd
```

#### CentOS/RHEL/Fedora
```bash
sudo yum install -y \
    php-cli \
    php-fpm \
    php-mysqlnd \
    php-json \
    php-mbstring \
    php-xml \
    php-curl \
    php-zip \
    php-gd
```

### Optional Extensions (Recommended)

```bash
php-redis         # Redis caching support
php-memcached     # Memcached support
php-opcache       # Opcode caching (performance)
php-intl          # Internationalization
php-bcmath        # Arbitrary precision mathematics
php-soap          # SOAP protocol support
```

---

## Required System Tools

### Package Managers & Build Tools

```bash
composer          # PHP dependency manager (v2.0+)
git               # Version control
curl              # HTTP client
wget              # File downloader
unzip             # Archive extraction
```

### System Utilities

```bash
systemctl         # Service management
cron              # Task scheduling
openssl           # SSL/TLS toolkit
```

### Installation Commands

#### Ubuntu/Debian
```bash
sudo apt-get install -y \
    composer \
    git \
    curl \
    wget \
    unzip \
    openssl
```

#### CentOS/RHEL/Fedora
```bash
sudo yum install -y \
    composer \
    git \
    curl \
    wget \
    unzip \
    openssl
```

---

## Optional Components

### Caching Systems (Highly Recommended for Production)

#### Redis
```bash
# Ubuntu/Debian
sudo apt-get install -y redis-server

# CentOS/RHEL/Fedora
sudo yum install -y redis

# Start and enable
sudo systemctl start redis
sudo systemctl enable redis
```

#### Memcached
```bash
# Ubuntu/Debian
sudo apt-get install -y memcached

# CentOS/RHEL/Fedora
sudo yum install -y memcached

# Start and enable
sudo systemctl start memcached
sudo systemctl enable memcached
```

### Node.js & NPM (For Admin UI Development)

```bash
# Ubuntu/Debian
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# CentOS/RHEL/Fedora
curl -fsSL https://rpm.nodesource.com/setup_18.x | sudo bash -
sudo yum install -y nodejs

# Verify installation
node --version  # Should be v18.x or higher
npm --version   # Should be v9.x or higher
```

### SSL/TLS Certificate Management

#### Certbot (Let's Encrypt)
```bash
# Ubuntu/Debian
sudo apt-get install -y certbot python3-certbot-apache  # For Apache
sudo apt-get install -y certbot python3-certbot-nginx   # For Nginx

# CentOS/RHEL/Fedora
sudo yum install -y certbot python3-certbot-apache      # For Apache
sudo yum install -y certbot python3-certbot-nginx       # For Nginx
```

### Monitoring Tools (Recommended for Production)

```bash
htop              # Process monitoring
iotop             # I/O monitoring
nethogs           # Network monitoring
sysstat           # System statistics
```

---

## Recommended Requirements

### For Production Environments

#### Hardware
- **CPU**: 4+ cores @ 3.0 GHz (8+ cores for high traffic)
- **RAM**: 8 GB minimum (16+ GB for high traffic)
- **Storage**: 
  - 50+ GB SSD for application and database
  - Separate disk for logs and backups
  - RAID configuration for redundancy
- **Network**: 1 Gbps connection with low latency

#### Software
- **PHP**: 8.2 or higher with OPcache enabled
- **Database**: 
  - MySQL 8.0+ or MariaDB 10.6+
  - Dedicated database server recommended
  - Master-slave replication for high availability
- **Web Server**: 
  - Nginx with HTTP/2 support
  - SSL/TLS certificate (Let's Encrypt or commercial)
- **Caching**: Redis or Memcached
- **CDN**: CloudFlare, AWS CloudFront, or similar

#### Security
- Firewall configured (UFW, iptables, or firewalld)
- Fail2ban for intrusion prevention
- Regular security updates
- SSH key-based authentication
- Non-root user for application

#### Backup
- Daily automated backups
- Off-site backup storage
- Backup retention policy (30+ days)
- Tested restore procedures

---

## Performance Tuning

### PHP Configuration (`php.ini`)

```ini
; Memory and execution
memory_limit = 512M
max_execution_time = 300
max_input_time = 300

; File uploads
upload_max_filesize = 64M
post_max_size = 64M

; OPcache (highly recommended)
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1

; Realpath cache
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

### MySQL/MariaDB Configuration (`my.cnf`)

```ini
[mysqld]
# InnoDB settings
innodb_buffer_pool_size = 2G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query cache (MySQL 5.7 and earlier)
query_cache_type = 1
query_cache_size = 128M

# Connections
max_connections = 200
max_connect_errors = 1000

# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
```

### Apache Configuration

```apache
# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>

# Enable browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>

# Security headers
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

### Nginx Configuration

```nginx
# Worker processes
worker_processes auto;
worker_rlimit_nofile 65535;

events {
    worker_connections 4096;
    use epoll;
    multi_accept on;
}

http {
    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss;

    # Client body size
    client_max_body_size 64M;

    # Timeouts
    client_body_timeout 12;
    client_header_timeout 12;
    keepalive_timeout 65;
    send_timeout 10;

    # FastCGI cache
    fastcgi_cache_path /var/cache/nginx levels=1:2 keys_zone=FASTCGI:100m inactive=60m;
    fastcgi_cache_key "$scheme$request_method$host$request_uri";
}
```

---

## Compatibility Matrix

### PHP Version Compatibility

| PHP Version | Status | Notes |
|-------------|--------|-------|
| 8.3 | ✅ Supported | Latest features |
| 8.2 | ✅ Recommended | Stable and fast |
| 8.1 | ✅ Supported | Minimum version |
| 8.0 | ⚠️ Not recommended | End of life |
| 7.4 | ❌ Not supported | End of life |

### Database Compatibility

| Database | Version | Status |
|----------|---------|--------|
| MySQL | 8.0+ | ✅ Recommended |
| MySQL | 5.7 | ⚠️ Works but not recommended |
| MariaDB | 10.6+ | ✅ Recommended |
| MariaDB | 10.5 | ✅ Supported |
| MariaDB | 10.4 | ⚠️ Works but not recommended |

### CMS Compatibility

| CMS | Version | Status |
|-----|---------|--------|
| WordPress | 6.0+ | ✅ Fully supported |
| WordPress | 5.9 | ✅ Supported |
| Joomla | 4.0+ | ✅ Fully supported |
| Joomla | 3.10 | ✅ Supported |
| Drupal | 10.0+ | ✅ Fully supported |
| Drupal | 9.5 | ✅ Supported |

---

## Verification Commands

### Check PHP Version and Extensions
```bash
php -v
php -m | grep -E 'mysql|json|mbstring|xml|curl|zip|gd'
```

### Check Database Version
```bash
mysql --version
# OR
mariadb --version
```

### Check Web Server
```bash
apache2 -v  # Apache
nginx -v    # Nginx
```

### Check System Resources
```bash
# CPU info
lscpu

# Memory info
free -h

# Disk space
df -h

# System info
uname -a
```

### Check PHP Configuration
```bash
php -i | grep -E 'memory_limit|max_execution_time|upload_max_filesize'
```

---

## Troubleshooting

### Common Issues

#### "PHP version too old"
```bash
# Add PHP repository and upgrade
sudo add-apt-repository ppa:ondrej/php  # Ubuntu
sudo apt-get update
sudo apt-get install php8.2
```

#### "Extension not found"
```bash
# Install missing extension
sudo apt-get install php8.2-<extension-name>
sudo systemctl restart apache2  # or nginx
```

#### "Database connection failed"
```bash
# Check if MySQL is running
sudo systemctl status mysql

# Check if user has permissions
mysql -u ikabud_user -p ikabud_kernel
```

#### "Permission denied"
```bash
# Fix ownership and permissions
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel
sudo chmod -R 775 storage instances themes logs
```

---

## Support

For requirements-related questions:
- Check the [Installation Guide](INSTALL.md)
- Visit the [Documentation](docs/)
- Open an [Issue](https://github.com/yourusername/ikabud-kernel/issues)

---

**Last Updated**: November 2025  
**Ikabud Kernel Version**: 1.0.0
