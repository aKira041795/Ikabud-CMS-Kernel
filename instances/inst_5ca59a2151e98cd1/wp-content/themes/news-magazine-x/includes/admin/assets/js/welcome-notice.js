jQuery( document ).ready( function( $ ) {
    "use strict";

    // Install/Activate Core Plugin
    $('.newsx-btn-get-started').on('click', function() {
        if ( 'no' === NewsxWelcomeNotice.core_installed ) {
            installActivatePluginViaAJAX('news-magazine-x-core');
            $(this).find('span').text(NewsxWelcomeNotice.installing_text);
            $(this).append('<span class="dot-flashing"></span>');
        } else {
            activatePluginViaAJAX('news-magazine-x-core');
        }
    });

    function installActivatePluginViaAJAX( slug ) {
        wp.updates.installPlugin({
            slug: slug,
            success: function() {
                $.ajax({
                    type: 'POST',
                    url: NewsxWelcomeNotice.ajaxurl,
                    data: {
                        action: 'newsx_activate_required_plugins',
                        plugin: slug,
                        nonce: NewsxWelcomeNotice.nonce,
                    },
                    success: function( response ) {
                        console.log('success', response);
                        activatePluginViaAJAX(slug);
                    },
                    error: function( response ) {
                        console.log('error', response);
                    }
                });
            },
            error: function( xhr, ajaxOptions, thrownerror ) {
                console.log(xhr)
                if ( 'folder_exists' === xhr.errorCode ) {
                    $.ajax({
                        type: 'POST',
                        url: NewsxWelcomeNotice.ajaxurl,
                        data: {
                            action: 'newsx_activate_required_plugins',
                            plugin: slug,
                            nonce: NewsxWelcomeNotice.nonce,
                        },
                        success: function( response ) {
                            console.log('success', response);
                        }
                    });
                }
            },
        });
    }

    function activatePluginViaAJAX( slug ) {
        $.ajax({
            type: 'POST',
            url: NewsxWelcomeNotice.ajaxurl,
            data: {
                action: 'newsx_activate_required_plugins',
                plugin: slug,
                nonce: NewsxWelcomeNotice.nonce,
            },
            success: function( response ) {
                console.log('success', response);
                window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=newsx-options&tab=starter-templates';
            },
            error: function( response ) {
                console.log('error', response);
            }
        });
    }
} );