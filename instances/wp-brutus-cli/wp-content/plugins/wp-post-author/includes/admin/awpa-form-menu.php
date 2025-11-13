<?php

/**
 * Implement plugin menu.
 *
 * @package CoverNews
 */

//Exit if directly acess
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Add new registration menu items.
 */
function awpa_settings_menu($hook)
{
    
    add_submenu_page(
        'wp-post-author',
        __('Advanced Settings', 'wp-post-author'),
        __('Advanced Settings', 'wp-post-author'),
        'manage_options',
        'wp-post-author',
        'awpa_settings_page'
    );

    add_submenu_page(
        'wp-post-author',
        __('Registration Forms', 'wp-post-author'),
        __('Registration Forms', 'wp-post-author'),
        'manage_options',
        'awpa-registration-form',
        'awpa_user_registration_page'
    );

    add_submenu_page(
        'wp-post-author',
        __('Registered Users', 'wp-post-author'),
        __('Registered  Users', 'wp-post-author'),
        'manage_options',
        'awpa-members',
        'awpa_members_page'
    );

    $author_metabox = awpa_get_author_metabox_setting();
    if ($author_metabox && $author_metabox['enable_author_metabox']) {
        add_submenu_page(
            'wp-post-author',
            __('Guest Users', 'wp-post-author'),
            __('Guest Users', 'wp-post-author'),
            'manage_options',
            'awpa-multi-authors',
            'awpa_multi_authors_page'
        );
    }

    
}
add_action('admin_menu', 'awpa_settings_menu', 60);


function awpa_settings_page()
{
?><br />
    <div id="afwrap-react"></div>
<?php
}
function awpa_user_registration_page()
{
?>
    <div id="awpa-actions" data-for="textarea"></div>
    <div id="awpa-form-listing" class="awpa-all-forms-container">
    </div>
<?php
}

function awpa_add_registration_page()
{
?>
    <div id="awpa-form-builder-container" class="awpa-form-builder-container"></div>
<?php
}


function awpa_members_page()
{
?>
    <div id="afwrap-membership-dashboard"></div>
<?php
}


function awpa_multi_authors_page()
{
?>
    <div id="awpa-guest-authors" class="awpa-multi-authors"></div>
<?php
}
