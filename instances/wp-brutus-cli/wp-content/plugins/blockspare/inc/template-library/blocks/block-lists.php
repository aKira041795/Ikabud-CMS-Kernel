<?php
class Blocksspare_Block_Library {


    public static function instance() {

        static $instance = null;
        if ( null === $instance ) {
            $instance = new self();

            if ( !defined( 'BLOCKSPARE_BLOCK_LIBRARY_PATH' ) ) {
                define( 'BLOCKSPARE_BLOCK_LIBRARY_PATH', BLOCKSPARE_PLUGIN_DIR . 'inc/template-library/blocks/' );
                define( 'BLOCKSPARE_BLOCK_LIBRARY_URL', BLOCKSPARE_PLUGIN_URL . 'inc/template-library/blocks/' );
            }
        }
        return $instance;
    }


    public function run() {
        $this->load_dependencies();
    }

    private function load_dependencies() {
        
        
        /*Block Library*/
         
          require_once BLOCKSPARE_BLOCK_LIBRARY_PATH .'pages/class-pages.php';
          require_once BLOCKSPARE_BLOCK_LIBRARY_PATH .'sections/class-sections.php';
          require_once BLOCKSPARE_BLOCK_LIBRARY_PATH .'footer/class-footer.php';
          require_once BLOCKSPARE_BLOCK_LIBRARY_PATH .'header/class-header.php';
    }
}

Blocksspare_Block_Library::instance()->run();