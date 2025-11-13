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
use Elementor\Plugin;

if (! defined('ABSPATH')) {
  exit;   // Exit if accessed directly.
}

/**
 * Elespare Site tagline widget
 *
 * Elespare widget for site tagline
 *
 * @since 1.0.0
 */
class SiteTagline extends Widget_Base
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
    return 'site-tagline';
  }

  /**
   * Retrieve the widget tagline.
   *
   * @since 1.0.0
   *
   * @access public
   *
   * @return string Widget tagline.
   */
  public function get_title()
  {
    return esc_html__('Site Taglinssssse', 'elespare');
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
    return 'demo-icon elespare-icons-tagline';
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
   * Register site tagline controls.
   *
   * @since 1.5.7
   * @access protected
   */
  protected function register_controls()
  {
    $this->register_general_content_controls();
    $this->register_general_content_styling_controls();
  }

  /**
   * Register site tagline General Controls.
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



    $this->add_responsive_control(
      'heading_text_align',
      [
        'label'     => esc_html__('Alignment', 'elespare'),
        'type'      => Controls_Manager::CHOOSE,
        'options'   => [
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
        'selectors' => [
          '{{WRAPPER}} .elespare-site-tagline' => 'text-align: {{VALUE}};',
        ],
      ]
    );



    $this->end_controls_section();
  }

  /**
   * Register site tagline styling General Controls.
   *
   * @since 1.0.0
   * @access protected
   */

  protected function register_general_content_styling_controls()
  {
    $this->start_controls_section(
      'section_style_site_logo_image',
      [
        'label' => esc_html__('Site Tagline', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'tagline_typography',
        'label'    => esc_html__('Typography', 'elespare'),
        'fields_options' => [
          'typography' => [
            'default' => 'yes'

          ],
          'font_size' => [
            'default' => [
              'unit' => 'px',
              'size' => '14',
            ],
          ],
          'font_weight' => [
            'default' => '700',
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-site-tagline',
      ]
    );
    $this->add_control(
      'tagline_color',
      [
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => \Elementor\Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => [
          '{{WRAPPER}} .elespare-site-tagline' => 'color: {{VALUE}};'
        ],
      ]
    );



    $this->end_controls_section();
  }



  /**
   * Render site tagline output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function render()
  {
    $settings = $this->get_settings_for_display();
?>
    <div class="elespare-site-tagline elespare-site-tagline-wrapper">

      <span>


        <?php echo wp_kses_post(get_bloginfo('description')); ?>

      </span>
    </div>
  <?php
  }

  /**
   * Render Site Tagline widgets output in the editor.
   *
   * Written as a Backbone JavaScript template and used to generate the live preview.
   *
   * @since 1.0.0
   * @access protected
   */
  protected function render_content_template()
  {
  ?>

    <div class="elespare-site-tagline elespare-site-tagline-wrapper">

      <span>

        <?php echo wp_kses_post(get_bloginfo('description')); ?>

      </span>
    </div>
<?php
  }

  /**
   * Render Site Tagline output in the editor.
   *
   * Written as a Backbone JavaScript template and used to generate the live preview.
   *
   * Remove this after Elementor v3.3.0
   *
   * @since 1.0.0
   * @access protected
   */
  protected function content_template()
  {
    $this->render_content_template();
  }
}
