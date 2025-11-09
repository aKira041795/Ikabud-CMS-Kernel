<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_List_Widget_Presets {
    private $instance;
    private $post_index;
    private $post_count;
    private $location;

    public function __construct( $instance, $post_index, $post_count ) {
        $this->instance = $instance;
        $this->instance['_post_index'] = $post_index;
        $this->instance['_post_count'] = $post_count;

        $this->instance['layout'] = isset ( $this->instance['layout'] ) ? $this->instance['layout'] : 'list-4';
        $this->instance['elements_preset'] = isset ( $this->instance['elements_preset'] ) ? $this->instance['elements_preset'] : 's0';
        $this->location = $this->instance['layout'] === 'list-5' ? 'over' : 'below';
        
        $this->instance['title_tag'] = isset ( $this->instance['title_tag'] ) ? $this->instance['title_tag'] : 'h3';
        if ( 'list-7' === $this->instance['layout'] ) {
            $this->instance['title_tag'] = 'h5';
        }
        
        $this->instance['excerpt_letter_count'] = isset ( $this->instance['excerpt_letter_count'] ) ? $this->instance['excerpt_letter_count'] : 250;
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
        // Featured Posts
        if ( $this->has_featured_post() || $this->has_two_featured_posts() ) {
            $this->instance['_el_locations'] = [
                $this->location => [
                    'meta',
                    'title',
                    'excerpt',
                    'read-more'
                ],
                'over' => [
                    'categories'
                ]
            ];

        // Secondary Posts
        } else {
            $this->instance['_el_locations'] = [
                'below' => [
                    'date',
                    'title',
                ],
            ];
        }
    }

    public function elements_preset_s2() {
        if ( 'list-7' === $this->instance['layout'] ) {
            $this->instance['_el_locations'] = [
                $this->location => [
                    'title',
                    'excerpt',
                    'meta',
                ],
                'over' => [
                    'categories'
                ]
            ];
        } else {
            // Featured Posts
            if ( $this->has_featured_post() || $this->has_two_featured_posts() ) {
                $this->instance['_el_locations'] = [
                    $this->location => [
                        'title',
                        'excerpt',
                        'meta',
                    ],
                    'over' => [
                        'categories'
                    ]
                ];

            // Secondary Posts
            } else {
                $this->instance['_el_locations'] = [
                    'below' => [
                        'title',
                        'date',
                    ],
                ];
            }
        }

    }

    public function elements_preset_default() {
        // Featured Posts
        if ( $this->has_featured_post() || $this->has_two_featured_posts() ) {
            $this->instance['_el_locations'] = [
                $this->location => [
                    'title',
                    'excerpt',
                    'meta',
                ]
            ];

            if ( 'over' === $this->location ) {
                array_unshift($this->instance['_el_locations'][$this->location], 'categories');
            } else {
                $this->instance['_el_locations']['over'] = ['categories'];
            }

        // Secondary Posts
        } else {
            $this->instance['_el_locations'] = [
                'below' => [
                    'title',
                    'date',
                ],
            ];
        }
    }

    public function has_featured_post() {
        $featured_post_layouts = [
            'list-1',
            'list-2',
            'list-3',
            'list-4',
            'list-5',
        ];

        return 1 === $this->instance['_post_index'] && in_array( $this->instance['layout'], $featured_post_layouts );
    }

    public function has_two_featured_posts() {
        return 'list-6' === $this->instance['layout'] && (1 === $this->instance['_post_index'] || 2 === $this->instance['_post_index'] ); ;
    }
}