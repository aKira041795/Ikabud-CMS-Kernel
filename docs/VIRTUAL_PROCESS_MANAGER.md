# Virtual Process Manager

## Overview

The Virtual Process Manager provides admin control interface for CMS instances in **shared hosting environments** where root access is not available. It seamlessly upgrades to real ProcessManager when moved to VPS.

## Features

### ✅ Works in Shared Hosting
- No root access required
- No PHP-FPM pool creation needed
- No systemd services required
- Database-based process tracking

### ✅ Admin Control Interface
- Start/Stop/Restart instances
- Virtual PID tracking
- Resource usage monitoring
- Health checks

### ✅ Seamless VPS Upgrade
- Automatically detects root access
- Switches to real ProcessManager when available
- No code changes needed
- Same API interface

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│          VirtualProcessManager                          │
│  • Detects environment (shared/VPS)                     │
│  • Provides unified interface                           │
│  • Delegates to real ProcessManager if available        │
└──────────────────┬──────────────────────────────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌──────────────────┐  ┌────────────────────────┐
│  SHARED HOSTING  │  │         VPS            │
│  (Virtual Mode)  │  │    (Real Mode)         │
│                  │  │                        │
│ • Virtual PIDs   │  │ • Real PIDs            │
│ • DB tracking    │  │ • PHP-FPM pools        │
│ • Status changes │  │ • Systemd services     │
│ • Resource est.  │  │ • Real isolation       │
└──────────────────┘  └────────────────────────┘
```

## Database Schema

### virtual_processes Table

```sql
CREATE TABLE virtual_processes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instance_id VARCHAR(64) NOT NULL UNIQUE,
  virtual_pid VARCHAR(20) NOT NULL,
  status ENUM('running', 'stopped', 'error'),
  started_at TIMESTAMP NULL,
  stopped_at TIMESTAMP NULL,
  last_activity TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## API Endpoints

### Start Instance
```bash
POST /api/instances/start.php
{
  "instance_id": "wp-test-001"
}
```

**Response:**
```json
{
  "success": true,
  "instance_id": "wp-test-001",
  "virtual_pid": "v172345678901234",
  "status": "running",
  "mode": "virtual",
  "message": "Instance activated successfully"
}
```

### Stop Instance
```bash
POST /api/instances/stop.php
{
  "instance_id": "wp-test-001"
}
```

**Response:**
```json
{
  "success": true,
  "instance_id": "wp-test-001",
  "status": "stopped",
  "mode": "virtual",
  "message": "Instance deactivated successfully"
}
```

### Restart Instance
```bash
POST /api/instances/restart.php
{
  "instance_id": "wp-test-001"
}
```

## Usage

### PHP Code

```php
use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\VirtualProcessManager;

// Boot kernel
Kernel::boot();
$kernel = Kernel::getInstance();

// Create manager (auto-detects environment)
$manager = new VirtualProcessManager($kernel);

// Start instance
$result = $manager->startInstance('wp-test-001');

// Get status
$status = $manager->getInstanceStatus('wp-test-001');
echo "PID: " . $status['pid'] . "\n";
echo "Status: " . $status['status'] . "\n";
echo "Mode: " . $status['mode'] . "\n";

// Get resource usage
$resources = $manager->getResourceUsage('wp-test-001');
echo "Memory: " . $resources['memory'] . " bytes\n";
echo "Disk: " . $resources['disk_usage'] . " bytes\n";
echo "DB Size: " . $resources['database_size'] . " bytes\n";

// Monitor health
$health = $manager->monitorInstanceHealth('wp-test-001');
echo "Healthy: " . ($health['healthy'] ? 'Yes' : 'No') . "\n";

// Stop instance
$result = $manager->stopInstance('wp-test-001');
```

## Virtual vs Real Mode

| Feature | Virtual Mode (Shared) | Real Mode (VPS) |
|---------|----------------------|-----------------|
| **PID** | Virtual (v172...) | Real (1234) |
| **Process** | Shared Apache PHP | Dedicated PHP-FPM |
| **Isolation** | Database-level | OS-level |
| **Resources** | Estimated | Actual |
| **Start/Stop** | Status change | Service control |
| **Root Access** | Not required | Required |
| **Memory Limits** | Shared | Per-instance |
| **CPU Limits** | Shared | Per-instance |

## Resource Tracking

### Virtual Mode (Shared Hosting)
- **Memory**: Estimated from database size × 2
- **Disk**: Calculated from instance directory
- **Database**: Actual size from information_schema
- **Queries**: Estimated from table count × 10

### Real Mode (VPS)
- **Memory**: Actual from PHP-FPM pool
- **Disk**: Actual from instance directory
- **Database**: Actual size
- **Queries**: Actual from slow query log

## Migration Path

### Shared Hosting → VPS

1. **Current (Shared)**:
   ```php
   $manager = new VirtualProcessManager($kernel);
   // Uses virtual mode automatically
   ```

2. **After VPS Setup**:
   ```bash
   # Grant sudo access to web user
   sudo visudo
   # Add: www-data ALL=(ALL) NOPASSWD: /usr/sbin/php-fpm*, /bin/systemctl
   ```

3. **Automatic Upgrade**:
   ```php
   $manager = new VirtualProcessManager($kernel);
   // Automatically detects root access
   // Switches to real ProcessManager
   // No code changes needed!
   ```

## Admin UI Integration

The admin UI automatically shows:
- Virtual PID (e.g., "v1723456789")
- Mode indicator ("Virtual Mode" or "Process Mode")
- Start/Stop/Restart buttons
- Resource usage metrics
- Health status

## Benefits

### For Development (Shared Hosting)
- ✅ Full admin control interface
- ✅ Start/Stop/Restart functionality
- ✅ Resource monitoring
- ✅ No root access needed
- ✅ Works immediately

### For Production (VPS)
- ✅ Same interface
- ✅ Real process isolation
- ✅ Actual resource limits
- ✅ Better security
- ✅ Seamless upgrade

## Example Workflow

### 1. Development on Shared Hosting
```bash
# Create instance
POST /api/instances/create.php

# Instance starts in virtual mode
# Virtual PID: v1723456789
# Status: running
# Mode: virtual
```

### 2. Move to VPS
```bash
# Deploy to VPS
# Grant sudo access
# Restart Apache

# Same API calls work
# Now uses real ProcessManager
# Real PID: 12345
# Status: running
# Mode: real
```

### 3. No Code Changes
```javascript
// Admin UI code stays the same
const response = await fetch('/api/instances/start.php', {
  method: 'POST',
  body: JSON.stringify({ instance_id: 'wp-test-001' })
});

// Works in both environments!
```

## Conclusion

VirtualProcessManager provides the **best of both worlds**:
- Works in **shared hosting** (no root needed)
- Provides **admin control** interface
- Seamlessly **upgrades to VPS**
- **Same API** in both modes
- **Future-proof** architecture

Perfect for development on shared hosting with easy migration to VPS for production! 🚀
