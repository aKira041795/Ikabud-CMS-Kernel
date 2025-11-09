<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Customizer Edit Button Markup.
*/
if ( ! function_exists( 'newsx_customizer_edit_button_markup' ) ) {
function newsx_customizer_edit_button_markup( $section = '' ) {
    $output = '';

    if ( 'title_tagline' !== $section && !str_contains($section, 'sidebar-widgets') ) {
        $section = 'newsx_section_'. $section;
    }

    if ( is_customize_preview() ) {
        $output .= '<div class="customize-partial-edit-shortcut newsx-customize-edit" data-section="'. esc_attr($section) .'">';
			$output .= '<button aria-label="' . esc_attr__( 'Click to edit this element.', 'news-magazine-x' ) . '" title="' . esc_attr__( 'Click to edit this element.', 'news-magazine-x' ) . '" class="item-customizer-focus">';
				$output .= newsx_get_svg_icon( 'edit' ); // Assuming this function returns safe HTML or is properly escaped
			$output .= '</button>';
        $output .= '</div>';
    }

    return $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
}

/*
** Render Header/Footer Element by Position
*/
if ( ! function_exists( 'newsx_hf_builder_grid_row_markup' ) ) {
function newsx_hf_builder_grid_row_markup($compontent, $row, $elements) {    
    // Set location
    $cmp_location = 'header' === $compontent ? 'hd_'. $row : 'ft_'. $row;

    if ( 'header' === $compontent ) {
        $section_content = [
            'left'   => '',
            'center' => '',
            'right'  => '',
        ];
        $positions_array = [ 'left', 'center', 'right' ];
    } else {
        $section_content = [
            '1'   => '',
            '2'  => '',
            '3'  => '',
            '4'  => '',
            '5'  => '',
            '6'  => '',
        ];
        $positions_array = [ '1','2','3','4','5','6' ];
    }

    $used_positions = [];
    $wrapper_class = '';
    
    foreach ($elements as $element) {
        $position = esc_attr($element[$cmp_location .'_element_position']);
        $template_name = esc_attr($element[$cmp_location .'_select_element']);
        $template_path = 'template-parts/'. $compontent .'/elements/'. $template_name;
        $is_duplicate = false;

        if ( 'header' === $compontent && isset($element[$cmp_location .'_element_duplicate']) && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $is_duplicate = $element[$cmp_location .'_element_duplicate'];
        }

        // Capture template content
        ob_start();

        if ( locate_template($template_path .'.php') ) {
            get_template_part($template_path, '', ['is_duplicate' => $is_duplicate]);
        } elseif ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $pro_template = NEWSX_CORE_PRO_PATH . 'public/template-parts/' . $compontent .'/'. $template_name . '.php';
            if ( file_exists($pro_template) ) {
                load_template($pro_template, false, ['is_duplicate' => $is_duplicate]);
            }
        }

        $template_content = ob_get_clean();

        $section_content[$position] .= $template_content;
        $used_positions[] = $position;
    }

    // Filter out duplicate positions
    $used_positions = array_unique($used_positions);
    $positions_count = count($used_positions);

    // Get Footer Options
    $footer_layout = 'equal'; // TODO: get layout from theme options
    $footer_inner_layout = 'stack';
    $footer_columns = (int)newsx_get_option('section_'. $cmp_location .'_columns');


    // Add class to wrapper
    if ( 'header' === $compontent ) {
        if ( in_array('left', $used_positions) ) {
            if ( $positions_count == 1 ) {
                $wrapper_class .= ' newsx-builder-grid-row-one-column';
            }
        }

        if ( in_array('center', $used_positions) ) {
            if ( $positions_count == 1 ) {
                $wrapper_class .= ' newsx-builder-grid-row-only-center';
            }
        
            if ( $positions_count >= 2 ) {
                $wrapper_class .= ' newsx-builder-grid-row-has-center';
            }
        } else {
            $wrapper_class .= ' newsx-builder-grid-row-no-center';
        }

        $wrapper_class .= 'boxed' === newsx_get_option('global_header_width') ? ' newsx-container' : '';

    // Footer
    } else {
        if ( 1 == $footer_columns ) {
            $wrapper_class .= ' newsx-builder-grid-row-full';
        } else {
            $wrapper_class .= ' newsx-builder-grid-row-'. $footer_columns .'-'. $footer_layout;
        }

        $wrapper_class .= ' newsx-builder-grid-row-group-'. $footer_inner_layout;
        $wrapper_class .= 'boxed' === newsx_get_option('global_footer_width') ? ' newsx-container' : '';
        
    }

    // Render Grid Row
    if ( $positions_count > 0 ) {
        $should_stick = 'header' === $compontent && newsx_get_option('section_'. $cmp_location .'_sticky') && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? true : false;
        $sticky_attr = $should_stick ? ' data-sticky="yes"' : '';

        echo '<div class="newsx-'. esc_attr($row) .'-section-wrap newsx-section-wrap" data-section="newsx_section_'. esc_attr($cmp_location) .'"'. $sticky_attr .'>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            // Customizer Edit Button
            echo newsx_customizer_edit_button_markup($cmp_location);

            // Render Grid Row
            echo '<div class="newsx-builder-grid-row' . esc_attr($wrapper_class) . '">';

            foreach ( $positions_array as $position ) {
                $content = $section_content[$position];

                // if $footer_columns is less than current positions, then skip
                if ( 'footer' === $compontent && $footer_columns < (int)$position ) {
                    continue;
                }

                $show_in_header = empty($content) && $position !== 'center' && 'header' === $compontent;
                $show_in_footer = empty($content) && 'footer' === $compontent;

                // Render Columns
                if ( !empty($content) || $show_in_header || $show_in_footer ) {
                    echo '<div class="newsx-grid-column-' . esc_attr($position) . ' site-'. esc_attr($compontent) .'-column newsx-flex">';
                        // Content is retrieved by get_template_part() function above.
                        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo '</div>';
                }
            }

            echo '</div>';
        echo '</div>';
    }
}
}

/*
** Menu Fallback
*/
if ( ! function_exists( 'newsx_menu_fallback' ) ) {
function newsx_menu_fallback() {
    if ( current_user_can( 'edit_theme_options' ) ) {
        echo '<ul class="newsx-nav-menu newsx-flex">';
            if ( is_customize_preview() ) {
                echo '<li class="menu-item newsx-setup-menu-link"><a href="#">'. esc_html__( 'Set up menu', 'news-magazine-x' ) .'</a></li>';
            } else {
                echo '<li class="menu-item"><a href='. esc_url( admin_url( 'nav-menus.php' ) ) .'>'. esc_html__( 'Set up menu', 'news-magazine-x' ) .'</a></li>';
            }
        echo '</ul>';
    }
}
}

/*
** Theme Default Icons Markup
*/
if ( ! function_exists( 'newsx_default_icon_markup' ) ) {
function newsx_default_icon_markup( $icon, $is_echo = false ) {
    switch ( $icon ) {
        case 'home':
            $output = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M280.37 148.26L96 300.11V464a16 16 0 0 0 16 16l112.06-.29a16 16 0 0 0 15.92-16V368a16 16 0 0 1 16-16h64a16 16 0 0 1 16 16v95.64a16 16 0 0 0 16 16.05L464 480a16 16 0 0 0 16-16V300L295.67 148.26a12.19 12.19 0 0 0-15.3 0zM571.6 251.47L488 182.56V44.05a12 12 0 0 0-12-12h-56a12 12 0 0 0-12 12v72.61L318.47 43a48 48 0 0 0-61 0L4.34 251.47a12 12 0 0 0-1.6 16.9l25.5 31A12 12 0 0 0 45.15 301l235.22-193.74a12.19 12.19 0 0 1 15.3 0L530.9 301a12 12 0 0 0 16.9-1.6l25.5-31a12 12 0 0 0-1.7-16.93z"></path></svg>';
            break;

        case 'sub-menu':
            $output = '<svg class="newsx-svg" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" x="0px" y="0px" width="26px" height="16.043px" viewBox="57 35.171 26 16.043" enable-background="new 57 35.171 26 16.043" xml:space="preserve"><path d="M57.5,38.193l12.5,12.5l12.5-12.5l-2.5-2.5l-10,10l-10-10L57.5,38.193z"/></svg>';
            break;

        case 'search':
            $output  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" x="0px" y="0px" viewBox="-888 480 142 142" enable-background="new -888 480 142 142" xml:space="preserve"><path d="M-787.4,568.7h-6.3l-2.4-2.4c7.9-8.7,12.6-20.5,12.6-33.1c0-28.4-22.9-51.3-51.3-51.3  c-28.4,0-51.3,22.9-51.3,51.3c0,28.4,22.9,51.3,51.3,51.3c12.6,0,24.4-4.7,33.1-12.6l2.4,2.4v6.3l39.4,39.4l11.8-11.8L-787.4,568.7  L-787.4,568.7z M-834.7,568.7c-19.7,0-35.5-15.8-35.5-35.5c0-19.7,15.8-35.5,35.5-35.5c19.7,0,35.5,15.8,35.5,35.5  C-799.3,553-815,568.7-834.7,568.7L-834.7,568.7z"></path></svg>';
            $output .= '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M5.293 6.707l5.293 5.293-5.293 5.293c-0.391 0.391-0.391 1.024 0 1.414s1.024 0.391 1.414 0l5.293-5.293 5.293 5.293c0.391 0.391 1.024 0.391 1.414 0s0.391-1.024 0-1.414l-5.293-5.293 5.293-5.293c0.391-0.391 0.391-1.024 0-1.414s-1.024-0.391-1.414 0l-5.293 5.293-5.293-5.293c-0.391-0.391-1.024-0.391-1.414 0s-0.391 1.024 0 1.414z"></path></svg>';
            break;
        
        default:
            $output = '';
            break;
    }

    $output = sprintf(
        '<span class="%1$s"%2$s>%3$s</span>',
        implode( ' ', ['newsx-svg-icon', $icon .'-icon'] ),
        'search' === $icon ? ' tabindex="0"' : '',
        $output
    );

    if ( $is_echo ) {
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        return $output;
    }
}
}

/*
** Social Icons Markup
*/
if ( ! function_exists( 'newsx_social_icons_markup' ) ) {
function newsx_social_icons_markup( $location = 'header', $class = '' ) {
    $social_icons = newsx_get_option($location .'_social_icons');
    $show_tooltips = newsx_get_option($location .'_si_tooltips');
    $section = 'header' === $location ? 'hd_social_icons' : 'ft_social_icons';

    echo '<div class="newsx-social-icons newsx-'. esc_attr($location) .'-social-icons newsx-flex'. esc_attr($class) .'">';

    // Edit Button
    echo newsx_customizer_edit_button_markup($section);

    if ( !empty($social_icons) ) {
        foreach ( $social_icons as $social_icon ) {
            $icon = $social_icon[$location .'_social_icons_select'];
            $link = $social_icon[$location .'_social_icons_url'];
            $label = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? $social_icon[$location .'_social_icons_label'] : '';
            $tooltip_attrs = $show_tooltips ? ' data-tooltip="'. esc_attr($label) .'" data-gravity="s"' : ' title="'. esc_attr($label) .'"';

            if ( !empty($icon) && !empty($link) ) {
                echo '<a href="'. esc_url($link) .'" class="newsx-social-icon newsx-social-icon-'. esc_attr($icon) .'" target="_blank" rel="noopener noreferrer"'. $tooltip_attrs .'>';
                    echo newsx_get_svg_icon($icon);
                    
                    echo ('' !== $label && !$show_tooltips) ? '<span>'. esc_html($label) .'</span>' : '';
                echo '</a>';
            }
        }

        echo '</div>';
    }
}
}

/*
** Weather Icon Markup
*/
if ( ! function_exists( 'newsx_weather_icon_markup' ) ) {
function newsx_weather_icon_markup( $name = '', $id = 'weather' ) {
	switch ( $name ) {
		case 'cloudy'   :
			echo '<svg class="svg-icon svg-cloudy" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M41.8 20.25l4.48 6.61.22 4.64 5.31 2.45 1.69 5.97h8.08L61 27l-9.31-8.5-9.89 1.75z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><path fill="none" stroke="#9ca3af" stroke-linejoin="round" stroke-width="2" d="M34.23 33.45a4.05 4.05 0 004.05 4h16.51a4.34 4.34 0 00.81-8.61 3.52 3.52 0 00.06-.66 4.06 4.06 0 00-6.13-3.48 6.08 6.08 0 00-11.25 3.19 6.34 6.34 0 00.18 1.46h-.18a4.05 4.05 0 00-4.05 4.1z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-2.1 0; 2.1 0; -2.1 0"/></g><g><path fill="none" stroke="#dddddd" stroke-linejoin="round" stroke-width="3" d="M46.5 31.5h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0h28a7 7 0 000-14z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-3 0; 3 0; -3 0"/></g></svg>';
            break;
		case 'day-cloudy'     :
			echo '<svg class="svg-icon svg-day-cloudy" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-8-1-1-10 2-8 5-4 4.72-2.21h6L29 10l4 3v7l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath><clipPath id="' . esc_attr($id) . '-2"><path fill="none" d="M41.8 20.25l4.48 6.61.22 4.64 5.31 2.45 1.69 5.97h8.08L61 27l-9.31-8.5-9.89 1.75z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><g><path fill="#f59e0b" d="M19 20.05A3.95 3.95 0 1115.05 24 4 4 0 0119 20.05m0-2A5.95 5.95 0 1025 24a5.95 5.95 0 00-6-5.95z"/><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M19 15.67V12.5M19 35.5v-3.17M24.89 18.11l2.24-2.24M10.87 32.13l2.24-2.24M13.11 18.11l-2.24-2.24M27.13 32.13l-2.24-2.24M10.67 24H7.5M30.5 24h-3.17"/><animateTransform attributeName="transform" dur="45s" from="0 19.22 24.293" repeatCount="indefinite" to="360 19.22 24.293" type="rotate"/></g><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="3 0; -3 0; 3 0"/></g><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-3 0; 3 0; -3 0"/></g><g clip-path="url(#' . esc_attr($id) . '-2)"><path fill="none" stroke="#9ca3af" stroke-linejoin="round" stroke-width="2" d="M34.23 33.45a4.05 4.05 0 004.05 4h16.51a4.34 4.34 0 00.81-8.61 3.52 3.52 0 00.06-.66 4.06 4.06 0 00-6.13-3.48 6.08 6.08 0 00-11.25 3.19 6.34 6.34 0 00.18 1.46h-.18a4.05 4.05 0 00-4.05 4.1z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-2.1 0; 2.1 0; -2.1 0"/></g><g><path fill="none" stroke="#dddddd" stroke-linejoin="round" stroke-width="3" d="M46.5 31.5h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0h28a7 7 0 000-14z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-3 0; 3 0; -3 0"/></g></svg>';
            break;
		case 'day-rain'       :
			echo '<svg class="svg-icon svg-rain" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><path fill="#f59e0b" d="M19 20.05A3.95 3.95 0 1115.05 24 4 4 0 0119 20.05m0-2A5.95 5.95 0 1025 24a5.95 5.95 0 00-6-5.95z"/><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M19 15.67V12.5M19 35.5v-3.17M24.89 18.11l2.24-2.24M10.87 32.13l2.24-2.24M13.11 18.11l-2.24-2.24M27.13 32.13l-2.24-2.24M10.67 24H7.5M30.5 24h-3.17"/><animateTransform attributeName="transform" dur="45s" from="0 19.22 24.293" repeatCount="indefinite" to="360 19.22 24.293" type="rotate"/></g></g><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M24.08 45.01l-.16.98"/><animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M31.08 45.01l-.16.98"/><animateTransform attributeName="transform" begin="-0.5s" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-0.5s" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M38.08 45.01l-.16.98"/><animateTransform attributeName="transform" begin="-1s" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-1s" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g></svg>';
            break;
		case 'day-snow'       :
			echo '<svg class="svg-icon svg-snow" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><path fill="#f59e0b" d="M19 20.05A3.95 3.95 0 1115.05 24 4 4 0 0119 20.05m0-2A5.95 5.95 0 1025 24a5.95 5.95 0 00-6-5.95z"/><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M19 15.67V12.5M19 35.5v-3.17M24.89 18.11l2.24-2.24M10.87 32.13l2.24-2.24M13.11 18.11l-2.24-2.24M27.13 32.13l-2.24-2.24M10.67 24H7.5M30.5 24h-3.17"/><animateTransform attributeName="transform" dur="45s" from="0 19.22 24.293" repeatCount="indefinite" to="360 19.22 24.293" type="rotate"/></g></g><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><g><g><path fill="#72b8d4" d="M24.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 24 45; 360 24 45"/></g><animateTransform attributeName="transform" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g><g><g><g><path fill="#72b8d4" d="M31.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 31 45; 360 31 45"/></g><animateTransform attributeName="transform" begin="-2s" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" begin="-2s" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" begin="-2s" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g><g><g><g><path fill="#72b8d4" d="M38.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 38 45; 360 38 45"/></g><animateTransform attributeName="transform" begin="-1s" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" begin="-1s" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" begin="-1s" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g></svg>';
            break;
		case 'night-fog'      :
			echo '<svg class="svg-icon svg-night-fog" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><path fill="none" stroke="#72b9d5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M29.33 26.68a10.61 10.61 0 01-10.68-10.54A10.5 10.5 0 0119 13.5a10.54 10.54 0 1011.5 13.11 11.48 11.48 0 01-1.17.07z"/><animateTransform attributeName="transform" dur="10s" repeatCount="indefinite" type="rotate" values="-10 19.22 24.293;10 19.22 24.293;-10 19.22 24.293"/></g></g><path fill="none" stroke="#dddddd" stroke-linejoin="round" stroke-width="3" d="M46.5 31.5h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0h28a7 7 0 000-14z"/><g><path fill="none" stroke="#d1d5db" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M17 58h30"/><animateTransform attributeName="transform" begin="0s" dur="5s" repeatCount="indefinite" type="translate" values="-4 0; 4 0; -4 0"/></g><g><path fill="none" stroke="#d1d5db" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M17 52h30"/><animateTransform attributeName="transform" begin="-4s" dur="5s" repeatCount="indefinite" type="translate" values="-4 0; 4 0; -4 0"/></g></svg>';
            break;
		case 'night-alt-snow' :
			echo '<svg class="svg-icon svg-night-alt-snow" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><path fill="none" stroke="#72b9d5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M29.33 26.68a10.61 10.61 0 01-10.68-10.54A10.5 10.5 0 0119 13.5a10.54 10.54 0 1011.5 13.11 11.48 11.48 0 01-1.17.07z"/><animateTransform attributeName="transform" dur="10s" repeatCount="indefinite" type="rotate" values="-10 19.22 24.293;10 19.22 24.293;-10 19.22 24.293"/></g></g><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><g><g><path fill="#72b8d4" d="M24.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 24 45; 360 24 45"/></g><animateTransform attributeName="transform" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g><g><g><g><path fill="#72b8d4" d="M31.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 31 45; 360 31 45"/></g><animateTransform attributeName="transform" begin="-2s" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" begin="-2s" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" begin="-2s" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g><g><g><g><path fill="#72b8d4" d="M38.24 42.67l.24.68a.25.25 0 00.35.14l.65-.31a.26.26 0 01.34.34l-.31.65a.25.25 0 00.14.35l.68.24a.25.25 0 010 .48l-.68.24a.25.25 0 00-.14.35l.31.65a.26.26 0 01-.34.34l-.65-.31a.25.25 0 00-.35.14l-.24.68a.25.25 0 01-.48 0l-.24-.68a.25.25 0 00-.35-.14l-.65.31a.26.26 0 01-.34-.34l.31-.65a.25.25 0 00-.14-.35l-.68-.24a.25.25 0 010-.48l.68-.24a.25.25 0 00.14-.35l-.31-.65a.26.26 0 01.34-.34l.65.31a.25.25 0 00.35-.14l.24-.68a.25.25 0 01.48 0z"/><animateTransform attributeName="transform" dur="9s" repeatCount="indefinite" type="rotate" values="0 38 45; 360 38 45"/></g><animateTransform attributeName="transform" begin="-1s" dur="4s" repeatCount="indefinite" type="translate" values="-3 0; 3 0"/></g><animateTransform attributeName="transform" begin="-1s" dur="4s" repeatCount="indefinite" type="translate" values="2 -6; -2 12"/><animate attributeName="opacity" begin="-1s" dur="4s" repeatCount="indefinite" values="0;1;1;1;0"/></g></svg>';
            break;
		case 'night-cloudy'   :
			echo '<svg class="svg-icon svg-night-cloudy" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath><clipPath id="' . esc_attr($id) . '-2"><path fill="none" d="M41.8 20.25l4.48 6.61.22 4.64 5.31 2.45 1.69 5.97h8.08L61 27l-9.31-8.5-9.89 1.75z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><g><path fill="none" stroke="#72b9d5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M29.33 26.68a10.61 10.61 0 01-10.68-10.54A10.5 10.5 0 0119 13.5a10.54 10.54 0 1011.5 13.11 11.48 11.48 0 01-1.17.07z"/><animateTransform attributeName="transform" dur="10s" repeatCount="indefinite" type="rotate" values="-10 19.22 24.293;10 19.22 24.293;-10 19.22 24.293"/></g><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="3 0; -3 0; 3 0"/></g><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-3 0; 3 0; -3 0"/></g><g clip-path="url(#' . esc_attr($id) . '-2)"><path fill="none" stroke="#9ca3af" stroke-linejoin="round" stroke-width="2" d="M34.23 33.45a4.05 4.05 0 004.05 4h16.51a4.34 4.34 0 00.81-8.61 3.52 3.52 0 00.06-.66 4.06 4.06 0 00-6.13-3.48 6.08 6.08 0 00-11.25 3.19 6.34 6.34 0 00.18 1.46h-.18a4.05 4.05 0 00-4.05 4.1z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-2.1 0; 2.1 0; -2.1 0"/></g><g><path fill="none" stroke="#dddddd" stroke-linejoin="round" stroke-width="3" d="M46.5 31.5h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0h28a7 7 0 000-14z"/><animateTransform attributeName="transform" dur="7s" repeatCount="indefinite" type="translate" values="-3 0; 3 0; -3 0"/></g></svg>';
            break;
		case 'night-rain'     :
			echo '<svg class="svg-icon svg-night-rain" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><clipPath id="' . esc_attr($id) . '"><path fill="none" d="M12 35l-5.28-4.21-2-6 1-7 4-5 5-3h6l5 1 3 3L33 20l-6 4h-6l-3 3v4l-4 2-2 2z"/></clipPath></defs><g clip-path="url(#' . esc_attr($id) . ')"><g><path fill="none" stroke="#72b9d5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M29.33 26.68a10.61 10.61 0 01-10.68-10.54A10.5 10.5 0 0119 13.5a10.54 10.54 0 1011.5 13.11 11.48 11.48 0 01-1.17.07z"/><animateTransform attributeName="transform" dur="10s" repeatCount="indefinite" type="rotate" values="-10 19.22 24.293;10 19.22 24.293;-10 19.22 24.293"/></g></g><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M24.08 45.01l-.16.98"/><animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M31.08 45.01l-.16.98"/><animateTransform attributeName="transform" begin="-0.5s" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-0.5s" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M38.08 45.01l-.16.98"/><animateTransform attributeName="transform" begin="-1s" dur="1.5s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-1s" dur="1.5s" repeatCount="indefinite" values="0;1;1;0"/></g></svg>';
            break;
		case 'moon-full'      :
			echo '<svg class="svg-icon svg-moon-full" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><g><path fill="none" stroke="#72b9d5" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M46.66 36.2a16.66 16.66 0 01-16.78-16.55 16.29 16.29 0 01.55-4.15A16.56 16.56 0 1048.5 36.1c-.61.06-1.22.1-1.84.1z"/><animateTransform attributeName="transform" dur="10s" repeatCount="indefinite" type="rotate" values="-5 32 32;15 32 32;-5 32 32"/></g></svg>';
            break;
		case 'day-sunny'      :
			echo '<svg class="svg-icon svg-day-sunny" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><g><path fill="#f59e0b" d="M32 23.36A8.64 8.64 0 1123.36 32 8.66 8.66 0 0132 23.36m0-3A11.64 11.64 0 1043.64 32 11.64 11.64 0 0032 20.36z"/><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M32 15.71V9.5"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M32 48.29v6.21"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M43.52 20.48l4.39-4.39"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M20.48 43.52l-4.39 4.39"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M20.48 20.48l-4.39-4.39"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M43.52 43.52l4.39 4.39"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M15.71 32H9.5"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><path fill="none" stroke="#f59e0b" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M48.29 32h6.21"><animate attributeName="stroke-dasharray" calcMode="spline" dur="5s" keySplines="0.5 0 0.5 1; 0.5 0 0.5 1" keyTimes="0; .5; 1" repeatCount="indefinite" values="3 6; 6 6; 3 6"/></path><animateTransform attributeName="transform" dur="45s" from="0 32 32" repeatCount="indefinite" to="360 32 32" type="rotate"/></g></svg>';
            break;
		case 'day-fog'        :
			echo '<svg class="svg-icon svg-fog" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="none" stroke="#dddddd" stroke-linejoin="round" stroke-width="3" d="M46.5 31.5h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0h28a7 7 0 000-14z"/><g><path fill="none" stroke="#d1d5db" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M17 58h30"/><animateTransform attributeName="transform" begin="0s" dur="5s" repeatCount="indefinite" type="translate" values="-4 0; 4 0; -4 0"/></g><g><path fill="none" stroke="#d1d5db" stroke-linecap="round" stroke-miterlimit="10" stroke-width="3" d="M17 52h30"/><animateTransform attributeName="transform" begin="-4s" dur="5s" repeatCount="indefinite" type="translate" values="-4 0; 4 0; -4 0"/></g></svg>';
            break;
		case 'rain'           :
			echo '<svg class="svg-icon svg-rain" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M24.39 43.03l-.78 4.94"/><animateTransform attributeName="transform" dur="0.7s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" dur="0.7s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M31.39 43.03l-.78 4.94"/><animateTransform attributeName="transform" begin="-0.4s" dur="0.7s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-0.4s" dur="0.7s" repeatCount="indefinite" values="0;1;1;0"/></g><g><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M38.39 43.03l-.78 4.94"/><animateTransform attributeName="transform" begin="-0.2s" dur="0.7s" repeatCount="indefinite" type="translate" values="1 -5; -2 10"/><animate attributeName="opacity" begin="-0.2s" dur="0.7s" repeatCount="indefinite" values="0;1;1;0"/></g></svg>';
            break;
		case 'storm-showers'  :
			echo '<svg class="svg-icon svg-storm-showers" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="none" stroke="#dddddd" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M43.67 45.5h2.83a7 7 0 000-14h-.32a10.49 10.49 0 00-19.11-8 7 7 0 00-10.57 6 7.21 7.21 0 00.1 1.14A7.5 7.5 0 0018 45.5a4.19 4.19 0 00.5 0v0"/><g><path fill="#f59e0b" d="M30 36l-4 12h4l-2 10 10-14h-6l4-8h-6z"/><animate attributeName="opacity" dur="2s" repeatCount="indefinite" values="1;1;1;1;1;1;0.1;1;0.1;1;1;0.1;1;0.1;1"/></g></svg>';
            break;
		case 'windy'          :
			echo '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 512 512"><defs><symbol id="a" viewBox="0 0 342 234"><path fill="none" stroke="#e2e8f0" stroke-linecap="round" stroke-miterlimit="10" stroke-width="18" d="M264.2 21.3A40 40 0 11293 89H9m139.2 123.7A40 40 0 10177 145H9"/></symbol></defs><use xlink:href="#a" width="342" height="234" transform="translate(85 139)"/></svg>';
            break;
		case 'raindrop'       :
			echo '<svg class="svg-icon svg-raindrop" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="none" stroke="#2885c7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M32 17c-6.09 9-10 14.62-10 20.09a10 10 0 0020 0C42 31.62 38.09 26 32 17z"/></svg>';
            break;
		case 'thermometer'    :
			echo '<svg class="svg-icon svg-thermometer" aria-hidden="true" role="img" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="42" r="4" fill="#ef4444"/><path fill="none" stroke="#ef4444" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M32 28.5v13"><animate attributeName="y1" dur="5s" repeatCount="indefinite" values="28.5;25.5;28.5"/></path><path fill="none" stroke="#bbbbbb" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M36 36.07v-17a4 4 0 10-8 0v17a7.12 7.12 0 00-3 5.83 7 7 0 1014 0 7.12 7.12 0 00-3-5.83zM32.5 25h3M32.5 21h3M32.5 29h3"/></svg>';
            break;
	}
}
}

/*
** Sidebar Widgets Markup
*/
if ( ! function_exists( 'newsx_dynamic_sidebar' ) ) {
function newsx_dynamic_sidebar( $sidebar_id ) {
    if ( is_active_sidebar( $sidebar_id ) ) {
        dynamic_sidebar( $sidebar_id );
    } elseif ( current_user_can( 'edit_theme_options' ) && is_customize_preview() ) {
        echo '<div class="widget newsx-no-widget-row newsx-flex">';
            // Edit Button
            echo newsx_customizer_edit_button_markup('sidebar-widgets-'. $sidebar_id);

            echo '<div class="newsx-add-widgets-text">';
                echo '<span>'. esc_html__( 'Add Widgets', 'news-magazine-x' ) .'</span>';
                echo '<span class="dashicons dashicons-plus"></span>';
            echo '</div>';
        echo '</div>';
    }
}
}

/*
** General Sidebar Widgets Markup
*/
if ( ! function_exists( 'newsx_general_sidebar_markup' ) ) {
function newsx_general_sidebar_markup( $display, $side ) {
    $display = newsx_get_option( $display );

    if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
        $display = in_array( $display, ['none', 'right'] ) ? $display : 'none';
    }

    if ( ($side === $display || 'both' === $display) && is_active_sidebar( 'general-'. $side .'-sidebar' ) ) {
        get_template_part( 'template-parts/sidebars/'. $side .'-sidebar', '', ['page' => 'general'] );
    }
}
}

/*
** Front Page Row Markup
*/
if ( ! function_exists( 'newsx_front_page_row_markup' ) ) {
function newsx_front_page_row_markup( $args ) {
    $layout = $args['layout'];
    $layout_class  = 'newsx-'. $layout;
    $layout_class .= 'boxed' === newsx_get_option('global_content_width') ? ' newsx-container' : '';
    $layout_class .= $args['stretch'] ? ' newsx-row-stretch' : '';

    echo '<div class="newsx-row newsx-fp-row-'. esc_attr($args['index']) .'">';
        echo '<div class="newsx-row-inner newsx-flex-nowrap '. esc_attr($layout_class) .'">';

            if ( 'content-nosidebar' === $layout ) {
                get_template_part( 'template-parts/front-page/content', '', $args );
            } elseif ( 'content-rsidebar' === $layout || 'content-rsidebar-eq' === $layout ) {
                get_template_part( 'template-parts/front-page/content', '', $args );
                get_template_part( 'template-parts/front-page/right-sidebar', '', $args );
            } elseif ( 'content-lsidebar' === $layout ) {
                get_template_part( 'template-parts/front-page/left-sidebar', '', $args );
                get_template_part( 'template-parts/front-page/content', '', $args );
            } else {
                get_template_part( 'template-parts/front-page/left-sidebar', '', $args );
                get_template_part( 'template-parts/front-page/content', '', $args );
                get_template_part( 'template-parts/front-page/right-sidebar', '', $args );
            }

        echo '</div>';
    echo '</div>';
}
}

/*
** Post Format Icon Markup
*/
if ( ! function_exists( 'newsx_post_format_icon_markup' ) ) {
function newsx_post_format_icon_markup( $id = null ) {
    if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
        return;
    }

    $id = $id ? $id : get_the_ID();
    $format = get_post_format( $id );

    if ( $format ) {
        echo '<span class="newsx-post-format-icon '. esc_attr($format) .'">';
            if ( 'video' === $format ) {
                echo newsx_get_svg_icon('play-fill');
            }
            
            if ( 'audio' === $format ) {
                echo newsx_get_svg_icon('audio-spectrum');
            }
            
            if ( 'gallery' === $format || 'image' === $format ) {
                echo newsx_get_svg_icon('camera');
            }
        echo '</span>';
    }
}
}

/*
** Reading Progress Bar Markup
*/
if ( ! function_exists( 'newsx_reading_progress_bar_markup' ) ) {
function newsx_reading_progress_bar_markup() {
    if ( newsx_get_option('bs_advanced_rpbar_enable') ) {
        echo '<div class="newsx-reading-progress-bar"></div>';
    }
}
}

/*
** Breadcrumbs Markup
*/
if ( ! function_exists( 'newsx_breadcrumbs_markup' ) ) {
function newsx_breadcrumbs_markup( $separator = ' / ', $page = 'single' ) {
    echo '<div class="newsx-breadcrumbs">';

    // Home Link
    echo '<a href="'. esc_url(get_home_url()) .'">'. esc_html('Home', 'news-magazine-x') .'</a>';
    
    // Single Post
    if ( 'single' === $page ) {
        // Check if the post is in a category
        $category = get_the_category();
        if ( $category ) {
            echo esc_html($separator);

            // Get last category the post is in
            $last_category = end($category);
            // Get the category hierarchy for the current category
            $category_hierarchy = get_category_parents($last_category->term_id, true, $separator);
            // Display the category hierarchy
            echo trim($category_hierarchy, $separator);
        }

        echo esc_html($separator);
        
        // Display the current post title
        echo '<span>' . get_the_title() . '</span>';
    
    // Term Archive
    } elseif ( 'term' === $page ) {
        $current_term = get_queried_object();

        // Check if the term has a parent
        if ( $current_term && $current_term->parent ) {
            $parent_term = get_term( $current_term->parent );
            if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                echo esc_html($separator);
                echo '<a href="' . esc_url(get_term_link($parent_term)) . '">' . esc_html($parent_term->name) . '</a>';
            }
        }

        // Display the current term name
        echo esc_html($separator);
        echo '<span>'. esc_html($current_term->name) .'</span>';
    }

    echo '</div>';
}
}

/*
** Custom Category List Markup
*/
if ( ! function_exists( 'newsx_custom_category_list_markup' ) ) {
function newsx_custom_category_list_markup( $separator = '', $post_id = 0 ) {
    // Get the post ID
    $post_id = ( $post_id ) ? $post_id : get_the_ID();

    // Retrieve the categories for the post
    $categories = get_the_category( $post_id );

    // Check if the post has categories
    if ( ! empty( $categories ) ) {
        // Initialize an empty string to hold the category list
        $category_list = '<ul class="post-categories">';

        // Initialize a counter to check the position
        $count = 0;
        $total = count( $categories );

        // Loop through each category
        foreach ( $categories as $category ) {
            // Generate the custom class
            $custom_class = 'newsx-cat-' . $category->term_id;

            // Create the category link with the custom class
            $category_link = '<a href="'. esc_url( get_category_link( $category->term_id ) ) .'" class="'. esc_attr( $custom_class ) .'" rel="category tag">'. esc_html( $category->name ) .'</a>';

            // Add the category link to the list item
            $category_list .= '<li>' . $category_link;

            // Add the separator if it's not the last item
            if ( ++$count < $total ) {
                $category_list .= $separator;
            }

            $category_list .= '</li>';
        }

        // Close the list
        $category_list .= '</ul>';

        // Return the category list
        return $category_list;
    }

    return '';
}
}

/*
** Post Views Markup
*/
if ( ! function_exists( 'newsx_post_views_markup' ) ) {
function newsx_post_views_markup( $post_id ) {
    if ( !function_exists('pvc_get_post_views') || !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
        return;
    }

    $fake_post_views = get_field('newsx_post_fake_views');
    $real_post_views = pvc_get_post_views($post_id);
    $post_views = ($fake_post_views > $real_post_views) ? $fake_post_views : $real_post_views;

    echo '<span class="newsx-post-views">'. esc_html($post_views) . esc_html__('&nbsp;Views', 'news-magazine-x') .'</span>';
}
}

/*
** Post Sharing Markup
*/
if ( ! function_exists( 'newsx_post_sharing_markup' ) ) {
function newsx_post_sharing_markup( $position = 'static' ) {
    global $post;

    // Get Post Data
    $post_id = get_the_ID();
    $permalink = get_permalink( $post_id );
    $twitter_user = get_bloginfo( 'name' );
    $twitter_user = parse_url( $twitter_user, PHP_URL_PATH );
    $twitter_user = str_replace( '/', '', (string) $twitter_user );
    $post_title   = urlencode( html_entity_decode( get_the_title( $post_id ), ENT_COMPAT, 'UTF-8' ) );

    // Get Options
    $show_labels    = newsx_get_option('bs_sharing_show_labels') && 'float' !== $position ? true : false;
    $original_colors = newsx_get_option('bs_sharing_original_colors');
    $show_tooltips  = newsx_get_option('bs_sharing_tooltips');

    if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
        $position = 'static';
        $show_labels = false;
        $original_colors = false;
        $show_tooltips  = false;
    }

    // Wrapper Class
    $option_prefix  = 'float' === $position ? 'bs_sharing_fl' : 'bs_sharing';
    $wrapper_class  = 'float' === $position ? 'newsx-float-sharing' : 'newsx-static-sharing';
    $wrapper_class .= ' newsx-' . newsx_get_option($option_prefix . '_style');
    $wrapper_class .= $original_colors ? ' newsx-original-colors' : '';
    $wrapper_class .= $show_labels ? ' newsx-show-labels' : '';

    // Tooltip Direction
    $tipsy_dir = 's';
    if ( 'float' === $position ) {
        $wrapper_class .= 'inside' === newsx_get_option( 'bs_sharing_fl_pos' ) ? ' pos-inside' : ' pos-outside';
        $tipsy_dir = 'w';
    }

    echo '<div class="'. esc_attr($wrapper_class) .'">';
    
    echo '<div class="newsx-post-sharing">';

        // Edit Button
        echo newsx_customizer_edit_button_markup('bs_sharing');

        if ( '' !== newsx_get_option( $option_prefix .'_text' ) ) {
            echo '<div class="sharing-header">';
                echo newsx_get_svg_icon('share');
                echo '<span>'. esc_html(newsx_get_option( $option_prefix .'_text' )) .'</span>';
            echo '</div>';
        }

        echo '<div class="sharing-icons">';

        if ( newsx_get_option( 'bs_sharing_facebook' ) ) {
            $facebook_src = 'https://www.facebook.com/sharer/sharer.php?u=' . esc_url( $permalink );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Facebook', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="facebook-share" target="_blank" href="' . esc_url( $facebook_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('facebook-f');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Facebook', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_x_twitter' ) ) {
            $twitter_src = 'https://twitter.com/intent/tweet?text='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' ) .'&amp;url='. urlencode( $permalink ) .'&amp;via='. urlencode( $twitter_user );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Twitter', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="x-twitter-share" target="_blank" href="' . esc_url( $twitter_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('x-twitter');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Twitter', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_flipboard' ) ) {
            $flipboard_src = ' https://share.flipboard.com/bookmarklet/popout?url=' . esc_url( $permalink );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Flipboard', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="flipboard-share" target="_blank" href="' . esc_url( $flipboard_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('flipboard');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Flipboard', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_pinterest' ) ) {        
            if ( defined( 'WPSEO_VERSION' ) && '' !== get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) {
                $pinterest_description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            } else {
                $pinterest_description = $post_title;
            }

            $pinterest_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'medium' );

            $pinterest_src = 'https://pinterest.com/pin/create/button/?url=' . urlencode( $permalink ) . '&media=' . (!empty($pinterest_image[0]) ? esc_url($pinterest_image[0]) : '') .'&amp;description='. htmlspecialchars( $pinterest_description, ENT_COMPAT, 'UTF-8' );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Pinterest', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';
            
            echo '<a class="pinterest-share" target="_blank" href="' . esc_url( $pinterest_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('pinterest');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Pinterest', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_linkedin' ) ) {
            $linkedin_src = 'https://linkedin.com/shareArticle?mini=true&amp;url='. urlencode( $permalink ) .'&amp;title='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('LinkedIn', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="linkedin-share" target="_blank" href="' . esc_url( $linkedin_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('linkedin');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('LinkedIn', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_tumblr' ) ) {
            $tumblr_src = 'https://www.tumblr.com/share/link?url='. urlencode( $permalink ) .'&amp;name='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' ) .'&amp;description='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Tumblr', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="tumblr-share" target="_blank" href="' . esc_url( $tumblr_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('tumblr');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Tumblr', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_reddit' ) ) {
            $reddit_src = 'https://www.reddit.com/submit?url='. urlencode( $permalink ) .'&amp;title='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Reddit', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="reddit-share" target="_blank" href="' . esc_url( $reddit_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('reddit');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Reddit', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_vk' ) ) {
            $vk_src = 'https://vkontakte.ru/share.php?url='. urlencode( $permalink );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('VK', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="vk-share" target="_blank" href="' . esc_url( $vk_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('vk');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('VK', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_whatsapp' ) ) {
            $whatsapp_src = 'https://api.whatsapp.com/send?text=' . get_the_title() . ' ' . esc_url( get_the_permalink() );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('WhatsApp', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="whatsapp-share" target="_blank" href="' . esc_url( $whatsapp_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('whatsapp');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('WhatsApp', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_telegram' ) ) {
            $telegram_src = 'https://t.me/share/?url='. urlencode( $permalink ) .'&amp;text='. htmlspecialchars( $post_title, ENT_COMPAT, 'UTF-8' );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Telegram', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="telegram-share" target="_blank" href="' . esc_url( $telegram_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('telegram');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Telegram', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_email' ) ) {
            $mail_src = 'mailto:?subject=' . get_the_title( $post_id ) . ' BODY=' . sprintf( esc_html__( 'I found this article interesting and thought of sharing it with you. Check it out: %s', 'news-magazine-x' ), $permalink );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Email', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="mail-share" target="_blank" href="' . esc_url( $mail_src ) . '" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('email');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Email', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
            $show_tooltips = true;
        }

        if ( newsx_get_option( 'bs_sharing_copy' ) ) {
            $copy_src = esc_url( $permalink );
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Copy Link', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="copy-share" target="_blank" href="' . esc_url( $copy_src ) . '" data-copied="'. esc_html__('Copied!', 'news-magazine-x') .'" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('link');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Copy Link', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        if ( newsx_get_option( 'bs_sharing_print' ) ) {
            $data_tooltip = $show_tooltips ? 'data-tooltip="'. esc_html__('Print Article', 'news-magazine-x') .'" data-gravity="'. esc_attr($tipsy_dir) .'"' : '';

            echo '<a class="print-share" href="javascript:if(window.print)window.print()" '. $data_tooltip .' rel="nofollow">';
                echo newsx_get_svg_icon('print');
                echo ($show_labels) ? '<span class="share-label">'. esc_html('Print Article', 'news-magazine-x') .'</span>' : '';
            echo '</a>';
        }

        echo '</div>'; // .newsx-post-sharing-inner

    echo '</div>'; // .newsx-post-sharing

    echo '</div>'; // $wrapper_class
}
}

/*
** Archive Page Header Markup
*/
if ( ! function_exists( 'newsx_archive_page_header_markup' ) ) {
function newsx_archive_page_header_markup( $page, $breadcrumbs ) {
    echo '<div class="newsx-archive-page-header">';

    // Breadcrumbs
    if ( $breadcrumbs ) {
        newsx_breadcrumbs_markup( ' / ', $page );
    }

    // Term Archive
    if ( 'term' === $page ) {
        // Get the current tag object
        $current_term = get_queried_object();

        // Display the tag title
        if ( ! empty( $current_term->name ) ) {
            echo '<h1>';
                echo 'post_tag' === $current_term->taxonomy ? esc_html__('Browsing Tag:&nbsp;', 'news-magazine-x') : '';
                echo esc_html( $current_term->name );
            echo '</h1>';
        }

        // Display the tag description
        if ( ! empty( $current_term->description ) ) {
            echo '<p>'. wp_kses_post( $current_term->description ) .'</p>';
        }

        // Display Sub Categories
        $sub_categories = get_categories( [
            'child_of' => $current_term->term_id,
            'hide_empty' => 0
        ] );
        
        if ( ! empty( $sub_categories ) ) {
            echo '<div class="sub-categories newsx-flex">';
                echo '<span>'. esc_html__('Sub Categories:', 'news-magazine-x') .'</span>';
                foreach ( $sub_categories as $sub_tag ) {
                    echo '<a href="'. esc_url( get_tag_link( $sub_tag->term_id ) ) .'">'. esc_html( $sub_tag->name ) .'</a>';
                }
            echo '</div>';
        }
        
    // Author Archive
    } elseif ( 'author' === $page ) {
        get_template_part( 'template-parts/blog-single/author' );
    
    // Search Archive
    } elseif ( 'search' === $page ) {
        echo '<h1>';

        // Search Results
        printf(
            /* Translators: %s: Search query. */
            esc_html__( 'Search Results for: %s', 'news-magazine-x' ),
            get_search_query()
        );

        echo '</h1>';

        // Results Count
		$found_results = 0;
		global $wp_query;

		if ( ! empty( $wp_query->found_posts ) ) {
			$found_results = $wp_query->found_posts;
		}

        echo '<p>';
            printf( esc_html__( 'Showing %s results for your search', 'news-magazine-x' ), $found_results );
        echo '</p>';
    }

    echo '</div>';
}
}