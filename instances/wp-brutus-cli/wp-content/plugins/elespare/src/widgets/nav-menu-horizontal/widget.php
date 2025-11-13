<?php

namespace Elespare\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;

if (! defined('ABSPATH')) {
  exit;   // Exit if accessed directly.
}
/**
 * Elespare Nav Menu
 *
 * Elementor widget for hello world.
 */
class NavigationHorizontalMenu extends Widget_Base
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
    return 'elespare-nav-horziontal-menu';
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
    return esc_html__('Horizontal Nav Menu', 'elespare');
  }

  public function has_widget_inner_wrapper(): bool
  {
    return ! Plugin::$instance->experiments->is_feature_active('e_optimized_markup');
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
    return 'demo-icon elespare-icons-hori-nav';
  }

  public function get_script_depends()
  {
    return array('elespare-custom-scripts');
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
   * Register Site Logo controls.
   *
   * @since 1.0.0
   * @access protected
   */

  protected function get_available_menus()
  {
    $menus = wp_get_nav_menus();

    $options = [];

    foreach ($menus as $menu) {
      $options[$menu->slug] = $menu->name;
    }

    return $options;
  }
  protected function register_controls()
  { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    $this->register_navigation_controls();
    $this->register_mobile_menu();
    $this->register_menu_style();
    $this->register_submenu_style();
    $this->register_menu_sidebar_style();
  }


  protected function register_navigation_controls()
  {
    $this->start_controls_section(
      'section_content',
      array(
        'label' => esc_html__('Menu', 'elespare'),
      )
    );

    $menus = $this->get_available_menus();

    if (! empty($menus)) {
      $this->add_control(
        'menu',
        [
          'label'        => esc_html__('Menu', 'elespare'),
          'type'         => Controls_Manager::SELECT,
          'options'      => $menus,
          'default'      => array_keys($menus)[0],
          'save_default' => true,
          /* translators: %s Nav menu URL */
          'description'  => sprintf(__('Go to the <a href="%s" target="_blank">Menus screen</a> to manage your menus.', 'elespare-pror'), admin_url('nav-menus.php')),
        ]
      );
    } else {
      $this->add_control(
        'menu',
        [
          'type'            => Controls_Manager::RAW_HTML,
          /* translators: %s Nav menu URL */
          'raw'             => sprintf(__('<strong>There are no menus in your site.</strong><br>Go to the <a href="%s" target="_blank">Menus screen</a> to create one.', 'elespare'), admin_url('nav-menus.php?action=edit&menu=0')),
          'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]
      );
    }

    $this->add_control(
      'align',
      array(
        'label'     => esc_html__('Desktop Alignment', 'elespare'),
        'type'      => Controls_Manager::CHOOSE,
        'default'   => 'flex-start',
        'options'   => array(
          'flex-start' => array(
            'icon'  => 'eicon-h-align-left',
            'title' => esc_html__('Left', 'elespare')
          ),
          'center'     => array(
            'icon'  => 'eicon-h-align-center',
            'title' => esc_html__('Center', 'elespare')
          ),
          'flex-end'   => array(
            'icon'  => 'eicon-h-align-right',
            'title' => esc_html__('Right', 'elespare')
          ),
        ),
        'toggle' => true,
      )
    );

    $this->add_control(
      'pointer',
      array(
        'label'   => esc_html__('Pointer', 'elespare'),
        'type'    => Controls_Manager::SELECT,
        'default' => 'none',
        'options' => array(
          'underline'  =>  esc_html__('Underline', 'elespare'),
          'none'       =>  esc_html__('None', 'elespare'),
        )

      )
    );

    $this->add_control(
      'submenu_icon',
      [
        'label'        => esc_html__('Submenu Icon', 'elespare'),
        'type'         => Controls_Manager::HIDDEN,
        'default'      => 'elespare-submenu-icon-arrow',
        'prefix_class' => 'elespare-submenu-icon-',
      ]
    );

    // $this->add_control(
    // 	'menu_badge_layout',
    // 	[
    // 		'label' => esc_html__( 'Menu Badge Layout', 'elespare' ),
    // 		'type' => Controls_Manager::SELECT,
    // 		'default' => 'menu-badge-1',
    // 		'options' => [
    // 			'menu-badge-1' => esc_html__( 'Layout 1', 'elespare' ),
    // 			'menu-badge-2' => esc_html__( 'Layout 2', 'elespare' ),
    // 			'menu-badge-3' => esc_html__( 'Layout 3', 'elespare' ),

    // 		],
    // 	]
    // );


    $this->end_controls_section();
  }

  protected function register_mobile_menu()
  {
    $this->start_controls_section(
      'section_mobile_menu',
      array(
        'label' => esc_html__('Responsive', 'elespare'),
      )
    );



    $this->add_control(
      'heading_responsive',
      [
        'type'      => Controls_Manager::HEADING,
        'label'     => esc_html__('Responsive', 'elespare'),
        'separator' => 'before',
      ]
    );

    $this->add_control(
      'break_points',
      [
        'label'        => esc_html__('Breakpoint', 'elespare'),
        'type'         => Controls_Manager::SELECT,
        'default'      => 'tablet-large',
        'options'      => [
          'mobile-small' => esc_html__('Mobile (480px >)', 'elespare'),
          'mobile-large' => esc_html__('Mobile (768px >)', 'elespare'),
          'tablet-small' => esc_html__('Tablet (992px >)', 'elespare'),
          'tablet-large' => esc_html__('Tablet (1025px >)', 'elespare'),
          'none'  => esc_html__('None', 'elespare'),
        ],
        'prefix_class' => 'elesapre-nav-menu__breakpoint-',
        'render_type'  => 'template',
      ]
    );

    $this->add_control(
      'layout_option',
      [
        'label'        => esc_html__('Menu Option', 'elespare'),
        'type'         => Controls_Manager::HIDDEN,
        'default'      => 'drawer',
        'condition' => [
          'break_points!' => 'none',
        ],


      ]
    );
    $this->add_control(
      '_left_right_option',
      [
        'label'        => esc_html__('Drawer Option', 'elespare'),
        'type'         => Controls_Manager::HIDDEN,
        'default'      => 'elespare-menu-left',
        'condition' => [
          'layout_option' => 'drawer',
          'break_points!' => 'none',
        ]

      ]
    );


    $this->add_control(
      'toggle_icon',
      array(
        'label'     => esc_html__('Toggle Icons', 'elespare'),
        'type'      => Controls_Manager::ICONS,
        'separator' => 'before',
        'default' => [
          'value' => 'demo-icon elespare-icons-menu',
          'library' => 'reguler'
        ],
        'condition' => [
          'break_points!' => 'none'
        ]
      )
    );

    $this->add_control(
      'toggle-align',
      array(
        'label'     => esc_html__('Toggle Align', 'elespare'),
        'type'      => Controls_Manager::CHOOSE,
        'default'   => 'right',
        'options'   => array(
          'left'   => array(
            'icon'  => 'eicon-h-align-left',
            'title' =>  esc_html__('Top', 'elespare'),
          ),
          'center' => array(
            'icon'  => 'eicon-h-align-center',
            'title' =>  esc_html__('Center', 'elespare'),
          ),
          'right'  => array(
            'icon'  => 'eicon-h-align-right',
            'title' =>  esc_html__('Bottom', 'elespare'),
          ),
        )
      )
    );

    $this->add_control(
      'ham_size',
      array(
        'label'      => esc_html__('Ham Size', 'elespare'),
        'type'       => Controls_Manager::SLIDER,
        'size_units' => array('px', '%'),
        'range'      => array(
          'px' => array(
            'min'  => 30,
            'max'  => 100,
            'step' => 1,
          ),
        ),
        'default'    => array(
          'unit' => 'px',
          'size' => 34,
        ),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-navigation-wrapper.horizontal.drawer .elespare-menu-toggle' => 'font-size: {{SIZE}}{{UNIT}};',
          '{{WRAPPER}} .elespare-navigation-wrapper.horizontal.dropdown .elespare-menu-toggle' => 'font-size: {{SIZE}}{{UNIT}};',
        ),
        'condition' => [
          'break_points!' => 'none',
        ],
      )
    );

    $this->add_control(
      'dropdown_close_icon',
      [
        'label'       => esc_html__('Close Icon', 'elespare'),
        'type'      => Controls_Manager::ICONS,
        'separator' => 'before',
        'default' => [
          'value' => 'demo-icon elespare-icons-cancel',
          'library' => 'reguler'
        ],
        'condition'   => [
          'break_points!' => 'none',
        ],
      ]
    );



    $this->end_controls_section();
  }

  protected function register_menu_style()
  {
    $this->start_controls_section(
      'menu_style',
      array(
        'label' => esc_html__('Menu Style', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );

    $this->add_control(
      'menu_item_padding',
      array(
        'label'      => esc_html__('Padding', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px', '%', 'em'),
        'default'    => array(
          'top'    => 0,
          'bottom' => 0,
          'left'   => 15,
          'right'  => 15,
        ),
        'selectors'  => array(
          '{{WRAPPER}} div.elespare-menu ul > li > a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} ul.elespare-menu > li > a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare--hover-overline div.elespare-menu > ul >li:hover>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-overline ul.elespare-menu >li:hover>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-underline div.elespare-menu >ul>li:hover>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-underline ul.elespare-menu>li:hover>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-overline div.elespare-menu>ul>licurrent-menu-item>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-overline ul.elespare-menu>licurrent-menu-item>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-underline div.elespare-menu>ul>licurrent-menu-item>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
          '{{WRAPPER}} .elespare--hover-underline ul.elespare-menu>licurrent-menu-item>a:before' => 'width: calc( 100% - {{RIGHT}}{{UNIT}} - {{LEFT}}{{UNIT}} );',
        ),
      )
    );

    $this->start_controls_tabs(
      'menu_style_tabs'
    );

    $this->start_controls_tab(
      'style_nemu_normal_tab',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    $this->add_control(
      'menucolor',
      array(
        'label'     => esc_html__('Menu Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#000',
        'selectors' => array(
          '{{WRAPPER}} div.elespare-menu > ul > li > a' => 'color: {{VALUE}}',
          '{{WRAPPER}} ul.elespare-menu > li > a' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'     => 'menu_typo',
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
            'default' => '700',
          ],
          'text_transform' => [
            'default' => 'none'
          ]

        ],

        'selector' => '{{WRAPPER}} ul.elespare-menu > li > a'

      )
    );


    $this->end_controls_tab();

    $this->start_controls_tab(
      'style_menu_hover_tab',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    $this->add_control(
      'menu_color_hover',
      array(
        'label'     => esc_html__('Menu Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#aa3166',
        'selectors' => array(
          '{{WRAPPER}} div.elespare-menu > ul > li:hover > a' => 'color: {{VALUE}}',
          '{{WRAPPER}} ul.elespare-menu > li:hover > a' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-underline div.elespare-menu > ul > li:hover > a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-underline ul.elespare-menu > li:hover > a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-overline div.elespare-menu ul li a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-overline ul.elespare-menu li a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu li.current-menu-item a' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-underline div.elespare-menu > ul > li.current-menu-item > a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-underline ul.elespare-menu > li.current-menu-item > a:before' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-overline .elespare-menu li a.current-menu-item > a:before' => 'background-color: {{VALUE}}',
        ),
      )
    );

    $this->end_controls_tab();

    $this->end_controls_tabs();

    $this->end_controls_section();
  }

  protected function register_submenu_style()
  {
    $this->start_controls_section(
      'submenu_style',
      array(
        'label' => esc_html__('Sub Menu Style', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );


    $this->add_responsive_control(
      'dropdown_top_distance',
      array(
        'label'     => esc_html__('Distance', 'elespare'),
        'type'      => Controls_Manager::SLIDER,
        'range'     => array(
          'px' => array(
            'min' => 0,
            'max' => 100,
          ),
        ),
        'selectors' => array(
          '{{WRAPPER}} div.elespare-menu > ul >.page_item_has_children>.children' => 'margin-top: {{SIZE}}{{UNIT}} !important',
          '{{WRAPPER}} ul.elespare-menu>.menu-item-has-children>.elespare-menu-child' => 'margin-top: {{SIZE}}{{UNIT}} !important',
        ),
        'separator' => 'before',
      )
    );


    $this->add_control(
      'padding_submenu_item',
      array(
        'label'      => esc_html__('Padding Menu Item', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px'),
        'separator'  => 'before',
        'default'    => array(
          'top'    => 13,
          'bottom' => 14,
          'left'   => 20,
          'right'  => 20,
        ),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-menu .sub-menu-default li a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-nav-default .sub-menu li a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ),
      )
    );

    $this->add_control(
      'submenu-bdrs',
      array(
        'label'      => esc_html__('Border Radius', 'elespare'),
        'type'       => Controls_Manager::DIMENSIONS,
        'size_units' => array('px'),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-menu .sub-menu-default' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
          '{{WRAPPER}} .elespare-nav-default .sub-menu' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ),
      )
    );


    $this->start_controls_tabs(
      'submenu_style_tabs'
    );

    $this->start_controls_tab(
      'style_submemu_normal_tab',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    $this->add_control(
      'color_submenu',
      array(
        'label'     => esc_html__('SubMenu Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#000',
        'selectors' => array(
          '{{WRAPPER}} .elespare-nav-default .menu-item-has-children .sub-menu a' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .menu-item-has-children .sub-menu-default a' => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .page_item_has_children .children a' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_control(
      'bgcolor_submenu',
      array(
        'label'     => esc_html__('Background color SubMenu', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'selectors' => array(
          '{{WRAPPER}} .elespare-nav-default .menu-item-has-children .sub-menu' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .menu-item-has-children .sub-menu-default' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .page_item_has_children .children' => 'background-color: {{VALUE}}',
        ),
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'     => 'submenu_typo',
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
            'default' => '700',
          ],

        ],
        'selectors' => '{{WRAPPER}} .elespare-menu .menu-item-has-children .sub-menu a, {{WRAPPER}} .elespare-nav-default .menu-item-has-children .sub-menu-default a,{{WRAPPER}} .elespare-menu .page_item_has_children .children a',

      )
    );

    $this->end_controls_tab();

    $this->start_controls_tab(
      'style_submenu_hover_tab',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    $this->add_control(
      'color_submenu_hover',
      array(
        'label'     => esc_html__('SubMenu Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#aa3166',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-nav-default li .sub-menu > li:hover > a'                                  => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-nav-default li .children > li:hover > a'                                  => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-nav-default .sub-menu li.current-menu-item a'                                       => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-nav-default .children li.current-menu-item a'                                       => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu li .sub-menu-default > li:hover > a'                                  => 'color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .sub-menu-default li.current-menu-item a'                                       => 'color: {{VALUE}}',
        ),
      )
    );


    $this->add_control(
      'submenu_item_background_color_hover',
      array(
        'label'     => esc_html__('Background Submenu Item Hover', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#eee',
        'selectors' => array(
          '{{WRAPPER}} .elespare--hover-background .elespare-menu .sub-menu-default > li:hover' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-background.elespare-nav-default .sub-menu > li:hover' => 'background-color: {{VALUE}}',
          '{{WRAPPER}} .elespare--hover-background.elespare-nav-default .children > li:hover' => 'background-color: {{VALUE}}',
        ),
        'condition' => array(
          'pointer' => 'background',
        ),
      )
    );

    $this->end_controls_tab();

    $this->end_controls_tabs();

    $this->add_control(
      'color_submenu_border',
      array(
        'label'     => esc_html__('SubMenu Border Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#efefef',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-nav-default .sub-menu' => 'border-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .sub-menu-default' => 'border-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .children li a' => 'border-color: {{VALUE}}',
          '{{WRAPPER}} .elespare-menu .sub-menu li a' => 'border-color: {{VALUE}}',
        ),
      )
    );

    $this->add_control(
      'submenu_border_width',
      array(
        'label'      => esc_html__('Border Width', 'elespare'),
        'type'       => Controls_Manager::HIDDEN,
        'size_units' => array('px', '%', 'em'),
        'default'    => array(
          'top'    => 0,
          'bottom' => 1,
          'left'   => 0,
          'right'  => 0,
        ),
        'selectors'  => array(
          '{{WRAPPER}} .elespare-nav-default .sub-menu li a' => 'border-width: 0px 0px 1px 0px;',
          '{{WRAPPER}} .elespare-nav-default .children li a' => 'border-width: 0px 0px 1px 0px;',

        ),
      )
    );



    $this->end_controls_section();
  }

  public function register_menu_sidebar_style()
  {
    $this->start_controls_section(
      'menu_sidebar_style',
      array(
        'label' => esc_html__('Menu Mobile Style', 'elespare'),
        'tab'   => Controls_Manager::TAB_STYLE,
      )
    );

    $this->add_group_control(
      Group_Control_Background::get_type(),
      array(
        'name'     => 'background_submenu',
        'label'    => esc_html__('Background', 'elespare'),
        'types'    => array('classic'),
        'selector' => '{{WRAPPER}} .elespare-moblie-ham-menu',
      )
    );

    $this->start_controls_tabs(
      'menu_sidebar_style_tabs'
    );

    $this->start_controls_tab(
      'style_memu_sidebar_normal_tab',
      array(
        'label' => esc_html__('Normal', 'elespare'),
      )
    );

    $this->menu_sidebar_style_nornal();

    $this->end_controls_tab();

    $this->start_controls_tab(
      'style_menu_sidebar_hover_tab',
      array(
        'label' => esc_html__('Hover', 'elespare'),
      )
    );

    $this->menu_sidebar_style_hover();

    $this->end_controls_tab();

    $this->end_controls_tabs();

    $this->end_controls_section();
  }

  public function menu_sidebar_style_nornal()
  {
    $this->add_control(
      'color_toggle',
      array(
        'label'     => esc_html__('Toggle Icon Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#000',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-menu-toggle' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_control(
      'color_menu_sidebar',
      array(
        'label'     => esc_html__('Menu Item Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#000',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-menu-sidebar .elespare-dropdown-menu a' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_group_control(
      Group_Control_Typography::get_type(),
      array(
        'name'     => 'menu_sidebar_typo',
        'label'    => esc_html__('Typography', 'elespare'),
        'selector' => '{{WRAPPER}} .elespare-menu-sidebar .elespare-dropdown-menu a',
      )
    );
  }

  public function menu_sidebar_style_hover()
  {
    $this->add_control(
      'color_toggle_hover',
      array(
        'label'     => esc_html__('Toggle Icon Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#000',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-menu-toggle:hover' => 'color: {{VALUE}}',
        ),
      )
    );

    $this->add_control(
      'color_menu_sidebar_hover',
      array(
        'label'     => esc_html__('Menu Item Color', 'elespare'),
        'type'      => Controls_Manager::COLOR,
        'default'   => '#d1346f',
        'separator' => 'before',
        'selectors' => array(
          '{{WRAPPER}} .elespare-menu-sidebar .elespare-dropdown-menu li:hover > a' => 'color: {{VALUE}}',
        ),
      )
    );
  }

  /**
   * Render the widget output on the frontend.
   *
   * Written in PHP and used to generate the final HTML.
   */
  protected function render()
  {
    $menus = $this->get_available_menus();

    if (empty($menus)) {
      return false;
    }
    $settings = $this->get_settings_for_display();
    $layout_option =  $settings['layout_option'];
    $mobile_align =  $settings['toggle-align'];
    add_filter('walker_nav_menu_start_el', 'elespare_nav_description', 10, 4);

    if ('none' != $settings['break_points']) {
      $breakpoint_none_class = 'elespare-mobile-responsive-' . $mobile_align;
    } else {
      $breakpoint_none_class = 'elespare-mobile-none-' . $mobile_align;
    }

    $this->add_render_attribute('elespare-widget-wrapper', 'class', ['elespare-navigation--widget elespare-navigation-wrapper horizontal', $settings['align'], $breakpoint_none_class, 'sub-hover', $layout_option]);
    $this->add_render_attribute('elespare-main-navigation', 'class', ['elespare-main-navigation elespare-nav-default elespare-nav elespare-header-navigation', ' elespare--hover-' .  $settings['pointer'], $settings['submenu_icon']]);
?>
    <div <?php $this->print_render_attribute_string('elespare-widget-wrapper'); ?> data-opt="sub-hover">
      <nav <?php $this->print_render_attribute_string('elespare-main-navigation'); ?>
        aria-label="<?php esc_attr_e('Primary navigation', 'elespare'); ?>"
        data-closeicon="<?php echo esc_attr($settings['dropdown_close_icon']['value']) ?>">
        <?php

        $args = array(
          'menu'           => $settings['menu'],
          'container'      => '',
          'menu_class'     => 'elespare-menu ' . 'animation-1',
          'theme_location' => 'primary',
        );

        wp_nav_menu($args);


        ?>
      </nav>

      <?php

      if ('none' != $settings['break_points']) {
        $ham_icon = $settings['toggle_icon']['value'];
        $ham_close_icon = $settings['dropdown_close_icon']['value'];
        $this->get_toggle($ham_icon, $ham_close_icon, $settings['layout_option']);

        if ($layout_option == 'drawer') {
          $layout_class = 'elespare-moblie-ham-menu elespare-menu-sidebar ' . $settings['_left_right_option'];
        } else {
          $layout_class = 'elespare-moblie-ham-menu  elespare-menu-sidebar-dropdown ';
        }
        $this->add_render_attribute('elespare-moblie-ham-menu', 'class', [$layout_class, 'elespare--hover-' . $settings['pointer']]);
        $this->add_render_attribute('elespare-menu-dropdown', 'class', ['elespare-menu-dropdown', $settings['menu'], $settings['submenu_icon']]);
      ?>

        <div <?php $this->print_render_attribute_string('elespare-moblie-ham-menu'); ?>>
          <?php if ($layout_option == 'drawer') { ?>
            <a href="#"
              class="elespare--close-menu-side-bar <?php echo esc_attr($settings['dropdown_close_icon']['value']) ?>"></a>
          <?php } ?>
          <div class="elespare-menu-sidebar--wrapper">

            <nav <?php $this->print_render_attribute_string('elespare-menu-dropdown'); ?>
              aria-label="<?php esc_attr_e('Dropdown navigation', 'elespare'); ?>">
              <?php
              if ('none' !== $settings['menu']) {
                $args = array(
                  'menu'       => $settings['menu'],
                  'container'  => '',
                  'menu_class' => 'elespare-dropdown-menu',
                  'theme_location' => 'primary',
                );

                wp_nav_menu($args);
              } elseif (is_user_logged_in()) {
              ?>
                <a class="add-menu"
                  href="<?php echo esc_url(get_admin_url() . 'nav-menus.php'); ?>"><?php esc_html_e('Add a Primary Menu', 'elespare'); ?></a>
              <?php } ?>
            </nav>
          </div>
        </div>

      <?php } ?>

      <?php if ($layout_option == 'drawer') { ?>
        <div class="elespare-overlay"></div>
      <?php } ?>
    </div>
  <?php

  }


  protected function nav_class()
  {
    $settings = $this->get_settings_for_display();
    $name     = $this->get_name();
    $classes  = array(
      'elespare-main-navigation',
      'elespare-header-navigation',
    );
    if (array_key_exists('pointer', $settings)) {
      $pointer = $settings['pointer'];
      array_push($classes, 'elespare--hover-' . $pointer);
    }
    if (array_key_exists('icon_position', $settings)) {
      $icon_position = $settings['icon_position'];
      array_push($classes, 'elespare-icon-' . $icon_position);
    }

    return $classes;
  }

  /**
   * Get Sub Mega Menu Class
   * @param int $post_id
   * @return object $sub menu default layout
   */
  protected function sub_menu_default($menu_id, $child_of)
  {
    $args = array(
      'menu'       => $menu_id,
      'menu_id'    => '',
      'menu_class' => 'sub-menu sub-menu-default',
      'container'  => '',
    );
    if (! empty($child_of)) {
      $args['level']    = 2;
      $args['child_of'] = $child_of;
    }

    wp_nav_menu($args);
  }

  protected function get_toggle($ham_icon, $close_icon, $layout_option)
  {
  ?>
    <a href="#" class="elespare-menu-toggle" aria-expanded="false">
      <span class="elespare-menu-icon-toggle  elespare-open-toggle <?php echo esc_attr($ham_icon); ?>"></span>
      <!-- .menu-toggle-wrapper -->
      <span class="elespare-menu-icon-toggle  elespare-close-toggle <?php echo esc_attr($close_icon); ?>"></span>
      <!-- .menu-toggle-wrapper -->
      <span class="screen-reader-text menu-toggle-text"><?php esc_html_e('Menu', 'elespare'); ?></span>
    </a><!-- .menu-toggle -->
<?php
  }
}
