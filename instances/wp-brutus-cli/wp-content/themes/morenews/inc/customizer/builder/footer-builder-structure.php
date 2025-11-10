<?php
function athfb_render_footer_structure($structure, $context = 'footer')
{
  $current_device = athfb_get_current_device();
  $builder_key = "{$context}_{$current_device}_items";

  if (empty($structure[$builder_key])) {
    return;
  }

  $device_structure = $structure[$builder_key];

  foreach ($device_structure as $row_id => $row_data) {
    if (empty($row_data) || !athfb_has_elements_in_row($row_data)) {
      continue;
    }

    switch ($row_id) {
      case 'top':
        athfb_render_footer_top_row($row_data, $context);
        break;

      case 'main':
        athfb_render_footer_main_row($row_data, $context);
        break;

      case 'bottom':
        athfb_render_footer_bottom_row($row_data, $context);
        break;
    }
  }
}

function athfb_render_footer_top_row($row_data, $context)
{
  $sidebars_active = is_active_sidebar('footer-first-widgets-section') ||
    is_active_sidebar('footer-second-widgets-section') ||
    is_active_sidebar('footer-third-widgets-section');

  if (!$sidebars_active) {
    return;
  }

?>
  <div class="primary-footer">
    <div class="container-wrapper">
      <div class="af-container-row">
        <?php
        athfb_render_top_footer_column('top_left', 'footer-first-widgets-section', $row_data, $context);
        athfb_render_top_footer_column('top_center', 'footer-second-widgets-section', $row_data, $context);
        athfb_render_top_footer_column('top_right', 'footer-third-widgets-section', $row_data, $context);
        ?>
      </div>
    </div>
  </div>
<?php
}

function athfb_render_top_footer_column($position, $section_class, $row_data, $context)
{


  echo "<div class=\"primary-footer-area {$section_class} col-3 float-l pad\">";
  echo '<section class="widget-area color-pad">';

  foreach ($row_data[$position] as $element_type) {
    $element = [
      'type' => $element_type,
      'id'   => "{$element_type}_1",
    ];
    athfb_render_element($element, $context);
  }

  echo '</section>';
  echo '</div>';
}
function   athfb_render_footer_main_row($row_data, $context)
{ ?>
  <div class="secondary-footer">
    <div class="container-wrapper">
      <div class="af-container-row af-flex-container">


        <?php
        athfb_render_main_footer_column('main_left',  $row_data, $context);
        athfb_render_main_footer_column('main_center', $row_data, $context);
        athfb_render_main_footer_column('main_right', $row_data, $context);
        ?>

      </div>
    </div>
  </div>

<?php
}

function athfb_render_main_footer_column($position,  $row_data, $context)
{


  $kebab = preg_replace('/_/', '-', $position);
  echo ' <div class="footer-' . $kebab . '">';
  echo '<div class="footer-nav-wrapper">';
  foreach ($row_data[$position] as $element_type) {
    $element = [
      'type' => $element_type,
      'id'   => "{$element_type}_1",
    ];
    athfb_render_element($element, $context);
  }
  echo '</div>';
  echo '</div>';
}

function athfb_render_footer_bottom_row($row_data, $context)
{ ?>
  <div class="site-info">
    <div class="container-wrapper">
      <div class="af-container-row af-flex-container">



        <?php
        athfb_render_bottom_footer_column('bottom_left',  $row_data, $context);
        athfb_render_bottom_footer_column('bottom_center', $row_data, $context);
        athfb_render_bottom_footer_column('bottom_right', $row_data, $context);
        ?>


      </div>

    </div>
  </div>
<?php
}

function athfb_render_bottom_footer_column($position,  $row_data, $context)
{

  $kebab = preg_replace('/_/', '-', $position);
  echo '   <div class="footer-' . $kebab . '">';
  foreach ($row_data[$position] as $element_type) {
    $element = [
      'type' => $element_type,
      'id'   => "{$element_type}_1",
    ];
    athfb_render_element($element, $context);
  }
  echo '</div>';
}
