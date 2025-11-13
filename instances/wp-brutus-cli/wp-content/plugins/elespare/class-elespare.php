<?php
/**
 * Widgets class.
 *
 * @category   Class
 * @package    Elespare
 * @subpackage WordPress
 * @author     Elespare
 * @copyright  2021 Elespare
 * @license    https://opensource.org/licenses/GPL-3.0 GPL-3.0-only
 * @link       https://afthemes.com/plugins/elespare/
 *             Build Custom Elementor Widgets)
 * @since      1.0.0
 * php version 7.3.9
 */

namespace Elespare;

// Security Note: Blocks direct access to the plugin PHP files.
defined( 'ABSPATH' ) || die();


/**
 * Class Plugin
 *
 * Main Plugin class
 *
 * @since 1.0.0
 */

class Widgets {

    /**
     * Instance
     *
     * @since 1.0.0
     * @access private
     * @static
     *
     * @var Plugin The single instance of the class.
     */
    private static $instance = null;

    /**
     * Instance
     *
     * Ensures only one instance of the class is loaded or can be loaded.
     *
     * @since 1.0.0
     * @access public
     *
     * @return Plugin An instance of the class.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Include Widgets files
     *
     * Load widgets files
     *
     * @since 1.0.0
     * @access private
     */
    private function include_widgets_files() {
        require_once 'src/widgets/posts-grid/widget.php';
        require_once 'src/widgets/posts-tile/widget.php';
        require_once 'src/widgets/posts-list/widget.php';
        require_once 'src/widgets/posts-express-list/widget.php';
        require_once 'src/widgets/posts-slider/widget.php';
        require_once 'src/widgets/posts-flash/widget.php';
        require_once 'src/widgets/posts-banner-1/widget.php';
        require_once 'src/widgets/author/widget.php';
        require_once 'src/widgets/social-links/widget.php';
        require_once 'src/widgets/posts-single-column/widget.php';
        require_once 'src/widgets/search-form/widget.php';
        require_once 'src/widgets/site-logo/widget.php';
        require_once 'src/widgets/site-title/widget.php';
        require_once 'src/widgets/site-tagline/widget.php';
        require_once 'src/widgets/nav-menu-horizontal/widget.php';
        require_once 'src/widgets/date-time/widget.php';
        require_once 'src/widgets/section-title/widget.php';
        require_once 'src/widgets/copyright/widget.php';
        require_once 'src/widgets/copyright/copyright-shortcode.php';
        require_once 'src/widgets/popular-tags/widget.php';
        require_once 'src/widgets/custom-link-button/widget.php';
        require_once 'src/widgets/main-banner-1/widget.php';


    }

    /**
     * Register Widgets
     *
     * Register new Elementor widgets.
     *
     * @since 1.0.0
     * @access public
     */
    public function register_widgets() {
        // It's now safe to include Widgets files.
        $this->include_widgets_files();

        // Register the plugin widget classes.
        
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\SiteLogo() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\SiteTitle() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\SiteTagline() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\Site_Search() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\NavigationHorizontalMenu() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostBannerOne() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostExpressList() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostGrid() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostTile() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostList() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostSingleColumn() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostSlider() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\Author() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PostFlash() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\SocialLinks() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\DateTime() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\SectionTitle() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\Copyright() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\PopularTags() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\CustomLinkButton() );
        \Elementor\Plugin::instance()->widgets_manager->register( new Widgets\MainBannerOne() );


    }

    /**
     *  Plugin class constructor
     *
     * Register plugin action hooks and filters
     *
     * @since 1.0.0
     * @access public
     */
    public function __construct() {
        // Register the widgets.
        add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
        add_action('wp_enqueue_scripts',array($this,'elespare_load_scripts'));

        // Enqueue editor scripts
       add_action('elementor/editor/after_enqueue_scripts', [$this, 'elespare_load_editor_scripts']);
       add_filter('elementor/editor/localize_settings', [$this, 'elespare_pro_widget_map']);
    }

    function elespare_load_editor_scripts(){

       

        wp_enqueue_script(
            'elespare-editor-scripts-for-pro',
            ELESPARE_DIR_URL . 'dist/elespare.build.min.js',
            ['elementor-editor', 'jquery'],
            ELESPARE_VERSION,
            true
        );

       
    }


    /**
     * Get the pro widgets map for dashboard only
     *
     * @return array
     */
    public function elespare_pro_widget_map($config) {
        $promotion_widgets = [];

        if (isset($config['promotionWidgets'])) {
            $promotion_widgets = $config['promotionWidgets'];
        }

        $combine_array = array_merge($promotion_widgets, [
            [
                'name' => 'elespare-nav-vertical-menu',
                'title' => __( 'Vertical Nav Menu', 'elespare' ),
                'icon' => 'elespare-icons-verti-nav',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],
            [
                'name' => 'elespare-nav-expanded-menu',
                'title' => __( 'Expanded Nav Menu', 'elespare' ),
                'icon' => 'elespare-icons-expan-nav',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],

            [
                'name' => 'elespare-post--banner-2',
                'title' => __( 'Hero Banner 2', 'elespare' ),
                'icon' => 'elespare-icons-banner-2-1',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],
            [
                'name' => 'elespare-post-express-grid',
                'title' => __( 'Posts Express Grid', 'elespare' ),
                'icon' => 'elespare-icons-express-gird',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],

            [
                'name' => 'elespare-post-carousel',
                'title' => __( 'Posts Carousel', 'elespare' ),
                'icon' => 'elespare-icons-carousel',
                'is_pro' => true,
                'categories' => '["elespare"]',

            ],
            [
                'name' => 'elespare-post-tabs',
                'title' => __( 'Posts Tabbed Grid', 'elespare' ),
                'icon' => 'elespare-icons-tabs',
                'is_pro' => true,
                'categories' => '["elespare"]',

            ],
            [

                'name' => 'elespare-post-masonry',
                'title' => __( 'Posts Masonry', 'elespare' ),
                'icon' => 'elespare-icons-masonry',
                'is_pro' => true,
                'categories' => '["elespare"]',

            ],
            [
                'name'=>'elespare-post-trending-carousel',
                'title' => __( 'Trending Posts Carousel', 'elespare' ),
                'icon' => 'elespare-icons-treinding',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],
            [
                'name'=>'elespare-post-timeline',
                'title' => __( 'Posts Timeline', 'elespare' ),
                'icon' => 'elespare-icons-timeline',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],
            [
                'name'=>'elespare-post-full',
                'title' => __( 'Posts Full', 'elespare' ),
                'icon' => 'elespare-icons-full',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ],
            [
                'name'=>'main-banner-2',
                'title' => __( 'Main Banner 2', 'elespare' ),
                'icon' => 'elespare-icons-main-banner-2',
                'is_pro' => true,
                'categories' => '["elespare"]',
            ]
            
            
        ]);

        $config['promotionWidgets'] = $combine_array;

        return $config;

    }

    




    function elespare_load_scripts(){
        wp_enqueue_style(
            'elespare-icons',
            ELESPARE_DIR_URL . 'assets/font/elespare-icons.css',
            null,
            ELESPARE_VERSION
        );
       
        wp_register_style('slick',ELESPARE_DIR_URL.'assets/slick/css/slick.css','',time(),'all');
        wp_register_script( 'slick', ELESPARE_DIR_URL . 'assets/slick/js/slick.js', array('jquery'), time(), true );
        wp_enqueue_script( 'jquery-marquee', ELESPARE_DIR_URL . 'assets/marquee/jquery.marquee.js', array('jquery'), time(), true );
        wp_register_script( 'elespare-custom-scripts', ELESPARE_DIR_URL . 'assets/js/elespare-scripts.js', array('jquery'), time(), true );
        
       
        wp_localize_script(
            'elespare-custom-scripts',
            'ElespareLocalize',
             [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'elespare_elementor_nonce' ),

             ]
        );



    }
}



// Instantiate the Widgets class.
Widgets::instance();