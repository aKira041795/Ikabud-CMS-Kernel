#!/usr/bin/env php
<?php
/**
 * Clean up stale WordPress Customizer changesets
 * Run this if you're getting "Non-existent changeset UUID" errors
 */

// Find all WordPress instances
$instancesDir = __DIR__ . '/instances';
$instances = glob($instancesDir . '/*/wp-config.php');

echo "🧹 Cleaning up stale customizer changesets...\n\n";

foreach ($instances as $configFile) {
    $instanceDir = dirname($configFile);
    $instanceName = basename($instanceDir);
    
    // Load WordPress
    define('WP_USE_THEMES', false);
    $_SERVER['HTTP_HOST'] = 'localhost';
    
    try {
        require_once $configFile;
        require_once $instanceDir . '/wp-load.php';
        
        global $wpdb;
        
        // Count stale changesets
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'customize_changeset' 
            AND post_status = 'auto-draft'"
        );
        
        if ($count > 0) {
            echo "📦 Instance: $instanceName\n";
            echo "   Found $count auto-draft changesets\n";
            
            // Delete them
            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->posts} 
                WHERE post_type = 'customize_changeset' 
                AND post_status = 'auto-draft'"
            );
            
            echo "   ✓ Deleted $deleted changesets\n\n";
        } else {
            echo "✓ Instance: $instanceName - No stale changesets\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Instance: $instanceName - Error: " . $e->getMessage() . "\n\n";
    }
}

echo "\n✅ Cleanup complete!\n";
