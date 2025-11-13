<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! function_exists( 'bs_ends_with' ) ) {
	/**
	 * This function is only available in PHP 8.0 and above.
	 *
	 * @param {string} $haystack
	 * @param {string} $needle
	 * @return {boolean}
	 */
    function bs_ends_with( $haystack, $needle ) {
        $needle_len = strlen( $needle );
        return ( $needle_len === 0 || 0 === substr_compare( $haystack, $needle, - $needle_len ) );
    }
}

if ( ! class_exists( 'blockspare_Google_Fonts' ) ) {
    class blockspare_Google_Fonts {

		public static $google_fonts = [];

        function __construct() {
           
			add_filter( 'render_block', array( $this, 'bs_gather_google_fonts' ), 10, 2 );
		}

		public function bs_gather_google_fonts($block_content, $block ) {
			if ( $block_content === null ) {
				return $block_content;
			}

			$block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( $this->is_blockspare_block( $block_name ) && is_array( $block['attrs'] ) ) {
				if ( stripos( $block_content, 'family' ) !== false ) {
					foreach ( $block['attrs'] as $attr_name => $font_name ) {
						if ( bs_ends_with( strtolower( $attr_name ), 'fontfamily' ) ) {
							self::register_font( $font_name );
						}
					}
				}
			}

			return $block_content;
		}

		public static function enqueue_frontend_block_fonts() {
			self::enqueue_google_fonts( array_unique( self::$google_fonts ) );
		}

		public static function is_web_font( $font_name ) {
			
			return ! in_array( strtolower($font_name) , [ 'Arial', 'Times New Roman', 'Helvetica', 'Georgia' ] );
		}

		public static function is_blockspare_block( $block_name ) {
			if(!empty($block_name)){
				return strpos( $block_name, 'blockspare/' ) === 0;
			
			}
			return  false;
		}

		public static function register_font( $font_name ) {
			if ( ! self::is_web_font( $font_name ) ) {
				return;
			}

			if ( ! in_array( $font_name, self::$google_fonts ) ) {
				// Allow themes to disable enqueuing fonts, helpful for custom fonts.
				if ( apply_filters( 'blockspare_enqueue_font', true, $font_name ) ) {
					self::$google_fonts[] = $font_name;

					// Enqueue the fonts in the footer.
					add_filter( 'wp_footer', array( 'blockspare_Google_Fonts', 'enqueue_frontend_block_fonts' ) );
				}
			}
		}


		

		/**
		 * Based on: https://github.com/elementor/elementor/blob/bc251b81afb626c4c47029aea8a762566524a811/includes/frontend.php#L647
		 */
		public static function enqueue_google_fonts( $google_fonts,$handle ='blockspare-google-fonts'  ) {
			if ( ! count( $google_fonts) ) {
				return;
			}

			foreach ( $google_fonts as &$font ) {
				$font = str_replace( ' ', '+', $font ) . ':100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic';
			}

			$fonts_url = sprintf( 'https://fonts.googleapis.com/css?family=%s', implode( rawurlencode( '|' ), $google_fonts ) );

			$subsets = [
				'ru_RU' => 'cyrillic',
				'bg_BG' => 'cyrillic',
				'he_IL' => 'hebrew',
				'el' => 'greek',
				'vi' => 'vietnamese',
				'uk' => 'cyrillic',
				'cs_CZ' => 'latin-ext',
				'ro_RO' => 'latin-ext',
				'pl_PL' => 'latin-ext',
			];

			$locale = get_locale();
			if ( isset( $subsets[ $locale ] ) ) {
				$fonts_url .= '&subset=' . $subsets[ $locale ];
			}

			wp_enqueue_style( $handle, $fonts_url ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

	}

	new blockspare_Google_Fonts();
}
