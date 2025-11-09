(function( $ ) {
	"use strict";

	var NewsxCustomizer = {
        lastFocusedSection: '',
		svgIconsArray: {},
		iconsLoaded: false,

		init: function() {
			$('.customize-control-sidebar_block_editor').prepend(
				'<div class="newsx-widget-area-info"><span class="dashicons dashicons-info"></span><span>' + 
				NewsxCustomizerSettings.widget_area_info_text + 
				'</span></div>'
			);
			NewsxCustomizer.mutationsObservers();

			// Load SVG Icons
			NewsxCustomizer.loadSvgIcons();

			// On Section Click
			NewsxCustomizer.onSectionClick();

			// Add Info to Front Page Repeater Header
			NewsxCustomizer.addInfoToFPRepeaterHeader();

			// Add Info to Front Page Widget Area Select
			NewsxCustomizer.addInfoToFPWidgetAreaSelect();

			// Init Icon Select Control
			NewsxCustomizer.iconSelectControlInit();

			// Init Repeater Radio Image Control
			NewsxCustomizer.repeaterRadioImageControlInit();

			// Init Default Radio Image Control
			NewsxCustomizer.defaultRadioImageControlInit();

			// Init Background Control
			NewsxCustomizer.backgroundControlInit();

			// Init Typography Control
			NewsxCustomizer.typographyControlInit();

			// Fix Customizer Section Back Button
            NewsxCustomizer.fixCustomizerBackButtonClick();

			// Fix Custom Logo display issue
            NewsxCustomizer.fixCustomLogoDisplay();

			// Fix Repeater Rows Minimize
			NewsxCustomizer.fixRepeaterRowsMinimize();

            // Repeater control Remove button
            NewsxCustomizer.repeaterRemoveButtonClick();
	
			// If Repeater has only one item, disable Remove button
            NewsxCustomizer.fixRepeaterRemoveButton();

			// Repeater control Add button
			NewsxCustomizer.repeaterAddButtonClick();

			// Change Footer Columns in Repeater
			NewsxCustomizer.fixFooterSectionsColumnsChange();

			// Adjust Controls by Version
			NewsxCustomizer.adjustControlsByVersion();
		},

		mutationsObservers: function() {

			// Track Live Changes
			var mutationObserver = new MutationObserver(function(mutations) {
                // Fix Repeater Rows Minimize
                NewsxCustomizer.fixRepeaterRowsMinimize();

                // Repeater Element Edit button
                NewsxCustomizer.repeaterElementEditOptionsButtonClick();

				// Repeater control Remove button
                NewsxCustomizer.repeaterRemoveButtonClick();

                // Repeater control Add button
                NewsxCustomizer.repeaterAddButtonClick();

				// Front Page Sections Layout Change
				NewsxCustomizer.fixFrontPageSectionsLayoutChange();

				// Footer Select Element Change
				NewsxCustomizer.fixFooterSelectElementChange();

				// Init Icon Select Control
				NewsxCustomizer.iconSelectControlInit();

				// Init Repeater Radio Image Control
				NewsxCustomizer.repeaterRadioImageControlInit();

				// Init Background Control
				NewsxCustomizer.backgroundControlInit();

				// Init Typography Control
				NewsxCustomizer.typographyControlInit();

				// Add Front Page Repeater Header Info
				NewsxCustomizer.addInfoToFPRepeaterHeader();
			});

            // Define Elements to observe
			let selectors = [
                'section_hd_top_elements',
                'section_hd_middle_elements',
                'section_hd_bottom_elements',
                'section_ft_top_elements',
                'section_ft_middle_elements',
                'section_ft_bottom_elements',
                'header_social_icons',
                'footer_social_icons',
				'section_hd_top_bg-background-tabs',
				'front_page_sections',
            ];

            // Only observe elements that exist in the DOM
            selectors.forEach(function(selector) {
                let target = $('#customize-control-newsx_options-' + selector)[0];
                if (target) {
                    mutationObserver.observe(target, {
                        childList: true,
                        subtree: true,
                    });
                }
            });
		},

		onSectionClick: function() {
			$('.accordion-section').on('click', function() {
				NewsxCustomizer.defaultRadioImageControlInit();
			});
		},

		loadSvgIcons: function() {
			// Load SVG icons from json file
			$.getJSON(NewsxCustomizerSettings.themeurl + '/assets/svg/svg-icons.json', function(data) {
				NewsxCustomizer.svgIconsArray = data;

				// Initialize Controls after icons are loaded
				NewsxCustomizer.iconSelectControlInit();
				NewsxCustomizer.repeaterRadioImageControlInit();

				NewsxCustomizer.iconsLoaded = true;
			}).fail(function(jqXHR, textStatus, errorThrown) {
				console.error('Failed to load SVG icons:', textStatus, errorThrown);
			});
		},

		iconSelectControlInit: function( forceLoad = false, $selector = '' ) {
			let defaultIcon = '<svg viewBox="0 0 32 32"><path d="M32 12.408l-11.056-1.607-4.944-10.018-4.944 10.018-11.056 1.607 8 7.798-1.889 11.011 9.889-5.199 9.889 5.199-1.889-11.011 8-7.798zM16 23.547l-6.983 3.671 1.334-7.776-5.65-5.507 7.808-1.134 3.492-7.075 3.492 7.075 7.807 1.134-5.65 5.507 1.334 7.776-6.983-3.671z"></path></svg>';
			
			// Generate icon list HTML if not loaded OR if forceLoad is true
			if ( !NewsxCustomizer.iconsLoaded && Object.keys(NewsxCustomizer.svgIconsArray).length > 0 ) {
				renderIconSelectControl( $('.newsx-icon-list-popup') );
			} else if ( forceLoad ) {
				renderIconSelectControl( $selector );
			}

			function renderIconSelectControl( $selector ) {
				let iconListHTML = '<div class="newsx-icon-search-wrap"><input type="text" class="newsx-icon-search" placeholder="Search icons..."></div>';
				iconListHTML += '<div class="newsx-icon-list">';
				for (let iconClass in NewsxCustomizer.svgIconsArray) {
					iconListHTML += '<span class="newsx-icon" title="' + iconClass + '">' + 
						NewsxCustomizer.svgIconsArray[iconClass] + 
					'</span>';
				}
				iconListHTML += '</div>';
				$selector.html(iconListHTML);

				// Set selected icon
				let $iconHolder = $('.newsx-icon-select-trigger .icon-holder');
				$iconHolder.each(function() {
					let selectedIcon = $(this).attr('data-selected');
					if (selectedIcon && NewsxCustomizer.svgIconsArray[selectedIcon]) {
						$(this).html(NewsxCustomizer.svgIconsArray[selectedIcon]);
					}
				});
			}
			
			// Icon Popup
			$('.newsx-icon-select-trigger span').on('click', function() {
				let wrapper = $(this).closest('.newsx-icon-select-trigger');

				wrapper.children('span').removeClass('active');
				$(this).addClass('active');

				if ( $(this).hasClass('icon-holder') ) {
					wrapper.next('.newsx-icon-list-popup').show();
				} else {
					wrapper.next('.newsx-icon-list-popup').hide();
				}
			});

			// Icon Search
			$('.newsx-icon-search').on('keyup', function() {
				let wrapper = $(this).closest('.newsx-icon-select-wrap'),
					search = $(this).val().toLowerCase(),
					list = wrapper.find('.newsx-icon-list'),
					icons = list.find('.newsx-icon');

				// if searching add searching class to list
				if ( search.length > 0 ) {
					list.addClass('searching');
				} else {
					list.removeClass('searching');
				}

				icons.each(function() {
					let icon = $(this).attr('title');

					if ( icon.toLowerCase().indexOf(search) > -1 ) {
						$(this).show();
					} else {
						$(this).hide();
					}
				});
			});

			// Icon Select
			$('.newsx-icon-list .newsx-icon').on('click', function() {
				let wrapper = $(this).closest('.newsx-icon-select-wrap'),
					iconClass = $(this).attr('title'),
					input = wrapper.find('.newsx-icon-value');

				// Set Icon
				input.val(iconClass).trigger('change').trigger('keyup');
				wrapper.find('.icon-holder').html( $(this).html() );

				// Close Popup
				wrapper.find('.newsx-icon-list-popup').hide();
			});

			$('.newsx-icon-select-trigger .icon-none').on('click', function() {
				let wrapper = $(this).closest('.newsx-icon-select-wrap'),
					input = wrapper.find('.newsx-icon-value');

				input.val('').trigger('change').trigger('keyup');
				wrapper.find('.icon-holder').html( defaultIcon );
			});

			// Hide popup on outside click
			$(document).mouseup(function(e) {
				let container = $('.newsx-icon-select-wrap');

				if ( container.has(e.target).length === 0 ) {
					$('.newsx-icon-list-popup').hide();
				}
			});
		},

		repeaterRadioImageControlInit: function() {
			if (Object.keys(NewsxCustomizer.svgIconsArray).length > 0) {
				$('.newsx-radio-image-control label').each(function() {
					let $this = $(this);

					if ( 0 === $this.find('svg').length ) {
						let icon = $this.attr('data-icon');
						if ( icon && NewsxCustomizer.svgIconsArray[icon] ) {
							$this.html(NewsxCustomizer.svgIconsArray[icon]);
						}
					}
				});
			}
		},

		defaultRadioImageControlInit: function() {
			$('.customize-control-kirki-radio-image .newsx-radio-image-control label').each(function() {
				let icon = $(this).attr('data-icon');
				
				if ( icon && NewsxCustomizer.svgIconsArray[icon] ) {
					$(this).html(NewsxCustomizer.svgIconsArray[icon]);
				}
			});
		},

		fixRepeaterRowsMinimize: function() {
            // Minimize other Repeater Rows when one is expanded
            $('.customize-control-kirki .repeater-row-header').on('click', function() {
                let repeaterRows = $(this).closest('.repeater-row').siblings('.repeater-row');
    
                repeaterRows.addClass('minimized');
            });
        },

        fixCustomizerBackButtonClick: function() {
            $('.customize-section-back').on('click', function() {
				var currentSection = $(this).closest('.accordion-section').attr('id'),
					currentSection = currentSection.replace('sub-accordion-section-', '');
	
				if (currentSection != NewsxCustomizer.lastFocusedSection && NewsxCustomizer.lastFocusedSection != '' ) {
					wp.customize.section(NewsxCustomizer.lastFocusedSection).focus();
				} else {
					setTimeout(function() {
						NewsxCustomizer.lastFocusedSection = '';
					}, 1000);
				}
			});
        },

		addInfoToFPRepeaterHeader: function() {
			let $front_page_repeater = $('#customize-control-newsx_options-front_page_sections');

			$front_page_repeater.find('.repeater-row-header').each(function() {
				let $this = $(this),
					$row = $this.closest('.repeater-row'),
					widgetArea = $row.find('select[data-field="widget_area"] option:selected').text();
					
				// Remove "- used" from widget area text if present
				widgetArea = widgetArea.replace(' - In Use', '');

				// Only append if info span doesn't exist
				if (!$this.find('.newsx-section-info').length) {
					$this.find('.repeater-row-label').append(' - <span class="newsx-section-info">' + widgetArea + '</span>');
				}
			});
		},

		addInfoToFPWidgetAreaSelect: function() {
			let $front_page_repeater = $('#customize-control-newsx_options-front_page_sections');
			if (!$front_page_repeater.length) return;

			// Get all widget area selects
			let $selects = $front_page_repeater.find('select[data-field="widget_area"]');
			if (!$selects.length) return;

			// Function to update all selects
			const updateAllSelects = () => {
				// Get all currently selected values
				let selectedWidgetAreas = [];
				$selects.each(function() {
					let value = $(this).val();
					if (value && value !== '0') {  // Exclude value="0"
						selectedWidgetAreas.push(value);
					}
				});

				// Update each select's options
				$selects.each(function() {
					// Mark as used if selected
					$(this).find('option').each(function() {
						let $option = $(this);
						$option.text($option.text().replace(' - In Use', ''));
						
						if (selectedWidgetAreas.includes($option.val()) && $option.val() !== '0') {  // Exclude value="0"
							$option.text($option.text() + ' - In Use');
						}
					});
				});
			};

			// Initial update
			updateAllSelects();

			// Update on select change
			$selects.off('change.widgetArea').on('change.widgetArea', function() {
				updateAllSelects();
			});
		},

        fixCustomLogoDisplay: function() {
            $('#customize-control-kirki_tabs_title_tagline .kirki-tab-menu-item[data-kirki-tab-menu-id="general"]').on('click', function() {
				$('#customize-control-custom_logo').css('display', 'list-item');
			});
			$('#customize-control-kirki_tabs_title_tagline .kirki-tab-menu-item[data-kirki-tab-menu-id="design"]').on('click', function() {
				$('#customize-control-custom_logo').hide();
			});
        },

		fixFrontPageSectionsLayoutChange: function() {
			let $front_page_repeater = $('#customize-control-newsx_options-front_page_sections');

			// Reset
			$front_page_repeater.find('.newsx-radio-image-control input[type=radio]').off('change');

			function showHideEditOptions( layout, $section ) {
				if ( layout.indexOf('ls') > -1 && layout.indexOf('rs') > -1 ) {
					$section.find('.newsx-customizer-edit-options-link').show();
				} else if ( layout.indexOf('ls') > -1 ) {
					$section.find('.newsx-customizer-edit-options-link.lsd').show();
					$section.find('.newsx-customizer-edit-options-link.rsd').hide();
				} else if ( layout.indexOf('rs') > -1 ) {
					$section.find('.newsx-customizer-edit-options-link.rsd').show();
					$section.find('.newsx-customizer-edit-options-link.lsd').hide();
				} else {
					$section.find('.newsx-customizer-edit-options-link.lsd, .newsx-customizer-edit-options-link.rsd').hide();
				}
			}

			// on Load
			$front_page_repeater.find('.repeater-row-content').each(function() {
				let $section = $(this),
					layout = $section.find('.newsx-radio-image-control input[type=radio]:checked').val();

				showHideEditOptions( layout, $section );
			});

			// on Change
			$front_page_repeater.find('.newsx-radio-image-control input[type=radio]').on('change', function() {
				let $this = $(this),
					$section = $this.closest('.repeater-row-content'),
					layout = $this.val();
					
				showHideEditOptions( layout, $section );
			});
		},

		fixFooterSelectElementChange: function() {
			let $footer_repeater_fields = $('.customize-control-repeater[id*="newsx_options-section_ft_"]');

			// Reset
			$footer_repeater_fields.find('.repeater-field-select select').off('change');

            function showHideEditOptions( element, $section ) {
                // Add null check
                if (!element) {
                    return;
                }

                if ( 'string' === typeof element && element.indexOf('widgets') > -1 ) {
                    $section.find('.repeater-field-edit_element_options').hide();
                } else {
                    $section.find('.repeater-field-edit_element_options').show();
                }
            }

			// on Load
			$footer_repeater_fields.find('.repeater-row-content').each(function() {
				let $section = $(this),
					element = $section.find('.repeater-field-select select').val() || ''; // Provide default empty string

				showHideEditOptions( element, $section );
			});

			// on Change
			$footer_repeater_fields.find('.repeater-field-select select').on('change', function() {
				let $this = $(this),
					$section = $this.closest('.repeater-row-content'),
					element = $this.val() || ''; // Provide default empty string

				showHideEditOptions( element, $section );
			});
		},

		fixFooterSectionsColumnsChange: function() {
			wp.customize( 'newsx_options[section_ft_top_columns]', function( value ) {
				value.bind( function( newval ) {
					NewsxCustomizer.footerElementRepeaterHideExtraColumns('section_ft_top_elements', 'ft_top_element_position', newval);
				});
			});
			wp.customize( 'newsx_options[section_ft_middle_columns]', function( value ) {
				value.bind( function( newval ) {
					NewsxCustomizer.footerElementRepeaterHideExtraColumns('section_ft_middle_elements', 'ft_middle_element_position', newval);
				});
			});
			wp.customize( 'newsx_options[section_ft_bottom_columns]', function( value ) {
				value.bind( function( newval ) {
					NewsxCustomizer.footerElementRepeaterHideExtraColumns('section_ft_bottom_elements', 'ft_bottom_element_position', newval);
				});
			});
		},

		footerElementRepeaterHideExtraColumns: function(repeater, control, newval) {
			let repRow = $('#customize-control-newsx_options-'+ repeater),
				repCtrl = '.repeater-field-'+ control;

			// Reset
			repRow.find(repCtrl).find('.kirki-radio-buttonset label').removeClass('newsx-disabled');

			// Add
			repRow.find(repCtrl).find('.kirki-radio-buttonset').each(function(index) {
				$(this).find('label').each(function(index) {
					if ( index > parseInt(newval) - 1 ) {
						$(this).addClass('newsx-disabled');
					}
				});
			});
		},

        fixRepeaterRemoveButton: function() {
            $('.customize-control-kirki .repeater-fields').each(function() {
				let repeaterRows = $(this).children('.repeater-row').length;
	
				if ( 1 == repeaterRows ) {
					$(this).find('.newsx-repeater-row-remove').hide();
				}
			});
        },

        repeaterAddButtonClick: function() {
            $('.customize-control-kirki .repeater-add').on('click', function() {
                let repeaterRows = $(this).siblings('.repeater-fields').children('.repeater-row');
    
                // Minimize all Repeater Rows when Add button is clicked
                repeaterRows.addClass('minimized');
                
                // Show remove button on repeater Add button click
                repeaterRows.find('.newsx-repeater-row-remove').show();

                // Update widget area options after new row is added
                if ($(this).closest('#customize-control-newsx_options-front_page_sections').length) {
                    setTimeout(() => {
                        NewsxCustomizer.addInfoToFPWidgetAreaSelect();
                    }, 100);
                }

				// Reinitialize icon select control
				setTimeout(() => {
					NewsxCustomizer.iconSelectControlInit(true, repeaterRows.last().next().find('.newsx-icon-list-popup'));
				}, 100);

                // Hide Extra Footer Column Options
                let footerColumns = $(this).closest('li.customize-control').siblings('li[id*="_columns"]').find('.buttonset').find('input:checked').val();
                var sectionID = $(this).closest('li').siblings('li[id*="_columns"]').attr('id');

                if ( undefined == sectionID || !sectionID.indexOf('ft_top') || !sectionID.indexOf('ft_middle') || !sectionID.indexOf('ft_bottom') ) {
                    return;
                }
                
                sectionID = sectionID.replace('customize-control-newsx_options-', ''),
                sectionID = sectionID.replace('section_', ''),
                sectionID = sectionID.replace('_columns', '');

				console.log('off click1');
                // Wait for the new repeater row to be added to DOM then reinitialize icon select events
                setTimeout(() => {
                    NewsxCustomizer.footerElementRepeaterHideExtraColumns('section_'+ sectionID +'_elements', sectionID +'_element_position', footerColumns);
                }, 100);
            });
        },

        repeaterElementEditOptionsButtonClick: function() {
			// Builder Elements
            if ( $('.repeater-field-edit_element_options').length ) {
                // Reset 
                $('.repeater-field-edit_element_options a').off('click');

                // Bind click event to edit button
                $('.repeater-field-edit_element_options a').on('click', function() {
                    var targetSection = $(this).closest('.repeater-row-content').find('select').val(),
                        targetSection = targetSection.replace(/-/g, '_'),
                        currentSection = $(this).closest('.accordion-section').attr('id'),
                        currentSection = currentSection.replace('sub-accordion-section-', ''),
                        prefix = 'newsx_section_';

                    NewsxCustomizer.lastFocusedSection = currentSection;

                    if ( -1 !== currentSection.indexOf('_hd_') ) {
                        prefix += 'hd_';
                    } else if ( -1 !== currentSection.indexOf('_ft_') ) {
                        prefix += 'ft_';
                    }

                    // Fix for site identity section
                    if ( 'site_identity' == targetSection ) {
                        targetSection = 'title_tagline';
                        prefix = '';
                    }

					// if ( targetSection.indexOf('widgets') > -1 ) {
					// 	prefix = 'sidebar-widgets-';
                    //     targetSection = targetSection.replace(/_/g, '-');
					// }

                    // Focus on the section
                    wp.customize.section(prefix + targetSection).focus();
                });
            }

			// Front Page Section Widgets
			if ( $('.repeater-field-edit_widgets').length ) {
				// Reset 
				$('.repeater-field-edit_widgets a').off('click');

				// Bind click event to edit button
				$('.repeater-field-edit_widgets a').on('click', function() {
					var $this = $(this),
						targetSectionID = $this.closest('.repeater-row').find('select[data-field="widget_area"]').val(),
						currentSection = $this.closest('.accordion-section').attr('id'),
						currentSection = currentSection.replace('sub-accordion-section-', ''),
						prefix = 'sidebar-widgets-front-page-section-',
						sufix = '-content';

					NewsxCustomizer.lastFocusedSection = currentSection;

					// Suffix for Sidebars
					if ( $this.hasClass('lsd') ) {
						sufix = '-left-sidebar';
					} else if ( $this.hasClass('rsd') ) {
						sufix = '-right-sidebar';
					}

					// Focus on the section
					wp.customize.section(prefix + targetSectionID + sufix).focus();
				});
			}
        },

        repeaterRemoveButtonClick: function() {
            $('.customize-control-kirki .repeater-row-header .newsx-repeater-row-remove').on('click', function() {
                // $(this).closest('.repeater-row').find('.repeater-row-remove').trigger('click');

                // Don't allow last repeater row to be removed
                let repeaterRows = $(this).closest('.repeater-fields').children('.repeater-row').length;
            
                if ( 2 == repeaterRows ) {
                    $(this).closest('.repeater-fields').find('.newsx-repeater-row-remove').hide();
                }
            });
        },

		backgroundControlInit: function() {
			$('.newsx-background-ctrl-trigger').off('click').on('click', function() {
				let $this = $(this).closest('.customize-control'),
					$tabsUL = $this.find('.newsx-background-ctrl-tabs'),
					$color = $this.siblings('li[id*="background-color"]'),
					$gradient = $this.siblings('li[id*="gradient-color-1"], li[id*="gradient-color-2"], li[id*="gradient-pos-1"], li[id*="gradient-pos-2"], li[id*="gradient-angle"]'),
					$image = $this.siblings('li[id*="background-image"], li[id*="background-repeat"], li[id*="background-position"], li[id*="background-size"], li[id*="background-attachment"]');
				
				if ( $tabsUL.hasClass('newsx-open') ) {
					$tabsUL.removeClass('newsx-open');
				} else {
					$tabsUL.addClass('newsx-open');
				}

				// Get Saved Value
				let thisVal = $this.find('input').val(),
					savedTab = thisVal.split('|')[0];

				// Open Popup
				if ( $tabsUL.hasClass('newsx-open') ) {
					$tabsUL.children('li[data-tab-id="'+ savedTab +'"]').first().addClass('active');
					
					if ( 'color' == savedTab ) {
						$color.addClass('active');
					} else if ( 'gradient' == savedTab ) {
						$gradient.addClass('active');
					} else if ( 'image' == savedTab ) {
						$image.addClass('active');
					}

					$this.siblings('.newsx-background-field.active:last').addClass('last-active');
				} else {
					NewsxCustomizer.closeBackgroundControlPopup($this);
				}

				$tabsUL.find('li').off('click').on('click', function() {
					let $this = $(this).closest('.customize-control'),
						tab = $(this).attr('data-tab-id');

					$tabsUL.find('li').removeClass('active');
					$(this).addClass('active');

					// Reset
					$this.siblings('.newsx-background-field').removeClass('active');

					// Show Active Tab
					if ( 'color' == tab ) {
						$color.addClass('active');
					} else if ( 'gradient' == tab ) {
						$gradient.addClass('active');
					} else if ( 'image' == tab ) {
						$image.addClass('active');
					}
					
					$this.siblings('.newsx-background-field.active:last').addClass('last-active');
				});

				// Close Background Control
				$(document).on('click', NewsxCustomizer.closeBackgroundControlOnDocClick);
				
			});
		},

		closeBackgroundControlPopup: function( $this ) {
			let value = $this.find('input').val(),
				newValue = '';

			// Get Current Tab
			let currentTabID = $this.find('.newsx-background-ctrl-tabs li.active').attr('data-tab-id'),
				colorValue = $this.siblings('li[id*="background-color"]').find('.kirki-color-input').val(),
				gradColor1Value = $this.siblings('li[id*="gradient-color-1"]').find('.kirki-color-input').val(),
				gradColor2Value = $this.siblings('li[id*="gradient-color-2"]').find('.kirki-color-input').val();
			
			let valueHolder = $this.find('.newsx-background-ctrl-trigger').children('div');
				valueHolder.html('');
				valueHolder.removeAttr('style');

			if ( 'color' == currentTabID ) {
				newValue = colorValue;
				valueHolder.css('background-color', colorValue);

			} else if ( 'gradient' == currentTabID ) {
				newValue = gradColor1Value + '|' + gradColor2Value;
				valueHolder.css('background-image', 'linear-gradient('+ gradColor1Value +', '+ gradColor2Value +')');
			} else {
				newValue = 'dashicons-format-image';
				valueHolder.html('<div class="dashicons dashicons-format-image"></div>');
			}

			// Set Value
			if ( value != newValue ) {
				value = currentTabID +'|'+ newValue;
				wp.customize($this.attr('data-kirki-setting'), setting => setting.set(value));
			}

			// Close Popup
			$this.find('.newsx-background-ctrl-tabs').removeClass('newsx-open');
			$this.find('.newsx-background-ctrl-tabs').find('li').removeClass('active');
			$this.siblings('.newsx-background-field').removeClass('active');
		},

		closeBackgroundControlOnDocClick: function(e) {
			if ($(e.target).is('button.image-upload-button, .media-modal, .media-modal *, .kirki-react-select__option') || 
				$(e.target).closest('.kirki-react-select__menu').length) {
				return;
			}

			if (!$(e.target).closest('.newsx-background-control').length &&
				!$(e.target).closest('.newsx-background-field').length) {
				
				NewsxCustomizer.closeBackgroundControlPopup( $('.newsx-background-ctrl-tabs.newsx-open').closest('.customize-control') );

				$(document).off('click', NewsxCustomizer.closeBackgroundControlOnDocClick);
			}
		},

		typographyControlInit: function() {
			$('.newsx-typography-ctrl-trigger').off('click').on('click', function() {
				let $this = $(this).closest('.customize-control');

				// Reset
				$('.customize-control.newsx-typography-field').removeClass('active');
				$('.customize-control .newsx-typography-ctrl-trigger').removeClass('newsx-open');

				// Open Popup
				$this.nextAll('li[data-kirki-setting*="[font-family]"]:eq(0)').addClass('newsx-typography-field active');
				$this.nextAll('li[data-kirki-setting*="[variant]"]:eq(0)').addClass('newsx-typography-field active');
				$this.nextAll('li[data-kirki-setting*="[text-align]"]:eq(0)').addClass('newsx-typography-field active');
				$this.nextAll('li[data-kirki-setting*="[text-transform]"]:eq(0)').addClass('newsx-typography-field active');
				$this.nextAll('li[data-kirki-setting*="[text-decoration]"]:eq(0)').addClass('newsx-typography-field active');
				$this.nextAll('li[data-kirki-setting*="[line-height]"]:eq(0)').addClass('newsx-typography-field active last-active');
				$this.nextAll('li[data-kirki-setting*="[letter-spacing]"]:eq(0)').addClass('newsx-typography-field active last-active');

				if ( 'customize-control-newsx_options-global_font_heading-typography-trigger' != $this.attr('id') ) {
					$this.nextAll('li[data-kirki-setting*="[font-size]"]:eq(0)').addClass('newsx-typography-field active');
					$this.nextAll('li[data-kirki-setting*="[font-size][desktop]"]:eq(0)').addClass('newsx-typography-field active');
					$this.nextAll('li[data-kirki-setting*="[font-size][tablet]"]:eq(0)').addClass('newsx-typography-field active');
					$this.nextAll('li[data-kirki-setting*="[font-size][mobile]"]:eq(0)').addClass('newsx-typography-field active');
				}

				$(this).addClass('newsx-open');

				// Close Typography Control
				$(document).on('click', NewsxCustomizer.closeTypographyControlOnDocClick);
			});
		},

		closeTypographyControlOnDocClick: function(e) {
			if ( $(e.target).is('.dashicons-edit, .kirki-react-select__option') || 
				$(e.target).closest('.kirki-react-select__menu').length ) {
				return;
			}

			if ( !$(e.target).closest('.newsx-typography-field').length ) {
				$('.customize-control.newsx-typography-field').removeClass('active');
				$('.customize-control .newsx-typography-ctrl-trigger').removeClass('newsx-open');

				$(document).off('click', NewsxCustomizer.closeTypographyControlOnDocClick);
			}
		},

		adjustControlsByVersion: function() {
			if (NewsxCustomizerSettings.premium_version == 'newsx-cstmzr-fr') {
				$('#customize-control-kirki_responsive__newsx_options-global_font_body-font-size').addClass('newsx-hidden-control');
				$('#customize-control-newsx_options-global_font_body-font-size-desktop').addClass('newsx-hidden-control');
				$('#customize-control-newsx_options-global_font_body-font-size-tablet').addClass('newsx-hidden-control');
				$('#customize-control-newsx_options-global_font_body-font-size-mobile').addClass('newsx-hidden-control');
			}
		}
	}

	wp.customize.bind( 'ready', NewsxCustomizer.init ); // wp.customize ready

})( jQuery );