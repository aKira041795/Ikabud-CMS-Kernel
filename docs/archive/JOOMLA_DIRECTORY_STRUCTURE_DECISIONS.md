# Joomla Instance Directory Structure Decisions

## Overview

This document explains the rationale behind which directories are instance-specific (physical) vs shared (symlinked) in the Ikabud Kernel architecture.

## Directory Classification

### Instance-Specific Directories (Physical/Copied)

These directories contain data that is unique to each instance and should NOT be shared:

#### 1. **`images/` Directory**
- **Size**: ~1.1MB (default)
- **Purpose**: User-uploaded images and media files
- **Why Instance-Specific**: 
  - Each site has different content and uploaded images
  - Users upload files through Joomla's media manager
  - Cannot be shared between instances
- **Subdirectories**:
  - `banners/` - Banner images
  - `headers/` - Header images
  - `sampledata/` - Sample data images
- **Permissions**: 775 (writable by web server)

#### 2. **`media/` Directory**
- **Size**: ~39MB (default)
- **Purpose**: Component-specific CSS, JavaScript, and media assets
- **Why Instance-Specific**:
  - Instances may install different extensions
  - Extensions may be customized per instance
  - Component updates may differ between instances
  - Custom CSS/JS modifications per instance
- **Contents**: Component assets (com_content, com_contact, etc.)
- **Permissions**: 775 (writable for extension installations)

#### 3. **`templates/` Directory**
- **Size**: ~varies
- **Purpose**: Site and admin templates/themes
- **Why Instance-Specific**:
  - Each instance may use different templates
  - Template customizations are instance-specific
  - Custom template overrides per instance
  - Template parameters differ per instance
- **Default Templates**: Cassiopeia (site), Atum (admin)
- **Permissions**: 755 (writable for template installations)

#### 4. **`tmp/` Directory**
- **Size**: Minimal (temporary files)
- **Purpose**: Temporary file storage
- **Why Instance-Specific**:
  - Prevents file conflicts between instances
  - Each instance needs isolated temp space
  - Security isolation
  - Session-specific temporary files
- **Permissions**: 775 (writable by web server)

#### 5. **`administrator/cache/` Directory**
- **Purpose**: Instance-specific admin cache
- **Why Instance-Specific**: Each instance has different cache data
- **Permissions**: 775 (writable)

#### 6. **`administrator/logs/` Directory**
- **Purpose**: Instance-specific log files
- **Why Instance-Specific**: Each instance needs separate logs
- **Permissions**: 775 (writable)

#### 7. **`administrator/manifests/` Directory**
- **Purpose**: Extension manifests for installed extensions
- **Why Instance-Specific**: Each instance may have different extensions
- **Permissions**: 775 (writable)

### Shared Directories (Symlinks)

These directories contain core Joomla files that are identical across all instances:

#### 1. **`components/` Directory**
- **Purpose**: Core Joomla components
- **Why Shared**: Core components are identical across instances
- **Read-Only**: Yes

#### 2. **`modules/` Directory**
- **Purpose**: Core Joomla modules
- **Why Shared**: Core modules are identical across instances
- **Read-Only**: Yes

#### 3. **`plugins/` Directory**
- **Purpose**: Core Joomla plugins
- **Why Shared**: Core plugins are identical across instances
- **Read-Only**: Yes

#### 4. **`libraries/` Directory**
- **Purpose**: Core Joomla libraries and vendor packages
- **Why Shared**: Core libraries are identical across instances
- **Read-Only**: Yes

#### 5. **`language/` Directory**
- **Purpose**: Core language files
- **Why Shared**: Core translations are identical across instances
- **Read-Only**: Yes

#### 6. **`layouts/` Directory**
- **Purpose**: Core layout files
- **Why Shared**: Core layouts are identical across instances
- **Read-Only**: Yes

#### 7. **`cache/` Directory**
- **Purpose**: Shared system cache
- **Why Shared**: System cache can be shared (read-only)
- **Read-Only**: Yes

#### 8. **`includes/` Directory**
- **Purpose**: Core include files
- **Why Shared**: Core includes are identical across instances
- **Read-Only**: Yes

#### 9. **`api/` Directory**
- **Purpose**: Joomla API files
- **Why Shared**: API files are identical across instances
- **Read-Only**: Yes

#### 10. **`cli/` Directory**
- **Purpose**: Command-line interface scripts
- **Why Shared**: CLI scripts are identical across instances
- **Read-Only**: Yes

#### 11. **`installation/` Directory**
- **Purpose**: Joomla installation wizard
- **Why Shared**: Installation wizard is identical across instances
- **Read-Only**: Yes

#### 12. **Administrator Subdirectories** (Symlinked)
- `administrator/components/`
- `administrator/help/`
- `administrator/includes/`
- `administrator/language/`
- `administrator/modules/`
- `administrator/templates/`

All symlinked with `../../../shared-cores/joomla/administrator/...`

## Disk Space Considerations

### Per Instance Storage Requirements

**Minimal (with symlinks):**
- Configuration files: ~10KB
- Instance-specific directories:
  - `images/`: ~1.1MB (grows with uploads)
  - `media/`: ~39MB (grows with extensions)
  - `templates/`: ~5-10MB (varies by template)
  - `tmp/`: ~1MB (temporary)
  - `administrator/cache/`: ~1MB (varies)
  - `administrator/logs/`: ~1MB (grows over time)
  - `administrator/manifests/`: ~100KB

**Total per instance**: ~50-60MB (initial) + growth from user content

**Without symlinks (full copy)**: ~200-300MB per instance

**Savings**: ~150-250MB per instance using shared core architecture

### 100 Instances Comparison

- **With shared core**: ~5-6GB total
- **Without shared core**: ~20-30GB total
- **Disk space saved**: ~15-24GB (75-80% reduction)

## Extension Installation Considerations

### Core Extensions (Shared)
- Installed in shared core
- Available to all instances
- Updates applied once, affect all instances

### Instance-Specific Extensions
- Installed in instance's `media/` directory
- Only available to that instance
- Updates per instance

### Best Practice
- Keep core extensions in shared core
- Install custom/unique extensions per instance
- Use instance-specific `media/` for customizations

## Template Customization Strategy

### Default Templates (Shared Core)
- Cassiopeia (site template)
- Atum (admin template)
- Available to all instances via symlink

### Instance-Specific Templates
- Copied from shared core during instance creation
- Can be customized per instance
- Template overrides stored in instance directory

### Custom Templates
- Installed directly in instance's `templates/` directory
- Unique to that instance

## Backup Considerations

### What to Backup Per Instance
1. **Database** (complete)
2. **Configuration files**:
   - `configuration.php`
   - `defines.php`
   - `.htaccess`
3. **User content**:
   - `images/` (all user uploads)
   - `media/` (if customized)
   - `templates/` (if customized)
4. **Logs** (optional):
   - `administrator/logs/`

### What NOT to Backup
- Symlinked directories (they point to shared core)
- Shared core files (backup once, not per instance)
- Temporary files (`tmp/`, `cache/`)

### Backup Size Per Instance
- Typical: 50-500MB (depending on uploaded content)
- Without symlinks: 200MB-1GB+

## Migration Considerations

### Moving an Instance
1. Export database
2. Copy instance directory (physical files only)
3. Recreate symlinks on new server
4. Update configuration.php paths
5. Import database

### Cloning an Instance
1. Copy instance directory
2. Create new database
3. Update configuration.php
4. Update instance.json
5. Clear cache

## Security Considerations

### File Permissions
- **Shared core**: 755 (read-only for web server)
- **Instance files**: 755 (read-only for web server)
- **Writable directories**: 775 (writable by web server)
- **Configuration**: 644 (read-only for web server)

### Isolation
- Each instance has isolated:
  - Database
  - User uploads (`images/`)
  - Temporary files (`tmp/`)
  - Logs (`administrator/logs/`)
  - Cache (`administrator/cache/`)

### Shared Security
- Core vulnerabilities affect all instances
- Update shared core to patch all instances
- Monitor shared core for security issues

## Performance Considerations

### Benefits of Shared Core
- **Reduced disk I/O**: Core files cached once
- **Reduced memory**: Shared opcache for core files
- **Faster deployment**: No need to copy core files
- **Faster updates**: Update core once

### Potential Issues
- **Symlink overhead**: Minimal (modern filesystems handle well)
- **Cache conflicts**: Mitigated by instance-specific cache directories
- **Extension conflicts**: Mitigated by instance-specific media directories

## Maintenance

### Updating Core
1. Backup shared core
2. Update shared core files
3. Test on one instance
4. All instances automatically use updated core
5. Clear instance caches if needed

### Updating Instance-Specific Files
1. Update per instance as needed
2. No impact on other instances
3. Can test updates on single instance

## Conclusion

The shared core architecture with instance-specific directories for user content provides:
- **75-80% disk space savings**
- **Simplified core updates**
- **Instance isolation for user content**
- **Flexibility for customization**
- **Efficient resource usage**

This approach balances the benefits of shared resources with the need for instance-specific customization and data isolation.
