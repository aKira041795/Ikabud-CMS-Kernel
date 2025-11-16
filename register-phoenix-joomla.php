#!/usr/bin/env php
<?php
/**
 * Register Phoenix Template in Joomla Database
 * 
 * This script registers the Phoenix template in the Joomla database
 * so it appears in the admin panel.
 */

echo "=== Phoenix Template Registration ===\n\n";

// Load Joomla configuration
$configFile = __DIR__ . '/instances/jml-joomla-the-beginning/configuration.php';

if (!file_exists($configFile)) {
    echo "❌ Joomla configuration not found: {$configFile}\n";
    exit(1);
}

require_once $configFile;
$config = new JConfig();

echo "Database Configuration:\n";
echo "  Host: {$config->host}\n";
echo "  Database: {$config->db}\n";
echo "  Prefix: {$config->dbprefix}\n";
echo "  User: {$config->user}\n\n";

// Connect to database
try {
    $mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
    
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    
    echo "✅ Connected to database\n\n";
    
    // Check if template already exists
    $prefix = $config->dbprefix;
    $checkSql = "SELECT extension_id, name, enabled FROM {$prefix}extensions WHERE type='template' AND element='phoenix' AND client_id=0";
    $result = $mysqli->query($checkSql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "⚠️  Phoenix template already registered!\n";
        echo "   Extension ID: {$row['extension_id']}\n";
        echo "   Name: {$row['name']}\n";
        echo "   Enabled: " . ($row['enabled'] ? 'Yes' : 'No') . "\n\n";
        
        // Check template style
        $styleSql = "SELECT id, template, home, title FROM {$prefix}template_styles WHERE template='phoenix' AND client_id=0";
        $styleResult = $mysqli->query($styleSql);
        
        if ($styleResult && $styleResult->num_rows > 0) {
            echo "✅ Template style exists:\n";
            while ($style = $styleResult->fetch_assoc()) {
                echo "   - {$style['title']} (ID: {$style['id']}, Default: " . ($style['home'] ? 'Yes' : 'No') . ")\n";
            }
        } else {
            echo "⚠️  No template style found. Creating one...\n";
            
            $insertStyleSql = "INSERT INTO {$prefix}template_styles (template, client_id, home, title, params) 
                              VALUES ('phoenix', 0, 0, 'Phoenix - Default', '{}')";
            
            if ($mysqli->query($insertStyleSql)) {
                echo "✅ Template style created (ID: " . $mysqli->insert_id . ")\n";
            } else {
                echo "❌ Failed to create template style: " . $mysqli->error . "\n";
            }
        }
        
    } else {
        echo "Template not found. Registering...\n\n";
        
        // Prepare manifest cache
        $manifestCache = json_encode([
            'name' => 'phoenix',
            'type' => 'template',
            'creationDate' => 'November 2025',
            'author' => 'Ikabud Team',
            'copyright' => '(C) 2025 Ikabud. All rights reserved.',
            'authorEmail' => 'support@ikabud.com',
            'authorUrl' => '',
            'version' => '1.0.0',
            'description' => 'A stunning, modern DiSyL-powered Joomla template',
            'group' => '',
            'filename' => 'templateDetails'
        ]);
        
        // Insert extension
        $insertExtSql = "INSERT INTO {$prefix}extensions 
            (package_id, name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state) 
            VALUES (0, 'phoenix', 'template', 'phoenix', '', 0, 1, 1, 0, 0, ?, '{}', '', 0, 0)";
        
        $stmt = $mysqli->prepare($insertExtSql);
        $stmt->bind_param('s', $manifestCache);
        
        if ($stmt->execute()) {
            $extensionId = $mysqli->insert_id;
            echo "✅ Extension registered (ID: {$extensionId})\n";
            
            // Insert template style
            $insertStyleSql = "INSERT INTO {$prefix}template_styles (template, client_id, home, title, params) 
                              VALUES ('phoenix', 0, 0, 'Phoenix - Default', '{}')";
            
            if ($mysqli->query($insertStyleSql)) {
                $styleId = $mysqli->insert_id;
                echo "✅ Template style created (ID: {$styleId})\n";
            } else {
                echo "❌ Failed to create template style: " . $mysqli->error . "\n";
            }
        } else {
            echo "❌ Failed to register extension: " . $stmt->error . "\n";
        }
        
        $stmt->close();
    }
    
    $mysqli->close();
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ Registration complete!\n\n";
    echo "Next steps:\n";
    echo "1. Go to Joomla Admin: System → Site Templates\n";
    echo "2. Find 'Phoenix' in the list\n";
    echo "3. Click to edit or set as default\n";
    echo "4. Configure template parameters\n";
    echo str_repeat("=", 50) . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
