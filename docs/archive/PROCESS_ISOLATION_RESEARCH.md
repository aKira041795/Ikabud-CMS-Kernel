# Process Isolation for CMS Instances - Research & Feasibility

**Date**: November 8, 2025  
**Question**: Can we run CMS instances as pure kernel processes with true OS-level isolation?  
**Answer**: ✅ YES - It's possible and has been done, but with important considerations

---

## 🔍 Research Findings

### 1. **Process Isolation is a Proven Pattern**

#### Real-World Examples:
- **Google Chrome**: "Multi-Process Architecture" - Each tab runs as a separate OS process
- **Internet Explorer 8**: "Loosely Coupled IE (LCIE)" - Process-per-tab isolation
- **Microkernel OS**: Minix, L4, Mach (macOS/iOS kernel) - Services run as isolated processes

#### Key Principle:
> "Process isolation ensures that if one service has an issue, the issue does not extend or affect other services. This isolation makes the system stable and secure."

---

## 🏗️ PHP-Specific Implementation: PHP-FPM Pools

### What PHP-FPM Pools Provide:

1. **Process Isolation**: Each pool runs as separate PHP-FPM master/worker processes
2. **User Isolation**: Each pool can run as different Unix user
3. **Resource Isolation**: Separate memory limits, execution time, etc.
4. **Security**: Filesystem permissions prevent cross-tenant access

### Architecture:

```
PHP-FPM Master Process
├── Pool: site1 (user: site1, socket: /var/run/php/site1-fpm.sock)
│   ├── Worker Process 1
│   ├── Worker Process 2
│   └── Worker Process 3
│
├── Pool: site2 (user: site2, socket: /var/run/php/site2-fpm.sock)
│   ├── Worker Process 1
│   ├── Worker Process 2
│   └── Worker Process 3
│
└── Pool: site3 (user: site3, socket: /var/run/php/site3-fpm.sock)
    ├── Worker Process 1
    ├── Worker Process 2
    └── Worker Process 3
```

### Benefits:
- ✅ True OS-level process isolation
- ✅ Each instance has own memory space
- ✅ Crash in one pool doesn't affect others
- ✅ Different PHP settings per pool
- ✅ User-level filesystem isolation

### Limitations:
⚠️ **Important**: "Pools are not a security mechanism, because they do not provide full separation" - PHP Manual

---

## 🚀 Systemd Service Per Instance

### What Systemd Provides:

1. **Process Management**: Start/stop/restart instances as services
2. **Auto-restart**: Automatic recovery on crash
3. **Resource Limits**: CPU, memory, I/O throttling
4. **Logging**: Centralized logging via journalctl
5. **Dependencies**: Service ordering and dependencies

### Example Architecture:

```
systemd
├── ikabud-wp-site1.service
│   └── PHP-FPM Pool: site1
│
├── ikabud-wp-site2.service
│   └── PHP-FPM Pool: site2
│
└── ikabud-wp-site3.service
    └── PHP-FPM Pool: site3
```

### Service File Example:

```ini
[Unit]
Description=Ikabud Kernel - WordPress Instance: site1
After=network.target mysql.service

[Service]
Type=forking
User=site1
Group=site1
WorkingDirectory=/var/www/html/ikabud-kernel/instances/site1
ExecStart=/usr/sbin/php-fpm --fpm-config /etc/php/8.1/fpm/pool.d/site1.conf
ExecReload=/bin/kill -USR2 $MAINPID
Restart=on-failure
RestartSec=5s

# Resource Limits
MemoryLimit=512M
CPUQuota=50%

[Install]
WantedBy=multi-user.target
```

---

## 📊 Comparison: Current vs Process-Based Architecture

| Aspect | Current (Symlink) | Process-Based (PHP-FPM Pools) |
|--------|-------------------|-------------------------------|
| **Isolation** | Filesystem only | OS-level process + filesystem |
| **Crash Protection** | ❌ One crash affects all | ✅ Isolated crashes |
| **Resource Control** | ❌ Shared resources | ✅ Per-instance limits |
| **Memory** | ❌ Shared PHP memory | ✅ Isolated memory space |
| **User Isolation** | ⚠️ Same user (www-data) | ✅ Different Unix users |
| **Monitoring** | Manual | ✅ systemd/journalctl |
| **Auto-recovery** | ❌ Manual restart | ✅ Automatic restart |
| **Complexity** | Low | Medium-High |
| **Performance** | High (shared) | Medium (per-process overhead) |
| **Scalability** | High | Medium (process limits) |

---

## 🎯 Proposed Hybrid Architecture: "Ikabud Process Kernel"

### Concept: Best of Both Worlds

```
Ikabud Kernel (Master Process)
├── Process Manager (systemd integration)
├── Instance Registry (database)
└── CMS Adapters
    │
    ├── WordPress Instances
    │   ├── wp-site1 → PHP-FPM Pool (user: wp_site1)
    │   ├── wp-site2 → PHP-FPM Pool (user: wp_site2)
    │   └── wp-site3 → PHP-FPM Pool (user: wp_site3)
    │
    ├── Joomla Instances
    │   ├── jm-site1 → PHP-FPM Pool (user: jm_site1)
    │   └── jm-site2 → PHP-FPM Pool (user: jm_site2)
    │
    └── Drupal Instances
        └── dr-site1 → PHP-FPM Pool (user: dr_site1)
```

### How It Works:

1. **Kernel Boot**:
   - Ikabud Kernel starts as master process
   - Reads instance registry from database
   - Generates PHP-FPM pool configs for each instance
   - Starts systemd services for each instance

2. **Instance as Process**:
   - Each CMS instance runs in its own PHP-FPM pool
   - Separate Unix user per instance
   - Isolated memory space and resources
   - Own socket for communication

3. **Kernel Supervision**:
   - Monitors instance health via systemd
   - Handles inter-instance communication (IPC)
   - Manages shared resources (database connections)
   - Provides syscalls for instance operations

4. **Request Flow**:
   ```
   User Request
   → Nginx
   → Kernel Router (determines instance)
   → PHP-FPM Pool Socket (instance-specific)
   → CMS Instance Process
   → Response
   ```

---

## 💡 Implementation Strategy

### Phase 1: PHP-FPM Pool Generation
```php
// kernel/ProcessManager.php
class ProcessManager {
    public function createInstancePool(string $instanceId, array $config) {
        $user = "ikabud_{$instanceId}";
        $socket = "/var/run/php/ikabud-{$instanceId}.sock";
        
        // Create Unix user
        exec("sudo useradd {$user}");
        
        // Generate PHP-FPM pool config
        $poolConfig = $this->generatePoolConfig($instanceId, $user, $socket, $config);
        file_put_contents("/etc/php/8.1/fpm/pool.d/ikabud-{$instanceId}.conf", $poolConfig);
        
        // Reload PHP-FPM
        exec("sudo systemctl reload php8.1-fpm");
    }
}
```

### Phase 2: Systemd Service Generation
```php
public function createInstanceService(string $instanceId) {
    $serviceFile = $this->generateSystemdService($instanceId);
    file_put_contents("/etc/systemd/system/ikabud-{$instanceId}.service", $serviceFile);
    
    exec("sudo systemctl daemon-reload");
    exec("sudo systemctl enable ikabud-{$instanceId}");
    exec("sudo systemctl start ikabud-{$instanceId}");
}
```

### Phase 3: Kernel Router
```php
// public/index.php - Kernel Router
$domain = $_SERVER['HTTP_HOST'];
$instance = $kernel->getInstanceByDomain($domain);

// Route to instance-specific PHP-FPM pool
$socket = "/var/run/php/ikabud-{$instance['instance_id']}.sock";
fastcgi_pass($socket, $_SERVER['REQUEST_URI']);
```

---

## ⚠️ Challenges & Considerations

### 1. **Resource Overhead**
- Each PHP-FPM pool consumes ~50-100MB base memory
- 100 instances = 5-10GB just for PHP-FPM processes
- **Mitigation**: Dynamic pool spawning, on-demand activation

### 2. **System Limits**
- Linux has limits on processes, file descriptors, sockets
- **Mitigation**: Tune kernel parameters (`ulimit`, `sysctl`)

### 3. **Shared Hosting Compatibility**
- Shared hosts won't allow custom PHP-FPM pools or systemd services
- **Mitigation**: Hybrid mode - use process isolation on VPS/dedicated, fallback to symlink on shared

### 4. **Complexity**
- Requires root access for user creation, systemd management
- More moving parts to debug
- **Mitigation**: Comprehensive logging, health checks, automated recovery

### 5. **Performance**
- Process switching overhead
- More memory usage
- **Mitigation**: Optimize pool settings, use opcache, CDN for static assets

---

## 🎯 Recommendation

### For Production: **Hybrid Approach**

1. **VPS/Dedicated Servers**: Use full process isolation
   - PHP-FPM pools per instance
   - Systemd service management
   - True OS-level isolation

2. **Shared Hosting**: Use current symlink approach
   - Filesystem isolation only
   - Minimal overhead
   - Maximum compatibility

3. **Kernel Detection**: Auto-detect environment and choose strategy
   ```php
   if ($kernel->canUseProcessIsolation()) {
       $strategy = new ProcessIsolationStrategy();
   } else {
       $strategy = new SymlinkStrategy();
   }
   ```

---

## 📚 References

1. **Process Isolation**: https://en.wikipedia.org/wiki/Process_isolation
2. **Microkernel Architecture**: https://www.aalpha.net/blog/microkernel-architecture/
3. **PHP-FPM Pools**: https://docs.vultr.com/use-php-fpm-pools-to-secure-multiple-web-sites
4. **Systemd Services**: https://tecadmin.net/running-a-php-script-as-systemd-service-in-linux/
5. **Google Chrome Multi-Process**: Process-per-tab architecture
6. **PHP Manual**: "Pools are not a security mechanism" - https://www.php.net/manual/en/install.fpm.configuration.php

---

## ✅ Conclusion

**YES, it's possible and has been proven in production:**

- ✅ **Chrome/IE8**: Process-per-tab for browsers
- ✅ **PHP-FPM Pools**: Process-per-site for web hosting
- ✅ **Microkernel OS**: Process-per-service for operating systems
- ✅ **Systemd**: Process management and supervision

**For Ikabud Kernel:**
- Implement as **optional feature** for VPS/dedicated environments
- Keep **symlink approach** as default for shared hosting compatibility
- Use **kernel detection** to automatically choose best strategy
- Provides **true multi-tenant isolation** when available

**Next Steps:**
1. Implement `ProcessManager` class
2. Add PHP-FPM pool generation
3. Create systemd service templates
4. Build kernel router for pool routing
5. Add environment detection and strategy selection
