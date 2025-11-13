<?php
/**
 * Block list .
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if(!function_exists('elespare_admin_blocks_list')){
    function elespare_admin_blocks_list(){
        return $elespare_blocks = array(

            array(
                'name' => __('Main Banner', 'elespare'),
                'slug' => 'post-banner',

            ),

            array(
                'name' => __('Main Banner 2', 'elespare'),
                'slug' => 'post-banner-2',

            ),

            array(
                'name' => __('Hero Banner 1', 'elespare'),
                'slug' => 'post-banner',

            ),
            array(
                'name' => __('Hero Banner 2', 'elespare'),
                'slug' => 'post-banner-2',
            ),

            array(
                'name' => __('Express Posts List', 'elespare'),
                'slug' => 'express-posts-list',
            ),

            array(
                'name' => __('Express Posts Grid', 'elespare'),
                'slug' => 'express-posts-grid',
            ),
            array(
                'name' => __('Posts Carousel', 'elespare'),
                'slug' => 'post-carousel',
            ),
            array(
                'name' => __('Posts Trending Carousel', 'elespare'),
                'slug' => 'post-trending-carousel',
            ),
            array(
                'name' => __('Tabbed Posts Grid', 'elespare'),
                'slug' => 'posts-tabbed',
            ),
            array(
                'name' => __('Posts Tile ', 'elespare'),
                'slug' => 'posts-tile',
            ),
            array(
                'name' => __('Posts Masonry', 'elespare'),
                'slug' => 'posts-masonry',
            ),
            array(
                'name' => __('Posts Grid', 'elespare'),
                'slug' => 'posts-grid',
            ),
            array(
                'name' => __('Posts List', 'elespare'),
                'slug' => 'posts-list',
            ),
            array(
                'name' => __('Posts Full', 'elespare'),
                'slug' => 'posts-full',
            ),
            array(
                'name' => __('Posts Single Column', 'elespare'),
                'slug' => 'posts-single-column',
            ),
            array(
                'name' => __('Posts Slider', 'elespare'),
                'slug' => 'posts-slider',
            ),
            array(
                'name' => __('Posts Timeline', 'elespare'),
                'slug' => 'posts-timeline',
            ),
            array(
                'name' => __('Posts Author', 'elespare'),
                'slug' => 'posts-author',
            ),
            array(
                'name' => __('Posts Flash (Ticker)', 'elespare'),
                'slug' => 'posts-flash',
            ),
            array(
                'name' => __('Social Link', 'elespare'),
                'slug' => 'social-link',
            ),

        );


    }
}


if(!function_exists('elespare_admin_templates_list')){
    function elespare_admin_templates_list(){
        return $elespare_blocks = array(

            array(
                'name' => __('General', 'elespare'),
                'slug' => 'general',

            ),

            array(
                'name' => __('Sport', 'elespare'),
                'slug' => 'sport',

            ),

            array(
                'name' => __('Fashion', 'elespare'),
                'slug' => 'fashion',

            ),
            array(
                'name' => __('Recipe', 'elespare'),
                'slug' => 'recipe',

            ),
            array(
                'name' => __('Classic', 'elespare'),
                'slug' => 'classic',

            ),
            array(
                'name' => __('Health', 'elespare'),
                'slug' => 'covid',

            ),
            array(
                'name' => __('Masonry', 'elespare'),
                'slug' => 'masonry',

            ),
            array(
                'name' => __('Dark', 'elespare'),
                'slug' => 'dark',

            ),
            array(
                'name' => __('City', 'elespare'),
                'slug' => 'city',

            ),
            array(
                'name' => __('Gadgets', 'elespare'),
                'slug' => 'gadgets',

            ),
            array(
                'name' => __('Real Estate', 'elespare'),
                'slug' => 'estate',

            ),
            array(
                'name' => __('Automobile', 'elespare'),
                'slug' => 'automobile',

            ),
            array(
                'name' => __('Travel', 'elespare'),
                'slug' => 'travel',

            ),
            array(
                'name' => __('Influencer', 'elespare'),
                'slug' => 'influencer',

            ),
            array(
                'name' => __('Gaming', 'elespare'),
                'slug' => 'gaming',

            ),

        );


    }
}