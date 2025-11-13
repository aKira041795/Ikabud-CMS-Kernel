<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Group_Control_Image_Size;
use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class MainBannerOne extends Widget_Base
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
    return 'main-banner-1';
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
    return esc_html__('Main Banner', 'elespare');
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
    return 'demo-icon elespare-icons-main-banner';
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
  public function get_style_depends()
  {
    return array('slick');
  }
  public function get_script_depends()
  {
    return array('slick', 'elespare-custom-scripts');
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
    $this->posts_style_category_options();

    $this->posts_style_meta_options();
    $this->posts_banner_style_post_format_options();
    $this->posts_banner_style_carousel_options();
    $this->posts_grid_style_content_options();
    $this->posts_grid_style_readmore_options();
  }

  private function elespare_get_banner_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['banner_layout'] = 'main-banner-style-1';
        $data_array['section_title'] = 'title-style-2';
        $data_array['post_slider_title_font_size'] = 25;
        $data_array['post_grid_title_font_size'] = 18;
        $data_array['post_trending_title_font_size'] = 14;
        $data_array['post_title_color'] = elespare_default_color('post-title-normal');
        $data_array['catgory_text_color'] = '#fff';
        $data_array['catgory_text_none'] = elespare_default_color('category');
        $data_array['category_bg_color'] = elespare_default_color('category');
        $data_array['main_story'] = esc_html__('Main Story', 'elespare');
        $data_array['editor'] = esc_html__('Editor’s Picks', 'elespare');
        $data_array['trending'] = esc_html__('Trending Story', 'elespare');
        return  $data_array;
        break;

      default:
        $data_array['banner_layout'] = 'main-banner-style-1';
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
      ]
    );
    $this->add_control(
      'layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_banner_layout_default('banner_layout'),
        'options' => [
          'main-banner-style-1' => esc_html__('Default', 'elespare'),
          'main-banner-style-2' => esc_html__('Layout 2', 'elespare'),
          'main-banner-style-3' => esc_html__('Layout 3', 'elespare'),
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

    $this->start_controls_tabs(
      'elespare_imgsize_tabs'
    );
    $this->start_controls_tab(
      'elespare_slider_imgsize_tabs',
      [
        'label' => __('Slider', 'elespare'),
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
    $this->end_controls_tab();

    $this->start_controls_tab(
      'elespare_grid_imgsize_tabs',
      [
        'label' => __('Grid', 'elespare'),
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
    $this->end_controls_tab();
    $this->start_controls_tab(
      'elespare_trending_imgsize_tabs',
      [
        'label' => __('Trending', 'elespare'),
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
    $this->end_controls_tab();


    $this->end_controls_tabs();





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
        'type' => Controls_Manager::SWITCHER,
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
      'category_layout_style',
      [
        'label' => esc_html__('Slider Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'solid',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',

          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]
        ]
      ]
    );

    $this->add_control(
      'category_layout_style2',
      [
        'label' => esc_html__('Slider Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'underline',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',

          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]
      ]
    );
    $this->add_control(
      'category_layout_style3',
      [
        'label' => esc_html__('Slider Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'border',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',

          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      ]
    );
    $this->add_control(
      'category_grid_layout_style',
      [
        'label' => esc_html__('Grid Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'solid',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',



          ]

        ]
      ]
    );
    $this->add_control(
      'category_grid_layout_style2',
      [
        'label' => esc_html__('Grid Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'underline',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]
      ]
    );
    $this->add_control(
      'category_grid_layout_style3',
      [
        'label' => esc_html__('Grid Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'border',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          'show_category' => 'yes',
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]

        ]
      ]
    );
    // $this->add_control(
    //     'show_category_on_trending',
    //     [
    //         'label' => esc_html__('Display Categories(Trending) ', 'elespare'),
    //         'type' => Controls_Manager::SWITCHER,
    //         'label_on' => esc_html__('Show', 'elespare'),
    //         'label_off' => esc_html__('Hide', 'elespare'),
    //         'default' => 'yes',
    //         'separator' => 'before',
    //     ]
    // );
    $this->add_control(
      'category_trending_layout_style',
      [
        'label' => esc_html__(' Trending Category Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'none',
        'options' => [
          'none' => esc_html__('None', 'elespare'),
          'solid' => esc_html__('Solid', 'elespare'),
          'border' => esc_html__('Border', 'elespare'),
          'underline' => esc_html__('Underline', 'elespare'),
        ],
        'condition' => [
          //  'show_category_on_trending' => 'yes'
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
      'author_icon',
      [
        'label' => esc_html__('Author Icon', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_block' => true,
        'default' => [
          'value' => 'demo-icon elespare-icons-user-circle-regular',
          'library' => 'reguler'
        ],
        'condition' => [
          'show_author' => 'yes',
          'author_icon_options' => 'icon'
        ],
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
        'options' => [
          'elespare-background' => esc_html__('Background', 'elespare'),
          'elespare-border' => esc_html__('Border', 'elespare'),
          'elespare-none' => esc_html__('None', 'elespare'),

        ],
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
      'date_icon',
      [
        'label' => esc_html__('Date Icon', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_block' => true,
        'default' => [
          'value' => 'demo-icon elespare-icons-clock-regular',
          'library' => 'reguler'
        ],
        'condition' => [
          'show_date' => 'yes',
        ],
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
      'commentIcon',
      [
        'label' => esc_html__('Comment Icon', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_block' => true,
        'default' => [
          'value' => 'demo-icon elespare-icons-comment-empty',
          'library' => 'reguler'
        ],
        'condition' => [
          'show_comment_count' => 'yes',
        ],
      ]
    );


    $this->add_control(
      'show_excerpt',
      [
        'label' => esc_html__('Excerpt', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );
    $this->add_control(
      'excerpt_length',
      [
        'label' => esc_html__('Excerpt Length', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        /** This filter is documented in wp-includes/formatting.php */
        // 'default' => apply_filters('excerpt_length', 12),
        'default' => 12,
        'condition' => [
          'show_excerpt' => 'yes',
        ],
      ]
    );

    $this->add_control(
      'show_read_more',
      [
        'label' => esc_html__('Read More', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
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
        'label' => esc_html__('Read More Text', 'elespare'),
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Read More', 'elespare'),
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

    $this->start_controls_tabs(
      'query_tabs'
    );

    $this->start_controls_tab(
      'query_slider_tab',
      [
        'label' => esc_html__('Slider', 'elespare'),
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

    $this->add_control(
      'posts_per_page_carousel',
      [
        'label' => esc_html__('Posts Per Page', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 6,
      ]
    );


    $this->add_control(
      'orderby_carousel',
      [
        'label' => esc_html__('Order By', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'post_date',
        'options' => elespare_orderby_list(),
      ]
    );

    $this->add_control(
      'order_carousel',
      [
        'label' => esc_html__('Order', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'desc',
        'options' => [
          'asc' => esc_html__('ASC', 'elespare'),
          'desc' => esc_html__('DESC', 'elespare'),
        ],
      ]
    );
    $this->end_controls_tab();

    $this->start_controls_tab(
      'query_grid_tab',
      [
        'label' => esc_html__('Grid', 'elespare'),
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
      'add_rows_of_grid',
      [
        'label' => esc_html__('Add More Posts in Grid', 'elespare'),
        'type' => \Elementor\Controls_Manager::HIDDEN,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'no',
      ]
    );
    $this->add_control(
      'posts_per_page_grid',
      [
        'label' => esc_html__('Posts In Lower Grid', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 4,
        'condition' => [
          'add_rows_of_grid' => 'yes',
        ]
      ]
    );


    $this->add_control(
      'orderby',
      [
        'label' => esc_html__('Order By', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'post_date',
        'options' => elespare_orderby_list(),
      ]
    );

    $this->add_control(
      'order',
      [
        'label' => esc_html__('Order', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'desc',
        'options' => [
          'asc' => esc_html__('ASC', 'elespare'),
          'desc' => esc_html__('DESC', 'elespare'),
        ],
      ]
    );
    $this->end_controls_tab();

    $this->start_controls_tab(
      'query_trending_tab',
      [
        'label' => esc_html__('Trending', 'elespare'),
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

    $this->add_control(
      'posts_per_page_trending',
      [
        'label' => esc_html__('Posts Per Page', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 4,
      ]
    );


    $this->add_control(
      'orderby_trending',
      [
        'label' => esc_html__('Order By', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'post_date',
        'options' => elespare_orderby_list(),
      ]
    );

    $this->add_control(
      'order_trending',
      [
        'label' => esc_html__('Order', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'desc',
        'options' => [
          'asc' => esc_html__('ASC', 'elespare'),
          'desc' => esc_html__('DESC', 'elespare'),
        ],
      ]
    );

    $this->end_controls_tab();

    $this->end_controls_tabs();
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
        'label' => esc_html__('Show on Hover', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Yes', 'elespare'),
        'label_off' => esc_html__('No', 'elespare'),
        'return_value' => 'yes',
        'default' => 'yes',
        'frontend_available' => true,
      ]
    );


    $this->add_control(
      '_arrow_prev_icon',
      [
        'label' => esc_html__('Previous Icon', 'elespare'),
        'label_block' => false,
        'type' => Controls_Manager::HIDDEN,
        'skin' => 'inline',
        'default' => [
          'value' => 'demo-icon elespare-icons-angle-left',
          'library' => 'reguler',
        ],
      ]
    );

    $this->add_control(
      '_arrow_next_icon',
      [
        'label' => esc_html__('Next Icon', 'elespare'),
        'label_block' => false,
        'type' => Controls_Manager::HIDDEN,
        'skin' => 'inline',
        'default' => [
          'value' => 'demo-icon elespare-icons-angle-right',
          'library' => 'reguler',
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
        'options' => [
          'default' => esc_html__('Circle', 'elespare'),
          'elespare-post-count-square' => esc_html__('Square', 'elespare')
        ],
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
        'options' => [
          'elespare-pst-cnt-bl' => esc_html__('Default', 'elespare'),
          'elespare-pst-cnt-tl' => esc_html__('Top Left ', 'elespare'),
          'elespare-pst-cnt-tr' => esc_html__('Top Right ', 'elespare'),
          'elespare-pst-cnt-br' => esc_html__('Bottom Right ', 'elespare')
        ],
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


    $this->add_responsive_control(
      'trending_slides_to_show',
      [
        'label' => esc_html__('Slides To Show', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 4,
        'options' => [
          3 => esc_html__('3 Slides', 'elespare'),
          4 => esc_html__('4 Slides', 'elespare'),
        ],
        'desktop_default' => 3,
        'tablet_default' => 2,
        'mobile_default' => 1,
        'frontend_available' => true,
        'style_transfer' => true,
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

    //             '{{WRAPPER}} .main-banner-upper-section-wrap' => 'grid-column-gap: {{SIZE}}{{UNIT}}',


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

    //             '{{WRAPPER}} .elespare-main-banner-wrap' => 'row-gap: {{SIZE}}{{UNIT}}',
    //             '{{WRAPPER}} .elespare-section-trending' => 'margin-top: {{SIZE}}{{UNIT}}',

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
            'max' => 40,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider.has-background  .elespare-carousel-wrap' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-5 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-6 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-13 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-14 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-21 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-22 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-29 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-30 .elespare-section-slider:not(.has-background)  .elespare-carousel-wrap .elespare-posts-carousel-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid.has-background   .elespare-posts-grid-wrap .elespare-posts-grid-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid-lower.has-background   .elespare-posts-grid-wrap .elespare-posts-grid-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid:not(.has-background)  .elespare-posts-grid-wrap .elespare-posts-grid-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid-lower:not(.has-background)  .elespare-posts-grid-wrap .elespare-posts-grid-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending.has-background .elespare-posts-wrap .elespare-posts-trending-post-items' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending:not(.has-background) .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending:not(.has-background) .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper a' => 'border-radius: {{SIZE}}{{UNIT}}',

        ],

      ]
    );

    $this->end_controls_section();
    //content Background color

    $this->start_controls_section(
      'whole_post_title_main_banner',
      [
        'label' => esc_html__(' Post Title', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
        'condition' => [
          'show_title' => 'yes',
        ]
      ]
    );


    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'label' => 'Slider Title Typography',
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span',
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
        'label'     => esc_html__('Slider Title Color', 'elespare'),
        'default' => '#f3f3f3',
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'condition' => [
          'show_title' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-29',
            'main-banner-style-30',
          ],

        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
      ]
    );
    $this->add_control(
      'posts_carousel_title_style_color_with_bg',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Slider Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span'       => 'color: {{VALUE}};',

        ],
        'condition' => [
          'dark_mode!' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ],
          'show_title' => 'yes',
        ],
      ]
    );

    $this->add_control(
      'posts_carousel_title_style_dark_mode_color1',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Slider Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a > span'       => 'color: {{VALUE}};',

        ],
        'condition' => [
          'dark_mode' => 'yes',
          'show_title' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ],

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
        'label'     => esc_html__('Slider Title Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'separator' => 'after',
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items h4 a:hover span' => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();




    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'label'  => 'Grid Title Typography',
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

        'selector' => '{{WRAPPER}} .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-content-wrapper .elespare-post-title a > span',
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
        'label'     => esc_html__('Grid Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span'       => 'color: {{VALUE}};',

        ),
        'condition' => [
          'dark_mode!' => 'yes',
          'show_title' => 'yes',
        ]
      ]
    );

    $this->add_control(
      'grid_posts_title_style_dark_mode_color1',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Grid Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items h4 a > span'       => 'color: {{VALUE}};',

        ),
        'condition' => [
          'dark_mode' => 'yes',
          'show_title' => 'yes',
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
        'label'     => esc_html__('Grid Title Color', 'elespare'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items h4 a:hover span'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items h4 a:hover span'       => 'color: {{VALUE}};',

        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();



    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'label'   => 'Trending Title Typography',
        'name'     => 'posts_trending_title_style_typography',
        'separator' => 'before',
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
        'separator' => 'before',
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span',
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
        'label'     => esc_html__('Trending Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span'       => 'color: {{VALUE}};',
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
        'label'     => esc_html__('Trending Title Color', 'elespare'),
        'default' => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a > span'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes',
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
      )
    );

    // Title hover color.
    $this->add_control(
      'posts_trending_title_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Trending Title Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items h4 a:hover span'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();


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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper .elespare-post-count' => 'color: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending .elespare-posts-wrap .elespare-posts-trending-post-items .elespare-img-wrapper .elespare-post-count' => 'background-color: {{VALUE}};',
        ]
      ]
    );

    $this->end_controls_section();

    // count end


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
          // 'elespare-image-zoom-tilt' => esc_html__('Zoom and Tilt', 'elespare'),
          // 'elespare-image-grayscale' => esc_html__('Grayscale', 'elespare'),
          // 'elespare-image-brightness' => esc_html__('Brightness', 'elespare'),
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
          'enable_background' => 'yes',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',

          ]
        ]

      ]
    );
    $this->add_control(
      'posts_slider_background_color_1',
      [
        'label'      => esc_html__('Slider Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider.has-background .elespare-posts-wrap.elespare-light' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode!' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]

      ]
    );

    $this->add_control(
      'posts_slider_background_darkmode_color_1',
      [
        'label'      => esc_html__('Slider Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-dark-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-carousel-post-items' => 'background-color: {{VALUE}};',


        ],
        'condition' => [
          'dark_mode' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]



      ]
    );

    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Trending Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending.has-background .elespare-posts-wrap.elespare-light .elespare-posts-trending-post-items' => 'background-color: {{VALUE}};',

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-trending.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-trending-post-items' => 'background-color: {{VALUE}};',


        ],
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode' => 'yes',

        ]



      ]
    );

    $this->add_control(
      'posts_background_color_2',
      [
        'label'      => esc_html__('Editors Pick Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid.has-background .elespare-posts-wrap.elespare-light .elespare-posts-grid-post-items' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid-lower.has-background .elespare-posts-wrap.elespare-light .elespare-posts-grid-post-items' => 'background-color: {{VALUE}};',

        ),
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode!' => 'yes',

        ]

      ]
    );

    $this->add_control(
      'posts_background_darkmode_color_2',
      [
        'label'      => esc_html__('Editors Pick Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-dark-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-grid-post-items' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .main-banner-upper-section-wrap .elespare-section-grid-lower.has-background .elespare-posts-wrap.elespare-dark .elespare-posts-grid-post-items' => 'background-color: {{VALUE}};',


        ),
        'condition' => [
          'enable_background' => 'yes',
          'dark_mode' => 'yes',

        ]



      ]
    );



    $this->end_controls_section();
  }
  /*
     * Category style option
     */

  private function posts_style_category_options()
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
    $this->start_controls_tabs(
      'cat_style_tabs'
    );

    $this->start_controls_tab(
      'slider_cat_style_tab',
      [
        'label' => esc_html__('Slider', 'elespare'),
        'condition' => [
          'show_category' => 'yes',
        ],
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap ul li > a',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => ['solid', 'border', 'underline'],
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => 'solid',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style' => ['border', 'underline'],
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style' => 'none',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap:not(.elespare-trending-wrap) .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_layout_style!' => ['none', 'underline'],
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',

          ]
        ]
      ]
    );
    $this->add_control(
      'posts_category_style_solid_color2',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => '#FFFFFF',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style2' => ['solid'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]

      ]
    );
    $this->add_control(
      'posts_category_solid_color2',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style2' => 'solid',
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]
      )
    );
    $this->add_control(
      'posts_category_border_color2',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => '#bb1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style2' => ['border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]
      )
    );

    $this->add_control(
      'posts_category_style_none_color2',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style2' => ['none', 'border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]
      ]
    );

    $this->add_control(
      'posts_category_style_margin2',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap:not(.elespare-trending-wrap) .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_layout_style2!' => ['none', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]

        ]
      ]
    );

    $this->add_control(
      'posts_category_style_solid_color3',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => '#ffffff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style3' => ['solid'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]

        ]
      ]
    );
    $this->add_control(
      'posts_category_solid_color3',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style3' => 'solid',
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',

          ]
        ]
      )
    );
    $this->add_control(
      'posts_category_border_color3',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => '#bb1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_layout_style3' => ['border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      )
    );

    $this->add_control(
      'posts_category_style_none_color3',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-carousel-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_layout_style3' => ['none', 'border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',

          ]
        ]
      ]
    );

    $this->add_control(
      'posts_category_style_margin3',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap:not(.elespare-trending-wrap) .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_layout_style3!' => ['none', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      ]
    );

    $this->end_controls_tab();

    $this->start_controls_tab(
      'grid_cat_style_tab',
      [
        'label' => esc_html__('Grid', 'elespare'),
        'condition' => [
          'show_category' => 'yes',
        ],
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'posts_editor_category_style_typography',
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap ul li > a',
      ]
    );

    // Category color.
    $this->add_control(
      'posts_editor_category_style_solid_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style' => ['solid', 'border', 'underline'],
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',


          ]
        ]

      ]
    );
    $this->add_control(
      'posts_editor_category_solid_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style' => 'solid',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',


          ]
        ]
      )
    );
    $this->add_control(
      'posts_editor_category_border_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style' => ['border', 'underline'],
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',


          ]
        ]
      )
    );

    $this->add_control(
      'posts_editor_category_style_none_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style' => 'none',
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-23',
            'main-banner-style-24',



          ]
        ]
      ]
    );

    $this->add_control(
      'posts_editor_category_style_margin',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_grid_layout_style!' => ['none', 'underline'],
          'main-banner-style-1',
          'main-banner-style-2',
          'main-banner-style-3',
          'main-banner-style-4',
          'main-banner-style-5',
          'main-banner-style-6',
          'main-banner-style-7',
          'main-banner-style-8',
          'main-banner-style-17',
          'main-banner-style-18',
          'main-banner-style-19',
          'main-banner-style-20',
          'main-banner-style-21',
          'main-banner-style-22',
          'main-banner-style-23',
          'main-banner-style-24',
        ]
      ]
    );

    $this->add_control(
      'posts_editor_category_style_solid_color2',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => '#FFFFFF',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style2' => ['solid'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]

      ]
    );
    $this->add_control(
      'posts_editor_category_solid_color2',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style2' => 'solid',
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]
      )
    );
    $this->add_control(
      'posts_editor_category_border_color2',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => '#bb1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style2' => ['border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]
      )
    );

    $this->add_control(
      'posts_editor_category_style_none_color2',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style2' => ['none', 'border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]
      ]
    );

    $this->add_control(
      'posts_editor_category_style_margin2',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_grid_layout_style2!' => ['none', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-29',
            'main-banner-style-30',
            'main-banner-style-31',
            'main-banner-style-32',
          ]
        ]
      ]
    );

    $this->add_control(
      'posts_editor_category_style_solid_color3',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => '#ffffff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style3' => ['solid'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',

          ]
        ]
      ]
    );
    $this->add_control(
      'posts_editor_category_solid_color3',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Background Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('category_bg_color'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style3' => 'solid',
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',

          ]
        ]
      )
    );
    $this->add_control(
      'posts_editor_category_border_color3',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Border Color', 'elespare'),
        'default'   => '#bb1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
        ),
        'condition' => [
          'category_grid_layout_style3' => ['border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      )
    );

    $this->add_control(
      'posts_editor_category_style_none_color3',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => $this->elespare_banner_layout_default('catgory_text_none'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'category_grid_layout_style3' => ['none', 'border', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      ]
    );

    $this->add_control(
      'posts_editor_category_style_margin3',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-grid-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_grid_layout_style3!' => ['none', 'underline'],
          'layout_posts_style' => [

            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-15',
            'main-banner-style-16',


          ]
        ]
      ]
    );

    $this->end_controls_tab();

    $this->start_controls_tab(
      'trending_cat_style_tab',
      [
        'label' => esc_html__('Trending', 'elespare'),
        'condition' => [
          //     'show_category_on_trending' => 'yes']
          'show_category' => 'yes'
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap  ul li > a',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links.solid li >a'       => 'background-color: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links li > a'       => 'border-color: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a'       => 'color: {{VALUE}};',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-trending-wrap.elespare-posts-wrap .elespare-cat-links .elespare-meta-category > a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

        'condition' => [
          'category_trending_layout_style!' => ['none', 'underline']
        ]
      ]
    );


    $this->end_controls_tab();

    $this->end_controls_tabs();

    $this->end_controls_section();
  }


  //Meta style
  private function posts_style_meta_options()
  {
    $this->start_controls_section(
      'section_meta',
      [
        'label'     => esc_html__('Meta', 'elespare-posts-grid-elementor'),
        'tab'       => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'label' => 'Slider Meta Typography',
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a,{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count  ',
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
        'label'     => esc_html__('Slider Meta Color', 'elespare'),
        'separator' => 'after',
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => [
            'main-banner-style-5',
            'main-banner-style-6',
            'main-banner-style-13',
            'main-banner-style-14',
            'main-banner-style-21',
            'main-banner-style-22',
            'main-banner-style-29',
            'main-banner-style-30',



          ],
        ],
      ]

    );
    $this->add_control(
      'posts_slider_meta_style_color_bg',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Slider Meta Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',



          ],
        ]
      ]

    );

    $this->add_control(
      'posts_slider_meta_dark_style_color_bg',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Slider Meta Color', 'elespare'),
        'separator' => 'after',
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes',
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ],
        ]
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
        'label'     => esc_html__('Slider Meta Color', 'elespare'),
        'separator' => 'after',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata span > a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
        ),
      )
    );

    $this->end_controls_tab();
    $this->end_controls_tabs();

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'label' => 'Grid Meta Typography',
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
        'selector' => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata span',
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
        'label'     => esc_html__('Grid Meta Color', 'elespare'),
        'default' => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap.elespare-light .elespare-posts-grid-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap.elespare-light  .elespare-posts-grid-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
        ]
      ]

    );

    $this->add_control(
      'posts_grid_meta_dark_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Grid Meta Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-grid-post-items .elespare-metadata span > a'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap.elespare-dark .elespare-posts-grid-post-items .elespare-metadata .comment_count'   => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-posts-wrap.elespare-dark .elespare-posts-grid-post-items .elespare-content-wrapper .elespare-exceprt p'   => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes',
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

    // Meta hover color.
    $this->add_control(
      'posts_grid_meta_style_hover_color',
      array(
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Grid Meta Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata span >a:hover'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-metadata .comment_count:hover'       => 'color: {{VALUE}};',
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
        'default'   => elespare_default_color('postformat'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format >i'       => 'color: {{VALUE}};',

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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-border > i:after'       => 'border-color: {{VALUE}};',

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
        'default'   => elespare_default_color('postformat-bg'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-slider .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-section-grid-lower .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-post-format.elespare-background > i:after'       => 'background: {{VALUE}};',

        ),
        'condition' => [
          'post_format_options' => 'elespare-background'
        ]
      )
    );
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


    // $this->add_group_control(
    //     Group_Control_Border::get_type(),
    //     [
    //         'name' => 'arrow_border',
    //         'selector' => '{{WRAPPER}} .slick-prev, {{WRAPPER}} .slick-next',
    //     ]
    // );

    $this->add_responsive_control(
      'arrow_border_radius',
      [
        'label' => esc_html__('Border Radius', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'size_units' => ['px', '%'],
        'selectors' => [
          '{{WRAPPER}} .slick-prev, {{WRAPPER}} .slick-next' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
        ],
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


    $this->start_controls_tabs('_tabs_content');
    $this->start_controls_tab(
      '_tab_content_normal',
      [
        'label' => esc_html__('Slider', 'elespare'),
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
        'selector'  => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-exceprt p',
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
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-5 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-6 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-13 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-14 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-21 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-22 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-29 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-30 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ],
        ]
      ]
    );

    $this->add_control(
      'posts_spotlight_content_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default'   => '#767676',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-5 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-6 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-13 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-14 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-21 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-22 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-29 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap.main-banner-style-30 .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'layout_posts_style!' => [
            'main-banner-style-1',
            'main-banner-style-2',
            'main-banner-style-3',
            'main-banner-style-4',
            'main-banner-style-7',
            'main-banner-style-8',
            'main-banner-style-9',
            'main-banner-style-10',
            'main-banner-style-11',
            'main-banner-style-12',
            'main-banner-style-15',
            'main-banner-style-16',
            'main-banner-style-17',
            'main-banner-style-18',
            'main-banner-style-19',
            'main-banner-style-20',
            'main-banner-style-23',
            'main-banner-style-24',
            'main-banner-style-25',
            'main-banner-style-26',
            'main-banner-style-27',
            'main-banner-style-28',
            'main-banner-style-31',
            'main-banner-style-32',
          ],
        ]
      ]
    );
    $this->end_controls_tab();

    $this->start_controls_tab(
      '_tab_content_grid',
      [
        'label' => esc_html__('Grid', 'elespare'),
      ]
    );
    // Content typography.
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'      => 'posts_content_grid_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'selector'  => '{{WRAPPER}} .elespare-section-grid .elespare-posts-grid-post-items .elespare-exceprt p , {{WRAPPER}} .elespare-posts-grid-wrap .elespare-posts-grid-post-items .elespare-exceprt p',
      ]
    );

    // Content color.
    $this->add_control(
      'posts_content_grid_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => '#767676',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-section-grid .elespare-posts-grid-post-items .elespare-exceprt p,' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-content-wrapper .elespare-exceprt p,' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elementor-widget-main-banner-1 .elespare-posts-grid-wrap .elespare-posts-grid-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items .elespare-exceprt p' => 'color: {{VALUE}};',
        ],
      ]
    );
    $this->end_controls_tab();
    $this->end_controls_tabs();
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
    $this->start_controls_tabs('_tabs_read_more');
    $this->start_controls_tab(
      '_tab_read_more_normal',
      [
        'label' => esc_html__('Slider', 'elespare-pro'),
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'      => 'posts_slider_readmore_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        // ],
        'selector'  => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items  .elespare-exceprt .read-more-btn'
      ]
    );


    $this->add_control(
      'posts_slider_read_more_dark_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color 3', 'elespare-pro'),
        'default' => "#fff",
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-carousel-post-items .elespare-exceprt .read-more-btn' => 'color: {{VALUE}};',
        ]
      ]
    );

    $this->end_controls_tab();
    $this->start_controls_tab(
      '_tab_read_more_grid',
      [
        'label' => esc_html__('Grid', 'elespare-pro'),
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'      => 'posts_grid_readmore_style_typography',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT,
        ],
        'selector'  => '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items  .elespare-exceprt .read-more-btn'
      ]
    );

    $this->add_control(
      'posts_grid_read_more_light_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare-pro'),
        'default' => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-main-banner-wrap .elespare-posts-wrap .elespare-posts-grid-post-items  .elespare-exceprt .read-more-btn' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes',
        ]
      ]
    );



    $this->end_controls_tab();
    $this->end_controls_tabs();
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
    $this->add_render_attribute('layout-gravatar', 'class', ['elespare-main-banner-wrap banner-layout', $gravatar_class, $layouts]);
    $this->add_inline_editing_attributes('title', 'basic');

    $numberOfSlides = 1;
    $bg_class_slider = '';
    $dark_mode_slider = 'elespare-light';
    if ($settings['enable_background'] == 'yes' && ($layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30')) {
      $bg_class_slider = 'has-background';
    }
    if ($settings['dark_mode'] == 'yes' && ($layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30')) {
      $dark_mode_slider = 'elespare-dark';
    }
    $show_on_hover = '';
    if ('yes' == $settings['nav_show_on_hover']) {
      $show_on_hover = 'show-on-hover';
    }
    if ($settings['enable_background'] == 'yes') {
      $bg_class = 'has-background ';
    }
    $darkmode = 'elespare-light';
    if ('yes' == $settings['dark_mode']) {
      $darkmode = 'elespare-dark';
    }

    $post_format_enable_class = "elespare-post-format-disabled";
    if ('yes' == $settings['show_post_format']) {
      $post_format_enable_class = "elespare-post-format-enabled";
    }




?>
    <div <?php $this->print_render_attribute_string('layout-gravatar'); ?>>

      <div class='main-banner-upper-section-wrap <?php echo esc_attr($post_format_enable_class); ?>'>


        <div class="elespare-section-slider <?php echo esc_attr($bg_class_slider); ?>">
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
                class="elespare-carousel-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?> <?php echo esc_attr($show_on_hover); ?> <?php echo esc_attr($dark_mode_slider); ?>"
                data-num="<?php echo esc_attr($numberOfSlides); ?>">
                <?php while ($carousel_posts->have_posts()) : $carousel_posts->the_post(); ?>
                  <div class="elespare-posts-carousel-post-items">
                    <?php
                    $has_class = elespare_has_post_format(get_the_ID(), $settings['post_format_options']);

                    ?>
                    <div class="elespare-img-wrapper <?php echo esc_attr($has_class); ?> ">
                      <?php elespare_posts_grid_render_thumbnail($settings['post_slider_thumbnail_size']);

                      if ('yes' == $settings['show_post_format']) {
                        $carousel_post_id = get_the_ID();
                        elespare_post_format($carousel_post_id, $settings['post_format_options']);
                      }


                      if ($layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-17' || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {
                      ?>

                        <?php
                        if ('yes' ==  $settings['show_category']) {
                          if ($layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-17' || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                          }
                          if ($layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style3']));
                          }
                        }
                        ?>
                      <?php
                      }
                      ?>


                    </div>
                    <div class="elespare-content-wrapper">
                      <?php
                      if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' ||  $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8' || $layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16' || $layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {

                        if ('yes' ==  $settings['show_category']) {
                          if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' ||  $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style']));
                          }
                          if ($layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style2']));
                          }
                          if ($layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_layout_style3']));
                          }
                        }
                      }

                      elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                      $this->posts_grid_render_metadata();
                      elespare_posts_tabs_render_excerpt_readmore(get_the_ID(), $settings['show_excerpt'], $settings['excerpt_length'], $settings['show_read_more'], $settings['read_more_text']);

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

        <div class="elespare-section-grid <?php echo esc_attr($bg_class); ?>">
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
            class="elespare-posts-grid-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?> <?php echo esc_attr($darkmode); ?>">
            <?php
            $grid_posts = elespare_get_all_posts_in_banner_one_grid($settings, $banner_grid_left = 'left');


            if ($grid_posts) :
              while ($grid_posts->have_posts()) : $grid_posts->the_post(); ?>
                <div class="elespare-posts-grid-post-items ">
                  <?php
                  $has_class_grid = elespare_has_post_format(get_the_ID(), $settings['post_format_options']);
                  ?>
                  <div class="elespare-img-wrapper <?php echo esc_attr($has_class_grid); ?>">
                    <?php elespare_posts_grid_render_thumbnail($settings['post_grid_thumbnail_size']);
                    if ('yes' == $settings['show_post_format']) {
                      $grid_post_id = get_the_ID();
                      elespare_post_format($grid_post_id, $settings['post_format_options']);
                    }

                    if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' || $layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8' || $layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16' || $layouts == 'main-banner-style-17'  || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {

                      if ('yes' ==  $settings['show_category']) {

                        if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' || $layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8' || $layouts == 'main-banner-style-17'  || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {

                          echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style']));
                        }


                        if ($layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16') {

                          echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style3']));
                        }
                      }
                    }

                    ?>

                  </div>
                  <div class="elespare-content-wrapper">
                    <?php
                    if ($layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {
                      if ('yes' ==  $settings['show_category']) {
                        // if ($layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16'   ) {

                        //     echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style']));
                        // }
                        if ($layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {

                          echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style2']));
                        }
                      }
                    }
                    elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                    $this->posts_grid_render_metadata();
                    elespare_posts_tabs_render_excerpt_readmore(get_the_ID(), $settings['show_excerpt'], $settings['excerpt_length'], $settings['show_read_more'], $settings['read_more_text']);
                    ?>

                  </div>
                </div>
            <?php

              endwhile;

            endif; ?>
          </div>
        </div>
        <?php if ('yes' ==  $settings['add_rows_of_grid']) : ?>
          <div class="elespare-section-grid-lower <?php echo esc_attr($bg_class); ?>">

            <div
              class="elespare-posts-grid-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?> <?php echo esc_attr($darkmode); ?>">
              <?php
              $lower_grid_posts = elespare_get_all_posts_in_banner_one_grid($settings, $banner_grid_left = 'lower');

              if ($lower_grid_posts->have_posts()) :
                while ($lower_grid_posts->have_posts()) : $lower_grid_posts->the_post(); ?>
                  <div class="elespare-posts-grid-post-items">
                    <?php
                    $has_class_grid = elespare_has_post_format(get_the_ID(), $settings['post_format_options']);
                    ?>
                    <div class="elespare-img-wrapper <?php echo esc_attr($has_class_grid); ?>">
                      <?php elespare_posts_grid_render_thumbnail($settings['post_grid_thumbnail_size']);
                      if ('yes' == $settings['show_post_format']) {
                        $grid_post_id = get_the_ID();
                        elespare_post_format($grid_post_id, $settings['post_format_options']);
                      }

                      if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' || $layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8' || $layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16' || $layouts == 'main-banner-style-17'  || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {

                        if ('yes' ==  $settings['show_category']) {

                          if ($layouts == 'main-banner-style-1' || $layouts == 'main-banner-style-2' || $layouts == 'main-banner-style-3' || $layouts == 'main-banner-style-4' || $layouts == 'main-banner-style-5' || $layouts == 'main-banner-style-6' || $layouts == 'main-banner-style-7' || $layouts == 'main-banner-style-8' || $layouts == 'main-banner-style-17'  || $layouts == 'main-banner-style-18' || $layouts == 'main-banner-style-19' || $layouts == 'main-banner-style-20' || $layouts == 'main-banner-style-21' || $layouts == 'main-banner-style-22' || $layouts == 'main-banner-style-23' || $layouts == 'main-banner-style-24') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style']));
                          }


                          if ($layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style3']));
                          }
                        }
                      }

                      ?>

                    </div>
                    <div class="elespare-content-wrapper">
                      <?php
                      if ($layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {
                        if ('yes' ==  $settings['show_category']) {
                          // if ($layouts == 'main-banner-style-9' || $layouts == 'main-banner-style-10' || $layouts == 'main-banner-style-11' || $layouts == 'main-banner-style-12' || $layouts == 'main-banner-style-13' || $layouts == 'main-banner-style-14' || $layouts == 'main-banner-style-15' || $layouts == 'main-banner-style-16'   ) {

                          //     echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style']));
                          // }
                          if ($layouts == 'main-banner-style-25' || $layouts == 'main-banner-style-26' || $layouts == 'main-banner-style-27' || $layouts == 'main-banner-style-28' || $layouts == 'main-banner-style-29' || $layouts == 'main-banner-style-30' || $layouts == 'main-banner-style-31' || $layouts == 'main-banner-style-32') {

                            echo wp_kses_post(elespare_posts_grid_get_categories_list($settings['category_grid_layout_style2']));
                          }
                        }
                      }
                      elespare_get_posts_title($settings['show_title'], get_the_title(), get_the_permalink());
                      $this->posts_grid_render_metadata();
                      elespare_posts_tabs_render_excerpt_readmore(get_the_ID(), $settings['show_excerpt'], $settings['excerpt_length'], $settings['show_read_more'], $settings['read_more_text']);

                      ?>

                    </div>
                  </div>

              <?php
                endwhile;
                wp_reset_postdata();
              endif; ?>
            </div>
          </div>


        <?php endif;
        if (!empty($settings['_arrow_prev_icon']['value'])) : ?>
          <button type="button"
            class="slick-prev elespare-nav-carousel"><?php Icons_Manager::render_icon($settings['_arrow_prev_icon'], ['aria-hidden' => 'true']); ?></button>
        <?php endif; ?>

        <?php if (!empty($settings['_arrow_next_icon']['value'])) : ?>
          <button type="button"
            class="slick-next elespare-nav-carousel"><?php Icons_Manager::render_icon($settings['_arrow_next_icon'], ['aria-hidden' => 'true']); ?></button>
        <?php endif;


        ?>
      </div>
      <div class="elespare-section-trending <?php echo esc_attr($bg_class); ?> ">
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



          $carousel_posts = elespare_get_all_posts_in_banner($settings, $sections = 'trending');
          if ($carousel_posts->have_posts()) : ?>
            <div
              class="elespare-trending-wrap elespare-posts-wrap <?php echo esc_attr($settings['item_effects_options']); ?>  <?php echo esc_attr($darkmode); ?>">
              <?php
              while ($carousel_posts->have_posts()) : $carousel_posts->the_post(); ?>
                <div class="elespare-posts-trending-post-items">

                  <div class="elespare-img-wrapper">

                    <?php elespare_posts_grid_render_thumbnail($settings['post_trending_thumbnail_size']);
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
    $comment_icon = (isset($settings['commentIcon']) ? $settings['commentIcon'] : 'far fa-comment');

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

              if ('icon' == $settings['author_icon_options']) {
                Icons_Manager::render_icon($settings['author_icon'], ['aria-hidden' => 'true']);
              }
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
              <?php Icons_Manager::render_icon($settings['date_icon'], ['aria-hidden' => 'true']); ?>
              <?php echo esc_html(apply_filters('the_date', get_the_date(), get_option('date_format'), '', '')); ?>
            </a>
          </span>
        <?php } ?>
        <?php if ('yes' ==  $show_comment_count) { ?>
          <span class="comment_count">
            <?php Icons_Manager::render_icon($comment_icon, ['aria-hidden' => 'true']); ?>
            <?php echo esc_html(get_comments_number()); ?>
          </span>
        <?php } ?>
      </div>
<?php

    }
  }
}
