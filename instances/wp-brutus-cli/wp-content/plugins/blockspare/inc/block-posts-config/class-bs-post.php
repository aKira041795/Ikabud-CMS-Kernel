<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

function blockspare_post_query($attributes)
{

  $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;

  if (isset($attributes['categories']) && !empty($attributes['categories']) && is_array($attributes['categories'])) {
    $categories = array();
    $i = 1;
    foreach ($attributes['categories'] as $key => $value) {
      $categories[] = $value['value'];
    }
  } else {
    $categories = array();
  }

  if (isset($attributes['tags']) && !empty($attributes['tags']) && is_array($attributes['tags'])) {
    $tags = array();
    $i = 1;
    foreach ($attributes['tags'] as $key => $value) {
      $tags[] = $value['value'];
    }
  } else {
    $tags = array();
  }

  /* Setup the query */
  $currentID = get_the_ID();
  $query_args = array(
    'posts_per_page' => $attributes['postsToShow'],
    'post_status' => 'publish',
    'order' => $attributes['order'],
    'orderby' => $attributes['orderBy'],
    'offset' => ($attributes['enablePagination'] == 'true') ? '' : $attributes['offset'],
    'post_type' => $attributes['postType'],
    'ignore_sticky_posts' => 1,
    'paged' => $paged,
    'post__not_in' => array($currentID),
  );

  if ('post' != $attributes['postType']) {

    $tax_type = $attributes['taxType'];
    if ($tax_type) {
      if (!empty($categories)) {
        $query_args['tax_query'][] = array(
          'taxonomy' => (isset($tax_type)) ? $tax_type : 'category',
          'field' => 'id',
          'terms' => $categories,
          'operator' => 'IN',
        );
      }
    }
  }

  if ('post' == $attributes['postType']) {

    $tax_type = $attributes['taxType'];
    if ($tax_type) {
      if (!empty($categories)) {
        $query_args['tax_query'][] = array(
          'taxonomy' => (isset($tax_type)) ? $tax_type : 'category',
          'field' => 'id',
          'terms' => $categories,
          'operator' => 'IN',
        );
      } else {

        $taxonomy = $tax_type; // this is the name of the taxonomy
        $terms = get_terms($taxonomy);
        if (!empty($terms)) {
          $query_args['tax_query'][] = array(
            'taxonomy' => (isset($tax_type)) ? $tax_type : 'category',
            'field' => 'slug',
            'terms' => wp_list_pluck($terms, 'slug'),

          );
        }
      }
    }
  }

  if ($attributes['taxType'] == 'category') {
    $query_args['category__in'] = $categories;
  }
  if ($attributes['taxType'] == 'post_tag') {
    $query_args['tag__in'] = $tags;
  }

  if ($attributes['taxType'] != 'category' && $attributes['taxType'] != 'post_tag') {

    $tax_type = $attributes['taxType'];
    if ($tax_type) {
      if (!empty($categories)) {
        $query_args['tax_query'][] = array(
          'taxonomy' => (isset($tax_type)) ? $tax_type : 'category',
          'field' => 'id',
          'terms' => $categories,
          'operator' => 'IN',
        );
      }
    }
  }
  /* Setup the query */
  $grid_query = new WP_Query($query_args);
  return $grid_query;
}

function blockspare_query_loop_and_wrapper($attributes, $blockclass = '', $design = '', $blockName = '', $layoutClass = false)
{
  $unq_class = mt_rand(100000, 999999);
  $blockuniqueclass = '';

  if (!empty($attributes['uniqueClass'])) {
    $blockuniqueclass = $attributes['uniqueClass'];
  } else {
    $blockuniqueclass = 'blockspare-posts-block-list-' . $unq_class;
  }

  $grid_query = blockspare_post_query($attributes);
  if ($grid_query->have_posts()) {
    $alignclass = blockspare_checkalignment($attributes['align']);

    /* Build the block classes */
    $class = "wp-block-blockspare-posts-block-blockspare-posts-block-latest-posts align" . $alignclass . " " . $attributes['blockHoverEffect'];

    if (isset($attributes['className'])) {
      $class .= ' ' . $attributes['className'];
    }

    if ($attributes['animation']) {
      $class .= ' blockspare-block-animation';
    }

    $list_layout_class = $design;
    $listgridClass = $blockclass . " ";

    // if($blockName=='' ){
    //     $listgridClass .= "column-" . $attributes['columns'];
    // }

    /* Layout orientation class */
    $grid_class = 'blockspare-posts-block-latest-post-wrap ' . $listgridClass . ' ' . $list_layout_class;
    $class .= ' ' . $blockuniqueclass;
    $category_class = 'blockspare-posts-block-post-category';

    if ($attributes['categoryLayoutOption'] == 'none') {
      $category_class .= ' has-no-category-style';
    } ?>
    <!-- <div class="<?php echo esc_attr($class); ?>" blockspare-animation="<?php echo esc_attr($attributes['animation']); ?>"> -->
    <section class="blockspare-posts-block-post-wrap">
      <div class="<?php echo esc_attr($grid_class); ?>">
        <?php while ($grid_query->have_posts()) {
          $grid_query->the_post();

          /* Setup the post ID */
          $post_id = get_the_ID();

          /* Setup the featured image ID */
          $post_thumb_id = get_post_thumbnail_id($post_id);

          $has_img_class = '';

          if (!$post_thumb_id) {
            $has_img_class = "post-has-no-image";
          }

          // if(isset($attributes['full'])){
          //     if($attributes['full']== 'blockspare-posts-block-full-layout-4' ){
          //         $attributes['enableEqualHeight'] = false;
          //     }

          // }

          if ($attributes['enableEqualHeight']) {
            $has_img_class .= ' bs-has-equal-height';
          }
          $contentOrderClass = '';
          if ($attributes['contentOrder'] == 'content-order-1') {
            $contentOrderClass .= 'contentorderone';
          }
          if ($attributes['contentOrder'] == 'content-order-2') {
            $contentOrderClass .= 'contentordertwo';
          }

          /* Setup the post classes */
          $post_classes = 'blockspare-posts-block-post-single blockspare-hover-item ' . $contentOrderClass;

          if ($layoutClass) {
            $post_classes .= ' has-background';
          } else {
            $post_classes .= ' blockspare-hover-child';
          }

          $className = '';

          if (isset($attributes['full'])) {
            if ($attributes['full'] == 'blockspare-posts-block-full-layout-6') {
              $post_classes .= ' blockspare-hover-child';
              $className .= 'hover-child';
            }
          }

          /* Add sticky class */
          if (is_sticky($post_id)) {
            $post_classes .= ' sticky';
          } else {
            $post_classes .= null;
          }
          $post_classes .= ' has-background';
        ?>
          <div id="<?php echo esc_attr($post_id); ?>" class="<?php echo esc_attr($post_classes) . ' ' . $has_img_class; ?>">
            <?php blockspare_post_image($attributes, $post_id, $category_class, $blockName, $className); ?>
            <?php blockspare_post_content($attributes, $post_id, $category_class, $blockName, $className); ?>
          </div>

        <?php
        } ?>

      </div>
      <?php
      if ($attributes['enablePagination'] == 'true' && $grid_query->max_num_pages > 1) {
        $loadmore_class = '';
        if ($attributes['loadMoreStyle']) {
          $loadmore_class = $attributes['loadMoreStyle'];
        }
        if ($attributes['loadMoreAlignment']) {
          $loadmore_class .= ' bs_blockspare_loadmore_' . $attributes['loadMoreAlignment'];
        }
      ?>
        <div class="bs_blockspare_loadmore blockspare-readmore-wrapper <?php echo esc_attr($loadmore_class); ?>" data-layout="<?php echo $layoutClass; ?>" block-name="<?php echo esc_attr($blockName) ?>" data-page='2' blockspare-att=<?php echo "'" . esc_attr(wp_json_encode($attributes)) . "'"; ?> max-paged="<?php echo $grid_query->max_num_pages; ?>">
          <a href="#" class="blockspare-readmore">
            <div class="load-btn "><?php echo ($attributes['loadMoreText']); ?><span class="ajax-loader"></span></div>
          </a>
        </div><?php
            }
              ?>
    </section>
    <!-- </div> -->
  <?php
  }

  wp_reset_postdata();
}

function blockspare_post_image($attributes, $post_id, $cat_class, $blockName = '')
{

  /* Setup the featured image ID */
  $post_thumb_id = get_post_thumbnail_id($post_id);

  if (!empty($attributes['imageSize'])) {
    $post_thumb_size = $attributes['imageSize'];
  }

  $content_order = false;
  if ($attributes['contentOrder'] == 'content-order-1' || $attributes['contentOrder'] == 'content-order-2') {
    $content_order = true;
  }
  if ($blockName == 'full') {
    $content_order = true;
  }

  if (!empty($attributes['imageSize'])) {
    $post_thumb_size = $attributes['imageSize'];
  }

  if (isset($attributes['enableFeatureImage']) && $attributes['enableFeatureImage'] == 'true') {
  ?>
    <figure class="blockspare-posts-block-post-img hover-child">
      <a href="<?php echo esc_url(get_permalink($post_id)); ?>" rel="bookmark" aria-hidden="true"
        tabindex="-1">
        <?php
        if (has_post_thumbnail($post_id)) {

          echo wp_kses_post(wp_get_attachment_image($post_thumb_id, $post_thumb_size));
        } else { ?>
          <div class="bs-no-thumbnail-img"> </div>
        <?php } ?>
      </a>
      <?php if ($attributes['displayPostCategory'] == 'true' && $content_order == true) { ?>
        <!-- Category     -->
        <div class="<?php echo esc_attr($cat_class); ?>">
          <?php

          blockspare_get_cat_tax_tags($attributes['taxType'], $post_id, $attributes['postType']); ?>
        </div>
        <!-- Category     -->
      <?php } ?>
    </figure>
  <?php
  }
}

function blockspare_post_content($attributes, $post_id, $cat_class, $blockName = '', $className = '')
{
  $content_order = false;
  if ($attributes['contentOrder'] == 'content-order-1' || $attributes['contentOrder'] == 'content-order-2') {
    $content_order = true;
  }
  if ($blockName == 'full') {
    $content_order = false;
  }
  ?>
  <div class="blockspare-posts-block-post-content <?php echo esc_attr($className); ?> <?php echo esc_attr($attributes['contentOrder']); ?> <?php echo esc_attr($attributes['titleOnHover']) ?>">
    <div class="blockspare-posts-block-bg-overlay"></div>
    <!-- blockspare-posts-block-post-grid-header -->
    <header class="blockspare-posts-block-post-grid-header">
      <!--display category when feature image is disable -->
      <?php if ($attributes['displayPostCategory'] == 'true' && $attributes['enableFeatureImage'] != 'true') { ?>
        <!-- Category     -->
        <div class="<?php echo esc_attr($cat_class); ?>">
          <?php blockspare_get_cat_tax_tags($attributes['taxType'], $post_id, $attributes['postType']); ?>
        </div>
        <!-- Category     -->
      <?php } ?>
      <!--display category when feature image is enable-->
      <?php if ($attributes['displayPostCategory'] == 'true' && $attributes['enableFeatureImage'] == 'true' && $content_order == false) { ?>
        <!-- Category     -->
        <div class="<?php echo esc_attr($cat_class); ?>">
          <?php blockspare_get_cat_tax_tags($attributes['taxType'], $post_id, $attributes['postType']); ?>
        </div>
        <!-- Category     -->
      <?php } ?>
      <!-- blockspare-posts-block-post-grid-title -->

      <h4 class="blockspare-posts-block-post-grid-title">
        <a href="<?php echo esc_url(get_permalink($post_id)); ?>" class="blockspare-posts-block-title-link"
          rel="bookmark">
          <span><?php echo get_the_title(); ?></span>
        </a>
      </h4>

      <!-- blockspare-posts-block-post-grid-title -->

      <!-- blockspare-posts-block-post-grid-byline -->
      <div class="blockspare-posts-block-post-grid-byline">
        <!-- blockspare-posts-block-post-grid-author -->
        <?php if (isset($attributes['displayPostAuthor']) && $attributes['displayPostAuthor'] == 'true') { ?>
          <div class="blockspare-posts-block-post-grid-author">
            <!-- <a class="blockspare-posts-block-text-link" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>"
                                        itemprop="url" rel="author">
                                        <span itemprop="name">
                                            <i class="<?php echo esc_attr($attributes['authorIcon']); ?>"></i>
                                            <?php echo esc_html(get_the_author_meta('display_name', get_the_author_meta('ID'))); ?>
                                        </span>
                                    </a> -->
            <?php
            $author_id = get_post_field('post_author', $post_id);
            $blockspare_get_multiauthor = new BlocksapreMultiAuthorForFrontend();
            $blockspare_get_multiauthor->blockspare_front_by_author($post_id, $attributes['authorIcon'], $author_id);
            ?>
          </div>
        <?php } ?>
        <!-- blockspare-posts-block-post-grid-author -->

        <!-- blockspare-posts-block-post-grid-date -->
        <?php if (isset($attributes['displayPostDate']) && $attributes['displayPostDate'] == 'true') { ?>
          <time datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>" class="blockspare-posts-block-post-grid-date" itemprop="datePublished"><i class="<?php echo esc_attr($attributes['dateIcon']); ?>"></i><?php echo esc_html(get_the_date('', $post_id)); ?></time>
        <?php } ?>
        <!-- blockspare-posts-block-post-grid-date -->

        <!-- comment_count -->
        <?php if ($attributes['enableComment'] == 'true') { ?>
          <span class="comment_count"><i class='<?php echo esc_attr($attributes['commentIcon']); ?>'></i><?php echo esc_html(get_comments_number($post_id)); ?></span>
        <?php } ?>
        <!-- comment_count -->
      </div>
      <!-- blockspare-posts-block-post-grid-byline -->
    </header>
    <!-- blockspare-posts-block-post-grid-header -->

    <?php
    $excerpt = blockspare_excerpt($post_id, $attributes['excerptLength']);
    $new_excerpt = apply_filters('the_excerpt', $excerpt); ?>
    <!-- blockspare-posts-block-post-grid-excerpt -->
    <?php if ($attributes['displayPostExcerpt'] == 'true' && $new_excerpt != null) { ?>
      <div class="blockspare-posts-block-post-grid-excerpt">
        <!-- blockspare-posts-block-post-grid-excerpt-content -->
        <?php if (isset($attributes['displayPostExcerpt']) && $attributes['displayPostExcerpt'] == 'true') { ?>
          <div class="blockspare-posts-block-post-grid-excerpt-content">
            <?php echo wp_kses_post($excerpt); ?>
          </div>
        <?php } ?>
        <!-- blockspare-posts-block-post-grid-excerpt-content -->
        <!-- blockspare-posts-block-post-grid-more-link -->
        <?php if (isset($attributes['displayPostLink']) && $attributes['displayPostLink'] == 'true') { ?>
          <p>
            <a class="blockspare-posts-block-post-grid-more-link blockspare-posts-block-text-link"
              href="<?php echo esc_url(get_permalink($post_id)); ?>" rel="bookmark">
              <span><?php echo esc_html($attributes['readMoreText']); ?></span>
            </a>
          </p>
        <?php } ?>
        <!-- blockspare-posts-block-post-grid-more-link -->
      </div>
    <?php } ?>
    <!-- blockspare-posts-block-post-grid-excerpt -->

  </div>
<?php
}

function bscheckFontfamily($fontFamily)
{

  $fonts = '';
  if ($fontFamily != 'Default' && $fontFamily != "undefined") {
    $fonts = 'font-family:' . $fontFamily;
  }
  return $fonts;
}

function bscheckFontfamilyWeight($fontFamilyWeight)
{

  $fontsweight = '';
  if ($fontFamilyWeight != '' && $fontFamilyWeight != "undefined") {
    $fontsweight = 'font-weight:' . $fontFamilyWeight;
  }
  return $fontsweight;
}

function blockspare_excerpt($post_id, $length = '')
{
  $excerpt = get_post_field(
    'post_excerpt',
    $post_id,
    'display'
  );

  $excerpt = preg_replace('~^(\s*(?:&nbsp;)?)*~i', '', $excerpt);

  if (empty($excerpt)) {
    $excerpt = preg_replace(
      array(
        '/\<figcaption>.*\<\/figcaption>/',
        '/\[caption.*\[\/caption\]/',
        '`[[^]]*]`',
      ),
      '',
      get_the_content()
    );
  }

  // Trim the excerpt if necessary.
  if (isset($length)) {
    $excerpt = wp_trim_words(
      $excerpt,
      $length
    );
  }

  return $excerpt;
}
