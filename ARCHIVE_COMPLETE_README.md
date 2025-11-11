# Complete Archive Package

This document describes the complete Ikabud Kernel archive that includes shared CMS cores and basic database data.

---

## 📦 Package Contents

### Complete Archive: `ikabud-kernel-complete-v1.0.0.zip`

**Size**: ~90 MB (includes all CMS cores)

**What's Included:**

#### ✅ **Shared CMS Cores** (NEW!)
- **WordPress** - Complete WordPress core files
- **Joomla** - Complete Joomla core files  
- **Drupal** - Complete Drupal core files

These are shared across all instances, saving disk space and simplifying updates.

#### ✅ **Database with Basic Data** (NEW!)
- **database/basic-data.sql** - Complete schema + user data
  - All table structures
  - Admin user account (ready to use)
  - No instance data (clean install)
  - No cache or logs

#### ✅ **All Standard Files**
- Documentation (README, INSTALL, etc.)
- Installation scripts (install.php, install.sh)
- Core application code
- Configuration templates
- CLI tools

---

## 🎯 Two Archive Options

### Option 1: Complete Archive (Recommended)
**File**: `ikabud-kernel-complete-v1.0.0.zip`  
**Size**: ~90 MB  
**Best For**: Full installations, offline deployments

```bash
php create-archive.php ikabud-kernel-complete-v1.0.0.zip
```

**Includes:**
- ✅ Shared CMS cores (WordPress, Joomla, Drupal)
- ✅ Database schema + user data
- ✅ All application files
- ✅ Documentation
- ✅ Installation scripts

**Excludes:**
- ❌ Instance data
- ❌ Vendor dependencies (run `composer install`)
- ❌ Cache and logs

### Option 2: Minimal Archive
**File**: `ikabud-kernel-v1.0.0.zip`  
**Size**: ~1.2 MB  
**Best For**: Quick distribution, users will download CMS cores separately

```bash
# Use the old version or modify create-archive.php
```

**Includes:**
- ✅ All application files
- ✅ Documentation
- ✅ Installation scripts

**Excludes:**
- ❌ Shared CMS cores (users download separately)
- ❌ Database dump
- ❌ Instance data
- ❌ Vendor dependencies

---

## 🚀 Creating the Complete Archive

### Step 1: Export Database (Optional but Recommended)

```bash
# Export database with user data
php export-basic-db.php database/basic-data.sql
```

This creates a clean database dump with:
- All table structures
- Admin user account
- No instance-specific data

### Step 2: Create Archive

```bash
# Create complete archive
php create-archive.php ikabud-kernel-complete-v1.0.0.zip
```

The script will:
1. ✅ Add all documentation files
2. ✅ Add installation scripts
3. ✅ Add core application code
4. ✅ Add shared-cores directory (WordPress, Joomla, Drupal)
5. ✅ Add database/basic-data.sql
6. ✅ Create empty directories for instances, cache, logs
7. ✅ Exclude vendor, .env, and runtime data

### Step 3: Verify Archive

```bash
# Check archive size
ls -lh ikabud-kernel-complete-v1.0.0.zip

# List contents
unzip -l ikabud-kernel-complete-v1.0.0.zip | less

# Verify shared-cores included
unzip -l ikabud-kernel-complete-v1.0.0.zip | grep "shared-cores/"

# Verify database dump included
unzip -l ikabud-kernel-complete-v1.0.0.zip | grep "basic-data.sql"

# Verify instances excluded
unzip -l ikabud-kernel-complete-v1.0.0.zip | grep "instances/"
# Should only show: instances/ and instances/.gitkeep
```

---

## 📥 Installing from Complete Archive

### Step 1: Extract Archive

```bash
unzip ikabud-kernel-complete-v1.0.0.zip
cd ikabud-kernel-complete-v1.0.0
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

```bash
cp .env.example .env
nano .env
```

Edit database credentials and other settings.

### Step 4: Import Database

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE ikabud_kernel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Import schema and basic data
mysql -u root -p ikabud_kernel < database/basic-data.sql
```

### Step 5: Set Permissions

```bash
chmod -R 775 storage instances logs themes
chmod +x ikabud
```

### Step 6: Verify Installation

```bash
# Check kernel status
./ikabud status

# Test API
curl http://localhost/api/health
```

---

## 🎁 What You Get

### Shared CMS Cores (~88 MB)

The archive includes complete, ready-to-use CMS cores:

```
shared-cores/
├── wordpress/
│   ├── wp-admin/
│   ├── wp-content/
│   ├── wp-includes/
│   └── [all WordPress core files]
├── joomla/
│   ├── administrator/
│   ├── components/
│   ├── modules/
│   └── [all Joomla core files]
└── drupal/
    ├── core/
    ├── modules/
    ├── profiles/
    └── [all Drupal core files]
```

**Benefits:**
- ✅ **Instant Setup** - No need to download CMS cores separately
- ✅ **Offline Installation** - Works without internet connection
- ✅ **Consistent Versions** - All instances use the same tested cores
- ✅ **Space Efficient** - One copy shared by all instances
- ✅ **Easy Updates** - Update core once, affects all instances

### Database with User Data (~19 KB)

```sql
-- Complete schema for all tables:
- kernel_config
- kernel_processes  
- kernel_syscalls
- instances
- users (with admin account)
- themes
- dsl_cache
- api_tokens
- ... and more

-- Includes 1 admin user ready to use
```

**Benefits:**
- ✅ **Quick Setup** - Database ready in seconds
- ✅ **Admin Account** - Login immediately
- ✅ **Clean State** - No old data or clutter
- ✅ **Tested Schema** - Known working structure

---

## 🔍 Archive Comparison

| Feature | Complete Archive | Minimal Archive |
|---------|-----------------|-----------------|
| **Size** | ~90 MB | ~1.2 MB |
| **CMS Cores** | ✅ Included | ❌ Download separately |
| **Database** | ✅ Schema + User | ❌ Run schema.sql |
| **Documentation** | ✅ Included | ✅ Included |
| **Installers** | ✅ Included | ✅ Included |
| **Core Code** | ✅ Included | ✅ Included |
| **Offline Install** | ✅ Yes | ❌ No (needs downloads) |
| **Setup Time** | ⚡ 5 minutes | ⏱️ 15-20 minutes |
| **Best For** | Production, Offline | Development, Testing |

---

## 🛠️ Maintenance

### Updating CMS Cores

When new CMS versions are released:

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

# Recreate archive
cd /var/www/html/ikabud-kernel
php create-archive.php ikabud-kernel-complete-v1.1.0.zip
```

### Updating Database Schema

When schema changes:

```bash
# Export updated schema
php export-basic-db.php database/basic-data.sql

# Recreate archive
php create-archive.php ikabud-kernel-complete-v1.1.0.zip
```

---

## 📋 Distribution Checklist

Before distributing the complete archive:

- [ ] Shared CMS cores are up-to-date
- [ ] Database schema is current
- [ ] Admin user credentials are documented
- [ ] Archive size is reasonable (~90 MB)
- [ ] No instance data included
- [ ] No .env files included
- [ ] Documentation is current
- [ ] Version number is correct
- [ ] Archive tested by extracting
- [ ] Installation tested from archive
- [ ] CMS cores verified working
- [ ] Database import tested

---

## 💡 Tips

### For Distributors

1. **Host on CDN** - 90 MB is large, use a CDN for faster downloads
2. **Provide Checksums** - Include SHA256 checksums for verification
3. **Version Clearly** - Include version in filename
4. **Document Changes** - Update CHANGELOG.md
5. **Test Thoroughly** - Test installation on clean system

### For Users

1. **Verify Download** - Check file size and checksum
2. **Read INSTALL.md** - Follow installation guide
3. **Use Complete Archive** - Easier and faster than minimal
4. **Keep Backup** - Save archive for reinstallation
5. **Update Regularly** - Check for new releases

---

## 🆘 Troubleshooting

### "Archive too large to download"

- Use a download manager
- Try a different network
- Contact distributor for alternative download methods

### "Shared cores not working"

```bash
# Verify cores extracted properly
ls -la shared-cores/wordpress
ls -la shared-cores/joomla
ls -la shared-cores/drupal

# Check permissions
chmod -R 755 shared-cores
```

### "Database import failed"

```bash
# Check database exists
mysql -u root -p -e "SHOW DATABASES"

# Try importing with verbose output
mysql -u root -p ikabud_kernel < database/basic-data.sql -v

# Check for errors
tail -f /var/log/mysql/error.log
```

### "Missing files after extraction"

```bash
# Re-extract with verbose output
unzip -v ikabud-kernel-complete-v1.0.0.zip

# Check for extraction errors
unzip -t ikabud-kernel-complete-v1.0.0.zip
```

---

## 📊 Statistics

**Complete Archive Contents:**
- **Files**: ~36,000 files
- **Directories**: ~10,000 directories
- **Compressed Size**: ~90 MB
- **Uncompressed Size**: ~250 MB
- **CMS Cores**: 3 (WordPress, Joomla, Drupal)
- **Database Tables**: 17 tables
- **Documentation Files**: 9 files

---

## 🎯 Use Cases

### Perfect For:

✅ **Production Deployments** - Everything included, tested, ready  
✅ **Offline Installations** - No internet required after download  
✅ **Corporate Environments** - Controlled, versioned packages  
✅ **Training/Demos** - Quick setup for workshops  
✅ **Disaster Recovery** - Complete backup package  

### Not Ideal For:

❌ **Slow Connections** - 90 MB may be too large  
❌ **Limited Storage** - Requires ~250 MB uncompressed  
❌ **Custom CMS Versions** - Uses specific core versions  

---

**Ready to distribute!** 🚀

For questions or issues, see the main documentation or contact support.
