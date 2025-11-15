<?php

/**
 * Custom template images for this theme
 *
 * Handles post thumbnails with optimized alt text for SEO and accessibility.
 *
 * @package MoreNews
 */

if (!function_exists('morenews_post_thumbnail')) :
  /**
   * Displays an optional post thumbnail.
   * For single views, the image is wrapped in a div, while in index views it is wrapped in an anchor.
   */
  function morenews_post_thumbnail()
  {
    if (post_password_required() || is_attachment() || !has_post_thumbnail()) {
      return;
    }

    global $post;

    if (is_singular()) :
      $morenews_theme_class = morenews_get_option('global_image_alignment');
      $morenews_post_image_alignment = get_post_meta($post->ID, 'morenews-meta-image-options', true);
      $morenews_post_class = !empty($morenews_post_image_alignment) ? $morenews_post_image_alignment : $morenews_theme_class;

      if ($morenews_post_class != 'no-image') :
        $single_featured_image_view = morenews_get_option('single_featured_image_view');
?>
        <div class="post-thumbnail <?php echo esc_attr($morenews_post_class); ?> <?php echo esc_attr($single_featured_image_view); ?>">
          <?php echo morenews_the_post_thumbnail('full', $post->ID); ?>
          <?php
                                    $thumbnail_id = get_post_thumbnail_id();
                                    if ($thumbnail_id) {
                                        $thumbnail_post = get_post($thumbnail_id);
                                        if ($thumbnail_post && !empty(trim($thumbnail_post->post_excerpt))) {
                                    ?>
                                            <span class="aft-image-caption">
                                                <p><?php echo esc_html($thumbnail_post->post_excerpt); ?></p>
                                            </span>
                                    <?php
                                        }
                                    }
                                    ?>
        </div>
      <?php endif; ?>

    <?php else :
      $morenews_archive_layout = morenews_get_option('archive_layout');
      $morenews_archive_image = ($morenews_archive_layout == 'archive-layout-list') ? 'medium' : (($morenews_archive_layout == 'archive-layout-full') ? 'morenews-medium' : 'post-thumbnail');
      $morenews_archive_class = ($morenews_archive_layout == 'archive-layout-list') ? morenews_get_option('archive_image_alignment') : '';

    ?>
      <div class="post-thumbnail <?php echo esc_attr($morenews_archive_class); ?>">
        <a href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr(get_the_title($post->ID, 'morenews')); ?>" aria-hidden="true">
          <?php echo morenews_the_post_thumbnail($morenews_archive_image, $post->ID); ?>
        </a>
      </div>
      <?php endif;
  }
endif;

if (!function_exists('morenews_the_post_thumbnail')) :
  /**
   * Fetches post thumbnail or first image from post content.
   *
   * @param string $morenews_thumbnail_size The thumbnail size.
   * @param int    $morenews_post_id The post ID.
   * @param bool   $return Whether to return the image HTML or echo it.
   * @return string|void Image HTML if $return is true.
   */
  function morenews_the_post_thumbnail($morenews_thumbnail_size, $morenews_post_id, $return = false)
  {

    $morenews_fetch_content_image = morenews_get_option('global_fetch_content_image_setting');
    if ($morenews_fetch_content_image == 'enable') {
      if (has_post_thumbnail($morenews_post_id)) {
        if ($return) {
          return get_the_post_thumbnail($morenews_post_id, $morenews_thumbnail_size);
        } else {
          the_post_thumbnail($morenews_thumbnail_size);
        }
      } else {
        // Fallback to first image in content if no thumbnail is set
        // $morenews_post_content = get_post_field('post_content', $morenews_post_id);
        // $output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $morenews_post_content, $matches);

        $morenews_post_content = substr(get_post_field('post_content', $morenews_post_id), 0, 3000);
        $output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $morenews_post_content, $matches);


        if (isset($matches[1][0])) {

          $morenews_img_id = morenews_find_post_id_from_path($matches[1][0]);
          $morenews_img_url = wp_get_attachment_image_src($morenews_img_id, $morenews_thumbnail_size);

          if (isset($morenews_img_url[0])) {
            if ($return) {
              return wp_get_attachment_image($morenews_img_id, $morenews_thumbnail_size);
            } else {
              echo wp_kses_post(wp_get_attachment_image($morenews_img_id, $morenews_thumbnail_size));
            }
          } else {
            // Check if external image URL is valid and display it
            if (@getimagesize($matches[1][0])) {
              if ($return) {
                ob_start();
      ?>
                <img src="<?php echo esc_url($matches[1][0]); ?>" alt="<?php echo esc_attr(basename($matches[1][0])); ?>" />
              <?php
                $morenews_img_html = ob_get_contents();
                ob_end_clean();
                return $morenews_img_html;
              } else {
              ?>
                <img src="<?php echo esc_url($matches[1][0]); ?>" alt="<?php echo esc_attr(basename($matches[1][0])); ?>" />
<?php
              }
            }
          }
        }
      }
    } else {
      if ($return) {
        return get_the_post_thumbnail($morenews_post_id, $morenews_thumbnail_size);
      } else {
        the_post_thumbnail($morenews_thumbnail_size);
      }
    }
  }
endif;

if (!function_exists('morenews_find_post_id_from_path')) :
  /**
   * Find the post ID for a file PATH or URL
   *
   * @param string $path The file path or URL.
   * @return int The post ID.
   */
  function morenews_find_post_id_from_path($path)
  {
    // Strip size info from image URL if it exists (e.g., -300x300.jpg)
    if (preg_match('/(-\d{1,4}x\d{1,4})\.(jpg|jpeg|png|gif|webp)$/i', $path, $matches)) {
      $path = str_ireplace($matches[1], '', $path);
    }

    // Ensure path includes only the relevant portion of the file URL
    if (preg_match('/uploads\/(\d{1,4}\/)?(\d{1,2}\/)?(.+)$/i', $path, $matches)) {
      unset($matches[0]);
      $path = implode('', $matches);
    }

    // Get post ID from URL
    return attachment_url_to_postid($path);
  }
endif;

/**
 * Optimized alt text function for images to enhance SEO and accessibility.
 *
 * @param array $attributes Image attributes.
 * @param WP_Post $attachment Attachment object.
 * @param string $size Size of the image.
 * @return array Updated image attributes.
 */
function morenews_alt_text_optimized($attributes, $attachment, $size)
{
  // Check if alt text is missing
  if (empty($attributes['alt'])) {
    // Use the attachment title as alt text if available
    if (!empty($attachment->post_title)) {
      $attributes['alt'] = esc_attr($attachment->post_title);
    } else {
      // Fallback to post title if in a singular post context
      $post_id = get_post();
      if ($post_id) {
        $attributes['alt'] = esc_attr(get_the_title($post_id->ID));
      } else {
        // Fallback to image filename if no other options available
        $attributes['alt'] = esc_attr(morenews_get_image_alt_from_filename($attachment->guid));
      }
    }
  }

  // Set 'loading' attribute for better performance

  $morenews_image_loading = morenews_get_option('global_toggle_image_lazy_load_setting');
  if ($morenews_image_loading == 'enable') {
    $attributes['loading'] = 'lazy';
  }

  // Set 'decoding' attribute to enhance rendering speed
  $morenews_image_decoding = morenews_get_option('global_decoding_image_async_setting');
  if ($morenews_image_decoding == 'enable') {
    $attributes['decoding'] = 'async';
  }

  return $attributes;
}
add_filter('wp_get_attachment_image_attributes', 'morenews_alt_text_optimized', 10, 3);

/**
 * Extract alt text from an image filename as a fallback.
 *
 * @param string $image_url The URL of the image.
 * @return string The sanitized filename without extension.
 */
function morenews_get_image_alt_from_filename($image_url)
{
  // Get the filename from the image URL and sanitize it for alt text usage.
  return esc_attr(pathinfo($image_url, PATHINFO_FILENAME));
}

function morenews_add_img_attributes($allowedtags)
{
  if (isset($allowedtags['img'])) {
    // Add additional attributes that plugins or core updates may introduce
    $allowedtags['img']['decoding'] = true;
    $allowedtags['img']['srcset'] = true;
    $allowedtags['img']['sizes'] = true;
    $allowedtags['img']['loading'] = true;
    $allowedtags['img']['data-*'] = true; // Support data-* attributes
    $allowedtags['img']['aria-*'] = true; // Support aria-* attributes for accessibility
    $allowedtags['img']['role'] = true;
    $allowedtags['img']['longdesc'] = true;
    $allowedtags['img']['usemap'] = true;
    $allowedtags['img']['referrerpolicy'] = true;
    $allowedtags['img']['style'] = true; // In case some plugins add inline styles
    $allowedtags['img']['crossorigin'] = true;
  }
  return $allowedtags;
}
add_filter('wp_kses_allowed_html', 'morenews_add_img_attributes');
