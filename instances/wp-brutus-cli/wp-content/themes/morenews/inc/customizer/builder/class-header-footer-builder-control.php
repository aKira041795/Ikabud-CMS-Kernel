<?php

/**
 * Custom Customizer Control for Header Footer Builder
 */
class Header_Footer_Builder_Control extends WP_Customize_Control
{
  public $type = 'header_footer_builder';

  /**
   * Enqueue control related scripts/styles
   */
  public function enqueue()
  {
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('jquery-ui-draggable');
    wp_enqueue_script('jquery-ui-droppable');
    // The main customizer script localization is now handled in Header_Footer_Builder::enqueue_customizer_scripts()
  }

  /**
   * Render the control's content
   */

  public function render_content()
  {
    $builder_type = strpos($this->id, 'footer') !== false ? 'footer' : 'header';
?>
    <div class="athfb-builder-control" data-builder-type="<?php echo esc_attr($builder_type); ?>">

      <a href="#" class="hide-builder" aria-label="<?php esc_attr_e('Hide builder panel', 'morenews'); ?>">
        <i class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></i>
        <span class="label"><?php esc_html_e('Hide Builder', 'morenews'); ?></span>
      </a>

      <div class="athfb-builder-header">
        <div class="athfb-device-controls" role="group" aria-label="<?php esc_attr_e('Device view controls', 'morenews'); ?>">
          <!-- Future: add accessible device toggle buttons here -->
        </div>
      </div>

      <div class="athfb-builder-content" tabindex="0">
        <div class="athfb-builder-grid" aria-live="polite">
          <?php $this->athfb_render_builder_rows(); ?>
        </div>

        <!-- Add Element Modal -->
        <div id="athfb-add-element-modal"
          class="athfb-modal"
          style="display:none;"
          role="dialog"
          aria-modal="true"
          aria-labelledby="athfb-add-element-title">
          <div class="athfb-modal-content">
            <div class="athfb-modal-header">
              <h3 id="athfb-add-element-title" class="athfb-modal-title">
                <?php _e('Choose an Element', 'morenews'); ?>
              </h3>
              <button type="button" class="athfb-modal-close" tabindex="-1" aria-label="<?php esc_attr_e('Close choose element modal', 'morenews'); ?>">&times;</button>
            </div>
            <div class="athfb-modal-body">
              <div class="athfb-elements-list">
                <?php $this->athfb_render_available_elements(); ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <input type="hidden"
        <?php $this->link(); ?>
        value="<?php echo esc_attr($this->value()); ?>"
        class="athfb-builder-data"
        aria-hidden="true" />

    </div>


<?php
  }



  /**
   * Render Builder Rows
   */
  private function athfb_render_builder_rows()
  {
    $rows = ['top', 'main', 'bottom'];
    $columns = ['left', 'center', 'right'];

    foreach ($rows as $row_id) {
      echo '<div class="athfb-builder-row" data-row="' . esc_attr($row_id) . '">';
      // echo '<div class="athfb-row-label">' . ucfirst($row_id) . '</div>';
      echo '<div class="athfb-row-label"></div>';
      echo '<div class="athfb-row-columns">';

      foreach ($columns as $column_id) {
        $combined_key = $row_id . '_' . $column_id; // e.g., "top_left"

        echo '<div class="athfb-builder-column" data-column="' . esc_attr($combined_key) . '">';
        echo '  <div class="athfb-column-controls">';
        echo '    <div class="athfb-drop-zone-wrapper">';

        echo '      <div class="athfb-drop-zone"  tabindex="-1" data-row="' . esc_attr($row_id) . '" data-column="' . esc_attr($combined_key) . '">';
        echo '        <div class="athfb-elements-wrapper"></div>';
        echo '      </div>';

        echo '      <button class="athfb-add-element-btn" data-row="' . esc_attr($row_id) . '" data-column="' . esc_attr($combined_key) . '">+</button>';

        echo '    </div>';
        echo '  </div>';
        echo '</div>';
      }

      echo '</div>';
      echo '</div>';
    }
  }


  /**
   * Render Available Elements (now renders ALL elements, JS will filter)
   */
  private function athfb_render_available_elements()
  {
    //$builder = Header_Footer_Builder::get_instance();
    // Get ALL registered blocks to render them all into the modal HTML
    $all_blocks = athfb_get_all_registered_blocks();

    foreach ($all_blocks as $block_id => $block_config) {
      echo '<button 
          class="athfb-element-item" 
          tabindex="0" 
          role="button" 
          aria-label="' . esc_attr($block_config['label']) . '" 
          data-element-type="' . esc_attr($block_id) . '"
          data-element-elfocus="' . (isset($block_config['elfocus']) ? esc_attr($block_config['elfocus']) : '') . '"
        >';
      echo '<span class="dashicons ' . esc_attr($block_config['icon']) . '" aria-hidden="true"></span>';
      echo '<span class="element-label">' . esc_html($block_config['label']) . '</span>';
      echo '</button>';
    }
  }
}
