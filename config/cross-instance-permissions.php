<?php
/**
 * Cross-Instance Permissions Configuration
 * 
 * Defines which instances can query data from other instances.
 * 
 * @package IkabudKernel
 * @version 0.6.0
 */

return [
    /**
     * Enable/disable authorization checks
     * Set to false to allow all cross-instance queries (development mode)
     */
    'enabled' => true,
    
    /**
     * Global maximum items per query
     */
    'max_limit' => 100,
    
    /**
     * Global allowed content types
     */
    'allowed_types' => [
        'post',
        'page',
        'article',
        'node',
        'product',
        'category',
        'tag',
    ],
    
    /**
     * Per-instance permissions
     * 
     * Format:
     * 'source_instance' => [
     *     'targets' => ['target1', 'target2'],  // Instances this source can query
     *     'types' => ['post', 'article'],       // Content types allowed (optional)
     *     'max_limit' => 50,                    // Max items per query (optional)
     * ]
     * 
     * Use '*' as wildcard:
     * - 'targets' => ['*'] allows querying all instances
     * - '*' => ['targets' => ['*']] allows all instances to query all instances
     */
    'permissions' => [
        // Development mode: Allow all instances to query each other
        // Comment this out in production and define specific permissions
        '*' => [
            'targets' => ['*'],
        ],
        
        // Example: Specific permissions
        // 'wp-main' => [
        //     'targets' => ['joomla-news', 'drupal-blog'],
        //     'types' => ['article', 'post', 'page'],
        //     'max_limit' => 50,
        // ],
        // 
        // 'joomla-news' => [
        //     'targets' => ['wp-main'],
        //     'types' => ['post', 'product'],
        //     'max_limit' => 25,
        // ],
    ],
];
