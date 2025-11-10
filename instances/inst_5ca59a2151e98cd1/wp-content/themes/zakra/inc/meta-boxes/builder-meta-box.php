<?php
/**
 * Zakra Header Builder Meta Box
 *
 * @package Zakra
 */
add_filter(
	'zakra_header_builder_options',
	function ( $header_builder_config ) {

		$page_id              = get_the_ID();
		$main_header_style    = get_post_meta( $page_id, 'zakra_main_header_style', true );
		$primary_menu_enable  = get_theme_mod( 'zakra_enable_primary_menu', true );
		$header_search_enable = get_theme_mod( 'zakra_enable_header_search', true );
		$header_button_enable = get_theme_mod( 'zakra_enable_header_button', false );

		if ( ! empty( $main_header_style ) && ( 'zak-layout-1-style-1' === $main_header_style || 'zak-layout-1-style-2' === $main_header_style || 'zak-layout-1-style-3' === $main_header_style ) ) {
			$header_builder_config = [
				'desktop' => [
					'top'    => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'main'   => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'bottom' => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
				],
				'mobile'  => [
					'top'    => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'main'   => [
						'left'   => [ 'logo' ],
						'center' => [],
						'right'  => [ 'toggle-button' ],
					],
					'bottom' => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
				],
				'offset'  => [ 'mobile-menu' ],
			];

			if ( 'zak-layout-1-style-1' === $main_header_style ) {
				$header_builder_config['desktop']['main']['left']   = [
					'logo',
				];
				$header_builder_config['desktop']['main']['center'] = [];
				if ( $primary_menu_enable ) {
					$header_builder_config['desktop']['main']['right'][] = 'primary-menu';
				}
				if ( $header_search_enable ) {
					$header_builder_config['desktop']['main']['right'][] = 'search';
				}
				if ( class_exists( 'WooCommerce' ) ) {
					$header_builder_config['desktop']['main']['right'][] = 'cart';
				}
				if ( $header_button_enable ) {
					$header_builder_config['desktop']['main']['right'][] = 'button';
				}
			} elseif ( 'zak-layout-1-style-2' === $main_header_style ) {
				$header_builder_config['desktop']['main']['center'] = [
					'logo',
				];
				if ( $primary_menu_enable ) {
					$header_builder_config['desktop']['bottom']['center'][] = 'primary-menu';
				}
				if ( $header_search_enable ) {
					$header_builder_config['desktop']['bottom']['center'][] = 'search';
				}
				if ( class_exists( 'WooCommerce' ) ) {
					$header_builder_config['desktop']['bottom']['center'][] = 'cart';
				}
				if ( $header_button_enable ) {
					$header_builder_config['desktop']['bottom']['center'][] = 'button';
				}
			} elseif ( 'zak-layout-1-style-3' === $main_header_style ) {
				if ( $primary_menu_enable ) {
					$header_builder_config['desktop']['main']['left'][] = 'primary-menu';
				}
				if ( $header_search_enable ) {
					$header_builder_config['desktop']['main']['left'][] = 'search';
				}
				if ( class_exists( 'WooCommerce' ) ) {
					$header_builder_config['desktop']['main']['left'][] = 'cart';
				}
				if ( $header_button_enable ) {
					$header_builder_config['desktop']['main']['left'][] = 'button';
				}
				$header_builder_config['desktop']['main']['right'] = [
					'logo',
				];
			}
		}

		return $header_builder_config;
	}
);
