<?php
/**
 * Layout block related functions.
 *
 * @package Blockspare
 */

use Blockspare\Layouts\Component_Registry;

/**
 * Registers layout components with the Component Registry
 * for use in the Layouts block.
 *
 * @param array $data The component data.
 *
 * @return bool|WP_Error
 */
function blockspare_register_layout_component( array $data ) {


	$registry = Component_Registry::instance();

	try {
		$registry::add( $data );
		return true;
	} catch ( Exception $exception ) {
		return new WP_Error( esc_html( $exception->getMessage() ) );
	}
}

/**
 * Unregisters the specified layout component from the Component Registry
 * for use in the Layouts block.
 *
 * @return mixed Boolean true if component unregistered. WP_Error object if an error occurs.
 * @param string $type The component type to be unregistered.
 * @param string $key The unique layout key to be unregistered.
 */
function blockspare_unregister_layout_component( $type, $key ) {
	$registry = Component_Registry::instance();
	try {
		$registry::remove( $type, $key );
		return true;
	} catch ( Exception $exception ) {
		return new WP_Error( esc_html( $exception->getMessage() ) );
	}
}

/**
 * Retrieves the specified layout component.
 *
 * @param string $type The layout component type.
 * @param string $key The layout component's unique key.
 *
 * @return mixed|WP_Error
 */
function blockspare_get_layout_component( $type, $key ) {

	if ( empty( $type ) ) {
		return new WP_Error( esc_html__( 'You must supply a type to retrieve a layout component.', 'blockspare' ) );
	}

	if ( empty( $key ) ) {
		return new WP_Error( esc_html__( 'You must supply a key to retrieve a layout component.', 'blockspare' ) );
	}

	$type = sanitize_key( $type );

	$key = sanitize_key( $key );

	$registry = Component_Registry::instance();

	try {
		return $registry::get( $type, $key );
	} catch ( Exception $exception ) {
		return new WP_Error( esc_html( $exception->getMessage() ) );
	}
}

/**
 * Gets the registered layouts.
 *
 * @return array Array of registered layouts.
 */
function blockspare_get_layouts() {
	$registry = Component_Registry::instance();
	return $registry::layouts();
}

/**
 * Gets the registered sections.
 *
 * @return array Array of registered sections.
 */
function blockspare_get_sections() {
	$registry = Component_Registry::instance();
	return $registry::sections();
}


/**
 * Gets the registered blocks.
 *
 * @return array Array of registered blocks.
 */
function blockspare_get_blocks() {
	$registry = Component_Registry::instance();
	return $registry::blocks();
}

/**
 * Gets the registered pages.
 *
 * @return array Array of registered pages.
 */
function blockspare_get_pages() {
	$registry = Component_Registry::instance();
	return $registry::pages();
}

/**
 * Gets the registered headers.
 *
 * @return array Array of registered headers.
 */
function blockspare_get_headers() {
	$registry = Component_Registry::instance();
	return $registry::headers();
}

/**
 * Gets the registered footers.
 *
 * @return array Array of registered footers.
 */
function blockspare_get_footers() {
	$registry = Component_Registry::instance();
	return $registry::footers();
}

function blockspare_get_templates() {
	$registry = Component_Registry::instance();
	return $registry::templates();
}


function blockspare_import_images_replace_url($content =''){
	
	preg_match_all( '#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $content, $match );
	$all_links = array_unique( $match[0] );

	
	if ( empty( $all_links ) ) {
		return $content;
	};
	$link_mapping = array();
	$image_links  = array();
	$other_links  = array();
	foreach ( $all_links as $key => $link ) {
		if ( blockspare_block_templates_is_valid_image( $link ) ) {
			if (
				false === strpos( $link, '-150x' ) &&
				false === strpos( $link, '-300x' ) &&
				false === strpos( $link, '-1024x' )
			) {
				$image_links[] = $link;
			}
		} else {

			
			$other_links[] = $link;
		}
	}

	
	if ( ! empty( $image_links ) ) {
		foreach ( $image_links as $key => $image_url ) {
			// Download remote image.
			$image            = array(
				'url' => $image_url,
				'id'  => 0,
			);
			$downloaded_image = Blockspare_Design_Image_Importer::get_instance()->blockspare_import( $image );

			
			$link_mapping[ $image_url ] = $downloaded_image['url'];
		}
	}
	foreach ( $link_mapping as $old_url => $new_url ) {
		$content = str_replace( $old_url, $new_url, $content );

		// Replace the slashed URLs if any exist.
		$old_url = str_replace( '/', '/\\', $old_url );
		$new_url = str_replace( '/', '/\\', $new_url );
		$content = str_replace( $old_url, $new_url, $content );
	}

	return $content;

	

	
}

function blockspare_block_templates_is_valid_image( $link = '' ) {
	return preg_match( '/^((https?:\/\/)|(www\.))([a-z0-9-].?)+(:[0-9]+)?\/[\w\-]+\.(jpg|png|gif|jpeg)\/?$/i', $link );
}


