<?php

if (! defined('ABSPATH')) {
  exit;
}

if (! class_exists('Blockspare_Import_Block_Base')) {


  class Blockspare_Import_Block_Base
  {
    public function run()
    {

      if (method_exists($this, 'add_block_template_library')) {

        add_filter('blockspare_template_library', array($this, 'add_block_template_library'));

      }
    }
  }
}

require_once BLOCKSPARE_PLUGIN_DIR . 'inc/template-library/blocks/block-lists.php';
