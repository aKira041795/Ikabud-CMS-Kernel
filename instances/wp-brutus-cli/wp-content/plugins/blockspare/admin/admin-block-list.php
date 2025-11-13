<?php



/**
 * Block list .
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if(!function_exists('blockspare_admin_blocks_list')){
function blockspare_admin_blocks_list()
{
    return $blockspare_blocks = array(

        array(
            'name' => __('Accordion', 'blockspare'),
            'slug' => 'accordion',

        ),
        array(
            'name' => __('Button', 'blockspare'),
            'slug' => 'button',
        ),

        array(
            'name' => __('Container', 'blockspare'),
            'slug' => 'container',
        ),
        array(
            'name' => __('Content Box', 'blockspare'),
            'slug' => 'content-box',
        ),
        array(
            'name' => __('Counter', 'blockspare'),
            'slug' => 'counter',
        ),
        array(
            'name' => __('Call to Action', 'blockspare'),
            'slug' => 'cta',
        ),
        array(
            'name' => __('Empty Section', 'blockspare'),
            'slug' => 'empty-section',
        ),
        array(
            'name' => __('Icon', 'blockspare'),
            'slug' => 'icon',
        ),
        array(
            'name' => __('Icon List', 'blockspare'),
            'slug' => 'icon-list',
        ),
        array(
            'name' => __('Image Carousel', 'blockspare'),
            'slug' => 'image-carousel',
        ),
        array(
            'name' => __('Image Masonry', 'blockspare'),
            'slug' => 'image-masonry',
        ),
        array(
            'name' => __('Image Slider', 'blockspare'),
            'slug' => 'image-slider',
        ),
        array(
            'name' => __('Logo Grid', 'blockspare'),
            'slug' => 'logo-grid',
        ),
        array(
            'name' => __('Notice Bar', 'blockspare'),
            'slug' => 'noticebar',
        ),
        array(
            'name' => __('Posts Carosuel', 'blockspare'),
            'slug' => 'posts-carousel',
        ),
        array(
            'name' => __('Posts Grid', 'blockspare'),
            'slug' => 'posts-grid',
        ),
        array(
            'name' => __('Posts List', 'blockspare'),
            'slug' => 'posts-list',
        ),


        array(
            'name' => __('Price List', 'blockspare'),
            'slug' => 'price-list',
        ),

        array(
            'name' => __('Price Table', 'blockspare'),
            'slug' => 'price-table',
        ),
        array(
            'name' => __('Progress Bar', 'blockspare'),
            'slug' => 'progress-bar',
        ),
        array(
            'name' => __('Section Header', 'blockspare'),
            'slug' => 'section-header',
        ),
        array(
            'name' => __('Services', 'blockspare'),
            'slug' => 'services',
        ),
        array(
            'name' => __('Shape Divider', 'blockspare'),
            'slug' => 'shape-divider',
        ),
        array(
            'name' => __('Social Links', 'blockspare'),
            'slug' => 'social-links',
        ),
        array(
            'name' => __('Social Sharing', 'blockspare'),
            'slug' => 'social-sharing',
        ),
        array(
            'name' => __('Star Rating', 'blockspare'),
            'slug' => 'star-rating',
        ),
        array(
            'name' => __('Tabs', 'blockspare'),
            'slug' => 'tabs',
        ),
        array(
            'name' => __('Testimonial', 'blockspare'),
            'slug' => 'testimonial',
        ),
        array(
            'name' => __('User Profile', 'blockspare'),
            'slug' => 'user-profile',
        )
    );


}
}