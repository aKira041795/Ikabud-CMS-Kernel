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


class PostList extends Widget_Base
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
    return 'post-list';
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
    return esc_html__('Posts List', 'elespare');
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
    return 'demo-icon elespare-icons-small-list';
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
    $this->posts_list_content_Layout_options();
    $this->posts_list_section_title_options();
    $this->posts_list_style_title_options();
    $this->posts_list_style_category_options();
    $this->posts_list_style_meta_options();
  }

  private function elespare_get_list_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['list_layout'] = 'list-style-1';
        $data_array['section_title'] = 'title-style-2';
        $data_array['post_title_font_size'] = 14;
        $data_array['post_spotlight_title_font_size'] = 18;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['catgory_text_none'] = elespare_default_color('category');
        $data_array['category_bg_color'] = elespare_default_color('category');
        return  $data_array;
        break;

      default:
        $data_array['list_layout'] = 'list-style-1';
        $data_array['section_title'] = 'title-style-1';
        $data_array['post_title_font_size'] = 14;
        $data_array['post_spotlight_title_font_size'] = 16;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['catgory_text_none'] = elespare_default_color('category');
        $data_array['category_bg_color'] = elespare_default_color('category');
        return  $data_array;
        break;
    }
  }

  private function  elespare_list_layout_default($key)
  {
    $active_theme = get_stylesheet();
    $default = $this->elespare_get_list_layout_default($active_theme);
    return $default[$key];
  }

  private function posts_list_content_Layout_options()
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
        'default' => esc_html__('Posts Lists', 'elespare'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );
    $this->add_control(
      'layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_list_layout_default('list_layout'),
        'options' => [
          'list-style-1' => esc_html__('Default', 'elespare'),
          'list-style-2' => esc_html__('List with Underlined Categories', 'elespare'),

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
      ]
    );
    $this->add_control(
      'enable_background',
      [
        'label' => esc_html__('Background Enable', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_on' => esc_html__('Enable', 'elespare'),
        'label_off' => esc_html__('Disable', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',

      ]
    );




    $this->end_controls_section();

    $this->start_controls_section(
      'post_image_settings',
      [
        'label' => esc_html__('Image Settings', 'elespare'),
      ]
    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'post_list_thumbnail',
        'label' => esc_html__('Grid Image size', 'elespare'),
        'exclude' => ['custom'],
        'default' => 'medium',
        'prefix_class' => 'post-thumbnail-size-',
        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-6', 'list-style-7', 'list-style-8', 'list-style-9']
        ]
      ]

    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'post_thumbnail',
        'exclude' => ['custom'],
        'default' => 'thumbnail',
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
      'show_author',
      [
        'label' => esc_html__('Display Author ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'no',
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
        'default' => 'no',
        'separator' => 'before',
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
        'type' => Controls_Manager::NUMBER,
        'default' => 6,
      ]
    );

    $this->end_controls_section();
  }

  /*
   * posts_list_section_title_options
   */
  private function posts_list_section_title_options()
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
        'default' => $this->elespare_list_layout_default('section_title'),
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
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
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

  private function posts_list_style_title_options()
  {
    // Layout.
    $this->start_controls_section(
      'section_layout_style',
      [
        'label' => esc_html__('Layout', 'post-list-elementor-addon'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    // Columns margin.
    $this->add_control(
      'list_style_columns_margin',
      [
        'label'     => esc_html__('Columns Gaps', 'elespare-posts-list-elementor-addon'),
        'type'      => Controls_Manager::SLIDER,
        'default'   => [
          'size' => 10,
        ],
        'range'     => [
          'px' => [
            'min' => 0,
            'max' => 100,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-posts-wrap' => 'grid-column-gap: {{SIZE}}{{UNIT}}',

        ],
      ]
    );
    // Row margin.
    $this->add_control(
      'list_style_rows_margin',
      [
        'label'     => esc_html__('Rows Gaps', 'elespare-posts-list-elementor-addon'),
        'type'      => Controls_Manager::SLIDER,
        'default'   => [
          'size' => 10,
        ],
        'range'     => [
          'px' => [
            'min' => 0,
            'max' => 100,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-posts-wrap' => 'grid-row-gap: {{SIZE}}{{UNIT}}',
        ],
      ]
    );

    $this->add_control(
      'list_style_content_radius',
      [
        'label'     => esc_html__('Content Border Radius', 'elespare-posts-list-elementor-addon'),
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
          '{{WRAPPER}} .elespare-posts-wrap .elespare-posts-list-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',

        ],
      ]
    );




    // Normal box shadow.
    $this->add_group_control(
      Group_Control_Box_Shadow::get_type(),
      [
        'name'      => 'list_button_style_normal_box_shadow',
        'selector'  => '{{WRAPPER}} .elespare-posts-wrap .elespare-posts-list-post-items',
        'condition' => [
          'enable_background' => 'yes'
        ]
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
        'label' => esc_html__('Content Background Color', 'post-list-elementor-addon'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );
    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap.has-background .elespare-posts-wrap.elespare-light .elespare-posts-list-post-items' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode!' => 'yes'

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
          '{{WRAPPER}} .elespare-list-wrap.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-list-post-items' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          'dark_mode' => 'yes',
          'enable_background' => 'yes'
        ]

      ]
    );

    $this->end_controls_section();

    $this->start_controls_section(
      'section_title',
      [
        'label'     => esc_html__('Title', 'elespare-posts-list-elementor-addon'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_spotlight_title_style_typography',
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
              'size' => $this->elespare_list_layout_default('post_title_font_size'),
            ],
          ],
          'font_weight' => [
            'default' => '600',
          ],
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1.32',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items h4 a > span',
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_title_style_typography',
        'label'    => esc_html__('spotlight Typography', 'elespare'),
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
              'size' => $this->elespare_list_layout_default('post_spotlight_title_font_size'),
            ],
          ],
          'font_weight' => [
            'default' => '600',
          ],
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1.43',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child h4 a > span,
                {{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child h4 a > span,
                {{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child h4 a > span,
                {{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child h4 a > span,
                {{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child h4 a > span',

        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-6', 'list-style-7', 'list-style-8', 'list-style-9']
        ]
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
        'default' => $this->elespare_list_layout_default('post_title_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes'
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]
      ]
    );

    $this->add_control(
      'posts_title_spotlight_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Spotlight Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child h4 a > span'       => 'color: {{VALUE}};',

        ],
        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-6', 'list-style-7', 'list-style-8', 'list-style-9']
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items h4  > a:hover span'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->add_responsive_control(
      'posts_title_style_margin',
      [
        'label'      => esc_html__('Margin', 'post-list-elementor'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'selectors'  => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items h4' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
      ]
    );

    $this->end_controls_section();
  }
  /*
     * Category style option
     */

  private function posts_list_style_category_options()
  {
    $this->start_controls_section(
      'section_category',
      [
        'label'     => esc_html__('Category', 'elespare-posts-list-elementor'),
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
        'selector' => '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items ul li > a',
      ]
    );


    // Category color.
    $this->add_control(
      'posts_category_style_solid_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color solid', 'elespare'),
        'default'   => $this->elespare_list_layout_default('catgory_text_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child .elespare-cat-links.solid .elespare-meta-category > a' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child .elespare-cat-links.border .elespare-meta-category > a' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child .elespare-cat-links.solid .elespare-meta-category > a' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child .elespare-cat-links.border .elespare-meta-category > a' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child .elespare-cat-links.border .elespare-meta-category > a' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-6', 'list-style-7', 'list-style-9', 'list-style-8'],
        ]

      ]
    );

    $this->add_control(
      'posts_category_solid_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_list_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-7'],
        ]
      )
    );

    $this->add_control(
      'posts_category_border_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Dash Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-2 .elespare-posts-list-post-items .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-4 .elespare-posts-list-post-items .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:not(:first-child) .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:not(:first-child) .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:not(:first-child) .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-11 .elespare-posts-list-post-items .elespare-content-wrapper ul.elespare-cat-links li.elespare-meta-category a.elespare-categories' => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'layout_posts_style' => ['list-style-2', 'list-style-4', 'list-style-6', 'list-style-8', 'list-style-9', 'list-style-11'],

        ]
      )
    );
    $this->add_control(
      'posts_category_border_layout_6_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Colors', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child .elespare-cat-links li > a' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child .elespare-cat-links li > a' => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child .elespare-cat-links li > a' => 'border-color: {{VALUE}};',

        ),
        'condition' => [
          'layout_posts_style' => ['list-style-6', 'list-style-8', 'list-style-9'],
        ]
      )
    );

    $this->add_control(
      'posts_category_style_none_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color none', 'elespare'),
        'default'   => $this->elespare_list_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],

      ]
    );



    $this->add_control(
      'posts_category_style_margin',
      [
        'label'      => esc_html__('Border Radus', 'elespare'),
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
        'condition' => [
          'category_layout_style!' => ['none']
        ]
      ]
    );

    $this->end_controls_section();
  }

  //Meta style
  private function posts_list_style_meta_options()
  {
    $this->start_controls_section(
      'section_meta',
      [
        'label'     => esc_html__('Meta', 'elespare-posts-list-elementor'),
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
        'selector' => '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-metadata ',
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-light .elespare-posts-list-post-items .elespare-metadata span >a ' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-light .elespare-posts-list-post-items .elespare-metadata span svg' => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-light .elespare-posts-list-post-items .elespare-metadata .comment_count ' => 'color: {{VALUE}};',

        ],
        'condition' => [
          'dark_mode!' => 'yes'
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-list-post-items .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-list-post-items .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-list-post-items .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]

      ]
    );


    $this->add_control(
      'posts_spotlight_meta_style_color',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Spotlight Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-5 .elespare-posts-list-post-items:first-child .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-6 .elespare-posts-list-post-items:first-child .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-7 .elespare-posts-list-post-items:first-child .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-8 .elespare-posts-list-post-items:first-child .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child .elespare-metadata span > a'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child .elespare-metadata span svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap.list-style-9 .elespare-posts-list-post-items:first-child .elespare-metadata .comment_count'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => ['list-style-5', 'list-style-6', 'list-style-7', 'list-style-8', 'list-style-9']
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

    // Meta hover color.
    $this->add_control(
      'posts_meta_style_hover_color',
      array(
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-metadata span >a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-metadata span >a:hover svg'       => 'fill: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-metadata .comment_count:hover svg'       => 'fill: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-list-wrap .elespare-posts-wrap .elespare-posts-list-post-items .elespare-metadata' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
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
    $title_layout =  $settings['_section_title_style'];
    $alignment_class = $settings['_section_title_alignment'];
    $this->add_render_attribute('layout-wrap', 'class', ['elespare-widget-title-section', $title_layout, $alignment_class]);
    $this->add_inline_editing_attributes('title', 'basic');
    $layouts = $settings['layout_posts_style'];

    $all_posts = elespare_get_all_posts($settings);
    $bg_class = 'has-no-background';

    if ($settings['enable_background'] == 'yes') {
      $bg_class = 'has-background ';
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

    $this->add_render_attribute('elespare-list-wrap', 'class', ['elespare-list-wrap list-layout', $bg_class]);
    $this->add_render_attribute('elespare-posts-wrap', 'class', ['elespare-posts-wrap', $layouts, $settings['item_effects_options'], $gravatar_class, $dark_mode]);
?>

    <div <?php $this->print_render_attribute_string('elespare-list-wrap'); ?>>
      <div <?php $this->print_render_attribute_string('elespare-posts-wrap'); ?>>
        <?php
        if ($all_posts->have_posts()) :
          $count = 1;
          while ($all_posts->have_posts()) : $all_posts->the_post(); ?>
            <div class="elespare-posts-list-post-items">
              <?php
              if ($layouts == 'list-style-7' || $layouts == 'list-style-8' || $layouts == 'list-style-10' || $layouts == 'list-style-11') {
                if ($count == 1) {
              ?>
                  <div class="elespare-img-wrapper">

                    <?php
                    if ($layouts == 'list-style-7' || $layouts == 'list-style-8') {
                      elespare_posts_grid_render_thumbnail($settings['post_list_thumbnail_size']);
                    } else {
                      elespare_posts_grid_render_thumbnail($settings['post_thumbnail_size']);
                    }
                    ?>

                  </div>
                <?php }
              } else { ?>
                <div class="elespare-img-wrapper">

                  <?php
                  if ($layouts == 'list-style-5' || $layouts == 'list-style-6' || $layouts == 'list-style-9') {
                    if ($count == 1) {
                      elespare_posts_grid_render_thumbnail($settings['post_list_thumbnail_size']);
                    } else {
                      elespare_posts_grid_render_thumbnail($settings['post_thumbnail_size']);
                    }
                  } else {
                    elespare_posts_grid_render_thumbnail($settings['post_thumbnail_size']);
                  }
                  ?>

                </div>
              <?php } ?>

              <div class="elespare-content-wrapper">
                <?php
                elespare_posts_express_list_show_category($settings['show_category'], $layouts, $count, 'post-list');
                elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                $this->posts_list_render_metadata();
                ?>
              </div>
              <?php //}
              ?>
            </div>
        <?php
            $count++;
          endwhile;
          wp_reset_postdata();
        endif;

        ?>
      </div>
    </div>
    <?php
  }

  protected function posts_list_render_metadata()
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
              <?php
              if ('gravatar' == $settings['author_icon_options']) {
                $author_avatar = elespare_get_user_profile_avatar(get_the_ID()); ?>
                <div class="elespare-avatar-wrap">
                  <img src="<?php echo esc_url($author_avatar); ?>">
                </div>
              <?php }
              if ('icon' == $settings['author_icon_options']) { ?>
                <i class="demo-icon elespare-icons-user-circle-regular" aria-hidden="true"></i>
              <?php }

              the_author();
              ?>
            </a>
          </span>
        <?php } ?>
        <?php if ('yes' == $meta_date) {
          $year_date = get_the_date('Y');
          $date_link = get_year_link($year_date, false, false);
        ?>
          <span class="elespare-posts-full-post-author">
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
