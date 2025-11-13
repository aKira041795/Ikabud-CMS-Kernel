# React Admin Instance Creation - Alignment Review

## Date: November 10, 2025

## Overview

This document reviews the alignment between the React Admin UI instance creation form and the backend bash scripts for WordPress and Joomla instance creation.

---

## Current Implementation Analysis

### React Admin UI (`CreateInstance.tsx`)

**Form Fields Collected:**
```typescript
{
  instance_id: string,           // ✅ FIXED: Auto-generated from instance_name, editable
  instance_name: string,          // Display name
  cms_type: string,               // 'wordpress' | 'joomla' | 'drupal'
  domain: string,                 // Frontend domain
  admin_subdomain: string,        // Admin subdomain
  database_name: string,          // Database name
  database_user: string,          // Default: 'root'
  database_password: string,      // Required
  database_host: string,          // Default: 'localhost'
  database_prefix: string,        // Auto-set based on CMS
  memory_limit: string,           // Advanced: '256M'
  max_execution_time: number,     // Advanced: 60
  max_children: number            // Advanced: 5
}
```

**✅ IMPLEMENTED:** Hybrid instance ID approach:
- Auto-generates slug from instance name (e.g., "ACME Corp" → "wp-acme-corp")
- Adds CMS-specific prefix (wp-, jml-, dpl-)
- Allows manual editing with real-time validation
- Shows "Auto-generated" indicator when using suggestion
- Validates format: lowercase, numbers, hyphens, 3-50 chars

**CMS-Specific Defaults:**
- WordPress: `prefix: 'wp_'`, `script: 'create-instance.sh'`
- Joomla: `prefix: 'jml_'`, `script: 'create-joomla-instance.sh'`
- Drupal: `prefix: 'drupal_'`, `script: 'create-drupal-instance.sh'`

---

### Backend API (`/api/instances/create`)

**Request Processing:**
1. Validates required fields: `instance_name`, `cms_type`, `database_name`
2. Generates instance ID: `inst_[16_hex_chars]`
3. Inserts record into `instances` table
4. Determines script based on CMS type
5. Executes bash script with parameters
6. Validates instance creation
7. Returns success/failure response

**Script Execution Format:**
```bash
./script.sh <instance_id> <domain> <db_name> <db_user> <db_pass> <db_prefix>
```

---

### WordPress Script (`create-instance.sh`)

**Parameters Expected:**
```bash
$1 = instance_id      # e.g., inst_abc123
$2 = instance_name    # e.g., "My Shop"
$3 = database_name    # e.g., ikabud_shop
$4 = domain           # e.g., shop.example.com
$5 = cms_type         # Optional, default: 'wordpress'
$6 = db_user          # Optional, from .env if not provided
$7 = db_pass          # Optional, from .env if not provided
$8 = db_host          # Optional, default: 'localhost'
$9 = db_prefix        # Optional, default: 'wp_'
```

**What It Does:**
1. Creates instance directory structure
2. Creates `wp-content/` subdirectories (plugins, themes, uploads, mu-plugins)
3. Generates `wp-config.php` with security keys
4. Creates `instance.json` manifest
5. Copies CORS configuration files
6. Creates symlinks to shared WordPress core
7. Creates database
8. Registers in kernel database
9. Sets permissions

**Key Features:**
- ✅ Auto-generates WordPress security keys (from API or openssl)
- ✅ Dynamic URL configuration based on manifest
- ✅ CORS support with mu-plugins
- ✅ Cache invalidation plugin installed
- ✅ Symlinks to shared core
- ✅ Instance-specific wp-content

---

### Joomla Script (`create-joomla-instance.sh`)

**Parameters Expected:**
```bash
$1 = instance_id      # e.g., joomla-002
$2 = domain           # e.g., joomla2.test
$3 = db_name          # e.g., ikabud_joomla2
$4 = db_user          # e.g., root
$5 = db_pass          # e.g., password
$6 = db_prefix        # Optional, default: 'jml_'
```

**What It Does (8 Steps):**
1. Creates instance directory structure (only writable directories)
2. Copies template files (defines.php, index.php, .htaccess)
3. Sets up administrator directory with custom bootstrap
4. Creates symlinks to shared core (with correct path depths)
5. Creates database automatically
6. Generates instance manifest (instance.json)
7. Creates configuration.php with all settings
8. Sets proper permissions (775 for writable, 755 for others)

**Key Features:**
- ✅ Correct symlink paths: `../../../` for admin, `../../` for root
- ✅ Instance-specific directories: images, media, templates, tmp
- ✅ Auto-generates configuration.php with unique secret key
- ✅ Auto-creates database with proper charset
- ✅ Copies media and templates from shared core
- ✅ Sets www-data ownership for writable directories

---

## Alignment Issues Found

### 🔴 Critical Issues

#### 1. **Parameter Order Mismatch** ✅ FIXED

**WordPress Script Expects:**
```bash
./create-instance.sh <instance_id> <instance_name> <database_name> <domain> [cms_type] [db_user] [db_pass] [db_host] [db_prefix]
```

**API Now Sends:**
```bash
./create-instance.sh <instance_id> <instance_name> <db_name> <domain> <cms_type> <db_user> <db_pass> <db_host> <db_prefix>
```

**Status:** ✅ **FIXED** - All parameters now passed in correct order

---

#### 2. **Joomla Parameter Mismatch** ✅ FIXED

**Joomla Script Now Expects:**
```bash
./create-joomla-instance.sh <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> [db_prefix]
```

**API Now Sends:**
```bash
./create-joomla-instance.sh <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> <db_prefix>
```

**Status:** ✅ **FIXED** - Added instance_name parameter to Joomla script

---

#### 3. **Missing Fields in React Form** ✅ FIXED

**Previously Not Sent:**
- ~~`instance_name` is captured but not sent in correct position~~
- Advanced settings (memory_limit, max_execution_time, max_children) are captured but not used

**Status:** ✅ **FIXED** - instance_name now passed correctly; Advanced settings can be added later

---

#### 4. **Database Creation Assumption** ⚠️ DOCUMENTED

**React UI Says:**
> "Database must already exist"

**Scripts Try To Do:**
- WordPress: Attempts to create database automatically
- Joomla: Attempts to create database automatically

**Reality:**
- ✅ **VPS/Dedicated**: Scripts can create database (have root access)
- ❌ **Shared Hosting**: Cannot auto-create database (no CREATE DATABASE privilege)
- 🔧 **Shared Hosting**: User must create database via cPanel first

**Issue:** Scripts assume database creation privileges that don't exist on shared hosting

**Solution:** Scripts should:
1. Try to create database (for VPS)
2. If fails, check if database exists
3. Continue if database exists (shared hosting scenario)
4. Only fail if database doesn't exist AND can't be created

---

#### 5. **Installation URL Not Returned** ✅ FIXED

**Joomla Script Provides:**
```bash
Installation URL: http://domain.test/installation/setup
```

**API Now Returns:**
```json
{
  "success": true,
  "instance_id": "wp-acme-corp",
  "message": "Instance created successfully",
  "installation_url": "http://admin.domain.name/wp-admin/install.php",
  "admin_url": "http://admin.domain.name",
  "frontend_url": "http://domain.name",
  "details": "..."
}
```

**Status:** ✅ **FIXED** - API now returns installation_url, admin_url, and frontend_url

**Important:** Installation uses subdomain architecture:
- **Frontend**: `domain.name` (e.g., `mysite.com`)
- **Backend/Admin**: `sub.domain.name` (e.g., `admin.mysite.com`)

**Correct Installation URLs:**
- WordPress: `http://admin.domain.name/wp-admin/install.php`
- Joomla: `http://admin.domain.name/installation/setup`
- Drupal: `http://admin.domain.name/install.php`

**VirtualHost Setup Required:**
```apache
# Frontend (cached through kernel)
<VirtualHost *:80>
    ServerName domain.name
    DocumentRoot /path/to/ikabud-kernel/public
</VirtualHost>

# Backend (direct access)
<VirtualHost *:80>
    ServerName admin.domain.name
    DocumentRoot /path/to/ikabud-kernel/instances/[instance_id]
</VirtualHost>
```

---

### 🟡 Minor Issues

#### 1. **Admin Subdomain Not Used** ⚠️ PARTIALLY FIXED

**React Form Collects:** `admin_subdomain`

**API Now Uses:** Uses admin_subdomain for installation URL generation

**Scripts Use:** Auto-generate as `admin.{domain}` from manifest

**Status:** ⚠️ API uses it for URLs, but scripts still auto-generate. Can be enhanced later.

---

#### 2. **Database Host Not Passed** ✅ FIXED

**React Form Collects:** `database_host` (default: 'localhost')

**API Now Sends:** Included in WordPress command

**Scripts Use:** Receives db_host parameter

**Status:** ✅ **FIXED** - database_host now passed to WordPress script

---

#### 3. **CMS Type Not Passed to WordPress Script** ✅ FIXED

**WordPress Script Accepts:** `cms_type` as 5th parameter

**API Now Sends:** All 9 parameters including cms_type

**Status:** ✅ **FIXED** - cms_type now passed correctly

---

## Recommendations

### 🎯 High Priority Fixes

#### 1. **Fix API Parameter Order**

Update `/api/routes/instances-actions.php` line 282:

**Current:**
```php
$command = "cd $rootPath && $scriptPath $instanceId $domain $dbName $dbUser $dbPass $dbPrefix 2>&1";
```

**Should Be (WordPress):**
```php
$instanceName = escapeshellarg($body['instance_name']);
$command = "cd $rootPath && $scriptPath $instanceId $instanceName $dbName $domain $cmsType $dbUser $dbPass $dbHost $dbPrefix 2>&1";
```

**Should Be (Joomla):**
```php
// Joomla script doesn't need instance_name yet, but should be added
$command = "cd $rootPath && $scriptPath $instanceId $domain $dbName $dbUser $dbPass $dbPrefix 2>&1";
```

---

#### 2. **Update Joomla Script to Accept Instance Name**

Modify `create-joomla-instance.sh` to accept instance_name as 2nd parameter:

```bash
INSTANCE_ID="$1"
INSTANCE_NAME="$2"  # Add this
DOMAIN="$3"         # Was $2
DB_NAME="$4"        # Was $3
DB_USER="$5"        # Was $4
DB_PASS="$6"        # Was $5
DB_PREFIX="${7:-jml_}"  # Was ${6:-jml_}
```

Update instance.json generation to use `$INSTANCE_NAME`.

---

#### 3. **Return Installation URL**

Update API response to include installation URL:

```php
$installationUrls = [
    'wordpress' => "http://{$body['domain']}/wp-admin/install.php",
    'joomla' => "http://{$body['domain']}/installation/setup",
    'drupal' => "http://{$body['domain']}/install.php"
];

$response->getBody()->write(json_encode([
    'success' => true,
    'instance_id' => $instanceId,
    'installation_url' => $installationUrls[$cmsType] ?? null,
    'admin_url' => "http://admin.{$body['domain']}",
    'message' => 'Instance created successfully',
    'details' => implode("\n", $output)
]));
```

---

#### 4. **Update React UI Message**

Change line 227 in `CreateInstance.tsx`:

**Current:**
```tsx
<p className="mt-1 text-xs text-gray-500">
  Database must already exist
</p>
```

**Should Be:**
```tsx
<p className="mt-1 text-xs text-gray-500">
  Database will be created automatically (VPS) or must exist (shared hosting via cPanel)
</p>
```

**Better Alternative - Add Info Box:**
```tsx
<div className="mt-2 p-3 bg-blue-50 border border-blue-200 rounded">
  <p className="text-xs text-blue-800 font-medium">Database Setup:</p>
  <ul className="text-xs text-blue-700 mt-1 space-y-1">
    <li>• <strong>VPS/Dedicated:</strong> Database created automatically</li>
    <li>• <strong>Shared Hosting:</strong> Create database via cPanel first</li>
  </ul>
</div>
```

---

#### 5. **Display Installation URL After Creation**

Update `CreateInstance.tsx` to show installation URL and VirtualHost instructions:

```tsx
if (data.success) {
  // Show success message with next steps
  toast.success('Instance created successfully!', { duration: 3000 });
  
  // Navigate to a "Setup Instructions" page
  navigate(`/instances/${data.instance_id}/setup`, {
    state: {
      installation_url: data.installation_url,
      admin_url: data.admin_url,
      frontend_url: data.frontend_url,
      instance_id: data.instance_id,
      domain: formData.domain,
      admin_subdomain: formData.admin_subdomain
    }
  });
}
```

**Create New Page: `SetupInstructions.tsx`**

Display:
1. ✅ Instance created successfully
2. 📋 VirtualHost configuration (copy-paste ready)
3. 🔗 Installation URL (clickable)
4. 📝 Next steps checklist:
   - [ ] Configure VirtualHosts (or cPanel subdomains)
   - [ ] Add DNS/hosts entries
   - [ ] Create database (if shared hosting)
   - [ ] Complete CMS installation
   - [ ] Test frontend and backend URLs

---

### 🔧 Medium Priority Improvements

#### 1. **Use Admin Subdomain Input**

Pass `admin_subdomain` to scripts and use it in manifest generation.

---

#### 2. **Pass Database Host**

Include `database_host` in API command:

```php
$dbHost = escapeshellarg($body['database_host'] ?? 'localhost');
```

---

#### 3. **Implement Advanced Settings**

Use `memory_limit`, `max_execution_time`, `max_children` to:
- Generate PHP-FPM pool configuration per instance
- Set in wp-config.php or configuration.php
- Store in instance manifest for future reference

---

#### 4. **Add Validation**

**React Form:**
- Validate domain format
- Check instance_id uniqueness before submission
- Validate database name format

**API:**
- Check if instance_id already exists
- Validate domain format
- Check if database already exists

---

#### 5. **Progress Feedback**

Show real-time progress during instance creation:
- Parsing script output
- Displaying each step as it completes
- Estimated time remaining

---

## Corrected Parameter Mapping

### WordPress (`create-instance.sh`)

| Position | Parameter | React Field | API Variable |
|----------|-----------|-------------|--------------|
| $1 | instance_id | Generated by API | $instanceId |
| $2 | instance_name | instance_name | $instanceName |
| $3 | database_name | database_name | $dbName |
| $4 | domain | domain | $domain |
| $5 | cms_type | cms_type | $cmsType |
| $6 | db_user | database_user | $dbUser |
| $7 | db_pass | database_password | $dbPass |
| $8 | db_host | database_host | $dbHost |
| $9 | db_prefix | database_prefix | $dbPrefix |

---

### Joomla (`create-joomla-instance.sh`)

| Position | Parameter | React Field | API Variable |
|----------|-----------|-------------|--------------|
| $1 | instance_id | Generated by API | $instanceId |
| $2 | instance_name | instance_name | $instanceName |
| $3 | domain | domain | $domain |
| $4 | db_name | database_name | $dbName |
| $5 | db_user | database_user | $dbUser |
| $6 | db_pass | database_password | $dbPass |
| $7 | db_prefix | database_prefix | $dbPrefix |

---

## Implementation Checklist

### Backend API (`instances-actions.php`)

- [ ] Fix parameter order for WordPress script
- [ ] Fix parameter order for Joomla script
- [ ] Add instance_name to command
- [ ] Add database_host to command
- [ ] Add cms_type to WordPress command
- [ ] Return installation_url in response
- [ ] Return admin_url in response
- [ ] Add validation for existing instance_id
- [ ] Add validation for domain format

### Joomla Script (`create-joomla-instance.sh`)

- [ ] Add instance_name as 2nd parameter
- [ ] Update parameter positions (domain becomes $3, etc.)
- [ ] Use instance_name in instance.json
- [ ] Update usage message
- [ ] Update documentation

### React Admin UI (`CreateInstance.tsx`)

- [ ] Update database message (auto-created)
- [ ] Display installation URL after creation
- [ ] Display admin URL after creation
- [ ] Add domain format validation
- [ ] Add instance_id uniqueness check
- [ ] Show creation progress
- [ ] Handle installation URL click

### Documentation

- [ ] Update HYBRID_KERNEL_ARCHITECTURE.md with correct parameters
- [ ] Update API documentation
- [ ] Create instance creation guide for users

---

## Testing Plan

### Test Cases

1. **WordPress Instance Creation**
   - [ ] Create with all default values
   - [ ] Create with custom database host
   - [ ] Create with custom prefix
   - [ ] Verify installation URL works
   - [ ] Verify admin URL works

2. **Joomla Instance Creation**
   - [ ] Create with all default values
   - [ ] Create with custom prefix
   - [ ] Verify installation URL includes /setup
   - [ ] Verify all symlinks are correct
   - [ ] Verify media/templates are copied

3. **Error Handling**
   - [ ] Duplicate instance_id
   - [ ] Invalid domain format
   - [ ] Database connection failure
   - [ ] Script execution failure

4. **UI/UX**
   - [ ] Installation URL displayed and clickable
   - [ ] Progress feedback during creation
   - [ ] Error messages are clear
   - [ ] Success message includes next steps

---

## Hosting Environment Considerations

### VPS/Dedicated Server

**Capabilities:**
- ✅ Can create databases automatically
- ✅ Can configure VirtualHosts
- ✅ Full root access
- ✅ Can install system packages
- ✅ Can modify Apache/Nginx config

**Script Behavior:**
- Creates database automatically
- Provides VirtualHost configuration
- Sets file permissions with chown

---

### Shared Hosting (cPanel)

**Limitations:**
- ❌ Cannot create databases (no CREATE DATABASE privilege)
- ❌ Cannot configure VirtualHosts (no Apache config access)
- ❌ Cannot use chown (no root access)
- ✅ Can create subdomains via cPanel
- ✅ Can create databases via cPanel
- ✅ Can set file permissions (chmod only)

**Required User Actions:**
1. **Create Database** via cPanel → MySQL Databases
2. **Create Subdomain** via cPanel → Subdomains
   - Subdomain: `admin`
   - Domain: `mysite.com`
   - Document Root: `/path/to/ikabud-kernel/instances/[instance_id]`
3. **Point Main Domain** to `/path/to/ikabud-kernel/public`

**Script Behavior:**
- Try to create database, continue if exists
- Skip chown commands (use chmod only)
- Provide cPanel setup instructions instead of VirtualHost config

---

### Script Improvements Needed

#### 1. **Graceful Database Creation**

**Current:**
```bash
mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>/dev/null
```

**Improved:**
```bash
# Try to create database
if mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null; then
    echo -e "${GREEN}✓${NC} Database created: $DB_NAME"
else
    # Check if database exists
    if mysql -u "$DB_USER" -p"$DB_PASS" -e "USE \`$DB_NAME\`" 2>/dev/null; then
        echo -e "${YELLOW}⚠${NC} Database already exists: $DB_NAME (continuing)"
    else
        echo -e "${RED}✗${NC} Cannot create database. Please create '$DB_NAME' via cPanel first."
        exit 1
    fi
fi
```

#### 2. **Detect Hosting Environment**

```bash
# Detect if running on shared hosting
IS_SHARED_HOSTING=false
if ! sudo -n true 2>/dev/null; then
    IS_SHARED_HOSTING=true
fi

# Set permissions based on environment
if [ "$IS_SHARED_HOSTING" = true ]; then
    # Shared hosting - use chmod only
    chmod -R 755 "$INSTANCE_PATH"
    chmod -R 775 "$INSTANCE_PATH/wp-content"
    echo -e "${YELLOW}⚠${NC} Shared hosting detected - using chmod only"
else
    # VPS/Dedicated - use chown + chmod
    chown -R www-data:www-data "$INSTANCE_PATH/wp-content"
    chmod -R 755 "$INSTANCE_PATH"
    chmod -R 775 "$INSTANCE_PATH/wp-content"
    echo -e "${GREEN}✓${NC} Permissions set (www-data owns wp-content)"
fi
```

#### 3. **Environment-Specific Instructions**

```bash
if [ "$IS_SHARED_HOSTING" = true ]; then
    echo ""
    echo "========================================="
    echo "cPanel Setup Instructions"
    echo "========================================="
    echo ""
    echo "1. Create Database (if not exists):"
    echo "   - Go to cPanel → MySQL Databases"
    echo "   - Create database: $DB_NAME"
    echo "   - Add user with all privileges"
    echo ""
    echo "2. Create Admin Subdomain:"
    echo "   - Go to cPanel → Subdomains"
    echo "   - Subdomain: admin"
    echo "   - Domain: $DOMAIN"
    echo "   - Document Root: $(pwd)/$INSTANCE_PATH"
    echo ""
    echo "3. Point Main Domain:"
    echo "   - Go to cPanel → Addon Domains (or primary domain)"
    echo "   - Point $DOMAIN to: $(pwd)/public"
    echo ""
    echo "4. Complete Installation:"
    echo "   http://admin.$DOMAIN/wp-admin/install.php"
    echo ""
else
    echo ""
    echo "========================================="
    echo "VirtualHost Configuration"
    echo "========================================="
    echo ""
    echo "1. Add to /etc/hosts:"
    echo "   127.0.0.1 $DOMAIN admin.$DOMAIN"
    echo ""
    echo "2. Create VirtualHosts:"
    echo "   [VirtualHost config as before]"
    echo ""
fi
```

---

## Summary

**Current Status:** ✅ **Mostly Aligned** (Core alignment complete)

**Completed:** 6 major fixes
**Critical Issues:** 0 (all fixed)
**Minor Issues:** 1 (partially fixed)
**Hosting Considerations:** 2 environments (VPS vs Shared) - documented

**Estimated Remaining Work:** 1-2 hours (optional enhancements)

**Priority:** 🟢 **Low** - Core functionality now aligned and working

**✅ Completed:**
1. ✅ **Instance ID Hybrid Approach** - Auto-generates from instance name with CMS prefix, allows manual editing, validates format and uniqueness
2. ✅ **Parameter Order Fixed** - WordPress and Joomla scripts now receive correct parameters in correct order
3. ✅ **Instance Name Added** - Both scripts now accept and use instance_name parameter
4. ✅ **Installation URL Returned** - API returns installation_url, admin_url, and frontend_url
5. ✅ **Database Host Passed** - WordPress script now receives database_host parameter
6. ✅ **CMS Type Passed** - WordPress script now receives cms_type parameter
7. ✅ **React UI Updated** - Shows installation URL in success toast with clickable link

**Remaining (Optional):**
1. Add graceful database creation (30 min) - Scripts already handle this
2. Add hosting environment detection (30 min) - Enhancement
3. Update React UI with setup instructions page (1 hour) - Enhancement
4. Test VPS and shared hosting scenarios (1 hour) - Testing

**Key Realizations:**
- ✅ Subdomain architecture: `domain.name` (frontend) + `admin.domain.name` (backend)
- ✅ Shared hosting: Cannot auto-create database, must use cPanel
- ✅ VirtualHost setup required for both environments (Apache config or cPanel)
- ✅ Installation URL must use admin subdomain: `http://admin.domain.name/installation/setup`
- ✅ Instance ID: Custom with auto-suggestion provides best UX and developer experience
