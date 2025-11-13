<?php
/**
 * REST API Endpoints for Sections and Layouts.
 *
 * @package Blockspare
 */

namespace Blockspare\Layouts;

use \WP_REST_Response;
use \WP_REST_Server;

const BS_API_NAMESPACE = 'blockspare/v1';

const LAYOUTS_ROUTE       = 'layouts';
const SINGLE_LAYOUT_ROUTE = 'layouts/([A-Za-z])\w+/';

const SECTIONS_ROUTE       = 'sections';
const SINGLE_SECTION_ROUTE = 'sections/([A-Za-z])\w+/';

const BLOCKS_ROUTE       = 'blocks';
const SINGLE_BLOCK_ROUTE = 'blocks/([A-Za-z])\w+/';

const PAGES_ROUTE       = 'pages';
const SINGLE_PAGE_ROUTE = 'pages/([A-Za-z])\w+/';

const FAVORITE_LAYOUTS_ROUTE = 'layouts/favorites';
const ALL_LAYOUTS_ROUTE      = 'layouts/all';

const IMPORT_ROUTE = 'importdata';

add_action( 'rest_api_init', __NAMESPACE__ . '\register_layout_endpoints' );
/**
 * Create custom endpoints for block settings
 */
function register_layout_endpoints() {

	/**
	 * Register the favorites GET endpoint.
	 *
	 * Note: Keep this route before the other routes
	 * otherwise they may override this one.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		FAVORITE_LAYOUTS_ROUTE,
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function () {
				return new WP_REST_Response( (array) get_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the layouts GET endpoint
	 * that combines all sections, layouts,
	 * and additional layouts.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		ALL_LAYOUTS_ROUTE,
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( \WP_REST_Request $request ) {

				$layouts            = blockspare_get_layouts();
				$sections           = blockspare_get_sections();
				$blocks           = blockspare_get_blocks();
				$pages           = blockspare_get_pages();
				$templates           = blockspare_get_templates();
				$additional_layouts = apply_filters( 'blockspare_additional_layout_components', [] );
				$all_layouts        = array_merge( $layouts, $sections, $blocks,$pages,$templates, $additional_layouts );
				$request_params     = $request->get_params();

				// Return all layouts if filtering was not requested. "allowed" is the only filter currently supported.
				if ( empty( $request_params['filter'] ) || 'allowed' !== $request_params['filter'] ) {
					return new WP_REST_Response( $all_layouts );
				}

				/**
				 * Filters the list of sections and layouts allowed to show in the layouts library.
				 *
				 * @since 2.5.0
				 *
				 * @param array $all_layouts Array of unique layout keys allowed. Defaults to all layouts.
				 */
				$allowed_layouts = (array) apply_filters( 'blockspare_allowed_layout_components', array_keys( $all_layouts ) );

				if ( empty( $allowed_layouts ) ) {
					return new WP_REST_Response( [] );
				}

				$filtered_layouts = [];

				foreach ( $all_layouts as $key => $layout ) {
					if ( in_array( $key, $allowed_layouts, true ) ) {
						$filtered_layouts[ $key ] = $layout;
					}
				}

				return new WP_REST_Response( $filtered_layouts );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the layouts GET endpoint.
	 * Returns all registered layouts.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		LAYOUTS_ROUTE,
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function () {
				return new WP_REST_Response( (array) blockspare_get_layouts() );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the single layout GET endpoint.
	 * Returns a single requested layout.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		SINGLE_LAYOUT_ROUTE,
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				$route      = $request->get_route();
				$layout_key = substr( strrchr( $route, '/' ), 1 );
				$layouts    = blockspare_get_layouts();
				if ( isset( $layouts[ $layout_key ] ) ) {
					return new WP_REST_Response( $layouts[ $layout_key ] );
				}

				return new WP_REST_Response( esc_html__( 'Layout not found.', 'blockspare' ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the favorites update endpoint.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		FAVORITE_LAYOUTS_ROUTE,
		[
			'methods'             => 'PATCH',
			'callback'            => function ( $request ) {

				$body      = json_decode( $request->get_body(), true );
				$new       = sanitize_key( $body['blockspare_favorite_key'] );
				$favorites = (array) get_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', true );

				if ( in_array( $new, $favorites, true ) ) {
					return new WP_REST_Response( $favorites );
				}

				if ( empty( $favorites[0] ) ) {
					$favorites = array( $new );
				} else {
					$favorites[] = $new;
				}

				update_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', array_values( $favorites ) );

				return new WP_REST_Response( (array) get_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the favorites delete endpoint.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		FAVORITE_LAYOUTS_ROUTE,
		[
			'methods'             => 'DELETE',
			'callback'            => function ( $request ) {

				$body      = json_decode( $request->get_body(), true );
				$delete_id = sanitize_key( $body['blockspare_favorite_key'] );
				$favorites = (array) get_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', true );

				if ( ! in_array( $delete_id, $favorites, true ) ) {
					return new WP_REST_Response( $favorites );
				}

				$position = array_search( $delete_id, $favorites, true );

				unset( $favorites[ $position ] );

				update_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', array_values( $favorites ) );

				return new WP_REST_Response( (array) get_user_meta( get_current_user_id(), 'blockspare_favorite_layouts', true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	/**
	 * Register the sections GET endpoint.
	 * Returns all registered sections.
	 */
	register_rest_route(
		BS_API_NAMESPACE,
		SECTIONS_ROUTE,
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function () {
				return new WP_REST_Response( (array) blockspare_get_sections() );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);

	register_rest_route(
		BS_API_NAMESPACE,
		IMPORT_ROUTE,
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => function (\WP_REST_Request $request) {
			$request_params     = $request->get_params();	
			
			 $data = blockspare_import_images_replace_url($request_params['content'] ) ;
			 return new WP_REST_Response( $data );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);
}
