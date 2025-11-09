# Environment Configuration Guide

## Overview

Ikabud Kernel uses environment variables for configuration management. This allows for secure, flexible configuration across different environments (development, staging, production).

---

## Quick Setup

### 1. Create .env File

```bash
cd /var/www/html/ikabud-kernel
cp .env.example .env
```

### 2. Generate JWT Secret

```bash
# Generate a secure random secret
openssl rand -base64 32

# Copy the output and paste into .env as JWT_SECRET
```

### 3. Configure Database

Edit `.env` and update database credentials:

```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ikabud_kernel
DB_USERNAME=your_db_user
DB_PASSWORD=your_secure_password
```

### 4. Update Admin Credentials

**⚠️ Important:** Change default admin credentials!

```env
ADMIN_USERNAME=your_admin_username
ADMIN_PASSWORD=your_secure_password
ADMIN_EMAIL=your_email@domain.com
```

---

## Configuration Sections

### 🔐 JWT Authentication (Required)

Used for admin panel authentication and API access.

```env
JWT_SECRET=your-secret-key-change-this
JWT_ALGORITHM=HS256
JWT_EXPIRATION=86400
```

**JWT_SECRET:**
- **Required** for security
- Generate with: `openssl rand -base64 32`
- Must be at least 32 characters
- Keep secret and never commit to version control

**JWT_ALGORITHM:**
- Default: `HS256` (HMAC SHA-256)
- Other options: `HS384`, `HS512`

**JWT_EXPIRATION:**
- Token lifetime in seconds
- Default: `86400` (24 hours)
- Adjust based on security requirements

---

### 💾 Database (Required)

MySQL/MariaDB connection settings.

```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ikabud_kernel
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

**Used by:**
- `kernel/Kernel.php` - Database initialization
- Instance management
- Configuration storage
- User authentication (future)

---

### 👤 Admin Credentials

Default admin user for initial login.

```env
ADMIN_USERNAME=admin
ADMIN_PASSWORD=password
ADMIN_EMAIL=admin@ikabud.local
```

**Security Notes:**
- Change immediately after first login
- Use strong passwords (12+ characters)
- Consider implementing 2FA (future enhancement)

**Used by:**
- `api/routes/auth.php` - Login authentication
- Admin panel access

---

### 🚀 Cache Configuration

Controls caching behavior for performance optimization.

```env
CACHE_DRIVER=file
CACHE_TTL=3600
CACHE_PATH=./var/cache
```

**CACHE_DRIVER:**
- `file` - File-based caching (default)
- `redis` - Redis caching (future)
- `memcached` - Memcached (future)

**CACHE_TTL:**
- Time-to-live in seconds
- Default: `3600` (1 hour)
- Adjust based on content update frequency

---

### 📁 Paths

Directory paths for kernel components.

```env
INSTANCES_PATH=./instances
SHARED_CORES_PATH=./shared-cores
THEMES_PATH=./themes
LOGS_PATH=./logs
```

**Default Structure:**
```
ikabud-kernel/
├── instances/          # CMS instances
│   ├── wp-test-001/
│   └── joomla-001/
├── shared-cores/       # Shared CMS cores
│   ├── wordpress/
│   ├── joomla/
│   └── drupal/
├── themes/             # Shared themes
└── logs/               # Application logs
```

---

### 🔒 Security

Security-related settings.

```env
SESSION_LIFETIME=120
CORS_ALLOWED_ORIGINS=*
ALLOWED_HOSTS=*
```

**SESSION_LIFETIME:**
- Session timeout in minutes
- Default: `120` (2 hours)

**CORS_ALLOWED_ORIGINS:**
- Cross-Origin Resource Sharing
- `*` = Allow all (development only)
- Production: Specify exact domains

**ALLOWED_HOSTS:**
- Restrict access to specific hosts
- `*` = Allow all (development only)
- Production: Comma-separated list

---

### 📝 Logging

Application logging configuration.

```env
LOG_LEVEL=info
LOG_CHANNEL=file
```

**LOG_LEVEL:**
- `debug` - Detailed debugging information
- `info` - General informational messages
- `warning` - Warning messages
- `error` - Error messages only

**LOG_CHANNEL:**
- `file` - Log to files (default)
- `syslog` - System log (future)
- `stderr` - Standard error output

---

### ⚡ Performance

Performance tuning settings.

```env
MAX_INSTANCES=100
MEMORY_LIMIT=512M
MAX_EXECUTION_TIME=300
```

**MAX_INSTANCES:**
- Maximum number of CMS instances
- Adjust based on server resources

**MEMORY_LIMIT:**
- PHP memory limit per request
- Format: `128M`, `256M`, `512M`, `1G`

**MAX_EXECUTION_TIME:**
- Maximum script execution time (seconds)
- Default: `300` (5 minutes)

---

### ⚡ Conditional Loading

CMS-agnostic conditional extension loading.

```env
CONDITIONAL_LOADING_ENABLED=true
CONDITIONAL_LOADING_CACHE=true
```

**CONDITIONAL_LOADING_ENABLED:**
- Enable/disable conditional loading system
- `true` = Load extensions conditionally
- `false` = Load all extensions (traditional)

**CONDITIONAL_LOADING_CACHE:**
- Cache extension loading decisions
- Improves performance on repeated requests

---

### 🛠️ Development

Development mode settings.

```env
DEV_MODE=false
SHOW_ERRORS=false
DEBUG_QUERIES=false
```

**⚠️ Production:** Set all to `false`

**DEV_MODE:**
- Enable development features
- Disables certain caching
- Shows detailed error messages

**SHOW_ERRORS:**
- Display PHP errors on screen
- Production: Always `false`

**DEBUG_QUERIES:**
- Log all database queries
- Performance impact - use sparingly

---

## Environment-Specific Configurations

### Development

```env
APP_ENV=development
APP_DEBUG=true
DEV_MODE=true
SHOW_ERRORS=true
LOG_LEVEL=debug
JWT_EXPIRATION=604800  # 7 days
CORS_ALLOWED_ORIGINS=*
```

### Staging

```env
APP_ENV=staging
APP_DEBUG=false
DEV_MODE=false
SHOW_ERRORS=false
LOG_LEVEL=info
JWT_EXPIRATION=86400  # 24 hours
CORS_ALLOWED_ORIGINS=https://staging.yourdomain.com
```

### Production

```env
APP_ENV=production
APP_DEBUG=false
DEV_MODE=false
SHOW_ERRORS=false
LOG_LEVEL=warning
JWT_EXPIRATION=43200  # 12 hours
CORS_ALLOWED_ORIGINS=https://yourdomain.com
ALLOWED_HOSTS=yourdomain.com,www.yourdomain.com
```

---

## Security Best Practices

### 1. Protect .env File

```bash
# Set proper permissions
chmod 600 .env

# Never commit to git
echo ".env" >> .gitignore
```

### 2. Use Strong Secrets

```bash
# Generate JWT secret
openssl rand -base64 32

# Generate admin password
openssl rand -base64 24
```

### 3. Restrict CORS in Production

```env
# Development
CORS_ALLOWED_ORIGINS=*

# Production
CORS_ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com
```

### 4. Regular Secret Rotation

- Rotate JWT_SECRET every 90 days
- Update admin passwords regularly
- Monitor access logs

---

## Troubleshooting

### .env File Not Loading

**Issue:** Configuration not being read

**Solution:**
1. Check file exists: `ls -la .env`
2. Check permissions: `chmod 600 .env`
3. Verify syntax (no spaces around `=`)
4. Check `Config::getInstance()` is called

### JWT Authentication Failing

**Issue:** Login returns 401 Unauthorized

**Solution:**
1. Verify `JWT_SECRET` is set
2. Check secret is at least 32 characters
3. Ensure no extra spaces in `.env`
4. Clear browser localStorage

### Database Connection Failed

**Issue:** Cannot connect to database

**Solution:**
1. Verify database exists
2. Check credentials are correct
3. Test connection: `mysql -u username -p`
4. Ensure MySQL is running

---

## Configuration Loading Order

1. **Default values** in code
2. **`.env` file** (overrides defaults)
3. **Environment variables** (overrides .env)
4. **Database config** (overrides all)

Example:
```php
// Default
$secret = 'default-secret';

// .env overrides
$secret = Config::get('JWT_SECRET', 'default-secret');

// Environment variable overrides
$secret = $_ENV['JWT_SECRET'] ?? $secret;
```

---

## API Usage

### In PHP Code

```php
use IkabudKernel\Core\Config;

// Get configuration value
$jwtSecret = Config::get('JWT_SECRET');

// Get with default
$cacheDriver = Config::get('CACHE_DRIVER', 'file');

// Set configuration
Config::set('CUSTOM_VALUE', 'my-value');

// Check if exists
if (Config::has('JWT_SECRET')) {
    // Configuration exists
}

// Get all configuration
$allConfig = Config::all();
```

---

## Related Documentation

- [JWT Authentication](./JWT_AUTHENTICATION.md)
- [Conditional Loading](./CONDITIONAL_LOADING_CMS_AGNOSTIC.md)
- [Security Guide](./SECURITY.md)
- [Deployment Guide](./DEPLOYMENT.md)

---

**Status**: Production Ready  
**Version**: 1.0  
**Last Updated**: November 9, 2025
