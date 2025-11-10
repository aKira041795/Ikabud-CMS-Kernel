<?php

class AMP_Walker_Nav_Menu extends Walker_Nav_Menu
{
  function start_lvl(&$output, $depth = 0, $args = null)
  {
    // Submenu binding to control visibility based on AMP state
    global $menu_item_id_for_amp;
    $output .= '<ul class="sub-menu" [class]="menuOpen' . $menu_item_id_for_amp . ' ? \'sub-menu active\' : \'sub-menu\'">';
  }

  function end_lvl(&$output, $depth = 0, $args = null)
  {
    $output .= '</ul>';
  }

  function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
  {
    global $menu_item_id_for_amp;
    $menu_item_id_for_amp = esc_attr($item->ID);

    $classes = empty($item->classes) ? array() : (array) $item->classes;
    $class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args, $depth));
    $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';

    $output .= '<li id="menu-item-' . esc_attr($item->ID) . '" ' . $class_names . ' data-menu-id="' . esc_attr($item->ID) . '" class="menu-item-' . esc_attr($item->ID) . '">';

    $atts = array();
    $atts['href'] = !empty($item->url) ? $item->url : '';

    $attributes = '';
    foreach ($atts as $attr => $value) {
      if (!empty($value)) {
        $value = ('href' === $attr) ? esc_url($value) : esc_attr($value);
        $attributes .= ' ' . $attr . '="' . $value . '"';
      }
    }

    $has_children = in_array('menu-item-has-children', $classes);
    $item_output = '<a' . $attributes . ' [class]="menuOpen' . $item->ID . ' ? \'active\' : \'\'">';

    $item_output .= apply_filters('the_title', $item->title, $item->ID);
    $item_output .= '</a>';

    if ($has_children) {
      // AMP toggle button with [class] binding
      $item_output .= '<button on="tap:AMP.setState({ menuOpen' . $item->ID . ': !menuOpen' . $item->ID . ' })" ';
      $item_output .= '[class]="menuOpen' . $item->ID . ' ? \'fa fa-angle-down active\' : \'fa fa-angle-down\'" ';
      $item_output .= 'aria-label="Toggle Submenu"></button>';
    }

    // Append the output of the menu item to the parent
    $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
  }

  function end_el(&$output, $item, $depth = 0, $args = null)
  {
    $output .= '</li>';
  }
}
