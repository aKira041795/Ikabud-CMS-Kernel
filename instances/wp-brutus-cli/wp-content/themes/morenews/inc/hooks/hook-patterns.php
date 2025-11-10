<?php

/**
 * Register patterns
 *
 * @package MoreNews
 */



function morenews_register_patterns_categories()
{
    register_block_pattern_category(
        'morenews',
        array('label' => __('MoreNews', 'morenews'))
    );
}

add_action('init', 'morenews_register_patterns_categories');

function morenews_register_patterns()
{

    if (!function_exists('register_block_pattern')) {
        return;
    }
    
    // Initialize the $patterns array
    $patterns = [];
    
    // Check if BlockspareInit class exists and prioritize those patterns
    if (class_exists('BlockspareInit')) {
        $patterns = [
            'block-1',
            'block-2',
            'block-3',
            'block-4',
            'block-5',
            'section-1',
            'section-2',
            'section-3',
            'section-4',
            'full',
        ];
    }
    
    // Add default theme patterns after Blockspare patterns
    $patterns = array_merge($patterns, [
        'bg-post-list',
        'dbg-post-list',
        'bg-post-grid-3-col',
        'dbg-post-grid-3-col',
        'bg-post-grid',
        'dbg-post-grid',
        'bg-large-post',
        'dbg-large-post',        
        'theme-block-10',
        'theme-block-10-2',
        'theme-block-9',
        'theme-block-9-2',
        'theme-block-8',
        'theme-block-8-2',
        'theme-block-7',
        'theme-block-7-2',
        'theme-block-6',        
        'theme-block-6-2',        
    ]);
    
    // Register all patterns
    foreach ($patterns as $pattern) {
        $pattern_file = __DIR__ . '/patterns/' . $pattern . '.php';
        
        // Check if pattern file exists before requiring it
        if (file_exists($pattern_file)) {
            register_block_pattern(
                'morenews/' . $pattern,
                require $pattern_file
            );
        }
    }
    
}

add_action('init', 'morenews_register_patterns');
