# Drupal Version Management

The Ikabud Kernel supports multiple Drupal versions through the shared-cores directory.

## Available Versions

- **drupal** (v10.3.10) - Default, compatible with MySQL 5.7+ / MariaDB 10.3+ AND PHP 8.1-8.3
- **drupal11** (v11.0.5) - Latest version, requires MySQL 8.0+ / MariaDB 10.6+ AND PHP 8.3+

## MySQL/MariaDB & PHP Requirements

| Drupal Version | MySQL Version | MariaDB Version | PHP Version |
|----------------|---------------|-----------------|-------------|
| 10.x           | 5.7.8+        | 10.3.7+         | 8.1 - 8.3   |
| 11.x           | 8.0+          | 10.6+           | 8.3+        |

## Setup Instructions

### 1. Initial Setup

Run the setup script to organize Drupal versions:

```bash
chmod +x setup-drupal-versions.sh
./setup-drupal-versions.sh
```

This will:
- Rename the current Drupal 11.0.5 to `shared-cores/drupal11`
- Download and extract Drupal 10.3.10 as `shared-cores/drupal`

### 2. Install Composer Dependencies

After setup, install dependencies for the default version:

```bash
cd shared-cores/drupal
composer install --no-dev --optimize-autoloader
composer require drush/drush --no-dev
```

If you plan to use Drupal 11:

```bash
cd shared-cores/drupal11
composer install --no-dev --optimize-autoloader
composer require drush/drush --no-dev
```

## Creating Instances

### Create Drupal 9 Instance (Default)

```bash
./bin/create-drupal-instance \
  dpl-mysite-001 \
  "My Drupal Site" \
  mysite.example.com \
  database_name \
  database_user \
  database_password \
  drupal_
```

### Create Drupal 11 Instance

Add `drupal11` as the last parameter:

```bash
./bin/create-drupal-instance \
  dpl-mysite-002 \
  "My Drupal 11 Site" \
  mysite11.example.com \
  database_name \
  database_user \
  database_password \
  drupal_ \
  drupal11
```

## Hosting Compatibility

### Bluehost / Shared Hosting (MySQL 5.7, PHP 8.1-8.3)
- Use **drupal** (v10.3.10) - Default
- Compatible with MySQL 5.7.x / MariaDB 10.3+ and PHP 8.1-8.3

### VPS / Cloud Hosting (MySQL 8.0+)
- Can use either version
- **drupal11** (v11.0.5) recommended for new projects

## Directory Structure

```
shared-cores/
├── drupal/          # v10.3.10 (default)
│   ├── core/
│   ├── vendor/
│   │   └── bin/
│   │       └── drush
│   └── ...
└── drupal11/        # v11.0.5
    ├── core/
    ├── vendor/
    │   └── bin/
    │       └── drush
    └── ...
```

## Drush Installation

Drush is required for automated Drupal installation. Install it after setting up Composer dependencies:

```bash
cd shared-cores/drupal
composer require drush/drush --no-dev
```

## Troubleshooting

### Missing Drush

If Drush is not found during instance creation:

```bash
cd shared-cores/drupal
composer require drush/drush --no-dev
```

### Wrong MySQL Version

If you see "MySQL 8.0 or higher required":
- You're trying to use Drupal 11 with MySQL 5.7
- Either upgrade MySQL or use Drupal 9.5.11 (default)

### Drush Not Executing on Staging

The improved script now shows:
- PHP path being used
- Drush path being used
- Full error output if installation fails

You can always complete installation manually at:
`http://admin.yourdomain.com/core/install.php`

### Manual Installation

If Drush fails, you can install Drupal through the web interface:

1. Visit: `http://admin.yourdomain.com/core/install.php`
2. Follow the installation wizard
3. Database credentials are already configured in `sites/default/settings.php`
