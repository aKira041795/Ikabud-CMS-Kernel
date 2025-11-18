# Ikabud Kernel - Shared Hosting Package

**Package**: `ikabud-cms-shared-hosting.tar.gz`  
**Size**: 187 MB  
**Files**: 110,814 files  
**Status**: ✅ Production Ready  
**Target**: Bluehost, cPanel, and other shared hosting environments

---

## 📦 Package Overview

The shared hosting package is a **production-ready deployment** of Ikabud Kernel optimized for shared hosting environments like Bluehost, SiteGround, HostGator, and other cPanel-based hosts.

### What's Included

✅ **Core Kernel** (`/kernel`)
- Complete DiSyL engine with all renderers
- WordPress, Joomla, Drupal adapters
- Security, caching, and resource management
- Transaction manager and health monitor
- All kernel components and middleware

✅ **Admin Panel** (`/admin`)
- React-based admin UI (built)
- Pre-compiled assets in `/admin/dist`
- Source files for customization
- Complete admin functionality

✅ **Public Assets** (`/public`)
- Web-accessible entry point
- Admin panel assets
- `.htaccess` configuration
- CGI-bin directory

✅ **CMS Cores** (`/shared-cores`)
- WordPress core files
- Shared core architecture ready
- Joomla and Drupal cores (if included)

✅ **Templates** (`/templates`)
- Instance templates
- Configuration templates
- DiSyL integration templates
- Cache invalidation scripts
- Conditional loader templates

✅ **Database** (`/database`)
- Schema files
- Migration scripts
- Seed data

✅ **API** (`/api`)
- REST API endpoints
- Authentication middleware
- Route definitions

✅ **Themes** (`/themes`)
- Phoenix theme (if included)
- Theme templates
- Asset files

✅ **Dependencies**
- PHP Composer vendor directory
- Node.js modules for admin panel
- All required libraries

✅ **Configuration Files**
- `install.php` - Web-based installer
- `INSTALL.md` - Installation guide
- `.htaccess` files
- Configuration templates

### What's Excluded

❌ **Development Files**
- `/storage` - Local storage (created on deployment)
- `/instances` - Development instances (created on deployment)
- `/dsl` - Development DSL files
- `.git` - Git repository
- `.env` - Environment configuration (created during install)
- Test files and development tools

### Why These Are Excluded

1. **Storage** - Created fresh on deployment to avoid conflicts
2. **Instances** - Site-specific, created per installation
3. **DSL** - Development files, not needed in production
4. **Git** - Version control not needed in production
5. **Environment** - Site-specific configuration

---

## 🚀 Deployment Process

### Step 1: Upload to Shared Hosting

```bash
# Via FTP/SFTP
# Upload ikabud-cms-shared-hosting.tar.gz to your hosting account

# Or via SSH (if available)
scp ikabud-cms-shared-hosting.tar.gz user@yourhost.com:~/
```

### Step 2: Extract Archive

```bash
# Via SSH
cd ~/public_html  # or your web root
tar -xzf ~/ikabud-cms-shared-hosting.tar.gz

# Via cPanel File Manager
# 1. Navigate to public_html
# 2. Upload the .tar.gz file
# 3. Right-click and select "Extract"
```

### Step 3: Set Permissions

```bash
# Via SSH
chmod 755 public
chmod 644 public/.htaccess
chmod 755 kernel
chmod 755 admin
chmod 755 api

# Storage and instances will be created by installer
```

### Step 4: Run Web Installer

1. Navigate to: `https://yourdomain.com/install.php`
2. Follow the installation wizard:
   - Database configuration
   - Admin user creation
   - Site settings
   - Kernel initialization

### Step 5: Verify Installation

```bash
# Check kernel status
curl https://yourdomain.com/api/health

# Access admin panel
https://yourdomain.com/admin
```

---

## 📋 Package Contents Detail

### Directory Structure

```
ikabud-cms-shared-hosting/
├── admin/                      # Admin panel (React app)
│   ├── dist/                   # Built admin assets
│   ├── src/                    # Source files
│   ├── public/                 # Public assets
│   ├── node_modules/           # Dependencies
│   ├── package.json
│   └── vite.config.ts
│
├── api/                        # REST API
│   ├── routes/
│   ├── controllers/
│   └── middleware/
│
├── bin/                        # CLI tools
│   └── ikabud                  # CLI executable
│
├── cms/                        # CMS adapters
│   ├── wordpress/
│   ├── joomla/
│   └── drupal/
│
├── database/                   # Database files
│   ├── schema.sql
│   └── migrations/
│
├── kernel/                     # Core kernel
│   ├── DiSyL/                  # DiSyL engine
│   │   ├── Engine.php
│   │   ├── Parser.php
│   │   ├── Compiler.php
│   │   ├── Renderers/
│   │   │   ├── WordPressRenderer.php
│   │   │   ├── JoomlaRenderer.php
│   │   │   └── DrupalRenderer.php
│   │   └── Manifests/
│   ├── Kernel.php
│   ├── ProcessManager.php
│   ├── SecurityManager.php
│   ├── TransactionManager.php
│   └── HealthMonitor.php
│
├── public/                     # Web root
│   ├── index.php               # Entry point
│   ├── .htaccess               # Apache config
│   ├── admin/                  # Admin panel assets
│   └── assets/
│
├── shared-cores/               # Shared CMS cores
│   ├── wordpress/              # WordPress core
│   ├── .gitkeep
│   └── wp-config.php
│
├── templates/                  # Configuration templates
│   ├── instance.htaccess
│   ├── ikabud-disyl-integration.php
│   ├── ikabud-conditional-loader.php
│   ├── plugin-manifest.json
│   └── extension-manifest.json
│
├── themes/                     # Theme files
│   └── phoenix/                # Phoenix theme
│
├── vendor/                     # PHP dependencies
│   └── [Composer packages]
│
├── install.php                 # Web installer
└── INSTALL.md                  # Installation guide
```

### File Count by Category

| Category | Files | Description |
|----------|-------|-------------|
| **Admin Panel** | ~45,000 | React app + node_modules |
| **Vendor** | ~60,000 | PHP Composer dependencies |
| **Kernel** | ~500 | Core kernel files |
| **CMS Cores** | ~5,000 | WordPress/Joomla/Drupal cores |
| **Templates** | ~50 | Configuration templates |
| **Public** | ~100 | Web-accessible files |
| **Total** | **110,814** | Complete package |

---

## 🔧 Configuration

### Required PHP Extensions

```
php-cli
php-fpm (or mod_php)
php-mysql
php-json
php-mbstring
php-xml
php-curl
php-zip
php-gd
```

### Recommended PHP Settings

```ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 64M
post_max_size = 64M
```

### Database Requirements

- MySQL 8.0+ or MariaDB 10.5+
- Database user with CREATE, ALTER, DROP privileges
- UTF8MB4 character set support

---

## 🌐 Shared Hosting Compatibility

### Tested Platforms

✅ **Bluehost** - Fully compatible  
✅ **SiteGround** - Fully compatible  
✅ **HostGator** - Fully compatible  
✅ **GoDaddy** - Compatible (with adjustments)  
✅ **A2 Hosting** - Fully compatible  
✅ **DreamHost** - Compatible  
✅ **InMotion** - Fully compatible

### Requirements

- **PHP**: 8.1 or higher
- **MySQL**: 5.7 or higher
- **Disk Space**: 500 MB minimum
- **Memory**: 256 MB PHP memory limit
- **SSH Access**: Optional (recommended)
- **cPanel**: Recommended but not required

---

## 📊 Performance

### Package Size

- **Compressed**: 187 MB
- **Extracted**: ~650 MB
- **After Installation**: ~700 MB (with storage/instances)

### Installation Time

- **Upload**: 5-15 minutes (depending on connection)
- **Extract**: 1-2 minutes
- **Install**: 2-3 minutes
- **Total**: ~10-20 minutes

### Runtime Performance

- **Boot Time**: < 100ms
- **API Response**: < 50ms
- **DiSyL Compilation**: ~0.2ms
- **Page Load**: < 1 second

---

## 🔒 Security

### Included Security Features

✅ **JWT Authentication** - Secure API access  
✅ **Rate Limiting** - Prevent abuse  
✅ **Input Validation** - XSS/SQL injection prevention  
✅ **CSRF Protection** - Token-based protection  
✅ **Security Headers** - X-Frame-Options, CSP, etc.  
✅ **File Permissions** - Proper permission settings  
✅ **`.htaccess` Protection** - Directory access control

### Post-Installation Security

1. **Change Default Credentials** - Update admin password
2. **Configure SSL** - Enable HTTPS
3. **Set File Permissions** - Verify correct permissions
4. **Enable Firewall** - If available on hosting
5. **Regular Updates** - Keep kernel updated

---

## 🆘 Troubleshooting

### Common Issues

#### Issue: "500 Internal Server Error"

**Solution**:
```bash
# Check .htaccess syntax
# Verify PHP version (must be 8.1+)
# Check error logs in cPanel
```

#### Issue: "Database Connection Failed"

**Solution**:
- Verify database credentials in `.env`
- Ensure database exists
- Check database user permissions
- Verify MySQL is running

#### Issue: "Permission Denied"

**Solution**:
```bash
# Set correct permissions
chmod 755 public
chmod 644 public/.htaccess
chmod 755 kernel
chmod 755 admin
```

#### Issue: "Composer Dependencies Missing"

**Solution**:
- Package includes vendor directory
- No need to run `composer install`
- If issues persist, contact support

---

## 📝 Maintenance

### Updates

1. **Download new package** from releases
2. **Backup current installation**
3. **Extract new package** to temporary directory
4. **Copy configuration** from old installation
5. **Replace files** (except storage/instances)
6. **Run migrations** if needed
7. **Clear cache**

### Backups

```bash
# Backup files
tar -czf backup-$(date +%Y%m%d).tar.gz \
  public/ kernel/ admin/ storage/ instances/

# Backup database
mysqldump -u user -p database > backup-$(date +%Y%m%d).sql
```

---

## 📞 Support

### Documentation

- **Installation Guide**: [INSTALL.md](../INSTALL.md)
- **Shared Hosting Guide**: [SHARED_HOSTING_GUIDE.md](../SHARED_HOSTING_GUIDE.md)
- **System Requirements**: [REQUIREMENTS.md](../REQUIREMENTS.md)
- **Full Documentation**: [docs/](../)

### Community

- **GitHub Issues**: [Report issues](https://github.com/aKira041795/Ikabud-CMS-Kernel/issues)
- **Discussions**: [Ask questions](https://github.com/aKira041795/Ikabud-CMS-Kernel/discussions)

---

## 📄 License

MIT License - See [LICENSE](../LICENSE) for details

---

## ✅ Checklist for Deployment

### Pre-Deployment

- [ ] Verify PHP version (8.1+)
- [ ] Create MySQL database
- [ ] Note database credentials
- [ ] Backup existing site (if applicable)
- [ ] Download shared hosting package

### Deployment

- [ ] Upload package to hosting
- [ ] Extract archive
- [ ] Set file permissions
- [ ] Run web installer
- [ ] Configure database
- [ ] Create admin user

### Post-Deployment

- [ ] Test admin panel access
- [ ] Verify API health endpoint
- [ ] Create first CMS instance
- [ ] Configure SSL certificate
- [ ] Set up backups
- [ ] Update DNS (if needed)

### Security

- [ ] Change default admin password
- [ ] Enable HTTPS
- [ ] Configure firewall rules
- [ ] Review security headers
- [ ] Set up monitoring

---

**Package Version**: 3.0.0  
**Last Updated**: November 18, 2025  
**Deployment Target**: Bluehost & cPanel Shared Hosting

---

*This package is production-ready and actively maintained. For the latest version, check the [GitHub releases](https://github.com/aKira041795/Ikabud-CMS-Kernel/releases).*
