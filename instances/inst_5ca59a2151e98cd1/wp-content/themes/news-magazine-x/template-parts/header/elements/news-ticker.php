<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$header_nt_type_select = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? newsx_get_option('header_nt_type_select') : 'marquee';

$heading_text = newsx_get_option( 'header_nt_heading_text' );
$heading_element = 'div';

$data_newsx_settings = [
	'layout' => newsx_get_option('header_nt_columns'),
	'delay' => absint( newsx_get_option('header_nt_autoplay_delay') ),
	'autoplay' => newsx_get_option('header_nt_autoplay'),
];

if ( 'marquee' === $header_nt_type_select ) {
	$swiper_class = '';
	$swiper_slide = '';

	$marquee_options = [
		'direction' => 'left',
		'duplicated' => true,
		'startVisible' => true,
		'gap' => 0,
		'duration' => absint( 50 * 1000 ),
		'pauseOnHover' => true
	];
} else {
	$swiper_class = 'swiper';
	$swiper_slide = 'swiper-slide';
}

if ( !function_exists('newsx_header_news_ticker_heading_icon') ) {
	function newsx_header_news_ticker_heading_icon( $instance ) {
		$heading_icon_type = newsx_get_option('header_nt_heading_icon_type');
		if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
			$heading_icon_type = in_array($heading_icon_type, ['none', 'fire']) ? $heading_icon_type : 'fire';
		}

		if ( 'none' !== $heading_icon_type ) {
			echo '<span class="news-ticker-heading-icon '. esc_attr($heading_icon_type) .'">';
				if ( 'blink' == $heading_icon_type ) {
					echo '<span class="newsx-ticker-icon-circle"></span>';
				} else if ( 'fire' == $heading_icon_type ) {
					echo newsx_get_svg_icon( $heading_icon_type );
				}
			echo '</span>';
		}
	}
}
		
// Ticker Title
if ( !function_exists('newsx_header_news_ticker_title') ) {
	function newsx_header_news_ticker_title( $class = '' ) {
		$class = '' !== $class ? ' '. $class : '';
		$count = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? newsx_get_option('header_nt_letter_count') : '';
		$count = '' !== $count ? intval($count) : 999;
		$title = strlen(html_entity_decode(get_the_title())) > $count ? substr(html_entity_decode(get_the_title()), 0, $count) . '...' : get_the_title();
		
		echo '<a class="newsx-news-ticker-title'. esc_attr($class) .'" href="'. esc_url( get_permalink() ) .'">';
			echo '<p>'. wp_kses_post( $title ) .'</p>';
		echo '</a>';
	}
}

// Ticker Slider Thumbnail
if ( !function_exists('newsx_header_news_ticker_thumbnail') ) {
	function newsx_header_news_ticker_thumbnail(  ) {
		if ( has_post_thumbnail() ) {

			echo '<a href="'. esc_url( get_permalink() ) .'" class="newsx-slider-image" title="'. esc_attr( get_the_title() ) .'">';
					the_post_thumbnail( 'newsx-150x100' );
			echo '</a>';
		}
	}
}

// Add Query Args to Instance for AJAX
$main_query_args = newsx_get_main_query_args( [], '', 'header_nt' );
$instance['_main_query_args'] = $main_query_args;

// Get Posts
$news_ticker_posts = new WP_Query( $main_query_args );

// Get Post Count
$instance['_post_count'] = $news_ticker_posts->found_posts;

echo '<div class="newsx-news-ticker newsx-header-news-ticker" data-ticker-type="'. esc_attr($header_nt_type_select) .'">';

	// Edit Button
	echo newsx_customizer_edit_button_markup('hd_news_ticker');

	if ( !empty(newsx_get_option('header_nt_heading_text')) || 'none' !== newsx_get_option('header_nt_heading_icon_type') ) {
		echo '<'. esc_attr($heading_element) .' class="news-ticker-heading newsx-'. esc_attr(newsx_get_option('header_nt_heading_bg_style')) .'">';
			echo '<span class="news-ticker-heading-text">'. esc_html($heading_text) .'</span>';
			newsx_header_news_ticker_heading_icon( $data_newsx_settings );
		echo '</'. esc_attr($heading_element) .'>';
	}

	echo '<div class="news-ticker-wrapper" data-newsx-settings="'. esc_attr( wp_json_encode( $data_newsx_settings ) ) .'">';

		echo '<div class="news-ticker-content '. esc_attr($swiper_class) .'">';
			if ( 'marquee' === $header_nt_type_select ) {
				echo '<div class="newsx-ticker-marquee" data-options='. wp_json_encode($marquee_options) .'>';
			} else {
				echo '<div class="swiper-wrapper">';
			}

			if ( $news_ticker_posts->have_posts() ) :
				while ( $news_ticker_posts->have_posts() ) : $news_ticker_posts->the_post();

					echo '<div class="news-ticker-post '. esc_attr($swiper_slide) .'">';
	
					$data_newsx_settings['_el_locations'] = [
						'below' => [
							'title',
						]
					];

					// Widget Instance
					$instance = $data_newsx_settings;
					
					// Ticker Item
					echo '<article class="newsx-flex-center-vr">';
					
						// Thumbnail
						if ( '1' == newsx_get_option('header_nt_show_images') ) {
							newsx_header_news_ticker_thumbnail();
						}
					
						echo '<div>';
							newsx_header_news_ticker_title();
						echo '</div>';
					
					echo '</article>';

					echo '</div>';

				endwhile;
			endif;

			wp_reset_postdata();

			echo '</div>';
		echo '</div>';

		if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
			if ( 'slider' === $header_nt_type_select && newsx_get_option('header_nt_nav') ) {
				echo '<div class="newsx-slider-prev swiper-button-prev"></div>';
				echo '<div class="newsx-slider-next swiper-button-next"></div>';
			}
		}

	echo '</div>';
echo '</div>';
