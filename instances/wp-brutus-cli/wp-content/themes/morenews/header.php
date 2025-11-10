<?php

/**
 * The header for our theme
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package MoreNews
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>

<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="profile" href="http://gmpg.org/xfn/11">

  <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
  <?php
  if (function_exists('wp_body_open')) {
    wp_body_open();
  } else {
    do_action('wp_body_open');
  } ?>

  <?php

  $enable_preloader = morenews_get_option('enable_site_preloader');
  if (morenews_is_amp()) {
    $enable_preloader = 0;
  }
  if (1 == $enable_preloader) :
  ?>
    <div id="af-preloader">
      <div id="loader-wrapper">
        <div class="loader1"></div>
        <div class="loader2"></div>
        <div class="loader3"></div>
        <div class="loader4"></div>
      </div>
    </div>
  <?php endif; ?>

  <div id="page" class="site af-whole-wrapper">
    <a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'morenews'); ?></a>

    <?php
    $morenews_enable_header_builder = morenews_get_option('athfb_show_checkbox_header');
    if ($morenews_enable_header_builder) {
      do_action('morenews_action_header_section_builder');
    } else {
      do_action('morenews_action_header_section');
    }


    $page_template = get_page_template_slug();
    if ($page_template !== 'page-templates/full-width.php') {
      // get current page we are on. If not set we can assume we are on page 1.
      $morenews_paged = (get_query_var('paged')) ? get_query_var('paged') : 1;

      // are we on page one?
      if (1 == $morenews_paged) :


        if (is_front_page() && is_home()) {
          // Default homepage
          do_action('morenews_action_front_page_main_section_scope');
          do_action('morenews_action_banner_featured_section');
        } elseif (is_front_page()) {
          // Static homepage
          do_action('morenews_action_front_page_main_section_scope');
          do_action('morenews_action_banner_featured_section');
        } elseif (is_home()) {
          // Blog page
          do_action('morenews_action_front_page_main_section_scope');
        }

    ?>

      <?php endif; ?>
      <?php
      if (!is_front_page() && !is_home()) : ?>
        <div class="aft-main-breadcrumb-wrapper container-wrapper">
          <?php do_action('morenews_action_get_breadcrumb'); ?>
        </div>
      <?php endif; ?>

      <div id="content" class="container-wrapper">
      <?php

    }
    if (is_single()) {
      $single_post_title_view = morenews_get_option('single_post_title_view');
      if ($single_post_title_view == 'full') {
        do_action('morenews_action_single_header');
      }
    }
