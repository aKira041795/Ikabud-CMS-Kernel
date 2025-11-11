# Ikabud Kernel - Archive Comparison Guide

Choose the right archive package for your deployment scenario.

---

## 📦 Available Packages

### 1. Minimal Archive
**File**: `ikabud-kernel-v1.0.0.zip`  
**Size**: ~1.2 MB  
**Best For**: Developers, VPS/Dedicated servers with Composer

### 2. Complete Archive  
**File**: `ikabud-kernel-complete-v1.0.0.zip`  
**Size**: ~90 MB  
**Best For**: Offline installations, complete deployments

### 3. Shared Hosting Archive ⭐ RECOMMENDED FOR SHARED HOSTING
**File**: `ikabud-kernel-shared-hosting-v1.0.0.zip`  
**Size**: ~92 MB  
**Best For**: cPanel, Plesk, shared hosting without Composer

---

## 🔍 Detailed Comparison

| Feature | Minimal | Complete | Shared Hosting |
|---------|---------|----------|----------------|
| **Size** | 1.2 MB | 90 MB | 92 MB |
| **Vendor Dependencies** | ❌ Run composer | ❌ Run composer | ✅ Included |
| **Shared CMS Cores** | ❌ Download separately | ✅ Included | ✅ Included |
| **Admin UI (Built)** | ✅ Included | ✅ Included | ✅ Included |
| **Database Schema** | ✅ schema.sql | ✅ basic-data.sql | ✅ basic-data.sql |
| **Documentation** | ✅ Full | ✅ Full | ✅ Full |
| **Installation Scripts** | ✅ Both | ✅ Both | ✅ Both |
| **Requires Composer** | ✅ Yes | ✅ Yes | ❌ No |
| **Requires Shell Access** | ⚠️ Recommended | ⚠️ Recommended | ❌ No |
| **Web Installer** | ✅ Yes | ✅ Yes | ✅ Yes |
| **Offline Installation** | ❌ No | ✅ Yes | ✅ Yes |
| **Setup Time** | 15-20 min | 5-10 min | 3-5 min |
| **Ideal Environment** | VPS/Dedicated | VPS/Cloud | Shared Hosting |

---

## 🎯 Which Package Should You Use?

### Use **Minimal Archive** if:
- ✅ You have VPS or dedicated server
- ✅ You have SSH/shell access
- ✅ Composer is available
- ✅ You want smallest download
- ✅ You're a developer
- ✅ You want latest dependencies

**Installation:**
```bash
unzip ikabud-kernel-v1.0.0.zip
cd ikabud-kernel
composer install --no-dev --optimize-autoloader
# Download CMS cores separately
cp .env.example .env
# Configure and install
```

### Use **Complete Archive** if:
- ✅ You have VPS or dedicated server
- ✅ You want offline installation
- ✅ You need CMS cores included
- ✅ Composer is available
- ✅ You want faster setup
- ✅ You're deploying to production

**Installation:**
```bash
unzip ikabud-kernel-complete-v1.0.0.zip
cd ikabud-kernel
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Configure and install
```

### Use **Shared Hosting Archive** if: ⭐
- ✅ You have shared hosting (cPanel, Plesk, etc.)
- ✅ No SSH/shell access
- ✅ No Composer available
- ✅ You want easiest installation
- ✅ You need everything pre-installed
- ✅ You want web-based setup

**Installation:**
```bash
# Upload via cPanel/FTP
# Extract via File Manager
# Import database via phpMyAdmin
# Visit http://yourdomain.com/install.php
# Done!
```

---

## 📋 Package Contents Breakdown

### All Packages Include:

✅ **Core Application:**
- api/ - REST API layer
- bin/ - Utility scripts
- cms/ - CMS adapters
- kernel/ - Core kernel
- public/ - Web root with built admin UI
- templates/ - Templates
- docs/ - Documentation

✅ **Configuration:**
- .env.example
- composer.json
- composer.lock

✅ **Installation:**
- install.php (web installer)
- install.sh (shell installer)
- README.md, INSTALL.md, etc.

✅ **Database:**
- database/schema.sql (minimal)
- database/basic-data.sql (complete & shared hosting)

### Additional in Complete & Shared Hosting:

✅ **Shared CMS Cores (~88 MB):**
- shared-cores/wordpress/
- shared-cores/joomla/
- shared-cores/drupal/

### Additional in Shared Hosting Only:

✅ **Vendor Dependencies (~2 MB):**
- vendor/ - All Composer packages
- No need to run `composer install`

---

## 🚀 Installation Comparison

### Minimal Archive Installation

```bash
# 1. Extract
unzip ikabud-kernel-v1.0.0.zip
cd ikabud-kernel

# 2. Install dependencies (REQUIRED)
composer install --no-dev --optimize-autoloader

# 3. Download CMS cores (if needed)
# Download WordPress, Joomla, Drupal manually
# Place in shared-cores/

# 4. Configure
cp .env.example .env
nano .env

# 5. Setup database
mysql -u root -p < database/schema.sql

# 6. Install
sudo ./install.sh
# or
php install.php
```

**Time**: 15-20 minutes  
**Difficulty**: Medium  
**Requirements**: Shell access, Composer

### Complete Archive Installation

```bash
# 1. Extract
unzip ikabud-kernel-complete-v1.0.0.zip
cd ikabud-kernel

# 2. Install dependencies (REQUIRED)
composer install --no-dev --optimize-autoloader

# 3. Configure
cp .env.example .env
nano .env

# 4. Setup database
mysql -u root -p < database/basic-data.sql

# 5. Install
sudo ./install.sh
# or
php install.php
```

**Time**: 5-10 minutes  
**Difficulty**: Easy  
**Requirements**: Shell access, Composer

### Shared Hosting Archive Installation

```bash
# 1. Upload via cPanel/FTP
# Upload ikabud-kernel-shared-hosting-v1.0.0.zip

# 2. Extract via File Manager
# Right-click → Extract

# 3. Create database via cPanel
# MySQL Databases → Create

# 4. Import database via phpMyAdmin
# Import → database/basic-data.sql

# 5. Configure via File Manager
# Copy .env.example to .env
# Edit .env with database credentials

# 6. Install via browser
# Visit: http://yourdomain.com/install.php
# Fill form and submit

# 7. Delete installer
# Delete install.php for security
```

**Time**: 3-5 minutes  
**Difficulty**: Very Easy  
**Requirements**: Web browser only

---

## 💾 Storage Requirements

### Disk Space Needed:

| Package | Download | Extracted | With Instances |
|---------|----------|-----------|----------------|
| Minimal | 1.2 MB | ~5 MB | +50 MB per instance |
| Complete | 90 MB | ~250 MB | +50 MB per instance |
| Shared Hosting | 92 MB | ~260 MB | +50 MB per instance |

**Note**: Instance size varies based on CMS type and content.

### Bandwidth Considerations:

- **Minimal**: Fast download, but needs additional downloads
- **Complete**: One-time large download, everything included
- **Shared Hosting**: One-time large download, ready to use

---

## 🔧 Maintenance & Updates

### Updating Dependencies:

**Minimal Archive:**
```bash
composer update
```

**Complete Archive:**
```bash
composer update
```

**Shared Hosting Archive:**
```bash
# Download new shared hosting archive
# Or manually update vendor/ via FTP
```

### Updating CMS Cores:

**All Packages:**
```bash
# Update WordPress
cd shared-cores/wordpress
wp core update

# Update Joomla
cd shared-cores/joomla
# Use Joomla update process

# Update Drupal
cd shared-cores/drupal
composer update drupal/core
```

---

## 🎓 Recommendations by Scenario

### Development Environment
**Use**: Minimal Archive
- Smallest size
- Latest dependencies
- Full control
- Easy to update

### Staging/Testing
**Use**: Complete Archive
- Consistent with production
- Offline capable
- Faster setup
- Includes all cores

### Production (VPS/Cloud)
**Use**: Complete Archive
- Tested package
- Known versions
- Quick deployment
- Offline installation

### Production (Shared Hosting)
**Use**: Shared Hosting Archive ⭐
- No Composer needed
- Web-based setup
- Everything included
- Easiest deployment

### Corporate/Enterprise
**Use**: Complete or Shared Hosting
- Controlled versions
- Auditable package
- Offline installation
- Reproducible deployments

### Training/Workshops
**Use**: Shared Hosting Archive
- Quick setup
- No technical requirements
- Browser-based
- Consistent environment

---

## 📊 Feature Matrix

| Feature | Minimal | Complete | Shared Hosting |
|---------|:-------:|:--------:|:--------------:|
| **Deployment** |
| VPS/Dedicated | ✅ | ✅ | ✅ |
| Shared Hosting | ⚠️ | ⚠️ | ✅ |
| Cloud Hosting | ✅ | ✅ | ✅ |
| **Requirements** |
| Composer | ✅ | ✅ | ❌ |
| Shell Access | ⚠️ | ⚠️ | ❌ |
| PHP 8.1+ | ✅ | ✅ | ✅ |
| MySQL 8.0+ | ✅ | ✅ | ✅ |
| **Installation** |
| CLI Install | ✅ | ✅ | ⚠️ |
| Web Install | ✅ | ✅ | ✅ |
| Offline Install | ❌ | ✅ | ✅ |
| **Contents** |
| Core Code | ✅ | ✅ | ✅ |
| Documentation | ✅ | ✅ | ✅ |
| Admin UI | ✅ | ✅ | ✅ |
| CMS Cores | ❌ | ✅ | ✅ |
| Vendor Deps | ❌ | ❌ | ✅ |
| Database | Schema | Schema+Data | Schema+Data |

---

## 🎯 Quick Decision Tree

```
Do you have Composer?
├─ YES → Do you have shell access?
│  ├─ YES → Want smallest download?
│  │  ├─ YES → Use Minimal Archive
│  │  └─ NO → Use Complete Archive
│  └─ NO → Use Shared Hosting Archive
└─ NO → Use Shared Hosting Archive ⭐
```

---

## 📝 Summary

### Choose Minimal if:
- You're a developer
- You have full server access
- You want latest dependencies
- Download size matters

### Choose Complete if:
- You want offline installation
- You need CMS cores included
- You have Composer available
- Setup speed matters

### Choose Shared Hosting if: ⭐
- You have shared hosting
- No Composer available
- No shell access needed
- You want easiest setup
- Everything must work out-of-box

---

**Still unsure? Use Shared Hosting Archive - it works everywhere!** 🚀
