# Ikabud Kernel OS — Installation Guide

## Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.1+ (8.2+ recommended) |
| MySQL | 8.0+ |
| Web Server | Apache 2.4+ with `mod_rewrite` |
| Composer | 2.x (for dependency management) |
| Node.js | 18+ (only if rebuilding the page builder UI) |

### Required PHP Extensions

- `pdo_mysql`
- `mbstring`
- `json`
- `openssl`
- `session`

---

## Quick Deploy (Bluehost / cPanel)

1. **Create MySQL database** — cPanel → MySQL Databases → Create database + user → Grant ALL privileges
2. **Upload archive** — Upload `application-kernel-os.zip` to `public_html/` → Extract
3. **Run installer** — Visit `https://yourdomain.com/lock.php` → Enter DB credentials and admin account
4. **Secure** — Delete `public/lock.php` after verifying the application works

---

## Manual Installation

### 1. Clone or Extract

Place the project files in your web server's document root (e.g., `/var/www/html/ikabud/`).

### 2. Install Dependencies

```bash
cd /path/to/ikabud
composer install --no-dev --optimize-autoloader
```

### 3. Configure Environment

Copy the example environment file and edit it:

```bash
cp .env.example .env
```

Required `.env` variables:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password

JWT_SECRET=your-random-64-char-secret
JWT_EXPIRATION=14400

# Optional: Multi-tenancy
MULTI_TENANT_ENABLED=false
CONTROL_DB_HOST=localhost
CONTROL_DB_DATABASE=control_db
CONTROL_DB_USERNAME=control_user
CONTROL_DB_PASSWORD=control_pass
CONTROL_DB_ENC_KEY=your-encryption-key
```

### 4. Set Permissions

```bash
chmod -R 775 storage/
chmod -R 775 public/
```

### 5. Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/ikabud/public

    <Directory /var/www/html/ikabud/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/ikabud-error.log
    CustomLog ${APACHE_LOG_DIR}/ikabud-access.log combined
</VirtualHost>
```

Enable rewrite and restart Apache:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Run Installer

Navigate to `https://yourdomain.com/lock.php` in your browser. The installer will:

- Verify PHP requirements
- Test database connectivity
- Run kernel migrations
- Run module migrations
- Create the initial admin user
- Generate `.installed` marker file

### 7. Post-Install Security

- Delete `public/lock.php`
- Verify `.env` is not web-accessible (the `.htaccess` blocks it by default)
- Confirm `storage/logs/` is not web-accessible

---

## Multi-Tenant Setup

To enable multi-tenancy:

1. Set `MULTI_TENANT_ENABLED=true` in `.env`
2. Configure the control-plane database credentials (`CONTROL_DB_*`)
3. Run control-plane migrations:

```bash
# Apply control-plane schema
mysql -u control_user -p control_db < control-migrations/001_control_plane_tenants.sql
mysql -u control_user -p control_db < control-migrations/002_control_plane_encrypt_db_pass.sql
```

4. Create tenant entries in the `tenants` table
5. Create per-tenant databases and register their encrypted credentials in `kernel_tenant_db_connections`

See [tenancy-roadmap.md](tenancy-roadmap.md) for the full multi-tenancy design.

---

## Rebuilding the Page Builder UI

The CMS page builder is a React/Vite application. To rebuild after changes:

```bash
cd modules/cms/builder-ui
npm install
npm run build
```

For development with hot reload:

```bash
npm run dev
```

Type checking:

```bash
npm run type-check
```

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| 500 error after install | Check `storage/logs/error.log` for PHP errors |
| "Class not found" errors | Run `composer install` or verify autoloader |
| Blank page | Ensure `APP_DEBUG=true` temporarily, check error.log |
| Login redirect loop | Verify `JWT_SECRET` is set and cookie domain is correct |
| Module not loading | Check `storage/modules.json` for enabled state |
| Template errors | Clear `storage/cache/` directory |

---

## Logs

- **Application log:** `storage/logs/app.log`
- **PHP error log:** `storage/logs/error.log`

All log entries include a request ID (`X-Request-Id`) for tracing.
