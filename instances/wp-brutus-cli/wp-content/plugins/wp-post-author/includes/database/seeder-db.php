<?php global $wpdb;
$table_name = $wpdb->prefix . "wpa_form_builder";

$wpdb->insert($table_name, array(
    'post_author' => 1,
    'post_title' => 'User Registration (Free)',
    'post_content' => '[{"id":1,"name":"email","type":"email","label":"E-mail","hide_label":"false","tool_tip_msg":"","required":true,"description":"","placeholder":"Enter e-mail address","classname":"email","custom_classname":""},{"id":2,"name":"password","type":"password","label":"Password","hide_label":"false","tool_tip_msg":"","required":true,"description":"","placeholder":"","classname":"password","custom_classname":""}]',
    'form_settings' => '{"enable_mailchimp":false,"enable_constant_contact":false}',
    'payment_data' => '{"paymentProcess":"free","currency":"USD","amount":"10","membershipPlans":[]}',
    'social_login_setting' => '',
    'other_settings' => null,
    'post_status' => 'publish',
    'post_date' => gmdate('Y-m-d H:i:s', time()),
    'post_date_gmt' => gmdate('Y-m-d H:i:s', time()),
    'post_modified' => gmdate('Y-m-d H:i:s', time()),
    'post_modified_gmt' => gmdate('Y-m-d H:i:s', time()),
    'editable' => 0,
), array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'));