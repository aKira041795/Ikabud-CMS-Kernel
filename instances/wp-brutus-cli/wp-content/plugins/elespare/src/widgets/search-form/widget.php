<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Background;
use Elementor\Plugin;
// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */

/**
 * Site Search Widget
 *
 * Elementor widget for Site Search.
 */
class Site_Search extends Widget_Base
{

  /**
   * Retrieve the widget name.
   *
   * @return string Widget name.
   */
  public function get_name()
  {
    return 'search-from';
  }

  /**
   * Retrieve the widget title.
   *
   * @return string Widget title.
   */
  public function get_title()
  {
    return esc_html__('Site Search', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  /**
   * Retrieve the widget icon.
   *
   * @return string Widget icon.
   */
  public function get_icon()
  {
    return 'demo-icon elespare-icons-site-search';
  }

  public function get_script_depends()
  {
    return array('elespare-custom-scripts');
  }


  public function get_categories()
  {
    return array('elespare');
  }

  /**
   * Register the widget controls.
   *
   * Adds different input fields to allow the user to change and customize the widget settings.
   */
  protected function register_controls()
  {
    $this->elespare_search_content_Layout_options();
    $this->elespare_search_content_style_options();
    $this->elespare_inner_search_text_style_options();
    $this->elespare_search_text_style_options();
  }


  private function elespare_search_content_Layout_options()
  {

    // phpcs:ignore
    $this->start_controls_section(
      'section_content',
      array(
        'label' => esc_html__('Search', 'elespare'),
      )
    );

    $this->add_control(
      'layout',
      array(
        'label'   => esc_html__('Layout', 'elespare'),
        'type'    => Controls_Manager::SELECT,
        'options' => array(
          'icon' => 'Icon',
          'form' => 'Form',
        ),
        'default' => 'icon',
      )
    );

    $this->add_control(
      'icon',
      array(
        'label'     => esc_html__('Select Icon', 'elespare'),
        'type' => Controls_Manager::ICONS,
        'label_block' => true,
        'default' => [
          'value' => 'demo-icon elespare-icons-search',
          'library' => 'reguler'
        ],
        'condition' => [
          'layout' => 'icon',
        ],

      )
    );


    $this->add_control(
      'text',
      array(
        'label'       => esc_html__('Label', 'elespare'),
        'type'        => Controls_Manager::TEXT,
        'placeholder' => esc_html__('Enter Label', 'elespare'),
        'default' => esc_html__('Search', 'elespare'),
      )
    );

    $this->add_control(
      'placeholder',
      array(
        'label'       => esc_html__('Placeholder', 'elespare'),
        'type'        => Controls_Manager::TEXT,
        'placeholder' => esc_html__('Enter Placeholder', 'elespare'),
        'default' => esc_html__('Search', 'elespare'),
      )
    );

    $this->add_responsive_control(
      'align',
      array(
        'label'     => esc_html__('Align', 'elespare'),
        'type'      => Controls_Manager::CHOOSE,
        'default'   => 'left',
        'options'   => array(
          'left'   => array(
            'icon'  => 'eicon-h-align-left',
            'title' => 'Left',
          ),
          'center' => array(
            'icon'  => 'eicon-h-align-center',
            'title' => 'Center',
          ),
          'right'  => array(
            'icon'  => 'eicon-h-align-right',
            'title' => 'Right',
          ),
        ),
        'prefix_class' => 'elespare-grid%s-',
        'condtion' => [
          'layout' => 'icon'
        ]
      )
    );




    $this->add_control(
      'padding',
      array(
        'label'      => esc_html__('Icon Padding', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px'),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-search-icon--toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ),
        'condition'  => array(
          'layout' => 'icon',
        ),
      )
    );

    $this->end_controls_section();
  }

  private function elespare_search_content_style_options()
  {

    $this->start_controls_section(
      'section_style',
      array(
        'label' => esc_html__('General Style', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );



    $this->add_control(
      'icon_color',
      array(
        'label'     => esc_html__('Icon Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'selectors' => array(
          '{{WRAPPER}} .elespare-search-icon--toggle' => 'color: {{VALUE}}',
          '{{WRAPPER}} .btn-elespare-search-form:before' => 'color: {{VALUE}}',
        ),
        'condition' => [
          'layout' => 'icon',
        ],
      )
    );

    $this->add_control(
      'form_bg_color',
      array(
        'label'     => esc_html__('Background Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default' => '#fff',
        'selectors' => array(
          '{{WRAPPER}} .elespare-search-wrapper .elespare--search-sidebar-wrapper .site-search-form' => 'background-color: {{VALUE}}',
        ),
      )
    );
    $this->add_control(
      'form_padding',
      array(
        'label'      => esc_html__('Padding', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px'),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-search-wrapper .elespare--search-sidebar-wrapper .site-search-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ),
      )
    );

    $this->add_control(
      'content_typography',
      array(

        'label'    => esc_html__('Icon Size', 'elespare'),
        'type'      => Controls_Manager::SLIDER,
        'default'   => [
          'size' => 18,
        ],
        'range'     => [
          'px' => [
            'min' => 0,
            'max' => 100,
          ],
        ],
        'condition' => array(
          'layout' => 'icon',
        ),
        'selectors' => [
          '{{WRAPPER}} .elespare-search-icon--toggle' => 'font-size: {{SIZE}}{{UNIT}}'
        ]
      )
    );

    $this->add_group_control(
      Group_Control_Border::get_type(),
      array(
        'name'     => 'border',
        'label'    => esc_html__('Border', 'elespare-pro'),
        'selector' => '{{WRAPPER}} .elespare-search-icon--toggle',
        'condition' => [
          'layout' => 'icon',
        ],
      )
    );


    $this->add_control(
      'bdrs',
      array(
        'label'      => esc_html__('Border Radius', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px'),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-search-icon--toggle' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .site-search-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ),
      )
    );


    $this->end_controls_section();
  }


  private function elespare_inner_search_text_style_options()
  {

    $this->start_controls_section(
      'section_inner_text_style',
      array(
        'label' => esc_html__('Button Settings', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'      => 'search_text_typography',
        'label'     => esc_html__('Search Typography', 'elespare'),
        'selector'  => '{{WRAPPER}} .elespare--search-sidebar-wrapper .btn-elespare-search-form',
      )
    );

    $this->add_control(
      'button_color',
      array(
        'label'     => esc_html__('Button Text Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'selectors' => array(
          '{{WRAPPER}} .elespare-search--toggle-dropdown .btn-elespare-search-form' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-search-toggle--wrapper .btn-elespare-search-form' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-search-form-header .btn-elespare-search-form' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_group_control(
      Group_Control_Background::get_type(),
      array(
        'name'     => 'background_text',
        'label'    => esc_html__('Background', 'elespare'),
        'types'    => array('classic', 'gradient'),
        'selector' => '{{WRAPPER}} .elespare--search-sidebar-wrapper  .btn-elespare-search-form',
      )
    );
    $this->end_controls_section();
  }


  private function elespare_search_text_style_options()
  {

    $this->start_controls_section(
      'section_text_style',
      array(
        'label' => esc_html__('Input Settings', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'      => 'placeholder_typography',
        'label'     => esc_html__('Placeholder Typography', 'elespare'),
        'selector'  => '{{WRAPPER}} .site-search-form ::placeholder'
      )
    );
    $this->add_control(
      'placeholder_color',
      array(
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'selectors' => array(
          '{{WRAPPER}} .site-search-form ::placeholder ' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_control(
      'input_header',
      array(
        'label'     => esc_html__('Input', 'elespare'),
        'type'      => Controls_Manager::HEADING,
        'separator' => 'before',
      )
    );

    $this->add_control(
      'input_color',
      array(
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'selectors' => array(
          '{{WRAPPER}} input.site-search-field' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'     => 'input_typography',
        'label'    => esc_html__('Typography', 'elespare'),
        'selector' => '{{WRAPPER}} .site-search-field',

      )
    );

    $this->end_controls_section();
  }

  /**
   * Render the widget output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   */
  protected function render()
  {
    $settings    = $this->get_settings_for_display();
    $icon        = $settings['icon'];
    $_form_search_icon = '';
    $text        = $settings['text'];
    $placeholder = $settings['placeholder'];
    if (empty($text)) {
      $text = null;
    }



    $align_class = 'elesape-search-' . $settings['align'];
?>
    <div class="elespare-search-wrapper">
      <?php
      if ('icon' == $settings['layout']) {

        $button_icon = '';

        $button_text = $text;



        // phpcs:ignore
      ?>
        <div class="elespare-search-dropdown-toggle  <?php echo esc_attr($align_class); ?>">
          <button class="elespare-search-icon--toggle <?php echo esc_attr($icon['value']); ?>">
            <span class="screen-reader-text"><?php echo esc_html__('Enter Keyword', 'elespare'); ?></span>
          </button>
          <div class="elespare-search--toggle-dropdown">
            <div class="elespare-search--toggle-dropdown-wrapper">
              <?php do_action('elespare_hf_seach_form', $button_icon, $placeholder, $button_text); ?>
            </div>
          </div>
        </div>



      <?php } else {
      ?>
        <div class="elespare-search-form-header <?php echo esc_attr($align_class); ?>">
          <div class="elespare-search-form--wrapper">
            <?php do_action('elespare_hf_seach_form', $_form_search_icon, $placeholder, $text); ?>
          </div>
        </div>
      <?php
      } ?>
    </div>
<?php
  }
}
