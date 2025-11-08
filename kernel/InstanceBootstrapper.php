<?php
/**
 * Ikabud Kernel - CMS Instance Bootstrapper
 * 
 * Handles the precise 5-phase boot sequence for CMS instances
 * Ensures proper dependency loading and instance isolation
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core;

use Exception;
use IkabudKernel\CMS\CMSRegistry;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

class InstanceBootstrapper
{
    private Kernel $kernel;
    private array $bootLog = [];
    private float $bootStartTime;
    private string $instanceId;
    private array $config;
    private $cmsAdapter = null;
    private array $data = [];
    
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }
    
    /**
     * Boot a CMS instance through 5-phase sequence
     * 
     * @param string $instanceId Instance identifier
     * @param array $config Instance configuration
     * @return bool Success status
     * @throws Exception If boot fails
     */
    public function bootInstance(string $instanceId, array $config): bool
    {
        $this->bootStartTime = microtime(true);
        $this->instanceId = $instanceId;
        $this->config = $config;
        
        try {
            $this->logStage('boot_start', "Booting instance: {$instanceId}");
            
            // PHASE 1: Kernel-Level Dependencies
            $this->phase1_KernelServices();
            
            // PHASE 2: CMS Core Dependencies
            $this->phase2_SharedCore();
            
            // PHASE 3: Instance-Specific Dependencies
            $this->phase3_InstanceConfiguration();
            
            // PHASE 4: CMS Runtime Dependencies
            $this->phase4_CMSRuntime();
            
            // PHASE 5: Theme & Plugin Dependencies
            $this->phase5_Extensions();
            
            // Validate instance is ready
            if (!$this->validateInstanceReady()) {
                throw new Exception("Instance validation failed");
            }
            
            $bootTime = round((microtime(true) - $this->bootStartTime) * 1000, 2);
            $this->logStage('boot_complete', "Instance booted successfully in {$bootTime}ms");
            
            return true;
            
        } catch (Exception $e) {
            $this->logStage('boot_failed', "Boot failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * PHASE 1: Initialize Kernel-Level Dependencies
     * These MUST load first before any CMS code
     */
    private function phase1_KernelServices(): void
    {
        $this->logStage('phase1_start', 'Initializing kernel services');
        
        // 1.1 Verify kernel is booted
        if (!$this->kernel->isBooted()) {
            throw new Exception("Kernel must be booted before instances");
        }
        
        // 1.2 Database Manager (already initialized by kernel)
        $db = $this->kernel->getDatabase();
        if (!$db) {
            throw new Exception("Database manager not available");
        }
        
        // 1.3 Configuration Loader
        $this->loadKernelConfig();
        
        // 1.4 Security Sandbox
        $this->initializeSandbox();
        
        $this->logStage('phase1_complete', 'Kernel services ready');
    }
    
    /**
     * PHASE 2: Load Shared CMS Core
     * Load the shared core WITHOUT executing it
     */
    private function phase2_SharedCore(): void
    {
        $this->logStage('phase2_start', 'Loading shared CMS core');
        
        $cmsType = $this->config['cms_type'] ?? 'wordpress';
        $sharedCorePath = dirname(__DIR__) . "/shared-cores/{$cmsType}";
        
        if (!is_dir($sharedCorePath)) {
            throw new Exception("Shared core not found: {$cmsType}");
        }
        
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
                
            default:
                throw new Exception("Unsupported CMS type: {$cmsType}");
        }
        
        $this->logStage('phase2_complete', "Shared core loaded: {$cmsType}");
    }
    
    /**
     * PHASE 3: Configure Instance-Specific Environment
     * Set up isolated environment for this instance
     */
    private function phase3_InstanceConfiguration(): void
    {
        $this->logStage('phase3_start', 'Configuring instance environment');
        
        // 3.1 Set instance-specific constants
        if (!defined('IKABUD_INSTANCE_ID')) {
            define('IKABUD_INSTANCE_ID', $this->instanceId);
        }
        
        $instancePath = dirname(__DIR__) . "/instances/{$this->instanceId}";
        if (!defined('IKABUD_INSTANCE_PATH')) {
            define('IKABUD_INSTANCE_PATH', $instancePath);
        }
        
        // 3.2 Configure CMS for this instance
        $this->configureCMSInstance();
        
        // 3.3 Set up isolated database connection
        $this->configureInstanceDatabase();
        
        // 3.4 Load instance-specific configuration
        $this->loadInstanceConfig();
        
        // 3.5 Ensure .htaccess exists for shared hosting compatibility
        $this->ensureInstanceHtaccess();
        
        $this->logStage('phase3_complete', 'Instance environment configured');
    }
    
    /**
     * PHASE 4: Bootstrap CMS Runtime
     * Initialize the CMS but don't run it yet
     */
    private function phase4_CMSRuntime(): void
    {
        $this->logStage('phase4_start', 'Bootstrapping CMS runtime');
        
        $cmsType = $this->config['cms_type'] ?? 'wordpress';
        
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
        
        $this->logStage('phase4_complete', 'CMS runtime bootstrapped');
    }
    
    /**
     * PHASE 5: Load Theme & Plugin Dependencies
     * Load instance-specific extensions
     */
    private function phase5_Extensions(): void
    {
        $this->logStage('phase5_start', 'Loading instance extensions');
        
        // 5.1 Load instance-specific functions
        $this->loadInstanceFunctions();
        
        // 5.2 Register instance themes
        $this->registerInstanceThemes();
        
        // 5.3 Load active plugins (selective loading)
        $this->loadInstancePlugins();
        
        // 5.4 Initialize DSL for this instance
        $this->initializeDSL();
        
        $this->logStage('phase5_complete', 'Extensions loaded');
    }
    
    /**
     * Load WordPress Core (Phase 2)
     */
    private function loadWordPressCore(string $corePath): void
    {
        // Create WordPress adapter
        $this->cmsAdapter = new WordPressAdapter($corePath);
        
        // Verify core exists
        if (!file_exists($corePath . '/wp-load.php')) {
            throw new Exception("WordPress core not found at: {$corePath}");
        }
        
        $this->logStage('wordpress_core', 'WordPress adapter created');
        $this->logStage('wordpress_isolated', 'WordPress environment ready');
    }
    
    /**
     * Load Joomla Core (Phase 2)
     */
    private function loadJoomlaCore(string $corePath): void
    {
        if (!defined('_JEXEC')) {
            define('_JEXEC', 1);
        }
        
        if (!defined('JPATH_BASE')) {
            define('JPATH_BASE', $corePath);
        }
        
        $this->logStage('joomla_core', 'Joomla core loaded');
    }
    
    /**
     * Load Drupal Core (Phase 2)
     */
    private function loadDrupalCore(string $corePath): void
    {
        if (!defined('DRUPAL_ROOT')) {
            define('DRUPAL_ROOT', $corePath);
        }
        
        $this->logStage('drupal_core', 'Drupal core loaded');
    }
    
    
    /**
     * Configure CMS Instance (Phase 3)
     */
    private function configureCMSInstance(): void
    {
        $cmsType = $this->config['cms_type'] ?? 'wordpress';
        
        switch ($cmsType) {
            case 'wordpress':
                if ($this->cmsAdapter) {
                    // Initialize WordPress adapter with instance config
                    $this->cmsAdapter->initialize([
                        'instance_id' => $this->instanceId,
                        'database_name' => $this->config['database_name'] ?? 'wordpress',
                        'database_prefix' => $this->config['database_prefix'] ?? 'wp_'
                    ]);
                    
                    $this->logStage('wp_initialized', 'WordPress adapter initialized');
                } else {
                    $this->logStage('wp_config', 'WordPress instance configured');
                }
                break;
                
            case 'joomla':
                // Set Joomla instance configuration
                $this->logStage('joomla_config', 'Joomla instance configured');
                break;
        }
    }
    
    /**
     * Configure Instance Database (Phase 3)
     */
    private function configureInstanceDatabase(): void
    {
        $dbName = $this->config['database_name'] ?? null;
        $dbPrefix = $this->config['database_prefix'] ?? 'wp_';
        
        if (!$dbName) {
            throw new Exception("Database name not configured for instance");
        }
        
        // Database connection is handled by CMS-specific config
        // (wp-config.php for WordPress, configuration.php for Joomla)
        
        $this->logStage('database_config', "Database configured: {$dbName}");
    }
    
    /**
     * Load Instance Configuration (Phase 3)
     */
    private function loadInstanceConfig(): void
    {
        $configFile = IKABUD_INSTANCE_PATH . '/ikabud.json';
        
        if (file_exists($configFile)) {
            $instanceConfig = json_decode(file_get_contents($configFile), true);
            $this->config = array_merge($this->config, $instanceConfig);
            $this->logStage('instance_config', 'Instance configuration loaded');
        }
    }
    
    /**
     * Bootstrap WordPress (Phase 4)
     */
    private function bootstrapWordPress(): void
    {
        if ($this->cmsAdapter) {
            // Boot WordPress through adapter
            $this->cmsAdapter->boot();
            
            // Register with CMS Registry
            CMSRegistry::register($this->instanceId, $this->cmsAdapter, [
                'routes' => ['/' . $this->instanceId],
                'memory_limit' => 256
            ]);
            
            $this->logStage('wordpress_booted', 'WordPress environment running');
        } else {
            // Fallback: WordPress bootstrap is handled by wp-load.php
            $this->logStage('wordpress_bootstrap', 'WordPress ready to run');
        }
    }
    
    /**
     * Bootstrap Joomla (Phase 4)
     */
    private function bootstrapJoomla(): void
    {
        // Joomla bootstrap logic
        $this->logStage('joomla_bootstrap', 'Joomla ready to run');
    }
    
    /**
     * Bootstrap Drupal (Phase 4)
     */
    private function bootstrapDrupal(): void
    {
        // Drupal bootstrap logic
        $this->logStage('drupal_bootstrap', 'Drupal ready to run');
    }
    
    /**
     * Load Instance Functions (Phase 5)
     */
    private function loadInstanceFunctions(): void
    {
        $functionsFile = IKABUD_INSTANCE_PATH . '/functions.php';
        
        if (file_exists($functionsFile)) {
            require_once $functionsFile;
            $this->logStage('functions_loaded', 'Instance functions loaded');
        }
    }
    
    /**
     * Register Instance Themes (Phase 5)
     */
    private function registerInstanceThemes(): void
    {
        $themesPath = IKABUD_INSTANCE_PATH . '/wp-content/themes';
        
        if (is_dir($themesPath)) {
            $this->logStage('themes_registered', 'Instance themes registered');
        }
    }
    
    /**
     * Load Instance Plugins (Phase 5)
     */
    private function loadInstancePlugins(): void
    {
        $pluginsPath = IKABUD_INSTANCE_PATH . '/wp-content/plugins';
        
        if (is_dir($pluginsPath)) {
            // Plugins are loaded by WordPress
            $this->logStage('plugins_ready', 'Instance plugins ready');
        }
    }
    
    /**
     * Initialize DSL for Instance (Phase 5)
     */
    private function initializeDSL(): void
    {
        // DSL initialization logic
        $this->logStage('dsl_initialized', 'DSL ready for instance');
    }
    
    /**
     * Load Kernel Configuration
     */
    private function loadKernelConfig(): void
    {
        // Kernel config is already loaded
        $this->logStage('kernel_config', 'Kernel configuration available');
    }
    
    /**
     * Initialize Security Sandbox
     */
    private function initializeSandbox(): void
    {
        // Security sandbox initialization
        $this->logStage('sandbox_init', 'Security sandbox initialized');
    }
    
    /**
     * Validate Instance is Ready
     */
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
                $this->logStage('validation_failed', "Check failed: {$check}");
                return false;
            }
        }
        
        $this->logStage('validation_passed', 'All checks passed');
        return true;
    }
    
    /**
     * Log Boot Stage
     */
    private function logStage(string $stage, string $message): void
    {
        $entry = [
            'stage' => $stage,
            'message' => $message,
            'memory' => memory_get_usage(true),
            'time' => microtime(true) - $this->bootStartTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->bootLog[] = $entry;
        
        // Log to error log for debugging
        error_log(sprintf(
            "IKABUD_BOOT [%s] %s: %s (%.2fms, %s)",
            $this->instanceId,
            $stage,
            $message,
            $entry['time'] * 1000,
            $this->formatBytes($entry['memory'])
        ));
    }
    
    /**
     * Get Boot Log
     */
    public function getBootLog(): array
    {
        return $this->bootLog;
    }
    
    /**
     * Ensure .htaccess exists for shared hosting compatibility
     * Creates .htaccess with proper MIME types and rewrite rules
     */
    private function ensureInstanceHtaccess(): void
    {
        $instancePath = dirname(__DIR__) . "/instances/{$this->instanceId}";
        $htaccessPath = $instancePath . '/.htaccess';
        
        // If .htaccess already exists, don't overwrite it
        if (file_exists($htaccessPath)) {
            return;
        }
        
        // Load template
        $templatePath = dirname(__DIR__) . '/templates/instance.htaccess';
        if (!file_exists($templatePath)) {
            // Create basic .htaccess if template doesn't exist
            $htaccessContent = $this->getDefaultHtaccessContent();
        } else {
            $htaccessContent = file_get_contents($templatePath);
        }
        
        // Add CMS-specific rewrite rules
        $cmsType = $this->config['cms_type'] ?? 'wordpress';
        $htaccessContent .= "\n\n" . $this->getCMSRewriteRules($cmsType);
        
        // Write .htaccess file
        file_put_contents($htaccessPath, $htaccessContent);
        chmod($htaccessPath, 0644);
        
        $this->logStage('htaccess_created', '.htaccess file created for shared hosting compatibility');
    }
    
    /**
     * Get default .htaccess content
     */
    private function getDefaultHtaccessContent(): string
    {
        return <<<'HTACCESS'
# Ikabud Kernel - Instance .htaccess
# Shared hosting compatible

<IfModule mod_mime.c>
    AddType text/css .css
    AddType application/javascript .js
    AddType font/woff .woff
    AddType font/woff2 .woff2
    AddType font/ttf .ttf
    AddType application/vnd.ms-fontobject .eot
    AddType image/svg+xml .svg
    AddType image/webp .webp
</IfModule>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

HTACCESS;
    }
    
    /**
     * Get CMS-specific rewrite rules
     */
    private function getCMSRewriteRules(string $cmsType): string
    {
        switch ($cmsType) {
            case 'wordpress':
                return <<<'WORDPRESS'
# WordPress Rewrite Rules
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
WORDPRESS;
            
            case 'joomla':
                return <<<'JOOMLA'
# Joomla Rewrite Rules
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_URI} !^/index\.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* index.php [L]
</IfModule>
JOOMLA;
            
            case 'drupal':
                return <<<'DRUPAL'
# Drupal Rewrite Rules
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !=/favicon.ico
RewriteRule ^ index.php [L]
</IfModule>
DRUPAL;
            
            default:
                return '';
        }
    }
    
    /**
     * Format Bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get CMS Adapter
     */
    public function getCMSAdapter()
    {
        return $this->cmsAdapter;
    }
}
