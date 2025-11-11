# Creating Distribution Archives

This document explains how to create distribution archives for Ikabud Kernel.

---

## Quick Archive Creation

### Using the PHP Script (Recommended)

```bash
# Create archive with default name (ikabud-kernel-YYYY-MM-DD.zip)
php create-archive.php

# Create archive with custom name
php create-archive.php ikabud-kernel-v1.0.0.zip
```

### Using the Release Package Script

```bash
# Create complete release package with tar.gz and zip
bin/create-release-package 1.0.0
```

---

## What's Included in the Archive

### ✅ Included Files

**Documentation:**
- README.md
- INSTALL.md
- REQUIREMENTS.md
- QUICK_START.md
- CHANGELOG.md
- CONTRIBUTING.md
- LICENSE
- PACKAGE_INFO.md
- INSTALLATION_PACKAGE_SUMMARY.txt

**Installation Scripts:**
- install.php (PHP CLI/Web installer)
- install.sh (Shell script installer)

**Core Application:**
- api/ (REST API)
- bin/ (Utility scripts)
- cms/ (CMS adapters)
- database/ (Database schema)
- docs/ (Documentation)
- dsl/ (DSL compiler)
- kernel/ (Core kernel)
- public/ (Web root)
- templates/ (Templates)
- admin/dist/ (Built admin UI)

**Configuration:**
- composer.json
- composer.lock
- .env.example
- ikabud (CLI tool)

**Empty Directories (with .gitkeep):**
- instances/
- shared-cores/
- storage/cache/
- storage/logs/
- themes/
- logs/

### ❌ Excluded Files

**Runtime Data:**
- instances/* (all instance data)
- storage/cache/* (cache files)
- storage/logs/* (log files)
- logs/* (application logs)

**Dependencies:**
- vendor/* (Composer dependencies)
- node_modules/* (NPM dependencies)

**Development Files:**
- .git/* (Git repository)
- .vscode/* (VS Code settings)
- .idea/* (PhpStorm settings)
- *.swp, *.swo (Vim swap files)

**Environment Files:**
- .env (contains secrets)
- .env.local

**Temporary Files:**
- *.tmp
- *.temp
- *.log
- *~

**Archives:**
- *.zip
- *.tar.gz
- *.tar

**OS Files:**
- .DS_Store (macOS)
- Thumbs.db (Windows)

---

## Archive Details

### File Statistics

- **Total Files**: ~145 files
- **Total Directories**: ~13 directories
- **Archive Size**: ~1.2 MB (compressed)
- **Uncompressed Size**: ~4.4 MB

### Archive Structure

```
ikabud-kernel-v1.0.0.zip
├── README.md
├── INSTALL.md
├── REQUIREMENTS.md
├── QUICK_START.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE
├── PACKAGE_INFO.md
├── INSTALLATION_PACKAGE_SUMMARY.txt
├── composer.json
├── composer.lock
├── .env.example
├── ikabud
├── install.sh
├── install.php
├── api/
│   ├── middleware/
│   └── routes/
├── bin/
├── cms/
│   └── Adapters/
├── database/
├── docs/
├── dsl/
├── kernel/
├── public/
│   ├── admin/
│   └── assets/
├── templates/
├── admin/
│   └── dist/
├── instances/ (empty)
├── shared-cores/ (empty)
├── storage/
│   ├── cache/ (empty)
│   └── logs/ (empty)
├── themes/ (empty)
└── logs/ (empty)
```

---

## Installation from Archive

### Step 1: Extract Archive

```bash
# Extract ZIP file
unzip ikabud-kernel-v1.0.0.zip

# Navigate to directory
cd ikabud-kernel-v1.0.0
```

### Step 2: Install Dependencies

```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

```bash
# Copy environment template
cp .env.example .env

# Edit configuration
nano .env
```

### Step 4: Run Installer

```bash
# Option 1: PHP installer
php install.php

# Option 2: Shell script installer
sudo ./install.sh

# Option 3: Web installer
# Navigate to: http://yourdomain.com/install.php
```

---

## Verification

### Verify Archive Contents

```bash
# List all files in archive
unzip -l ikabud-kernel-v1.0.0.zip

# Check archive integrity
unzip -t ikabud-kernel-v1.0.0.zip

# View archive comment
unzip -z ikabud-kernel-v1.0.0.zip
```

### Verify Exclusions

```bash
# Verify no instance data included
unzip -l ikabud-kernel-v1.0.0.zip | grep "instances/"
# Should only show: instances/ and instances/.gitkeep

# Verify no vendor directory
unzip -l ikabud-kernel-v1.0.0.zip | grep "vendor/"
# Should return nothing

# Verify no .env file
unzip -l ikabud-kernel-v1.0.0.zip | grep "\.env$"
# Should return nothing (only .env.example)
```

---

## Distribution Checklist

Before distributing the archive:

- [ ] Archive created successfully
- [ ] Archive size is reasonable (~1-2 MB)
- [ ] No instance data included
- [ ] No .env files included
- [ ] No vendor directory included
- [ ] All documentation files included
- [ ] Both installers included (install.php and install.sh)
- [ ] Empty directories have .gitkeep files
- [ ] Archive tested by extracting
- [ ] Installation tested from extracted archive
- [ ] README and documentation reviewed
- [ ] Version number updated in files

---

## Troubleshooting

### "ZIP extension not available"

```bash
# Ubuntu/Debian
sudo apt-get install php-zip

# CentOS/RHEL
sudo yum install php-zip

# Verify installation
php -m | grep zip
```

### "Permission denied"

```bash
# Make script executable
chmod +x create-archive.php

# Run with proper permissions
php create-archive.php
```

### "Archive too large"

The archive should be around 1-2 MB. If it's much larger:

1. Check if vendor/ was included (should be excluded)
2. Check if instances/ data was included (should be excluded)
3. Check if node_modules/ was included (should be excluded)
4. Review the exclusion patterns in create-archive.php

### "Missing files in archive"

If files are missing:

1. Check the exclusion patterns
2. Ensure files exist in source directory
3. Check file permissions
4. Review the script output for errors

---

## Advanced Usage

### Custom Exclusions

Edit `create-archive.php` and modify the `$excludePatterns` array:

```php
$excludePatterns = [
    'instances/*',
    'vendor/*',
    // Add your custom patterns here
    'custom-dir/*',
    '*.custom',
];
```

### Include Additional Files

Edit `create-archive.php` and modify the `$rootFiles` array:

```php
$rootFiles = [
    'README.md',
    'INSTALL.md',
    // Add your custom files here
    'CUSTOM_FILE.md',
];
```

### Automated Archive Creation

Add to cron for automated backups:

```bash
# Daily archive at 2 AM
0 2 * * * cd /var/www/html/ikabud-kernel && php create-archive.php ikabud-backup-$(date +\%Y\%m\%d).zip
```

---

## Notes

- **Security**: The archive excludes .env files to prevent accidental exposure of secrets
- **Size**: Excluding vendor/ keeps the archive small; users run `composer install` after extraction
- **Instances**: Instance data is excluded as it's runtime/user-specific data
- **Dependencies**: Users must run `composer install` after extraction
- **Empty Directories**: Kept with .gitkeep files to maintain structure

---

## Support

For issues with archive creation:
- Check the script output for errors
- Verify PHP ZIP extension is installed
- Ensure proper file permissions
- Review the troubleshooting section above

---

**Happy distributing!** 🚀
