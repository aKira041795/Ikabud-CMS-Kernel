<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get the post format
$post_format = get_post_format() ? : 'standard';

echo '<div class="newsx-single-post-media">';
    if ( 'standard' === $post_format ) {
        the_post_thumbnail();

        // Add Featured Image Caption
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $thumbnail_id = get_post_thumbnail_id();
            $layout_preset = newsx_get_option('bs_header_layout_preset');

            if ( $thumbnail_id && newsx_get_option('bs_featured_image_show_caption') && 's3' !== $layout_preset ) {
                $caption = wp_get_attachment_caption( $thumbnail_id );
                if ( $caption ) {
                    echo '<p class="newsx-single-post-media-caption">' . esc_html( $caption ) . '</p>';
                }
            }
        }
    
    // Load Post Formats from Plugin
    } else if ( class_exists( 'Newsx_Core_Pro' ) ) {
        $newsx_core_pro = new Newsx_Core_Pro();
        $newsx_core_pro_public = new Newsx_Core_Pro_Public( $newsx_core_pro->get_plugin_name(), $newsx_core_pro->get_version() );
        $newsx_core_pro_public->load_post_format_template( $post_format );
    }
echo '</div>';
