<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Image_Size;
use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class PostBannerOne extends Widget_Base
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
    return 'post-banner';
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
    return esc_html__('Hero Banner', 'elespare');
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
    return 'demo-icon elespare-icons-banner';
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

  public function get_script_depends()
  {
    return array('slick', 'elespare-custom-scripts');
  }
  public function get_style_depends()
  {
    return ['slick'];
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
    $this->posts_grid_content_Layout_options();
    $this->posts_grid_section_title_options();
    $this->posts_grid_style_title_options();
    $this->posts_grid_style_category_options();
    $this->posts_trending_style_category_options();
    $this->posts_grid_style_meta_options();
    $this->posts_banner_style_post_format_options();
    $this->posts_slider_style_meta_options();
    $this->posts_banner_style_carousel_options();
    $this->posts_grid_style_content_options();
    $this->posts_grid_style_readmore_options();
  }

  private function elespare_get_banner_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['banner_layout'] = 'banner-style-3';
        $data_array['section_title'] = 'title-style-2';
        $data_array['post_slider_title_font_size'] = 25;
        $data_array['post_grid_title_font_size'] = 18;
        $data_array['post_trending_title_font_size'] = 14;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['catgory_text_none'] = elespare_default_color('category');
        $data_array['category_bg_color'] = elespare_default_color('category');
        $data_array['main_story'] = esc_html__('Main Story', 'elespare');
        $data_array['editor'] = esc_html__("Editorial's Picks", 'elespare');
        $data_array['trending'] = esc_html__('Trending Story', 'elespare');
        return  $data_array;
        break;

      default:
        $data_array['banner_layout'] = 'banner-style-1';
        $data_array['section_title'] = 'title-style-1';
        $data_array['post_slider_title_font_size'] = 27;
        $data_array['post_grid_title_font_size'] = 16;
        $data_array['post_trending_title_font_size'] = 14;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['catgory_text_none'] = elespare_default_color('category');
        $data_array['category_bg_color'] = elespare_default_color('category');
        $data_array['main_story'] = esc_html__('Main Slider', 'elespare');
        $data_array['editor'] = esc_html__('Editorials Grid', 'elespare');
        $data_array['trending'] = esc_html__('Trending Posts', 'elespare');
        return  $data_array;
        break;
    }
  }

  private function  elespare_banner_layout_default($key)
  {
    $active_theme = get_stylesheet();
    $default = $this->elespare_get_banner_layout_default($active_theme);
    return $default[$key];
  }

  private function posts_grid_content_Layout_options()
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
        'label' => esc_html__('Grid Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => $this->elespare_banner_layout_default('editor'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
        'condition' => [
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3'


          ]
        ]
      ]
    );
    $this->add_control(
      'carousel_title',
      [
        'label' => esc_html__('Slider Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' =>  $this->elespare_banner_layout_default('main_story'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );
    $this->add_control(
      'trending_title',
      [
        'label' => esc_html__('Trending Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => $this->elespare_banner_layout_default('trending'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
        'condition' => [
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',
            'banner-style-4',
            'banner-style-5',
            'banner-style-6',
            'banner-style-7',

          ]
        ]
      ]
    );
    $this->add_control(
      'layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_banner_layout_default('banner_layout'),
        'options' => [
          'banner-style-1' => esc_html__('Default', 'elespare'),
          'banner-style-2' => esc_html__('Trending - Main Slider - Editorials', 'elespare'),
          'banner-style-3' => esc_html__('Main Slider - Editorials - Trending', 'elespare')
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
        'name' => 'post_grid_thumbnail',
        'label' => esc_html__('Grid Image size', 'elespare'),
        'exclude' => ['custom'],
        'default' => 'medium',
        'prefix_class' => 'post-thumbnail-size-',
      ]
    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'post_slider_thumbnail',
        'description' => esc_html__('Slider Image size', 'elespare'),
        'exclude' => ['custom'],
        'default' => 'large',
        'prefix_class' => 'post-thumbnail-size-',
      ]
    );
    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'post_trending_thumbnail',
        'label' => esc_html__('Trendings Image size', 'elespare'),
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
        'label' => esc_html__('Display Categories ', 'elespare'),
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
      'show_category_on_trending',
      [
        'label' => esc_html__('Display Categories(Trending) ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );
    $this->add_control(
      'category_trending_layout_style',
      [
        'label' => esc_html__(' Trending Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'none',
        'condition' => [
          'show_category_on_trending' => 'yes'
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
        'label' => esc_html__('Query Grid', 'elespare'),
        'condition' => [
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',
            'banner-style-4',
            'banner-style-5',
            'banner-style-6',
            'banner-style-7',
            'banner-style-12',
            'banner-style-13',

          ]
        ]
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
    $this->end_controls_section();


    $this->start_controls_section(
      '_section_query_for_carousel',
      [
        'label' => esc_html__('Query For Slider', 'elespare'),

      ]
    );


    $this->add_control(
      '_filter_by_carousel',
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
      'posts_category_carousel',
      [
        'label'   => esc_html__('Select Category', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'category', ''),
        'condition' => [
          '_filter_by_carousel' => 'category'
        ]
      ]
    );
    $this->add_control(
      'tag_term_ids_carousel',
      [
        'label'   => esc_html__('Select Tags', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'post_tag', ''),
        'condition' => [
          '_filter_by_carousel' => 'post_tag'
        ]

      ]
    );



    $this->end_controls_section();

    //Carousel Settings
    $this->start_controls_section(
      'section_carousel',
      [
        'label' => esc_html__('Slider Settings', 'elespare'),
      ]
    );
    $this->add_control(
      '_animation_speed',
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
      '_autoplay',
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
      '_autoplay_speed',
      [
        'label' => esc_html__('Autoplay Speed', 'elespare'),
        'type' => Controls_Manager::NUMBER,
        'min' => 100,
        'step' => 100,
        'max' => 10000,
        'default' => 6000,
        'description' => esc_html__('Autoplay speed in milliseconds', 'elespare'),
        'condition' => [
          'autoplay' => 'yes'
        ],
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      '_loop',
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
      'nav_show_on_hover',
      [
        'label' => esc_html__('Show  Navigation on Hover', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'yes',
        'frontend_available' => true,
      ]
    );


    $this->end_controls_section();

    $this->start_controls_section(
      '_section_query_for_trending',
      [
        'label' => esc_html__('Query For Trending', 'elespare'),
        'condition' => [
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',
            'banner-style-4',
            'banner-style-5',
            'banner-style-6',
            'banner-style-7',

          ]
        ]
      ]
    );


    $this->add_control(
      '_filter_by_trending',
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
      'posts_category_trending',
      [
        'label'   => esc_html__('Select Category', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'category', ''),
        'condition' => [
          '_filter_by_trending' => 'category'
        ]
      ]
    );
    $this->add_control(
      'tag_term_ids_trending',
      [
        'label'   => esc_html__('Select Tags', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => '0',
        'options' =>  elespare_get_categories_list_dropdown('', 'post_tag', ''),
        'condition' => [
          '_filter_by_trending' => 'post_tag'
        ]

      ]
    );


    $this->end_controls_section();

    $this->start_controls_section(
      'trending_section_carousel',
      [
        'label' => esc_html__('Trending Carousel Settings', 'elespare'),

      ]
    );

    $this->add_control(
      'trending_count_shapes',
      [
        'label' => esc_html__('Count Shapes', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'elespare-post-count-square',

        'description' => esc_html__('Count shapes', 'elespare'),
        'frontend_available' => true,
      ]
    );
    $this->add_control(
      'trending_count_shapes_position',
      [
        'label' => esc_html__('Count Postition', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'elespare-pst-cnt-bl',

        'description' => esc_html__('Count shape Positions', 'elespare'),
        'frontend_available' => true,
      ]
    );


    $this->add_control(
      'trending_animation_speed',
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
      'trending_autoplay',
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
      'trending_autoplay_speed',
      [
        'label' => esc_html__('Autoplay Speed', 'elespare'),
        'type' => Controls_Manager::NUMBER,
        'min' => 100,
        'step' => 100,
        'max' => 10000,
        'default' => 6000,
        'description' => esc_html__('Autoplay speed in milliseconds', 'elespare'),
        'condition' => [
          'trending_autoplay' => 'yes'
        ],
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      'trending_loop',
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




    $this->end_controls_section();
  }

  /*
   * posts_grid_section_title_options
   */
  private function posts_grid_section_title_options()
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
        'default' => $this->elespare_banner_layout_default('section_title'),
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
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => '#000',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-widget-title-section .elespare-widget-title  .elespare-section-title' => 'color: {{VALUE}};',


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
        'type' => \Elementor\Controls_Manager::COLOR,
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
        'type' => Controls_Manager::COLOR,
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
        'type' => \Elementor\Controls_Manager::COLOR,
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

  private function posts_grid_style_title_options()
  {
    // Layout.

    $this->start_controls_section(
      'section_layout_style',
      [
        'label' => esc_html__('Layout', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );
    // // Columns margin.
    // $this->add_control(
    //     'main_banner_style_columns_margin',
    //     [
    //         'label'     => esc_html__( 'Columns Gaps', 'elespare' ),
    //         'type'      => Controls_Manager::SLIDER,
    //         'default'   => [
    //             'size' => 10,
    //         ],
    //         'range'     => [
    //             'px' => [
    //                 'min' => 0,
    //                 'max' => 100,
    //             ],
    //         ],
    //         'selectors' => [
    //             '{{WRAPPER}} .elespare-banner-one-wrap' => 'column-gap: {{SIZE}}{{UNIT}}',

    //         ],
    //     ]
    // );
    // // Row margin.
    // $this->add_control(
    //     'main_banner_style_rows_margin',
    //     [
    //         'label'     => esc_html__( 'Rows Gaps', 'elespare' ),
    //         'type'      => Controls_Manager::SLIDER,
    //         'default'   => [
    //             'size' => 10,
    //         ],
    //         'range'     => [
    //             'px' => [
    //                 'min' => 0,
    //                 'max' => 100,
    //             ],
    //         ],
    //         'selectors' => [
    //             '{{WRAPPER}} .elespare-banner-one-wrap' => 'row-gap: {{SIZE}}{{UNIT}}',
    //         ],
    //     ]
    // );
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
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-carousel-wrap' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-grid-wrap .elespare-posts-grid-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending.has-background .elespare-posts-wrap .elespare-posts-trending-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending.has-no-background .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',

        ],

      ]
    );

    $this->end_controls_section();


    $this->start_controls_section(
      'section_title',
      [
        'label'     => esc_html__('Grid Title', 'elespare'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',


          ]

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
              'size' => $this->elespare_banner_layout_default('post_grid_title_font_size'),
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
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span',
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
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span'       => 'color: {{VALUE}};',
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
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a:hover span'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->end_controls_section();

    //Carousel color
    $this->start_controls_section(
      'section_carousel_title',
      [
        'label'     => esc_html__('Slider Title', 'elespare'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_carousel_title_style_typography',
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
              'size' => $this->elespare_banner_layout_default('post_slider_title_font_size'),
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
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span',
      ]
    );

    $this->start_controls_tabs('posts_carousel_title_color_style');

    // Normal tab.
    $this->start_controls_tab(
      'posts_carousel_title_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );



    $this->add_control(
      'posts_carousel_title_style_color1',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],

        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span'       => 'color: {{VALUE}};',
        ]
      ]
    );

    $this->end_controls_tab();


    // Hover tab.
    $this->start_controls_tab(
      'posts_carousel_title_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    // Title hover color.
    $this->add_control(
      'posts_carousel_title_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a:hover span' => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();
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


    $this->start_controls_section(
      'section_layout_trending_content_bg_style',
      [
        'label' => esc_html__('Content Background Color', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'enable_background' => 'yes'

        ]

      ]
    );
    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending.has-background .elespare-posts-wrap.elespare-light .elespare-posts-trending-post-items' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode!' => 'yes',

        ]

      ]
    );

    $this->add_control(
      'posts_background_darkmode_color_1',
      [
        'label'      => esc_html__('Trending Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-dark-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-trending-post-items' => 'background-color: {{VALUE}};',


        ],
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode' => 'yes',

        ]



      ]
    );

    $this->end_controls_section();

    // count 
    $this->start_controls_section(
      '_count',
      [
        'label' => esc_html__('Count', 'elespare-pro'),
        'tab'   => Controls_Manager::TAB_STYLE,

      ]
    );
    $this->add_control(
      'posts_trending_count_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Count Color', 'elespare-pro'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper .elespare-post-count' => 'color: {{VALUE}};',
        ]
      ]
    );
    $this->add_control(
      'posts_trending_bg_count_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Count Background Color', 'elespare-pro'),
        'default' => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper .elespare-post-count' => 'background-color: {{VALUE}};',
        ]
      ]
    );

    $this->end_controls_section();

    // count end

    //trending color
    $this->start_controls_section(
      'section_trending_title',
      [
        'label'     => esc_html__('Trending Title', 'elespare'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );


    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_trending_title_style_typography',
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
              'size' => $this->elespare_banner_layout_default('post_trending_title_font_size'),
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
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span',
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );

    $this->start_controls_tabs('posts_trending_title_color_style');

    // Normal tab.
    $this->start_controls_tab(
      'posts_trending_title_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
        'condition' => [
          'show_title' => 'yes',
        ]
      )
    );



    $this->add_control(
      'posts_trending_title_style_color1',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
          'show_title' => 'yes',
        ]
      ]
    );

    $this->add_control(
      'posts_title_style_dark_mode_color1',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes',
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',
            'banner-style-4',
            'banner-style-5',
            'banner-style-6',
            'banner-style-7',


          ],
          'show_title' => 'yes',
        ]

      ]
    );

    $this->end_controls_tab();


    // Hover tab.
    $this->start_controls_tab(
      'posts_trending_title_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
        'condition' => [
          'show_title' => 'yes',
        ]
      )
    );

    // Title hover color.
    $this->add_control(
      'posts_trending_title_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Title Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],

        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a:hover span'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();
    $this->end_controls_section();
  }
  /*
     * Category style option
     */

  private function posts_grid_style_category_options()
  {
    $this->start_controls_section(
      'section_category',
      [
        'label'     => esc_html__('Category', 'elespare-posts-grid-elementor'),
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
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap ul li > a',
      ]
    );

    // Category color.
    $this->add_control(
      'posts_category_style_solid_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => ['solid', 'border', 'underline']
        ]

      ]
    );
    $this->add_control(
      'posts_category_solid_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => 'solid'
        ]
      )
    );
    $this->add_control(
      'posts_category_border_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => ['border', 'underline']
        ]
      )
    );

    $this->add_control(
      'posts_category_style_none_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => 'none'
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
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap:not(.elespare-trending-wrap) .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_layout_style!' => ['none', 'underline']
        ]
      ]
    );

    $this->end_controls_section();
  }


  //posts_trending_style_category_options
  private function posts_trending_style_category_options()
  {
    $this->start_controls_section(
      'section_trending_category',
      [
        'label'     => esc_html__('Trending Category', 'elespare-posts-grid-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_category_on_trending' => 'yes',
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_category_trending_style_typography',
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
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap  ul li > a',
      ]
    );

    // Category color.
    $this->add_control(
      'posts_category_trending_style_solid_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_trending_layout_style' => ['solid']
        ]

      ]
    );
    $this->add_control(
      'posts_category_trending_solid_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_trending_layout_style' => 'solid'
        ]
      )
    );
    $this->add_control(
      'posts_category_trending_border_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_trending_layout_style' => ['border', 'underline']
        ]
      )
    );

    $this->add_control(
      'posts_category_trending_style_none_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => elespare_default_color('category'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_trending_layout_style' => ['none', 'border', 'underline']

        ]
      ]
    );

    $this->add_control(
      'posts_category_trending_style_margin',
      [
        'label'      => esc_html__('Border Radius', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'default' => [
          'top' => '3',
          'right' => '3',
          'bottom' => '3',
          'left' => '3',
          'isLinked' => true
        ],
        'selectors'  => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_trending_layout_style!' => ['none', 'underline']
        ]
      ]
    );

    $this->end_controls_section();
  }
  //Meta style
  private function posts_grid_style_meta_options()
  {
    $this->start_controls_section(
      'section_grid_meta',
      [
        'label'     => esc_html__('Grid Meta', 'elespare-posts-grid-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'layout_posts_style' => [
            'banner-style-1',
            'banner-style-2',
            'banner-style-3',
          ]
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_grid_meta_style_typography',
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
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata ',
      ]
    );

    $this->start_controls_tabs('posts_grid_meta_color_style');

    $this->start_controls_tab(
      'posts_grid_meta_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    // Meta color.
    $this->add_control(
      'posts_grid_meta_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ]
      ]

    );

    $this->end_controls_tab();

    // Hover tab.
    $this->start_controls_tab(
      'posts_grid_meta_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    // Meya hover color.
    $this->add_control(
      'posts_grid_meta_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata span >a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->end_controls_section();
  }

  private function posts_banner_style_post_format_options()
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
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Icon Color', 'elespare'),
        'default'   => '#ffffff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',
        ),
      )
    );
    $this->add_control(
      'posts_format_border_style_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => elespare_default_color('postformat'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'post_format_options' => 'elespare-border'
        ]
      )
    );
    $this->add_control(
      'posts_format_bg_style_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => '#BB1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',
        ),
        'condition' => [
          'post_format_options' => 'elespare-background'
        ]
      )
    );
    $this->end_controls_section();
  }

  //Slider Meta
  private function posts_slider_style_meta_options()
  {
    $this->start_controls_section(
      'section_slider_meta',
      [
        'label'     => esc_html__('Slider Meta', 'elespare-posts-grid-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_slider_meta_style_typography',
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
          'line_height' => [
            'default' => [
              'unit' => 'em',
              'size' => '1',
            ],
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a,{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count  ',
      ]
    );

    $this->start_controls_tabs('posts_slider_meta_color_style');

    $this->start_controls_tab(
      'posts_slider_meta_style_normal',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    // Meta color.
    $this->add_control(
      'posts_slider_meta_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ],
      ]

    );

    $this->end_controls_tab();

    // Hover tab.
    $this->start_controls_tab(
      'posts_slider_meta_style_hover',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    // Meya hover color.
    $this->add_control(
      'posts_slider_meta_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->end_controls_section();
  }


  private function posts_banner_style_carousel_options()
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
        'type' => Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-arrow'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev i, {{WRAPPER}} .slick-next i' => 'color: {{VALUE}};',
        ]
      ]
    );

    $this->add_control(
      'arrow_bg_color',
      [
        'label' => esc_html__('Background Color', 'elespare'),
        'type' => Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-background'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev, {{WRAPPER}} .slick-next' => 'background-color: {{VALUE}};',
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
        'type' => Controls_Manager::COLOR,
        'selectors' => [
          '{{WRAPPER}} .slick-prev:hover i, {{WRAPPER}} .slick-next:hover i' => 'color: {{VALUE}};',
        ]
      ]
    );

    $this->add_control(
      'arrow_hover_bg_color',
      [
        'label' => esc_html__('Background Color', 'elespare'),
        'type' => Controls_Manager::COLOR,
        'default' => elespare_default_color('navigation-background'),
        'selectors' => [
          '{{WRAPPER}} .slick-prev:hover, {{WRAPPER}} .slick-next:hover' => 'background-color: {{VALUE}};',
        ],
      ]
    );



    $this->end_controls_tab();
    $this->end_controls_tabs();
    $this->end_controls_section();
  }

  //Content style
  private function posts_grid_style_content_options()
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

        'selector'  => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-exceprt p',
      ]
    );

    // Content color.
    $this->add_control(
      'posts_content_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt' => 'color: {{VALUE}};',
        ],
      ]
    );
    $this->end_controls_section();
  }


  //Read More text
  private function  posts_grid_style_readmore_options()
  {
    $this->start_controls_section(
      'section_posts_reamore_style',
      [
        'label' => esc_html__('Read More', 'elespare-pro'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_read_more' => 'yes',
          'show_excerpt' => 'yes',
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
        'selector'  => '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-exceprt .read-more-btn',
      ]
    );

    $this->add_control(
      'posts_readmore_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare-pro'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-banner-one-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt .read-more-btn' => 'color: {{VALUE}};',
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
    $gravatar_class = '';
    if ('yes' == $settings['show_author'] && 'gravatar' == $settings['author_icon_options']) {
      $gravatar_class = 'elespare-gravatar';
    }

    $layouts = $settings['layout_posts_style'];
    $this->add_render_attribute('layout-gravatar', 'class', ['elespare-banner-one-wrap banner-layout', $gravatar_class, $layouts]);
    $this->add_inline_editing_attributes('title', 'basic');


    if ($layouts == 'banner-style-6' || $layouts == 'banner-style-7') {
      $numberOfSlides = 2;
    } else {
      $numberOfSlides = 1;
    }
    $show_on_hover = '';
    if ('yes' == $settings['nav_show_on_hover']) {
      $show_on_hover = 'show-on-hover';
    }



?>

    <div <?php $this->print_render_attribute_string('layout-gravatar'); ?>>
      <?php if ($layouts == 'banner-style-1' || $layouts == 'banner-style-2' || $layouts == 'banner-style-3') { ?>
        <div class="elespare-section-grid">
          <?php
          if ($settings['title']) :
            printf(
              '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
              $this->get_render_attribute_string('layout-wrap'),
              elespare_kses_basic($settings['title'])
            );
          endif;
          ?>
          <div
            class="elespare-posts-grid-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?>">
            <?php
            $grid_posts = elespare_get_all_posts_in_banner($settings, $sections = 'banner-1');
            if ($grid_posts->have_posts()) :
              while ($grid_posts->have_posts()) : $grid_posts->the_post(); ?>
                <div class="elespare-posts-grid-post-items">

                  <div class="elespare-img-wrapper">
                    <?php elespare_posts_grid_render_thumbnail($settings['post_grid_thumbnail_size']);
                    if ('yes' == $settings['show_post_format']) {
                      $grid_post_id = get_the_ID();
                      elespare_post_format($grid_post_id, $settings['post_format_options']);
                    }

                    ?>

                  </div>
                  <div class="elespare-content-wrapper">
                    <?php
                    if ('yes' ==  $settings['show_category']) {

                      echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                    }
                    elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                    $this->posts_grid_render_metadata();

                    ?>

                  </div>
                </div>
            <?php endwhile;
              wp_reset_postdata();
            endif; ?>
          </div>
        </div>
      <?php } ?>

      <div class="elespare-section-slider">
        <div class="elespare-section-carousel-title">
          <?php
          if ($settings['carousel_title']) :
            printf(
              '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
              $this->get_render_attribute_string('layout-wrap'),
              elespare_kses_basic($settings['carousel_title'])
            );
          endif;
          $carousel_posts = elespare_get_all_posts_in_banner($settings, $sections = 'carousel');
          if ($carousel_posts->have_posts()) : ?>
            <div
              class="elespare-carousel-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?> <?php echo esc_attr($show_on_hover); ?>"
              data-num="<?php echo esc_attr($numberOfSlides); ?>">
              <?php while ($carousel_posts->have_posts()) : $carousel_posts->the_post(); ?>
                <div class="elespare-posts-carousel-post-items">

                  <div class="elespare-img-wrapper">
                    <?php elespare_posts_grid_render_thumbnail($settings['post_slider_thumbnail_size']);
                    if ('yes' == $settings['show_post_format']) {
                      $carousel_post_id = get_the_ID();
                      elespare_post_format($carousel_post_id, $settings['post_format_options']);
                    }
                    ?>

                  </div>
                  <div class="elespare-content-wrapper">
                    <?php
                    if ('yes' ==  $settings['show_category']) {

                      echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                    }

                    elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                    $this->posts_grid_render_metadata();
                    // $excerpt_length = apply_filters( 'excerpt_length', 12 );
                    $excerpt_length = 12;
                    elespare_posts_tabs_render_excerpt_readmore(get_the_ID(), $settings['show_excerpt'], $excerpt_length, $settings['show_read_more'], $settings['read_more_text']);

                    ?>

                  </div>
                </div>
              <?php endwhile;
              wp_reset_postdata();
              ?>
            </div>
          <?php
          endif; ?>
        </div>
      </div>


      <button type="button" class="slick-prev elespare-nav-carousel"><i class="elespare-icons-angle-left"
          aria-hidden="true"></i></button>
      <button type="button" class="slick-next elespare-nav-carousel"><i class="elespare-icons-angle-right"
          aria-hidden="true"></i></button>


      <?php if ($settings['enable_background'] == 'yes') {
        $bg_class = 'has-background ';
      } else {
        $bg_class = 'has-no-background ';
      }
      ?>

      <div class="elespare-section-trending <?php echo esc_attr($bg_class); ?>">
        <div class="elespare-section-trending-title">
          <?php
          if ($settings['trending_title']) :
            printf(
              '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
              $this->get_render_attribute_string('layout-wrap'),
              elespare_kses_basic($settings['trending_title'])
            );
          endif;
          $count = 1;
          $darkmode = 'elespare-light';
          if ('yes' == $settings['dark_mode']) {
            $darkmode = 'elespare-dark';
          }


          $carousel_posts = elespare_get_all_posts_in_banner($settings, $sections = 'trending');
          if ($carousel_posts->have_posts()) : ?>
            <div
              class="elespare-trending-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?>  <?php echo esc_attr($darkmode); ?>">
              <?php
              while ($carousel_posts->have_posts()) : $carousel_posts->the_post(); ?>
                <div class="elespare-posts-trending-post-items">

                  <div class="elespare-img-wrapper">

                    <?php elespare_posts_grid_render_thumbnail('thumbnail');
                    ?>
                    <div
                      class="elespare-post-count <?php echo esc_attr($settings['trending_count_shapes']); ?> <?php echo esc_attr($settings['trending_count_shapes_position']) ?>">
                      <?php echo esc_html($count); ?></div>
                  </div>
                  <div class="elespare-content-wrapper">
                    <?php

                    if ('yes' ==  $settings['show_category_on_trending']) {

                      echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_trending_layout_style']));
                    }
                    elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());

                    ?>

                  </div>
                </div>
              <?php
                $count++;
              endwhile;

              wp_reset_postdata(); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
    <?php
  }


  protected function posts_grid_render_metadata()
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
              <?php  }
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
