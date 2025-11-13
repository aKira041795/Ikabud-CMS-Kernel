# Virtual Host Setup - testcors.test

## Configuration Overview

### Frontend Domain (Kernel Routing)
- **Domain**: `testcors.test`, `www.testcors.test`
- **DocumentRoot**: `/var/www/html/ikabud-kernel/public`
- **Purpose**: Serves public-facing content through Ikabud Kernel routing

### Backend Domain (Direct WordPress Access)
- **Domain**: `backend.testcors.test`
- **Aliases**: `admin.testcors.test`, `dashboard.testcors.test`
- **DocumentRoot**: `/var/www/html/ikabud-kernel/instances/test-cors-001`
- **Purpose**: Direct access to WordPress admin and REST API

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Browser Requests                          │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
        ┌──────────────────┴──────────────────┐
        │                                     │
        ▼                                     ▼
┌───────────────────┐              ┌──────────────────────┐
│  testcors.test    │              │ backend.testcors.test│
│  www.testcors.test│              │ admin.testcors.test  │
└─────────┬─────────┘              │ dashboard.*          │
          │                        └──────────┬───────────┘
          ▼                                   │
┌─────────────────────┐                      │
│ Ikabud Kernel       │                      │
│ /public/index.php   │                      │
└─────────┬───────────┘                      │
          │                                   │
          │ Routes to instance                │
          ▼                                   ▼
┌──────────────────────────────────────────────────────────┐
│         WordPress Instance: test-cors-001                 │
│         /instances/test-cors-001/                         │
│                                                            │
│  ✓ CORS .htaccess                                         │
│  ✓ CORS mu-plugin (ikabud-cors.php)                      │
│  ✓ Cookie domain: .testcors.test                         │
└──────────────────────────────────────────────────────────┘
```

## CORS Flow

1. **Admin Dashboard Request**:
   ```
   http://admin.testcors.test → WordPress Instance (direct)
   ```

2. **REST API Request from Admin**:
   ```
   admin.testcors.test → http://backend.testcors.test/wp-json/wp/v2/posts
   ```

3. **CORS Headers Applied**:
   - Apache (.htaccess) handles OPTIONS preflight
   - WordPress plugin handles actual requests
   - Origin: `http://admin.testcors.test` is allowed
   - Credentials: `true` (cookies shared)

## Setup Instructions

### Automated Setup

Run the setup script:
```bash
sudo /tmp/setup-testcors-vhost.sh
```

### Manual Setup

1. **Copy virtual host configuration**:
   ```bash
   sudo cp /tmp/testcors.test.conf /etc/apache2/sites-available/
   ```

2. **Add to /etc/hosts**:
   ```bash
   sudo nano /etc/hosts
   # Add:
   127.0.0.1 testcors.test www.testcors.test backend.testcors.test admin.testcors.test dashboard.testcors.test
   ```

3. **Enable Apache modules**:
   ```bash
   sudo a2enmod rewrite proxy_fcgi setenvif headers
   ```

4. **Enable site**:
   ```bash
   sudo a2ensite testcors.test.conf
   ```

5. **Test and reload Apache**:
   ```bash
   sudo apache2ctl configtest
   sudo systemctl reload apache2
   ```

## Testing

### 1. Install WordPress
```bash
http://backend.testcors.test/wp-admin/install.php
```

### 2. Test Frontend Access
```bash
curl -I http://testcors.test
# Should return 200 or redirect to WordPress
```

### 3. Test Backend Access
```bash
curl -I http://backend.testcors.test
# Should return 200 and WordPress headers
```

### 4. Test CORS Preflight
```bash
curl -H "Origin: http://admin.testcors.test" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: X-WP-Nonce" \
     -X OPTIONS \
     -v \
     http://backend.testcors.test/wp-json/wp/v2/posts
```

Expected response headers:
```
Access-Control-Allow-Origin: http://admin.testcors.test
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Credentials: true
Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override
```

### 5. Test from Browser Console

Access `http://admin.testcors.test` and run:
```javascript
fetch('http://backend.testcors.test/wp-json/wp/v2/posts', {
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json'
  }
})
.then(r => r.json())
.then(console.log)
.catch(console.error);
```

Should return posts without CORS errors.

## Troubleshooting

### Issue: 403 Forbidden
**Cause**: Directory permissions  
**Fix**:
```bash
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel/instances/test-cors-001/wp-content
sudo chmod -R 755 /var/www/html/ikabud-kernel/instances/test-cors-001
```

### Issue: CORS errors still appearing
**Cause**: Apache not reloaded or .htaccess not being read  
**Fix**:
```bash
# Verify AllowOverride is set to All
sudo apache2ctl -S
sudo systemctl restart apache2
```

### Issue: PHP not executing
**Cause**: PHP-FPM not running or wrong socket path  
**Fix**:
```bash
# Check PHP-FPM status
sudo systemctl status php8.3-fpm

# Verify socket path
ls -la /run/php/php*-fpm.sock

# Update vhost if needed
sudo nano /etc/apache2/sites-available/testcors.test.conf
```

### Issue: WordPress redirecting to wrong domain
**Cause**: WP_HOME/WP_SITEURL mismatch  
**Fix**: Check `wp-config.php` cookie domain logic

## Log Files

Monitor for issues:
```bash
# Frontend logs
sudo tail -f /var/log/apache2/testcors.test-error.log
sudo tail -f /var/log/apache2/testcors.test-access.log

# Backend logs
sudo tail -f /var/log/apache2/backend.testcors.test-error.log
sudo tail -f /var/log/apache2/backend.testcors.test-access.log
```

## Summary

✅ **Frontend**: `testcors.test` → Ikabud Kernel routing  
✅ **Backend**: `backend.testcors.test` → Direct WordPress instance  
✅ **Admin Aliases**: `admin.*`, `dashboard.*` → Same WordPress instance  
✅ **CORS**: Fully configured for cross-subdomain requests  
✅ **Cookies**: Shared across `.testcors.test` domain
