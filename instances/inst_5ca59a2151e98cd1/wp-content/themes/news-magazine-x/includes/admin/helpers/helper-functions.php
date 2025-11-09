<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Get Default Section Tabs.
*/
function newsx_get_section_tabs() {
	return [
        'general' => [
            'label' => esc_html__( 'General', 'news-magazine-x' ),
        ],
        'design'  => [
            'label' => esc_html__( 'Design', 'news-magazine-x' ),
        ],
    ];
}

/*
** Get Header/Footer Builder Elements
*/

function newsx_get_available_elements_array( $component = 'header' ) {
    if ( 'header' === $component ) {
        $elements = [
            'site-identity' => esc_html__( 'Logo / Site Title', 'news-magazine-x' ),
            'primary-menu' => esc_html__( 'Primary Menu', 'news-magazine-x' ),
            'secondary-menu' => esc_html__( 'Secondary Menu', 'news-magazine-x' ),
            'date-and-time'  => esc_html__( 'Date and Time', 'news-magazine-x' ),
            'news-ticker' => esc_html__( 'News Ticker', 'news-magazine-x' ),
            'social-icons' => esc_html__( 'Social Icons', 'news-magazine-x' ),
            'search' => esc_html__( 'Search', 'news-magazine-x' ),
            'offcanvas' => esc_html__( 'Off-Canvas', 'news-magazine-x' ),
            'random-post' => esc_html__( 'Random Post', 'news-magazine-x' ),
            'dark-switcher' => esc_html__( 'Dark Mode Switcher', 'news-magazine-x' ),
        ];
    } elseif ( 'footer' === $component ) {
        $elements = [
            'logo' => esc_html__( 'Logo', 'news-magazine-x' ),
            'footer-menu' => esc_html__( 'Footer Menu', 'news-magazine-x' ),
            'social-icons' => esc_html__( 'Social Icons', 'news-magazine-x' ),
            'custom-html-1' => esc_html__( 'Custom HTML 1', 'news-magazine-x' ),
            'custom-html-2' => esc_html__( 'Custom HTML 2', 'news-magazine-x' ),
            'footer-widgets-1' => esc_html__( 'Widgets Area 1', 'news-magazine-x' ),
            'footer-widgets-2' => esc_html__( 'Widgets Area 2', 'news-magazine-x' ),
            'footer-widgets-3' => esc_html__( 'Widgets Area 3', 'news-magazine-x' ),
            'footer-widgets-4' => esc_html__( 'Widgets Area 4', 'news-magazine-x' ),
        ];
    }
    
    if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
        return Newsx_Core_Pro_Helpers::get_available_elements_array( $component );
    }
    
    return $elements;
}

/*
** Get HTML Tag Control Options
*/
function newsx_get_html_tag_options() {
    return [
        'h1' => esc_html__( 'H1', 'news-magazine-x' ),
        'h2' => esc_html__( 'H2', 'news-magazine-x' ),
        'h3' => esc_html__( 'H3', 'news-magazine-x' ),
        'h4' => esc_html__( 'H4', 'news-magazine-x' ),
        'h5' => esc_html__( 'H5', 'news-magazine-x' ),
        'h6' => esc_html__( 'H6', 'news-magazine-x' ),
        'div' => esc_html__( 'Div', 'news-magazine-x' ),
        'span' => esc_html__( 'Span', 'news-magazine-x' ),
        'p' => esc_html__( 'P', 'news-magazine-x' ),
    ];
}

/*
** Get Alignment Control Options
*/
function newsx_get_align_control_options( $type = 'text' ) {
    if ( 'text' === $type ) {
        $options = [
            'left' => '<span class="dashicons dashicons-editor-alignleft"></span>',
            'center' => '<span class="dashicons dashicons-editor-aligncenter"></span>',
            'right' => '<span class="dashicons dashicons-editor-alignright"></span>',
        ];
    } elseif ( 'flex' === $type ) {
        $options = [
            'flex-start' => '<span class="dashicons dashicons-editor-alignleft"></span>',
            'center' => '<span class="dashicons dashicons-editor-aligncenter"></span>',
            'flex-end' => '<span class="dashicons dashicons-editor-alignright"></span>',
        ];
    }

    return $options;
}

/*
** Get SVG Icons Data
** Use wp_safe_remote_get for Kirki repeater_js_template function
*/
function newsx_get_repeater_svg_icons_data( $format = 'array' ) {
    $icons_data = [];

    // Specify the URL of the JSON file.
    $json_file_url = esc_url(NEWSX_ASSETS_URL .'/svg/svg-icons.json');

    // Make the HTTP request to fetch the JSON content.
    $response = wp_safe_remote_get($json_file_url);

    // Check if the request was successful.
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        // Get the JSON content from the response.
        $json_content = wp_remote_retrieve_body($response);

        if ( 'array' === $format ) {
            // Parse the JSON content into a PHP array.
            $json_data = json_decode($json_content, true);

            if ( $json_data !== null ) {
                $icons_data = $json_data;
            }
        } elseif ( 'json' === $format ) {
            $icons_data = $json_content;
        }
    }

    // Return an empty array if something went wrong.
    return $icons_data;
}

/*
** Get Taxonomies Choices
*/
function newsx_get_taxonomy_choices() {
    $taxonomies = get_taxonomies([
        'public' => true
    ], 'objects' );
    
    $taxonomies_array = [];

    if ( !empty($taxonomies) && !is_wp_error($taxonomies) ) {
        foreach ( $taxonomies as $term ) {
            if ( 'post_format' === $term->name ) continue;
            $taxonomies_array[(string) $term->name] = $term->label;
        }
    }

    return $taxonomies_array;
}

/*
** Get Selected Taxonomy Choices
*/
function newsx_get_taxonomy_term_choices( $taxonomy ) {
    $taxonomy = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ]);

    $taxonomy_array = [];

    if ( !empty($taxonomy) && !is_wp_error($taxonomy) ) {
        foreach ( $taxonomy as $term ) {
            $taxonomy_array[(string) $term->term_id] = $term->name;
        }
    }

    return $taxonomy_array;
}

/*
** Get Image Sizes
*/
function newsx_get_image_sizes() {
    $image_sizes = [
        'full' => esc_html('Full', 'news-magazine-x'),
        'thumbnail' => esc_html('Thumbnail', 'news-magazine-x'),
        'medium' => esc_html('Medium', 'news-magazine-x'),
        'medium_large' => esc_html('Medium Large', 'news-magazine-x'),
        'large' => esc_html('Large', 'news-magazine-x'),
        'newsx-150x100' => esc_html('Crop Size 150x100', 'news-magazine-x'),
        'newsx-330x220' => esc_html('Crop Size 330x220', 'news-magazine-x'),
        'newsx-420x280' => esc_html('Crop Size 420x280', 'news-magazine-x'),
        'newsx-510x340' => esc_html('Crop Size 510x340', 'news-magazine-x'),
        'newsx-640x480' => esc_html('Crop Size 640x480', 'news-magazine-x'),
        'newsx-670x370' => esc_html('Crop Size 670x370', 'news-magazine-x'),
        'newsx-860x570' => esc_html('Crop Size 860x570', 'news-magazine-x'),
        'newsx-1000x750' => esc_html('Crop Size 1000x750', 'news-magazine-x'),
        'newsx-1200x600' => esc_html('Crop Size 1200x600', 'news-magazine-x'),
    ];

    // Return array of image sizes
    return $image_sizes;
}

/*
** Get Orderby Query Choices
*/
function newsx_get_orderby_query_choices( $exclude = [] ) {
    $choices = [
        'date' => esc_html__( 'Published Date', 'news-magazine-x' ),
        'title' => esc_html__( 'Post Title', 'news-magazine-x' ),
        'ID' => esc_html__( 'Post ID', 'news-magazine-x' ),
        'author' => esc_html__( 'Post Author', 'news-magazine-x' ),
    ];

    if ( ! empty( $exclude ) ) {
        foreach ( $exclude as $key ) {
            unset( $choices[ $key ] );
        }
    }

    if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
        return Newsx_Core_Pro_Helpers::get_orderby_query_choices( $exclude );
    }

    return $choices;
}

/*
** Check if Plugin is Installed
*/
if ( ! function_exists( 'newsx_is_plugin_installed' ) ) {
	function newsx_is_plugin_installed( $plugin ) {
		// Include plugin.php to get access to get_plugins()
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		
		$file_path = $plugin .'/'. $plugin .'.php';
		$installed_plugins = get_plugins();

		return isset( $installed_plugins[ $file_path ] );
	}
}

/*
** Get Pro Control
*/
if ( ! function_exists( 'newsx_add_pro_field' ) ) {
    function newsx_add_pro_field( $setting, $priority ) {
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $newsx_pro_options = new Newsx_Customizer_Pro_Options();

            Kirki::add_field( 'newsx_theme_config', $newsx_pro_options->get_pro_control( $setting, $priority ) );
        }
    }
}

/*
** Get Pro Control Args
*/
if ( ! function_exists( 'newsx_get_pro_field_args' ) ) {
    function newsx_get_pro_field_args( $setting ) {
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $newsx_pro_options = new Newsx_Customizer_Pro_Options();

            return $newsx_pro_options->get_pro_control_args( $setting );
        }

        return [];
    }
}

/*
** Get Pro Controls Group
*/
function newsx_add_pro_controls_group( $element ) {
    if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
        $newsx_pro_options = new Newsx_Customizer_Pro_Options();
        $newsx_pro_options->get_pro_controls_group( $element );

        foreach ( $newsx_pro_options->get_pro_controls_group( $element ) as $control ) {
            Kirki::add_field( 'newsx_theme_config', $control );
        }
    }
}