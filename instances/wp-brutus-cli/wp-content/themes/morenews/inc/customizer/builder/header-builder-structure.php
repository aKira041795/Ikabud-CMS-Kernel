<?php
function athfb_render_builder_structure($structure, $context = 'header')
{

  $current_device = athfb_get_current_device();
  $builder_key = $context . '_' . $current_device . '_items';

  if (!isset($structure[$builder_key])) {
    return;
  }


  $select_header_image_mode = morenews_get_option('select_header_image_mode');
  $morenews_class = '';
  $morenews_background = '';
  $inline_style = '';
  if (has_header_image()) {

    if ($select_header_image_mode == 'above') {
      $morenews_class = 'af-header-image';
    } else {
      $morenews_class = 'af-header-image data-bg';
      $morenews_background = get_header_image();
      // Set inline style for background-image
      $inline_style = 'style="background-image: url(' . esc_url($morenews_background) . ');"';
    }
  }
  if (has_header_image()) {

    if ($select_header_image_mode == 'above') {
      $morenews_class = 'af-header-image';
      $morenews_background = '';
    } else {
      $morenews_class = 'af-header-image data-bg';
      $morenews_background = get_header_image();
    }
  }
  $morenews_show_top_header_section = morenews_get_option('show_top_header_section');








  $device_structure = $structure[$builder_key];



  foreach ($device_structure as $row_id => $row_data) {
    if (empty($row_data) || ! athfb_has_elements_in_row($row_data)) {
      continue;
    }


    switch ($row_id) {
      case 'top': ?>
        <div class="top-header">
          <div class="container-wrapper">
            <div class="top-bar-flex">
              <div class="top-bar-left col-3">
                <?php
                if (isset($row_data['top_left'])) {
                  echo '<div class="athfb-column athfb-column-left">';
                  foreach ($row_data['top_left'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id' => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                  echo '</div>';
                }
                ?>
              </div>
              <div class="top-bar-center col-3">
                <?php
                // 🔁 Dynamically render elements in top-left column
                if (isset($row_data['top_center'])) {
                  echo '<div class="athfb-column athfb-column-left">';
                  foreach ($row_data['top_center'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                  echo '</div>';
                }
                ?>

              </div>
              <div class="top-bar-right col-3">

                <?php
                if (isset($row_data['top_right'])) {
                  echo '<div class="athfb-column athfb-column-left">';
                  foreach ($row_data['top_right'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                  echo '</div>';
                }
                ?>

              </div>
            </div>
          </div>
        </div>


      <?php
        break;

      case 'main':
      ?>
        <div class="af-middle-header <?php echo esc_attr($morenews_class); ?>" <?php echo $inline_style; ?>>
          <div class="container-wrapper">
            <?php if (has_header_image() && $select_header_image_mode == 'above') : ?>
              <div class="header-image-above-site-title">
                <img src="<?php echo esc_url(get_header_image()); ?>" alt="<?php echo esc_attr(get_bloginfo('title')); ?>" />
              </div>
            <?php endif; ?>
            <div class="af-middle-container">

              <?php
              if (isset($row_data['main_left'])) {
                echo ' <div class="logo main-bar-left">';
                foreach ($row_data['main_left'] as $element_type) {
                  $element = array(
                    'type' => $element_type,
                    'id'   => $element_type . '_1'
                  );
                  athfb_render_element($element, $context);
                }
                echo ' </div>';
              }



              ?>

              <div class="header-promotion main-bar-center">

                <?php

                //do_action('morenews_action_banner_advertisement');
                if (isset($row_data['main_center'])) {

                  foreach ($row_data['main_center'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                }
                ?>
              </div>


              <div class="main-bar-right">
                <?php
                if (isset($row_data['main_right'])) {

                  foreach ($row_data['main_right'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                }

                ?>
              </div>
            </div>
          </div>
        </div>
        <?php if (!morenews_is_amp()) {
          if (is_active_sidebar('express-off-canvas-panel')) : ?>

            <div id="sidr" class="primary-background">
              <a class="sidr-class-sidr-button-close" aria-label="<?php esc_attr_e('close Off-Canvas Navigation', 'morenews') ?>" href="#sidr" href="#sidr-nav"></a>
              <?php dynamic_sidebar('express-off-canvas-panel'); ?>
            </div>
        <?php endif;
        } ?>
      <?php
        break;

      case 'bottom':
      ?>
        <div id="main-navigation-bar" class="af-bottom-header">
          <div class="container-wrapper">
            <div class="bottom-bar-flex">
              <div class="offcanvas-navigaiton bottom-bar-left">


                <?php
                if (isset($row_data['bottom_left'])) {

                  foreach ($row_data['bottom_left'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                }
                ?>
              </div>
              <div class="bottom-bar-center">
                <?php
                if (isset($row_data['bottom_center'])) {

                  foreach ($row_data['bottom_center'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                }
                ?>
              </div>
              <div class="search-watch bottom-bar-right">
                <?php if (is_active_sidebar('main-navigation-widgets')) : ?>
                  <?php dynamic_sidebar('main-navigation-widgets'); ?>
                <?php endif;

                if (isset($row_data['bottom_right'])) {



                  foreach ($row_data['bottom_right'] as $element_type) {
                    $element = array(
                      'type' => $element_type,
                      'id'   => $element_type . '_1'
                    );
                    athfb_render_element($element, $context);
                  }
                }
                ?>
                <?php //do_action('morenews_dark_and_light_mode'); 
                ?>
                <?php //do_action('morenews_load_search_form'); 
                ?>
                <?php //do_action('morenews_load_watch_online'); 
                ?>
              </div>
            </div>
          </div>
        </div>
<?php
        break;
    }
  }
}
