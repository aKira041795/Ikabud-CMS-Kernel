<?php
/**
 * Clean up stale WordPress Customizer changesets - Simple Version
 * Run this if you're getting "Non-existent changeset UUID" errors
 */

$instances = [
    'inst_58b72c1746710061' => ['db' => 'ikabud_magic_test', 'prefix' => 'wp_'],
    'inst_5ca59a2151e98cd1' => ['db' => 'ikabud_akira_test', 'prefix' => 'aki_'],
    'test-cors-001' => ['db' => 'ikabud_test_cors', 'prefix' => 'wp_'],
    'wp-brutus-cli' => ['db' => 'ikabud_brutus', 'prefix' => 'bru_'],
    'wp-test-001' => ['db' => 'ikabud_wp_test', 'prefix' => 'wp_'],
];

$db_user = 'root';
$db_pass = 'Nds90@NXIOVRH*iy';
$db_host = 'localhost';

echo "🧹 Cleaning up stale customizer changesets...\n\n";

foreach ($instances as $instance_id => $config) {
    $db_name = $config['db'];
    $prefix = $config['prefix'];
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Count stale changesets
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM {$prefix}options 
            WHERE option_name LIKE '%_customize_changeset_%'"
        );
        $option_count = $stmt->fetchColumn();
        
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM {$prefix}posts 
            WHERE post_type = 'customize_changeset' 
            AND post_status = 'auto-draft'"
        );
        $post_count = $stmt->fetchColumn();
        
        echo "📦 Instance: $instance_id (DB: $db_name, Prefix: $prefix)\n";
        echo "   Found $post_count auto-draft changesets\n";
        echo "   Found $option_count changeset options\n";
        
        if ($post_count > 0) {
            // Delete them
            $deleted = $pdo->exec(
                "DELETE FROM {$prefix}posts 
                WHERE post_type = 'customize_changeset' 
                AND post_status = 'auto-draft'"
            );
            echo "   ✓ Deleted $deleted changeset posts\n";
        }
        
        if ($option_count > 0) {
            // Delete changeset options
            $deleted = $pdo->exec(
                "DELETE FROM {$prefix}options 
                WHERE option_name LIKE '%_customize_changeset_%'"
            );
            echo "   ✓ Deleted $deleted changeset options\n";
        }
        
        if ($post_count == 0 && $option_count == 0) {
            echo "   ✓ No stale changesets\n";
        }
        
        echo "\n";
        
    } catch (PDOException $e) {
        echo "✗ Instance: $instance_id - Error: " . $e->getMessage() . "\n\n";
    }
}

echo "✅ Cleanup complete!\n";
