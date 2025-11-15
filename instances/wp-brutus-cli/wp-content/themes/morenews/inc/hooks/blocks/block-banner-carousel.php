<?php

/**
 * Full block part for displaying page content in page.php
 *
 * @package MoreNews
 */

$morenews_posts_filter_by = morenews_get_option('select_main_banner_carousel_filterby');
if ($morenews_posts_filter_by == 'tag') {
  $morenews_slider_category = morenews_get_option('select_slider_news_tag');
  $morenews_filterby = 'tag';
} else {
  $morenews_slider_category = morenews_get_option('select_slider_news_category');
  $morenews_filterby = 'cat';
}

$morenews_number_of_slides = morenews_get_option('number_of_slides');
$morenews_slider_mode = morenews_get_option('select_main_banner_section_mode');
$morenews_main_banner_layout_section = morenews_get_option('select_main_banner_layout_section');

$morenews_centerPadding = '';
$morenews_break_point_1_centerPadding = '';
$morenews_break_point_2_centerPadding = '';
$morenews_break_point_3_centerPadding = '';


if (($morenews_main_banner_layout_section == 'layout-5') || ($morenews_main_banner_layout_section == 'layout-6')) {
  $thumbnail_size = 'morenews-large';
} else {
  $thumbnail_size = 'morenews-medium';
}
if ($morenews_main_banner_layout_section == 'layout-7') {
  $morenews_centerMode = false;
  $morenews_slidesToShow = 2;
  $morenews_slidesToScroll = 1;
  $morenews_carousel_class = 'af-carousel-2';
  $morenews_break_point_1_slidesToShow = 2;
  $morenews_break_point_1_slidesToScroll = 1;
  $morenews_break_point_2_slidesToShow = 2;
  $morenews_break_point_2_slidesToScroll = 1;
  $morenews_break_point_3_slidesToShow = 1;
  $morenews_break_point_3_slidesToScroll = 1;
} else {
  $morenews_centerMode = false;
  $morenews_slidesToShow = 1;
  $morenews_slidesToScroll = 1;
  $morenews_carousel_class = 'af-carousel-default';
  $morenews_break_point_1_slidesToShow = 1;
  $morenews_break_point_1_slidesToScroll = 1;
  $morenews_break_point_2_slidesToShow = 1;
  $morenews_break_point_2_slidesToScroll = 1;
  $morenews_break_point_3_slidesToShow = 1;
  $morenews_break_point_3_slidesToScroll = 1;
}

$morenews_carousel_args = array(
  'slidesToShow' => $morenews_slidesToShow,
  'slidesToScroll' => $morenews_slidesToScroll,
  'autoplaySpeed' => 6000,
  'centerMode' => $morenews_centerMode,
  'centerPadding' => $morenews_centerPadding,
  'responsive' => array(
    array(
      'breakpoint' => 1025,
      'settings' => array(
        'slidesToShow' => $morenews_break_point_2_slidesToShow,
        'slidesToScroll' => $morenews_break_point_3_slidesToScroll,
        'infinite' => true,
        'centerPadding' => $morenews_break_point_1_centerPadding,
      ),
    ),
    array(
      'breakpoint' => 600,
      'settings' => array(
        'slidesToShow' => $morenews_break_point_2_slidesToShow,
        'slidesToScroll' => $morenews_break_point_2_slidesToScroll,
        'infinite' => true,
        'centerPadding' => $morenews_break_point_2_centerPadding,
      ),
    ),
    array(
      'breakpoint' => 480,
      'settings' => array(
        'slidesToShow' => $morenews_break_point_3_slidesToShow,
        'slidesToScroll' => $morenews_break_point_3_slidesToScroll,
        'infinite' => true,
        'centerPadding' => $morenews_break_point_3_centerPadding,
      ),
    ),
  ),
);

$morenews_carousel_args_encoded = wp_json_encode($morenews_carousel_args);
// wp_enqueue_style('slick');
// wp_enqueue_script('slick');
?>

<div class="af-widget-carousel aft-carousel">
  <div class="slick-wrapper af-banner-carousel af-banner-carousel-1 common-carousel af-cat-widget-carousel <?php echo esc_html($morenews_carousel_class); ?>"
    data-slick='<?php echo wp_kses_post($morenews_carousel_args_encoded); ?>'>
    <?php
    $morenews_slider_posts = morenews_get_posts($morenews_number_of_slides, $morenews_slider_category, $morenews_filterby);
    if ($morenews_slider_posts->have_posts()) :
      while ($morenews_slider_posts->have_posts()) : $morenews_slider_posts->the_post();
        global $post;

    ?>
        <div class="slick-item">
          <?php do_action('morenews_action_loop_grid', $post->ID, 'grid-design-texts-over-image', $thumbnail_size); ?>
        </div>
    <?php
      endwhile;
    endif;
    wp_reset_postdata();
    ?>
  </div>
  <div class="af-main-navcontrols af-slick-navcontrols"></div>
</div>