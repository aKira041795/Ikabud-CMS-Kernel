# Production Deployment Guide

**Last updated:** 2026-04-15

## Server Requirements

### Minimum

| Component | Requirement |
|-----------|-------------|
| PHP | 8.2+ (8.3 recommended) |
| Extensions | `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `gd` or `imagick` |
| Optional extensions | `apcu` (strongly recommended for caching), `opcache` |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| Disk | 500 MB application + storage headroom |
| RAM | 256 MB minimum per PHP-FPM worker |

### Recommended Production

| Component | Recommendation |
|-----------|----------------|
| PHP | 8.3 with OPcache + APCu |
| Workers | 4–16 PHP-FPM workers (based on traffic) |
| MySQL | Dedicated instance, InnoDB, `innodb_buffer_pool_size` ≥ 50% of RAM |
| Disk | SSD, 2 GB+ for storage/cache |
| RAM | 1 GB+ total for PHP-FPM pool |

## Directory Structure

```
/var/www/html/yoursite/
├── bootstrap.php          # App bootstrap (env, paths, logging)
├── public/                # Web root (point vhost here)
│   └── index.php          # Entry point
├── kernel/                # Core framework
├── modules/               # Feature modules
├── config/                # Configuration files
├── storage/               # Runtime storage (must be writable)
│   ├── cache/             # File cache tier
│   ├── logs/              # Application + error logs
│   └── uploads/           # User uploads
├── templates/             # DiSyL templates
├── vendor/                # Composer dependencies
└── .env                   # Environment configuration
```

## Environment Configuration

Create `.env` in the project root:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=<random-64-char-hex-string>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ikabud
DB_USERNAME=ikabud_user
DB_PASSWORD=<strong-password>

# Control plane database (multi-tenant)
CONTROL_DB_HOST=127.0.0.1
CONTROL_DB_DATABASE=ikabud_control
CONTROL_DB_USERNAME=ikabud_control_user
CONTROL_DB_PASSWORD=<strong-password>

# Cache
CACHE_DIR=storage/cache
CACHE_MAX_SIZE_MB=100

# Logging
LOG_LEVEL=warning
```

**Critical:** `APP_KEY` must be a cryptographically random value. Generate with:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

## Web Server Configuration

### Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/html/yoursite/public;
    index index.php;

    # Security headers are handled by PHP (SecurityHeaders class)
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    # Deny access to sensitive files
    location ~ /\.(env|git|htaccess) {
        deny all;
    }
    location ~ ^/(bootstrap\.php|composer\.(json|lock)|kernel|modules|config|storage|templates|vendor) {
        deny all;
    }
}
```

### Apache

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/yoursite/public

    <Directory /var/www/html/yoursite/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The project includes `public/.htaccess` for URL rewriting.

## PHP Configuration

### php.ini (production)

```ini
; OPcache
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.save_comments=1

; APCu
apc.enabled=1
apc.shm_size=64M
apc.ttl=3600

; Security
expose_php=Off
display_errors=Off
log_errors=On
error_log=/var/www/html/yoursite/storage/logs/error.log

; Limits
memory_limit=256M
max_execution_time=30
post_max_size=32M
upload_max_filesize=32M
```

**Important:** `opcache.validate_timestamps=0` requires an OPcache reset on deployment (see below).

## Installation

```bash
# 1. Clone / extract application
cd /var/www/html/yoursite

# 2. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Set permissions
chown -R www-data:www-data storage/
chmod -R 775 storage/

# 4. Create .env file (see above)
cp .env.example .env
# Edit with production values

# 5. Run database migrations
php scripts/migrate.php

# 6. Build CMS builder UI (if using page builder)
cd modules/cms/builder-ui
npm ci --production=false
npm run build
cd ../../..

# 7. Verify
curl -s http://localhost/login | head -20
```

## Deployment (Updates)

```bash
# 1. Pull code changes
git pull origin main

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Run migrations
php scripts/migrate.php

# 4. Rebuild builder UI (if changed)
cd modules/cms/builder-ui && npm ci && npm run build && cd ../../..

# 5. Clear file cache
php -r "require 'bootstrap.php'; app()->cache()->clear();"

# 6. Reset OPcache (if validate_timestamps=0)
# Option A: Restart PHP-FPM
sudo systemctl restart php8.3-fpm

# Option B: Via PHP script (hit from web)
# php -r "opcache_reset();"

# 7. Verify
curl -sI http://localhost/login
```

## Monitoring

### Log Files

| Log | Path | Content |
|-----|------|---------|
| Application | `storage/logs/app.log` | Business-level events, warnings, request IDs |
| PHP Errors | `storage/logs/error.log` | PHP errors, exceptions, fatals |

Both logs include `X-Request-Id` for correlation.

### Key Metrics to Monitor

| Metric | How to Check | Alert Threshold |
|--------|-------------|-----------------|
| PHP-FPM status | `/status` endpoint or `pm.status_path` | Active workers > 80% of max |
| OPcache usage | `opcache_get_status()` | Memory > 90% |
| APCu usage | `apcu_cache_info()` | Memory > 80% |
| File cache size | `du -sh storage/cache/` | Near `CACHE_MAX_SIZE_MB` |
| Error log growth | `wc -l storage/logs/error.log` | New errors per minute > 0 |
| MySQL connections | `SHOW PROCESSLIST` | Near `max_connections` |

### Cache Statistics

```php
// Via application
$stats = app()->cache()->stats();
// → ['hits' => ..., 'misses' => ..., 'size' => ..., 'entries' => ...]
```

## Backup Strategy

### Database

```bash
# Daily full backup
mysqldump --single-transaction --routines --triggers \
  -u backup_user -p ikabud > /backups/ikabud_$(date +%Y%m%d).sql

# Control plane (if multi-tenant)
mysqldump --single-transaction \
  -u backup_user -p ikabud_control > /backups/control_$(date +%Y%m%d).sql
```

### Files

```bash
# Application files (exclude vendor, node_modules, cache)
tar czf /backups/app_$(date +%Y%m%d).tar.gz \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='storage/cache' \
  /var/www/html/yoursite/

# User uploads only
tar czf /backups/uploads_$(date +%Y%m%d).tar.gz \
  /var/www/html/yoursite/storage/uploads/
```

## Security Checklist

- [ ] `.env` is not accessible via web (nginx/apache deny rules)
- [ ] `APP_DEBUG=false` in production
- [ ] `APP_KEY` is set to a random value (not the example)
- [ ] `storage/` directory is not web-accessible
- [ ] `kernel/`, `modules/`, `config/` are not web-accessible
- [ ] PHP `display_errors=Off`
- [ ] HTTPS enforced (via web server or load balancer)
- [ ] Database user has minimal required privileges
- [ ] File permissions: `www-data` owns `storage/`, no world-writable files
- [ ] OPcache `validate_timestamps=0` with controlled deployments
- [ ] Security headers active (managed by `kernel/Http/SecurityHeaders.php`)

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Blank page | `APP_KEY` not set or wrong | Check `.env`, set correct key |
| 500 error | PHP error hidden by `display_errors=Off` | Check `storage/logs/error.log` |
| Styles broken | Builder UI not built | Run `npm run build` in `modules/cms/builder-ui` |
| Login broken | CSP headers blocking scripts | Check `SecurityHeaders` config, ensure `unsafe-eval` present |
| Cache stale | OPcache not cleared | Restart PHP-FPM or call `opcache_reset()` |
| Slow pages | APCu not installed | Install `php-apcu` extension |
| Module missing | Module not enabled for tenant | Check control plane settings |
