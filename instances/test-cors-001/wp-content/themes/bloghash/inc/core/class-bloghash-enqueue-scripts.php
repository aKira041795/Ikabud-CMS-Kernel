<?php
/**
 * Enqueue scripts & styles.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Enqueue and register scripts and styles.
 *
 * @since 1.0.0
 */
class Bloghash_Enqueue_Scripts {

	/**
	 * Check if debug is on
	 *
	 * @var boolean
	 */
	private $is_debug;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->is_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		add_action( 'wp_enqueue_scripts', array( $this, 'bloghash_enqueues' ) );
		add_action( 'wp_print_footer_scripts', array( $this, 'bloghash_skip_link_focus_fix' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'bloghash_block_editor_assets' ) );
	}

	/**
	 * Enqueue styles and scripts.
	 *
	 * @since 1.0.0
	 */
	public function bloghash_enqueues() {
		// Script debug.
		$bloghash_dir    = $this->is_debug ? 'dev/' : '';
		$bloghash_suffix = $this->is_debug ? '' : '.min';

		wp_enqueue_style( 'swiper', BLOGHASH_THEME_URI . '/assets/css/swiper-bundle' . $bloghash_suffix . '.css' );

		wp_enqueue_script( 'swiper', BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'vendors/swiper-bundle' . $bloghash_suffix . '.js', array(), false, true );

		// fontawesome enqueue.
		wp_enqueue_style(
			'FontAwesome',
			BLOGHASH_THEME_URI . '/assets/css/all' . $bloghash_suffix . '.css',
			false,
			'5.15.4',
			'all'
		);
		// Enqueue theme stylesheet.
		wp_enqueue_style(
			'bloghash-styles',
			BLOGHASH_THEME_URI . '/assets/css/style' . $bloghash_suffix . '.css',
			false,
			BLOGHASH_THEME_VERSION,
			'all'
		);

		// Enqueue IE specific styles.
		wp_enqueue_style(
			'bloghash-ie',
			BLOGHASH_THEME_URI . '/assets/css/compatibility/ie' . $bloghash_suffix . '.css',
			false,
			BLOGHASH_THEME_VERSION,
			'all'
		);

		wp_style_add_data( 'bloghash-ie', 'conditional', 'IE' );

		// Enqueue HTML5 shiv.
		wp_register_script(
			'html5shiv',
			BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'vendors/html5' . $bloghash_suffix . '.js',
			array(),
			'3.7.3',
			true
		);

		// Load only on < IE9.
		wp_script_add_data(
			'html5shiv',
			'conditional',
			'lt IE 9'
		);

		// Flexibility.js for crossbrowser flex support.
		wp_enqueue_script(
			'bloghash-flexibility',
			BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'vendors/flexibility' . $bloghash_suffix . '.js',
			array(),
			BLOGHASH_THEME_VERSION,
			false
		);

		wp_add_inline_script(
			'bloghash-flexibility',
			'flexibility(document.documentElement);'
		);

		wp_script_add_data(
			'bloghash-flexibility',
			'conditional',
			'IE'
		);

		// Register Bloghash slider.
		wp_register_script(
			'bloghash-slider',
			BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'bloghash-slider' . $bloghash_suffix . '.js',
			array( 'imagesloaded' ),
			BLOGHASH_THEME_VERSION,
			true
		);

		wp_register_script(
			'bloghash-marquee',
			BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'vendors/vanilla-marquee' . $bloghash_suffix . '.js',
			array( 'imagesloaded' ),
			BLOGHASH_THEME_VERSION,
			true
		);

		if ( bloghash()->options->get( 'bloghash_blog_masonry' ) ) {
			wp_enqueue_script( 'masonry' );
		}

		// Load comment reply script if comments are open.
		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}

		// Enqueue main theme script.
		wp_enqueue_script(
			'bloghash',
			BLOGHASH_THEME_URI . '/assets/js/' . $bloghash_dir . 'bloghash' . $bloghash_suffix . '.js',
			array( 'jquery', 'imagesloaded' ),
			BLOGHASH_THEME_VERSION,
			true
		);

		// Comment count used in localized strings.
		$comment_count = get_comments_number();

		// Localized variables so they can be used for translatable strings.
		$localized = array(
			'ajaxurl'               	=> esc_url( admin_url( 'admin-ajax.php' ) ),
			'nonce'                 	=> wp_create_nonce( 'bloghash-nonce' ),
			'live-search-nonce'     	=> wp_create_nonce( 'bloghash-live-search-nonce' ),
			'post-like-nonce'       	=> wp_create_nonce( 'bloghash-post-like-nonce' ),
			'close'                 	=> esc_html__( 'Close', 'bloghash' ),
			'no_results'            	=> esc_html__( 'No results found', 'bloghash' ),
			'more_results'          	=> esc_html__( 'More results', 'bloghash' ),
			'responsive-breakpoint' 	=> intval( bloghash_option( 'main_nav_mobile_breakpoint' ) ),
			'dark_mode' 				=> (bool) bloghash_option( 'dark_mode' ),
			'sticky-header'         	=> array(
				'enabled' => bloghash_option( 'sticky_header' ),
				'hide_on' => bloghash_option( 'sticky_header_hide_on' ),
			),
			'strings'               => array(
				/* translators: %s Comment count */
				'comments_toggle_show' => $comment_count > 0 ? esc_html( sprintf( _n( 'Show %s Comment', 'Show %s Comments', $comment_count, 'bloghash' ), $comment_count ) ) : esc_html__( 'Leave a Comment', 'bloghash' ),
				'comments_toggle_hide' => esc_html__( 'Hide Comments', 'bloghash' ),
			),
		);

		wp_localize_script(
			'bloghash',
			'bloghash_vars',
			apply_filters( 'bloghash_localized', $localized )
		);

		// Enqueue google fonts.
		bloghash()->fonts->enqueue_google_fonts();

		// Add additional theme styles.
		do_action( 'bloghash_enqueue_scripts' );
	}

	/**
	 * Skip link focus fix for IE11.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function bloghash_skip_link_focus_fix() {
		?>
		<script>
			! function() {
				var e = -1 < navigator.userAgent.toLowerCase().indexOf("webkit"),
					t = -1 < navigator.userAgent.toLowerCase().indexOf("opera"),
					n = -1 < navigator.userAgent.toLowerCase().indexOf("msie");
				(e || t || n) && document.getElementById && window.addEventListener && window.addEventListener("hashchange", function() {
					var e, t = location.hash.substring(1);
					/^[A-z0-9_-]+$/.test(t) && (e = document.getElementById(t)) && (/^(?:a|select|input|button|textarea)$/i.test(e.tagName) || (e.tabIndex = -1), e.focus())
				}, !1)
			}();
		</script>
		<?php
	}

	/**
	 * Enqueue assets for the Block Editor.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function bloghash_block_editor_assets() {

		// RTL version.
		$rtl = is_rtl() ? '-rtl' : '';

		// Minified version.
		$min = $this->is_debug ? '' : '.min';
		// Enqueue block editor styles.
		wp_enqueue_style(
			'bloghash-block-editor-styles',
			BLOGHASH_THEME_URI . '/inc/admin/assets/css/bloghash-block-editor-styles' . $rtl . $min . '.css',
			false,
			BLOGHASH_THEME_VERSION,
			'all'
		);

		// Enqueue google fonts.
		bloghash()->fonts->enqueue_google_fonts();

		// Add dynamic CSS as inline style.
		wp_add_inline_style(
			'bloghash-block-editor-styles',
			apply_filters( 'bloghash_block_editor_dynamic_css', bloghash_dynamic_styles()->get_block_editor_css() )
		);
	}
}
