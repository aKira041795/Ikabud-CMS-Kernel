<?php
class Element_Settings_Container_Control extends WP_Customize_Control
{
  public $type = 'element_settings_container';

  public function render_content()
  {
?>
    <div id="athfb-element-settings-container">
      <div id="athfb-no-element-selected">
        <p>
          <?php echo wp_kses_post(__('Click the setting <span class="dashicons dashicons-admin-generic"></span> on each element to customize its settings.', 'morenews')); ?>
        </p>

      </div>
      <!-- <div id="athfb-element-settings-list" style="display: none;">
        <h4 id="athfb-current-element-title"></h4>
        <div id="athfb-current-element-settings"></div>
      </div> -->
    </div>
<?php
  }
}
