<?php
if (!function_exists('morenews_banner_exclusive_posts')):
  /**
   * Ticker Slider
   *
   * @since MoreNews 1.0.0
   *
   */
  function morenews_banner_exclusive_posts()
  {



    if (false != morenews_get_option('show_popular_tags_section')) : ?>
      <div class="aft-popular-tags">
        <div class="container-wrapper">
          <?php

          $morenews_show_popular_tags_title = morenews_get_option('frontpage_popular_tags_section_title');
          $morenews_select_popular_tags_mode = morenews_get_option('select_popular_tags_mode');
          $morenews_number_of_popular_tags = morenews_get_option('number_of_popular_tags');


          morenews_list_popular_taxonomies($morenews_select_popular_tags_mode, $morenews_show_popular_tags_title, $morenews_number_of_popular_tags);


          ?>
        </div>
      </div>
    <?php endif;


    if (false != morenews_get_option('show_flash_news_section')) :

      $morenews_em_ticker_news_mode = 'aft-flash-slide left';
      $morenews_dir = 'left';
      if (is_rtl()) {
        $morenews_em_ticker_news_mode = 'aft-flash-slide right';
        $morenews_dir = 'right';
      }
    ?>
      <div class="banner-exclusive-posts-wrapper">

        <?php
        $morenews_categories = morenews_get_option('select_flash_news_category');
        $morenews_number_of_posts = morenews_get_option('number_of_flash_news');
        $morenews_em_ticker_news_title = morenews_get_option('flash_news_title');

        $morenews_all_posts = morenews_get_posts($morenews_number_of_posts, $morenews_categories);
        $morenews_show_trending = true;
        $morenews_count = 1;
        ?>

        <div class="container-wrapper">
          <div class="exclusive-posts">
            <div class="exclusive-now primary-color">
              <div class="aft-box-ripple">
                <div class="box1"></div>
                <div class="box2"></div>
                <div class="box3"></div>
                <div class="box4"></div>
              </div>
              <?php if (!empty($morenews_em_ticker_news_title)): ?>
                <span><?php echo esc_html($morenews_em_ticker_news_title); ?></span>
              <?php endif; ?>
            </div>
            <div class="exclusive-slides" dir="ltr">
              <?php
              if ($morenews_all_posts->have_posts()) : ?>
                <div class='marquee <?php echo esc_attr($morenews_em_ticker_news_mode); ?>' data-speed='80000'
                  data-gap='0' data-duplicated='true' data-direction="<?php echo esc_attr($morenews_dir); ?>">
                  <?php

                  while ($morenews_all_posts->have_posts()) : $morenews_all_posts->the_post();
                    global $post;
                    $morenews_img_url = morenews_get_freatured_image_url($post->ID, 'thumbnail');
                    $morenews_img_thumb_id = get_post_thumbnail_id($post->ID);
                  ?>
                    <a href="<?php the_permalink(); ?>" aria-label="<?php echo the_title(); ?>">
                      <?php if ($morenews_show_trending == true): ?>

                      <?php endif; ?>

                      <span class="circle-marq">

                        <?php morenews_the_post_thumbnail('thumbnail', $post->ID); ?>
                      </span>

                      <?php the_title(); ?>
                    </a>
                <?php
                    $morenews_count++;
                  endwhile;
                endif;
                wp_reset_postdata();
                ?>
                </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Excluive line END -->
<?php

    endif;
  }
endif;

add_action('morenews_action_banner_exclusive_posts', 'morenews_banner_exclusive_posts', 10);
