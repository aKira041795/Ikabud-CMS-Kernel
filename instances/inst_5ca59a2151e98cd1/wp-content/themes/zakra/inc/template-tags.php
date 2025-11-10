<?php
/**
 * Custom template tags for this theme
 *
 * Eventually, some of the functionality here could be replaced by core features.
 *
 * @package zakra
 */

if ( ! function_exists( 'zakra_page_title' ) ) :

	/**
	 * Print the title.
	 *
	 * The title of a blog page, archive page etc.
	 *
	 * @since 3.0.0
	 */
	function zakra_page_title() {

		$allowed_markup = array( 'h1', 'h2', 'h3', 'h3', 'h4', 'h5', 'h6', 'span', 'p', 'div' );
		$markup         = get_theme_mod( 'zakra_page_title_markup', 'h1' );

		// If the markup doesn't match from the allowed list, set it to default one (h1).
		$markup = in_array( $markup, $allowed_markup, true ) ? $markup : 'h1';

		// Finale.
		$markup = apply_filters( 'zakra_page_header_markup', $markup );
		?>
		<div class="zak-page-header__title">
			<<?php echo esc_attr( $markup ); ?> class="zak-page-title">

				<?php
				// Blog page.
				if ( is_singular() ) {

					the_title();
				} elseif ( is_search() ) { // Search page.

					/* translators: %s: search query. */
					printf( esc_html__( 'Search Results for: %s', 'zakra' ), '<span>' . get_search_query() . '</span>' );
				} elseif ( is_home() ) {

					echo wp_kses_post( get_the_title( get_option( 'page_for_posts', true ) ) );
				} elseif ( is_404() ) {

					// Page header.
					if ( 'page-header' === zakra_page_title_position() ) {

						echo esc_html__( 'Page Not Found', 'zakra' );
					} else { // Content area.

						echo esc_html__( 'Oops! That page can&rsquo;t be found.', 'zakra' );
					}
				} elseif ( is_archive() ) {

					if ( is_author() ) :

						/**
						 * Queue the first post, that way we know
						 * what author we're dealing with (if that is the case).
						 */
						the_post();

						$author_title = apply_filters( 'zakra_author_title_prefix', 'Author :' );

						/* translators: %1$s: Author prefix, %2$s: Author. */
						$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $author_title, '<span class="vcard">' . get_the_author() . '</span>' );

						/**
						 * Since we called the_post() above, we need to
						 * rewind the loop back to the beginning that way
						 * we can run the loop properly, in full.
						 */
						rewind_posts();
									elseif ( is_post_type_archive() ) :

										$page_title = post_type_archive_title( '', false );
									elseif ( is_day() ) :

										$day_title = apply_filters( 'zakra_day_title_prefix', 'Day:' );

										/* translators: %1$s: Day prefix, %2$s: Day. */
										$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $day_title, '<span>' . get_the_date() . '</span>' );
									elseif ( is_month() ) :

										$month_title = apply_filters( 'zakra_month_title_prefix', 'Month:' );

										/* translators: %1$s: Month prefix, %2$s: Month. */
										$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $month_title, '<span>' . get_the_date( 'F Y' ) . '</span>' );
									elseif ( is_year() ) :

										$year_title = apply_filters( 'zakra_year_title_prefix', 'Year:' );

										/* translators: %1$s: Year prefix, %2$s: Year. */
										$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $year_title, '<span>' . get_the_date( 'Y' ) . '</span>' );
									elseif ( zakra_is_woocommerce_active() && function_exists( 'is_woocommerce' ) && is_woocommerce() ) :

										$page_title = woocommerce_page_title( false );
									else :

										$page_title = single_cat_title( '', false );
									endif;

									echo wp_kses_post( $page_title );
				}
				?>

			</<?php echo esc_attr( $markup ); ?>>

		</div>
		<?php
	}
endif;

if ( ! function_exists( 'zakra_posted_on' ) ) :
	/**
	 * Prints HTML with meta information for the current post-date/time.
	 */
	function zakra_posted_on() {

		$meta_style     = get_theme_mod( 'zakra_post_meta_style', 'style-1' );
		$catgories_icon = ( 'style-2' === $meta_style ) ? zakra_get_icon( 'calendar', false ) : '';

		/* translators: %s: post date. */
		$date_text = ( 'style-1' === $meta_style ) ? esc_html_x( 'Posted on %s', 'post date', 'zakra' ) : '%s';

		$time_string = '<time class="entry-date published updated" datetime="%1$s">%2$s</time>';

		if ( ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) && 'modified-date' === get_theme_mod( 'zakra_blog_post_date_type', 'published-date' ) ) {
			$time_string = '<time class="updated" datetime="%3$s">%4$s</time>';
		}

		$time_string = sprintf(
			$time_string,
			esc_attr( get_the_date( DATE_W3C ) ),
			esc_html( get_the_date() ),
			esc_attr( get_the_modified_date( DATE_W3C ) ),
			esc_html( get_the_modified_date() )
		);

		$posted_on = sprintf(
			$date_text,
			'<a href="' . esc_url( get_permalink() ) . '" rel="bookmark">' . $time_string . '</a>'
		);

		echo '<span class="zak-posted-on">' . $catgories_icon . $posted_on . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
endif;

if ( ! function_exists( 'zakra_posted_by' ) ) :
	/**
	 * Prints HTML with meta information for the current author.
	 */
	function zakra_posted_by() {

		$meta_style = get_theme_mod( 'zakra_post_meta_style', 'style-1' );

		$catgories_icon = ( 'style-2' === $meta_style ) ? zakra_get_icon( 'user', false ) : '';

		/* translators: %s: post author. */
		$author_text = ( 'style-1' === $meta_style ) ? esc_html_x( 'By %s', 'post author', 'zakra' ) : '%s';

		$byline = sprintf(
			$author_text,
			'<span class="author vcard"><a class="url fn n" href="' . esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ) . '">' . esc_html( get_the_author() ) . '</a></span>'
		);

		echo '<span class="zak-byline"> ' . $catgories_icon . $byline . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
endif;

if ( ! function_exists( 'zakra_posted_in' ) ) :
	/**
	 * Prints HTML with meta information of post categories.
	 */
	function zakra_posted_in() {

		$meta_style     = get_theme_mod( 'zakra_post_meta_style', 'style-1' );
		$catgories_icon = ( 'style-2' === $meta_style ) ? zakra_get_icon( 'folder', false ) : '';

		/* translators: 1: list of categories. */
		$catgories_text = ( 'style-1' === $meta_style ) ? esc_html__( 'Posted in %1$s', 'zakra' ) : '%1$s';

		// Hide category and tag text for pages.
		if ( 'post' === get_post_type() ) {
			/* translators: used between list items, there is a space after the comma */
			$categories_list = get_the_category_list( esc_html__( ', ', 'zakra' ) );
			if ( $categories_list ) {
				printf( '<span class="zak-cat-links">' . $catgories_icon . $catgories_text . '</span>', $categories_list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}
endif;

if ( ! function_exists( 'zakra_post_comments' ) ) :
	/**
	 * Prints HTML with comments on current post.
	 */
	function zakra_post_comments() {

		$meta_style = get_theme_mod( 'zakra_post_meta_style', 'style-1' );

		if ( ! post_password_required() && ( comments_open() || get_comments_number() ) ) {
			echo '<span class="zak-comments-link">';
			if ( 'style-2' === $meta_style ) {
				zakra_get_icon( 'comment' );
			}
			comments_popup_link(
				sprintf(
					wp_kses(
						/* translators: %s: post title */
						__( 'No Comments<span class="screen-reader-text"> on %s</span>', 'zakra' ),
						array(
							'span' => array(
								'class' => array(),
							),
						)
					),
					get_the_title()
				)
			);
			echo '</span>';
		}
	}
endif;

if ( ! function_exists( 'zakra_post_tags' ) ) :
	/**
	 * Prints HTML with tags of current post.
	 */
	function zakra_post_tags() {

		$meta_style = get_theme_mod( 'zakra_post_meta_style', 'style-1' );
		$tag_icon   = ( 'style-2' === $meta_style ) ? zakra_get_icon( 'tag', false ) : '';

		/* translators: 1: list of tags. */
		$tags_text = ( 'style-1' === $meta_style ) ? esc_html__( 'Tagged %1$s', 'zakra' ) : '%1$s';

		// Hide category and tag text for pages.
		if ( 'post' === get_post_type() ) {
			/* translators: used between list items, there is a space after the comma */
			$tags_list = get_the_tag_list( '', esc_html_x( ', ', 'list item separator', 'zakra' ) );

			if ( $tags_list ) {
				printf( '<span class="zak-tags-links">' . $tag_icon . $tags_text . '</span>', $tags_list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}
endif;

if ( ! function_exists( 'zakra_post_thumbnail' ) ) :

	/**
	 * Displays an optional post thumbnail.
	 *
	 * Wraps the post thumbnail in an anchor element on index views, or a div
	 * element when on single views.
	 */
	function zakra_post_thumbnail( $image_size = 'post-thumbnail' ) {

		if ( post_password_required() || is_attachment() || ! has_post_thumbnail() ) {

			return;
		}
		?>

		<div class="zak-entry-thumbnail">

			<?php
			if ( is_singular() ) :

				the_post_thumbnail();
			else :
				?>

				<a class="zak-entry-thumbnail__link" href="<?php the_permalink(); ?>" aria-hidden="true">
					<?php
					the_post_thumbnail(
						$image_size,
						array(
							'alt' => the_title_attribute(
								array(
									'echo' => false,
								)
							),
						)
					);
					?>
				</a>
			<?php endif; // End is_singular(). ?>

		</div><!-- .zak-entry-thumbnail -->
		<?php
	}
endif;

/**
 * Determine whether this is an AMP response.
 *
 * @return bool Is AMP endpoint and is AMP plugin active.
 */
function zakra_is_amp() {
	return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
}

if ( ! function_exists( 'zakra_get_post_id' ) ) {
	/**
	 * Store the post ids.
	 *
	 * Since blog page takes the first post as its id,
	 * here we are storing the id of the post and for the blog page,
	 * storing its value via getting the specific page id through:
	 * `get_option( 'page_for_posts' )`
	 *
	 * @return false|int|mixed|string|void
	 */
	function zakra_get_post_id() {

		$post_id        = '';
		$page_for_posts = get_option( 'page_for_posts' );

		// For single post and pages.
		if ( is_singular() ) {
			$post_id = get_the_ID();
		} elseif ( ! is_front_page() && is_home() && $page_for_posts ) { // For the static blog page.

			$post_id = $page_for_posts;
		}

		// Return the post ID.
		return $post_id;
	}
}
