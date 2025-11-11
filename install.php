#!/usr/bin/env php
<?php
/**
 * Ikabud Kernel - PHP CLI Installer
 * 
 * This installer works on shared hosting environments where shell scripts
 * are not allowed or available.
 * 
 * Usage:
 *   php install.php
 * 
 * Or via web browser:
 *   http://yourdomain.com/install.php
 */

// Detect if running from CLI or web
define('IS_CLI', PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
define('IS_WEB', !IS_CLI);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set execution time
set_time_limit(300);

/**
 * Output handler - works for both CLI and web
 */
class Output
{
    private static $webOutput = [];
    
    public static function header($text)
    {
        if (IS_CLI) {
            echo "\n\033[0;36m";
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║  " . str_pad($text, 60) . "  ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
            echo "\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='header'><h2>$text</h2></div>";
        }
    }
    
    public static function success($text)
    {
        if (IS_CLI) {
            echo "\033[0;32m✓ $text\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='success'>✓ $text</div>";
        }
    }
    
    public static function error($text)
    {
        if (IS_CLI) {
            echo "\033[0;31m✗ $text\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='error'>✗ $text</div>";
        }
    }
    
    public static function warning($text)
    {
        if (IS_CLI) {
            echo "\033[1;33m⚠ $text\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='warning'>⚠ $text</div>";
        }
    }
    
    public static function info($text)
    {
        if (IS_CLI) {
            echo "\033[0;34mℹ $text\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='info'>ℹ $text</div>";
        }
    }
    
    public static function step($text)
    {
        if (IS_CLI) {
            echo "\033[0;36m▶ $text\033[0m\n";
        } else {
            self::$webOutput[] = "<div class='step'>▶ $text</div>";
        }
    }
    
    public static function text($text)
    {
        if (IS_CLI) {
            echo "$text\n";
        } else {
            self::$webOutput[] = "<div class='text'>$text</div>";
        }
    }
    
    public static function getWebOutput()
    {
        return implode("\n", self::$webOutput);
    }
    
    public static function flush()
    {
        if (IS_WEB) {
            echo self::getWebOutput();
            ob_flush();
            flush();
        }
    }
}

/**
 * Installer class
 */
class IkabudInstaller
{
    private $config = [];
    private $errors = [];
    private $installDir;
    
    public function __construct()
    {
        $this->installDir = dirname(__FILE__);
    }
    
    /**
     * Run the installation
     */
    public function run()
    {
        Output::header('Ikabud Kernel Installation');
        
        // Check if already installed
        if ($this->isAlreadyInstalled()) {
            Output::warning('Ikabud Kernel appears to be already installed.');
            
            if (IS_CLI) {
                echo "Do you want to reinstall? (yes/no): ";
                $answer = trim(fgets(STDIN));
                if (strtolower($answer) !== 'yes') {
                    Output::info('Installation cancelled.');
                    return;
                }
            } else {
                Output::warning('To reinstall, delete the .env file and refresh this page.');
                return;
            }
        }
        
        // Step 1: Check requirements
        if (!$this->checkRequirements()) {
            Output::error('System requirements not met. Please fix the issues above and try again.');
            return;
        }
        
        // Step 2: Get configuration
        if (IS_CLI) {
            $this->getConfigCLI();
        } else {
            $this->getConfigWeb();
        }
        
        // Step 3: Create environment file
        if (!$this->createEnvironmentFile()) {
            Output::error('Failed to create environment file.');
            return;
        }
        
        // Step 4: Create database
        if (!$this->setupDatabase()) {
            Output::error('Failed to setup database.');
            return;
        }
        
        // Step 5: Install dependencies
        if (!$this->installDependencies()) {
            Output::warning('Could not install Composer dependencies. Please run: composer install');
        }
        
        // Step 6: Set permissions
        if (!$this->setPermissions()) {
            Output::warning('Could not set all permissions. Please check manually.');
        }
        
        // Step 7: Create directories
        if (!$this->createDirectories()) {
            Output::error('Failed to create required directories.');
            return;
        }
        
        // Step 8: Initialize kernel
        if (!$this->initializeKernel()) {
            Output::warning('Could not initialize kernel. Please check configuration.');
        }
        
        // Success!
        $this->printSummary();
    }
    
    /**
     * Check if already installed
     */
    private function isAlreadyInstalled()
    {
        return file_exists($this->installDir . '/.env');
    }
    
    /**
     * Check system requirements
     */
    private function checkRequirements()
    {
        Output::step('Checking system requirements...');
        
        $allGood = true;
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            Output::success('PHP version: ' . PHP_VERSION);
        } else {
            Output::error('PHP 8.1 or higher required. Current: ' . PHP_VERSION);
            $allGood = false;
        }
        
        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl'];
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                Output::success("Extension '$ext' loaded");
            } else {
                Output::error("Extension '$ext' not found");
                $allGood = false;
            }
        }
        
        // Check write permissions
        $writableDirs = ['storage', 'instances', 'themes', 'logs'];
        foreach ($writableDirs as $dir) {
            $path = $this->installDir . '/' . $dir;
            if (!file_exists($path)) {
                @mkdir($path, 0775, true);
            }
            
            if (is_writable($path)) {
                Output::success("Directory '$dir' is writable");
            } else {
                Output::error("Directory '$dir' is not writable");
                $allGood = false;
            }
        }
        
        // Check if Composer is available
        if ($this->commandExists('composer')) {
            Output::success('Composer is available');
        } else {
            Output::warning('Composer not found. Dependencies must be installed manually.');
        }
        
        return $allGood;
    }
    
    /**
     * Get configuration from CLI
     */
    private function getConfigCLI()
    {
        Output::step('Configuration Setup');
        Output::text('Please provide the following information:');
        echo "\n";
        
        // Database configuration
        echo "Database Host [localhost]: ";
        $this->config['DB_HOST'] = trim(fgets(STDIN)) ?: 'localhost';
        
        echo "Database Port [3306]: ";
        $this->config['DB_PORT'] = trim(fgets(STDIN)) ?: '3306';
        
        echo "Database Name [ikabud_kernel]: ";
        $this->config['DB_DATABASE'] = trim(fgets(STDIN)) ?: 'ikabud_kernel';
        
        echo "Database Username: ";
        $this->config['DB_USERNAME'] = trim(fgets(STDIN));
        
        echo "Database Password: ";
        $this->config['DB_PASSWORD'] = trim(fgets(STDIN));
        
        // Admin configuration
        echo "\nAdmin Username [admin]: ";
        $this->config['ADMIN_USERNAME'] = trim(fgets(STDIN)) ?: 'admin';
        
        echo "Admin Password: ";
        $this->config['ADMIN_PASSWORD'] = trim(fgets(STDIN));
        
        echo "Admin Email: ";
        $this->config['ADMIN_EMAIL'] = trim(fgets(STDIN));
        
        // Application URL
        echo "\nApplication URL [http://localhost]: ";
        $this->config['APP_URL'] = trim(fgets(STDIN)) ?: 'http://localhost';
        
        // Generate JWT secret
        $this->config['JWT_SECRET'] = base64_encode(random_bytes(32));
        
        echo "\n";
    }
    
    /**
     * Get configuration from web form
     */
    private function getConfigWeb()
    {
        // Check if form was submitted
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->config = [
                'DB_HOST' => $_POST['db_host'] ?? 'localhost',
                'DB_PORT' => $_POST['db_port'] ?? '3306',
                'DB_DATABASE' => $_POST['db_name'] ?? 'ikabud_kernel',
                'DB_USERNAME' => $_POST['db_user'] ?? '',
                'DB_PASSWORD' => $_POST['db_pass'] ?? '',
                'ADMIN_USERNAME' => $_POST['admin_user'] ?? 'admin',
                'ADMIN_PASSWORD' => $_POST['admin_pass'] ?? '',
                'ADMIN_EMAIL' => $_POST['admin_email'] ?? '',
                'APP_URL' => $_POST['app_url'] ?? 'http://' . $_SERVER['HTTP_HOST'],
                'JWT_SECRET' => base64_encode(random_bytes(32)),
            ];
        } else {
            // Show form
            $this->showWebForm();
            exit;
        }
    }
    
    /**
     * Show web installation form
     */
    private function showWebForm()
    {
        $defaultUrl = 'http://' . $_SERVER['HTTP_HOST'];
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ikabud Kernel - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 10px; }
        .subtitle { color: #7f8c8d; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #2c3e50; font-weight: 500; }
        input[type="text"], input[type="password"], input[type="email"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        input:focus { outline: none; border-color: #3498db; }
        .section { margin-top: 30px; padding-top: 20px; border-top: 2px solid #ecf0f1; }
        .section-title { color: #3498db; margin-bottom: 15px; font-size: 18px; }
        button { background: #3498db; color: white; padding: 12px 30px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; width: 100%; }
        button:hover { background: #2980b9; }
        .note { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin-bottom: 20px; color: #856404; }
        .required { color: #e74c3c; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Ikabud Kernel</h1>
        <div class="subtitle">Installation Setup</div>
        
        <div class="note">
            <strong>Note:</strong> Make sure you have created a MySQL database before proceeding.
        </div>
        
        <form method="POST" action="">
            <div class="section">
                <div class="section-title">Database Configuration</div>
                
                <div class="form-group">
                    <label>Database Host <span class="required">*</span></label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                
                <div class="form-group">
                    <label>Database Port</label>
                    <input type="text" name="db_port" value="3306">
                </div>
                
                <div class="form-group">
                    <label>Database Name <span class="required">*</span></label>
                    <input type="text" name="db_name" value="ikabud_kernel" required>
                </div>
                
                <div class="form-group">
                    <label>Database Username <span class="required">*</span></label>
                    <input type="text" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label>Database Password <span class="required">*</span></label>
                    <input type="password" name="db_pass" required>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">Admin Account</div>
                
                <div class="form-group">
                    <label>Admin Username <span class="required">*</span></label>
                    <input type="text" name="admin_user" value="admin" required>
                </div>
                
                <div class="form-group">
                    <label>Admin Password <span class="required">*</span></label>
                    <input type="password" name="admin_pass" required>
                </div>
                
                <div class="form-group">
                    <label>Admin Email <span class="required">*</span></label>
                    <input type="email" name="admin_email" required>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">Application Settings</div>
                
                <div class="form-group">
                    <label>Application URL</label>
                    <input type="text" name="app_url" value="{$defaultUrl}">
                </div>
            </div>
            
            <button type="submit">Install Ikabud Kernel</button>
        </form>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Create environment file
     */
    private function createEnvironmentFile()
    {
        Output::step('Creating environment file...');
        
        $envContent = <<<ENV
# Ikabud Kernel Environment Configuration
# Generated by installer on {date}

# Application
APP_NAME="Ikabud Kernel"
APP_ENV=production
APP_DEBUG=false
APP_URL={$this->config['APP_URL']}

# JWT Authentication
JWT_SECRET={$this->config['JWT_SECRET']}
JWT_ALGORITHM=HS256
JWT_EXPIRATION=86400

# Database
DB_HOST={$this->config['DB_HOST']}
DB_PORT={$this->config['DB_PORT']}
DB_DATABASE={$this->config['DB_DATABASE']}
DB_USERNAME={$this->config['DB_USERNAME']}
DB_PASSWORD={$this->config['DB_PASSWORD']}
DB_CHARSET=utf8mb4

# Admin Credentials
ADMIN_USERNAME={$this->config['ADMIN_USERNAME']}
ADMIN_PASSWORD={$this->config['ADMIN_PASSWORD']}
ADMIN_EMAIL={$this->config['ADMIN_EMAIL']}

# Cache Configuration
CACHE_DRIVER=file
CACHE_TTL=3600
CACHE_PATH=./storage/cache

# Paths
INSTANCES_PATH=./instances
SHARED_CORES_PATH=./shared-cores
THEMES_PATH=./themes
LOGS_PATH=./logs

# Security
SESSION_LIFETIME=120
CORS_ALLOWED_ORIGINS=*
ALLOWED_HOSTS=*

# Logging
LOG_LEVEL=info
LOG_CHANNEL=file

# Performance
MAX_INSTANCES=100
MEMORY_LIMIT=512M
MAX_EXECUTION_TIME=300

# Conditional Loading
CONDITIONAL_LOADING_ENABLED=true
CONDITIONAL_LOADING_CACHE=true

# Development
DEV_MODE=false
SHOW_ERRORS=false
DEBUG_QUERIES=false
ENV;
        
        $envContent = str_replace('{date}', date('Y-m-d H:i:s'), $envContent);
        
        if (file_put_contents($this->installDir . '/.env', $envContent)) {
            Output::success('Environment file created');
            return true;
        }
        
        Output::error('Failed to create environment file');
        return false;
    }
    
    /**
     * Setup database
     */
    private function setupDatabase()
    {
        Output::step('Setting up database...');
        
        try {
            // Connect to database
            $dsn = "mysql:host={$this->config['DB_HOST']};port={$this->config['DB_PORT']};charset=utf8mb4";
            $pdo = new PDO($dsn, $this->config['DB_USERNAME'], $this->config['DB_PASSWORD']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$this->config['DB_DATABASE']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            Output::success('Database created/verified');
            
            // Connect to the specific database
            $dsn = "mysql:host={$this->config['DB_HOST']};port={$this->config['DB_PORT']};dbname={$this->config['DB_DATABASE']};charset=utf8mb4";
            $pdo = new PDO($dsn, $this->config['DB_USERNAME'], $this->config['DB_PASSWORD']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Import schema
            $schemaFile = $this->installDir . '/database/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
                Output::success('Database schema imported');
            } else {
                Output::warning('Schema file not found. Please import manually.');
            }
            
            return true;
            
        } catch (PDOException $e) {
            Output::error('Database error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Install Composer dependencies
     */
    private function installDependencies()
    {
        Output::step('Installing dependencies...');
        
        if (!$this->commandExists('composer')) {
            Output::warning('Composer not available. Skipping dependency installation.');
            return false;
        }
        
        $output = [];
        $returnVar = 0;
        
        exec('cd ' . escapeshellarg($this->installDir) . ' && composer install --no-dev --optimize-autoloader 2>&1', $output, $returnVar);
        
        if ($returnVar === 0) {
            Output::success('Dependencies installed');
            return true;
        } else {
            Output::warning('Could not install dependencies automatically');
            return false;
        }
    }
    
    /**
     * Set file permissions
     */
    private function setPermissions()
    {
        Output::step('Setting file permissions...');
        
        $dirs = ['storage', 'instances', 'themes', 'logs', 'storage/cache', 'storage/logs'];
        $success = true;
        
        foreach ($dirs as $dir) {
            $path = $this->installDir . '/' . $dir;
            if (file_exists($path)) {
                if (@chmod($path, 0775)) {
                    Output::success("Set permissions for '$dir'");
                } else {
                    Output::warning("Could not set permissions for '$dir'");
                    $success = false;
                }
            }
        }
        
        // Make CLI tool executable
        $cliTool = $this->installDir . '/ikabud';
        if (file_exists($cliTool)) {
            @chmod($cliTool, 0755);
        }
        
        return $success;
    }
    
    /**
     * Create required directories
     */
    private function createDirectories()
    {
        Output::step('Creating required directories...');
        
        $dirs = [
            'storage/cache',
            'storage/logs',
            'instances',
            'shared-cores',
            'themes',
            'logs'
        ];
        
        foreach ($dirs as $dir) {
            $path = $this->installDir . '/' . $dir;
            if (!file_exists($path)) {
                if (@mkdir($path, 0775, true)) {
                    Output::success("Created directory '$dir'");
                    // Create .gitkeep
                    @touch($path . '/.gitkeep');
                } else {
                    Output::error("Failed to create directory '$dir'");
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Initialize kernel
     */
    private function initializeKernel()
    {
        Output::step('Initializing kernel...');
        
        // Test if kernel can boot
        try {
            $testUrl = $this->config['APP_URL'] . '/api/health';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents($testUrl, false, $context);
            
            if ($response) {
                Output::success('Kernel initialized successfully');
                return true;
            } else {
                Output::warning('Could not verify kernel initialization');
                return false;
            }
        } catch (Exception $e) {
            Output::warning('Could not test kernel: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Print installation summary
     */
    private function printSummary()
    {
        Output::header('Installation Complete!');
        
        Output::success('Ikabud Kernel has been installed successfully!');
        Output::text('');
        Output::info('Installation Summary:');
        Output::text('  Database: ' . $this->config['DB_DATABASE']);
        Output::text('  Admin User: ' . $this->config['ADMIN_USERNAME']);
        Output::text('  Application URL: ' . $this->config['APP_URL']);
        Output::text('');
        Output::info('Next Steps:');
        Output::text('  1. Delete this install.php file for security');
        Output::text('  2. Access admin panel: ' . $this->config['APP_URL'] . '/admin');
        Output::text('  3. Login with your admin credentials');
        Output::text('  4. Create your first CMS instance');
        Output::text('');
        
        if (IS_CLI) {
            Output::info('CLI Commands:');
            Output::text('  ikabud create <instance-id>  - Create instance');
            Output::text('  ikabud start <instance-id>   - Start instance');
            Output::text('  ikabud list                  - List instances');
            Output::text('  ikabud help                  - Show all commands');
        }
        
        Output::text('');
        Output::success('Happy building with Ikabud Kernel! 🚀');
        
        if (IS_WEB) {
            echo '<div class="final-note">';
            echo '<p><strong>⚠️ IMPORTANT:</strong> Please delete install.php for security!</p>';
            echo '<p><a href="' . $this->config['APP_URL'] . '/admin">Go to Admin Panel →</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Check if command exists
     */
    private function commandExists($command)
    {
        $return = shell_exec(sprintf("which %s 2>/dev/null", escapeshellarg($command)));
        return !empty($return);
    }
}

// Start web output buffering if running from web
if (IS_WEB) {
    ob_start();
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ikabud Kernel - Installation Progress</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #3498db; color: white; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .success { color: #27ae60; padding: 8px 0; }
        .error { color: #e74c3c; padding: 8px 0; }
        .warning { color: #f39c12; padding: 8px 0; }
        .info { color: #3498db; padding: 8px 0; }
        .step { color: #2c3e50; padding: 8px 0; font-weight: 500; }
        .text { color: #7f8c8d; padding: 4px 0; }
        .final-note { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 4px; margin-top: 20px; }
        .final-note a { color: #3498db; text-decoration: none; font-weight: 500; }
        .final-note a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
HTML;
    ob_flush();
    flush();
}

// Run installer
try {
    $installer = new IkabudInstaller();
    $installer->run();
} catch (Exception $e) {
    Output::error('Installation failed: ' . $e->getMessage());
    if (IS_CLI) {
        exit(1);
    }
}

// End web output
if (IS_WEB) {
    echo '</div></body></html>';
    ob_end_flush();
}
