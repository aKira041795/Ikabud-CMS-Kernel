<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class PostFlash extends Widget_Base
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
    return 'post-flash';
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
    return esc_html__('Ticker Posts', 'elespare');
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
    return 'demo-icon elespare-icons-flash';
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
   * load scripts
   */

  public function get_script_depends()
  {
    return array('jquery-marquee', 'elespare-custom-scripts');
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

    $this->posts_flash_content_Layout_options();

    $this->posts_flash_section_title_options();
    $this->posts_flash_style_title_options();
  }

  private function posts_flash_content_Layout_options()
  {
    $this->start_controls_section(
      'ticker_section_layout',
      [
        'label' => esc_html__('Layout', 'elespare'),
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
      '_exclusive_title',
      [
        'label' => esc_html__('Exclusive Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Breaking News', 'elespare'),
        'placeholder' => esc_html__('Type text here', 'elespare'),
      ]
    );

    $this->add_control(
      'show_flash_subtitle',
      [
        'label' => esc_html__('Show Subtitle', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'no',
        'separator' => 'before',
      ]
    );


    $this->add_control(
      '_exclusive_sub_title',
      [
        'label' => esc_html__('Exclusive Subtitle', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Exclusive', 'elespare'),
        'placeholder' => esc_html__('Type text here', 'elespare'),
        'condition' => [
          'show_flash_subtitle' => 'yes'
        ]
      ]
    );
    $this->add_control(
      '_exclusive_show_spinner',
      [
        'label' => esc_html__('Show Spiner', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'yes',
        'separator' => 'before',
      ]
    );



    $this->add_control(
      'show_number',
      [
        'label' => esc_html__('Show Number', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'no',
        'separator' => 'before',
      ]
    );


    $this->end_controls_section();

    $this->start_controls_section(
      '_section_flash_settings',
      [
        'label' => esc_html__('Settings', 'elespare'),
      ]
    );

    $this->add_control(
      '_flash_direction',
      [
        'label' => esc_html__('Direction', 'elespare-pro'),
        'type' => Controls_Manager::SELECT,
        'default' => 'left',
        'options' => [
          'left' => esc_html__('Left', 'elespare-pro'),
          'right' => esc_html__('Right', 'elespare-pro'),

        ],
      ]
    );



    $this->add_control(
      '_animation_speed',
      [
        'label' => esc_html__('Animation Speed', 'elespare'),
        'type' => Controls_Manager::NUMBER,
        'min' => 10000,
        'step' => 1000,
        'max' => 100000,
        'default' => 80000,
        'description' => esc_html__('Slide speed in milliseconds', 'elespare'),
        'frontend_available' => true,
      ]
    );

    $this->add_control(
      '_pause_on_hover',
      [
        'label' => esc_html__('Pause on hover', 'elespare'),
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
      '_section_query',
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
  }



  /*
   * posts_flash_section_title_options
   */
  private function posts_flash_section_title_options()
  {
    $this->start_controls_section(
      'section_widget_title_style',
      [
        'label' => esc_html__('Posts Title', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
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
              'size' => '14',
            ],
          ],
          'font_weight' => [
            'default' => '500',
          ],

        ],
        'selector' => '{{WRAPPER}} .elespare-flash-wrap .elespare-flash-side a .elespare-post-title',
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
        'default' => elespare_default_color('post-title-normal'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-flash-side a .elespare-post-title'       => 'color: {{VALUE}};',
        ],

      ]
    );
    $this->add_control(
      'posts_title_style_dark_mode_color1',
      [
        'type'      => \Elementor\Controls_Manager::COLOR,
        'label'     => esc_html__('Color', 'elespare'),
        'default' => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap.elespare-dark .elespare-flash-side a .elespare-post-title'       => 'color: {{VALUE}};',
        ],
        'condition' => [
          'dark_mode' => 'yes'
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
        'label'     => esc_html__('Text Color', 'elespare'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => array(
          '{{WRAPPER}} .elespare-flash-wrap .elespare-flash-side a .elespare-post-title:hover'       => 'color: {{VALUE}};',
        ),

      )
    );



    $this->end_controls_tab();
    $this->end_controls_tabs();
    $this->add_control(
      'posts_background_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#efefef',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap' => 'background-color: {{VALUE}};',

        ],
        'condition' => [
          'dark_mode!' => 'yes'
        ]

      ]
    );

    $this->add_control(
      'posts_background_darkmode_color_1',
      [
        'label'      => esc_html__('Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#000',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap.elespare-dark' => 'background-color: {{VALUE}};',


        ],
        'condition' => [
          'dark_mode' => 'yes'
        ]

      ]
    );



    $this->add_control(
      'post_number_color',
      [
        'label'      => esc_html__('Post Number Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => elespare_default_color('post-title-spotlight'),
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-slides .marquee a .elespare-circle-marq .elespare-trending-no' => 'color: {{VALUE}};',
        ],
        'condition' => [
          'show_number' => 'yes'
        ]

      ]
    );
    $this->end_controls_section();
  }

  /*
     * Title style option
     */

  private function posts_flash_style_title_options()
  {

    $this->start_controls_section(
      'section_layout_content_bg_style',
      [
        'label' => esc_html__('Exclusive Text Settings', 'post-flash-elementor-addon'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );


    $this->add_control(
      '_exclusive_text_color',
      [
        'label'      => esc_html__('Exclusive Text Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#222222',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-now > span' => 'color: {{VALUE}};',

        ],
        'condition' => [
          'show_flash_subtitle' => 'yes'
        ]

      ]
    );

    $this->add_control(
      'posts_background_color',
      [
        'label'      => esc_html__('Exclusive Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#FF9900',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-now > span' => 'background-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-now > span:after' => 'border-bottom-color: {{VALUE}};',
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-now > span:before' => 'border-top-color: {{VALUE}};',

        ],
        'condition' => [
          'show_flash_subtitle' => 'yes'
        ]

      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'breaking_title_style_typography',
        // 'global' => [
        //   'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_PRIMARY,
        // ],
        'fields_options' => [
          'typography' => [
            'default' => 'no'
          ],
          'font_weight' => [
            'default' => '700',
          ],
        ],
        'selector' => '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-now .elespare-exclusive-texts-wrapper',
      ]
    );

    $this->add_control(
      '_breaking_news_color',
      [
        'label'      => esc_html__('Breaking News text Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#fff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap .elespare-exclusive-texts-wrapper' => 'color: {{VALUE}};',



        ],

      ]
    );

    $this->add_control(
      'spinner_background_color',
      [
        'label'      => esc_html__('Breaking News Background Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#BB1919',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap' => 'background-color: {{VALUE}};',
          'body:not(.rtl) {{WRAPPER}} .elespare-flash-wrap.flash-style-2 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:after' => 'border-left-color: {{VALUE}};',
          'body:not(.rtl) {{WRAPPER}} .elespare-flash-wrap.flash-style-3 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:after' => 'background-color: {{VALUE}};',
          'body:not(.rtl) {{WRAPPER}} .elespare-flash-wrap.flash-style-4 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:after' => 'background-color: {{VALUE}};',
          'body.rtl {{WRAPPER}} .elespare-flash-wrap.flash-style-2 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:before' => 'border-right-color: {{VALUE}};',
          'body.rtl {{WRAPPER}} .elespare-flash-wrap.flash-style-3 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:before' => 'border-right-color: {{VALUE}};',
          'body.rtl {{WRAPPER}} .elespare-flash-wrap.flash-style-4 .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap:before' => 'border-bottom-color: {{VALUE}};',

        ],

      ]
    );

    $this->add_control(
      '_font_awesome_spinner_background_color',
      [
        'label'      => esc_html__('Icon Color', 'elespare'),
        'type'       => \Elementor\Controls_Manager::COLOR,
        'default'    => '#fff',
        'global' => [
          'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap .elespare-spinner' => 'color: {{VALUE}};',
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-now .elespare-exclusive-now-txt-animation-wrap .elespare-spinner svg' => 'fill: {{VALUE}};',
        ],
        'condition' => [
          '_exclusive_show_spinner' => 'yes',
          '_ticker_layout_style' => 'font-awesome'
        ],

      ]
    );




    $this->end_controls_section();

    $this->start_controls_section(
      '_section_image',
      [
        'label' => esc_html__('Image', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
      ]
    );
    // Image border radius.
    $this->add_control(
      'posts_image_border_radius',
      [
        'label'      => esc_html__('Border Radius', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px', '%'],
        'default'    => [
          'top' => "0",
          'right' => "0",
          'bottom' => "0",
          'left' => "0",
          'unit' => 'px',
          'isLinked' => true
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-flash-wrap .elespare-exclusive-posts .elespare-exclusive-slides .elespare-circle-marq' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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


    $all_posts = elespare_get_all_posts($settings);





    if ($settings['_flash_direction'] == 'right') {
      $em_ticker_news_mode = 'elespare-flash-side right';
    } else {
      $em_ticker_news_mode = 'elespare-flash-side left';
    }

    if (is_rtl()) {
      $em_ticker_news_mode = 'elespare-flash-side right';
    }
    $layouts = 'flash-style-1';
    $count = 1;
    $dark_mode = '';
    if ('yes' == $settings['dark_mode']) {
      $dark_mode =  'elespare-dark';
    }

    $flash_class = '';
    if ($settings['_exclusive_show_spinner'] == 'yes') {
      $flash_class = 'elespare_flash_enable';
    }

    $border_class = '';
    $enable_border = 'ele-border-disabled';

    $exclusive_title = ($settings['_exclusive_title']) ? $settings['_exclusive_title'] : 'Breaking News';
    $this->add_render_attribute('elespare-flash-wrap', 'class', ['elespare-flash-wrap flash-layout', $layouts, $dark_mode, $flash_class]);
    $this->add_render_attribute('elespare-exclusive-posts', 'class', ['elespare-exclusive-posts', $border_class, $enable_border]);
    $this->add_render_attribute('marquee', 'class', ['marquee', $em_ticker_news_mode]);
    $this->add_render_attribute('marquee', 'data-direction', [$settings['_flash_direction']]);

?>

    <div <?php $this->print_render_attribute_string('elespare-flash-wrap'); ?>>
      <div <?php $this->print_render_attribute_string('elespare-exclusive-posts'); ?>>
        <div class="elespare-exclusive-now">
          <?php if ('yes' == $settings['show_flash_subtitle']) { ?>
            <span class="elespare-exclusive-news-title">
              <?php printf(
                '%1$s',
                elespare_kses_basic($settings['_exclusive_sub_title'])
              ); ?>
            </span>
          <?php } ?>

          <div class="elespare-exclusive-now-txt-animation-wrap">
            <?php
            if ('yes' == $settings['_exclusive_show_spinner']) { ?>
              <span class="elespare-spinner spinner-style-2">
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="ring"></div>
              </span>
            <?php
            }
            ?>
            <span class="elespare-exclusive-texts-wrapper">
              <?php printf(
                '%1$s',
                elespare_kses_basic($exclusive_title)
              ); ?>
            </span>
          </div>
        </div>

        <div class="elespare-exclusive-slides" dir="ltr">
          <?php

          if ($all_posts->have_posts()) : ?>
            <div <?php $this->print_render_attribute_string('marquee'); ?>>
              <?php

              while ($all_posts->have_posts()) : $all_posts->the_post(); ?>
                <h4 class="elespare-post-title">
                  <a href="<?php the_permalink(); ?>">

                    <?php if (has_post_thumbnail() || $settings['show_number'] == 'yes') { ?>

                      <span class="elespare-circle-marq">
                        <?php if ('yes' == $settings['show_number']) { ?>
                          <span class="elespare-trending-no"><?php echo esc_html($count); ?></span>
                        <?php } ?>
                        <?php $this->posts_flash_render_thumbnail() ?>
                      </span>

                    <?php } ?>

                    <span class="elespare-post-title"> <?php the_title(); ?></span>
                  </a>
                </h4>
              <?php
                $count++;
              endwhile;

              wp_reset_postdata();
              ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php
  }

  protected function posts_flash_render_thumbnail()
  {

    $settings = $this->get_settings_for_display();

    if (has_post_thumbnail()) :  ?>

      <?php the_post_thumbnail('thumbnail'); ?>

<?php endif;
  }
}
