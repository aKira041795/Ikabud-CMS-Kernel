jQuery( document ).ready( function( $ ) {
    "use strict";

    // Track plugin installation and status
    let pluginInstallationComplete = false;
    let requiredPlugins = {};

    // Install/Activate/Import
    $( '.newsx-import-button' ).on( 'click', function() {

        // Show Import Popup
        showImportPopup('first'); 

        // Get Template
        var template = $(this).closest('.newsx-template');

        // Start Import
        $( '.newsx-start-import' ).on( 'click', function() {
            let importCustomizer = $('.newsx-import-popup').find('#import-customizer'),
                importContent = $('.newsx-import-popup').find('#import-content');

            if ( importCustomizer.is(':checked') || importContent.is(':checked') ) {
                
                if ( importCustomizer.is(':checked') && importContent.is(':checked') ) {
                    fullImport( template );
                } else if ( importCustomizer.is(':checked') ) {
                    customizerImport( template );
                } else if ( importContent.is(':checked') ) {
                    contentImport( template );
                }
                
                // Show/Hide Popups
                showImportPopup('second');
                hideImportPopup('first');
            } else {
                alert('Please select at least one option.');
            }
        } );

    } );

    // Full Import
    function fullImport( template ) {
        let templateData = template.data('template-data');
        let templateSlug = templateData.slug;
        let templateType = templateData.type;

        importProgressBar('plugins');
        installRequiredPlugins( template );

        var installPlugins = setInterval(function() {

            if ( Object.values(requiredPlugins).every(Boolean) ) {

                // Reset Previous Template (if any) and then Import New one
                $.ajax({
                    type: 'POST',
                    url: NEWSXCoreAdmin.ajaxurl,
                    data: {
                        action: 'newsx_reset_previous_import',
                        nonce: NEWSXCoreAdmin.nonce,
                    },
                    success: function(response) {
                        // console.log(response);
                        console.log('Importing Template - '+ templateSlug);
                        importProgressBar('xml');
                        
                        // Import XML Data
                        $.ajax({
                            type: 'POST',
                            url: NEWSXCoreAdmin.ajaxurl,
                            data: {
                                action: 'newsx_import_xml_template',
                                template: templateSlug,
                                template_type: templateType,
                                nonce: NEWSXCoreAdmin.nonce,
                            },
                            success: function(response) {
                                console.log('XML Import successful!');

                                if ( 'plugin' === getOptionsPage() ) {
                                    importProgressBar('finish');
                                    return;
                                }
                                
                                importProgressBar('widgets');

                                // Import Widgets
                                $.ajax({
                                    type: 'POST',
                                    url: NEWSXCoreAdmin.ajaxurl,
                                    data: {
                                        action: 'newsx_import_widgets_data',
                                        template: templateSlug,
                                        template_type: templateType,
                                        nonce: NEWSXCoreAdmin.nonce,
                                    },
                                    success: function(response) {
                                        // console.log(response);
                                        console.log('Widgets Import successful!');
                                        importProgressBar('customizer');

                                        // Import Customizer Settings
                                        $.ajax({
                                            type: 'POST',
                                            url: NEWSXCoreAdmin.ajaxurl,
                                            data: {
                                                action: 'newsx_import_customizer_data',
                                                template: templateSlug,
                                                template_type: templateType,
                                                nonce: NEWSXCoreAdmin.nonce,
                                            },
                                            success: function(response) {
                                                // console.log(response);
                                                console.log('Customizer Import successful!');
                                                importProgressBar('settings');

                                                // Get Site Title & Tagline
                                                let siteIdentity = templateData.site_identity || {};
                                                
                                                // Setup General Settings
                                                $.ajax({
                                                    type: 'POST',
                                                    url: NEWSXCoreAdmin.ajaxurl,
                                                    data: {
                                                        action: 'newsx_setup_general_settings',
                                                        nonce: NEWSXCoreAdmin.nonce,
                                                        template_type: templateType,
                                                        site_identity: siteIdentity,
                                                    },
                                                    success: function(response) {
                                                        console.log('General Settings Setup successful!');
                                                        importProgressBar('finish');
                                                    },
                                                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                                                        console.error('General Settings Setup AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                                                    }
                                                });
                                            },
                                            error: function(XMLHttpRequest, textStatus, errorThrown) {
                                                console.error('Customizer Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                                            }
                                        });
                                    },
                                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                                        console.error('Widgets Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                                    }
                                });
                            },
                            error: function(XMLHttpRequest, textStatus, errorThrown) {
                                console.error('XML Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                            }
                        });

                    },
                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                        console.error('Reset AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                    }
                });

                // Clear
                clearInterval( installPlugins );
            }
        }, 1000);
    }

    // Customizer Import
    function customizerImport( template ) {
        let templateData = template.data('template-data'),
            templateSlug = templateData.slug,
            templateType = templateData.type;

        importProgressBar('widgets');

        // Import Widgets
        setTimeout(function() {
            $.ajax({
                type: 'POST',
                url: NEWSXCoreAdmin.ajaxurl,
                data: {
                    action: 'newsx_import_widgets_data',
                    template: templateSlug,
                    template_type: templateType,
                    nonce: NEWSXCoreAdmin.nonce,
                },
                success: function(response) {
                    // console.log(response);
                    console.log('Widgets Import successful!');
                    importProgressBar('customizer');
                            
                    // Import Customizer Settings
                    setTimeout(function() {
                        $.ajax({
                            type: 'POST',
                            url: NEWSXCoreAdmin.ajaxurl,
                            data: {
                                action: 'newsx_import_customizer_data',
                                template: templateSlug,
                                template_type: templateType,
                                nonce: NEWSXCoreAdmin.nonce,
                            },
                            success: function(response) {
                                // console.log(response);
                                console.log('Customizer Import successful!');
                                importProgressBar('finish');
                            },
                            error: function(XMLHttpRequest, textStatus, errorThrown) {
                                console.error('Customizer Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                            }
                        });
                    }, 1000);
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.error('Widgets Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                }
            });
        }, 1000);
    }

    // Content Import
    function contentImport( template ) {
        let templateData = template.data('template-data');
        let templateSlug = templateData.slug;
        let templateType = templateData.type;

        console.log('Importing Template - '+ templateSlug);
        importProgressBar('xml');

        // Reset Previous Template (if any) and then Import New one
        $.ajax({
            type: 'POST',
            url: NEWSXCoreAdmin.ajaxurl,
            data: {
                action: 'newsx_reset_previous_import',
                nonce: NEWSXCoreAdmin.nonce,
            },
            success: function(response) {
                // console.log(response);
                        
                // Import XML Data
                $.ajax({
                    type: 'POST',
                    url: NEWSXCoreAdmin.ajaxurl,
                    data: {
                        action: 'newsx_import_xml_template',
                        template: templateSlug,
                        template_type: templateType,
                        nonce: NEWSXCoreAdmin.nonce,
                    },
                    success: function(response) {
                        console.log('XML Import successful!');
                        importProgressBar('finish');
                    },
                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                        console.error('XML Import AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
                    }
                });
            },
            error: function(XMLHttpRequest, textStatus, errorThrown) {
                console.error('Reset AJAX error:', XMLHttpRequest, textStatus.responseText, errorThrown);
            }
        });
        
    }

    // Install Required Plugins
    function installRequiredPlugins(selector) {
        const $selector = $(selector);
        let templateData = $selector.data('template-data');
        requiredPlugins = templateData.plugins || {};
        
        // If no plugins required, mark as complete immediately
        if (!requiredPlugins || Object.keys(requiredPlugins).length === 0) {
            pluginInstallationComplete = true;
            startImportProcess();
            return;
        }

        // Initialize plugin status tracking
        Object.keys(requiredPlugins).forEach(plugin => {
            requiredPlugins[plugin] = false;
        });

        // Start plugin installations
        Object.keys(requiredPlugins).forEach(plugin => {
            installPluginViaAjax(plugin);
        });

        // Check installation progress every second
        const checkInterval = setInterval(() => {
            const allPluginsReady = Object.values(requiredPlugins).every(status => status === true);
            if (allPluginsReady) {
                clearInterval(checkInterval);
                pluginInstallationComplete = true;
                startImportProcess();
            }
        }, 1000);
    }

    // Install Plugin via AJAX
    function installPluginViaAjax(slug) {
        wp.updates.installPlugin({
            slug: slug,
            success: function() {
                activatePlugin(slug);
            },
            error: function(xhr) {
                if ('folder_exists' === xhr.errorCode) {
                    activatePlugin(slug);
                } else {
                    console.error('Plugin installation failed:', slug, xhr.errorCode);
                    handlePluginError(slug);
                }
            },
        });
    }

    // Activate Plugin
    function activatePlugin(slug) {
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'newsx_activate_required_plugins',
                plugin: slug,
                nonce: NEWSXCoreAdmin.nonce,
            },
            success: function(response) {
                if (response.success) {
                    console.log(`Plugin ${slug} activated successfully`);
                    requiredPlugins[slug] = true;
                } else {
                    console.error(`Plugin ${slug} activation failed:`, response.data);
                    handlePluginError(slug);
                }
            },
            error: function(xhr) {
                console.error(`Plugin ${slug} activation request failed:`, xhr);
                handlePluginError(slug);
            }
        });
    }

    // Handle plugin errors
    function handlePluginError(slug) {
        requiredPlugins[slug] = true; // Mark as complete to prevent hanging
        alert(`Warning: Plugin ${slug} installation/activation failed. Import process may be affected.`);
    }

    // Start the import process
    function startImportProcess() {
        if (!pluginInstallationComplete) {
            console.log('Waiting for plugin installation to complete...');
            return;
        }
        
        // Your existing import process code here
        console.log('Starting import process...');
        // Trigger your import steps here
    }

    // Close Import Popup
    $( '.newsx-import-popup-wrap .close-btn' ).on( 'click', function() {
        $(this).closest('.newsx-import-popup-wrap').css('display', 'none');
    } );

    function showImportPopup( selector ) {
        let popup = $('.newsx-import-popup-wrap.'+ selector);
        popup.css('display', 'flex');
    }

    function hideImportPopup( selector ) {
        let popup = $('.newsx-import-popup-wrap.'+ selector);
        popup.css('display', 'none');
    }

    function importProgressBar( step ) {
        var href = window.location.href,
            index = href.indexOf('/wp-admin'),
            homeUrl = href.substring(0, index);

        if ( 'theme' === getOptionsPage() ) {
            if ( 'full' === getSelectedImportMethod() ) {
                if ( 'plugins' === step ) {
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 1: Installing/Activating Plugins');
                } else if ( 'xml' === step ) {
                    $('.newsx-import-popup .progress-bar').animate({'width' : '20%'}, 500);
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 2: Importing Demo Content XML');
                } else if ( 'widgets' === step ) {
                    $('.newsx-import-popup .progress-bar').animate({'width' : '40%'}, 500);
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 3: Importing Widgets');
                } else if ( 'customizer' === step ) {
                    $('.newsx-import-popup .progress-bar').animate({'width' : '60%'}, 500);
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 4: Importing Customizer Settings');
                } else if ( 'settings' === step ) {
                    $('.newsx-import-popup .progress-bar').animate({'width' : '80%'}, 500);
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 5: Importing General Settings');
                }
            } else if ( 'customizer' === getSelectedImportMethod() ) {
                if ( 'widgets' === step ) {
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 1: Importing Widgets');
                    $('.newsx-import-popup .progress-bar').animate({'width' : '33%'}, 500);
                } else if ( 'customizer' === step ) {
                    $('.newsx-import-popup .progress-bar').animate({'width' : '63%'}, 500);
                    $('.newsx-import-popup .progress-wrap .steps').text('Step 2: Importing Customizer Settings');
                }
            } else if ( 'content' === getSelectedImportMethod() ) {
                $('.newsx-import-popup .progress-bar').animate({'width' : '33%'}, 500);
                $('.newsx-import-popup .progress-wrap .steps').text('Importing Demo Content XML');
            }

            if ( 'finish' === step ) {
                $('.newsx-import-popup .progress-bar').animate({'width' : '100%'}, 500);
                $('.newsx-import-popup .content').children('p').remove();
                $('.newsx-import-popup .progress-wrap').before('<p>Navigate to the <strong><a href="customize.php">Theme Customizer</a></strong> to continue customizing your website further.</p>');
                $('.newsx-import-popup .progress-wrap strong').html('Import Finished - <a href="'+ homeUrl +'" target="_blank">Visit Site</a>');
                $('.newsx-import-popup header h3').text('Import was Successfull!');
                $('.newsx-import-popup-wrap .close-btn').show();
            }
        } else {
            if ( 'xml' === step ) {
                $('.newsx-import-popup .progress-bar').animate({'width' : '20%'}, 500);
                $('.newsx-import-popup .progress-wrap .steps').text('Importing Demo Content XML');
            } else if ( 'finish' === step ) {
                $('.newsx-import-popup .progress-bar').animate({'width' : '100%'}, 500);
                $('.newsx-import-popup .content').children('p').remove();
                $('.newsx-import-popup .progress-wrap strong').html('Import Finished - <a href="'+ homeUrl +'" target="_blank">Visit Site</a>');
                $('.newsx-import-popup header h3').text('Import was Successfull!');
                $('.newsx-import-popup-wrap .close-btn').show();
            }
        }
    }

    function getOptionsPage() {
        let optionsPagee = $('body').hasClass('toplevel_page_newsx-options') ? 'theme' : 'plugin';
        return optionsPagee;
    }

    function getSelectedImportMethod() {
        let importCustomizer = $('.newsx-import-popup').find('#import-customizer'),
            importContent = $('.newsx-import-popup').find('#import-content');

        if ( importCustomizer.is(':checked') && importContent.is(':checked') ) {
            return 'full';
        } else if ( importCustomizer.is(':checked') ) {
            return 'customizer';
        } else if ( importContent.is(':checked') ) {
            return 'content';
        }
    }
} );