<?php
/**
 * Setup the Posts Block Rest Calls
 *
 * @since   2.0.0
 * @package AFT Blocks
 */


/**
 * Class to setup new rest calls.
 */
if(!class_exists('Aft_Post_Rest_Controller')){
	class Aft_Post_Rest_Controller extends WP_REST_Controller {

		 /**
	 * Type property name.
	 */
	const PROP_TYPE = 'type';

	/**
	 * Type property name.
	 */
	const PROP_TYPE_ARRAY = 'type_array';

	/**
	 * Query property name.
	 */
	const PROP_QUERY = 'query';
	/**
	 * Query property name.
	 */
	const PROP_MULTIPLE = 'multiple';

	/**
	 * Query property name.
	 */
	const PROP_ORDER_BY = 'order_by';

	/**
	 * Query property name.
	 */
	const PROP_ORDER = 'order';

	/**
	 * Query property name.
	 */
	const PROP_ALLOW_STICKY = 'allow_sticky';

	/**
	 * Query property name.
	 */
	const PROP_OFFSET = 'offset';

	/**
	 * Query property name.
	 */
	const PROP_TAX = 'tax';

	/**
	 * Query property name.
	 */
	const PROP_EXCLUDE = 'exclude';

	/**
	 * Query property name.
	 */
	const PROP_CUSTOM_TAX = 'custom_tax';
	/**
	 * Query property name.
	 */
	const PROP_TAGS = 'tags';
	/**
	 * Query property name.
	 */
	const PROP_CATEGORY = 'category';

	/**
	 * Query property name.
	 */
	const PROP_TAX_TYPE = 'tax_type';

	/**
	 * Search property name.
	 */
	const PROP_SEARCH = 'search';

	/**
	 * Include property name.
	 */
	const PROP_INCLUDE = 'include';

	/**
	 * Per page property name.
	 */
	const PROP_PER_PAGE = 'per_page';

	/**
	 * Per page property name.
	 */
	const PROP_POST_ID = 'post_id';

	/**
	 * Page property name.
	 */
	const PROP_PAGE = 'page';
	

	/**
	 * Constructor.
	 */
    public function __construct() {
		$this->namespace = 'aft/v1';
		$this->query_base = 'post-query';
	}

    /**
	 * Registers the routes for the objects of the controller.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		
		register_rest_route(
			$this->namespace,
			'/' . $this->query_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_query_items' ),
					'permission_callback' => array( $this, 'get_items_permission_check' ),
					'args'                => $this->get_query_params(),
				),
			)
		);
	}
    public function get_items_permission_check( $request ) {
		return current_user_can( 'edit_posts' );
	}
    public function get_query_items( $request ) {
		
		$prop_type      = $request->get_param( self::PROP_TYPE );
		$query_type     = $request->get_param( self::PROP_QUERY );
		$tax_type       = $request->get_param( self::PROP_TAX_TYPE );
		
		
		$categories     = ( $request->get_param( self::PROP_CATEGORY ) ? wp_parse_list( $request->get_param( self::PROP_CATEGORY ) ) : array() );
		$tags = ( $request->get_param( self::PROP_TAGS ) ? wp_parse_list( $request->get_param( self::PROP_TAGS ) ) : array() );
		if ( empty( $query_type ) ) {
			return array();
		}

		$query_args = array(
			'post_type' => $prop_type,
		);
		if ( 'individual' === $query_type ) {
			$query_args['post__in']            = $request->get_param( self::PROP_INCLUDE );
			$query_args['orderby']             = 'post__in';
			$query_args['posts_per_page']      = -1;
			$query_args['ignore_sticky_posts'] = 1;
		} else {
			$query_args['posts_per_page']      = $request->get_param( self::PROP_PER_PAGE );
			$query_args['tax_query']           = array();
			$query_args['orderby']             = $request->get_param( self::PROP_ORDER_BY );
			$query_args['order']               = $request->get_param( self::PROP_ORDER );
			$query_args['offset']              = $request->get_param( self::PROP_OFFSET );
			
			$query_args['post_status']         = 'publish';
			$query_args['ignore_sticky_posts'] = $request->get_param( self::PROP_ALLOW_STICKY );
			$current_post_id                   = $request->get_param( self::PROP_POST_ID );
			if ( ! empty( $current_post_id ) ) {
				$query_args['post__not_in']        = array( $current_post_id );
			}
			if ( 'post' !== $prop_type || $request->get_param( self::PROP_CUSTOM_TAX ) ) {
				if ( $tax_type ) {
					if(!empty($categories)){
						$query_args['tax_query'][] = array(
							'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
							'field'    => 'id',
							'terms'    => (isset($categories))?$categories:'',
							'operator' => ( isset( $exclude ) && 'exclude' === $exclude ? 'NOT IN' : 'IN' ),
						);
					}
				}
			} else if('post' == $prop_type){
				if ( $tax_type ) {
					
					if(!empty($categories) ){
						$query_args['tax_query'][] = array(
							'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
							'field'    => 'term_id',
							'terms'    => (!empty($categories))?$categories:'',
							'operator' => ( isset( $exclude ) && 'exclude' === $exclude ? 'NOT IN' : 'IN' )
							
						);
					}else{
						
						if($tax_type  == 'post_tag'){
							if(empty($tags)){
								$taxonomy = $tax_type; // this is the name of the taxonomy
								$terms = get_terms($taxonomy);
								
								$query_args['tax_query'][] = array(
									'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
									'field' => 'term_id',
									'terms' => wp_list_pluck($terms,'term_id')
									
								);
							}else{
								$query_args['tax_query'][] = array(
									'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
									'field' => 'id',
									'terms' => $tags,
									
								);
							}
						}else{
						
							$taxonomy = $tax_type; // this is the name of the taxonomy
							$terms = get_terms($taxonomy);
							$query_args['tax_query'][] = array(
								'taxonomy' => ( isset( $tax_type ) ) ? $tax_type : 'category',
								'field' => 'term_id',
								'terms' => wp_list_pluck($terms,'term_id')
								
							);
						}
					
					}
				}
			}else {
				$tags = ( $request->get_param( self::PROP_TAGS ) ? wp_parse_list( $request->get_param( self::PROP_TAGS ) ) : array() );
				
					$query_args['category__in'] = $categories;
					$query_args['tag__in']      = $tags;
				
			}
		}
		$query = new WP_Query( $query_args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$posts[] = $this->prepare_query_item_for_response( $post, $request,$tax_type  );
		}

		return rest_ensure_response( $posts );
	}

    public function prepare_query_item_for_response( $post, $request,$tax_type  ) {
		$excerpt = $this->blockspare_api_excerpt($post,$length=15);
		$new_excerpt = apply_filters( 'the_excerpt', $excerpt );
		$data = array(
			'id' => $post->ID,
			'date' => $this->prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'date_gmt' => $this->prepare_date_response( $post->post_date_gmt ),
			'modified' => $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'modified_gmt' => $this->prepare_date_response( $post->post_modified_gmt ),
			'title' => array(
				'raw'      => $post->post_title,
				'rendered' => get_the_title( $post->ID ),
			),
			'excerpt' => array(
				'raw'      => $post->post_excerpt,
				'rendered' => get_the_excerpt( $post->ID ),
			),
			'data_content'=>wp_kses_post($excerpt),
			'type' => $post->post_type,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			'link' => get_permalink( $post->ID ),
			'author' => absint( $post->post_author ),
			'display_name'=> get_the_author_meta('display_name', $request['author']),
			'author_link'=>get_author_posts_url($request['author']),
			'featured_media' => get_post_thumbnail_id( $post->ID ),
		);

		$attachment_id = get_post_thumbnail_id( $post->ID );
		
			 $image= wp_get_attachment_image_src(
				get_post_thumbnail_id( $post ),
				'full',
				false
			);
			$sizes = get_intermediate_image_sizes();
            
            $imageSizes = array(
                'full' => is_array($image) ? $image : '',
            );
            
            foreach ($sizes as $size) {
                $imageSizes[$size] = is_array($image) ? wp_get_attachment_image_src($attachment_id, $size, false) : '';
            }

			$data['featured_image_src_large'] = $imageSizes;
		
		//$author_data = array();
		if ( post_type_supports( $post->post_type, 'author' ) ) {
			$blockspare = new BlocksapreMultiAuthorForBackend();
			
			
			$data['author_info'] = $blockspare->blockspare_by_author($post);
		
		}
		if ( 'post' != $post->post_type || 'comments' != $post->post_type  || 'author' != $post->post_type ) {
			$blockspare = new BlocksapreMultiAuthorForBackend();
			
			$data['author_info'] = $blockspare->blockspare_by_author($post);
		
		}
		//$data['author_info'] = $author_data;
		if ( post_type_supports( $post->post_type, 'comments' ) ) {
			$comments_count = wp_count_comments( $post->ID );
			$data['comment_count'] = $comments_count->total_comments;
		}
		if ( 'post' === $post->post_type ) {
			if($tax_type == 'category' || $tax_type =='' ){
				$data['category_info'] = get_the_category( $post->ID );
			}else if($tax_type == 'post_tag'){
				$data['taxonomy_info']      = json_encode(get_the_tags( $post->ID ));
			}else if(!empty($tax_type) && ($tax_type != 'category' || $tax_type != 'post_tag')){
				
				$term_items = array();
				$terms = get_the_terms( $post->ID, $tax_type );
			
				if ( ! empty( $terms ) ) {
					
					foreach ( $terms as $term_key => $term_item ) {
						$term_items[] = array(
							'term_id' => $term_item->term_id,
							'name' => $term_item->name,
						);
					}
				
					$data['taxonomy_info'] =json_encode($term_items);
				}
			}else{
				$data['category_info'] = get_the_category( $post->ID );
			}
		}else{
		 $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		 $taxs = array();
		 $term_items = array();
		 foreach ( $taxonomies as $term_slug => $term ) {
		 	if ( ! $term->public || ! $term->show_ui ) {
		 		continue;
		 	}
		 	$terms = get_the_terms( $post->ID, $term_slug );
			
		 	if ( ! empty( $terms ) ) {
				
		 		foreach ( $terms as $term_key => $term_item ) {
		 			$term_items[] = array(
		 				'term_id' => $term_item->term_id,
		 				'name' => $term_item->name,
		 			);
		 		}
				
		 	}
		 }
		 $data['category_info'] = get_the_category( $post->ID );
		 $data['taxonomy_info'] = json_encode($term_items);
		}
	
		
		return $data;
	}

	protected function blockspare_api_excerpt($post,$length=''){
    
		$excerpt = get_post_field(
			'post_excerpt',
			$post->post_id,
			'display'
		);
		
		preg_replace( '~^(\s*(?:&nbsp;)?)*~i', '', $excerpt );
			if ( empty( $excerpt ) ) {
				
				$excerpt = preg_replace(
					array(
						'/\<figcaption>.*\<\/figcaption>/',
						'/\[caption.*\[\/caption\]/',
						'`[[^]]*]`'
					),
					'',
					$post->post_content
				);
			}
			
		
				// Trim the excerpt if necessary.
			if ( isset( $length ) ) {
				$excerpt = wp_trim_words(
					$excerpt,
					$length
				);
			}
	
			return $excerpt;
	}

	protected function prepare_date_response( $date_gmt, $date = null ) {
		// Use the date if passed.
		if ( isset( $date ) ) {
			return mysql2date( 'Y-m-d\TH:i:s', $date, false );
		}

		// Return null if $date_gmt is empty/zeros.
		if ( '0000-00-00 00:00:00' === $date_gmt ) {
			return null;
		}

		// Return the formatted datetime.
		return mysql2date( 'Y-m-d\TH:i:s', $date_gmt, false );
	}


    public function get_query_params() {
		$query_params  = parent::get_collection_params();

		$query_params[ self::PROP_TYPE ] = array(
			'description' => __( 'Limit results to items of a specific post type.', 'latest-posts-block' ),
			'type'        => 'string',
			'sanitize_callback' => array( $this, 'sanitize_post_type_string' ),
			'validate_callback' => array( $this, 'validate_post_type_string' ),
		);
	
		$query_params[ self::PROP_INCLUDE ] = array(
			'description' => __( 'Include posts by ID.', 'latest-posts-block' ),
			'type'        => 'array',
			'validate_callback' => array( $this, 'validate_post_ids' ),
			'sanitize_callback' => array( $this, 'sanitize_post_ids' ),
		);
		$query_params[ self::PROP_PER_PAGE ] = array(
			'description' => __( 'Number of results to return.', 'latest-posts-block' ),
			'type'        => 'number',
			'sanitize_callback' => array( $this, 'sanitize_post_perpage' ),
			'default' => 25,
		);
		$query_params[ self::PROP_QUERY ] = array(
			'description' => __( 'Define Type of Query.', 'latest-posts-block' ),
			'type'        => 'string',
		);
		$query_params[ self::PROP_ORDER ] = array(
			'description' => __( 'Define Query Order.', 'latest-posts-block' ),
			'type'        => 'string',
		);
		$query_params[ self::PROP_ORDER_BY ] = array(
			'description' => __( 'Define Query Order By.', 'latest-posts-block' ),
			'type'        => 'string',
		);
		$query_params[ self::PROP_ALLOW_STICKY ] = array(
			'description'       => __( 'Allow Sticky in Query.', 'latest-posts-block' ),
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_allow_sticky' ),
		);
		$query_params[ self::PROP_EXCLUDE ] = array(
			'description' => __( 'Exclude Category.', 'latest-posts-block' ),
			'type'        => 'string',
		);
		$query_params[ self::PROP_OFFSET ] = array(
			'description' => __( 'Number of items to offset in query.', 'latest-posts-block' ),
			'type'        => 'number',
			'sanitize_callback' => array( $this, 'sanitize_results_page_number' ),
			'default' => 0,
		);
		$query_params[ self::PROP_POST_ID ] = array(
			'description' => __( 'The Current Post ID.', 'latest-posts-block' ),
			'type'        => 'number',
		);
		$query_params[ self::PROP_CUSTOM_TAX ] = array(
			'description' => __( 'Check if using a custom Taxonomy', 'latest-posts-block' ),
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_allow_sticky' ),
		);
		$query_params[ self::PROP_TAX_TYPE ] = array(
			'description' => __( 'Define Query Order By.', 'latest-posts-block' ),
			'type'        => 'string',
		);
		$query_params[ self::PROP_CATEGORY ] = array(
			'description' => __( 'Include posts category.', 'latest-posts-block' ),
			'type'              => 'string',
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$query_params[ self::PROP_TAGS ] = array(
			'description' => __( 'Include posts tags.', 'latest-posts-block' ),
			'type'              => 'string',
			'sanitize_callback' => 'wp_parse_id_list',
		);
		return $query_params;
	}

    public function sanitize_post_ids( $ids ) {
		return array_map( 'absint', $ids );
	}
    public function validate_post_ids( $ids ) {
		return count( $ids ) > 0;
	}
    public function sanitize_post_perpage( $val ) {
		return min( absint( $val ), 100 );
	}
    public function sanitize_allow_sticky( $val ) {
		return $val ? 0 : 1;
	}
    public function sanitize_results_page_number( $val ) {
		return absint( $val );
	}
	public function validate_post_type_string( $value ) {
		$allowed_types = $this->get_allowed_post_types();
		return in_array( $value, $allowed_types );
	}

	public function sanitize_post_type_string( $post_type, $request ) {
		return sanitize_text_field( $post_type );
	}

	public function get_allowed_post_types() {
		$allowed_types = array_values(
			get_post_types(
				array(
					'show_in_rest'       => true,
					'public'             => true,
				)
			)
		);

		$key = array_search( 'attachment', $allowed_types, true );

		if ( false !== $key ) {
			unset( $allowed_types[ $key ] );
		}

		/**
		 * Filter the allowed post types.
		 *
		 * Note that if you allow this for posts that are not otherwise public,
		 * this data will be accessible using this endpoint for any logged in user with the edit_post capability.
		 */
		return apply_filters( 'aft_blocks_allowed_post_types', $allowed_types );
	}

	}
}