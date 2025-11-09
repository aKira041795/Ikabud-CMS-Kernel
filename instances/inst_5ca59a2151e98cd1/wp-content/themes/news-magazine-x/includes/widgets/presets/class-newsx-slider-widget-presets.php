<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Slider_Widget_Presets {
    private $instance;
    private $post_index;
    private $post_count;
    private $location;

    public function __construct( $instance) {
        $this->instance = $instance;

        $this->instance['layout'] = isset ( $this->instance['layout'] ) ? $this->instance['layout'] : '1-column';
        $this->instance['elements_preset'] = isset ( $this->instance['elements_preset'] ) ? $this->instance['elements_preset'] : 's0';
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

            case 's3':
                $this->elements_preset_s3();
                break;

            default:
                $this->elements_preset_default();
                break;
        }

        // Get Post Template
        get_template_part( 'includes/widgets/loop/slider-post', '', [ 'instance' => $this->instance ] );
    }

    public function elements_preset_s1() {
        $this->instance['_el_locations'] = [
            'over' => [
                'categories',
                'title',
                'date',
            ]
        ];
    }

    public function elements_preset_s2() {
    }

    public function elements_preset_s3() {
    }

    public function elements_preset_default() {
        $this->instance['_el_locations'] = [
            'over' => [
                'categories',
                'title',
                'excerpt',
                'meta',
                'read-more'
            ]
        ];
    }
}