<?php

/**
 * The template for displaying the footer
 *
 * Contains the closing of the #content div and all content after.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package MoreNews
 */

$sidebar_col = 0;
if (is_active_sidebar('footer-first-widgets-section')) {
  $sidebar_col += 1;
}

if (is_active_sidebar('footer-second-widgets-section')) {
  $sidebar_col += 1;
}

if (is_active_sidebar('footer-third-widgets-section')) {
  $sidebar_col += 1;
}

$sidebar_col_class = 'aft-footer-sidebar-col-' . $sidebar_col;

$morenews_footer_background = morenews_get_option('footer_background_image');
$morenews_footer_background_url = '';
if (!empty($morenews_footer_background)) {

  $morenews_footer_background = absint($morenews_footer_background);
  $morenews_footer_background_url = wp_get_attachment_url($morenews_footer_background);

  $sidebar_col_class .= ' data-bg';
}


?>

<?php // Get the current page template
$page_template = get_page_template_slug();
if ($page_template !== 'page-templates/full-width.php') {
?>
  </div>



  <?php do_action('morenews_action_full_width_upper_footer_section'); ?>
<?php } ?>
<footer class="site-footer <?php echo esc_attr($sidebar_col_class); ?>" data-background="<?php echo esc_attr($morenews_footer_background_url); ?>">
  <?php
  $show_footer_checkbox = morenews_get_option('athfb_show_checkbox_footer');

  if ($show_footer_checkbox) {


    athfb_render_footer_builder();
  } else {
    if (is_active_sidebar('footer-first-widgets-section') || is_active_sidebar('footer-second-widgets-section') || is_active_sidebar('footer-third-widgets-section')) : ?>
      <div class="primary-footer">
        <div class="container-wrapper">
          <div class="af-container-row">
            <?php if (is_active_sidebar('footer-first-widgets-section')) : ?>
              <div class="primary-footer-area footer-first-widgets-section col-3 float-l pad">
                <section class="widget-area color-pad">
                  <?php dynamic_sidebar('footer-first-widgets-section'); ?>
                </section>
              </div>
            <?php endif; ?>

            <?php if (is_active_sidebar('footer-second-widgets-section')) : ?>
              <div class="primary-footer-area footer-second-widgets-section  col-3 float-l pad">
                <section class="widget-area color-pad">
                  <?php dynamic_sidebar('footer-second-widgets-section'); ?>
                </section>
              </div>
            <?php endif; ?>

            <?php if (is_active_sidebar('footer-third-widgets-section')) : ?>
              <div class="primary-footer-area footer-third-widgets-section  col-3 float-l pad">
                <section class="widget-area color-pad">
                  <?php dynamic_sidebar('footer-third-widgets-section'); ?>
                </section>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (1 != morenews_get_option('hide_footer_menu_section')) : ?>
      <?php if (has_nav_menu('aft-footer-nav') || has_nav_menu('aft-social-nav')) :
        $class = 'col-1';
        if (has_nav_menu('aft-footer-nav') && has_nav_menu('aft-social-nav')) {
          $class = 'col-2';
        }

      ?>
        <div class="secondary-footer">
          <div class="container-wrapper">
            <div class="af-container-row af-flex-container">
              <?php if (has_nav_menu('aft-footer-nav')) : ?>
                <div class="pad color-pad <?php echo esc_attr($class); ?>">
                  <div class="footer-nav-wrapper">
                    <?php
                    wp_nav_menu(array(
                      'theme_location' => 'aft-footer-nav',
                      'menu_id' => 'footer-menu',
                      'depth' => 1,
                      'container' => 'div',
                      'container_class' => 'footer-navigation'
                    )); ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if (has_nav_menu('aft-social-nav')) : ?>
                <div class="pad color-pad <?php echo esc_attr($class); ?>">
                  <div class="footer-social-wrapper">
                    <div class="aft-small-social-menu">
                      <?php
                      wp_nav_menu(array(
                        'theme_location' => 'aft-social-nav',
                        'link_before' => '<span class="screen-reader-text">',
                        'link_after' => '</span>',
                        'container' => 'div',
                        'container_class' => 'social-navigation'
                      ));
                      ?>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="site-info">
      <div class="container-wrapper">
        <!-- <div class="af-container-row"> -->
        <div class="col-1 color-pad">
          <?php $morenews_copy_right = morenews_get_option('footer_copyright_text'); ?>
          <?php if (!empty($morenews_copy_right)) : ?>
            <?php echo esc_html($morenews_copy_right); ?>
          <?php endif; ?>
          <?php $morenews_theme_credits = morenews_get_option('hide_footer_copyright_credits'); ?>
          <?php if ($morenews_theme_credits != 1) : ?>
            <span class="sep"> | </span>
            <?php
            /* translators: 1: Theme name, 2: Theme author. */
            printf(esc_html__('%1$s by %2$s.', 'morenews'), '<a href="https://afthemes.com/products/morenews/" target="_blank">MoreNews</a>', 'AF themes');
            ?>
          <?php endif; ?>
        </div>
        <!-- </div> -->
      </div>
    </div>
  <?php } ?>
</footer>
</div>

<?php $morenews_scroll_to_top_position = morenews_get_option('global_scroll_to_top_position');
if ($morenews_scroll_to_top_position != 'none') :
?>

  <a id="scroll-up" class="secondary-color <?php echo esc_attr($morenews_scroll_to_top_position); ?>">
  </a>
<?php endif; ?>
<?php wp_footer(); ?>

</body>

</html>