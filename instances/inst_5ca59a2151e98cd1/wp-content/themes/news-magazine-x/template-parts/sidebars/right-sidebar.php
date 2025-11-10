<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$current_page = $args['page'];

?>

<div class="newsx-sidebar widget-area secondary <?php echo 'newsx-'. esc_attr($current_page) .'-sidebar'; ?>">
    <?php newsx_dynamic_sidebar( $current_page .'-right-sidebar' ); ?>
</div>