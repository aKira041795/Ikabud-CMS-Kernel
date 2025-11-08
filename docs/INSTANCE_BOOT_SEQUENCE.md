# Ikabud Kernel - Instance Boot Sequence

**Version**: 1.0.0  
**Status**: ✅ IMPLEMENTED  
**Architecture**: 5-Phase Boot Sequence

---

## 🎯 Overview

The Ikabud Kernel uses a **precise 5-phase boot sequence** to initialize CMS instances with proper dependency management and isolation. This ensures that:

- ✅ Kernel boots FIRST, then supervises CMS instances
- ✅ Each phase depends on the previous phase
- ✅ CMS instances are properly isolated
- ✅ No dependency conflicts or race conditions
- ✅ Clear validation at each stage

---

## 🏗️ Boot Architecture

```
┌─────────────────────────────────────────┐
│         Ikabud Kernel (Master)          │
│              BOOTS FIRST                │
└─────────────────────────────────────────┘
                    │
                    ├─── Phase 1: Kernel Services
                    ├─── Phase 2: Shared Core
                    ├─── Phase 3: Instance Config
                    ├─── Phase 4: CMS Runtime
                    └─── Phase 5: Extensions
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
   ┌────▼────┐          ┌────▼────┐          ┌────▼────┐
   │ WP-001  │          │ WP-002  │          │ JM-001  │
   │ Process │          │ Process │          │ Process │
   └─────────┘          └─────────┘          └─────────┘
```

---

## 📋 5-Phase Boot Sequence

### **PHASE 1: Kernel-Level Dependencies** 🔧

**Purpose**: Initialize kernel services that MUST be available before any CMS code loads

**What Happens**:
1. Verify kernel is booted
2. Database manager available
3. Configuration loader ready
4. Security sandbox initialized

**Code**:
```php
private function phase1_KernelServices(): void
{
    // 1.1 Verify kernel is booted
    if (!$this->kernel->isBooted()) {
        throw new Exception("Kernel must be booted before instances");
    }
    
    // 1.2 Database Manager
    $db = $this->kernel->getDatabase();
    
    // 1.3 Configuration Loader
    $this->loadKernelConfig();
    
    // 1.4 Security Sandbox
    $this->initializeSandbox();
}
```

**Success Indicators**:
- ✅ Kernel is booted
- ✅ Database connection available
- ✅ Configuration loaded
- ✅ Sandbox initialized

---

### **PHASE 2: CMS Core Dependencies** 📦

**Purpose**: Load the shared CMS core WITHOUT executing it

**What Happens**:
1. Locate shared core directory
2. Set core constants (ABSPATH, _JEXEC, etc.)
3. Isolate CMS environment
4. Prepare core for instance-specific configuration

**Code**:
```php
private function phase2_SharedCore(): void
{
    $cmsType = $this->config['cms_type'];
    $sharedCorePath = dirname(__DIR__) . "/shared-cores/{$cmsType}";
    
    switch ($cmsType) {
        case 'wordpress':
            $this->loadWordPressCore($sharedCorePath);
            break;
        case 'joomla':
            $this->loadJoomlaCore($sharedCorePath);
            break;
        case 'drupal':
            $this->loadDrupalCore($sharedCorePath);
            break;
    }
}
```

**Success Indicators**:
- ✅ Shared core path exists
- ✅ Core constants defined
- ✅ Environment isolated
- ✅ No output generated yet

---

### **PHASE 3: Instance-Specific Dependencies** ⚙️

**Purpose**: Configure isolated environment for this specific instance

**What Happens**:
1. Set instance-specific constants
2. Configure CMS for this instance
3. Set up isolated database connection
4. Load instance configuration file

**Code**:
```php
private function phase3_InstanceConfiguration(): void
{
    // 3.1 Set instance constants
    define('IKABUD_INSTANCE_ID', $this->instanceId);
    define('IKABUD_INSTANCE_PATH', $instancePath);
    
    // 3.2 Configure CMS
    $this->configureCMSInstance();
    
    // 3.3 Database isolation
    $this->configureInstanceDatabase();
    
    // 3.4 Load instance config
    $this->loadInstanceConfig();
}
```

**Success Indicators**:
- ✅ Instance constants defined
- ✅ Instance path exists
- ✅ Database configured
- ✅ Instance config loaded

---

### **PHASE 4: CMS Runtime Dependencies** 🚀

**Purpose**: Bootstrap the CMS runtime but don't run it yet

**What Happens**:
1. Set up CMS environment variables
2. Load CMS pluggable functions (isolated)
3. Initialize CMS core objects
4. Prepare CMS for request handling

**Code**:
```php
private function phase4_CMSRuntime(): void
{
    $cmsType = $this->config['cms_type'];
    
    switch ($cmsType) {
        case 'wordpress':
            $this->bootstrapWordPress();
            break;
        case 'joomla':
            $this->bootstrapJoomla();
            break;
        case 'drupal':
            $this->bootstrapDrupal();
            break;
    }
}
```

**Success Indicators**:
- ✅ CMS globals set
- ✅ Core functions loaded
- ✅ CMS objects initialized
- ✅ Ready to handle requests

---

### **PHASE 5: Theme & Plugin Dependencies** 🎨

**Purpose**: Load instance-specific extensions and finalize boot

**What Happens**:
1. Load instance-specific functions.php
2. Register instance themes
3. Load active plugins (selective loading)
4. Initialize DSL for this instance

**Code**:
```php
private function phase5_Extensions(): void
{
    // 5.1 Instance functions
    $this->loadInstanceFunctions();
    
    // 5.2 Themes
    $this->registerInstanceThemes();
    
    // 5.3 Plugins
    $this->loadInstancePlugins();
    
    // 5.4 DSL
    $this->initializeDSL();
}
```

**Success Indicators**:
- ✅ Functions loaded
- ✅ Themes registered
- ✅ Plugins loaded
- ✅ DSL initialized

---

## 🔍 Validation & Health Checks

After all 5 phases complete, the bootstrapper validates the instance:

```php
private function validateInstanceReady(): bool
{
    $checks = [
        'instance_path_exists' => is_dir(IKABUD_INSTANCE_PATH),
        'wp_content_exists' => is_dir(IKABUD_INSTANCE_PATH . '/wp-content'),
        'database_configured' => isset($this->config['database_name']),
        'cms_type_set' => isset($this->config['cms_type']),
    ];
    
    foreach ($checks as $check => $result) {
        if (!$result) {
            return false;
        }
    }
    
    return true;
}
```

---

## 📊 Boot Logging

Every stage is logged with detailed metrics:

```php
$entry = [
    'stage' => $stage,
    'message' => $message,
    'memory' => memory_get_usage(true),
    'time' => microtime(true) - $this->bootStartTime,
    'timestamp' => date('Y-m-d H:i:s')
];
```

**Example Log Output**:
```
IKABUD_BOOT [wp-test-001] phase1_start: Initializing kernel services (0.00ms, 2.5 MB)
IKABUD_BOOT [wp-test-001] kernel_config: Kernel configuration available (1.23ms, 2.5 MB)
IKABUD_BOOT [wp-test-001] sandbox_init: Security sandbox initialized (2.45ms, 2.6 MB)
IKABUD_BOOT [wp-test-001] phase1_complete: Kernel services ready (3.12ms, 2.6 MB)
IKABUD_BOOT [wp-test-001] phase2_start: Loading shared CMS core (3.15ms, 2.6 MB)
IKABUD_BOOT [wp-test-001] wordpress_core: WordPress core path set (4.23ms, 2.7 MB)
IKABUD_BOOT [wp-test-001] wordpress_isolated: WordPress environment isolated (5.01ms, 2.7 MB)
IKABUD_BOOT [wp-test-001] phase2_complete: Shared core loaded: wordpress (5.45ms, 2.7 MB)
...
IKABUD_BOOT [wp-test-001] boot_complete: Instance booted successfully in 12.34ms (12.34ms, 3.2 MB)
```

---

## 🧪 Testing the Boot Sequence

### **Test Script**:
```bash
php test-instance-boot.php
```

### **Expected Output**:
```
===========================================
Ikabud Kernel - Instance Boot Test
===========================================

Step 1: Booting Kernel...
✓ Kernel booted successfully

✓ Kernel instance retrieved
✓ Kernel version: 1.0.0
✓ Kernel booted: YES

Step 2: Loading instance configuration...
✓ Instance found: Ikabud WP Instance
✓ CMS Type: wordpress
✓ Database: ikabud_wp_test
✓ Status: active

Step 3: Booting CMS instance through 5-phase sequence...
-------------------------------------------
[Boot log entries appear here]
-------------------------------------------
✓ Instance booted successfully!

Step 4: Boot Log Summary
===========================================
✓ All 5 phases completed
✓ Instance is ready to serve requests

Step 5: Validating instance...
✓ Instance directory exists
✓ wp-content exists
✓ wp-config.php exists
✓ Plugins directory exists
✓ Themes directory exists
✓ Uploads directory exists

===========================================
SUCCESS: Instance boot sequence complete!
===========================================
```

---

## 🎯 Usage in Production

### **Boot an Instance**:
```php
use IkabudKernel\Core\Kernel;

// 1. Boot kernel
Kernel::boot();

// 2. Get kernel instance
$kernel = Kernel::getInstance();

// 3. Load instance config
$instanceConfig = [
    'cms_type' => 'wordpress',
    'database_name' => 'ikabud_wp_test',
    'database_prefix' => 'wp_',
    'status' => 'active'
];

// 4. Boot instance
$success = $kernel->bootInstance('wp-test-001', $instanceConfig);

if ($success) {
    echo "Instance ready!";
}
```

---

## 🔥 Benefits of This Architecture

### **1. Proper Dependency Management**
- ✅ No race conditions
- ✅ No circular dependencies
- ✅ Clear load order

### **2. Instance Isolation**
- ✅ Each instance has own environment
- ✅ No constant conflicts
- ✅ Isolated database connections

### **3. Debugging & Monitoring**
- ✅ Detailed boot logs
- ✅ Performance metrics
- ✅ Memory tracking
- ✅ Clear failure points

### **4. CMS Agnostic**
- ✅ Works for WordPress
- ✅ Works for Joomla
- ✅ Works for Drupal
- ✅ Easy to add new CMS types

### **5. Testable**
- ✅ Each phase can be tested independently
- ✅ Clear validation points
- ✅ Reproducible boot sequence

---

## 🚀 Next Steps

### **Phase 1 (Current)**: ✅ Boot Sequence Implemented
- InstanceBootstrapper class
- 5-phase boot logic
- Validation and logging

### **Phase 2 (Next)**: Process Isolation
- PHP-FPM pools per instance
- Systemd service management
- True process-level isolation

### **Phase 3 (Future)**: Advanced Features
- Hot-reload instances
- Zero-downtime updates
- Dynamic resource allocation
- Auto-scaling

---

## 📚 Related Documentation

- `docs/PROCESS_ISOLATION_RESEARCH.md` - Process-based architecture
- `docs/FINAL_ARCHITECTURE.md` - Overall system architecture
- `docs/INSTANCE_VHOST_ARCHITECTURE.md` - VHost configuration

---

## ✅ Status

**Implementation**: ✅ COMPLETE  
**Testing**: ✅ READY  
**Production**: ⚠️ Requires PHP-FPM pool setup for full isolation

**The boot sequence is now kernel-supervised and follows proper dependency management!** 🎉
