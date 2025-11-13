<?php

/**
 * Elementor Classes.
 *
 * @package elespare-pro
 */

namespace Elespare\Widgets;

use Elementor\Widget_Base;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Utils;
use Elementor\Plugin;


if (! defined('ABSPATH')) {
  exit;   // Exit if accessed directly.
}

/**
 * Elespare Site title widget
 *
 * Elespare widget for site title
 *
 * @since 1.0.0
 */
class SiteTitle extends Widget_Base
{

  /**
   * Retrieve the widget name.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget name.
   */
  public function get_name()
  {
    return 'elespare-site-title';
  }

  /**
   * Retrieve the widget title.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget title.
   */
  public function get_title()
  {
    return esc_html__('Site Title', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  /**
   * Retrieve the widget icon.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget icon.
   */
  public function get_icon()
  {
    return 'demo-icon elespare-icons-site-title';
  }

  /**
   * Retrieve the list of categories the widget belongs to.
   *
   * Used to determine where to display the widget in the editor.
   *
   * Note that currently Elementor supports only one category.
   * When multiple categories passed, Elementor uses the first one.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return array Widget categories.
   */
  public function get_categories()
  {
    return ['elespare'];
  }


  /**
   * Register site title controls.
   *
   * @since 1.5.7
   * @access protected
   */
  protected function register_controls()
  {

    $this->register_general_content_controls();
    $this->register_heading_typo_content_controls();
  }

  /**
   * Register Advanced Heading General Controls.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function register_general_content_controls()
  {

    $this->start_controls_section(
      'section_general_fields',
      [
        'label' => esc_html__('General', 'elespare'),
      ]
    );

    $this->add_control(
      'heading_tag',
      [
        'label'   => esc_html__('HTML Tag', 'elespare'),
        'type'    => Controls_Manager::SELECT,
        'options' => [
          'h1' => esc_html__('H1', 'elespare'),
          'h2' => esc_html__('H2', 'elespare'),
          'h3' => esc_html__('H3', 'elespare'),
          'h4' => esc_html__('H4', 'elespare'),
          'h5' => esc_html__('H5', 'elespare'),
          'h6' => esc_html__('H6', 'elespare'),
        ],
        'default' => 'h1',
      ]
    );

    $this->add_responsive_control(
      'heading_text_align',
      [
        'label'        => esc_html__('Alignment', 'elespare'),
        'type'         => Controls_Manager::CHOOSE,
        'options'      => [
          'left'    => [
            'title' => esc_html__('Left', 'elespare'),
            'icon'  => 'fa fa-align-left',
          ],
          'center'  => [
            'title' => esc_html__('Center', 'elespare'),
            'icon'  => 'fa fa-align-center',
          ],
          'right'   => [
            'title' => esc_html__('Right', 'elespare'),
            'icon'  => 'fa fa-align-right',
          ],
          'justify' => [
            'title' => esc_html__('Justify', 'elespare'),
            'icon'  => 'fa fa-align-justify',
          ],
        ],
        'selectors'    => [
          '{{WRAPPER}} .elespare-heading' => 'text-align: {{VALUE}};',
        ],
        'prefix_class' => 'ele%s-heading-align-',
      ]
    );
    $this->end_controls_section();
  }


  /**
   * Register Advanced Heading Typography Controls.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function register_heading_typo_content_controls()
  {
    $this->start_controls_section(
      'section_heading_typography',
      [
        'label' => esc_html__('Title', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'heading_typography',
        'label'    => esc_html__('Typography', 'elespare'),
        'fields_options' => [
          'typography' => [
            'default' => 'yes'

          ],
          'font_size' => [
            'default' => [
              'unit' => 'px',
              'size' => '32',
            ],
          ],
          'font_weight' => [
            'default' => '700',
          ],

        ],
        'selector' => '{{WRAPPER}} .elementor-heading-title',
      ]
    );
    $this->add_control(
      'heading_color',
      [
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => [
          '{{WRAPPER}} .elespare-heading-text' => 'color: {{VALUE}};'
        ],
      ]
    );


    $this->end_controls_section();
  }

  /**
   * Render Heading output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function render()
  {

    $settings = $this->get_settings();
    $title    = get_bloginfo('name');

    $this->add_inline_editing_attributes('heading_title', 'basic');


    $heading_size_tag = $this->validate_html_tag($settings['heading_tag']);
?>

    <div class="elespare-module-content elespare-heading-wrapper elementor-widget-heading">

      <a href="<?php echo esc_url(get_home_url()); ?>">

        <<?php echo esc_attr($heading_size_tag); ?> class="elespare-heading elementor-heading-title">

          <span class="elespare-heading-text">

            <?php echo wp_kses_post($title); ?>


          </span>
        </<?php echo esc_attr($heading_size_tag); ?>>
      </a>
    </div>
  <?php
  }
  /**
   * Render site title output in the editor.
   *
   * Written as a Backbone JavaScript template and used to generate the live preview.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function content_template()
  {
  ?>
    <# if ( ''==settings.heading_title ) { return; } if ( ''==settings.size ){ return; } var
      headingSizeTag=settings.heading_tag; if ( typeof elementor.helpers.validateHTMLTag==="function" ) {
      headingSizeTag=elementor.helpers.validateHTMLTag( headingSizeTag ); } else if( ElespareLocalize.allowed_tags ) {
      headingSizeTag=ElespareLocalize.allowed_tags.includes( headingSizeTag.toLowerCase() ) ? headingSizeTag : 'div' ; } #>
      <div class="elespare-module-content elespare-heading-wrapper elementor-widget-heading">

        <a {{{ view.getRenderAttributeString( 'url' ) }}}>

          <{{{ headingSizeTag }}} class="elespare-heading elementor-heading-title elementor-size-{{{ settings.size }}}">

            <span class="elespare-heading-text  elementor-heading-title" data-elementor-setting-key="heading_title"
              data-elementor-inline-editing-toolbar="basic">

              <?php echo wp_kses_post(get_bloginfo('name')); ?>

            </span>
          </{{{ headingSizeTag }}}>

        </a>

      </div>
  <?php
  }

  /**
   * Render site title output in the editor.
   *
   * Written as a Backbone JavaScript template and used to generate the live preview.
   *
   * Remove this after Elementor v3.3.0
   *
   * @since 1.0.0
   * @access protected
   */

  public static function validate_html_tag($tag)
  {

    // Check if Elementor method exists, else we will run custom validation code.
    if (method_exists('Elementor\Utils', 'validate_html_tag')) {
      return Utils::validate_html_tag($tag);
    } else {
      $allowed_tags = ['article', 'aside', 'div', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'main', 'nav', 'p', 'section', 'span'];
      return in_array(strtolower($tag), $allowed_tags) ? $tag : 'div';
    }
  }
}
