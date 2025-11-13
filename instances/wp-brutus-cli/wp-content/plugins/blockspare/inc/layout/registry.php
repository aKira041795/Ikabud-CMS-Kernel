<?php
/**
 * Layout Component Registry
 *
 * @package Blockspare
 */

namespace Blockspare\Layouts;

use \Exception;
use \InvalidArgumentException;

/**
 * Class Component_Registry
 *
 * @access private Not intended as a public API.
 * Use the helper functions to manage layouts.
 * This class will likely go away in the future.
 */
final class Component_Registry {

	/**
	 * Supported component types.
	 *
	 * @var array
	 */
	private static $supported_component_types = [ 'layout', 'section','block','page','header','footer','templates' ];

	/**
	 * Required keys for components.
	 *
	 * @var array
	 */
	private static $required_data_keys = [ 'type', 'key', 'name', 'content', 'item' ];

	/**
	 * Holds the registered layouts.
	 *
	 * @var array
	 */
	private static $layouts = [];

	/**
	 * Holds the registered sections.
	 *
	 * @var array
	 */
	private static $sections = [];

	/**
	 * Holds the registered blocks.
	 *
	 * @var array
	 */
	private static $blocks = [];


	/**
	 * Holds the registered pages.
	 *
	 * @var array
	 */
	private static $pages = [];

	/**
	 * Holds the registered headers.
	 *
	 * @var array
	 */
	private static $headers = [];

	/**
	 * Holds the registered footers.
	 *
	 * @var array
	 */
	private static $footers = [];

	/**
	 * Holds the registered templates.
	 *
	 * @var array
	 */
	private static $templates = [];

	/**
	 * The Component_Registry object.
	 *
	 * @var object Component_Registry
	 */
	private static $instance;

	/**
	 * Creates the Component_Registry instance.
	 *
	 * @return Component_Registry
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Adds a component to the registry.
	 *
	 * @param array $data Component data.
	 * @throws InvalidArgumentException If invalid data is provided or the component is already registered.
	 */
	public static function add( array $data ) {

		if ( empty( $data['type'] ) || ! in_array( $data['type'], self::$supported_component_types, true ) ) {
			throw new InvalidArgumentException( esc_html__( 'You must supply a valid component type.', 'blockspare' ) );
		}

		if ( empty( $data ) ) {
			throw new InvalidArgumentException( __( 'You must supply valid layout data to register a layout.', 'blockspare' ) );
		}

		foreach ( self::$required_data_keys as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) || empty( $data[ $required_key ] ) ) {
				/* translators: %s: The missing key that is required to register a component. */
				throw new InvalidArgumentException( sprintf( esc_html__( 'You must supply a %s to register a layout.', 'blockspare' ), $required_key ) );
			}
		}

		switch ( $data['type'] ) {
			case 'layout':
				if ( ! empty( self::$layouts[ $data['key'] ] ) ) {
					/* translators: %s: The component's unique key. */
					throw new InvalidArgumentException( sprintf( esc_html__( 'The %s layout is already registered.', 'blockspare' ), $data['key'] ) );
				}
				self::$layouts[ $data['key'] ] = $data;
				break;

			case 'section':
				if ( ! empty( self::$sections[ $data['key'] ] ) ) {
					/* translators: %s: The component's unique key. */
					throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
				}
				self::$sections[ $data['key'] ] = $data;
				break;


				case 'block':
					if ( ! empty( self::$blocks[ $data['key'] ] ) ) {
						/* translators: %s: The component's unique key. */
						throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
					}
					self::$blocks[ $data['key'] ] = $data;
					break;

				case 'page':
					if ( ! empty( self::$pages[ $data['key'] ] ) ) {
						/* translators: %s: The component's unique key. */
						throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
						}
					self::$pages[ $data['key'] ] = $data;
					break;
				case 'header':
					if ( ! empty( self::$headers[ $data['key'] ] ) ) {
						/* translators: %s: The component's unique key. */
						throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
						}
					self::$pages[ $data['key'] ] = $data;
					break;
				case 'footer':
					if ( ! empty( self::$footers[ $data['key'] ] ) ) {
						/* translators: %s: The component's unique key. */
						throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
						}
					self::$pages[ $data['key'] ] = $data;
					break;

					case 'templates':
						if ( ! empty( self::$templates[ $data['key'] ] ) ) {
							/* translators: %s: The component's unique key. */
							throw new InvalidArgumentException( sprintf( esc_html__( 'The %s section is already registered.', 'blockspare' ), $data['key'] ) );
							}
						self::$pages[ $data['key'] ] = $data;
						break;

			default:
				/* translators: %s: This functions name. Will always be Blockspare\Layouts\Component_Registry::add(). */
				throw new InvalidArgumentException( sprintf( esc_html__( 'You must supply a valid component type in %s.', 'blockspare' ), __METHOD__ ) );
		}
	}

	/**
	 * Removes an existing component from the registry.
	 *
	 * @param string $type The component type to unregister.
	 * @param string $key The unique layout key to be removed.
	 *
	 * @throws Exception If the required data is not provided or the specified component is not registered.
	 * @throws InvalidArgumentException If an unsupported component type is provided.
	 */
	public static function remove( $type, $key ) {

		if ( empty( $type ) || ! in_array( $type, self::$supported_component_types, true ) ) {
			/* translators: %s: This functions name. Will always be Blockspare\Layouts\Component_Registry::remove(). */
			throw new InvalidArgumentException( sprintf( esc_html__( 'You must supply a valid component type in %s.', 'blockspare' ), __METHOD__ ) );
		}

		if ( empty( $key ) ) {
			/* translators: %s: This functions name. Will always be Blockspare\Layouts\Component_Registry::remove(). */
			throw new InvalidArgumentException( sprintf( esc_html__( 'You must supply a valid component key in %s.', 'blockspare' ), __METHOD__ ) );
		}

		$key = sanitize_key( $key );

		switch ( $type ) {
			case 'layout':
				if ( empty( self::$layouts[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s layout is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$layouts[ $key ] );
				break;

			case 'section':
				if ( empty( self::$sections[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$sections[ $key ] );
				break;

			case 'block':
				if ( empty( self::$blocks[ $key ] ) ) {
					/* translators: The requested components unique key. */
						throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$blocks[ $key ] );
				break;

			case 'page':
				if ( empty( self::$pages[ $key ] ) ) {
					/* translators: The requested components unique key. */
						throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$pages[ $key ] );
				break;
			case 'header':
				if ( empty( self::$headers[ $key ] ) ) {
					/* translators: The requested components unique key. */
							throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$headers[ $key ] );
				break;
					
			case 'footer':
				if ( empty( self::$footers[ $key ] ) ) {
					/* translators: The requested components unique key. */
						throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				unset( self::$footers[ $key ] );
				break;

				case 'templates':
					if ( empty( self::$templates[ $key ] ) ) {
						/* translators: The requested components unique key. */
							throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
					}
					unset( self::$templates[ $key ] );
					break;
		}

	}

	/**
	 * Gets a component from the registry.
	 *
	 * @param string $type The component type.
	 * @param string $key The component's unique key.
	 *
	 * @return mixed
	 * @throws Exception If the required data is not provided or the specified component is not registered.
	 * @throws InvalidArgumentException If an unsupported component type is provided.
	 */
	public static function get( $type, $key ) {

		if ( empty( $type ) || ! in_array( $type, self::$supported_component_types, true ) ) {
			/* translators: This function name. Will always be Blockspare\Layouts\Component_Registry::get(). */
			throw new Exception( sprintf( esc_html__( 'You must supply a component type in %s.', 'blockspare' ), __METHOD__ ) );
		}

		switch ( $type ) {
			case 'layout':
				if ( empty( self::$layouts[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s layout is not registered.', 'blockspare' ), $key ) );
				}
				return self::$layouts[ $key ];

			case 'section':
				if ( empty( self::$sections[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				return self::$sections[ $key ];

			case 'block':
				if ( empty( self::$blocks[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				return self::$blocks[ $key ];


			case 'page':
				if ( empty( self::$pages[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				return self::$pages[ $key ];
			
			case 'header':
				if ( empty( self::$headers[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				return self::$headers[ $key ];

			case 'footer':
				if ( empty( self::$footers[ $key ] ) ) {
					/* translators: The requested components unique key. */
					throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
				}
				return self::$footers[ $key ];

				case 'templates':
					if ( empty( self::$templates[ $key ] ) ) {
						/* translators: The requested components unique key. */
						throw new Exception( sprintf( esc_html__( 'The %s section is not registered.', 'blockspare' ), $key ) );
					}
					return self::$templates[ $key ];

			default:
				/* translators: This function name. Will always be Blockspare\Layouts\Component_Registry::get(). */
				throw new InvalidArgumentException( sprintf( esc_html__( 'You must supply a valid component type in %s.', 'blockspare' ), __METHOD__ ) );
		}
	}

	/**
	 * Returns the registered layouts.
	 *
	 * @return array
	 */
	public static function layouts() {
		return self::$layouts;
	}

	/**
	 * Returns the registered sections.
	 *
	 * @return array
	 */
	public static function sections() {
		return self::$sections;
	}


	/**
	 * Returns the registered sections.
	 *
	 * @return array
	 */
	public static function blocks() {
		return self::$blocks;
	}

	/**
	 * Returns the registered pages.
	 *
	 * @return array
	 */
	public static function pages() {
		return self::$pages;
	}

	/**
	 * Returns the registered headers.
	 *
	 * @return array
	 */
	public static function headers() {
		return self::$headers;
	}

	/**
	 * Returns the registered footers.
	 *
	 * @return array
	 */
	public static function footers() {
		return self::$footers;
	}


	public static function templates() {
		return self::$templates;
	}
}
