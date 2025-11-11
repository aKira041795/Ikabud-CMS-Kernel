# Ikabud Kernel - Quick Start Guide

Get up and running with Ikabud Kernel in 5 minutes!

---

## 🚀 Installation (2 minutes)

### Option 1: PHP Installer (Shared Hosting)

```bash
# Via command line
php install.php

# Or via web browser
# Navigate to: http://yourdomain.com/install.php
```

Perfect for shared hosting where shell scripts aren't allowed!

### Option 2: Shell Script Installer (VPS/Dedicated)

```bash
# Download and run the installer
curl -O https://raw.githubusercontent.com/yourusername/ikabud-kernel/main/install.sh
chmod +x install.sh
sudo ./install.sh
```

### Option 3: Manual Installation

```bash
# Clone and setup
git clone https://github.com/yourusername/ikabud-kernel.git
cd ikabud-kernel
composer install --no-dev --optimize-autoloader

# Configure
cp .env.example .env
nano .env  # Edit database credentials

# Create database
mysql -u root -p
CREATE DATABASE ikabud_kernel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# Import schema
mysql -u root -p ikabud_kernel < database/schema.sql

# Set permissions
sudo chown -R www-data:www-data .
sudo chmod -R 775 storage instances themes logs

# Install CLI
sudo ln -s $(pwd)/ikabud /usr/local/bin/ikabud
```

---

## ✅ Verify Installation (30 seconds)

```bash
# Check kernel status
ikabud status

# Test API
curl http://localhost/api/health

# Expected response:
# {"status":"ok","kernel":{"version":"1.0.0","booted":true}}
```

---

## 🎯 Create Your First Instance (1 minute)

### WordPress Instance

```bash
# Create instance
ikabud create wp-blog

# Start instance
ikabud start wp-blog

# Check status
ikabud status wp-blog

# Access your site
# Open browser: http://localhost/wp-blog
```

### Joomla Instance

```bash
ikabud create joomla-site
ikabud start joomla-site
```

### Drupal Instance

```bash
ikabud create drupal-site
ikabud start drupal-site
```

---

## 📋 Essential Commands

### Instance Management

```bash
# List all instances
ikabud list

# Start an instance
ikabud start <instance-id>

# Stop an instance
ikabud stop <instance-id>

# Restart an instance
ikabud restart <instance-id>

# Check instance status
ikabud status <instance-id>

# View instance logs
ikabud logs <instance-id>

# Health check
ikabud health <instance-id>

# Remove instance
ikabud remove <instance-id>
```

### Kernel Management

```bash
# Check kernel status
curl http://localhost/api/v1/kernel/status

# View running processes
curl http://localhost/api/v1/kernel/processes

# View syscall logs
curl http://localhost/api/v1/kernel/syscalls

# View boot log
curl http://localhost/api/v1/kernel/boot-log
```

---

## 🎨 Admin Dashboard

Access the admin panel:

```
http://localhost/admin
```

**Default Credentials:**
- Username: `admin`
- Password: (set during installation)

**⚠️ Change the default password immediately!**

---

## 🔧 Common Tasks

### Create Multiple Instances

```bash
# Create 3 WordPress sites
ikabud create wp-blog-1
ikabud create wp-blog-2
ikabud create wp-blog-3

# Start all
ikabud start wp-blog-1
ikabud start wp-blog-2
ikabud start wp-blog-3

# Check all running
ikabud list
```

### Monitor Resources

```bash
# Check instance health
ikabud health wp-blog-1

# View resource usage
curl http://localhost/api/v1/instances/wp-blog-1/resources

# View logs
ikabud logs wp-blog-1
```

### Stop All Instances

```bash
# Get list of running instances
ikabud list

# Stop each instance
ikabud stop <instance-id>
```

---

## 🐛 Troubleshooting

### Instance Won't Start

```bash
# Check status
ikabud status <instance-id>

# Check logs
ikabud logs <instance-id>

# Check systemd service
sudo systemctl status ikabud-<instance-id>

# Restart
ikabud restart <instance-id>
```

### Permission Errors

```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/html/ikabud-kernel
sudo chmod -R 775 storage instances themes logs
```

### Database Connection Failed

```bash
# Check .env file
nano .env

# Test database connection
mysql -u ikabud_user -p ikabud_kernel

# Verify credentials match .env
```

### 500 Internal Server Error

```bash
# Check error logs
sudo tail -f /var/log/apache2/error.log
# OR
sudo tail -f /var/log/nginx/error.log

# Check application logs
tail -f storage/logs/kernel.log

# Enable debug mode (temporarily)
nano .env
# Set: APP_DEBUG=true
```

---

## 📖 Next Steps

### Learn More

1. **[Full Documentation](docs/)** - Comprehensive guides
2. **[API Reference](docs/API.md)** - Complete API documentation
3. **[CLI Reference](docs/CLI.md)** - All CLI commands
4. **[DSL Guide](docs/DSL.md)** - Theme development with DSL

### Advanced Features

- **Multi-CMS Setup** - Run WordPress, Joomla, and Drupal together
- **Custom Themes** - Build themes with the DSL
- **Performance Tuning** - Optimize for production
- **Security Hardening** - Secure your installation

### Get Help

- **GitHub Issues**: [Report bugs](https://github.com/yourusername/ikabud-kernel/issues)
- **Discussions**: [Ask questions](https://github.com/yourusername/ikabud-kernel/discussions)
- **Discord**: [Join community](https://discord.gg/ikabud)
- **Email**: support@ikabud.com

---

## 💡 Tips & Tricks

### Performance

```bash
# Enable OPcache
sudo nano /etc/php/8.1/fpm/php.ini
# Set: opcache.enable=1

# Use Redis for caching
sudo apt-get install redis-server php-redis
# Update .env: CACHE_DRIVER=redis
```

### Security

```bash
# Generate secure JWT secret
openssl rand -base64 32

# Update .env with new secret
nano .env

# Enable firewall
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### Backup

```bash
# Backup database
mysqldump -u ikabud_user -p ikabud_kernel > backup.sql

# Backup files
tar czf ikabud-backup.tar.gz /var/www/html/ikabud-kernel

# Backup instances
tar czf instances-backup.tar.gz instances/
```

---

## 🎉 Success!

You're now ready to use Ikabud Kernel!

**What you can do:**
- ✅ Create and manage CMS instances
- ✅ Run multiple CMS platforms simultaneously
- ✅ Monitor resource usage
- ✅ Build custom themes with DSL
- ✅ Scale to production

**Need help?** Check the [full documentation](docs/) or [open an issue](https://github.com/yourusername/ikabud-kernel/issues).

---

**Happy building with Ikabud Kernel!** 🚀
