<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Grid_Widget_Presets {
    private $instance;
    private $post_index;
    private $post_count;
    private $location;

    public function __construct( $instance, $post_index, $post_count ) {
        $this->instance = $instance;
        $this->instance['_post_index'] = $post_index;
        $this->instance['_post_count'] = $post_count;

        $this->instance['layout'] = isset ( $this->instance['layout'] ) ? $this->instance['layout'] : '2-column';
        $this->instance['elements_preset'] = isset ( $this->instance['elements_preset'] ) ? $this->instance['elements_preset'] : 's0';
        $this->instance['title_tag'] = isset ( $this->instance['title_tag'] ) ? $this->instance['title_tag'] : 'h3';
        $this->instance['excerpt_letter_count'] = isset ( $this->instance['excerpt_letter_count'] ) ? $this->instance['excerpt_letter_count'] : 230;    
    }

    // Display
    public function display() {
        $preset = $this->instance['elements_preset'];
        
        // Switch Presets
        switch ( $preset ) {
            case 's1':
                $this->elements_preset_s1();
                break;

            case 's2':
                $this->elements_preset_s2();
                break;

            default:
                $this->elements_preset_default();
                break;
        }

        // Get Post Template
        get_template_part( 'includes/widgets/loop/grid-post', '', [ 'instance' => $this->instance ] );
    }

    public function elements_preset_s1() {
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

    public function elements_preset_s2() {
        $this->instance['_el_locations'] = [
            'over' => [
                'categories',
            ],
            'below' => [
                'title',
                'date',
            ]
        ];
    }

    public function elements_preset_default() {
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
}