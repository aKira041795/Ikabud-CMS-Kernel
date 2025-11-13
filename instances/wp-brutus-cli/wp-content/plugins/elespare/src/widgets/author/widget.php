<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Image_Size;
use Elementor\Utils;

use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class Author extends Widget_Base
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
    return 'post-author';
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
    return esc_html__('Author', 'elespare');
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
    return 'demo-icon elespare-icons-author';
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
    $this->posts_author_content_Layout_options();
    $this->posts_author_widget_title_style_options();
    $this->posts_author_typography_content_style_options();
    $this->posts_author_color_content_style_options();
  }

  private function elespare_get_author_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['layout_posts_style'] = 'author-style-1';
        $data_array['section_title'] = 'title-style-2';
        return  $data_array;
        break;

      default:
        $data_array['layout_posts_style'] = 'author-style-1';
        $data_array['section_title'] = 'title-style-1';

        return  $data_array;
        break;
    }
  }

  private function  elespare_author_layout_default($key)
  {
    $active_theme = get_stylesheet();
    $default = $this->elespare_get_author_layout_default($active_theme);
    return $default[$key];
  }



  private function posts_author_content_Layout_options()
  {
    $this->start_controls_section(
      'author_section_layout',
      [
        'label' => esc_html__('Author Layout', 'elespare'),
      ]
    );
    $this->add_control(
      '_author_widget_title',
      [
        'label' => esc_html__('Section Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Author Profile', 'elespare'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );
    $this->add_control(
      'layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_author_layout_default('layout_posts_style'),
        'options' => [
          'author-style-1' => esc_html__('Default', 'elespare'),
          'author-style-2' => esc_html__('Circled - Left', 'elespare'),
          'author-style-3' => esc_html__('Circled - Right', 'elespare'),

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
        'separator' => 'before'
      ]
    );
    $this->end_controls_section();
    $this->start_controls_section(
      'author_section_info',
      [
        'label' => esc_html__('Author Info', 'elespare'),
      ]
    );


    $this->add_control(
      '_author_name',
      [
        'label' => esc_html__('Author Name', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('AF themes', 'elespare'),
        'placeholder' => esc_html__('Type text here', 'elespare')
      ]
    );
    $this->add_control(
      '_author_url',
      [
        'label' => esc_html__('Author Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://example.com',
        'default' => [
          'url' => '#',
        ]

      ]
    );

    $this->add_control(
      '_description',
      [
        'label' => esc_html__('Description', 'elespare'),
        'type' => Controls_Manager::TEXTAREA,
        'default' => esc_html__('Author info box description goes here', 'elespare'),
        'placeholder' => esc_html__('Type info box description', 'elespare'),
        'rows' => 5,
        'dynamic' => [
          'active' => true,
        ]
      ]
    );

    $this->end_controls_section();

    $this->start_controls_section(
      '_section_media',
      [
        'label' => esc_html__('Image', 'elespare'),

      ]
    );



    $this->add_control(
      'image',
      [
        'label' => esc_html__('Image', 'elespare'),
        'type' => Controls_Manager::MEDIA,
        'default' => [
          'url' => Utils::get_placeholder_image_src(),
        ],
        'dynamic' => [
          'active' => true,
        ]
      ]
    );

    $this->add_group_control(
      Group_Control_Image_Size::get_type(),
      [
        'name' => 'thumbnail',
        'default' => 'thumbnail',
        'separator' => 'none',
        'exclude' => [
          'full',
          'custom',
          'large',
          'shop_catalog',
          'shop_single',
          'shop_thumbnail'
        ],
      ]
    );




    $this->end_controls_section();

    $this->start_controls_section(
      '_section_social',
      [
        'label' => esc_html__('Social Profiles', 'happy-elementor-addons'),

      ]
    );
    $this->add_control(
      '_facebook_url',
      [
        'label' => esc_html__('Facebook Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://facebook.com',
        'default' => [
          'url' => 'https://facebook.com',
        ]

      ]
    );
    $this->add_control(
      '_twitter_url',
      [
        'label' => esc_html__('Twitter Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://twitter.com',
        'default' => [
          'url' => 'https://twitter.com',
        ]

      ]
    );
    $this->add_control(
      '_instagram_url',
      [
        'label' => esc_html__('Instagram Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://instagram.com',
        'default' => [
          'url' => 'https://instagram.com',
        ]

      ]
    );
    $this->add_control(
      '_linkedin_url',
      [
        'label' => esc_html__('Linkedin Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://linkedin.com',
        'default' => [
          'url' => 'https://linkedin.com',
        ]

      ]
    );
    $this->add_control(
      '_youtube_url',
      [
        'label' => esc_html__('Youtube Url', 'elespare'),
        'type' => Controls_Manager::URL,
        'placeholder' => 'https://youtube.com',
        'default' => [
          'url' => 'https://youtube.com',
        ]

      ]
    );
    $this->end_controls_section();
  }
  //posts_author_widget_title_style_options
  protected function posts_author_widget_title_style_options()
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
        'default' => $this->elespare_author_layout_default('section_title'),
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
  protected function posts_author_typography_content_style_options()
  {

    $this->start_controls_section(
      'section_typography_style',
      [
        'label' => esc_html__('Typography Settings', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'author_name_style_typography',
        'label'    => 'Atuhor Typography',
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
              'size' => '18',
            ],
          ],
          'font_weight' => [
            'default' => '700',
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-title >a'
      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'author_description_style_typography',
        'label'    => 'Description Typography',
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
              'size' => '16',
            ],
          ],
          'font_weight' => [
            'default' => '400',
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-desc'
      ]
    );


    $this->end_controls_section();
  }

  protected function posts_author_color_content_style_options()
  {
    $this->start_controls_section(
      'section_color_style',
      [
        'label' => esc_html__('Color Settings', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );



    $this->add_control(
      'author_name_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Author Color', 'elespare'),
        'default'   => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-title >a'       => 'color: {{VALUE}};',
        ],

      ]
    );

    $this->add_control(
      'author_name_dark_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Author Color', 'elespare'),
        'default'   => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-title >a'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]
      ]
    );

    $this->add_control(
      'author_desc_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Description Color', 'elespare'),
        'default'   => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-desc'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode!' => 'yes'
        ]
      ]
    );

    $this->add_control(
      'author_desc_dark_style_color',
      [
        'type' => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Description Color', 'elespare'),
        'default'   => elespare_default_color('post-title-dark'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper .elespare-author-info-wrap .elespare-infobox-desc'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]
      ]
    );



    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('content-bg'),
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper' => 'background-color: {{VALUE}};'

        ],
        'condition' => [
          'dark_mode!' => 'yes'
        ]


      ]
    );
    $this->add_control(
      'posts_background_dark_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type' => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-dark-bg'),
        'selectors' => [
          '{{WRAPPER}} .elespare-author-wrapper' => 'background-color: {{VALUE}};'

        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]


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
          '{{WRAPPER}} .elespare-author-wrapper' => 'border-radius: {{SIZE}}{{UNIT}}',

        ],

      ]
    );

    $this->add_group_control(
      Group_Control_Box_Shadow::get_type(),
      [
        'name'      => 'tile_button_style_normal_box_shadow',
        'selector'  => '{{WRAPPER}} .elespare-author-wrapper',
      ]
    );

    $this->add_control(
      'author_content_style_padding',
      [
        'label'      => esc_html__('Content Padding', 'post-grid-elementor'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'default' => [
          'top' => "25",
          'right' => "25",
          'bottom' => "25",
          'left' => "25",
          'isLinked' => true,
          'unit' => 'px',
        ],
        'selectors'  => [
          '{{WRAPPER}} .elespare-author-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'
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
    // $this->add_inline_editing_attributes( '_author_name', 'basic' );
    $this->add_render_attribute('_author_name', 'class', 'elespare-infobox-title');
    // $this->add_inline_editing_attributes( '_description' );
    $this->add_render_attribute('_description', 'class', 'elespare-infobox-desc');
    $title_layout =  $settings['_section_title_style'];
    $alignment_class = $settings['_section_title_alignment'];
    $this->add_render_attribute('layout-wrap', 'class', ['elespare-widget-title-section', $title_layout, $alignment_class]);
    $this->add_inline_editing_attributes('_author_widget_title', 'basic');

?>
    <div class="elespare-widget-auhtor-wrapper">
      <?php
      if ($settings['_author_widget_title']) :
        printf(
          '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
          $this->get_render_attribute_string('layout-wrap'),
          elespare_kses_basic($settings['_author_widget_title'])
        );
      endif;
      ?>
      <div class="elespare-author-wrapper <?php echo esc_attr($settings['layout_posts_style']); ?>">
        <?php

        if ($settings['image']['url'] || $settings['image']['id']) :
          $settings['hover_animation'] = 'disable-animation';
        ?>
          <div class="elespare-img-wrap">
            <?php echo Group_Control_Image_Size::get_attachment_image_html($settings, 'thumbnail', 'image'); ?>
          </div>
        <?php endif; ?>
        <div class="elespare-author-info-wrap">
          <?php
          if ($settings['_author_name']) :
            printf(
              '<h2 %1$s><a target="_blank" href="%3$s">%2$s</a></h2>',
              $this->get_render_attribute_string('_author_name'),
              elespare_kses_basic($settings['_author_name']),
              esc_url($settings['_author_url']['url'])
            );
          endif;

          if ($settings['_description']) :
            printf(
              '<div %1$s>%2$s</div>',
              $this->get_render_attribute_string('_description'),
              elespare_kses_basic($settings['_description'])
            );
          endif; ?>
          <div class="elespare-social-links">
            <?php if ($settings['_facebook_url']['url']) { ?>
              <a target="_blank" class="es-facebook" href="<?php echo esc_url($settings['_facebook_url']['url']); ?>"><i
                  class="demo-icon elespare-icons-facebook"></i>Facebook</a>
            <?php } ?>
            <?php if ($settings['_twitter_url']['url']) { ?>
              <a target="_blank" class="es-twitter" href="<?php echo esc_url($settings['_twitter_url']['url']); ?>"><i
                  class="demo-icon elespare-icons-twitter"></i>Twitter</a>
            <?php } ?>
            <?php if ($settings['_linkedin_url']['url']) { ?>
              <a target="_blank" class="es-linkedin" href="<?php echo esc_url($settings['_linkedin_url']['url']); ?>"><i
                  class="demo-icon elespare-icons-linkedin"></i>LinkedIn</a>
            <?php } ?>
            <?php if ($settings['_instagram_url']['url']) { ?>
              <a target="_blank" class="es-instagram" href="<?php echo esc_url($settings['_instagram_url']['url']); ?>"><i
                  class="demo-icon elespare-icons-instagram"></i>Instagram</a>
            <?php } ?>
            <?php if ($settings['_youtube_url']['url']) { ?>
              <a target="_blank" class="es-youtube" href="<?php echo esc_url($settings['_youtube_url']['url']); ?>"><i
                  class="demo-icon elespare-icons-youtube"></i>Youtube</a>
            <?php } ?>

          </div>
        </div>
      </div>

    </div>

<?php
  }
}
