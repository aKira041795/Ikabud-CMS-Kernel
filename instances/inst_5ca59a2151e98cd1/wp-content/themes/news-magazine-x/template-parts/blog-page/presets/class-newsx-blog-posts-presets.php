<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Blog_Posts_Presets {
    private $instance;

    public function __construct() {
        $this->instance = [
            'layout' => newsx_get_option('bp_feed_layout'),
            'elements_preset' => newsx_get_option('bp_feed_elements_preset'),
            'title_tag' => newsx_get_option('bp_feed_title_tag'),
            'title_letter_count' => newsx_get_option('bp_feed_title_letter_count'),
            'excerpt_letter_count' => newsx_get_option('bp_feed_excerpt_letter_count'),
            'date_format' => newsx_get_option('bp_feed_date_format'),
            'date_show_time' => newsx_get_option('bp_feed_date_show_time'),
            'author_show_avatar' => newsx_get_option('bp_feed_show_avatar'),
            'author_avatar_size' => newsx_get_option('bp_feed_avatar_size'),
            'read_more_text' => newsx_get_option('bp_feed_read_more_text'),
            'image_link' => newsx_get_option('bp_feed_image_link'),
        ];

        if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
            $this->instance['layout'] = 'list-7';
            $this->instance['elements_preset'] = 's0';
            $this->instance['excerpt_letter_count'] = 200;
            $this->instance['date_format'] = 'default';
            $this->instance['date_show_time'] = false;
            $this->instance['author_show_avatar'] = false;
        }

        $this->instance['elements_preset'] = isset ( $this->instance['elements_preset'] ) ? $this->instance['elements_preset'] : 's0';
    }

    // Display
    public function display() {
        $preset = $this->instance['elements_preset'];
        
        // Switch Presets
        switch ( $preset ) {
            case 's1':
                if (str_contains($this->instance['layout'], 'list')) {
                    $this->elements_preset_default(); 
                } else {
                    $this->elements_preset_s1();
                }
                break;

            case 's2':
                $this->elements_preset_s2();
                break;

            default:
                $this->elements_preset_default();
                break;
        }

        // Get Post Template
        get_template_part( 'template-parts/blog-page/post', '', [ 'instance' => $this->instance ] );
    }

    public function elements_preset_s1() {
        $this->instance['_el_locations'] = [
            'above' => [
                'title',
            ],
            'below' => [
                'categories',
                'excerpt',
                'meta',
                'read-more'
            ]
        ];
    }

    public function elements_preset_s2() {
        $this->instance['_el_locations'] = [
            'over' => [
                'categories',
            ],
            'below' => [
                'title',
                'excerpt',
                'meta',
                'read-more'
            ]
        ];
    }

    public function elements_preset_default() {
        $this->instance['_el_locations'] = [
            'below' => [
                'categories',
                'title',
                'excerpt',
                'meta',
                'read-more'
            ]
        ];
    }
}