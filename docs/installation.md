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
3. **Run installer** — Visit `https://yourdomain.com/lock.php` → Enter app DB credentials and admin account. If multi-tenant mode is enabled, also enter the control-plane DB settings.
4. **Secure** — Delete `public/lock.php` after verifying the application works

### Bluehost Upgrade Kit For Existing Installs

If Bluehost is already running an older version and you want a package that includes both the updated files and importable SQL bundles, generate the upgrade kit locally:

```bash
php create-bluehost-upgrade-package.php
```

This creates a zip containing:

- a fresh deployment archive for the application files
- `db/app-upgrade.sql` for the primary application database
- `db/control-upgrade.sql` for the control database when multi-tenant mode is enabled
- `db/tenant-upgrade.sql` for tenant databases when tenants use separate databases
- `README-UPGRADE.txt` with the recommended import/deploy order

Use this upgrade kit for additive Bluehost upgrades where you want the SQL import to create missing tables, add newer columns, and preserve existing rows before replacing the live code.

This is now the recommended production upgrade path for older Bluehost installs. The guarded SQL bundle/import flow has been validated against a live Bluehost site.

Recommended live-upgrade order:

1. Back up the live files and every database first.
2. Generate a fresh upgrade kit from the exact commit you plan to deploy.
3. Import `db/app-upgrade.sql` into the primary application database.
4. If multi-tenant mode is enabled, import `db/control-upgrade.sql` into the control database.
5. If tenants use separate databases, import `db/tenant-upgrade.sql` into each tenant database.
6. Upload and extract the bundled application zip over the live codebase.
7. Preserve the live `.env`, `storage/`, and `public/uploads/` directories.
8. Test the kernel host, tenant hosts, and review `storage/logs/app.log` plus `storage/logs/error.log`.

Important notes:

- Always use a freshly generated guarded upgrade kit, not an older extracted SQL file.
- The SQL bundles are intended to be repeatable for partially upgraded databases and will skip already-existing guarded columns.
- Some reconciliation migrations still remove obsolete legacy tables only after canonical replacements or backfills exist.
- Do not use `public/lock.php` as the upgrade path for an already-installed production site.

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
APP_MULTI_TENANT_ENABLED=0
APP_TENANT_STRATEGY=control_host
CONTROL_DB_HOST=localhost
CONTROL_DB_PORT=3306
CONTROL_DB_DATABASE=control_db
CONTROL_DB_USERNAME=control_user
CONTROL_DB_PASSWORD=control_pass
CONTROL_DB_ENC_KEY=your-encryption-key
```

Notes:

- `APP_COOKIE_NAME` is derived automatically from `APP_URL` when it is not set.
- AI and SMS provider credentials are managed by their modules and are not required in the base `.env`.

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

- Connect to the application database and apply `database/migrations/001_full_schema.sql`
- Bootstrap the kernel and apply pending kernel + module migrations
- Apply control-plane migrations to `CONTROL_DB_*` when multi-tenant mode is enabled
- Create the initial admin user
- Write or refresh `.env` and generate the `.installed` marker file

If you are reinstalling, the installer backs up the previous `.env` to `storage/backups/env-YYYYmmdd-HHMMSS.bak` before replacing it.

### 7. Post-Install Security

- Delete `public/lock.php`
- Verify `.env` is not web-accessible (the `.htaccess` blocks it by default)
- Confirm `storage/logs/` is not web-accessible

---

## Multi-Tenant Setup

To enable multi-tenancy:

1. Set `APP_MULTI_TENANT_ENABLED=1` in `.env`
2. Configure the control-plane database credentials (`CONTROL_DB_*`) and `CONTROL_DB_ENC_KEY`
3. If you are using the web installer, enter the same control DB values in the installer form so `_control` migrations run against the correct database.
4. If you are provisioning manually without the web installer, run control-plane migrations:

```bash
# Apply control-plane schema
mysql -u control_user -p control_db < control-migrations/001_control_plane_tenants.sql
mysql -u control_user -p control_db < control-migrations/002_control_plane_encrypt_db_pass.sql
```

5. Create tenant entries in the `tenants` table
6. Create per-tenant databases and register their encrypted credentials in `kernel_tenant_db_connections`

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
| Generic error page on first request | Ensure the generated `.env` is readable by the web server process |
| "Class not found" errors | Run `composer install` or verify autoloader |
| Blank page | Ensure `APP_DEBUG=true` temporarily, check error.log |
| Login redirect loop | Verify `JWT_SECRET` is set and cookie domain is correct |
| Module not loading | Check `storage/modules.json` for enabled state |
| Template errors | Clear `storage/cache/` directory |
| `Tenant DB resolution failed: Decryption failed.` | Verify the live `CONTROL_DB_ENC_KEY` was preserved across the upgrade, then audit tenant DB ciphertext with `php scripts/audit-tenant-db-crypto.php --all` |

HTTP smoke test for the installer without `curl`:

```bash
php scripts/test-install-http.php
```

Tenant DB encryption audit and repair:

```bash
php scripts/audit-tenant-db-crypto.php --all
php scripts/audit-tenant-db-crypto.php --tenant=203 --legacy-key='OLD_KEY' --apply
php scripts/audit-tenant-db-crypto.php --tenant=203 --set-password='tenant-db-password' --apply
```

Use the first command to confirm which tenant rows decrypt with the current `CONTROL_DB_ENC_KEY`. If a tenant row only decrypts with an older key, rerun with `--legacy-key=... --apply` to re-encrypt it under the current key. If the old key is unavailable, reset that tenant's DB password with `--set-password=... --apply` after confirming the real database credential.

This checks the live `lock.php` endpoint over HTTP and verifies that installed systems keep the web installer locked.

---

## Logs

- **Application log:** `storage/logs/app.log`
- **PHP error log:** `storage/logs/error.log`

All log entries include a request ID (`X-Request-Id`) for tracing.
