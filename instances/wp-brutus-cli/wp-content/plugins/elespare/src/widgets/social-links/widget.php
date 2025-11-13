<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Repeater;
use Elementor\Group_Control_Typography;
use Elementor\Controls_Manager;
use Elementor\Plugin;

// Security Note: Blocks direct access to the plugin PHP files.
defined('ABSPATH') || die();

/**
 * elespare widget class.
 *
 * @since 1.0.0
 */


class SocialLinks extends Widget_Base
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
    return 'post-social-links';
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
    return esc_html__('Social Links', 'elespare');
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
    return 'demo-icon elespare-icons-links';
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

  protected static function elespare_get_social_names()
  {
    return [

      'digg'           => esc_html__('Digg', 'elespare'),
      'dribbble'       => esc_html__('Dribbble', 'elespare'),
      'facebook'       => esc_html__('Facebook', 'elespare'),
      'flickr'         => esc_html__('Flicker', 'elespare'),
      'github'         => esc_html__('Github', 'elespare'),
      'houzz'          => esc_html__('Houzz', 'elespare'),
      'instagram'      => esc_html__('Instagram', 'elespare'),
      'linkedin'       => esc_html__('LinkedIn', 'elespare'),
      'pinterest'      => esc_html__('Pinterest', 'elespare'),
      'reddit'         => esc_html__('Reddit', 'elespare'),
      'tumblr'         => esc_html__('Tumblr', 'elespare'),
      'twitter'        => esc_html__('Twitter', 'elespare'),
      'vimeo'          => esc_html__('Vimeo', 'elespare'),
      'vk'             => esc_html__('VK', 'elespare'),
      'youtube'        => esc_html__('YouTube', 'elespare'),
    ];
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
    $this->posts_social_menu_content_Layout_options();
    $this->elespare_social_items();
    $this->posts_social_section_title_options();
    $this->posts_social_icon_alignments();
  }

  private function elespare_get_social_layout_default($design = '')
  {
    switch ($design) {
      case 'covernews':
        $data_array['social_layout_posts_style'] = 'social-style-1';
        $data_array['_section_title_style'] = 'title-style-2';
        return  $data_array;
        break;

      default:
        $data_array['social_layout_posts_style'] = 'social-style-1';
        $data_array['_section_title_style'] = 'title-style-1';

        return  $data_array;
        break;
    }
  }

  private function  elespare_social_layout_default($key)
  {
    $active_theme = get_stylesheet();
    $default = $this->elespare_get_social_layout_default($active_theme);
    return $default[$key];
  }



  private function posts_social_menu_content_Layout_options()
  {
    $this->start_controls_section(
      'social_section_layout',
      [
        'label' => esc_html__('Social Layout', 'elespare'),
        'tab' => Controls_Manager::TAB_CONTENT,
      ]
    );
    $this->add_control(
      'social_layout_posts_style',
      [
        'label' => esc_html__(' Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => $this->elespare_social_layout_default('social_layout_posts_style'),
        'options' => [
          'social-style-1' => esc_html__('Social Style 1', 'elespare'),
          'social-style-2' => esc_html__('Social Style 2', 'elespare'),
        ],
      ]
    );
    $this->add_control(
      '_social_menu_widget_title',
      [
        'label' => esc_html__('Section Title', 'elespare'),
        'label_block' => true,
        'type' => Controls_Manager::TEXT,
        'default' => esc_html__('Social Links', 'elespare'),
        'placeholder' => esc_html__('Type Section Title', 'elespare'),
      ]
    );



    $this->end_controls_section();
  }

  private function elespare_social_items()
  {
    $this->start_controls_section(
      '_social_items',
      [
        'label' => esc_html__('Social Links', 'elespare'),
        'tab' => Controls_Manager::TAB_CONTENT,
      ]
    );

    $repeater = new Repeater();

    $repeater->add_control(
      'name',
      [
        'label' => esc_html__('Profile Name', 'elespare'),
        'type' => Controls_Manager::SELECT2,
        'label_block' => true,
        'select2options' => [
          'allowClear' => false,
        ],
        'options' => self::elespare_get_social_names()
      ]
    );
    $repeater->add_control(
      'link',
      [
        'label' => esc_html__('Profile Link', 'elespare'),
        'placeholder' => esc_html__('Add your profile link', 'elespare'),
        'type' => Controls_Manager::URL,
        'label_block' => true,
        'autocomplete' => false,
        'show_external' => false,
        'dynamic' => [
          'active' => true,
        ]
      ]
    );

    $this->add_control(
      'profiles',
      [
        'show_label' => false,
        'type' => Controls_Manager::REPEATER,
        'fields' => $repeater->get_controls(),
        'title_field' => '<# print(name.slice(0,1).toUpperCase() + name.slice(1)) #>',
        'default' => [
          [
            'link' => ['url' => 'https://facebook.com/'],
            'name' => 'facebook'
          ],
          [
            'link' => ['url' => 'https://twitter.com/'],
            'name' => 'twitter'
          ],
          [
            'link' => ['url' => 'https://linkedin.com/'],
            'name' => 'linkedin'
          ],
          [
            'link' => ['url' => 'https://instagram.com/'],
            'name' => 'instagram'
          ]
        ],
      ]
    );

    $this->end_controls_section();
  }

  private function posts_social_section_title_options()
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
        'default' => $this->elespare_social_layout_default('_section_title_style'),
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

  private function posts_social_icon_alignments()
  {
    $this->start_controls_section(
      'social_icon_style',
      [
        'label' => esc_html__('Social Icon', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_responsive_control(
      '_social_icon_alignment',
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
        'prefix_class' => 'elespare-grid%s-',
        'toggle' => true,
      ]
    );


    $this->add_control(
      'social_icon_size_style',
      [
        'label' => esc_html__('Size', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'elespare-medium',
        'options' => [
          'elespare-small' => esc_html__('Small', 'elespare'),
          'elespare-medium' => esc_html__('Medium', 'elespare'),
          'elespare-large' => esc_html__('Large', 'elespare'),
        ],
      ]
    );

    $this->add_responsive_control(
      'social_icon_style_margin',
      [
        'label'      => esc_html__('Margin', 'post-tile-elementor'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => ['px'],
        'default' => [
          'top' => "0",
          'right' => "5",
          'bottom' => "0",
          'left' => "0",
          'isLinked' => false,
          'unit' => 'px',
        ],
        'selectors'  => [
          '{{WRAPPER}} .elespare-widget-social-link-wrapper .elespare-social-link-wrapper .elespare-social-links > a' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
    $social_alignment = $settings['_social_icon_alignment'];
    $this->add_inline_editing_attributes('_social_menu_widget_title', 'basic');

    $this->add_render_attribute('layout-wrap', 'class', ['elespare-widget-title-section', $title_layout, $alignment_class]);



?>
    <div class="elespare-widget-social-link-wrapper">
      <?php
      if ($settings['_social_menu_widget_title']) :
        printf(
          '<div %1$s><h4 class="elespare-widget-title"><span class="elespare-section-title-before"></span><span class="elespare-section-title">%2$s </span><span class="elespare-section-title-after"></span></h4></div>',
          $this->get_render_attribute_string('layout-wrap'),
          elespare_kses_basic($settings['_social_menu_widget_title'])
        );
      endif;
      ?>
      <div
        class="elespare-social-link-wrapper <?php echo esc_attr($settings['social_layout_posts_style']) . ' ' . esc_attr($social_alignment) . ' ' . esc_attr($settings['social_icon_size_style']); ?>">
        <?php if (is_array($settings['profiles'])) : ?>
          <div class="elespare-social-links">
            <?php
            foreach ($settings['profiles'] as $profile) :

              $icon = $profile['name'];
              $url = $profile['link']['url'];

              if ($icon == 'vk') {
                $icon = 'vkontakte';
              }
              if ($icon == 'reddit') {
                $icon = 'reddit-alien';
              }

              printf(
                '<a target="_blank" rel="noopener" href="%1$s" class="elementor-repeater-item-%2$s %4$s"><i class="demo-icon elespare-icons-%3$s" aria-hidden="true"></i><span class="elespare-social-item-name">%3$s</span></a>',
                esc_url($url),
                esc_attr($profile['_id']),
                esc_attr($icon),
                'es-' . esc_html($icon)

              );
            endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

<?php
  }
}
