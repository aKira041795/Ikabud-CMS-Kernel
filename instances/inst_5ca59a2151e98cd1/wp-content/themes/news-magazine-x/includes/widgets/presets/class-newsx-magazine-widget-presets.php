<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Magazine_Widget_Presets {
    private $instance;
    private $post_index;
    private $post_count;

    public function __construct( $instance, $post_index, $post_count ) {
        $this->instance = $instance;
        $this->instance['_post_index'] = $post_index;
        $this->instance['_post_count'] = $post_count;
        $this->instance['excerpt_letter_count'] = isset ( $this->instance['excerpt_letter_count'] ) ? $this->instance['excerpt_letter_count'] : 250;
    }

    // Display
    public function display() {
        $preset = isset($this->instance['elements_preset']) ? $this->instance['elements_preset'] : 's0';
        
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
        get_template_part( 'includes/widgets/loop/magazine-post', '', [ 'instance' => $this->instance ] );
    }

    public function elements_preset_s1() {
        // Featured Posts
        if ( $this->has_featured_post() ) {
            $this->instance['_el_locations'] = [
                'over' => [
                    'categories',
                    'title',
                    'excerpt',
                    'meta',
                ],
            ];
        
        // Secondary Posts
        } else {
            $this->instance['_el_locations'] = [
                'over' => [
                    'title',
                    'date',
                ],
            ];
        }
    }

    public function elements_preset_s2() {
        $this->instance['_el_locations'] = [
            'over' => [
                'title',
                'date',
            ],
        ];
    }
    
    public function elements_preset_default() {
        // Featured Posts
        if ( $this->has_featured_post() ) {
            $this->instance['_el_locations'] = [
                'over' => [
                    'categories',
                    'title',
                    'excerpt',
                    'meta',
                ],
            ];
        
        // Secondary Posts
        } else {
            $this->instance['_el_locations'] = [
                'over' => [
                    'categories',
                    'title',
                    'meta',
                ],
            ];
        }
    }

    public function has_featured_post() {
        $featured_post_layouts = [
            '1-2',
            '1-3',
            '1-4',
            '1-1-1',
            '1-1-2',
            '1-1-3',
            '2-1-2',
            '1vh-3h',
        ];

        return 1 === $this->instance['_post_index'] && in_array( $this->instance['layout'], $featured_post_layouts );
    }
}
