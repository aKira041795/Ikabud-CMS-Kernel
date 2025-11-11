# Ikabud Kernel - Shared Hosting Installation Guide

Complete guide for installing Ikabud Kernel on shared hosting environments where Composer and shell access are not available.

---

## 📦 Shared Hosting Package

**File**: `ikabud-kernel-shared-hosting-v1.0.0.zip`  
**Size**: ~92 MB  
**Perfect For**: cPanel, Plesk, DirectAdmin, and other shared hosting

### ✅ What's Included (Everything You Need!)

- **✅ Vendor Dependencies** - All Composer packages pre-installed
- **✅ Shared CMS Cores** - WordPress, Joomla, Drupal ready to use
- **✅ Built Admin UI** - React admin panel (no build required)
- **✅ Database Schema** - Complete schema + admin user
- **✅ All Documentation** - Installation guides and references
- **✅ Web Installer** - Browser-based installation wizard

### ❌ No Need For:

- ❌ Composer (dependencies included)
- ❌ Node.js (admin UI pre-built)
- ❌ Shell/SSH access (web installer available)
- ❌ Command line tools (everything via browser)

---

## 🚀 Quick Installation (5 Steps)

### Step 1: Upload Archive

**Via cPanel File Manager:**
1. Login to cPanel
2. Go to File Manager
3. Navigate to `public_html` (or your web root)
4. Click "Upload"
5. Select `ikabud-kernel-shared-hosting-v1.0.0.zip`
6. Wait for upload to complete

**Via FTP:**
```
Upload ikabud-kernel-shared-hosting-v1.0.0.zip to:
/public_html/ or /www/ or /httpdocs/
```

### Step 2: Extract Archive

**Via cPanel File Manager:**
1. Right-click the ZIP file
2. Select "Extract"
3. Choose extraction location
4. Click "Extract Files"
5. Wait for extraction (may take 2-3 minutes)

**Via FTP (if supported):**
- Some FTP clients support ZIP extraction
- Otherwise, extract locally and upload all files

### Step 3: Create Database

**Via cPanel MySQL Databases:**
1. Go to "MySQL Databases"
2. Create new database: `yourusername_ikabud`
3. Create new user: `yourusername_ikabud_user`
4. Set a strong password
5. Add user to database with ALL PRIVILEGES
6. Note down: database name, username, password

**Via phpMyAdmin:**
1. Open phpMyAdmin
2. Click "New" to create database
3. Name it `ikabud_kernel`
4. Set collation to `utf8mb4_unicode_ci`

### Step 4: Import Database

**Via phpMyAdmin:**
1. Select your database
2. Click "Import" tab
3. Click "Choose File"
4. Select `database/basic-data.sql` from extracted files
5. Click "Go"
6. Wait for import to complete
7. Verify 17 tables were created

### Step 5: Run Web Installer

1. Open browser: `http://yourdomain.com/install.php`
2. Fill in the form:
   - Database host: `localhost` (usually)
   - Database name: Your database name
   - Database user: Your database username
   - Database password: Your database password
   - Admin username: Choose admin username
   - Admin password: Choose strong password
   - Admin email: Your email
3. Click "Install Ikabud Kernel"
4. Wait for installation to complete
5. **Delete `install.php` for security!**

---

## 📋 Detailed Installation Steps

### Prerequisites

**Hosting Requirements:**
- PHP 8.1 or higher
- MySQL 8.0+ or MariaDB 10.5+
- At least 256 MB PHP memory limit
- At least 100 MB disk space (after extraction)
- mod_rewrite enabled (for Apache)

**Check Your PHP Version:**
1. Create file `info.php` with content: `<?php phpinfo();`
2. Upload to your web root
3. Visit `http://yourdomain.com/info.php`
4. Check PHP version (must be 8.1+)
5. Delete `info.php` after checking

### File Structure After Extraction

```
public_html/
├── api/                    # REST API
├── bin/                    # Utility scripts
├── cms/                    # CMS adapters
├── database/               # Database files
│   ├── schema.sql
│   └── basic-data.sql     # Import this!
├── docs/                   # Documentation
├── kernel/                 # Core kernel
├── public/                 # Web root
│   ├── admin/             # Admin UI (built)
│   └── assets/
├── shared-cores/           # CMS cores
│   ├── wordpress/
│   ├── joomla/
│   └── drupal/
├── vendor/                 # Composer dependencies ✅
├── instances/              # Instance storage (empty)
├── storage/                # Cache and logs
├── themes/                 # Theme storage
├── .env.example            # Configuration template
├── install.php             # Web installer
└── README.md
```

### Configuration

**Edit `.env` file:**

```bash
# Via cPanel File Manager:
1. Find .env.example
2. Right-click → Copy
3. Rename copy to .env
4. Right-click .env → Edit
5. Update these values:

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=yourusername_ikabud
DB_USERNAME=yourusername_ikabud_user
DB_PASSWORD=your_password

# Application
APP_URL=http://yourdomain.com

# Admin (from install.php)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your_admin_password
ADMIN_EMAIL=your@email.com

# JWT (generate random string)
JWT_SECRET=your_random_secret_here
```

**Generate JWT Secret:**
- Visit: https://generate-secret.now.sh/32
- Or use: `base64_encode(random_bytes(32))`
- Or just use a long random string

### File Permissions

**Via cPanel File Manager:**

Set permissions to `755` for directories:
- `storage/`
- `storage/cache/`
- `storage/logs/`
- `instances/`
- `themes/`
- `logs/`

Set permissions to `644` for files:
- `.env`

**Permission Numbers:**
- `755` = rwxr-xr-x (directories)
- `644` = rw-r--r-- (files)
- `775` = rwxrwxr-x (writable directories)

---

## 🌐 Web Server Configuration

### Apache (.htaccess)

The package includes `.htaccess` files. Ensure `mod_rewrite` is enabled.

**Main `.htaccess` in public/:**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### Nginx

If using Nginx, add this to your server block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
}
```

---

## ✅ Verification

### Check Installation

1. **API Health Check:**
   ```
   http://yourdomain.com/api/health
   ```
   Should return: `{"status":"ok"}`

2. **Admin Panel:**
   ```
   http://yourdomain.com/admin
   ```
   Should show login page

3. **Database:**
   - Check phpMyAdmin
   - Verify 17 tables exist
   - Check `users` table has 1 row

### Test Instance Creation

1. Login to admin panel
2. Go to "Instances"
3. Click "Create Instance"
4. Fill in details:
   - Instance ID: `test-wp-001`
   - CMS Type: WordPress
   - Domain: `test.yourdomain.com`
5. Click "Create"
6. Verify instance appears in list

---

## 🔧 Troubleshooting

### "500 Internal Server Error"

**Check PHP error log:**
- cPanel: Home → Errors → Error Log
- Or check: `storage/logs/error.log`

**Common causes:**
- PHP version too old (need 8.1+)
- Missing PHP extensions
- Wrong file permissions
- `.htaccess` syntax error

**Fix:**
```bash
# Check PHP version
php -v

# Check required extensions
php -m | grep -E "pdo|mysql|json|mbstring|curl"

# Fix permissions
chmod 755 storage instances logs themes
chmod 644 .env
```

### "Database Connection Failed"

**Check:**
- Database name is correct
- Username is correct
- Password is correct
- User has privileges on database
- Database host is `localhost`

**Test connection:**
Create `test-db.php`:
```php
<?php
$host = 'localhost';
$db = 'yourusername_ikabud';
$user = 'yourusername_ikabud_user';
$pass = 'your_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    echo "✓ Connected successfully!";
} catch (PDOException $e) {
    echo "✗ Connection failed: " . $e->getMessage();
}
```

### "Vendor Directory Not Found"

**This means you used the wrong archive!**

Use: `ikabud-kernel-shared-hosting-v1.0.0.zip`  
NOT: `ikabud-kernel-complete-v1.0.0.zip`

The shared hosting version includes vendor/.

### "Admin UI Not Loading"

**Check:**
- `public/admin/` directory exists
- `public/admin/index.html` exists
- `public/admin/assets/` has JS/CSS files

**Fix:**
- Re-extract the archive
- Ensure all files were uploaded
- Check file permissions

### "Cannot Create Instances"

**Check:**
- `instances/` directory exists
- `instances/` is writable (755 or 775)
- `shared-cores/` directory exists
- Database connection is working

---

## 🔐 Security Checklist

After installation:

- [ ] Delete `install.php`
- [ ] Delete `info.php` (if created)
- [ ] Change default admin password
- [ ] Generate new JWT_SECRET
- [ ] Set strong database password
- [ ] Enable HTTPS/SSL
- [ ] Set proper file permissions
- [ ] Disable directory listing
- [ ] Keep `.env` file secure (644)
- [ ] Regular backups

---

## 📊 Performance Optimization

### Enable OPcache

Add to `.htaccess` or `php.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

### Enable Gzip Compression

Add to `.htaccess`:
```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
```

### Browser Caching

Add to `.htaccess`:
```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

---

## 🆘 Getting Help

### Documentation
- `README.md` - Project overview
- `INSTALL.md` - Detailed installation
- `REQUIREMENTS.md` - System requirements
- `docs/` - Full documentation

### Support Channels
- GitHub Issues: Report bugs
- Email: support@ikabud.com
- Documentation: https://docs.ikabud.com

### Common Resources
- cPanel Documentation
- PHP Documentation
- MySQL Documentation

---

## 📝 Post-Installation

### Next Steps

1. **Secure Your Installation**
   - Change admin password
   - Enable HTTPS
   - Configure firewall

2. **Create Your First Instance**
   - Login to admin panel
   - Create WordPress/Joomla/Drupal instance
   - Configure domain/subdomain

3. **Explore Features**
   - Read documentation
   - Test API endpoints
   - Try DSL templates

4. **Backup Strategy**
   - Daily database backups
   - Weekly file backups
   - Off-site storage

### Maintenance

**Regular Tasks:**
- Check error logs weekly
- Update CMS cores monthly
- Backup database daily
- Monitor disk space
- Review security logs

**Updates:**
- Check for Ikabud Kernel updates
- Update shared CMS cores
- Update PHP version when needed
- Keep SSL certificates current

---

## 🎉 Success!

You've successfully installed Ikabud Kernel on shared hosting!

**What You Can Do Now:**
- ✅ Create multiple CMS instances
- ✅ Manage instances via admin panel
- ✅ Use shared CMS cores efficiently
- ✅ Deploy websites quickly
- ✅ Scale with ease

**Happy hosting!** 🚀

---

*For the complete feature list and advanced usage, see the main documentation.*
