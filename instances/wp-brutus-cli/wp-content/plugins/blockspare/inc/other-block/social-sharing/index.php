<?php

/**
 * Server-side rendering for the sharing block
 *
 * @since   1.1.2
 * @package Blockspare
 */

/**
 * Register the block on the server
 */

if (!function_exists('blockspare_register_social_sharing_block')) {
  function blockspare_register_social_sharing_block()
  {

    if (!function_exists('register_block_type')) {
      return;
    }
    ob_start();
    include BLOCKSPARE_PLUGIN_DIR . 'inc/other-block/social-sharing/block.json';

    $metadata = json_decode(ob_get_clean(), true);

    register_block_type(
      'blockspare/blockspare-social-sharing',

      array(
        'style' => 'blockspare-style-css',
        'attributes' => $metadata['attributes'],
        'render_callback' => 'blockspare_render_social_sharing_block',
      )
    );
  }

  add_action('init', 'blockspare_register_social_sharing_block');
}


/**
 * Add the pop-up share window to the footer
 */
if (! function_exists('blockspare_social_sharing_block_icon_footer_script')) {
  function blockspare_social_sharing_block_icon_footer_script()
  {

    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) {
      return;
    }
?>
    <script type="text/javascript">
      function blockspareBlocksShare(url, title, w, h) {
        var left = (window.innerWidth / 2) - (w / 2);
        var top = (window.innerHeight / 2) - (h / 2);
        return window.open(url, title, 'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=no, resizable=no, copyhistory=no, width=600, height=600, top=' + top + ', left=' + left);
      }
    </script>
<?php
  }


  add_action('wp_footer', 'blockspare_social_sharing_block_icon_footer_script');
}

/**
 * Render the sharing links
 *
 * @param array $attributes The block attributes.
 *
 * @return string The block HTML.
 */
if (!function_exists('blockspare_render_social_sharing_block')) {
  function blockspare_render_social_sharing_block($attributes)
  {

    global $post;

    if (has_post_thumbnail()) {
      $thumbnail_id = get_post_thumbnail_id($post->ID);
      $thumbnail = $thumbnail_id ? current(wp_get_attachment_image_src($thumbnail_id, 'large', true)) : '';
    } else {
      $thumbnail = null;
    }


    $unq_class = mt_rand(100000, 999999);
    $blockuniqueclass = '';

    if (!empty($attributes['uniqueClass'])) {
      $blockuniqueclass = $attributes['uniqueClass'];
    } else {
      $blockuniqueclass = 'blockspare-share-' . $unq_class;
    }

    $icon_style = '';

    if ($attributes['iconColorOption'] != 'blockspare-default-official-color') {

      $icon_style = "color:" . $attributes['customfontColorOption'] . ';';
      $icon_style .= " background-color:" . $attributes['custombackgroundColorOption'] . ';';
    }


    $animation_class  = '';
    if ($attributes['animation']) {
      $animation_class = 'blockspare-block-animation';
    }



    $is_amp_endpoint = function_exists('is_amp_endpoint') && is_amp_endpoint();

    $share_url = '';


    if (isset($attributes['facebook']) && $attributes['facebook']) {
      $facebook_url = 'https://www.facebook.com/sharer/sharer.php?u=' . get_the_permalink() . '&title=' . get_the_title() . '';
      $share_url .= blockspare_render_social_sharing_block_item($attributes['facebookTitle'], $facebook_url, 'blockspare-share-facebook', 'fab fa-facebook-f', $icon_style, $is_amp_endpoint);
    }

    if (isset($attributes['twitter']) && $attributes['twitter']) {
      $twitter_url = 'http://twitter.com/share?text=' . get_the_title() . '&url=' . get_the_permalink() . '';
      $share_url .= blockspare_render_social_sharing_block_item($attributes['twitterTitle'], $twitter_url, 'blockspare-share-twitter', 'fab fa-twitter', $icon_style, $is_amp_endpoint);
    }


    if (isset($attributes['pinterest']) && $attributes['pinterest']) {

      $pinterest_url = 'https://pinterest.com/pin/create/button/?&url=' . get_the_permalink() . '&description=' . get_the_title();
      if (!empty($thumbnail)) {
        $pinterest_url .= '&media=' . esc_url($thumbnail);
      }
      $share_url .=  blockspare_render_social_sharing_block_item($attributes['pinterestTitle'], $pinterest_url, 'blockspare-share-pinterest', 'fab fa-pinterest-p', $icon_style, $is_amp_endpoint);
    }

    if (isset($attributes['linkedin']) && $attributes['linkedin']) {

      $linkedin_url = 'https://www.linkedin.com/shareArticle?mini=true&url=' . get_the_permalink() . '&title=' . get_the_title() . '';
      $share_url .= blockspare_render_social_sharing_block_item($attributes['linkedinTitle'], $linkedin_url, 'blockspare-share-linkedin', 'fab fa-linkedin-in', $icon_style, $is_amp_endpoint);
    }

    if (isset($attributes['reddit']) && $attributes['reddit']) {


      $reddit_url = 'https://www.reddit.com/submit?url=' . get_the_permalink() . '';
      $share_url .= blockspare_render_social_sharing_block_item($attributes['redditTitle'], $reddit_url, 'blockspare-share-reddit', 'fab fa-reddit-alien', $icon_style, $is_amp_endpoint);
    }

    if (isset($attributes['email']) && $attributes['email']) {

      $email_url = 'mailto:?subject=' . get_the_title() . '&body=' . get_the_title() . '&mdash;' . get_the_permalink() . '';
      $share_url .= blockspare_render_social_sharing_block_item($attributes['emailTitle'], $email_url, 'blockspare-share-email', 'fas fa-envelope', $icon_style, false);
    }

    $block_content = sprintf(
      '
<div class="%7$s %8$s %10$s %12$s" blockspare-animation="%9$s">' . blockspare_social_share_style($blockuniqueclass, $attributes) . '
<div class="blockspare-blocks blockspare-social-wrapper">
			<ul class="blockspare-social-sharing %2$s %3$s %4$s %5$s %6$s %11$s ">%1$s</ul>
			</div>
			</div>
		',
      $share_url,
      isset($attributes['iconColorOption']) ? esc_attr($attributes['iconColorOption']) : null,
      isset($attributes['buttonOptions']) ? esc_attr($attributes['buttonOptions']) : null,
      isset($attributes['buttonShapes']) ? esc_attr($attributes['buttonShapes']) : null,
      isset($attributes['buttonSizes']) ? esc_attr($attributes['buttonSizes']) : null,
      isset($attributes['buttonFills']) ? esc_attr($attributes['buttonFills']) : null,
      esc_attr($blockuniqueclass),
      esc_attr($animation_class),
      esc_attr($attributes['animation']),
      esc_attr($attributes['blockHoverEffect']),
      esc_attr($attributes['buttonOptions'] == 'blockspare-icon-only' ? 'blockspare-social-sharing-horizontal' : $attributes['iconLayoutType']),
      esc_attr('blockspare-social-sharing-' . $attributes['sectionAlignments'])



    );



    return $block_content;
  }

  function blockspare_social_share_style($blockuniqueclass, $attributes)
  {

    $block_content = '';
    $block_content .= '<style type="text/css">';

    if ($attributes['iconColorOption'] != 'blockspare-default-official-color') {

      if ($attributes['buttonFills'] == 'blockspare-social-icon-solid') {

        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-sharing a span{
        color:' . $attributes['customfontColorOption'] . ';
        background-color:' . $attributes['custombackgroundColorOption'] . ';
        }';
      } else if ($attributes['buttonFills'] == 'blockspare-social-icon-border') {
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-wrapper .blockspare-social-sharing a{
        color:' . $attributes['customfontColorOption'] . ';
        border:' . "2px solid " . $attributes['custombackgroundColorOption'] . ';
        }';
      } else {
        $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-sharing a span{
        color:' . $attributes['customfontColorOption'] . ';
      
        }';
      }
    }
    $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-wrapper{
        text-align:' . $attributes['sectionAlignments'] . ';
        margin-top:' . $attributes['marginTop'] . 'px;
        margin-right:' . $attributes['marginRight'] . 'px;
        margin-bottom:' . $attributes['marginBottom'] . 'px;
        margin-left:' . $attributes['marginLeft'] . 'px;
        
    }';

    $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-wrapper .blockspare-social-icons > span{
        font-size: ' . $attributes['socialFontSize'] . $attributes['socialFontSizeType'] . ';
        ' . bscheckFontfamily($attributes['socialFontFamily']) . ';
        ' . bscheckFontfamilyWeight($attributes['socialFontWeight']) . ';
        
    }';

    $block_content .= '@media (max-width: 1025px) { ';
    $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-wrapper .blockspare-social-icons > span{
            font-size: ' . $attributes['socialFontSizeTablet'] . $attributes['socialFontSizeType'] . ';
        }';
    $block_content .= '}';

    $block_content .= '@media (max-width: 767px) { ';
    $block_content .= ' .' . $blockuniqueclass . ' .blockspare-social-wrapper .blockspare-social-icons > span{
            font-size: ' . $attributes['socialFontSizeMobile'] . $attributes['socialFontSizeType'] . ';
        }';
    $block_content .= '}';

    $block_content .= '</style>';

    return $block_content;
  }
}

if (!function_exists('blockspare_render_social_sharing_block_item')) {

  function blockspare_render_social_sharing_block_item($attribute_title, $attribute_url, $item_class, $icon_class, $icon_style, $is_amp_endpoint)
  {


    $share_url = '';

    if (!$is_amp_endpoint) {
      $share_url .= sprintf(
        '<li class="blockspare-hover-item">
				<a
					href="%1$s"
					class="%3$s"
					title="%2$s" 
					>
					<span class="blockspare-social-icons">
					<i class="%4$s"></i> <span class="blockspare-social-text">%2$s</span>
					</span>
				</a>
			</li>',
        $attribute_url,
        esc_html__($attribute_title),
        esc_attr__($item_class),
        esc_attr__($icon_class)

      );
    } else {

      $href_format = sprintf('href="javascript:void(0)" onClick="javascript:blockspareBlocksShare(\'%1$s\', \'%2$s\', \'600\', \'600\')"', esc_url($attribute_url), esc_html__($attribute_title));

      if ($is_amp_endpoint) {
        $href_format = sprintf('href="%1$s"', esc_url($attribute_url));
      }
      $share_url .= sprintf(
        '<li class="blockspare-hover-item">
				<a
					%1$s
					class="%3$s"
					title="%2$s" 
					><span class="blockspare-social-icons">
					<i class="%4$s"></i> <span class="blockspare-social-text">%2$s</span>
					</span>
				</a>
			</li>',
        $href_format,
        esc_html__($attribute_title),
        esc_attr__($item_class),
        esc_attr__($icon_class)

      );
    }


    return $share_url;
  }
}
