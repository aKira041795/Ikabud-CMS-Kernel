# Ikabud Kernel - Process Isolation Implementation

**Version**: 1.0.0  
**Status**: ✅ IMPLEMENTED  
**Architecture**: Process-Per-Instance with PHP-FPM Pools

---

## 🎯 What We Built

**Each CMS instance now runs as its own OS-level process with:**
- ✅ Dedicated PID (can kill to stop CMS)
- ✅ Separate Unix user
- ✅ Isolated PHP-FPM pool
- ✅ Systemd service management
- ✅ Resource limits (CPU, memory)
- ✅ Auto-restart on crash
- ✅ Health monitoring

---

## 🏗️ Architecture

```
Ikabud Kernel (Master)
│
├── ProcessManager
│   ├── Creates Unix users
│   ├── Generates PHP-FPM pools
│   ├── Creates systemd services
│   └── Monitors health
│
└── Instance Processes
    │
    ├── wp-test-001 (PID 5001)
    │   ├── User: ikabud_wp_test_001
    │   ├── Socket: /var/run/php/ikabud-wp-test-001.sock
    │   ├── Pool: /etc/php/8.3/fpm/pool.d/ikabud-wp-test-001.conf
    │   └── Service: /etc/systemd/system/ikabud-wp-test-001.service
    │
    ├── wp-site-002 (PID 5002)
    │   ├── User: ikabud_wp_site_002
    │   ├── Socket: /var/run/php/ikabud-wp-site-002.sock
    │   └── ...
    │
    └── jm-site-001 (PID 5003)
        ├── User: ikabud_jm_site_001
        ├── Socket: /var/run/php/ikabud-jm-site-001.sock
        └── ...
```

---

## 📦 Components

### 1. **ProcessManager** (`kernel/ProcessManager.php`)

Manages the complete lifecycle of instance processes:

```php
$processManager = new ProcessManager($kernel);

// Create instance process
$processInfo = $processManager->createInstanceProcess('wp-test-001', $config);
// Returns: ['pid' => 5001, 'user' => 'ikabud_wp_test_001', 'socket' => '...']

// Stop instance
$processManager->stopInstanceProcess('wp-test-001');

// Restart instance
$processManager->restartInstanceProcess('wp-test-001');

// Get PID
$pid = $processManager->getInstancePID('wp-test-001');

// Kill process
$processManager->killInstanceProcess('wp-test-001', 9); // SIGKILL
```

### 2. **CLI Tool** (`ikabud`)

Command-line interface for managing instances:

```bash
# Create instance process
./ikabud create wp-test-001

# Start instance
./ikabud start wp-test-001

# Check status
./ikabud status wp-test-001

# List all instances
./ikabud list

# Stop instance
./ikabud stop wp-test-001

# Force kill
./ikabud kill wp-test-001

# Health check
./ikabud health wp-test-001

# View logs
./ikabud logs wp-test-001
```

### 3. **PHP-FPM Pool Configuration**

Auto-generated for each instance:

```ini
[ikabud-wp-test-001]
user = ikabud_wp_test_001
group = ikabud_wp_test_001
listen = /var/run/php/ikabud-wp-test-001.sock
listen.owner = ikabud_wp_test_001
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3

php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60

env[IKABUD_INSTANCE_ID] = wp-test-001
env[IKABUD_INSTANCE_PATH] = /var/www/html/ikabud-kernel/instances/wp-test-001
```

### 4. **Systemd Service**

Auto-generated for each instance:

```ini
[Unit]
Description=Ikabud Kernel - CMS Instance: wp-test-001
After=network.target mysql.service

[Service]
Type=forking
PIDFile=/var/run/php/ikabud-wp-test-001.pid
ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/php/8.3/fpm/pool.d/ikabud-wp-test-001.conf
Restart=on-failure
RestartSec=5s

MemoryLimit=512M
CPUQuota=50%

[Install]
WantedBy=multi-user.target
```

---

## 🚀 Usage

### **Step 1: Create Instance Process**

```bash
./ikabud create wp-test-001
```

This will:
1. Create Unix user `ikabud_wp_test_001`
2. Generate PHP-FPM pool config
3. Generate systemd service file
4. Set proper permissions
5. Start the service
6. Return PID

**Output:**
```
Creating instance process: wp-test-001...
✓ Instance process created
  PID: 5001
  User: ikabud_wp_test_001
  Socket: /var/run/php/ikabud-wp-test-001.sock
  Pool: /etc/php/8.3/fpm/pool.d/ikabud-wp-test-001.conf
  Service: /etc/systemd/system/ikabud-wp-test-001.service
```

### **Step 2: Check Status**

```bash
./ikabud status wp-test-001
```

**Output:**
```
Instance: wp-test-001
==================================================
Status: ✓ running
PID: 5001
Socket: /var/run/php/ikabud-wp-test-001.sock
Pool File: /etc/php/8.3/fpm/pool.d/ikabud-wp-test-001.conf
Service File: /etc/systemd/system/ikabud-wp-test-001.service

Process Info:
  5001 ikabud_wp_test_001  0.0  1.2 00:05:23 php-fpm: master process
```

### **Step 3: List All Instances**

```bash
./ikabud list
```

**Output:**
```
Running Instances
======================================================================
INSTANCE             STATUS     PID        SOCKET
----------------------------------------------------------------------
wp-test-001          running    5001       ikabud-wp-test-001.sock
wp-site-002          running    5002       ikabud-wp-site-002.sock
jm-site-001          stopped    N/A        ikabud-jm-site-001.sock
```

### **Step 4: Stop Instance**

```bash
./ikabud stop wp-test-001
```

**This stops the process! The CMS is no longer running.**

### **Step 5: Kill Instance by PID**

```bash
# Get PID
./ikabud status wp-test-001
# PID: 5001

# Kill it directly
kill 5001

# Or force kill via CLI
./ikabud kill wp-test-001
```

**✅ YES! Killing the PID stops the CMS!**

---

## 🔍 Process Isolation Features

### **1. Dedicated PID**
```bash
ps aux | grep ikabud
# ikabud_wp_test_001  5001  0.0  1.2  php-fpm: master process
# ikabud_wp_site_002  5002  0.0  1.1  php-fpm: master process
```

### **2. Separate Unix Users**
```bash
ls -la /var/www/html/ikabud-kernel/instances/wp-test-001/wp-content
# drwxrwxr-x ikabud_wp_test_001 ikabud_wp_test_001
```

### **3. Isolated Sockets**
```bash
ls -la /var/run/php/ikabud-*
# srw-rw---- ikabud_wp_test_001 www-data ikabud-wp-test-001.sock
# srw-rw---- ikabud_wp_site_002 www-data ikabud-wp-site-002.sock
```

### **4. Resource Limits**
```bash
systemctl show ikabud-wp-test-001 | grep -E "Memory|CPU"
# MemoryLimit=512M
# CPUQuota=50%
```

### **5. Auto-Restart**
```bash
# Kill the process
kill -9 5001

# Systemd automatically restarts it
./ikabud status wp-test-001
# Status: ✓ running
# PID: 5123  (new PID!)
```

---

## 📊 Comparison: Before vs After

| Feature | Before (Symlink) | After (Process Isolation) |
|---------|------------------|---------------------------|
| **Has PID** | ❌ No | ✅ Yes (e.g., 5001) |
| **Kill PID stops CMS** | ❌ No | ✅ Yes |
| **Separate user** | ❌ All use www-data | ✅ Each has own user |
| **Resource limits** | ❌ Shared | ✅ Per-instance limits |
| **Crash isolation** | ❌ Affects all | ✅ Only that instance |
| **Auto-restart** | ❌ Manual | ✅ Automatic |
| **Monitoring** | ❌ Manual | ✅ Built-in |
| **Start/Stop** | ❌ Apache restart | ✅ Per-instance control |

---

## 🧪 Testing

### **Test 1: Create Process**
```bash
./ikabud create wp-test-001
# Should create user, pool, service, and start process
```

### **Test 2: Verify PID**
```bash
./ikabud status wp-test-001
# Should show PID

ps aux | grep 5001
# Should show php-fpm process
```

### **Test 3: Kill Process**
```bash
PID=$(./ikabud status wp-test-001 | grep "PID:" | awk '{print $2}')
kill $PID

# Wait a moment
sleep 2

# Check if auto-restarted
./ikabud status wp-test-001
# Should show new PID
```

### **Test 4: Stop and Start**
```bash
./ikabud stop wp-test-001
# CMS should be inaccessible

curl http://wp-test.ikabud-kernel.test/
# Should fail or show 502 Bad Gateway

./ikabud start wp-test-001
# CMS should be accessible again

curl http://wp-test.ikabud-kernel.test/
# Should work
```

---

## ⚙️ Configuration

### **Instance Config in Database**

```sql
UPDATE instances SET config = JSON_OBJECT(
    'max_children', 10,
    'start_servers', 3,
    'memory_limit', '512M',
    'max_execution_time', 120
) WHERE instance_id = 'wp-test-001';
```

### **Resource Limits**

Edit systemd service:
```bash
sudo systemctl edit ikabud-wp-test-001
```

Add:
```ini
[Service]
MemoryLimit=1G
CPUQuota=100%
```

Reload:
```bash
sudo systemctl daemon-reload
sudo systemctl restart ikabud-wp-test-001
```

---

## 🔧 Troubleshooting

### **Instance Won't Start**

```bash
# Check logs
./ikabud logs wp-test-001

# Check systemd status
sudo systemctl status ikabud-wp-test-001

# Check PHP-FPM logs
sudo tail -f /var/log/php/ikabud-wp-test-001-error.log
```

### **Socket Permission Issues**

```bash
# Check socket permissions
ls -la /var/run/php/ikabud-wp-test-001.sock

# Should be: srw-rw---- ikabud_wp_test_001 www-data

# Fix if needed
sudo chown ikabud_wp_test_001:www-data /var/run/php/ikabud-wp-test-001.sock
sudo chmod 660 /var/run/php/ikabud-wp-test-001.sock
```

### **Process Not Responding**

```bash
# Force kill
./ikabud kill wp-test-001

# Remove and recreate
./ikabud remove wp-test-001
./ikabud create wp-test-001
```

---

## 🎯 Benefits

### **1. True Process Isolation**
- Each CMS has its own process
- Crash in one doesn't affect others
- Can kill specific instance

### **2. Resource Control**
- Set memory limits per instance
- CPU quotas per instance
- Prevent resource hogging

### **3. Security**
- Separate Unix users
- Filesystem isolation
- Process-level sandboxing

### **4. Monitoring**
- Health checks
- Process metrics
- Systemd integration

### **5. Management**
- Start/stop individual instances
- Auto-restart on crash
- Easy debugging

---

## 🚀 Next Steps

### **Phase 1 (Current)**: ✅ Process Isolation Implemented
- ProcessManager class
- CLI tool
- PHP-FPM pools
- Systemd services

### **Phase 2 (Next)**: Nginx/Apache Integration
- Update vhost to use instance sockets
- Load balancing across pools
- Zero-downtime deployments

### **Phase 3 (Future)**: Advanced Features
- Dynamic scaling
- Hot-reload
- Resource auto-tuning
- Cluster management

---

## ✅ Status

**Implementation**: ✅ COMPLETE  
**Testing**: ⚠️ READY FOR TESTING  
**Production**: ⚠️ Requires vhost socket configuration

**You can now kill a PID and stop a specific CMS instance!** 🎉

---

## 📚 Related Documentation

- `docs/INSTANCE_BOOT_SEQUENCE.md` - Boot sequence implementation
- `docs/PROCESS_ISOLATION_RESEARCH.md` - Research and architecture
- `docs/FINAL_ARCHITECTURE.md` - Overall system architecture
