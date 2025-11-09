<?php
/**
 * Header builder markup for this theme
 *
 * @package zakra
 */

if ( ! function_exists( 'zakra_header_default_builder' ) ) {
	/**
	 * Header builder markup for this theme
	 *
	 * @package zakra
	 * @return array
	 *
	 * @since 4.0.0
	 */
	function zakra_header_default_builder() {
		return array(
			'desktop' => array(
				'top'    => array(
					'left'   => array(),
					'center' => array(),
					'right'  => array(),
				),
				'main'   => array(
					'left'   => array(
						'logo',
					),
					'center' => array(),
					'right'  => array(
						'primary-menu',
						'search',
					),
				),
				'bottom' => array(
					'left'   => array(),
					'center' => array(),
					'right'  => array(),
				),
			),
			'mobile'  => array(
				'top'    => array(
					'left'   => array(),
					'center' => array(),
					'right'  => array(),
				),
				'main'   => array(
					'left'   => array(
						'logo',
					),
					'center' => array(),
					'right'  => array(
						'toggle-button',
					),
				),
				'bottom' => array(
					'left'   => array(),
					'center' => array(),
					'right'  => array(),
				),
			),
			'offset'  => array(
				'mobile-menu',
			),
		);
	}
}

function zakra_get_area_class( $id ) {
	return str_replace( 'zakra-builder-', '', str_replace( '_', '-', $id ) );
}

if ( ! function_exists( 'zakra_render_header_cols' ) ) {
	/**
	 * Processes column elements for a specific area.
	 *
	 * @param array  $cols      An array of elements to be processed.
	 * @param string $cols_area The area of the columns (e.g., 'left', 'center', 'right').
	 *
	 * @return void
	 *
	 * @since 4.0.0
	 */
	function zakra_render_header_cols( $cols, $cols_area ) {
		echo '<div class="zak-header-' . esc_attr( zakra_get_area_class( $cols_area ) ) . '-col">';
		foreach ( $cols as $element ) {
			do_action( 'zakra_header_template_parts', $element );
			get_template_part( "template-parts/header-builder-elements/$element", '' );
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'zakra_header_builder_markup' ) ) {
	/**
	 * Hook for builder inside markup.
	 *
	 * @hooked zakra_header_builder_inside_markup - 10.
	 */
	function zakra_header_builder_markup() {
		$classes = zakra_css_class( 'zakra_header_class', false );

		// Convert the class list string into an array
		$classes_array = explode( ' ', $classes );

		// Remove the class 'zak-header'
		$key = array_search( 'zak-header', $classes_array, true );
		if ( false !== $key ) {
			unset( $classes_array[ $key ] );
		}

		// Join the remaining classes into a string
		$class_string = implode( ' ', $classes_array );
		echo '<header id="zak-masthead" class="zak-header-builder ' . esc_attr( $class_string ) . '">';

		/**
		 * Hook - zakra_action_before_header
		 *
		 * @hooked zakra_header_start - 10
		 * @hooked zakra_transparent_header_start - 20
		 */
		do_action( 'zakra_action_before_header' );

		/**
		 * Hook - zakra_header_builder_inside_markup
		 *
		 * @hooked zakra_header_builder_inside_markup - 10
		 */
		do_action( 'zakra_header_builder_inside_markup' );

		/**
		 * Hook - zakra_header_builder_inside_markup
		 *
		 * @hooked zakra_header_builder_inside_markup - 10
		 */
		do_action( 'zakra_action_after_header' );
		echo '</header>';

		/**
		 * Hook - zakra_page_header
		 *
		 * @hooked zakra_page_header - 10
		 */
		do_action( 'zakra_page_header' );
	}
	$enable_builder = get_theme_mod( 'zakra_enable_builder', false );
	if ( $enable_builder || zakra_maybe_enable_builder() ) {
		add_action( 'zakra_header', 'zakra_header_builder_markup' );
	}
}

if ( ! function_exists( 'zakra_header_builder_inside_markup' ) ) {
	/**
	 * Renders the inside markup for the header builder.
	 *
	 * This function generates the HTML structure for both desktop and mobile header layouts.
	 * It retrieves the header builder configuration from the theme mod, applies filters,
	 * and then renders the appropriate rows for desktop and mobile views.
	 *
	 * The function performs the following steps:
	 * 1. Retrieves the header builder configuration.
	 * 2. Renders the desktop header layout.
	 * 3. Renders the mobile header layout.
	 *
	 * @since 4.0.0
	 * @action zakra_header_builder_inside_markup
	 *
	 * @return void
	 */
	function zakra_header_builder_inside_markup() {

		$builder = get_theme_mod( 'zakra_header_builder', zakra_header_default_builder() );
		$builder = apply_filters( 'zakra_header_builder_options', $builder );

		echo '<div class="zak-row zak-desktop-row zak-main-header">';
		$desktop_builder = filter_areas( $builder, 'desktop' );
		zakra_render_header_rows( $desktop_builder, 'desktop' );
		echo '</div>';

		echo '<div class="zak-row zak-mobile-row">';
		$mobile_builder = filter_areas( $builder, 'mobile' );
		zakra_render_header_rows( $mobile_builder, 'mobile' );
		echo '</div>';
	}
	add_action( 'zakra_header_builder_inside_markup', 'zakra_header_builder_inside_markup' );
}

if ( ! function_exists( 'filter_areas' ) ) {
	/**
	 * Filters the builder areas for a specific device.
	 *
	 * This function takes a builder configuration and a device type, and filters
	 * out empty rows from the builder layout for the specified device. If the
	 * device-specific configuration doesn't exist, it falls back to the default
	 * header builder configuration.
	 *
	 * @param array  $builder The builder configuration array.
	 * @param string $device  The device type (e.g., 'desktop', 'mobile').
	 *
	 * @return array Filtered array of non-empty rows for the specified device.
	 *
	 * @since 4.0.0
	 */
	function filter_areas( $builder, $device ) {
		return array_filter(
			isset( $builder[ $device ] ) ? $builder[ $device ] : zakra_header_default_builder()[ $device ],
			function ( $row ) {
				foreach ( $row as $cols ) {
					if ( ! empty( $cols ) ) {
						return true;
					}
				}
				return false;
			}
		);
	}
}

if ( ! function_exists( 'zakra_render_header_rows' ) ) {
	/**
	 * Renders the header rows for a specific device.
	 *
	 * This function iterates through the builder configuration and renders each header row.
	 * It handles different areas (top, middle, bottom) and applies specific actions and filters.
	 *
	 * The function performs the following steps:
	 * 1. Loops through each area in the builder configuration.
	 * 2. Applies actions before and after the header top area for desktop devices.
	 * 3. Checks if the current area should be disabled using a filter.
	 * 4. Renders the HTML structure for each row, including container and area-specific classes.
	 * 5. Calls zakra_render_header_cols() to render the columns within each row.
	 *
	 * @param array  $builder The builder configuration array.
	 * @param string $device  The device type (e.g., 'desktop', 'mobile').
	 *
	 * @return void
	 *
	 * @since 4.0.0
	 */
	function zakra_render_header_rows( $builder, $device ) {

		foreach ( $builder as $area => $row ) {
			if ( 'desktop' === $device && 'top' === $area ) {
				do_action( 'zakra_before_header_top' );
			}

			if ( apply_filters( 'zakra_disable_header_area', false, $area, $row, $builder ) ) {
				continue;
			}

			echo '<div class="zak-header-' . esc_attr( zakra_get_area_class( $area ) ) . '-row">';
			echo '<div class="zak-container">';
			echo '<div class="zak-' . esc_attr( zakra_get_area_class( $area ) ) . '-row">';

			foreach ( $row as $cols_area => $cols ) {
				zakra_render_header_cols( $cols, $cols_area );
			}

			echo '</div>';
			echo '</div>';
			echo '</div>';

			if ( 'desktop' === $device && 'top' === $area ) {
				do_action( 'zakra_after_header_top' );
			}
		}
	}
}

// Footer builder markup.
if ( ! function_exists( 'zakra_footer_builder_default' ) ) {
	/**
	 * Footer builder markup for this theme.
	 *
	 * @package zakra
	 * @return array
	 *
	 * @since 4.0.0
	 */
	function zakra_footer_builder_default() {
		return array(
			'desktop' => array(
				'top'    => array(
					'top-1' => array(),
					'top-2' => array(),
					'top-3' => array(),
					'top-4' => array(),
					'top-5' => array(),
				),
				'main'   => array(
					'main-1' => array(),
					'main-2' => array(),
					'main-3' => array(),
					'main-4' => array(),
					'main-5' => array(),
				),
				'bottom' => array(
					'bottom-1' => array( 'copyright' ),
					'bottom-2' => array(),
					'bottom-3' => array(),
					'bottom-4' => array(),
					'bottom-5' => array(),
				),
			),
			'offset'  => array(
				'mobile-menu',
			),
		);
	}
}

function zakra_footer_get_area_class( $id ) {
	return str_replace( 'zakra-builder-', '', str_replace( '_', '-', $id ) );
}

if ( ! function_exists( 'zakra_render_footer_cols' ) ) {
	/**
	 * Renders the footer columns for a specific area.
	 *
	 * This function generates the HTML structure for footer columns and populates
	 * them with the specified elements. It performs the following steps:
	 * 1. Creates a div with a class specific to the footer area.
	 * 2. Iterates through the provided elements.
	 * 3. Includes the template part for each element.
	 * 4. Closes the div container.
	 *
	 * @param array  $cols      An array of elements to be rendered in the footer columns.
	 * @param string $cols_area The specific area of the footer (e.g., 'left', 'center', 'right').
	 *
	 * @return void Outputs the HTML directly.
	 *
	 * @since 4.0.0
	 */
	function zakra_render_footer_cols( $cols, $cols_area ) {
		echo '<div class="zak-footer-col zak-footer-' . esc_attr( zakra_footer_get_area_class( $cols_area ) ) . '-col">';
		foreach ( $cols as $element ) {
			get_template_part( "template-parts/footer-builder-elements/$element", '' );
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'zakra_footer_builder_markup' ) ) {
	/**
	 * Generates and outputs the footer builder markup.
	 *
	 * This function retrieves the footer builder configuration from theme mods,
	 * applies filters, and then constructs the HTML structure for the footer.
	 * It handles different areas (top, main, bottom) and their respective columns.
	 *
	 * The function performs the following steps:
	 * 1. Retrieves and filters the footer builder configuration.
	 * 2. Checks if the configuration is empty and returns early if so.
	 * 3. Outputs the opening footer tags with appropriate classes.
	 * 4. Iterates through each area of the footer (top, main, bottom):
	 *    - Filters out empty rows.
	 *    - Applies a filter to potentially disable specific areas.
	 *    - Generates the HTML structure for each area, including containers and rows.
	 *    - Renders columns within each row using zakra_render_footer_cols().
	 * 5. Closes all opened HTML tags.
	 *
	 * @return void Outputs the footer HTML directly.
	 *
	 * @since 4.0.0
	 */
	function zakra_footer_builder_markup() {
		$footer_builder = get_theme_mod( 'zakra_footer_builder', zakra_footer_builder_default() );
		$footer_builder = apply_filters( 'zakra_footer_builder_options', $footer_builder );

		if ( empty( $footer_builder ) ) {
			return;
		}
		echo '<footer id="zak-footer" class="zak-footer zak-footer-builder' . esc_attr( zakra_footer_class() ) . '">';
		echo '<div class="zak-row zak-footer-desktop-row">';
		foreach ( $footer_builder['desktop'] as $area => $row ) {
			$non_empty_row = array_filter(
				array_map(
					function ( $r ) {
						return ! empty( $r );
					},
					$row
				)
			);

			if ( empty( $non_empty_row ) ) {
				continue;
			}

			if ( apply_filters( 'zakra_disable_footer_area', false, $area, $row, $footer_builder ) ) {
				continue;
			}

			echo '<div class="zak-footer-' . esc_attr( zakra_footer_get_area_class( $area ) ) . '-row" >';
			echo '<div class="zak-container" >';
			$classes = apply_filters(
				'zakra_footer_row_classes',
				array(
					'zak-' . esc_attr( zakra_footer_get_area_class( $area ) ) . '-row',
				),
				$area
			);
			echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			$i       = 1;
			$top_row = get_theme_mod( 'zakra_footer_' . $area . '_area_cols', ( 'top' === $area || 'main' === $area ) ? 4 : 1 );
			$top_row = apply_filters( 'zakra_footer_' . $area . '_area_cols', $top_row );

			foreach ( $row as $cols_area => $cols ) {
				if ( $i <= $top_row ) {
					zakra_render_footer_cols( $cols, $cols_area );
				}
				++$i;
			}
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
		echo '</footer>';
	}
	$enable_builder = get_theme_mod( 'zakra_enable_builder', false );
	if ( $enable_builder || zakra_maybe_enable_builder() ) {
		add_action( 'zakra_builder_footer', 'zakra_footer_builder_markup' );
	}
}
