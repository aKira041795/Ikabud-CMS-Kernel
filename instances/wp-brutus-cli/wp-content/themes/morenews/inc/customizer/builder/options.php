<?php
if (!function_exists('athfb_get_default_header_structure')) {
  function athfb_get_default_header_structure()
  {
    return json_encode(array(
      'header_desktop_items' => array(
        'top' => array(
          'top_left' => array('header_date'),
          'top_center' => array(),
          'top_right' => array('header_social_icons'),
        ),
        'main' => array(
          'main_left' => array('header_logo'),
          'main_center' => array(),
          'main_right' => array('header_promotion'),
        ),
        'bottom' => array(
          'bottom_left' => array('header_off_canvas', 'header_navigation'),
          'bottom_center' => array(),
          'bottom_right' => array('header_site_mode', 'header_search', 'header_button'),
        ),
      ),
    ));
  }
}

if (!function_exists('athfb_get_default_footer_structure')) {
  function athfb_get_default_footer_structure()
  {
    return json_encode(array(
      'footer_desktop_items' => array(
        'top' => array(
          'top_left' => array('footer_widget_1'),
          'top_center' => array(),
          'top_right' => array(),
        ),
        'main' => array(
          'main_left' => array('footer_navigation'),
          'main_center' => array(),
          'main_right' => array('footer_social_icons'),
        ),
        'bottom' => array(
          'bottom_left' => array(),
          'bottom_center' => array('footer_copyright'),
          'bottom_right' => array(),
        ),
      ),
    ));
  }
}

if (!function_exists('athfb_get_current_device')) {
  function athfb_get_current_device()
  {
    return 'desktop';
  }
}

/**
 * Check if row has elements
 */

if (!function_exists('athfb_has_elements_in_row')) {
  function athfb_has_elements_in_row($row_data)
  {
    foreach ($row_data as $column) {
      if (!empty($column)) {
        return true;
      }
    }
    return false;
  }
}

if (!function_exists('athfb_get_all_registered_blocks')) {
  function athfb_get_all_registered_blocks()
  {
    $all_blocks = get_available_blocks(); // Header blocks
    $footer_blocks = athfb_get_footer_available_blocks(); // Footer blocks
    return array_merge($all_blocks, $footer_blocks);
  }
}

if (!function_exists('get_available_blocks')) {
  function get_available_blocks()
  {
    return array(
      'header_logo' => array(
        'label' => esc_html__('Site & Logo', 'morenews'),
        'icon' => 'dashicons-format-image',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(

          'site_msg' => array(
            'label' => esc_html__('Site Identity', 'morenews'),
            'type' => 'hidden', // important
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to Site Identity panel */
              __('You can change Site Identity from %s.', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="title_tagline">' . esc_html__('Site Identity', 'morenews') . '</a>'
            ),
          )
        ),
      ),


      'header_promotion' => array(
        'label' => esc_html__('Promotion', 'morenews'),
        'icon' => 'dashicons-megaphone',
        'elfocus' => 'banner_advertisement_section',
        'settings' => array(
          'promotion_area' => array(
            'label' => esc_html__('Promotion', 'morenews'),
            'type' => 'hidden',
            'value' => '',
            'sanitize' => 'sanitize_text_field',
            // 'description' => __(
            //   'This widget area is used in the mobile off-canvas panel. <a href="#" class="athfb-widget-panel-link" data-panel="frontpage_advertisement_settings">Manage Adevertise</a>',
            //   'morenews'
            // ),

          )
        )

      ),

      'header_off_canvas' => array(
        'label' => esc_html__('Off Canvas', 'morenews'),
        'icon'  => 'dashicons-welcome-widgets-menus',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'widget_area' => array(
            'label'       => esc_html__('Off Canvas Widget Area', 'morenews'),
            'type'        => 'hidden',
            'value'       => 'express-off-canvas-panel',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              __('This widget area is used in the mobile off-canvas panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="sidebar-widgets-express-off-canvas-panel">' . esc_html__('Manage widgets', 'morenews') . '</a>'
            ),
          ),
        ),
      ),


      'header_navigation' => array(
        'label' => esc_html__('Primary menu', 'morenews'),
        'icon' => 'dashicons-menu',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'menu_id' => array(
            'label' => esc_html__('Select Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-primary-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menus */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
          ),
        ),
      ),
      'header_date' => array(
        'label' => esc_html__('Date', 'morenews'),
        'icon' => 'dashicons-calendar',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'placeholder' => array(
            'label' => esc_html__('Choose date format', 'morenews'),
            'type' => 'hidden', // important
            //'choices' => athfb_get_date_format_choices(),
            'default' => get_option('date_format', ''),
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menus */
              __('You can change date from general settings (Settings > General > Date Format). %s', 'morenews'),
              '<a href="' . esc_url(admin_url('options-general.php')) . '">' . esc_html__('Manage Date', 'morenews') . '</a>'
            ),
          ),
        ),
      ),
      'header_top_navigation' => array(
        'label' => esc_html__('Top Navigation', 'morenews'),
        'icon' => 'dashicons-menu',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'menu_id' => array(
            'label' => esc_html__('Select Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-top-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menu */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
            'is_lock' => true,
            'lock_msg' => __('pro', 'morenews')

          ),
        ),
      ),
      'header_site_mode' => array(
        'label' => esc_html__('Site Mode', 'morenews'),
        'icon' => 'dashicons-lightbulb',
        'elfocus' => 'enable_site_mode_switch',
        'settings' => array(
          'placeholder' => array(
            'label' => esc_html__('Site Mode', 'morenews'),
            'type' => 'hidden', // important
            'default' => 'light',
            'sanitize' => 'sanitize_text_field',

          ),
        ),
      ),
      'header_html' => array(
        'label' => esc_html__('Custom HTML', 'morenews'),
        'icon' => 'dashicons-editor-code',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'custom_html' => array(
            'label' => esc_html__('HTML Content', 'morenews'),
            'type' => 'hidden',
            'default' => '',
            'sanitize' => 'morenews_sanitize_custom_html',
            'is_lock' => true,
            'lock_msg' => __('pro', 'morenews')

          ),
        )
      ),
      'header_search' => array(
        'label' => esc_html__('Search', 'morenews'),
        'icon' => 'dashicons-search',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'placeholder' => array(
            'label' => esc_html__('Searc Form', 'morenews'),
            'type' => 'hidden',
            'sanitize' => 'sanitize_text_field',


          ),
        ),
      ),
      'header_button' => array(
        'label' => esc_html__('Custom Link', 'morenews'),
        'icon' => 'dashicons-button',
        'elfocus' => 'show_watch_online_section_section_title',
        'settings' => array(
          'text' => array(
            'label' => esc_html__('Custom link', 'morenews'),
            'type' => 'hidden',
            'sanitize' => 'sanitize_text_field',
            'default' => esc_html__('live', 'morenews'),

          )
        )
      ),
      'header_social_icons' => array( // This is a generic social icons block, could be used in header
        'label' => esc_html__('Social Menu', 'morenews'),
        'icon' => 'dashicons-share',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'placeholder' => array(
            'label' => esc_html__('Social Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-social-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to the menu panel */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
          ),
        ),
      ),
      'header_widget_1' => array(
        'label' => esc_html__('Widget 1', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'hidden',
            'choices' => athfb_get_all_registered_widgets(),
            'default' => 'header-1-widgets',
            'sanitize' => 'sanitize_text_field',
            'is_lock' => true,
            'lock_msg' => __('pro', 'morenews')
          ),
        ),
      ),
      'header_widget_2' => array(
        'label' => esc_html__('Widget 2', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'hidden',
            'choices' => athfb_get_all_registered_widgets(),
            'default' => 'header-2-widgets',
            'sanitize' => 'sanitize_text_field',
            'is_lock' => true,
            'lock_msg' => __('pro', 'morenews')
          ),
        ),
      ),
      'header_widget_3' => array(
        'label' => esc_html__('Widget 3', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_header',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'select',
            'choices' => athfb_get_all_registered_widgets(),
            'default' => 'header-3-widgets',
            'sanitize' => 'sanitize_text_field',
            'is_lock' => true,
            'lock_msg' => __('pro', 'morenews')
          ),
        ),
      ),
    );
  }
}

if (!function_exists('athfb_get_footer_available_blocks')) {
  function athfb_get_footer_available_blocks()
  {
    return array(
      'footer_navigation' => array(
        'label' => esc_html__('Navigation', 'morenews'),
        'icon' => 'dashicons-menu',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'menu_id' => array(
            'label' => esc_html__('Select Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-primary-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menu */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
          ),
        ),
      ),


      'footer_html' => array(
        'label' => esc_html__('Custom HTML', 'morenews'),
        'icon' => 'dashicons-editor-code',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'custom_html' => array(
            'label' => esc_html__('HTML Content', 'morenews'),
            'type' => 'textarea',
            'default' => '',
            'sanitize' => 'wp_kses_post',
            'is_lock' => true,
            'lock_msg' => __('Pro', 'morenews')

          ),
        )
      ),

      'footer_copyright' => array(
        'label' => esc_html__('Copyright', 'morenews'),
        'icon' => 'dashicons-admin-page',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'text' => array(
            'label' => '',
            'type' => 'hidden',
            'sanitize' => 'sanitize_textarea_field',
          ),
        ),

      ),
      'footer_social_icons' => array( // This is a generic social icons block, could be used in header
        'label' => esc_html__('Social Menu', 'morenews'),
        'icon' => 'dashicons-share',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'placeholder' => array(
            'label' => esc_html__('Social Menu', 'morenews'),
            'type' => 'hidden',
            // 'choices' => athfb_get_nav_menus(),
            'default' => 'aft-social-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menu */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),

          ),
        ),
      ),


      'footer_widget_1' => array(
        'label' => esc_html__('Widget 1', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'hidden',
           // 'choices' => athfb_get_all_registered_widgets(),
            'default' => 'footer-first-widgets-section',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              __('Add widget to display . %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="sidebar-widgets-footer-first-widgets-section">' . esc_html__('Manage widgets', 'morenews') . '</a>'
            ),
          ),
        ),
      ),
      'footer_widget_2' => array(
        'label' => esc_html__('Widget 2', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_all_registered_widgets(),
            'default' => 'footer-second-widgets-section',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              __('Add widget to display . %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="sidebar-widgets-footer-second-widgets-section">' . esc_html__('Manage widgets', 'morenews') . '</a>'
            ),

            // 'is_lock' => true,
            // 'lock_msg' => __('pro', 'morenews')
          ),
        ),
      ),
      'footer_widget_3' => array(
        'label' => esc_html__('Widget 3', 'morenews'),
        'icon' => 'dashicons-menu-alt',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'widget' => array(
            'label' => esc_html__('Select Widget', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_all_registered_widgets(),
            'default' => 'footer-third-widgets-section',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              __('Add widget to display . %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="sidebar-widgets-footer-third-widgets-section">' . esc_html__('Manage widgets', 'morenews') . '</a>'
            ),
            // 'is_lock' => true,
            // 'lock_msg' => __('pro', 'morenews')
          ),
        ),
      ),
      'footer_logo' => array(
        'label' => esc_html__('Site & Logo', 'morenews'),
        'icon' => 'dashicons-format-image',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(

          'site_msg' => array(
            'label' => esc_html__('Site Identity', 'morenews'),
            'type' => 'hidden', // important
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to Site Identity panel */
              __('You can change Site Identity from %s.', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="title_tagline">' . esc_html__('Site Identity', 'morenews') . '</a>'
            ),
            'is_lock' => true,
            'lock_msg' => __('Beta', 'morenews')
          )
        ),
      ),
      'footer_promotion' => array(
        'label' => esc_html__('Promotion', 'morenews'),
        'icon' => '',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'promotion_area' => array(
            'label' => esc_html__('Promotion', 'morenews'),
            'type' => 'hidden',
            'value' => '',
            'sanitize' => 'sanitize_text_field',
            'is_lock' => true,
            'lock_msg' => __('Beta', 'morenews')

          )
        )

      ),

      'footer_off_canvas' => array(
        'label' => esc_html__('Off Canvas', 'morenews'),
        'icon'  => 'dashicons-welcome-widgets-menus',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'widget_area' => array(
            'label'       => esc_html__('Off Canvas Widget Area', 'morenews'),
            'type'        => 'hidden',
            'value'       => 'express-off-canvas-panel',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              __('This widget area is used in the mobile off-canvas panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="sidebar-widgets-express-off-canvas-panel">' . esc_html__('Manage widgets', 'morenews') . '</a>'
            ),
            'is_lock' => true,
            'lock_msg' => __('Beta', 'morenews')
          ),
        ),
      ),
      'footer_secondary_navigation' => array(
        'label' => esc_html__('Primary menu', 'morenews'),
        'icon' => 'dashicons-menu',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'menu_id' => array(
            'label' => esc_html__('Select Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-primary-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menus */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
            'is_lock' => true,
            'lock_msg' => __('Beta', 'morenews')
          ),
        ),
      ),
      'footer_top_navigation' => array(
        'label' => esc_html__('Top Navigation', 'morenews'),
        'icon' => 'dashicons-menu',
        'elfocus' => 'athfb_show_checkbox_footer',
        'settings' => array(
          'menu_id' => array(
            'label' => esc_html__('Select Menu', 'morenews'),
            'type' => 'hidden',
            //'choices' => athfb_get_nav_menus(),
            'default' => 'aft-top-nav',
            'sanitize' => 'sanitize_text_field',
            'description' => sprintf(
              /* translators: %s is a link to manage menu */
              __('You can change Menu from menu panel. %s', 'morenews'),
              '<a href="#" class="athfb-widget-panel-link" data-panel="menu_locations">' . esc_html__('Manage menu', 'morenews') . '</a>'
            ),
            'is_lock' => true,
            'lock_msg' => __('Beta', 'morenews')

          ),
        ),
      )
    );
  }
}



if (!function_exists('athfb_get_nav_menus')) {
  function athfb_get_nav_menus()
  {

    $choices   = array('' => esc_html__('Select Menu', 'morenews'));
    $locations = get_registered_nav_menus();  // ['aft-primary-nav' => 'Primary Menu', ...]
    $menu_ids  = get_nav_menu_locations();    // ['aft-primary-nav' => 3, ...]

    foreach ($locations as $location_key => $label) {
      $menu_name = esc_html__('No menu assigned', 'morenews');

      if (isset($menu_ids[$location_key])) {
        $menu_obj = wp_get_nav_menu_object($menu_ids[$location_key]);
        if ($menu_obj) {
          $menu_name = $menu_obj->name;
        }
      }

      $choices[$location_key] = $label . ' - ' . $menu_name;
    }

    return $choices;
  }
}
if (!function_exists('athfb_get_date_format_choices')) {
  function athfb_get_date_format_choices()
  {
    $date = current_time('timestamp');
    return [
      'F j, Y' => date('F j, Y', $date),       // July 16, 2025
      'Y-m-d' => date('Y-m-d', $date),         // 2025-07-16
      'm/d/Y' => date('m/d/Y', $date),         // 07/16/2025
      'd/m/Y' => date('d/m/Y', $date),         // 16/07/2025
      'd.m.Y' => date('d.m.Y', $date),         // 16.07.2025
    ];
  }
}

function athfb_get_all_registered_widgets()
{
  global $wp_registered_sidebars;
  $sidebars = [];

  foreach ($wp_registered_sidebars as $sidebar) {
    // You can filter by prefix or description
    //if (strpos($sidebar['description'], 'morenews') !== false || strpos($sidebar['id'], 'home-') === 0 || strpos($sidebar['id'], 'single-') === 0 || strpos($sidebar['id'], 'footer-') === 0) {
    $sidebars[$sidebar['id']] = $sidebar['name'];
    //}
  }

  return $sidebars;
}
