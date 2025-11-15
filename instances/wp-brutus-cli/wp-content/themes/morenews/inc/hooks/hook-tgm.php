<?php
/**
 * Recommended plugins
 *
 * @package MoreNews
 */

if ( ! function_exists( 'morenews_recommended_plugins' ) ) :

    /**
     * Recommend plugins.
     *
     * @since 1.0.0
     */
    function morenews_recommended_plugins() {

        $plugins = array(
            array(
                'name'     => esc_html__( 'Templatespare', 'morenews' ),
                'slug'     => 'templatespare',
                'required' => false,
            ),
            array(
                'name'     => esc_html__( 'Elespare', 'morenews' ),
                'slug'     => 'elespare',
                'required' => false,
            ),
            array(
                'name'     => esc_html__( 'Blockspare', 'morenews' ),
                'slug'     => 'blockspare',
                'required' => false,
            ),
            array(
                'name'     => esc_html__( 'WP Post Author', 'morenews' ),
                'slug'     => 'wp-post-author',
                'required' => false,
            ),
            array(
                'name'     => esc_html__( 'Free Live Chat using 3CX', 'morenews' ),
                'slug'     => 'wp-live-chat-support',
                'required' => false,
            )

        );

        tgmpa( $plugins );

    }

endif;

add_action( 'tgmpa_register', 'morenews_recommended_plugins' );
