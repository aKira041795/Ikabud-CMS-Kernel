<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Plugin;

if (! defined('ABSPATH')) {
  exit();
}
class CustomLinkButton extends Widget_Base
{

  /**
   * Retrieve the widget name.
   *
   * @return string Widget name.
   */
  public function get_name()
  {
    return 'custom-link-button';
  }

  /**
   * Retrieve the widget title.
   *
   * @return string Widget title.
   */
  public function get_title()
  {
    return esc_html__('Custom Link Button', 'elespare');
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
    return 'demo-icon elespare-icons-link-button';
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
    $this->elespare_custom_button_content_options();
    $this->elespare_custom_button_style_options();
  }

  //Content tab

  protected function elespare_custom_button_content_options()
  {

    $this->start_controls_section(
      'button_content',
      [
        'label' => __('Content', 'elespare'),
        'tab' => Controls_Manager::TAB_CONTENT,
      ]
    );
    $this->add_control(
      'custom_button_layout_style',
      [
        'label' => esc_html__('Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'elespare-button-style-1',
        'options' => [
          'elespare-button-style-1' => esc_html__('Layout 1', 'elespare'),
          'elespare-button-style-2' => esc_html__('Layout 2', 'elespare'),
          // 'elespare-button-style-3' => esc_html__( 'Layout 3', 'elespare' ),
          // 'elespare-button-style-4' => esc_html__( 'Layout 4', 'elespare' ),
          // 'elespare-button-style-5' => esc_html__( 'Layout 5', 'elespare' ),
          // 'elespare-button-style-6' => esc_html__( 'Layout 6', 'elespare' ),
          // 'elespare-button-style-7' => esc_html__( 'Layout 7', 'elespare' ),
          // 'elespare-button-style-8' => esc_html__( 'Layout 8', 'elespare' ),
          // 'elespare-button-style-9' => esc_html__( 'Layout 9', 'elespare' ),
          // 'elespare-button-style-10' => esc_html__( 'Layout 10', 'elespare' ),
          // 'elespare-button-style-11' => esc_html__( 'Layout 11', 'elespare' ),
          // 'elespare-button-style-12' => esc_html__( 'Layout 12', 'elespare' ),
        ],

      ]
    );

    $this->add_control(
      'button_text',
      [
        'label'       => __('Button Text', 'elespare'),
        'type'        => Controls_Manager::TEXT,
        'dynamic' => [
          'active' => true,
        ],
        'label_block' => true,
        'default'     => 'Click Here',
        'placeholder' => __('Enter button text', 'elespare'),
        'title'       => __('Enter button text here', 'elespare'),
      ]
    );

    $this->add_control(
      'button_link',
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

    $this->add_control(
      'button_icon',
      array(
        'label'     => esc_html__('Button Icon', 'elespare'),
        'type' => Controls_Manager::ICONS,
        'skin' => 'inline',
        'separator' => 'before',
        'label_block' => false,
        'default' => [
          'value' => 'demo-icon elespare-icons-right',
          'library' => 'reguler'
        ],


      )
    );

    $this->add_control(
      'icon_position',
      [
        'label' => esc_html__('Icon Position', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'left',
        'condition' => [
          'button_icon[value]!' => '',
        ],
        'options' => [
          'left' => esc_html__('Left', 'elespare'),
          'right' => esc_html__('Right', 'elespare'),

        ],
      ]
    );

    $this->add_control(
      'icon_spacing',
      [
        'label' => esc_html__('Icon Spacing', 'elementor'),
        'type' => Controls_Manager::HIDDEN,
        'range' => [
          'px' => [
            'max' => 40,
          ],
        ],

        'condition' => [
          'button_icon[value]!' => '',
        ],
        'selectors' => [
          '{{WRAPPER}}  .elespare-icon-position-right .elespare-custom-link-icon' => 'margin-left: {{SIZE}}{{UNIT}};',
          '{{WRAPPER}}   .elespare-icon-position-left .elespare-custom-link-icon' => 'margin-right: {{SIZE}}{{UNIT}};',

        ],
      ]
    );

    $this->end_controls_section();
  }

  //Style Tab

  protected function elespare_custom_button_style_options()
  {

    $this->start_controls_section(
      'content_section',
      [
        'label' => __('Button', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
      ]
    );

    $this->add_responsive_control(
      'button_align',
      [
        'label'        => esc_html__('Button Alignment', 'elespare'),
        'type'         => Controls_Manager::CHOOSE,
        'options'      => [
          'left'    => [
            'title' => esc_html__('Left', 'elespare'),
            'icon'  => 'fa fa-align-left',
          ],
          'center'  => [
            'title' => esc_html__('Center', 'elespare'),
            'icon'  => 'fa fa-align-center',
          ],
          'right'   => [
            'title' => esc_html__('Right', 'elespare'),
            'icon'  => 'fa fa-align-right',
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-custom-link-button' => 'text-align: {{VALUE}};',
        ],
        'default' => 'left',
        'prefix_class' => 'ele%s-button-align-',
        'toggle' => true,
      ]
    );

    $this->start_controls_tabs(
      'elespare_style_tabs'
    );

    $this->start_controls_tab(
      'elespare_style_normal_tab',
      [
        'label' => __('Normal', 'elespare'),
      ]
    );


    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name'     => 'text_typography',
        'label'    => esc_html__('Typography', 'elespare'),
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
        'selector' => '{{WRAPPER}} .elespare-custom-link-button',
      ]
    );

    $this->add_control(
      'button_text_color',
      [
        'label' => esc_html__('Text Color', 'elespare'),
        'type' => Controls_Manager::COLOR,
        'default' => '#2c2c2c',
        'selectors' => [
          '{{WRAPPER}} .elespare-custom-link-text' => 'color: {{VALUE}};'
        ],
      ]
    );

    $this->add_control(
      'button_background',
      [
        'label' => esc_html__('Button Background', 'elespare'),
        'type' => Controls_Manager::COLOR,
        'default' => '#ffb400',
        'selectors' => [
          '{{WRAPPER}} .elespare-custom-link-button .elespare-button-link' => 'background: {{VALUE}};',
        ],
        'condition' => [
          'custom_button_layout_style!' => 'elespare-button-style-1',
        ],
      ]
    );

    // $this->add_group_control(
    // 	Group_Control_Background::get_type(),
    // 	[
    // 		'name' => 'background',
    // 		'label' => __( 'Button Background', 'elespare' ),
    // 		'types' => [ 'classic'],
    // 		'exclude' => [ 'image' ],
    // 		'selector' => '{{WRAPPER}} .elespare-custom-link-button .elespare-button-link',
    // 		'fields_options' => [
    // 			'background' => [
    // 				'default' => 'classic',
    // 			],
    // 			'color' => [
    // 				'label' => 'Text Background',
    // 				'default' => '#ffb400',

    // 			],
    // 		],
    // 		'condition' => [
    // 			'custom_button_layout_style!' => 'elespare-button-style-1',
    // 		],
    // 	]
    // );



    $this->end_controls_tab();

    $this->start_controls_tab(
      'elespare_style_hover_tab',
      [
        'label' => __('Hover', 'elespare'),
        'condition' => [
          'custom_button_layout_style!' => 'elespare-button-style-1',
        ],
      ]

    );

    // $this->add_control(
    // 	'custom_button_hover_style',
    // 	[
    // 		'label' => esc_html__( 'Button Hover Color', 'elespare' ),
    // 		'type' => Controls_Manager::SELECT,
    // 		'default' => 'elespare-button-hover-hover-none',
    // 		'options' => [
    // 			'elespare-button-hover-hover-none' => esc_html__( 'None', 'elespare' ),
    // 			'elespare-button-hover-hover-1' => esc_html__( 'Hover 1', 'elespare' ),
    // 			'elespare-button-hover-hover-2' => esc_html__( 'Hover 2', 'elespare' ),
    // 			'elespare-button-hover-hover-3' => esc_html__( 'Hover 3', 'elespare' ),
    // 			'elespare-button-hover-hover-4' => esc_html__( 'Hover 4', 'elespare' ),
    // 			'elespare-button-hover-hover-5' => esc_html__( 'Hover 5', 'elespare' ),
    // 			'elespare-button-hover-hover-6' => esc_html__( 'Hover 5', 'elespare' ),
    // 			'elespare-button-hover-hover-7' => esc_html__( 'Hover 5', 'elespare' ),

    // 		],
    // 	]
    // );


    $this->add_control(
      'elespare_hover_animation',
      [
        'label' => __('Hover Animation', 'elespare'),
        'type' => Controls_Manager::HOVER_ANIMATION,
      ]
    );

    $this->add_group_control(
      Group_Control_Background::get_type(),
      [
        'name' => 'hover_button_background_color',
        'label' => __('Hover Background', 'elespare'),
        'types' => ['classic', 'gradient'],
        'exclude' => ['image'],
        'selector' =>
        '{{WRAPPER}} .elespare-custom-link-button .elespare-button-hover-color:hover',

        'fields_options' => [
          'background' => [
            'default' => 'classic',
          ],

        ],
      ]
    );



    $this->end_controls_tab();




    $this->end_controls_tabs();

    $this->add_control(
      'button_icon_color',
      [
        'label' => esc_html__('Icon Color', 'elespare'),
        'type' => Controls_Manager::COLOR,
        'default' => '#2c2c2c',
        'separator' => 'before',
        'condition' => [
          'button_icon[value]!' => '',
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-custom-link-icon' => 'color: {{VALUE}};',
        ],
      ]
    );
    // $this->add_group_control(
    // 	Group_Control_Border::get_type(),
    // 	[
    // 		'name' => 'border',
    // 		'label' => __( 'Border', 'elespare' ),
    // 		'separator' => 'before',
    // 		'selector' => '{{WRAPPER}} .elespare-custom-link-button .elespare-button-link',
    // 	]
    // );

    // $this->add_control(
    // 	'border_radius',
    // 	[
    // 		'label' => __( 'Border Radius', 'elespare' ),
    // 		'type' => Controls_Manager::SLIDER,
    // 		'size_units' => ['px'],
    // 		'range' => [
    // 			'px' => [
    // 				'min' => 0,
    // 				'max' => 100,
    // 			],
    // 		],
    // 		'default'   => [
    // 			'size' => 0,
    // 		],
    // 		'selectors' => [
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-1 .elespare-button-link' => 'border-radius: {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}};',
    // 			// '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-1 .elespare-button-link .elespare-custom-link-button-color' => 'border-radius: 2px {{SIZE}}{{UNIT}};',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-2 .elespare-button-link' => 'border-radius: 25px {{SIZE}}{{UNIT}};',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-2 .elespare-button-link .elespare-custom-link-button-color' => 'border-radius: 25px {{SIZE}}{{UNIT}};',
    // 			//'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-3 .elespare-button-link' => 'border-radius: 25px 25px {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}};',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-3 .elespare-button-link' => 'border-radius: {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}};',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-4 .elespare-button-link' => 'border-radius: {{SIZE}}{{UNIT}} 25px;',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-4 .elespare-button-link .elespare-custom-link-button-color' => 'border-radius: {{SIZE}}{{UNIT}} 25px;',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-5 .elespare-button-link' => 'border-radius: 25px {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} 25px;',
    // 			'{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-5 .elespare-button-link .elespare-custom-link-button-color' => 'border-radius: {{SIZE}}{{UNIT}} {{SIZE}}{{UNIT}} 25px 25px;',
    // 		],
    // 	]
    // );

    $this->add_control(
      'border_radius',
      [
        'label' => esc_html__('Border Radius', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'mixed',
        'separator' => 'before',
        'options' => [
          'mixed' => esc_html__('Default', 'elespare'),
          'rounded' => esc_html__('Rounded', 'elespare'),
          'circular' => esc_html__('Circular', 'elespare'),
          'rectangular' => esc_html__('Rectangular', 'elespare')
        ],
        'condition' => [
          'custom_button_layout_style!' => 'elespare-button-style-1',
        ]
      ]
    );

    // $this->add_group_control(
    // 	Group_Control_Box_Shadow::get_type(),
    // 	[
    // 		'name' => 'box_shadow',
    // 		'label' => __( 'Box Shadow', 'elespare' ),
    // 		'selector' => '{{WRAPPER}} .elespare-custom-link-button .elespare-button-link',
    // 	]
    // );

    $this->add_responsive_control(
      'text_padding',
      [
        'label' => esc_html__('Padding', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'size_units' => ['px', 'em', '%'],
        'selectors' => [
          '{{WRAPPER}} .elespare-custom-link-button:not(.elespare-button-style-1, .elespare-button-style-2, .elespare-button-style-3, .elespare-button-style-11, .elespare-button-style-12) .elespare-custom-link-text' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-1 .elespare-button-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-2 .elespare-button-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-3 .elespare-button-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-11 .elespare-button-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-custom-link-button.elespare-button-style-12 .elespare-button-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],

      ]
    );

    $this->end_controls_section();
  }

  protected function render()
  {

    $settings = $this->get_settings_for_display();


    // $this->add_render_attribute( 'wrapper', 'class', ['elespare-button-wrapper', ] );
    $this->add_render_attribute('button', 'class', ['elespare-button-link', 'elespare-button-hover-color']);
    // $this->add_render_attribute( 'button', 'class', 'elespare-custom-link-button' );

    if (! empty($settings['button_link']['url'])) {
      $this->add_link_attributes('button', $settings['button_link']);
    }

    if ($settings['elespare_hover_animation']) {
      $this->add_render_attribute('button', 'class', 'elementor-animation-' . $settings['elespare_hover_animation']);
    }

?>
    <div class='elespare-custom-link-button <?php echo esc_attr($settings['custom_button_layout_style']) ?> <?php echo esc_attr($settings['border_radius']) ?> <?php echo esc_attr($settings['custom_button_hover_style']) ?>  <?php echo esc_attr('elespare-icon-position-' . $settings['icon_position']) ?> '>
      <a <?php $this->print_render_attribute_string('button'); ?> class=>
        <?php $this->render_text(); ?>
      </a>
    </div>
  <?php
  }

  protected function render_text()
  {
    $settings = $this->get_settings_for_display();

    $icon = $settings['button_icon'];

    $this->add_render_attribute([
      'icon-position' => [
        'class' => [
          'elespare-custom-link-icon',
        ]
      ],
      'text' => [
        'class' => [
          'elespare-custom-link-text',

        ],
      ],
    ]);
  ?>
    <?php
    if (! empty($icon['value'])):
    ?>
      <div <?php $this->print_render_attribute_string('icon-position'); ?>>
        <i class="<?php echo esc_attr($icon['value']); ?>" aria-hidden="true"></i>
      </div>
    <?php
    endif;
    ?>

    <div <?php $this->print_render_attribute_string('text'); ?>>
      <?php
      $this->print_unescaped_setting('button_text'); ?></div>

<?php

  }
}
