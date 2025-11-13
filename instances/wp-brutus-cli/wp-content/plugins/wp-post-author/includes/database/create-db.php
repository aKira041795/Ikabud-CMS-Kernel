<?php
if (!function_exists('awpa_create_form_builder_table')) {
    add_action('admin_init', 'awpa_create_form_builder_table');
    function awpa_create_form_builder_table()
    {
        if (current_user_can('edit_posts')) {
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_form_builder";
            $charset_collate = $wpdb->get_charset_collate();
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $sql = "CREATE TABLE IF NOT EXISTS $table_name (
							id bigint(20) NOT NULL AUTO_INCREMENT,
							post_author bigint(20) UNSIGNED NOT NULL,
							post_title text NOT NULL,
							post_content longtext NOT NULL,
							form_settings longtext NOT NUll,
							payment_data longtext NOT NULL,
							social_login_setting longtext NULL,
							other_settings longtext NULL,
							post_status varchar(20) NOT NULL,
							post_date datetime NOT NULL,
							post_date_gmt datetime NOT NULL,
							post_modified datetime NOT NULL,
							post_modified_gmt datetime NOT NULL,
							editable TINYINT(1) NOT NULL DEFAULT '1',
							PRIMARY KEY id (id)
						) $charset_collate;";
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql);
            }
        }
    }
}

if (!function_exists('awpa_create_guest_authors_table')) {
    add_action('admin_init', 'awpa_create_guest_authors_table');
    function awpa_create_guest_authors_table()
    {
        if (current_user_can('edit_posts')) {
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_guest_authors";
            $charset_collate = $wpdb->get_charset_collate();
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $sql = "CREATE TABLE IF NOT EXISTS $table_name (
					id bigint(20) NOT NULL AUTO_INCREMENT,
					user_email text NOT NULL,
					display_name text NOT NULL,
					user_nicename text NOT NULL,
					first_name text NOT NULL,
					last_name text NOT NULL,
					description text NULL,
					user_registered datetime NOT NULL,
					website text NULL,
					is_active integer NOT NULL,
					user_meta text NULL,
					is_linked tinyInt(1) NOT NULL,
					avatar_name text NULL,
					linked_user_id bigInt(20) NULL,
					PRIMARY KEY id (id)
					) $charset_collate;";
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $data = dbDelta($sql);
            }
            do_action('awpa_call_seeder_function');
        }
    }
}
