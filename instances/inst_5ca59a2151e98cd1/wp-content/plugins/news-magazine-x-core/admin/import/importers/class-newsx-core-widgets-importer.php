<?php

class Newsx_Core_Widgets_Importer {
	/**
	 * Import widgets from WIE or JSON file.
	 */
	public static function import() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-core-admin' ) || !current_user_can('manage_options') ) {
            wp_send_json_error( 'Invalid nonce' );
            return;
        }

        // Delete All Widgets from Sidebars
		self::clear_sidebars();

		// Get Template
		$template = isset( $_POST['template'] ) ? $_POST['template'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$templateType = isset( $_POST['template_type'] ) ? $_POST['template_type'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$results = [];
        $import_file_path = NEWSX_CORE_PATH . 'admin/import/data/'. sanitize_file_name(wp_unslash($template)) .'/demo.wie';
		if ( $templateType === 'pro' && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
			$import_file_path = NEWSX_CORE_PRO_PATH . 'admin/import/data/'. sanitize_file_name(wp_unslash($template)) .'/demo.wie';
		} else {
			$import_file_path = NEWSX_CORE_PATH . 'admin/import/data/'. sanitize_file_name(wp_unslash($template)) .'/demo.wie';
		}
		
		// Try to Import widgets and return result.
		if ( ! empty( $import_file_path ) ) {
			$results = self::import_widgets( $import_file_path );
		}

		// Fix Featured Tabs Widget
		self::fix_featured_tabs_widget();

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( 'Widgets Import Failed.' );
        }

	}

	/**
	 * Clear sidebars
	 */
	public static function clear_sidebars() {
		$sidebars = get_option('sidebars_widgets');

		foreach ($sidebars as $sidebar_id => $widgets) {
			if ('wp_inactive_widgets' !== $sidebar_id) {
				$sidebars[$sidebar_id] = array();
			}
		}

		update_option('sidebars_widgets', $sidebars);
	}

	/**
	 * Fix Featured Tabs Widget
	 */
	public static function fix_featured_tabs_widget() {
		// Get tag IDs for "Popular" and "Featured"
		$popular_tag = get_term_by('name', 'Popular', 'post_tag');
		$featured_tag = get_term_by('name', 'Featured', 'post_tag');
		$popular_tag_id = $popular_tag ? (string)$popular_tag->term_id : '';
		$featured_tag_id = $featured_tag ? (string)$featured_tag->term_id : '';

		// Get category ID for "featured"
		$featured_cat = get_category_by_slug('featured');
		$featured_cat_id = $featured_cat ? $featured_cat->term_id : null;

		// Get all widget settings
		$all_widget_settings = get_option('widget_newsx_featured_tabs');

		// Get active widgets
		$active_widgets = get_option('sidebars_widgets');

		$featured_tabs_widgets = [];

		// Loop through all sidebars and their widgets
		foreach ($active_widgets as $sidebar => $widgets) {
			if (!is_array($widgets)) {
				continue;
			}
			
			// Find featured tabs widgets in this sidebar
			foreach ($widgets as $widget) {
				if ('false' !== strpos($widget, 'newsx_featured_tabs')) {
					// Extract widget number
					$widget_number = str_replace('newsx_featured_tabs-', '', $widget);
					
					// Get this widget's settings
					if (isset($all_widget_settings[$widget_number])) {
						// Update t1_tags and t2_tags with respective tag IDs
						$all_widget_settings[$widget_number]['t1_tags'] = $popular_tag_id ? [$popular_tag_id] : [];
						$all_widget_settings[$widget_number]['t2_tags'] = $featured_tag_id ? [$featured_tag_id] : [];
						
						$featured_tabs_widgets[$sidebar][$widget] = $all_widget_settings[$widget_number];
					}
				}
			}
		}

		// Save updated widget settings
		update_option('widget_newsx_featured_tabs', $all_widget_settings);
	}

	/**
	 * Imports widgets from a json file.
	 */
	private static function import_widgets( $data_file ) {
		// Get widgets data from file.
		$data = self::process_import_file( $data_file );

		// Return from this function if there was an error.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Import the widget data and save the results.
		return self::import_data( $data );
	}

	/**
	 * Process import file - this parses the widget data and returns it.
	 */
	private static function process_import_file( $file ) {
		// File exists?
		if ( ! file_exists( $file ) ) {
			return new \WP_Error(
				'widget_import_file_not_found',
				__( 'Error: Widget import file could not be found.', 'news-magazine-x-core' )
			);
		}

		// Get file contents and decode.
		$data = Newsx_Core_Helpers::get_data_from_file( $file );

		// Return from this function if there was an error.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return json_decode( $data );
	}


	/**
	 * Import widget JSON data
	 */
	private static function import_data( $data ) {
		global $wp_registered_sidebars;

		// Have valid data? If no data or could not decode.
		if ( empty( $data ) || ! is_object( $data ) ) {
			return new \WP_Error(
				'corrupted_widget_import_data',
				__( 'Error: Widget import data could not be read. Please try a different file.', 'news-magazine-x-core' )
			);
		}

		// Get all available widgets site supports.
		$available_widgets = self::available_widgets();

		// Get all existing widget instances.
		$widget_instances = [];

		foreach ( $available_widgets as $widget_data ) {
			$widget_instances[ $widget_data['id_base'] ] = get_option( 'widget_' . $widget_data['id_base'] );
		}

		// Begin results.
		$results = [];

		// Loop import data's sidebars.
		foreach ( $data as $sidebar_id => $widgets ) {
			// Skip inactive widgets (should not be in export file).
			if ( 'wp_inactive_widgets' == $sidebar_id ) {
				continue;
			}

			// Check if sidebar is available on this site. Otherwise add widgets to inactive, and say so.
			if ( isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
				$sidebar_available    = true;
				$use_sidebar_id       = $sidebar_id;
				$sidebar_message_type = 'success';
				$sidebar_message      = '';
			}
			else {
				$sidebar_available    = false;
				$use_sidebar_id       = 'wp_inactive_widgets'; // Add to inactive if sidebar does not exist in theme.
				$sidebar_message_type = 'error';
				$sidebar_message      = __( 'Sidebar does not exist in theme (moving widget to Inactive)', 'news-magazine-x-core' );
			}

			// Result for sidebar.
			$results[ $sidebar_id ]['name']         = ! empty( $wp_registered_sidebars[ $sidebar_id ]['name'] ) ? $wp_registered_sidebars[ $sidebar_id ]['name'] : $sidebar_id; // Sidebar name if theme supports it; otherwise ID.
			$results[ $sidebar_id ]['message_type'] = $sidebar_message_type;
			$results[ $sidebar_id ]['message']      = $sidebar_message;
			$results[ $sidebar_id ]['widgets']      = [];

			// Loop widgets.
			foreach ( $widgets as $widget_instance_id => $widget ) {
				$fail = false;

				// Get id_base (remove -# from end) and instance ID number.
				$id_base            = preg_replace( '/-[0-9]+$/', '', $widget_instance_id );
				$instance_id_number = str_replace( $id_base . '-', '', $widget_instance_id );

				// Does site support this widget?
				if ( ! $fail && ! isset( $available_widgets[ $id_base ] ) ) {
					$fail                = true;
					$widget_message_type = 'error';
					$widget_message      = __( 'Site does not support widget', 'news-magazine-x-core' ); // Explain why widget not imported.
				}

				// Convert multidimensional objects to multidimensional arrays.
				// Some plugins like Jetpack Widget Visibility store settings as multidimensional arrays.
				// Without this, they are imported as objects and cause fatal error on Widgets page.
				// If this creates problems for plugins that do actually intend settings in objects then may need to consider other approach: https://wordpress.org/support/topic/problem-with-array-of-arrays.
				// It is probably much more likely that arrays are used than objects, however.
				$widget = json_decode( wp_json_encode( $widget ), true );

				// Replace kinsta.cloud URLs with local URLs
				$upload_dir = wp_upload_dir();
				if (!empty($upload_dir['baseurl'])) {
					$widget = self::replace_urls_in_array($widget, $upload_dir['baseurl']);
				}

				// Does widget with identical settings already exist in same sidebar?
				if ( ! $fail && isset( $widget_instances[ $id_base ] ) ) {
					// Get existing widgets in this sidebar.
					$sidebars_widgets = get_option( 'sidebars_widgets' );
					$sidebar_widgets  = isset( $sidebars_widgets[ $use_sidebar_id ] ) ? $sidebars_widgets[ $use_sidebar_id ] : []; // Check Inactive if that's where will go.

					// Loop widgets with ID base.
					$single_widget_instances = ! empty( $widget_instances[ $id_base ] ) ? $widget_instances[ $id_base ] : [];
					foreach ( $single_widget_instances as $check_id => $check_widget ) {
						// Is widget in same sidebar and has identical settings?
						if ( in_array( "$id_base-$check_id", $sidebar_widgets ) && (array) $widget == $check_widget ) {
							$fail                = true;
							$widget_message_type = 'warning';
							$widget_message      = __( 'Widget already exists', 'news-magazine-x-core' ); // Explain why widget not imported.

							break;
						}
					}
				}

				// No failure.
				if ( ! $fail ) {
					// Add widget instance.
					$single_widget_instances   = get_option( 'widget_' . $id_base ); // All instances for that widget ID base, get fresh every time.
					$single_widget_instances   = ! empty( $single_widget_instances ) ? $single_widget_instances : [ '_multiwidget' => 1 ]; // Start fresh if have to.
					$single_widget_instances[] = $widget; // Add it.

					// Get the key it was given.
					end( $single_widget_instances );
					$new_instance_id_number = key( $single_widget_instances );

					// If key is 0, make it 1.
					// When 0, an issue can occur where adding a widget causes data from other widget to load, and the widget doesn't stick (reload wipes it).
					if ( '0' === strval( $new_instance_id_number ) ) {
						$new_instance_id_number                           = 1;
						$single_widget_instances[ $new_instance_id_number ] = $single_widget_instances[0];
						unset( $single_widget_instances[0] );
					}

					// Move _multiwidget to end of array for uniformity.
					if ( isset( $single_widget_instances['_multiwidget'] ) ) {
						$multiwidget = $single_widget_instances['_multiwidget'];
						unset( $single_widget_instances['_multiwidget'] );
						$single_widget_instances['_multiwidget'] = $multiwidget;
					}

					// Update option with new widget.
					update_option( 'widget_' . $id_base, $single_widget_instances );

					// Assign widget instance to sidebar.
					$sidebars_widgets = get_option( 'sidebars_widgets' ); // Which sidebars have which widgets, get fresh every time.

					// Avoid rarely fatal error when the option is an empty string
					// https://github.com/churchthemes/widget-importer-exporter/pull/11.
					if ( ! $sidebars_widgets ) {
						$sidebars_widgets = [];
					}

					$new_instance_id = $id_base . '-' . $new_instance_id_number; // Use ID number from new widget instance.
					$sidebars_widgets[ $use_sidebar_id ][] = $new_instance_id; // Add new instance to sidebar.
					update_option( 'sidebars_widgets', $sidebars_widgets ); // Save the amended data.

					// After widget import action.
					$after_widget_import = [
						'sidebar'           => $use_sidebar_id,
						'sidebar_old'       => $sidebar_id,
						'widget'            => $widget,
						'widget_type'       => $id_base,
						'widget_id'         => $new_instance_id,
						'widget_id_old'     => $widget_instance_id,
						'widget_id_num'     => $new_instance_id_number,
						'widget_id_num_old' => $instance_id_number,
					];

					// Success message.
					if ( $sidebar_available ) {
						$widget_message_type = 'success';
						$widget_message      = __( 'Imported', 'news-magazine-x-core' );
					}
					else {
						$widget_message_type = 'warning';
						$widget_message      = __( 'Imported to Inactive', 'news-magazine-x-core' );
					}
				}

				// Result for widget instance.
				$results[ $sidebar_id ]['widgets'][ $widget_instance_id ]['name']         = isset( $available_widgets[ $id_base ]['name'] ) ? $available_widgets[ $id_base ]['name'] : $id_base; // Widget name or ID if name not available (not supported by site).
				$results[ $sidebar_id ]['widgets'][ $widget_instance_id ]['title']        = ! empty( $widget['title'] ) ? $widget['title'] : __( 'No Title', 'news-magazine-x-core' ); // Show "No Title" if widget instance is untitled.
				$results[ $sidebar_id ]['widgets'][ $widget_instance_id ]['message_type'] = $widget_message_type;
				$results[ $sidebar_id ]['widgets'][ $widget_instance_id ]['message']      = $widget_message;

			}
		}

		// Return results.
		return $results;
	}

	// Add this new helper function
	private static function replace_urls_in_array($array, $upload_url) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$array[$key] = self::replace_urls_in_array($value, $upload_url);
			} elseif (is_string($value) && false !== strpos($value, '/wp-content/uploads/')) {
				$array[$key] = preg_replace(
					'|https?://[^/]+/[^/]+/wp-content/uploads/(?:sites/\d+/)?|',
					$upload_url . '/',
					$value
				);
			}
		}
		return $array;
	}

	/**
	 * Available widgets.
	 *
	 * Gather site's widgets into array with ID base, name, etc.
	 *
	 */
	private static function available_widgets() {
		global $wp_registered_widget_controls;

		$widget_controls   = $wp_registered_widget_controls;
		$available_widgets = [];

		foreach ( $widget_controls as $widget ) {
			if ( ! empty( $widget['id_base'] ) && ! isset( $available_widgets[ $widget['id_base'] ] ) ) {
				$available_widgets[ $widget['id_base'] ]['id_base'] = $widget['id_base'];
				$available_widgets[ $widget['id_base'] ]['name']    = $widget['name'];
			}
		}

		return $available_widgets;
	}
}
