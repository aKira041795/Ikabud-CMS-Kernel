<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;


defined('ABSPATH') || die();


class SectionTitle extends Widget_Base
{

  public function get_name()
  {
    return 'title-section';
  }

  public function get_title()
  {
    return esc_html__('Section Title', 'elespare');
  }
  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }

  public function get_icon()
  {
    return 'demo-icon elespare-icons-section-title';
  }
  public function get_categories()
  {
    return ['elespare'];
  }



  protected function register_controls()
  {
    $this->elespare_section_title_content_options();
    $this->elespare_section_title_style_options();
  }
  private function elespare_section_title_content_options()
  {
    $this->start_controls_section(
      'section_layout',
      [
        'label' => esc_html__('General', 'elespare'),
      ]
    );

    $this->add_control(
      'title',
      [
        'label' => esc_html__('Section Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Title Here', 'elespare'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );

    $this->end_controls_section();
  }

  private function elespare_section_title_style_options()
  {
    $this->start_controls_section(
      'section_widget_title_style',
      [
        'label' => esc_html__('Section Title', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_control(
      '_section_title_style',
      [
        'label' => esc_html__('Title Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'title-style-1',
        'options' => elespare_section_title_dropdown(),
      ]
    );

    $this->add_responsive_control(
      '_section_title_alignment',
      [
        'label' => esc_html__('Alignment', 'elespare'),
        'type' => Controls_Manager::CHOOSE,
        'label_block' => false,
        'default' => 'elespare-left',
        'options' => [
          'elespare-left' => [
            'title' => esc_html__('Left', 'elespare'),
            'icon' => 'eicon-h-align-left',
          ],
          'elespare-center' => [
            'title' => esc_html__('Center', 'elespare'),
            'icon' => 'eicon-h-align-center',
          ],
          'elespare-right' => [
            'title' => esc_html__('Right', 'elespare'),
            'icon' => 'eicon-h-align-right',
          ],
        ],
        'prefix_class' => 'elespare-section-title%s-',
        'toggle' => true,
      ]
    );

    $this->add_control(
      '_section_widget_title_color',
      [
        'label'      => esc_html__('Title Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#000',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-widget-title-section .elespare-widget-title .elespare-section-title' => 'color: {{VALUE}};',


        ],
        'condition' => [
          '_section_title_style!' => ['title-style-8', 'title-style-9']
        ]
      ]
    );

    $this->add_control(
      '_section_widget_title_color_8_9',
      [
        'label'      => esc_html__('Title Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#fff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-widget-title-section.title-style-8 .elespare-widget-title .elespare-section-title' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-9 .elespare-widget-title .elespare-section-title' => 'color: {{VALUE}};',


        ],
        'condition' => [
          '_section_title_style' => ['title-style-8', 'title-style-9']
        ]
      ]
    );

    $this->add_control(
      '_section_widget_title_dash_1_color',
      [
        'label' => esc_html__('Title Dash Color1', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('section-dash-color-1'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-widget-title-section.title-style-1 .elespare-widget-title .elespare-section-title-after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-2 .elespare-widget-title span.elespare-section-title:after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-3 .elespare-widget-title .elespare-section-title' => 'border-bottom-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-4 .elespare-widget-title .elespare-section-title-before' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-5 .elespare-widget-title .elespare-section-title-after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-6 .elespare-widget-title .elespare-section-title-after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-6 .elespare-widget-title .elespare-section-title-before' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-7 .elespare-widget-title .elespare-section-title-after' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-7 .elespare-widget-title .elespare-section-title-before' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-8 .elespare-widget-title .elespare-section-title' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-8 .elespare-widget-title .elespare-section-title:before' => 'border-top-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-9 .elespare-widget-title .elespare-section-title' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-9 .elespare-widget-title ' => 'border-bottom-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-10 .elespare-widget-title .elespare-section-title:before' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          '_section_title_style!' => ['title-none']
        ]
      ]
    );


    $this->add_control(
      '_section_widget_title_dash_2_color',
      [
        'label'      => esc_html__('Title Dash Color 2', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('section-dash-color-2'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_SECONDARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-widget-title-section.title-style-1 .elespare-widget-title .elespare-section-title-before' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-3 .elespare-widget-title .elespare-section-title-after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-4 .elespare-widget-title .elespare-section-title-after' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-widget-title-section.title-style-5 .elespare-widget-title .elespare-section-title-before' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          '_section_title_style!' => ['title-none', 'title-style-2', 'title-style-6', 'title-style-7', 'title-style-8', 'title-style-9', 'title-style-10']
        ]

      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => '_section_widget_title_color_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_PRIMARY,
        // ],
        'selector' => '{{WRAPPER}} .elespare-widget-title-section .elespare-widget-title .elespare-section-title',
      ]
    );



    $this->end_controls_section();
  }

  protected function render()
  {
    $settings = $this->get_settings_for_display();
    $title_layout =  $settings['_section_title_style'];
    $alignment_class = $settings['_section_title_alignment'];
    $this->add_render_attribute('layout-wrap', 'class', ['elespare-widget-title-section', $title_layout, $alignment_class]);
    $this->add_inline_editing_attributes('title', 'basic');

    if ($settings['title']) :
      printf(
        '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
        $this->get_render_attribute_string('layout-wrap'),
        elespare_kses_basic($settings['title'])
      );
    endif;
  }
}
