<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Image_Size;
use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class PostSlider extends Widget_Base
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
    return 'post-slider';
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
    return esc_html__('Posts Slider', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  public function get_custom_help_url()
  {
    return 'https://afthemes.com/';
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
    return 'demo-icon elespare-icons-slider';
  }

  public function get_style_depends()
  {
    return array('slick');
  }
  /**
   * load scripts
   */
  public function get_script_depends()
  {
    return array('slick', 'elespare-custom-scripts');
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
    return array('elespare');
  }



  /**
   * Register the widget controls.
   *
   * Adds different input fields to allow the user to change and customize the widget settings.
   *
   * @since 1.0.0
   *
   * @access protected
   */
  protected function register_controls()
  {
    $this->posts_slider_content_Layout_options();
    $this->posts_slider_section_title_options();
    $this->posts_slider_style_title_options();
    $this->posts_slider_style_category_options();
    $this->posts_slider_style_meta_options();
    $this->posts_slider_style_post_format_options();
    $this->posts_slider_style_navigation_options();
    $this->posts_slider_style_content_options();
    $this->posts_slider_style_readmore_options();
  }

  private function elespare_get_slider_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['slider_layout'] = 'slider-style-1';
        $data_array['section_title'] = 'title-style-2';
        $data_array['post_title_font_size'] = 32;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['category_bg_color'] = elespare_default_color('category');
        return  $data_array;
        break;

      default:
        $data_array['slider_layout'] = 'slider-style-1';
        $data_array['section_title'] = 'title-style-1';
        $data_array['post_title_font_size'] = 27;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['category_bg_color'] = elespare_default_color('category');
        return  $data_array;
        break;
    }
  }

  private function  elespare_slider_layout_default($key)
  {
    $active_theme = get_stylesheet();
    $default = $this->elespare_get_slider_layout_default($active_theme);
    return $default[$key];
  }

  private function posts_slider_content_Layout_options()
  {
    $this->start_controls_section(
      'section_layout',
      [
        'label' => esc_html__('Layout', 'elespare'),
      ]
    );

    $this->add_control(
      'title',
      [
        'label' => esc_html__('Section Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Posts Slider', 'elespare'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );
    $this->add_control(
      'layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_slider_layout_default('slider_layout'),
        'options' => [
          'slider-style-1' => esc_html__('Default', 'elespare'),
          'slider-style-4' => esc_html__('Slider with Bottom Title Box', 'elespare'),

        ],
      ]
    );
    $this->add_control(
      'dark_mode',
      [
        'label' => esc_html__('Dark Mode', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_on' => esc_html__('Enable', 'elespare'),
        'label_off' => esc_html__('Disable', 'elespare'),
        'default' => 'no',
        'separator' => 'before',
        'condition' => [
          'layout_posts_style!' => ['slider-style-1']
        ]

      ]
    );
    $this->end_controls_section();
    $this->start_controls_section(
      'post_image_settings',
      [
        'label' => esc_html__('Image Settings', 'elespare'),
      ]
    );
    $this->add_control(
      '_image_size',
      [
        'label' => esc_html__('Equal Height', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before'
      ]
    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'post_thumbnail',
        'exclude' => ['custom'],
        'default' => 'large',
        'prefix_class' => 'post-thumbnail-size-',
      ]
    );


    $this->end_controls_section();


    $this->start_controls_section(
      'section_post_settings',
      [
        'label' => esc_html__('Posts Settings', 'elespare'),
      ]
    );

    $this->add_control(
      'show_title',
      [
        'label' => esc_html__('Display Title', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'show_category',
      [
        'label' => esc_html__('Display Categories', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'category_layout_style',
      [
        'label' => esc_html__(' Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'solid',
        'condition' => [
          'show_category' => 'yes'
        ]
      ]
    );


    $this->add_control(
      'show_author',
      [
        'label' => esc_html__('Display Author ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );
    $this->add_control(
      'author_icon_options',
      [
        'label' => esc_html__('Author Icon Options', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => 'icon',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'icon' => esc_html__('Icon', 'elespare'),
          'gravatar' => esc_html__('Gravatar', 'elespare'),

        ],
        'condition' => [
          'show_author' => 'yes',
        ]


      ]
    );

    $this->add_control(
      'show_post_format',
      [
        'label' => esc_html__('Display Post Format ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'post_format_options',
      [
        'label' => esc_html__('Post Format Options', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::HIDDEN,
        'default' => 'elespare-background',
        'condition' => [
          'show_post_format' => 'yes',
        ]


      ]
    );
    $this->add_control(
      'show_date',
      [
        'label' => esc_html__('Display Date ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'show_comment_count',
      [
        'label' => esc_html__('Display Comment Count', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );



    $this->add_control(
      'show_excerpt',
      [
        'label' => esc_html__('Excerpt', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'no',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'show_read_more',
      [
        'label' => esc_html__('Read More', 'elespare-pro'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare-pro'),
        'label_off' => esc_html__('Hide', 'elespare-pro'),
        'default' => 'no',
        'separator' => 'before',
        'condition' => [
          'show_excerpt' => 'yes',
        ],
      ]
    );

    $this->add_control(
      'read_more_text',
      [
        'label' => esc_html__('Read More Text', 'elespare-pro'),
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Read More', 'elespare-pro'),
        'condition' => [
          'show_read_more' => 'yes',
          'show_excerpt' => 'yes',
        ],
      ]
    );

    $this->end_controls_section();



    $this->start_controls_section(
      'section_query',
      [
        'label' => esc_html__('Query', 'elespare'),
      ]
    );

    $this->add_control(
      '_filter_by',
      [
        'label' => esc_html__('Filter by ', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => 'category',
        'options' => [
          'category' => esc_html__('Categories', 'elespare'),
          'post_tag' => esc_html__('Tags', 'elespare'),
        ]

      ]
    );



    $this->add_control(
      'posts_category',
      [
        'label'   => esc_html__('Select Category', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'category', ''),
        'condition' => [
          '_filter_by' => 'category'
        ]
      ]
    );
    $this->add_control(
      'tag_term_ids',
      [
        'label'   => esc_html__('Select Tags', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'post_tag', ''),
        'condition' => [
          '_filter_by' => 'post_tag'
        ]

      ]
    );

    $this->add_control(
      'posts_per_page',
      [
        'label' => esc_html__('Posts Per Page', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 5,
      ]
    );



    $this->end_controls_section();

    //Slider Settings
    $this->start_controls_section(
      'section_slider',
      [
        'label' => esc_html__('Slider Settings', 'elespare'),
      ]
    );
    $this->add_control(
      'animation_speed',
      [
        'label' => esc_html__('Animation Speed', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'min' => 100,
        'step' => 10,
        'max' => 10000,
        'default' => 300,
        'description' => esc_html__('Slide speed in milliseconds', 'elespare'),
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      'autoplay',
      [
        'label' => esc_html__('Autoplay?', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'yes',
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      'autoplay_speed',
      [
        'label' => esc_html__('Autoplay Speed', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'min' => 100,
        'step' => 100,
        'max' => 10000,
        'default' => 3000,
        'description' => esc_html__('Autoplay speed in milliseconds', 'elespare'),
        'condition' => [
          'autoplay' => 'yes'
        ],
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      'loop',
      [
        'label' => esc_html__('Infinite Loop?', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'yes',
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      'navigation',
      [
        'label' => esc_html__('Navigation', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'arrow' => esc_html__('Arrow', 'elespare'),
        ],
        'default' => 'arrow',
        'frontend_available' => true,
        'style_transfer' => true,
      ]
    );

    $this->add_control(
      'nav_show_on_hover',
      [
        'label' => esc_html__('Show on Hover', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'yes',
        'frontend_available' => true,
        'condition' => [
          'navigation' => ['arrow', 'both']
        ]
      ]
    );



    $this->end_controls_section();
  }

  /*
   * posts_slider_section_title_options
   */
  private function posts_slider_section_title_options()
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
        'default' => $this->elespare_slider_layout_default('section_title'),
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

  /*
     * Title style option
     */

  private function posts_slider_style_title_options()
  {
    // Layout.
    $this->start_controls_section(
      'section_layout_style',
      [
        'label' => esc_html__('Layout', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );
    // Row margin.
    // $this->add_control(
    //     'content_style_rows_margin',
    //     [
    //         'label'     => esc_html__( 'Rows Gaps', 'elespare' ),
    //         'type'      => Controls_Manager::HIDDEN,
    //         'default'   => [
    //             'size' => 30,
    //         ],
    //         'range'     => [
    //             'px' => [
    //                 'min' => 0,
    //                 'max' => 100,
    //             ],
    //         ],
    //         'selectors' => [
    //             '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap' => 'grid-row-gap: 30px',
    //         ],
    //     ]
    // );

    $this->add_responsive_control(
      'posts_content_style_padding',
      [
        'label'      => esc_html__('Content Padding', 'post-grid-elementor'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'default' => [
          'top' => "15",
          'right' => "15",
          'bottom' => "15",
          'left' => "15",
          'isLinked' => true,
          'unit' => 'px',
        ],
        'selectors'  => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap:not(.slider-style-6):not(.slider-style-7) .elespare-posts-slider-post-items .elespare-content-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-6 .elespare-posts-slider-post-items .elespare-content-wrapper .elespare-content-wrapper-inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-7 .elespare-posts-slider-post-items .elespare-content-wrapper .elespare-content-wrapper-inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
      ]
    );

    $this->add_control(
      'tile_style_content_radius',
      [
        'label'     => esc_html__('Content Border Radius', 'elespare'),
        'type'      => Controls_Manager::SLIDER,
        'default'   => [
          'size' => 1,
        ],
        'range'     => [
          'px' => [
            'min' => 0,
            'max' => 100,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap:not(.slider-style-6):not(.slider-style-7) .elespare-posts-slider-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-6 .elespare-posts-slider-post-items .elespare-content-wrapper .elespare-content-wrapper-inner' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-7 .elespare-posts-slider-post-items .elespare-content-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',

        ],

      ]
    );




    // Normal box shadow.
    $this->add_group_control(
      Group_Control_Box_Shadow::get_type(),
      [
        'name'      => 'tile_button_style_normal_box_shadow',
        'selector'  => '{{WRAPPER}} .elespare-posts-wrap:not(.slider-style-6):not(.slider-style-7) .elespare-posts-slider-post-items, 
                {{WRAPPER}} .elespare-posts-wrap.slider-style-6 .elespare-posts-slider-post-items .elespare-content-wrapper-inner,
                {{WRAPPER}} .elespare-posts-wrap.slider-style-7 .elespare-posts-slider-post-items .elespare-content-wrapper',
      ]
    );

    $this->end_controls_section();
    //Item Effect
    $this->start_controls_section(
      '_item_effects',
      [
        'label' => esc_html__('Item Effects', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,

      ]
    );
    $this->add_control(
      'item_effects_options',
      [
        'label' => esc_html__('Effects', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'elespare-image-zoom',
        'options' => [
          '' => esc_html__('None', 'elespare'),
          'elespare-image-zoom' => esc_html__('Zoom', 'elespare'),

        ],
      ]
    );

    $this->end_controls_section();
    //content Background color

    $this->start_controls_section(
      'section_layout_content_bg_style',
      [
        'label' => esc_html__('Content Background Color', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'layout_posts_style!' => 'slider-style-1'
        ]
      ]
    );
    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap:not(.slider-style-6):not(.slider-style-7) .elespare-posts-slider-post-items' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-light.slider-style-d .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-light.slider-style-2 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-light.slider-style-3 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-light.slider-style-5 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-6 .elespare-posts-slider-post-items .elespare-content-wrapper .elespare-content-wrapper-inner' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-7 .elespare-posts-slider-post-items .elespare-content-wrapper' => 'background-color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
          'layout_posts_style!' => ['full-style-4']
        ]


      ]
    );

    $this->add_control(
      'posts_background_darkmode_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-dark-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark:not(.slider-style-6):not(.slider-style-7) .elespare-posts-slider-post-items' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-d .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-2 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-3 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-5 .elespare-posts-slider-post-items .elespare-avatar-wrap' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-6 .elespare-posts-slider-post-items .elespare-content-wrapper .elespare-content-wrapper-inner' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark.slider-style-7 .elespare-posts-slider-post-items .elespare-content-wrapper' => 'background-color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes',
          'layout_posts_style!' => 'slider-style-1'
        ]

      ]
    );


    $this->end_controls_section();

    $this->start_controls_section(
      'section_title',
      [
        'label'     => esc_html__('Title', 'elespare-posts-slider-elementor-addon'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_title_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_PRIMARY,
        // ],
        'fields_options' => [
          'typography' => [
            'default' => 'yes'

          ],
          'font_size' => [
            'default' => [
              'unit' => 'px',
              'size' => $this->elespare_slider_layout_default('post_title_font_size'),
            ],
          ],
          'font_weight' => [
            'default' => '600',
          ],
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1.26',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items h4 a >span',
      ]
    );

    $this->start_controls_tabs('posts_title_color_style');

    // Normal tab.
    $this->start_controls_tab(
      'posts_title_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    // Title color.
    $this->add_control(
      'posts_title_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_slider_layout_default('post_title_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items h4 a >span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
          'layout_posts_style!' => ['slider-style-1']
        ]
      ]
    );
    $this->add_control(
      'posts_title_style_dark_mode_color1',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-slider-post-items h4 a >span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => ['slider-style-1'],
          'dark_mode' => 'yes'
        ]
      ]
    );

    $this->add_control(
      'posts_title_style_color1',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Colors', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.slider-style-1 .elespare-posts-slider-post-items h4 a >span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => ['slider-style-1'],
        ]
      ]
    );

    $this->end_controls_tab();


    // Hover tab.
    $this->start_controls_tab(
      'posts_title_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    // Title hover color.
    $this->add_control(
      'posts_title_style_hover_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items h4  > a:hover span'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->add_responsive_control(
      'posts_title_style_margin',
      [
        'label'      => esc_html__('Margin', 'post-slider-elementor'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'selectors'  => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items h4' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
      ]
    );

    $this->end_controls_section();
  }
  /*
     * Category style option
     */

  private function posts_slider_style_category_options()
  {
    $this->start_controls_section(
      'section_category',
      [
        'label'     => esc_html__('Category', 'elespare-posts-slider-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_category' => 'yes',
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_category_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'fields_options' => [
          'typography' => [
            'default' => 'yes'
          ],
          'font_size' => [
            'default' => [
              'unit' => 'px',
              'size' => '12',
            ],
          ],
          'font_weight' => [
            'default' => '600',
          ],
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1.3',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items ul li > a',
      ]
    );


    // Category color.
    $this->add_control(
      'posts_category_style_solid_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_slider_layout_default('catgory_text_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => ['solid']
        ]
      ]
    );

    $this->add_control(
      'posts_category_solid_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => 'solid'
        ]
      )
    );

    $this->add_control(
      'posts_category_border_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => $this->elespare_slider_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-cat-links.border li > a'       => 'border: 1px solid ;border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => ['border', 'underline']
        ]
      )
    );


    $this->add_control(
      'posts_category_style_none_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => ['none', 'border', 'underline']
        ]
      ]
    );




    $this->add_control(
      'posts_category_style_margin',
      [
        'label'      => esc_html__('Border Radius', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'default' => [
          'top' => '0',
          'right' => '0',
          'bottom' => '0',
          'left' => '0',

          'isLinked' => true
        ],
        'selectors'  => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
        'condition' => [
          'category_layout_style!' => ['none', 'underline']
        ]
      ]
    );

    $this->end_controls_section();
  }

  //Meta style
  private function posts_slider_style_meta_options()
  {
    $this->start_controls_section(
      'section_meta',
      [
        'label'     => esc_html__('Meta', 'elespare-posts-slider-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_meta_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'fields_options' => [
          'typography' => [
            'default' => 'yes'
          ],
          'font_size' => [
            'default' => [
              'unit' => 'px',
              'size' => '12',
            ],
          ],
          'font_weight' => [
            'default' => '400',
          ],
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-metadata',
      ]
    );

    $this->start_controls_tabs('posts_meta_color_style');

    $this->start_controls_tab(
      'posts_meta_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    // Meta color.
    $this->add_control(
      'posts_meta_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('post-meta'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span >a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => ['slider-style-1'],
          'dark_mode!' => 'yes'
        ]
      ]
    );

    $this->add_control(
      'posts_meta_style_color1',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span >a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => ['slider-style-1'],

        ]
      ]
    );
    $this->add_control(
      'posts_meta_dark_mode_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-slider-post-items .elespare-metadata span >a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-slider-post-items .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-slider-post-items .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => ['slider-style-1'],
          'dark_mode' => 'yes'
        ]

      ]
    );

    $this->end_controls_tab();

    // Hover tab.
    $this->start_controls_tab(
      'posts_meta_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    // Meya hover color.
    $this->add_control(
      'posts_meta_style_hover_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span >a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata span >a:hover svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata .comment_count:hover svg'       => 'fill: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();
    $this->add_responsive_control(
      'posts_meta_style_margin',
      [
        'label'      => esc_html__('Margin', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'selectors'  => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-metadata' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
      ]
    );

    $this->end_controls_section();
  }
  private function posts_slider_style_post_format_options()
  {
    $this->start_controls_section(
      '_section_post_format',
      [
        'label' => esc_html__('Post Format', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_post_format' => 'yes'
        ]
      ]
    );
    $this->add_control(
      'posts_format_style_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Icon Color', 'elespare'),
        'default'   => '#ffffff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',
        ),
      )
    );
    $this->add_control(
      'posts_format_border_style_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => elespare_default_color('postformat'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'post_format_options' => 'elespare-border'
        ]
      )
    );
    $this->add_control(
      'posts_format_bg_style_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => '#BB1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',
        ),
        'condition' => [
          'post_format_options' => 'elespare-background'
        ]
      )
    );
    $this->end_controls_section();
  }

  //Navigation  color option
  private function posts_slider_style_navigation_options()
  {
    $this->start_controls_section(
      'section_content_carousel',
      [
        'label' => esc_html__('Slider Navigation', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
      ]
    );


    $this->start_controls_tabs('_tabs_arrow');
    $this->start_controls_tab(
      '_tab_arrow_normal',
      [
        'label' => esc_html__('Normal', 'elespare'),
      ]
    );

    $this->add_control(
      'arrow_color',
      [
        'label' => esc_html__('Arrow Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-arrow'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev i, {{WRAPPER}} .slick-next i' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['arrow', 'both']
        ]
      ]
    );

    $this->add_control(
      'arrow_bg_color',
      [
        'label' => esc_html__('Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-background'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev, {{WRAPPER}} .slick-next' => 'background-color: {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['arrow', 'both']
        ]
      ]
    );

    $this->end_controls_tab();

    $this->start_controls_tab(
      '_tab_arrow_hover',
      [
        'label' => esc_html__('Hover', 'elespare'),
      ]
    );

    $this->add_control(
      'arrow_hover_color',
      [
        'label' => esc_html__('Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => [
          '{{WRAPPER}} .slick-prev:hover i, {{WRAPPER}} .slick-next:hover i' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['arrow', 'both']
        ]
      ]
    );

    $this->add_control(
      'arrow_hover_bg_color',
      [
        'label' => esc_html__('Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-background'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev:hover, {{WRAPPER}} .slick-next:hover' => 'background-color: {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['arrow', 'both']
        ]
      ]
    );



    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->add_control(
      'dots_nav_color',
      [
        'label' => esc_html__('Dot Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-arrow'),
        'selectors' => [
          '{{WRAPPER}} .slick-dots li button:before' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['dots', 'both']
        ]
      ]
    );

    $this->add_control(
      'dots_nav_hover_color',
      [
        'label' => esc_html__('Active Dot Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-arrow'),
        'selectors' => [
          '{{WRAPPER}} .slick-dots li.slick-active button:before' => 'color:  {{VALUE}};',
        ],
        'condition' => [
          'navigation' => ['dots', 'both']
        ]
      ]
    );


    $this->end_controls_section();
  }

  //Content style
  private function posts_slider_style_content_options()
  {
    $this->start_controls_section(
      'section_posts_content_style',
      [
        'label' => esc_html__('Content', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_excerpt' => 'yes',
        ]
      ]
    );

    // Content typography.
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'      => 'posts_content_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'selector'  => '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items  .elespare-exceprt p',
      ]
    );


    // Content color.
    $this->add_control(
      'posts_content_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('post-meta'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => 'slider-style-1',
          'dark_mode!' => 'yes'
        ]
      ]
    );
    $this->add_control(
      'posts_content_style_color1',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Spotlight Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => 'slider-style-1'

        ]
      ]
    );

    $this->add_control(
      'posts_content_darkmode_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]
      ]
    );

    // Content margin
    $this->add_responsive_control(
      'posts_content_style_margin',
      [
        'label'      => esc_html__('Margin', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'selectors'  => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .elespare-exceprt p' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
      ]
    );

    $this->end_controls_section();
  }


  //Read More text
  private function  posts_slider_style_readmore_options()
  {
    $this->start_controls_section(
      'section_posts_reamore_style',
      [
        'label' => esc_html__('Read More', 'elespare-pro'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_read_more' => 'yes',
        ]
      ]
    );
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'      => 'posts_readmore_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'selector'  => '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items  .read-more-btn',
      ]
    );

    $this->add_control(
      'posts_readmore_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare-pro'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-slider-wrap .elespare-posts-wrap .elespare-posts-slider-post-items .read-more-btn' => 'color: {{VALUE}};',
        ]
      ]
    );





    $this->end_controls_section();
  }

  /**
   * Render the widget output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   *
   * @since 1.0.0
   *
   * @access protected
   */
  protected function render($instance = [])
  {
    // get our input from the widget settings.
    $settings = $this->get_settings_for_display();
    $layouts = $settings['layout_posts_style'];
    $title_layout =  $settings['_section_title_style'];
    $alignment_class = $settings['_section_title_alignment'];
    $this->add_render_attribute('layout-wrap', 'class', ['elespare-widget-title-section', $title_layout, $alignment_class]);
    $this->add_inline_editing_attributes('title', 'basic');
    $all_posts = elespare_get_all_posts($settings);
    $same_height = '';
    if ('yes' === $settings['_image_size']) {
      $same_height = 'elespare-has-equal-height';
    }

    if ($settings['title']) :
      printf(
        '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
        $this->get_render_attribute_string('layout-wrap'),
        elespare_kses_basic($settings['title'])
      );
    endif;

    $gravatar_class = '';
    if ('yes' == $settings['show_author'] && 'gravatar' == $settings['author_icon_options']) {
      $gravatar_class = 'elespare-gravatar';
    }
    $dark_mode = 'elespare-light';
    if ('yes' == $settings['dark_mode']) {
      $dark_mode =  'elespare-dark';
    }
    $show_on_hover = '';
    if ('yes' == $settings['nav_show_on_hover']) {
      $show_on_hover = 'show-on-hover';
    }
    $this->add_render_attribute('elespare-slider-wrap', 'class', ['elespare-slider-wrap slider-layout', $same_height]);
    $this->add_render_attribute('elespare-posts-wrap', 'class', ['elespare-posts-wrap', $layouts, $gravatar_class, $settings['item_effects_options'], $dark_mode, $show_on_hover]);
?>
    <div <?php $this->print_render_attribute_string('elespare-slider-wrap'); ?>>
      <div <?php $this->print_render_attribute_string('elespare-posts-wrap'); ?>>
        <?php
        if ($all_posts->have_posts()) :
          while ($all_posts->have_posts()) : $all_posts->the_post(); ?>
            <div class="elespare-posts-slider-post-items">
              <div class="elespare-img-wrapper">
                <?php elespare_posts_grid_render_thumbnail($settings['post_thumbnail_size']);

                if ('gravatar' == $settings['author_icon_options']) {
                  if ($layouts == 'slider-style-2' || $layouts == 'slider-style-3' || $layouts == 'slider-style-5') {
                    $author_avatar = elespare_get_user_profile_avatar(get_the_ID()); ?>
                    <div class="elespare-avatar-wrap">
                      <img src="<?php echo esc_url($author_avatar); ?>">
                    </div>
                <?php }
                }
                if ('yes' == $settings['show_post_format']) {
                  elespare_post_format(get_the_ID(), $settings['post_format_options']);
                }
                if ('yes' ==  $settings['show_category']) {
                  if ($layouts == 'slider-style-7') {
                    echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                  }
                }
                ?>
              </div>

              <div class="elespare-content-wrapper">
                <?php
                if ('yes' ==  $settings['show_category']) {
                  if ($layouts != 'slider-style-7') {
                    echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                  }
                }
                ?>
                <div class="elespare-content-wrapper-inner">
                  <?php
                  elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                  $this->posts_slider_render_metadata();
                  // $excerpt_length = apply_filters( 'excerpt_length', 12 );
                  $excerpt_length = 12;
                  elespare_posts_tabs_render_excerpt_readmore(get_the_ID(), $settings['show_excerpt'], $excerpt_length, $settings['show_read_more'], $settings['read_more_text']);
                  ?>
                </div>
              </div>
            </div>

        <?php endwhile;
          wp_reset_postdata();
        endif;

        ?>
      </div>
    </div>
    <button type="button" class="slick-prev elespare-nav-carousel"><i class="elespare-icons-angle-left"
        aria-hidden="true"></i></button>
    <button type="button" class="slick-next elespare-nav-carousel"><i class="elespare-icons-angle-right"
        aria-hidden="true"></i></button>
    <?php

  }


  protected function posts_slider_render_metadata()
  {
    $settings = $this->get_settings_for_display();

    $meta_date = $settings['show_date'];
    $meta_author = $settings['show_author'];
    $show_comment_count = $settings['show_comment_count'];


    if ('yes' == $meta_date  || 'yes' == $meta_author || 'yes' ==  $show_comment_count) {

    ?>
      <div class="elespare-metadata">
        <?php if ('yes' == $meta_author) {
          $author_link = get_author_posts_url(get_the_author_meta('ID'));
        ?>
          <span class="post-author">
            <a href="<?php echo esc_url($author_link); ?>">
              <?php if ('gravatar' == $settings['author_icon_options']) {

                $author_avatar = elespare_get_user_profile_avatar(get_the_ID()); ?>
                <div class="elespare-avatar-wrap">
                  <img src="<?php echo esc_url($author_avatar); ?>">
                </div>
              <?php
              }
              if ('icon' == $settings['author_icon_options']) { ?>
                <i class="demo-icon elespare-icons-user-circle-regular" aria-hidden="true"></i>
              <?php  }
              the_author(); ?>
            </a>
          </span>
        <?php } ?>
        <?php if ('yes' == $meta_date) {
          $year_date = get_the_date('Y');
          $date_link = get_year_link($year_date, false, false); ?>
          <span class="elespare-posts-slider-post-author">
            <a href="<?php echo esc_url($date_link); ?>">
              <i class="demo-icon elespare-icons-clock-regular" aria-hidden="true"></i>
              <?php echo esc_html(apply_filters('the_date', get_the_date(), get_option('date_format'), '', '')); ?>
            </a>
          </span>
        <?php } ?>
        <?php if ('yes' ==  $show_comment_count) { ?>
          <span class="comment_count">
            <i class="demo-icon elespare-icons-comment-empty" aria-hidden="true"></i>
            <?php echo esc_html(get_comments_number()); ?>
          </span>
        <?php } ?>
      </div>
<?php
    }
  }
}
