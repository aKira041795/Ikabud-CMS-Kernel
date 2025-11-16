# Joomla Version Management

The Ikabud Kernel supports multiple Joomla versions through the shared-cores directory.

## Available Versions

- **joomla** (v4.4.14) - Default, compatible with MySQL 5.7+ and MariaDB 10.1+
- **joomla5** (v5.2.1) - Latest version, requires MySQL 8.0.13+ or MariaDB 10.4+

## Setup Instructions

### 1. Initial Setup

Run the setup script to organize Joomla versions:

```bash
chmod +x setup-joomla-versions.sh
./setup-joomla-versions.sh
```

This will:
- Rename the current Joomla 5.2.1 to `shared-cores/joomla5`
- Download and extract Joomla 4.4.14 as `shared-cores/joomla`

### 2. Install Composer Dependencies

After setup, install vendor dependencies for the default version:

```bash
cd shared-cores/joomla/libraries
composer install --no-dev --optimize-autoloader
```

If you plan to use Joomla 5:

```bash
cd shared-cores/joomla5/libraries
composer install --no-dev --optimize-autoloader
```

## Creating Instances

### Create Joomla 4.4.14 Instance (Default)

```bash
./bin/create-joomla-instance \
  jml-mysite-001 \
  "My Joomla Site" \
  mysite.example.com \
  database_name \
  database_user \
  database_password \
  jml_
```

### Create Joomla 5.2.1 Instance

Add `joomla5` as the last parameter:

```bash
./bin/create-joomla-instance \
  jml-mysite-002 \
  "My Joomla 5 Site" \
  mysite5.example.com \
  database_name \
  database_user \
  database_password \
  jml_ \
  joomla5
```

## Hosting Compatibility

### Bluehost / Shared Hosting (MySQL 5.7)
- Use **joomla** (v4.4.14) - Default
- Compatible with MySQL 5.7.x and MariaDB 10.1+

### VPS / Cloud Hosting (MySQL 8.0+)
- Can use either version
- **joomla5** (v5.2.1) recommended for new projects

## Directory Structure

```
shared-cores/
├── joomla/          # v4.4.14 (default)
│   ├── administrator/
│   ├── libraries/
│   │   └── vendor/  # Composer dependencies
│   └── ...
└── joomla5/         # v5.2.1
    ├── administrator/
    ├── libraries/
    │   └── vendor/  # Composer dependencies
    └── ...
```

## Troubleshooting

### Missing Vendor Dependencies

If you see errors about missing PSR classes:

```bash
cd shared-cores/joomla/libraries
composer install --no-dev
```

### Wrong MySQL Version

If you see "MySQL 8.0.13 or higher required":
- You're trying to use Joomla 5 with MySQL 5.7
- Either upgrade MySQL or use Joomla 4.4.14 (default)

### Constant Already Defined Warnings

Ensure your instance's `defines.php` and `index.php` are updated:
- See templates/joomla-defines.php
- See templates/joomla-site-index.php
