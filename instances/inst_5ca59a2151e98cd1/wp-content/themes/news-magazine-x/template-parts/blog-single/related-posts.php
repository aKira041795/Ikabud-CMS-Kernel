<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$bs_related_title = newsx_get_option('bs_related_title');
$bs_related_query = newsx_get_option('bs_related_query');
$bs_related_ppp = newsx_get_option('bs_related_ppp');

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $bs_related_query = 'related';
    $bs_related_ppp = 3;
}

global $post;
$current_categories	= get_the_category();
$first_category	= !empty($current_categories) ? $current_categories[0]->term_id : false;

$args = [
    'post_type'				=> 'post',
    'posts_per_page'		=> $bs_related_ppp,
    'post__not_in'          => [$post->ID],
    'ignore_sticky_posts'	=> 1,
    'meta_query' => [
        [
            'key' => '_thumbnail_id',
            'compare' => 'EXISTS'
        ],
    ]
];

if ( 'related' === $bs_related_query ) {
    $args['category__in'] = [$first_category];
} else {
    $args['orderby'] = 'rand';
}

$related_posts = new WP_Query( $args );	

if ( $related_posts->have_posts() ) : ?>

<div class="newsx-related-posts-wrap">


    <?php echo newsx_customizer_edit_button_markup('bs_related'); // Edit Button ?>

    <h4><?php echo esc_html( $bs_related_title ); ?></h4>
    
    <div class="newsx-related-posts <?php echo esc_attr( 'newsx-col-' . $bs_related_ppp ); ?>">

    <?php  while ( $related_posts->have_posts() ) : $related_posts->the_post(); ?>

        <section>
            <a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_post_thumbnail('newsx-330x220'); ?></a>
            <h5>
                <a href="<?php echo esc_url( get_permalink() ); ?>">
                    <?php // by Duke: add mb_strlen and mb_substr to handle multibyte characters like Japanese text (漢字) correctly

                    $title = mb_strlen(html_entity_decode(get_the_title())) > 65 ? mb_substr(html_entity_decode(get_the_title()), 0, 65) . ' ...' : get_the_title();
                    echo esc_html($title);

                    ?>
                </a>
            </h5>
            <span class="post-date"><?php echo get_the_time( get_option('date_format') ); ?></span>
        </section>

    <?php endwhile; ?>

    </div>

</div>

<?php

endif;

wp_reset_postdata();