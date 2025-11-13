<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;

if (! defined('ABSPATH')) {
  exit();
}

class Copyright extends Widget_Base
{

  public function get_name()
  {
    return 'copyright-txt';
  }

  public function get_title()
  {
    return esc_html__('Copyright Text', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  public function get_icon()
  {
    return 'demo-icon elespare-icons-copyright';
  }

  public function get_categories()
  {
    return ['elespare'];
  }

  protected function register_controls()
  {
    $this->elespare_copyright_text_content_options();
    $this->elespare_copyright_text_style_options();
  }

  protected function elespare_copyright_text_content_options()
  {
    $this->start_controls_section(
      'copyright_section',
      [
        'label' => esc_html__('General', 'elespare'),
      ]
    );

    $this->add_control(
      'copyright_content',
      [
        'label'   => __('Copyright Text', 'elespare'),
        'type'    => Controls_Manager::TEXTAREA,
        'dynamic' => [
          'active' => true,
        ],
        'default' => __('Copyright © [es_current_year] [es_site_title] | Powered by [es_site_title]', 'elespare'),
      ]
    );

    $this->add_control(
      'copyright_link',
      [
        'label'     => __('Link', 'elespare'),
        'type'      => Controls_Manager::URL,
        'dynamic' => [
          'active' => true,
        ],
        'placeholder' => esc_html__('https://your-link.com', 'elespare'),
        'default' => [
          'url' => '#',
          'is_external' => true,
          'nofollow' => true,
        ]
      ]
    );

    $this->end_controls_section();
  }


  protected function elespare_copyright_text_style_options()
  {
    $this->start_controls_section(
      'copyright_text_style',
      [
        'label' => esc_html__('Copyright Text', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_responsive_control(
      '_cr_text_alignment',
      [
        'label' => esc_html__('Alignment', 'elespare'),
        'type' => Controls_Manager::CHOOSE,
        'label_block' => false,
        'default' => 'elespare-left',
        'options' => [
          'left' => [
            'title' => esc_html__('Left', 'elespare'),
            'icon' => 'eicon-h-align-left',
          ],
          'center' => [
            'title' => esc_html__('Center', 'elespare'),
            'icon' => 'eicon-h-align-center',
          ],
          'right' => [
            'title' => esc_html__('Right', 'elespare'),
            'icon' => 'eicon-h-align-right',
          ],

        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-copyright-wrapper' => 'text-align: {{VALUE}};',
        ],

      ]
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => '_section_widget_title_color_typography',
        [
          'name' => 'after_date_typography',
          'label' => esc_html__('After Date Text Typography', 'elespare'),
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
              'default' => '300',
            ],

          ],
          'selector' =>
          [
            '{{WRAPPER}} .elespare-copyright-wrapper span.elespare-copyright-text',
          ]
        ]

      ]
    );


    $this->add_control(
      'copyright_text_color',
      [
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => [
          '{{WRAPPER}} .elespare-copyright-wrapper span.elespare-copyright-text .elespare-copyright-link-color' => 'color: {{VALUE}};',

        ],

      ]
    );



    $this->end_controls_section();
  }


  protected function render()
  {
    $settings = $this->get_settings_for_display();
    $link     = isset($settings['copyright_link']['url']) ? $settings['copyright_link']['url'] : '';

    if (! empty($link)) {
      $this->add_link_attributes('_copyright_link', $settings['copyright_link']);
    }
    $this->add_render_attribute('_copyright_link', 'class', 'elespare-copyright-link-color');


    $es_copyright_shortcode = do_shortcode(shortcode_unautop($settings['copyright_content'])); ?>
    <div class="elespare-copyright-wrapper">
      <span class='elespare-copyright-text'>

        <a <?php echo wp_kses_post($this->get_render_attribute_string('_copyright_link')); ?>>
          <span><?php echo wp_kses_post($es_copyright_shortcode); ?></span>
        </a>

      </span>
    </div>
<?php
  }
}
