# Shared Hosting Setup Guide

## Overview

Ikabud Kernel is designed to work perfectly in **shared hosting environments** where you don't have:
- Root/sudo access
- Apache VirtualHost configuration access
- PHP-FPM pool creation rights
- Systemd service management

## How It Works

### ✅ Shared Hosting Compatible Features

1. **`.htaccess` Files** - Instead of VirtualHost configs
2. **Virtual Process Manager** - Instead of real OS processes
3. **Database-Based Tracking** - Instead of systemd services
4. **File-Based Configuration** - Instead of system configs

---

## Automatic `.htaccess` Generation

### **What Happens Automatically**:

When an instance boots, the `InstanceBootstrapper` automatically creates a `.htaccess` file with:

1. **MIME Type Configuration**
   ```apache
   <IfModule mod_mime.c>
       AddType text/css .css
       AddType application/javascript .js
       AddType font/woff2 .woff2
       # ... etc
   </IfModule>
   ```

2. **Security Headers**
   ```apache
   <IfModule mod_headers.c>
       Header set X-Content-Type-Options "nosniff"
       Header set X-Frame-Options "SAMEORIGIN"
       Header set X-XSS-Protection "1; mode=block"
   </IfModule>
   ```

3. **CMS-Specific Rewrite Rules**
   - WordPress rules
   - Joomla rules
   - Drupal rules

### **File Location**:
```
/instances/{instance-id}/.htaccess
```

### **Template Location**:
```
/templates/instance.htaccess
```

---

## Shared Hosting Deployment

### **Step 1: Upload Files**

Upload the entire Ikabud Kernel directory to your shared hosting:

```
/public_html/ikabud-kernel/
├── kernel/
├── cms/
├── dsl/
├── api/
├── public/
├── instances/
├── templates/
└── vendor/
```

### **Step 2: Configure Database**

1. Create MySQL database via cPanel/Plesk
2. Update `.env` file:
   ```env
   DB_HOST=localhost
   DB_DATABASE=your_database_name
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

3. Import schema:
   ```bash
   mysql -u username -p database_name < database/schema.sql
   mysql -u username -p database_name < database/admin_schema.sql
   mysql -u username -p database_name < database/virtual_processes.sql
   ```

### **Step 3: Set Document Root**

In cPanel or your hosting control panel, set the document root to:
```
/public_html/ikabud-kernel/public
```

Or if you can't change document root, create a symlink:
```bash
ln -s /home/username/ikabud-kernel/public /home/username/public_html
```

### **Step 4: Configure Subdomains**

For each instance, create a subdomain in cPanel:

1. **Subdomain**: `wp-test.yourdomain.com`
2. **Document Root**: `/home/username/ikabud-kernel/public`
3. **No need for custom .htaccess** - Kernel handles it!

---

## How MIME Types Are Handled

### **In Shared Hosting** (Automatic):

```
Request: wp-test.yourdomain.com/style.css
         ↓
Apache checks: /instances/wp-test-001/.htaccess
         ↓
.htaccess sets: Content-Type: text/css
         ↓
File served with correct MIME type ✅
```

### **In VPS** (Optional):

You can use VirtualHost configs OR `.htaccess` - both work!

---

## Differences: Shared vs VPS

| Feature | Shared Hosting | VPS |
|---------|----------------|-----|
| **MIME Types** | `.htaccess` ✅ | VirtualHost or `.htaccess` |
| **Process Manager** | Virtual ✅ | Real (optional) |
| **Isolation** | Database-level ✅ | OS-level |
| **Start/Stop** | Status change ✅ | Service control |
| **Root Access** | Not needed ✅ | Optional |
| **Cost** | $5-10/month | $5-20/month |

---

## Troubleshooting

### **CSS Not Loading?**

Check if `.htaccess` exists:
```bash
ls -la /instances/wp-test-001/.htaccess
```

If missing, trigger instance boot:
```bash
curl http://wp-test.yourdomain.com/
```

The Kernel will auto-create it!

### **Fonts Returning 404?**

1. Check file exists:
   ```bash
   ls -la /instances/wp-test-001/wp-content/themes/*/assets/fonts/
   ```

2. Check `.htaccess` has font MIME types:
   ```bash
   grep "woff2" /instances/wp-test-001/.htaccess
   ```

3. If missing, delete `.htaccess` and let Kernel recreate it:
   ```bash
   rm /instances/wp-test-001/.htaccess
   curl http://wp-test.yourdomain.com/
   ```

### **Instance Won't Start?**

Check virtual process status:
```sql
SELECT * FROM virtual_processes WHERE instance_id = 'wp-test-001';
```

Start via admin panel or API:
```bash
curl -X POST http://yourdomain.com/api/instances/start.php \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"instance_id":"wp-test-001"}'
```

---

## File Permissions

### **Required Permissions**:

```bash
# Directories
chmod 755 instances/
chmod 755 instances/wp-test-001/
chmod 755 instances/wp-test-001/wp-content/

# Files
chmod 644 instances/wp-test-001/.htaccess
chmod 644 instances/wp-test-001/wp-config.php

# Writable directories
chmod 775 instances/wp-test-001/wp-content/uploads/
chmod 775 storage/cache/
```

### **Ownership**:

Files should be owned by your hosting account user:
```bash
chown -R username:username ikabud-kernel/
```

---

## Performance Tips

### **1. Enable OPcache** (if available)

Add to `.htaccess` or `php.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
```

### **2. Use Object Caching**

For WordPress instances:
```bash
# Install Redis or Memcached plugin
# Configure in wp-config.php
```

### **3. Optimize Database**

```sql
OPTIMIZE TABLE instances;
OPTIMIZE TABLE virtual_processes;
OPTIMIZE TABLE kernel_boot_log;
```

---

## Security in Shared Hosting

### **Automatic Security Headers**:

Every instance `.htaccess` includes:
```apache
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"
```

### **Additional Recommendations**:

1. **Change default admin password**:
   ```sql
   UPDATE admin_users SET password = PASSWORD('new_secure_password') WHERE username = 'admin';
   ```

2. **Restrict API access**:
   Add to `/public/.htaccess`:
   ```apache
   <FilesMatch "^(api)">
       Require ip YOUR_IP_ADDRESS
   </FilesMatch>
   ```

3. **Enable HTTPS**:
   Most shared hosts offer free Let's Encrypt SSL - enable it!

---

## Conclusion

Ikabud Kernel is **fully compatible with shared hosting** and requires:
- ✅ No VirtualHost access
- ✅ No root privileges
- ✅ No special server configuration
- ✅ Just upload, configure, and run!

The `.htaccess` files are automatically created and managed by the Kernel, ensuring proper MIME types, security headers, and CMS routing work perfectly in any shared hosting environment! 🚀
