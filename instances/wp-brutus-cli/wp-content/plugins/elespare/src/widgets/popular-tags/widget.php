<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;

if (! defined('ABSPATH')) {
  exit();
}

class PopularTags extends Widget_Base
{


  public function get_name()
  {
    return 'popular-tags';
  }

  public function get_title()
  {
    return esc_html__('Popular Tag/Category', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
  }


  public function get_icon()
  {
    return 'demo-icon elespare-icons-categories';
  }

  public function get_categories()
  {
    return ['elespare'];
  }

  protected function register_controls()
  {
    $this->elespare_popular_tag_content_options();
    $this->elespare_popular_tag_style_options();
  }

  protected function elespare_popular_tag_content_options()
  {
    $this->start_controls_section(
      'copyright_section',
      [
        'label' => esc_html__('General', 'elespare'),
      ]
    );

    $this->add_control(
      'popular_tag_layout_style',
      [
        'label' => esc_html__('Layout Style', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'popular-tag-style-1',
        'options' => [
          'popular-tag-style-1' => esc_html__('Layout 1', 'elespare'),
          'popular-tag-style-2' => esc_html__('Layout 2', 'elespare'),
          // 'popular-tag-style-3' => esc_html__( 'Layout 3', 'elespare' ),
          // 'popular-tag-style-4' => esc_html__( 'Layout 4', 'elespare' ),
          // 'popular-tag-style-5' => esc_html__( 'Layout 5', 'elespare' ),
          // 'popular-tag-style-6' => esc_html__( 'Layout 6', 'elespare' ),
          // 'popular-tag-style-7' => esc_html__( 'Layout 7', 'elespare' ),
          // 'popular-tag-style-8' => esc_html__( 'Layout 8', 'elespare' ),
          // 'popular-tag-style-9' => esc_html__( 'Layout 9', 'elespare' ),
          // 'popular-tag-style-10' => esc_html__( 'Layout 10', 'elespare' ),
          // 'popular-tag-style-11' => esc_html__( 'Layout 11', 'elespare' ),
          // 'popular-tag-style-12' => esc_html__( 'Layout 12', 'elespare' ),


        ],
      ]
    );

    $this->add_control(
      'popular_tag_bordr_radius',
      [
        'label' => esc_html__('Border Radius', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'popular-tag-mixed',
        'separator' => 'before',
        'options' => [
          'popular-tag-mixed' => esc_html__('Default', 'elespare'),
          'popular-tag-rounded' => esc_html__('Rounded', 'elespare'),
          'popular-tag-circular' => esc_html__('Circular', 'elespare'),
          'popular-tag-rectangular' => esc_html__('Rectangular', 'elespare')
        ],
        'condition' => [
          'popular_tag_layout_style!' => 'popular-tag-style-1',
        ]
      ]
    );

    $this->add_control(
      'popular_tag_verticale_horizontal_style',
      [
        'label' => esc_html__('Horizontal/Vertical', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'default' => 'popular-horizontal',
        'options' => [
          'popular-horizontal' => esc_html__('Horizontal', 'elespare'),
          'popular-vertical' => esc_html__('Vertical', 'elespare')
        ],
      ]
    );

    $this->add_control(
      'popular_tag_column',
      [
        'label' => esc_html__('Columns', 'elespare'),
        'type' => Controls_Manager::SELECT,
        'default' => 'popular-vertical',
        'options' => [
          'popular-vertical' => esc_html__('Column 1', 'elespare'),
          'elespare-column-2' => esc_html__('Column 2', 'elespare'),
          'elespare-column-3' => esc_html__('Column 3', 'elespare'),
          'elespare-column-4' => esc_html__('Column 4', 'elespare'),
        ],
        'condition' => [
          'popular_tag_verticale_horizontal_style' => 'popular-vertical'
        ]
      ]
    );

    $this->add_control(
      'popular_tag_content',
      [
        'label' => esc_html__('Filter by ', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => 'post_tag',
        'options' => [
          'category' => esc_html__('Categories', 'elespare'),
          'post_tag' => esc_html__('Tags', 'elespare'),
        ]

      ]

    );

    $this->add_control(
      'latest_or_popular_content',
      [
        'label' => esc_html__('Order by ', 'elespare'),
        'label_block' => true,
        'type'    => Controls_Manager::SELECT,
        'default' => 'count',
        'options' => [
          'count' => esc_html__('Popular', 'elespare'),
          'date' => esc_html__('Latest', 'elespare'),
        ]

      ]

    );

    $this->add_control(
      'number',
      [
        'label' => __('Number of tags', 'elespare'),
        'type' => Controls_Manager::NUMBER,
        'min' => 1,
        'max' => 100,
        'step' => 1,
        'default' => 5,
      ]
    );

    $this->add_control(
      'primary_icon',
      array(
        'label'     => esc_html__('Primary Icon', 'elespare'),
        'type' => Controls_Manager::ICONS,
        'skin' => 'inline',
        'default' => [
          'value' => 'demo-icon elespare-icons-tag',
          'library' => 'reguler'
        ],


      )
    );

    $this->add_control(
      'secondary_icon',
      array(
        'label'     => esc_html__('Icon Before Category/Tag', 'elespare'),
        'type' => Controls_Manager::HIDDEN,
        'skin' => 'inline',
        'default' => [
          'value' => 'demo-icon elespare-icons-hashtag',
          'library' => 'reguler'
        ],


      )
    );
    $this->end_controls_section();
  }

  protected function elespare_popular_tag_style_options()
  {

    $this->start_controls_section(
      'section_style',
      array(
        'label' => esc_html__('General Style', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [

        'name' => 'tag_title_typography',
        'label' => esc_html__('Section Title Typography', 'elespare'),
        'fields_options' => [
          'typography' => [
            'default' => 'yes'

          ]

        ],
        'selector' => '{{WRAPPER}} .elespare-tag-title-text ,
                {{WRAPPER}} .elespare-primary-icon',


      ]
    );

    $this->add_control('tag_title_color', [
      'label'     => esc_html__('Section Title Color', 'elespare'),
      'type'      => Controls_Manager::COLOR,
      'default'  => '#000',
      'selectors' => [
        '{{WRAPPER}} .elespare-tag-title-wrapper span.elespare-tag-title-text' => 'color: {{VALUE}};',
      ],

    ]);

    $this->add_control('tag_title_hover_color', [
      'label'     => esc_html__('Section Title Hover Color', 'elespare'),
      'type'      => Controls_Manager::COLOR,
      'default'  => '#4E4949',
      'selectors' => [
        '{{WRAPPER}} .elespare-tag-title-wrapper span.elespare-tag-title-text:hover' => 'color: {{VALUE}};',
      ],

    ]);

    $this->add_control(
      'primary_color',
      array(
        'label'     => esc_html__('Primary Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'     => '#ffb400',
        'selectors' => array(
          '{{WRAPPER}} .elespare-popular-taxonomies-lists .elespare-popular-tags-text' => 'background-color: {{VALUE}}',
        ),
        'condition' => [
          'popular_tag_layout_style!' => 'popular-tag-style-1'
        ]
      )
    );

    $this->add_control(
      'primary_icon_color',
      array(
        'label'     => esc_html__('Primary Icon Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'  => '#000',
        'selectors' => array(
          '{{WRAPPER}} .elespare-primary-icon' => 'color: {{VALUE}}',
        ),
        'condition' => [
          'primary_icon[value]!' => ''
        ]
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      [
        'name' => 'tag_typography',
        'label' => esc_html__('Items Typography', 'elespare'),
        'fields_options' => [
          'typography' => [
            'default' => 'yes'

          ]

        ],
        'selector' => '{{WRAPPER}} .elespare-tag-text,
              {{WRAPPER}} .elespare-secondary-icon',


      ]
    );

    $this->add_control('tag_color', [
      'label'     => esc_html__('Items Color', 'elespare'),
      'type'      => Controls_Manager::COLOR,
      'default'  => '#000',
      'selectors' => [
        '{{WRAPPER}} .elespare-tag-wrapper span.elespare-tag-text' => 'color: {{VALUE}};',
      ],

    ]);

    $this->add_control('tag_hover_color', [
      'label'     => esc_html__('Items Hover Color', 'elespare'),
      'type'      => Controls_Manager::COLOR,
      'default'  => '#4E4949',
      'selectors' => [
        '{{WRAPPER}} .elespare-tag-wrapper span.elespare-tag-text:hover' => 'color: {{VALUE}};',
      ],

    ]);

    $this->add_control(
      'secondary_icon_color',
      array(
        'label'     => esc_html__('Secondary Icon Color', 'elespare'),
        'type'      => Controls_Manager::HIDDEN,
        'default'  => '#000',
        'selectors' => array(
          '{{WRAPPER}} .elespare-secondary-icon' => 'color: {{VALUE}}',
        ),
        'condition' => [
          'secondary_icon[value]!' => ''
        ]
      )
    );

    $this->end_controls_section();
  }

  protected function render()
  {

    $settings = $this->get_settings_for_display();
    $number = $settings['number'];
    $primary_icon = $settings['primary_icon'];
    $secondary_icon = $settings['secondary_icon'];
    $vertical_horizontal = $settings['popular_tag_verticale_horizontal_style'];
    $popular_tag_column =  $settings['popular_tag_column'];
    $query_by = $settings['latest_or_popular_content'];
    $tag_wrap_class = "";
    $date_time_border_radius = $settings['popular_tag_bordr_radius'];

    if (empty($primary_icon['value'])) {
      $tag_wrap_class .= " elespare-tag-without-icon-wrapper";
    }

?>


    <div class="<?php echo esc_attr($settings['popular_tag_layout_style']); ?> <?php echo esc_attr($tag_wrap_class); ?>">

      <?php
      if ('post_tag' == $settings['popular_tag_content']) {

        elespare_list_popular_categories('post_tag', "Tags", $number, $primary_icon['value'], $secondary_icon['value'], $vertical_horizontal, $popular_tag_column, $query_by, $date_time_border_radius); //Passes icon

      } else {
        if ('category' == $settings['popular_tag_content'])

          elespare_list_popular_categories('category', "Categories", $number, $primary_icon['value'], $secondary_icon['value'], $vertical_horizontal, $popular_tag_column, $query_by, $date_time_border_radius); //Passes icon

      }
      ?>


    </div>
<?php
  }
}
