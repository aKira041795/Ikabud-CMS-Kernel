<?php
defined('ABSPATH') or die('No script kiddies please!'); // prevent direct access

class WPAMultiAuthors
{

  public $to_be_filtered_caps = array();

  public function __construct()
  {
    $author_metabox = awpa_get_author_metabox_setting();
    if ($author_metabox && $author_metabox['enable_author_metabox']) {
      add_filter('manage_posts_columns', array($this, 'awpa_ma_custom_column_head'), 1, 1);
      add_action('manage_posts_custom_column', array($this, 'awpa_ma_custom_columns_content'), 10, 2);
      add_action('add_meta_boxes', array($this, 'awpa_add_metaboxes'), 10, 1);
      add_action('save_post', array($this, 'awpa_ma_save_metabox'), 10, 1);
      add_action('admin_enqueue_scripts', array($this, 'awpa_ma_register_backend_scripts'));
      add_filter('user_has_cap', array($this, 'awpa_ma_change_user_capabilities'), 10, 3);
      // Modify SQL queries to include guest authors
    }
    add_filter('posts_where', array($this, 'awpa_ma_posts_where_filter'), 10, 2);
    add_filter('posts_join', array($this, 'awpa_ma_posts_join_filter'), 10, 2);
    add_filter('posts_distinct', array($this, 'awpa_ma_search_distinct'), 10);
  }
  public function awpa_ma_custom_column_head($columns)
  {
    $post_type = get_post_type();
    if ($post_type != 'post') {
      return $columns;
    }
    $columns = $this->awpa_ma_replace_key($columns, 'author', 'authors');

    return $columns;
  }

  public function awpa_ma_replace_key($arr, $oldkey, $newkey)
  {
    if (array_key_exists($oldkey, $arr)) {
      $keys = array_keys($arr);
      $keys[array_search($oldkey, $keys)] = $newkey;
      return array_combine($keys, $arr);
    }

    return $arr;
  }

  public function awpa_ma_custom_columns_content($column_name, $post_id)
  {
    switch ($column_name) {
      case 'authors':
        $this->awpa_get_authors($post_id);
        break;
    }
  }

  public function awpa_add_metaboxes()
  {
    add_meta_box(
      'awpa-multi-author',
      __('Authors', 'wp-post-author'),
      array($this, 'awpa_ma_render_metabox_multiauthor'),
      array('post', 'page', 'movie'),
      'side',
      'high'
    );
  }

  public function awpa_ma_render_metabox_multiauthor($post)
  {
    $mainauthor = get_post_meta($post->ID, 'wpma_mainauthor');
    $coauthors = get_post_meta($post->ID, 'wpma_author');
    $authors = $this->awpa_get_all_users();
    $guest_authors = $this->awpa_get_all_guest_authors();

    $all_authors = array_merge($authors, $guest_authors);

    if (gettype($coauthors) == 'string' || gettype($coauthors) == "") {
      $coauthors = array();
    } else {
      $coauthors = array_merge(array($post->post_author), $coauthors);
      $coauthors = array_unique($coauthors);
      $coauthors = array_values($coauthors);
    }
    $author_metabox = awpa_get_author_metabox_setting();
    if (isset($author_metabox['enable_author_metabox']) && $author_metabox['enable_author_metabox']) { ?>
      <style>
        .wp-admin .components-select-control.post-author-selector {
          display: none;
        }
      </style>
    <?php }

    ?>
    <input type="hidden" name="wpma_meta_box_nonce" value="<?php echo wp_create_nonce(basename(__FILE__)); ?>">
    <input type="hidden" class="wpmma-current-post-id" value="<?php echo esc_attr($post->ID); ?>">
    <div id="awpa_authors_metabox" name="awpa_authors_list" multi_author_addon="<?php echo htmlspecialchars(class_exists('AWPA_Multi_Authors_Addon') ? "true" : "false") ?>" all_authors="<?php echo htmlspecialchars(json_encode($all_authors)); ?>" coauthors="<?php echo htmlspecialchars(json_encode($coauthors)); ?>">
    </div>
    <input type="hidden" id="wpma_metabox_authors_list" name="wpma_metabox_authors_list">
    <?php
  }

  public function awpa_ma_save_metabox($post_id)
  {

    $coauthors = get_post_meta($post_id, 'wpma_coauthors', true);
    if (gettype($coauthors) == 'string' || gettype($coauthors) == false) {
      $coauthors = array();
    }

    $author_list = isset($_POST['wpma_metabox_authors_list']) ? $_POST['wpma_metabox_authors_list'] : array();

    $first_author = false;
    if ($author_list) {
      $data = explode(',', $author_list);
      delete_post_meta($post_id, 'wpma_author');
      foreach ($data as $key => $user_id) {
        $userid = sanitize_text_field($user_id);
        add_post_meta($post_id, 'wpma_author', $user_id);
        if (!str_contains('guest', $user_id)) {
          $user = get_user_by('ID', $user_id);
          if ($user && $first_author == false) {
            $requried_role_string = "administrator editor author";
            $roles = $user->roles;
            foreach ($roles as $key => $role) {
              if (str_contains($requried_role_string, $role) && !$first_author) {
                remove_action('save_post', array($this, 'awpa_ma_save_metabox'), 10);
                $arg = array(
                  'ID' => $post_id,
                  'post_author' => $user_id,
                );
                $arg = array_map('intval', $arg);
                wp_update_post($arg);
                $first_author = true;
                add_action('save_post', array($this, 'awpa_ma_save_metabox'), 10, 1);
              }
            }
          }
        }
      }
    }
  }

  public function awpa_get_all_guest_authors()
  {
    $guests = $this->awpa_get_guest();
    if (!$guests) {
      return array();
    }
    $all_guests = array();
    foreach ($guests as $key => $guest) {
      $all_guests[] = array(
        'user' => $guest,
        'id' => 'guest-' . $guest->id,
        'is_active' => $guest->is_active == "1" ? true : false,
        'type' => 'guest',
      );
    }
    return $all_guests;
  }
  public function awpa_get_guest()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "wpa_guest_authors";
    $query = "SELECT * FROM $table_name";
    $all_guests = $wpdb->get_results($query, OBJECT);
    return $all_guests;
  }
  public function get_guest_by_id($id)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "wpa_guest_authors";
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE id = %d", $id));
  }

  public function awpa_is_guest_linked_with_author($user_id)
  {

    global $wpdb;
    $table_name = $wpdb->prefix . "wpa_guest_authors";

    // Use $wpdb->prepare() to safely insert the $user_id
    $query = $wpdb->prepare(
      "SELECT * FROM $table_name WHERE linked_user_id = %d",
      $user_id
    );

    // Execute the query and fetch the results
    $guest = $wpdb->get_results($query, OBJECT);

    return $guest;
  }
  public function awpa_get_all_users()
  {
    $user_fields = array('user_login', 'user_nicename', 'user_email', 'display_name', 'ID');
    $args = array(
      'role__in' => array('administrator', 'editor', 'author', 'contributor'),
      'fields' => $user_fields,
    );
    $users = get_users($args);
    $users_id_array = array();
    foreach ($users as $key => $user) {

      $user_meta = get_userdata($user->ID);
      $user_roles = $user_meta->roles;
      $users_id_array[] = array(
        'user' => $user,
        'id' => $user->ID,
        'is_active' => true,
        'type' => ucfirst($user_roles[0]),
      );
    }

    return $users_id_array;
  }
  public function awpa_get_authors($post_id)
  {
    global $post;
    $coauthors = get_post_meta($post_id, 'wpma_author');
    $main_author = $post->post_author;
    if (gettype($coauthors) != 'string') {
      $coauthors[] = $main_author;
      $coauthors = array_unique($coauthors);
    } else {
      $coauthors = array();
      $coauthors[] = $main_author;
    }
    $count = 1;
    foreach ($coauthors as $key => $author_id) {
      if (preg_match('/\bguest-\b/', $author_id)) {
        $guest_id = str_replace('guest-', '', $author_id);
        $guest_author = $this->awpa_ma_get_guest_author($guest_id);
        if ($guest_author) {
          $args = array(
            'author_name' => $guest_author->user_nicename,
          );
          $author_filter_url = add_query_arg(array_map('rawurlencode', $args), admin_url('edit.php'));
    ?>
          <a href="<?php echo esc_url($author_filter_url); ?>" data-nice_name="<?php echo esc_attr($guest_author->user_nicename); ?>" data-user_email="<?php echo esc_attr($guest_author->user_email); ?>" data-display_name="<?php echo esc_attr($guest_author->display_name); ?>" data-avatar="<?php echo esc_attr(get_avatar_url($guest_author->id)); ?>"><?php echo esc_html($guest_author->display_name); ?></a>
          <?php echo ($count < count($coauthors)) ? ',' : ''; ?>
        <?php
        }
      } else {
        $author = get_user_by('id', $author_id);
        if ($author) {
          $args = array(
            'author_name' => $author->user_nicename,
          );
          if ('post' != $post->post_type) {
            $args['post_type'] = $post->post_type;
          }
          $author_filter_url = add_query_arg(array_map('rawurlencode', $args), admin_url('edit.php'));
        ?>
          <a href="<?php echo esc_url($author_filter_url); ?>" data-user_nicename="<?php echo esc_attr($author->user_nicename); ?>" data-user_email="<?php echo esc_attr($author->user_email); ?>" data-display_name="<?php echo esc_attr($author->display_name); ?>" data-user_login="<?php echo esc_attr($author->user_login); ?>" data-avatar="<?php echo esc_attr(get_avatar_url($author->ID)); ?>"><?php echo esc_html($author->display_name); ?></a>
          <?php echo ($count < count($coauthors)) ? ',' : ''; ?>

        <?php
        }
      }
      $count++;
    }
  }
  public function awpa_get_authors_frontend($post_id)
  {
    global $post;
    $coauthors = get_post_meta($post_id, 'wpma_author');
    $main_author = $post->post_author;
    if (gettype($coauthors) != 'string') {
      $coauthors[] = $main_author;
      $coauthors = array_unique($coauthors);
    } else {
      $coauthors = array();
      $coauthors[] = $main_author;
    }
    $count = 1;
    foreach ($coauthors as $key => $author_id) {
      if (preg_match('/\bguest-\b/', $author_id)) {
        $guest_id = str_replace('guest-', '', $author_id);
        $guest_author = $this->awpa_ma_get_guest_author($guest_id);
        if ($guest_author) {
          $permalink_structure = get_option('permalink_structure');
          if ($permalink_structure == '') {
            $author_url = site_url() . '?author_name=' . $guest_author->user_nicename;
          } else {
            $author_url = site_url() . '/author/' . $guest_author->user_nicename;
          }
        ?>
          <a href="<?php echo esc_url($author_url); ?>" data-nice_name="<?php echo esc_attr($guest_author->user_nicename); ?>" data-user_email="<?php echo esc_attr($guest_author->user_email); ?>" data-display_name="<?php echo esc_attr($guest_author->display_name); ?>" data-avatar="<?php echo esc_attr(get_avatar_url($guest_author->id)); ?>"><?php echo esc_html($guest_author->display_name); ?></a>
          <?php echo ($count < count($coauthors)) ? ',' : ''; ?>

        <?php
        }
        $count++;
      } else {
        $author = get_user_by('id', $author_id);
        if ($author) {

          $author_url = get_author_posts_url($author->ID, $author->user_nicename);
        ?>
          <a href="<?php echo esc_url($author_url); ?>"><?php echo esc_html($author->display_name); ?></a>
          <?php echo ($count < count($coauthors)) ? ',' : ''; ?>

<?php
        }
        $count++;
      }
    }
  }
  public function awpa_ma_get_guest_author($guest_id)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "wpa_guest_authors";
    $query = "SELECT id, user_email, display_name, user_nicename FROM $table_name where id = $guest_id";
    $guest_author = $wpdb->get_results($query, OBJECT);

    return $guest_author ? $guest_author[0] : false;
  }
  public function awpa_ma_change_user_capabilities($allcaps, $caps, $args)
  {
    $cap = $args[0];
    $user_id = isset($args[1]) ? $args[1] : 0;
    $post_id = isset($args[2]) ? $args[2] : 0;
    $multiauthors = get_post_meta($post_id, 'wpma_author');
    $current_user = wp_get_current_user();

    $obj = get_post_type_object(get_post_type($post_id));
    if (!$obj || 'revision' == $obj->name) {
      return $allcaps;
    }

    $caps_to_modify = array(
      $obj->cap->edit_post,
      'edit_post',
      $obj->cap->edit_others_posts,
      'read_post',
      $obj->cap->read_post,
    );
    if (!in_array($cap, $caps_to_modify)) {
      return $allcaps;
    }

    if (is_user_logged_in() && $current_user->ID == $user_id && gettype($multiauthors) == 'array' && in_array($user_id, $multiauthors)) {
      $allcaps[$obj->cap->edit_others_posts] = true;
    }

    if (
      'publish' == get_post_status($post_id) &&
      (isset($obj->cap->edit_published_posts) && !empty($current_user->allcaps[$obj->cap->edit_published_posts]))
    ) {
      $allcaps[$obj->cap->edit_published_posts] = true;
    } elseif (
      'private' == get_post_status($post_id) &&
      (isset($obj->cap->edit_private_posts) && !empty($current_user->allcaps[$obj->cap->edit_private_posts]))
    ) {
      $allcaps[$obj->cap->edit_private_posts] = true;
    }

    return $allcaps;
  }
  public function awpa_ma_search_distinct()
  {
    return "DISTINCT";
  }

  public function awpa_ma_posts_join_filter($join, $query)
  {
    global $wpdb;

    $query_author_name = $query->get('author_name');
    $author = array();
    if ($query_author_name) {
      $author = $this->awpa_ma_get_user_by_nicename($query_author_name);
    }
    $query_author_name_id = $query->get('author');
    if ($query_author_name_id) {
      $author = $this->awpa_ma_get_user_by_id($query_author_name_id);
    }

    if (!$author) { //if no user author exists
      $guest_author = $this->awap_ma_get_guest_by_nicename($query_author_name);
      if ($guest_author) { //if guest author exists
        if ($guest_author->linked_user_id != "0" && $guest_author->is_linked == "1") {
          $join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
          $linked_author = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->users WHERE ID = %d", $guest_author->linked_user_id));
          if (is_admin()) { //check if backend panel
            if ($linked_author) {
              wp_redirect(admin_url("/edit.php?author_name=" . $linked_author->user_nicename), 301);
            }
          } else {
            wp_redirect(home_url('/author/' . $linked_author->user_nicename), 301);
          }
        } else {
          $join .= " INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
        }
        return $join;
      } else {
        return $join;
      }
    } else {
      $join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
      return $join;
    }

    return $join;
  }

  public function awpa_ma_posts_where_filter($where, $query)
  {
    global $wpdb;
    if (is_author() && $query->is_main_query() && !is_admin()) {
      $author = array();
      $query_author_name = $query->get('author_name');
      if ($query_author_name) {
        $author = $this->awpa_ma_get_user_by_nicename($query_author_name);
      }
      $query_author_name_id = $query->get('author');
      if ($query_author_name_id) {
        $author = $this->awpa_ma_get_user_by_id($query_author_name_id);
      }
      if (!$author) { //no user author found
        $guest_author = $this->awap_ma_get_guest_by_nicename($query_author_name);

        if ($guest_author && $guest_author->is_active == "1") { //guest is linked and is active
          $where = preg_replace('/AND\s*\((?:' . $wpdb->posts . '\.)?post_author\s*\=\s*\d+\)/', ' ', $where);
          $where = preg_replace('/AND\s*' . $wpdb->posts . '\.post_author\s*IN\s*\([0-9]*\)/', ' ', $where, 1);
          if ($guest_author->linked_user_id != "0" && $guest_author->is_linked == "1") { //guest has linked user and is linked
            $where .= " AND ($wpdb->postmeta.meta_value = 'guest-{$guest_author->id}'
                                        OR ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = '{$guest_author->linked_user_id}')
                                        OR $wpdb->posts.post_author = '{$guest_author->linked_user_id}')";
          } else {
            $where .= " AND ($wpdb->postmeta.meta_value = 'guest-{$guest_author->id}')";
          }
          return $where;
        } else {
          return $where;
        }
      } else { //user author found
        $where = preg_replace('/AND\s*\((?:' . $wpdb->posts . '\.)?post_author\s*\=\s*\d+\)/', ' ', $where, 1); //remove post_author for sql query
        $where = preg_replace('/AND\s*' . $wpdb->posts . '\.post_author\s*IN\s*\([0-9]*\)/', ' ', $where, 1);
        $author_id = (int) $author->ID;
        $guest_author = $this->apwa_ma_get_guest_by_linked_user_id($author_id); //check if user is linked with guests
        if ($guest_author && $guest_author->id == $author_id && $guest_author->is_linked == "1" && $guest_author->is_active == "1") { //
          $where .= " AND (
                                    ($wpdb->posts.post_author = {$author_id}) OR
                                    ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = {$author_id})
                                    OR ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = 'guest-{$guest_author->id}'))";
        } else {
          $where .= " AND (
                            ($wpdb->posts.post_author = {$author_id}) OR
                            ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = {$author_id}))";
        }
      }
    }
    // Reset post_author to prevent conflicts with the author title in the loop.
    add_filter('the_posts', array($this, 'awpa_reset_post_author'), 10, 2);
    return $where;
  }

  public function awpa_reset_post_author($posts, $query)
  {
    if ($query->is_author() && $query->is_main_query() && !is_admin()) {
      foreach ($posts as &$post) {
        $post->post_author = $query->get_queried_object_id();
      }
    }

    return $posts;
  }

  public function awpa_ma_get_user_by_nicename($value)
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->users WHERE user_nicename = %s", $value));
  }
  public function awpa_ma_get_user_by_id($value)
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->users WHERE ID = %d", $value));
  }

  public function awpa_ma_get_guest_by_email($email)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "wpa_guest_authors";
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table_name . " WHERE user_email = %s", $email));
  }

  public function awap_ma_get_guest_by_nicename($value)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpa_guest_authors';
    $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$table_name' AND TABLE_SCHEMA = '$wpdb->dbname' AND COLUMN_NAME = 'user_nicename'");
    if ($row) {
      if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE user_nicename = %s", $value));
        return $data;
      }
    }
    return false;
  }

  public function apwa_ma_get_guest_by_linked_user_id($author_id)
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpa_guest_authors WHERE linked_user_id = %s", $author_id));
  }

  public function awpa_ma_register_backend_scripts()
  {
    wp_enqueue_style('admin-metabox-css', AWPA_PLUGIN_URL . '/assets/css/admin.metabox.css', array(), AWPA_VERSION);

    $awpa_current_screent = get_current_screen();

    if ($awpa_current_screent->post_type == 'post') {
      if ($this->awpa_is_edit_page('new') || $this->awpa_is_edit_page('edit')) {
        wp_enqueue_script('authors-metabox', AWPA_PLUGIN_URL . 'assets/dist/authors_metabox.build.js', array(), AWPA_VERSION, true);
      }
    }
  }

  private function awpa_is_edit_page($new_edit = null)
  {
    global $pagenow;
    if (!is_admin()) {
      return false;
    }

    if ($new_edit == "edit") {
      return in_array($pagenow, array('post.php'));
    } elseif ($new_edit == "new") {
      return in_array($pagenow, array('post-new.php'));
    } else {
      return in_array($pagenow, array('post.php', 'post-new.php'));
    }
  }
}

$wpaMultiAuthors = new WPAMultiAuthors();
