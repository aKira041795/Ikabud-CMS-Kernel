<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$author_style = is_archive() ? 's0' : newsx_get_option('bs_author_style');
$avatar_size = is_archive() ? 100 : newsx_get_option('bs_author_avatar_size');

// Get the ID of the post's author
$author_id = get_the_author_meta('ID');

$author_job = get_the_author_meta('job', $author_id);

// Get the bio/description of the post's author
$author_desc = get_the_author_meta('description', $author_id);

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $author_style = 's4';
    $avatar_size = !is_archive() ? 100 : 50;
}

echo '<div class="newsx-post-author-box newsx-flex-nowrap newsx-'. esc_attr($author_style) .'">';

    // Edit Button
    echo newsx_customizer_edit_button_markup('bs_author');

    echo '<div class="box-header newsx-flex">';
        echo '<div class="author-avatar">';
            echo get_avatar( $author_id, $avatar_size );
        echo '</div>';

        if ( 's2' === $author_style || 's3' === $author_style || 's4' === $author_style ) {
            echo '<div class="author-info newsx-flex">';
                echo '<div class="newsx-flex">';
                    newsx_author_name_markup( $author_id );
                    newsx_author_job_markup( $author_id );
                echo '</div>';
                
                if ( 's4' !== $author_style ) {
                    newsx_author_socials_markup( newsx_get_user_socials( $author_id ) );
                }
            echo '</div>';
        }
    echo '</div>';

    echo '<div class="box-content">';
        if ( 's0' === $author_style ) {
            echo '<div class="author-info newsx-flex">';
                echo '<div class="newsx-flex">';
                    newsx_author_name_markup( $author_id );
                    newsx_author_job_markup( $author_id );
                echo '</div>';
            echo '</div>';
        } elseif ( 's1' === $author_style ) {
            echo '<div class="author-info newsx-flex">';
                echo '<div class="newsx-flex">';
                    newsx_author_name_markup( $author_id );
                    newsx_author_job_markup( $author_id );
                echo '</div>';
                
                newsx_author_socials_markup( newsx_get_user_socials( $author_id ) );
            echo '</div>';
        }

        newsx_author_description_markup( $author_id );

        if ( 's0' === $author_style || 's4' === $author_style ) {
            newsx_author_socials_markup( newsx_get_user_socials( $author_id ) );
        }
    echo '</div>';
echo '</div>';


// Author Name Markup
function newsx_author_name_markup( $author_id = null ) {
    $author_name = get_the_author_meta('display_name', $author_id);
    $html_tag = is_archive() ? 'h1' : 'a';

    
    echo '<'. esc_attr($html_tag) .' href="'. esc_url(get_author_posts_url( $author_id )) .'" class="author-name">' . esc_html($author_name) . '</'. esc_attr($html_tag) .'>';
}

// Author Job Markup
function newsx_author_job_markup( $author_id = null ) {
    $author_job = get_the_author_meta('job', $author_id);

    echo !empty( $author_job ) ? '<span class="author-job">' . esc_html($author_job) . '</span>' : '';
}

// Author Description Markup
function newsx_author_description_markup( $author_id = null ) {
    $author_desc = get_the_author_meta('description', $author_id);

    echo !empty( $author_desc ) ? '<p class="author-description">' . esc_html($author_desc) . '</p>' : '';
}

// Author Social Icons Markup
function newsx_author_socials_markup( $data = [], $new_tab = true ) {

    if ( empty( $data ) ) {
        return false;
    }

    if ( true === $new_tab ) {
        $new_tab = 'target="_blank" rel="noopener"';
    } else {
        $new_tab = 'rel="noopener"';
    }

    extract( shortcode_atts( [
        'website'    => '',
        'facebook'   => '',
        'x_twitter'  => '',
        'youtube'    => '',
        'googlenews' => '',
        'instagram'  => '',
        'pinterest'  => '',
        'linkedin'   => '',
        'tumblr'     => '',
        'flickr'     => '',
        'skype'      => '',
        'snapchat'   => '',
        'bloglovin'  => '',
        'digg'       => '',
        'dribbble'   => '',
        'soundcloud' => '',
        'vimeo'      => '',
        'reddit'     => '',
        'vkontakte'  => '',
        'telegram'   => '',
        'whatsapp'   => '',
        'rss'        => '',
    ], $data ) );

    echo '<div class="author-socials">';

    if ( ! empty( $website ) ) {
        echo '<a class="social-link-website" aria-label="' . esc_attr__( 'Website', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Website', 'news-magazine-x' ) . '" href="' . esc_url( $website ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('globe-americas');
        echo '</a>';
    }
    if ( ! empty( $facebook ) ) {
        echo '<a class="social-link-facebook" aria-label="' . esc_attr__( 'Facebook', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Facebook', 'news-magazine-x' ) . '" href="' . esc_url( $facebook ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('facebook-f');
        echo '</a>';
    }
    if ( ! empty( $x_twitter ) ) {
        echo '<a class="social-link-twitter" aria-label="' . esc_attr__( 'X (Twitter)', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'X (Twitter)', 'news-magazine-x' ) . '" href="' . esc_url( $x_twitter ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('x-twitter');
        echo '</a>';
    }
    if ( ! empty( $youtube ) ) {
        echo '<a class="social-link-youtube" aria-label="' . esc_attr__( 'YouTube', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'YouTube', 'news-magazine-x' ) . '" href="' . esc_url( $youtube ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('youtube');
        echo '</a>';
    }
    if ( ! empty( $googlenews ) ) {
        echo '<a class="social-link-google-news" aria-label="' . esc_attr__( 'Google News', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Google News', 'news-magazine-x' ) . '" href="' . esc_url( $googlenews ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('google');
        echo '</a>';
    }
    if ( ! empty( $pinterest ) ) {
        echo '<a class="social-link-pinterest" aria-label="' . esc_attr__( 'Pinterest', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Pinterest', 'news-magazine-x' ) . '" href="' . esc_url( $pinterest ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('pinterest-p');
        echo '</a>';
    }
    if ( ! empty( $instagram ) ) {
        echo '<a class="social-link-instagram" aria-label="' . esc_attr__( 'Instagram', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Instagram', 'news-magazine-x' ) . '" href="' . esc_url( $instagram ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('instagram-square');
        echo '</a>';
    }
    if ( ! empty( $linkedin ) ) {
        echo '<a class="social-link-linkedin" aria-label="' . esc_attr__( 'LinkedIn', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'LinkedIn', 'news-magazine-x' ) . '" href="' . esc_url( $linkedin ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('linkedin');
        echo '</a>';
    }
    if ( ! empty( $tumblr ) ) {
        echo '<a class="social-link-tumblr" aria-label="' . esc_attr__( 'Tumblr', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Tumblr', 'news-magazine-x' ) . '" href="' . esc_url( $tumblr ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('tumblr');
        echo '</a>';
    }
    if ( ! empty( $flickr ) ) {
        echo '<a class="social-link-flickr" aria-label="' . esc_attr__( 'Flickr', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Flickr', 'news-magazine-x' ) . '" href="' . esc_url( $flickr ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('flickr');
        echo '</a>';
    }
    if ( ! empty( $skype ) ) {
        echo '<a class="social-link-skype" aria-label="' . esc_attr__( 'Skype', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Skype', 'news-magazine-x' ) . '" href="' . esc_url( $skype ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('skype');
        echo '</a>';
    }
    if ( ! empty( $snapchat ) ) {
        echo '<a class="social-link-snapchat" aria-label="' . esc_attr__( 'SnapChat', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'SnapChat', 'news-magazine-x' ) . '" href="' . esc_url( $snapchat ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('snapchat');
        echo '</a>';
    }
    if ( ! empty( $bloglovin ) ) {
        echo '<a class="social-link-bloglovin" aria-label="' . esc_attr__( 'Bloglovin', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Bloglovin', 'news-magazine-x' ) . '" href="' . esc_url( $bloglovin ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('bloglovin');
        echo '</a>';
    }
    if ( ! empty( $digg ) ) {
        echo '<a class="social-link-digg" aria-label="' . esc_attr__( 'Digg', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Digg', 'news-magazine-x' ) . '" href="' . esc_url( $digg ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('digg');
        echo '</a>';
    }
    if ( ! empty( $dribbble ) ) {
        echo '<a class="social-link-dribbble" aria-label="' . esc_attr__( 'Dribbble', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Dribbble', 'news-magazine-x' ) . '" href="' . esc_url( $dribbble ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('dribbble');
        echo '</a>';
    }
    if ( ! empty( $soundcloud ) ) {
        echo '<a class="social-link-soundcloud" aria-label="' . esc_attr__( 'SoundCloud', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'SoundCloud', 'news-magazine-x' ) . '" href="' . esc_url( $soundcloud ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('soundcloud');
        echo '</a>';
    }
    if ( ! empty( $vimeo ) ) {
        echo '<a class="social-link-vimeo" aria-label="' . esc_attr__( 'Vimeo', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Vimeo', 'news-magazine-x' ) . '" href="' . esc_url( $vimeo ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('vimeo');
        echo '</a>';
    }
    if ( ! empty( $reddit ) ) {
        echo '<a class="social-link-reddit" aria-label="' . esc_attr__( 'Reddit', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Reddit', 'news-magazine-x' ) . '" href="' . esc_url( $reddit ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('reddit-alien');
        echo '</a>';
    }
    if ( ! empty( $vkontakte ) ) {
        echo '<a class="social-link-vk" aria-label="' . esc_attr__( 'Vkontakte', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Vkontakte', 'news-magazine-x' ) . '" href="' . esc_url( $vkontakte ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('vk');
        echo '</a>';
    }
    if ( ! empty( $telegram ) ) {
        echo '<a class="social-link-telegram" aria-label="' . esc_attr__( 'Telegram', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Telegram', 'news-magazine-x' ) . '" href="' . esc_url( $telegram ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('telegram-plane');
        echo '</a>';
    }
    if ( ! empty( $whatsapp ) ) {
        echo '<a class="social-link-whatsapp" aria-label="' . esc_attr__( 'WhatsApp', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'WhatsApp', 'news-magazine-x' ) . '" href="' . esc_url( $whatsapp ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('whatsapp');
        echo '</a>';
    }
    if ( ! empty( $rss ) ) {
        echo '<a class="social-link-rss" aria-label="' . esc_attr__( 'Rss', 'news-magazine-x' ) . '" data-title="' . esc_attr__( 'Rss', 'news-magazine-x' ) . '" href="' . esc_url( $rss ) . '" ' . $new_tab . '>';
            echo newsx_get_svg_icon('rss-square');
        echo '</a>';
    }

    echo '</div>';
}
