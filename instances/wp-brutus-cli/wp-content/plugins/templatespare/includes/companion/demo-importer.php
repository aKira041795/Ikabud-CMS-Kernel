<?php

function templatespare_import_navigation($selected_import, $homepagetype)
{



  global $wpdb;

  $front_page_id = null;

  $home_pages = $wpdb->get_results($wpdb->prepare("
      SELECT ID, post_title
      FROM $wpdb->posts
      WHERE post_type = 'page'
      AND post_status = 'publish'
      AND post_title = %s
      ORDER BY ID DESC
  ", 'Home'));

  if ($home_pages) {
    foreach ($home_pages as $page) {
      if ($page->post_title === 'Home') {
        $front_page_id = $page->ID;
        break;
      }
    }

    if (! $front_page_id) {
      $front_page_id = $home_pages[0]->ID;
    }
  }

  // Get blog page ID
  $blog_page_id = null;
  $blog_page_query = new WP_Query(array(
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'title'          => 'Blog',
  ));
  if ($blog_page_query->have_posts()) {
    $blog_page_id = $blog_page_query->posts[0]->ID;
  }



  // Update options depending on homepage type
  if ($homepagetype === 'blog') {
    // Show latest posts
    update_option('show_on_front', 'posts');
  } else {
    // Show a static page
    if ($front_page_id) {
      update_option('show_on_front', 'page');
      update_option('page_on_front', $front_page_id);
    }

    if ($blog_page_id) {
      update_option('page_for_posts', $blog_page_id);
    }
  }

  // Remove any previous menu assignments to avoid conflicts

  // Get all registered menu locations
  templatespare_nav_menu_setup();
}


add_action('templatespare/after_import', 'templatespare_import_navigation', 10, 3);

add_filter('templatespare_post_content_before_insert', 'templatespare_replace_urls', 10, 2);

function templatespare_replace_urls($content, $old_base_url)
{

  $site_url = get_site_url();
  $site_url = str_replace('/', '\/', $site_url);

  $demo_site_url = str_replace('/', '\/', $old_base_url);
  $content = json_encode($content, true);

  $content = preg_replace('/\\\{1}\/sites\\\{1}\/\d+/', '', $content);

  $content = str_replace($demo_site_url, $site_url, $content);

  $content = json_decode($content, true);

  return $content;
}

add_action('templatespare/after_import_is_not_content', 'templatespare_set_static_front_and_blog_pages', 10, 3);
function templatespare_set_static_front_and_blog_pages($selected_import, $homepagetype)
{



  $home_slug     = sanitize_title('home');
  $blog_slug     = sanitize_title('blog');
  $home_template = 'tmpl-front-page.php';

  // Refresh template cache
  wp_clean_themes_cache();
  delete_site_transient('theme_roots');

  // Check if the home template exists in the theme
  $page_templates  = wp_get_theme()->get_page_templates();
  $template_exists = array_key_exists($home_template, $page_templates);

  // Get pages by slug
  $home_page = get_page_by_path($home_slug);
  $blog_page = get_page_by_path($blog_slug);

  // Create Home page if it doesn't exist
  if (!$home_page instanceof WP_Post) {
    $home_page_id = wp_insert_post(array(
      'post_title'    => 'Home',
      'post_name'     => $home_slug,
      'post_status'   => 'publish',
      'post_type'     => 'page',
      'post_content'  => '',
    ));

    if (!is_wp_error($home_page_id) && $template_exists) {
      update_post_meta($home_page_id, '_wp_page_template', sanitize_text_field($home_template));
    }
  } else {
    $home_page_id = $home_page->ID;

    // Update template if not set correctly
    if ($template_exists) {
      update_post_meta($home_page_id, '_wp_page_template', sanitize_text_field($home_template));
    }
  }

  // Create Blog page if it doesn't exist
  if (!$blog_page instanceof WP_Post) {
    $blog_page_id = wp_insert_post(array(
      'post_title'    => 'Blog',
      'post_name'     => $blog_slug,
      'post_status'   => 'publish',
      'post_type'     => 'page',
      'post_content'  => '',
    ));
  } else {
    $blog_page_id = $blog_page->ID;
  }


  if ($homepagetype === 'blog') {
    // Show latest posts
    update_option('show_on_front', 'posts');
  } else {
    // Show a static page
    if (!empty($home_page_id) && !is_wp_error($home_page_id)) {
      update_option('show_on_front', 'page');
      update_option('page_on_front', $home_page_id);
    }

    if (!empty($blog_page_id) && !is_wp_error($blog_page_id)) {
      update_option('page_for_posts', $blog_page_id);
    }
  }


  // templatespare_nav_menu_setup();
}

function templatespare_nav_menu_setup()
{

  $registered_menus = get_registered_nav_menus();
  $nav_menus = get_terms('nav_menu', array('hide_empty' => true));

  $menus = [];
  foreach ($nav_menus as $menu) {
    $menus[$menu->name] = $menu->term_id;
  }

  $new_menu = [];

  // Loop through the registered menu locations
  foreach ($registered_menus as $location => $description) {

    $matching_menus = [];

    // Loop through the available menus
    foreach ($menus as $menu_name => $menu_id) {

      // Match social menus
      if (stripos($location, 'social') !== false && stripos($menu_name, 'Social') !== false) {

        $matching_menus[] = $menu_id;
      }
      // Match primary menus
      elseif (stripos($location, 'primary') !== false && stripos($menu_name, 'Main') !== false) {

        $matching_menus[] = $menu_id;
      }
      // Match footer menus
      elseif (stripos($location, 'footer') !== false && stripos($menu_name, 'footer') !== false) {

        $matching_menus[] = $menu_id;
      } elseif (stripos($location, 'social') !== false && stripos($menu_name, 'Social') !== false) {

        $matching_menus[] = $menu_id;
      } elseif (stripos($location, 'secondary') !== false && stripos($menu_name, 'Secondary') !== false) {

        $matching_menus[] = $menu_id;
      }
      // Match top menus
      elseif (stripos($location, 'top') !== false && stripos($menu_name, 'Top') !== false) {

        $matching_menus[] = $menu_id;
      }
    }

    // Assign the first matching menu if there are any matches
    if (!empty($matching_menus)) {

      $new_menu[$location] = reset($matching_menus); // Pick first match
      set_theme_mod('nav_menu_locations', $new_menu);
    } else {
      error_log("No matching menu found for location: $location");
    }
  }
}
