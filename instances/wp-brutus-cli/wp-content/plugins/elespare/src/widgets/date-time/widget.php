<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;



if (!defined('ABSPATH')) exit; // Exit if accessed directly


class DateTime extends Widget_Base
{

  public function get_name()
  {
    return 'date-time';
  }

  public function get_title()
  {
    return esc_html__('Date and Time', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  public function get_icon()
  {
    return 'demo-icon elespare-icons-clock-regular';
  }

  public function get_categories()
  {
    return ['elespare'];
  }
  protected function register_controls()
  {
    $this->elespare_date_time_content_Layout_options();
    $this->elespare_date_time_style_options();
  }
  protected function elespare_date_time_content_Layout_options()
  {
    $this->start_controls_section(
      'section_content',
      [
        'label' => esc_html__('General ', 'elespare')
      ]
    );

    $this->add_control(
      'display_date',
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
      'before_date_text',
      [
        'label' => esc_html__('Before Date Text', 'elesapre'),
        'type' => Controls_Manager::HIDDEN,
        //  'default' => esc_html__('Current Date','elespare'),
        'condition' => [
          'display_date' => 'yes',
        ],
      ]
    );
    $this->add_control(
      'after_date_text',
      [
        'label' => esc_html__('After Date text', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        // 'default'=> 'A.D.' ,
        'condition' => [
          'display_date' => 'yes',
        ],
      ]
    );

    $this->add_control(
      'date_icon',
      array(
        'label'     => esc_html__('Date Icon', 'elespare'),
        'type' => Controls_Manager::ICONS,
        'skin' => 'inline',
        'label_block' => false,
        'condition' => [
          'display_date' => 'yes',
        ],
        'default' => [
          'value' => 'demo-icon elespare-icons-calendar',
          'library' => 'reguler'
        ],


      )
    );

    //Time control        
    $this->add_control(
      'display_time',
      [
        'label' => esc_html__('Display Time ', 'elespare'),
        'type' => Controls_Manager::SWITCHER,
        'label_on' => esc_html__('Show', 'elespare'),
        'label_off' => esc_html__('Hide', 'elespare'),
        'default' => 'no',
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'time_icon',
      array(
        'label'     => esc_html__('Time Icon', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'skin' => 'inline',
        'label_block' => false,
        'condition' => [
          'display_time' => 'yes',
        ],
        'default' => [
          'value' => 'demo-icon elespare-icons-clock-regular',
          'library' => 'reguler'
        ],


      )
    );

    $this->add_control(
      'date_time_layout_style',
      [
        'label' => esc_html__('Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'date-time-style-1',
        'separator' => 'before',
        'options' => [
          'date-time-style-1' => esc_html__('Layout 1', 'elespare'),
          'date-time-style-2' => esc_html__('Layout 2', 'elespare'),
          // 'date-time-style-3' => esc_html__( 'Layout 3', 'elespare' ),
          // 'date-time-style-4' => esc_html__( 'Layout 4', 'elespare' ),
          // 'date-time-style-5' => esc_html__( 'Layout 5', 'elespare' ),
          // 'date-time-style-6' => esc_html__( 'Layout 6', 'elespare' ),
          // 'date-time-style-7' => esc_html__( 'Layout 7', 'elespare' ),
          // 'date-time-style-8' => esc_html__( 'Layout 8', 'elespare' ),
          // 'date-time-style-9' => esc_html__( 'Layout 9', 'elespare' ),
          // 'date-time-style-10' => esc_html__( 'Layout 10', 'elespare' ),
          // 'date-time-style-11' => esc_html__( 'Layout 11', 'elespare' ),
          // 'date-time-style-12' => esc_html__( 'Layout 12', 'elespare' ),
        ],
      ]
    );

    $this->add_control(
      'date_time_bordr_radius',
      [
        'label' => esc_html__('Border Radius', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'date-time-mixed',
        'separator' => 'before',
        'options' => [
          'date-time-mixed' => esc_html__('Default', 'elespare'),
          'date-time-rounded' => esc_html__('Rounded', 'elespare'),
          'date-time-circular' => esc_html__('Circular', 'elespare'),
          'date-time-rectangular' => esc_html__('Rectangular', 'elespare')
        ],
        'condition' => [
          'date_time_layout_style!' => 'date-time-style-1',
        ]
      ]
    );

    $this->add_control(
      'date_time_order',
      [
        'label' => esc_html__('Order', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'date-time-order-1',
        'options' => [
          'date-time-order-1' => esc_html__('Date-Time', 'elespare'),
          'date-time-order-2' => esc_html__('Time-Date', 'elespare'),

        ],
        'condition' => [
          'display_date' => 'yes',
          'display_time' => 'yes',
        ]
      ]
    );

    $this->add_control(
      'date_time_horizontal_vertical_style',
      [
        'label' => esc_html__('Layout Style', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'date-time-horizontal',
        'options' => [
          'date-time-horizontal' => esc_html__('Horizontal', 'elespare'),
          'date-time-vertical' => esc_html__('Vertical', 'elespare'),

        ],
        'condition' => [
          'display_date' => 'yes',
          'display_time' => 'yes',
        ]
      ]
    );
    $this->add_responsive_control(
      '_date_time_title_alignment',
      [
        'label' => esc_html__('Alignment', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'label_block' => false,
        'default' => 'elespare-left',
        'options' => [
          'elespare-left' => [
            'title' => esc_html__('Left', 'elespare'),
            'icon' => 'eicon-text-align-left',
          ],
          'elespare-center' => [
            'title' => esc_html__('Center', 'elespare'),
            'icon' => 'eicon-text-align-center',
          ],
          'elespare-right' => [
            'title' => esc_html__('Right', 'elespare'),
            'icon' => 'eicon-text-align-right',
          ],
        ],
        'prefix_class' => 'elespare-section-title%s-',
        'toggle' => true,
      ]
    );

    $this->end_controls_section();
  }
  protected function elespare_date_time_style_options()
  {

    $this->start_controls_section(
      'primary_secondary_color_section',
      [
        'label' => esc_html__('Secondary Color Setting', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
        'condition' => [
          'date_time_layout_style!' => 'date-time-style-1',
        ]

      ]
    );


    $this->add_control(
      'secondary_color',
      array(
        'label'     => esc_html__('Secondary Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'     => '#ffb400',
        'selectors' => array(
          '{{WRAPPER}} .elespare-date-time-widget .elespare-date-time' => 'background-color: {{VALUE}}',
        ),
      )
    );



    $this->end_controls_section();
    $this->start_controls_section(
      'style_section',
      [
        'label' => esc_html__('Date Settings', 'elespare'),
        'tab' => Controls_Manager::TAB_STYLE,
        'condition' => [
          'display_date' => 'yes',
        ]
      ]
    );
    //Date style
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name' => 'date_typography',
        'label' => esc_html__('Date Typography', 'elespare'),
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
        'selector' => '{{WRAPPER}} .elespare-date-wrapper span.elespare-date-text',
      ]
    );

    $this->add_control(
      'date_color',
      [
        'label'     => esc_html__('Date Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => [
          '{{WRAPPER}} .elespare-date-wrapper span.elespare-date-text' => 'color: {{VALUE}};',
        ],

      ]
    );

    $this->add_control(
      'date_icon_color',
      array(
        'label'     => esc_html__('Icon Color', 'elespare'),
        'type'      => Controls_Manager::HIDDEN,
        'default'  => '#000',
        'selectors' => array(
          '{{WRAPPER}} .elespare-date-icon' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_responsive_control(
      'date_icon_size',
      [
        'label' => esc_html__('Date Icon Size', 'elementor'),
        'type' => Controls_Manager::HIDDEN,
        'range' => [
          'px' => [
            'min' => 10,
            'max' => 100,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-date-icon' => 'font-size: {{SIZE}}{{UNIT}};',
        ],
        'separator' => 'before',
      ]
    );

    //Before Text Style
    // $this->add_group_control(
    //   Group_Control_Typography::get_type(),
    //   [
    //     'name' => 'before_date_typography',
    //     'label' => esc_html__( 'Before Date Text Typography', 'elespare' ),
    //     'fields_options' => [
    //       'typography' => [
    //           'default' => 'yes'

    //       ],
    //       'font_size' => [
    //           'default' => [
    //               'unit' => 'px',
    //               'size' => '16',
    //           ],
    //       ],
    //       'font_weight' => [
    //           'default' => '400',
    //       ],

    //   ],
    //     'selector' => '{{WRAPPER}} span.elespare-before-date-text',
    //     'condition' => [
    //       'before_date_text[value]!' => '',
    //      ]
    // ]
    // );

    // $this->add_control('before_date_color',
    // [
    // 	'label'     => esc_html__( 'Before Date Text Color', 'elespare' ),
    // 	'type'      => Controls_Manager::COLOR,
    // 	'default'	=>'#000',
    //   'selectors' => [
    // 		'{{WRAPPER}} span.elespare-before-date-text' => 'color: {{VALUE}};',
    // 		],
    //     'condition' => [
    //       'before_date_text[value]!' => '',
    //      ]

    // ]);
    //After Text Style
    // $this->add_group_control(
    //   Group_Control_Typography::get_type(),
    //   [
    //     'name' => 'after_date_typography',
    //     'label' => esc_html__( 'After Date Text Typography', 'elespare' ),
    //     'fields_options' => [
    //       'typography' => [
    //           'default' => 'yes'

    //       ],
    //       'font_size' => [
    //           'default' => [
    //               'unit' => 'px',
    //               'size' => '16',
    //           ],
    //       ],
    //       'font_weight' => [
    //           'default' => '400',
    //       ],

    //   ],
    //     'selector' => '{{WRAPPER}} span.elespare-after-date-text',
    //     'condition' => [
    //       'after_date_text[value]!' => '',
    //      ]
    // ]
    // );

    // $this->add_control('after_date_color',
    // [
    // 	'label'     => esc_html__( 'After Date Text Color', 'elespare' ),
    // 	'type'      => Controls_Manager::COLOR,
    // 	'default'	=>'#000',
    //   'selectors' => [
    // 		'{{WRAPPER}} span.elespare-after-date-text' => 'color: {{VALUE}};',
    // 		],
    //     'condition' => [
    //       'after_date_text[value]!' => '',
    //      ]

    // ]);



    $this->end_controls_section();

    //Time Style
    $this->start_controls_section(
      'section_time',
      [
        'label'     => esc_html__('Time Settings', 'elespare'),
        'tab'       => Controls_Manager::TAB_STYLE,
        'condition' => [
          'display_time' => 'yes',
        ]
      ]
    );
    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name' => 'time_typography',
        'label' => esc_html__('Typography', 'elespare'),
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
        'selector' => '{{WRAPPER}} .elespare-time-wrapper span.elespare-time-text',
      ]
    );

    $this->add_control(
      'time_color',
      [
        'label'     => esc_html__('Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => [
          '{{WRAPPER}} .elespare-time-wrapper span.elespare-time-text' => 'color: {{VALUE}};',
        ],

      ]
    );

    $this->add_control(
      'time_icon_color',
      array(
        'label'     => esc_html__('Icon Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => array(
          '{{WRAPPER}} .elespare-time-icon' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_responsive_control(
      'time_icon_size',
      [
        'label' => esc_html__('Time Icon Size', 'elementor'),
        'type' => Controls_Manager::HIDDEN,
        'range' => [
          'px' => [
            'min' => 10,
            'max' => 100,
          ],
        ],
        'selectors' => [
          '{{WRAPPER}} .elespare-time-icon' => 'font-size: {{SIZE}}{{UNIT}};',
        ],
        'separator' => 'before',
      ]
    );

    $this->end_controls_section();
  }



  protected function render()
  {
    $settings = $this->get_settings_for_display();
    $current_date = $settings['display_date'];
    $current_time = $settings['display_time'];
    $date_icon = $settings['date_icon'];
    $time_icon = $settings['time_icon'];
    $layout_style = $settings['date_time_layout_style'];
    $alignment_class = $settings['_date_time_title_alignment'];
    $order = $settings['date_time_order'];
    $horizontal_vertical = $settings['date_time_horizontal_vertical_style'];
    $date_before_text = $settings['before_date_text'];
    $date_after_text = $settings['after_date_text'];
    $date_time_border_radius = $settings['date_time_bordr_radius'];


    $this->add_render_attribute('elespare-date-time-wrap', 'class', ['elespare-date-time-widget', $layout_style, $alignment_class, $order, $horizontal_vertical, $date_time_border_radius]);


    if ('yes' == $current_date || 'yes' == $current_time) {
?>

      <div <?php $this->print_render_attribute_string('elespare-date-time-wrap'); ?>>


        <?php if ('yes' == $current_date) {
          $date_wrap_class = "";

          if (empty($date_icon['value'])) {
            $date_wrap_class .= " elespare-date-without-icon-wrapper";
          }
        ?>

          <div class="elespare-date-time elespare-date-wrapper <?php echo esc_attr($date_wrap_class); ?>">

            <?php if (!empty($date_icon['value'])) { ?>
              <div class="elespare-icon-wrapper">
                <i class="elespare-date-icon <?php echo esc_attr($date_icon['value']); ?>" aria-hidden="true"></i>
              </div>
            <?php } ?>
            <div class="elespare-date">
              <?php if (!empty($date_before_text)) { ?>
                <span class="elespare-before-date-text "><?php echo esc_html($settings['before_date_text']); ?></span>
              <?php } ?>

              <span class='elespare-date-text'>
                <?php echo wp_kses_post(date_i18n(get_option('date_format'))); ?>
              </span>
              <?php if (!empty($date_after_text)) { ?>
                <span class="elespare-after-date-text"><?php echo esc_html($settings['after_date_text']); ?></span>
              <?php } ?>
            </div>
          </div>
        <?php } ?>


        <?php if ('yes' == $current_time) {
          $time_wrap_class = "";

          if (empty($time_icon['value'])) {
            $time_wrap_class .= " elespare-time-without-icon-wrapper";
          }

        ?>
          <div class="elespare-date-time elespare-time-wrapper <?php echo esc_attr($time_wrap_class); ?>">
            <?php if (!empty($time_icon['value'])) { ?>
              <div class="elespare-icon-wrapper">
                <i class="elespare-time-icon <?php echo esc_attr($time_icon['value']); ?>" aria-hidden="true"></i>
              </div>
            <?php } ?>
            <span class='elespare-time-text'>
              <?php $time = current_time('H:i');
              echo esc_html($time);
              ?>
            </span>
          </div>
        <?php }
        ?>

      </div>
<?php
    }
  }
}
