<?php

function blockspare_banner_one_render_block($attributes)
{
    ob_start();
    $unq_class = mt_rand(100000, 999999);
    $blockuniqueclass = '';

    if (!empty($attributes['uniqueClass'])) {
        $blockuniqueclass = $attributes['uniqueClass'];
    } else {
        $blockuniqueclass = 'blockspare-posts-block-list-' . $unq_class;
    }

    $desingParmeter = $attributes['bannerOneLayout'];

    $numOfSlides = 2;
    if ($attributes['align'] == 'center' || $attributes['align'] == '') {
        $numOfSlides = 1;
    } else {
        $numOfSlides = $attributes['numberofSlideTrending'];
    }

    $animation_class = '';
    if ($attributes['animation']) {
        $animation_class = 'blockspare-block-animation';
    }

    $alignclass = blockspare_checkalignment($attributes['align']);
    $banner_name = 'banner-1';

    ?>
        <div class='<?php echo esc_attr($blockuniqueclass); ?> align<?php echo esc_attr($alignclass) ?>'>
        <?php
$blockName = 'blockspare-banner-1';
    echo banner_control_slider($attributes, $blockuniqueclass, $blockName);
    ?>
            <div class='<?php echo esc_attr($animation_class) ?> blockspare-banner-wrapper blockspare-banner-1-main-wrapper <?php echo esc_attr($attributes['bannerOneLayout']) ?> <?php echo esc_attr($attributes['blockHoverEffect']) ?> <?php echo esc_attr($attributes['imageHoverEffect']) ?>' blockspare-animation="<?php echo esc_attr($attributes['animation']); ?>">
                <div class='blockspare-banner-col-wrap'>

                    <?php //if($layoutone ==true){ ?>
                    <div class="blockspare-banner-trending-wrap">
                        <?php
blockspare_get_slider_template($attributes);
    // blocspare_get_trending_template($attributes,$desingParmeter,$numOfSlides);
    ?>
                    </div>
                    <?php blocspare_get_editor_template($attributes, '2', 'banner-1');?>

                </div>
            </div>
        </div>
            <?php
return ob_get_clean();
}
/**
 * Registers banner one on server
 */
function blockspare_banner_one_register_block()
{

    if (!function_exists('register_block_type')) {
        return;
    }

    ob_start();
    include BLOCKSPARE_PLUGIN_DIR . 'inc/post-banner/block.json';

    $metadata = json_decode(ob_get_clean(), true);

    /* Block attributes */
    register_block_type(
        'blockspare/blockspare-banner-1',
        array(
            'attributes' => $metadata['attributes'],
            'render_callback' => 'blockspare_banner_one_render_block',
        )
    );
}

add_action('init', 'blockspare_banner_one_register_block');