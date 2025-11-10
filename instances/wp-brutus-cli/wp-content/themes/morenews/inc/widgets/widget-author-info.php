<?php
if (!class_exists('MoreNews_author_info')) :
  /**
   * Adds MoreNews_author_info widget.
   */
  class MoreNews_author_info extends MoreNews_Widget_Base
  {
    /**
     * Sets up a new widget instance.
     *
     * @since 1.0.0
     */
    function __construct()
    {
      $this->text_fields = array('morenews-author-info-title', 'morenews-author-info-subtitle', 'morenews-author-info-image', 'morenews-author-info-name', 'morenews-author-info-desc', 'morenews-author-info-phone', 'morenews-author-info-email');
      $this->url_fields = array('morenews-author-info-facebook', 'morenews-author-info-twitter', 'morenews-author-info-linkedin', 'morenews-author-info-instagram', 'morenews-author-info-vk', 'morenews-author-info-youtube', 'morenews-author-info-tiktok');

      $widget_ops = array(
        'classname' => 'morenews_author_info_widget aft-widget',
        'description' => __('Displays author info.', 'morenews'),
        'customize_selective_refresh' => false,
      );

      parent::__construct('morenews_author_info', __('AFTMN Author Info', 'morenews'), $widget_ops);
    }

    /**
     * Front-end display of widget.
     *
     * @see WP_Widget::widget()
     *
     * @param array $args Widget arguments.
     * @param array $instance Saved values from database.
     */

    public function widget($args, $instance)
    {

      $morenews_featured_news_title = apply_filters('widget_title', $instance['morenews-author-info-title'], $instance, $this->id_base);


      $profile_image = isset($instance['morenews-author-info-image']) ? ($instance['morenews-author-info-image']) : '';

      if ($profile_image) {
        $image_attributes = wp_get_attachment_image_src($profile_image, 'large');
        $image_src = $image_attributes[0];
        $image_class = 'data-bg data-bg-hover';
      } else {
        $image_src = '';
        $image_class = 'no-bg';
      }

      $name = isset($instance['morenews-author-info-name']) ? ($instance['morenews-author-info-name']) : '';

      $desc = isset($instance['morenews-author-info-desc']) ? ($instance['morenews-author-info-desc']) : '';
      $facebook = isset($instance['morenews-author-info-facebook']) ? ($instance['morenews-author-info-facebook']) : '';
      $twitter = isset($instance['morenews-author-info-twitter']) ? ($instance['morenews-author-info-twitter']) : '';
      $youtube = isset($instance['morenews-author-info-youtube']) ? ($instance['morenews-author-info-youtube']) : '';


      echo $args['before_widget'];
?>
      <section class="aft-blocks af-author-info pad-v">
        <div class="af-author-info-wrap">
          <?php if (!empty($morenews_featured_news_title)): ?>
            <?php morenews_render_section_title($morenews_featured_news_title); ?>
          <?php endif; ?>
          <div class="widget-block widget-wrapper af-widget-body">
            <div class="posts-author-wrapper">

              <?php if (!empty($image_src)) : ?>


                <figure class="read-img af-author-img">
                  <img src="<?php echo esc_attr($image_src); ?>" alt="" />
                </figure>

              <?php endif; ?>
              <div class="af-author-details">
                <?php if (!empty($name)) : ?>
                  <h3 class="af-author-display-name"><?php echo esc_html($name); ?></h3>
                <?php endif; ?>
                <?php if (!empty($desc)) : ?>
                  <p class="af-author-display-name"><?php echo esc_html($desc); ?></p>
                <?php endif; ?>

                <?php if (!empty($facebook) || !empty($twitter) || !empty($youtube)) : ?>
                  <div class="social-navigation aft-small-social-menu">
                    <ul>
                      <?php if (!empty($facebook)) : ?>
                        <li>
                          <a href="<?php echo esc_url($facebook); ?>" target="_blank" aria-label="Facebook"></a>
                        </li>
                      <?php endif; ?>

                      <?php if (!empty($twitter)) : ?>
                        <li>
                          <a href="<?php echo esc_url($twitter); ?>" target="_blank" aria-label="Twitter"></a>
                        </li>
                      <?php endif; ?>

                      <?php if (!empty($youtube)) : ?>
                        <li>
                          <a href="<?php echo esc_url($youtube); ?>" target="_blank" aria-label="Youtube"></a>
                        </li>
                      <?php endif; ?>




                    </ul>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
      </section>
<?php

      // close the widget container
      echo $args['after_widget'];
    }

    /**
     * Back-end widget form.
     *
     * @see WP_Widget::form()
     *
     * @param array $instance Previously saved values from database.
     */
    public function form($instance)
    {
      $this->form_instance = $instance;
      $categories = morenews_get_terms();


      if (isset($categories) && !empty($categories)) {
        // generate the text input for the title of the widget. Note that the first parameter matches text_fields array entry
        echo parent::morenews_generate_text_input('morenews-author-info-title', __('About Author', 'morenews'), __('Title', 'morenews'));

        echo parent::morenews_generate_image_upload('morenews-author-info-image', __('Profile image', 'morenews'), __('Profile image', 'morenews'));
        echo parent::morenews_generate_text_input('morenews-author-info-name', __('Name', 'morenews'), __('Name', 'morenews'));
        echo parent::morenews_generate_text_input('morenews-author-info-desc', __('Descriptions', 'morenews'), '');
        echo parent::morenews_generate_text_input('morenews-author-info-facebook', __('Facebook', 'morenews'), '');
        echo parent::morenews_generate_text_input('morenews-author-info-twitter', __('Twitter', 'morenews'), '');
        echo parent::morenews_generate_text_input('morenews-author-info-youtube', __('YouTube', 'morenews'), '');
      }
    }
  }
endif;
