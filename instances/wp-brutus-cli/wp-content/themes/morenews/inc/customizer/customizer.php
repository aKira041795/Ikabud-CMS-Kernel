<?php

/**
 * MoreNews Theme Customizer
 *
 * @package MoreNews
 */

if (!function_exists('morenews_get_option')):
  /**
   * Get theme option.
   *
   * @since 1.0.0
   *
   * @param string $key Option key.
   * @return mixed Option value.
   */
  function morenews_get_option($key)
  {

    if (empty($key)) {
      return;
    }

    $value = '';

    $default       = morenews_get_default_theme_options();
    $default_value = null;

    if (is_array($default) && isset($default[$key])) {
      $default_value = $default[$key];
    }

    if (null !== $default_value) {
      $value = get_theme_mod($key, $default_value);
    } else {
      $value = get_theme_mod($key);
    }

    return $value;
  }
endif;

// Load customize default values.
require get_template_directory() . '/inc/customizer/customizer-callback.php';

// Load customize default values.
require get_template_directory() . '/inc/customizer/customizer-default.php';

/**
 * Add postMessage support for site title and description for the Theme Customizer.
 *
 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
 */
function morenews_customize_register($wp_customize)
{

  // Load customize controls.
  require get_template_directory() . '/inc/customizer/customizer-control.php';

  // Load customize sanitize.
  require get_template_directory() . '/inc/customizer/customizer-sanitize.php';

  $wp_customize->get_setting('blogname')->transport         = 'postMessage';
  $wp_customize->get_setting('blogdescription')->transport  = 'postMessage';
  $wp_customize->get_setting('header_textcolor')->transport = 'postMessage';

  $wp_customize->get_control('background_color')->label = __('Body Background Color', 'morenews');

  if (isset($wp_customize->selective_refresh)) {
    $wp_customize->selective_refresh->add_partial('blogname', array(
      'selector'        => '.site-title a',
      'render_callback' => 'morenews_customize_partial_blogname',
    ));
    $wp_customize->selective_refresh->add_partial('blogdescription', array(
      'selector'        => '.site-description',
      'render_callback' => 'morenews_customize_partial_blogdescription',
    ));
  }

  $default = morenews_get_default_theme_options();

  // Setting - secondary_font.
  $wp_customize->add_setting(
    'site_title_font_size',
    array(
      'default'           => $default['site_title_font_size'],
      'capability'        => 'edit_theme_options',
      'sanitize_callback' => 'sanitize_text_field',
    )
  );

  $wp_customize->add_control(
    'site_title_font_size',
    array(
      'label'    => esc_html__('Site Title Size', 'morenews'),
      'section'  => 'title_tagline',
      'type'     => 'number',
      'priority' => 50,
    )
  );

  // Setting - header overlay.
  $wp_customize->add_setting(
    'site_title_uppercase',
    array(
      'default'           => $default['site_title_uppercase'],
      'capability'        => 'edit_theme_options',
      'sanitize_callback' => 'morenews_sanitize_checkbox',
    )
  );

  $wp_customize->add_control(
    'site_title_uppercase',
    array(
      'label'    => esc_html__('Uppercase Site Title and Tagline', 'morenews'),
      'section'  => 'title_tagline',
      'type'     => 'checkbox',
      'priority' => 50,
    )
  );


  $wp_customize->get_section('header_image')->panel = 'site_header_option_panel';

  // use get control
  $wp_customize->get_control('header_textcolor')->label = __('Site Title/Tagline Color', 'morenews');
  $wp_customize->get_control('header_textcolor')->section = 'colors';
  $wp_customize->get_control('header_textcolor')->section = 'colors';
  $wp_customize->get_control('header_textcolor')->priority = 5;

  // Setting - header_image.
  $wp_customize->get_control('header_image')->section = 'header_builder';
  $wp_customize->get_control('header_image')->priority = 10;

  // Setting - select_main_banner_section_mode.
  $wp_customize->add_setting(
    'select_header_image_mode',
    array(
      'default'           => $default['select_header_image_mode'],
      'capability'        => 'edit_theme_options',
      'sanitize_callback' => 'morenews_sanitize_select',
    )
  );

  $wp_customize->add_control(
    'select_header_image_mode',
    array(
      'label'       => esc_html__('Header Image Mode', 'morenews'),
      'description'       => esc_html__('Image visibility may vary as per the mode', 'morenews'),
      'section'     => 'header_builder',
      'type'        => 'select',
      'choices'               => array(
        'above' => esc_html__("Above Site Title", 'morenews'),
        'default' => esc_html__("Set as Background", 'morenews'),
        'full' => esc_html__("Show Full Image (Background)", 'morenews'),
      ),
      'priority'    => 50
    )
  );


  // Setting - header overlay.
  $wp_customize->add_setting(
    'disable_header_image_tint_overlay',
    array(
      'default'           => $default['disable_header_image_tint_overlay'],
      'capability'        => 'edit_theme_options',
      'sanitize_callback' => 'morenews_sanitize_checkbox',
    )
  );

  $wp_customize->add_control(
    'disable_header_image_tint_overlay',
    array(
      'label'    => esc_html__('Disable Image Tint/Overlay', 'morenews'),
      'section'  => 'header_builder',
      'type'     => 'checkbox',
      'priority' => 50,
      'active_callback' => 'morenews_disable_header_image_tint_overlay_status'
    )
  );


  /*theme option panel info*/
  require get_template_directory() . '/inc/customizer/theme-options.php';

  // Register custom section types.
  $wp_customize->register_section_type('MoreNews_Customize_Section_Upsell');

  // Register sections.
  $wp_customize->add_section(
    new MoreNews_Customize_Section_Upsell(
      $wp_customize,
      'theme_upsell',
      array(
        'title'    => esc_html__('MoreNews Pro', 'morenews'),
        'pro_text' => esc_html__('Upgrade Now', 'morenews'),
        'pro_url'  => 'https://www.afthemes.com/products/morenews-pro/',
        'priority'  => 1,
      )
    )
  );
}
add_action('customize_register', 'morenews_customize_register');

/**
 * Render the site title for the selective refresh partial.
 *
 * @return void
 */
function morenews_customize_partial_blogname()
{
  bloginfo('name');
}

/**
 * Render the site tagline for the selective refresh partial.
 *
 * @return void
 */
function morenews_customize_partial_blogdescription()
{
  bloginfo('description');
}

/**
 * Binds JS handlers to make Theme Customizer preview reload changes asynchronously.
 */
function morenews_customize_preview_js()
{
  wp_enqueue_script('morenews-customizer', get_template_directory_uri() . '/js/customizer.js', array('customize-preview'), '20151215', true);
}

add_action('customize_preview_init', 'morenews_customize_preview_js');


function morenews_customizer_css()
{
  wp_enqueue_script('morenews-customize-controls', get_template_directory_uri() . '/assets/customizer-admin.js', array('customize-controls'));

  wp_enqueue_style('morenews-customize-controls-style', get_template_directory_uri() . '/assets/customizer-admin.css');
}
add_action('customize_controls_enqueue_scripts', 'morenews_customizer_css', 0);
