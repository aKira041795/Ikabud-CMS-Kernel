<?php
if (!function_exists('morenews_archive_layout_selection')) :
    /**
     *
     * @param null
     *
     * @return null
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_archive_layout_selection($morenews_archive_layout = 'default')
    {

        switch ($morenews_archive_layout) {

            case "archive-layout-list":
                morenews_get_block('list', 'archive');
                break;
            case "archive-layout-full":
                morenews_get_block('full', 'archive');
                break;
            default:
                morenews_get_block('list', 'archive');
        }
    }
endif;

if (!function_exists('morenews_archive_layout')) :
    /**
     *
     * @param null
     *
     * @return null
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_archive_layout($cat_slug = '')
    {

        $morenews_archive_args = morenews_archive_layout_class($cat_slug);
        if (!empty($morenews_archive_args['data_mh'])): ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class($morenews_archive_args['add_archive_class']); ?>
                 data-mh="<?php echo esc_attr($morenews_archive_args['data_mh']); ?>">
            <?php morenews_archive_layout_selection($morenews_archive_args['archive_layout']); ?>
        </article>
    <?php else: ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class($morenews_archive_args['add_archive_class']); ?> >
            <?php morenews_archive_layout_selection($morenews_archive_args['archive_layout']); ?>
        </article>
    <?php endif; ?>

        <?php

    }

    add_action('morenews_action_archive_layout', 'morenews_archive_layout', 10, 1);
endif;

function morenews_archive_layout_class($morenews_cat_slug)
{




    $morenews_archive_class = morenews_get_option('archive_layout');
    $morenews_archive_layout_list = morenews_get_option('archive_image_alignment');


    if ($morenews_archive_class == 'archive-layout-list') {
        $morenews_archive_args['archive_layout'] = 'archive-layout-list';
        $morenews_archive_args['add_archive_class'] = 'latest-posts-list col-1 float-l pad';
        $morenews_archive_args['data_mh'] = '';
        $morenews_image_align_class = $morenews_archive_layout_list;
        $morenews_archive_args['add_archive_class'] .= ' ' . $morenews_archive_class . ' ' . $morenews_image_align_class;
    } else {
        $morenews_archive_args['archive_layout'] = 'archive-layout-full';
        $morenews_archive_args['add_archive_class'] = 'af-sec-post latest-posts-full col-1 float-l pad';
        $morenews_archive_args['data_mh'] = '';
    }

    return $morenews_archive_args;

}


//Archive div wrap before loop

if (!function_exists('morenews_archive_layout_before_loop')) :

    /**
     *
     * @param null
     *
     * @return null
     *
     * @since MoreNews 1.0.0
     *
     */

    function morenews_archive_layout_before_loop()
    {

            //grid layout option
            $morenews_archive_mode = morenews_get_option('archive_layout');
            if ($morenews_archive_mode == 'archive-layout-full') {
                $morenews_archive_layout_full = morenews_get_option('archive_layout_full');
                if ($morenews_archive_layout_full == 'full-image-first') {
                    $morenews_archive_class = $morenews_archive_mode . " " . 'full-image-first';
                } else if ($morenews_archive_layout_full == 'full-title-first') {
                    $morenews_archive_class = $morenews_archive_mode . " " . 'archive-title-first';
                } else if ($morenews_archive_layout_full == 'archive-full-grid') {
                    $morenews_archive_class = $morenews_archive_mode . " " . "full-with-grid";
                } else {
                    $morenews_archive_class = $morenews_archive_mode;
                }
            } else {

                $morenews_archive_class = $morenews_archive_mode;
            }

        ?>
        <div class="af-container-row aft-archive-wrapper morenews-customizer clearfix <?php echo esc_attr($morenews_archive_class); ?>">
        <?php

    }

    add_action('morenews_archive_layout_before_loop', 'morenews_archive_layout_before_loop');
endif;

if (!function_exists('morenews_archive_layout_after_loop')):

    function morenews_archive_layout_after_loop()
    {
        ?>
        </div>
    <?php }

    add_action('morenews_archive_layout_after_loop', 'morenews_archive_layout_after_loop');

endif;