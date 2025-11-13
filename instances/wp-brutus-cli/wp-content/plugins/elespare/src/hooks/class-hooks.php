<?php
if(!class_exists('Elespare_Hooks')){
    class Elespare_Hooks{
       private static $instance;

       public static function instance() {
           if ( ! isset( self::$instance ) ) {
               self::$instance = new self();
           }
           return self::$instance;
       }

       public function __construct() {
           add_action('elespare_hf_seach_form',array($this,'elespare_header_footer_search_form'), 10, 3);
    
     }
     public function elespare_header_footer_search_form( $icon = '', $placeholder = 'Enter Keyword', $text = null ) {
        $url = esc_url( home_url( '/' ) );

        ?>
            <div class="elespare--search-sidebar-wrapper" aria-expanded="false" role="form">
                <form action="<?php echo esc_url( $url ); ?>" class="search-form site-search-form" method="GET">
    
                    <span class="screen-reader-text"><?php echo esc_html_x( 'Search for:', 'label', 'elespare' ); ?></span>
    
                    <input type="search" class="search-field site-search-field" placeholder="<?php echo esc_attr( $placeholder ); ?>" name="s">
                    <input type="hidden" name="post_type" value="post">
                    <button type="submit" class="btn-elespare-search-form <?php echo esc_attr( $icon ); ?>">
                        <?php
                        if ( $text ) :
                            echo esc_html( $text );
                        endif;
                        ?>
                        <span class="screen-reader-text"><?php echo esc_html__( 'Search', 'elespare' ); ?></span>
                    </button>
                </form>
            </div><!-- .elespare-container -->
        <?php
    }
    }

    Elespare_Hooks::instance();
}
