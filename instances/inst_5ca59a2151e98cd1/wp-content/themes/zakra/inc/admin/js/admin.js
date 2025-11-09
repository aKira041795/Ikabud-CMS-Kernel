/**
 * Remove activate button and replace with activation in progress button.
 *
 * @package ColorMag
 */

/**
 * Import button
 */
jQuery(document).ready(function ($) {
	$('.btn-get-started').click(function (e) {
		e.preventDefault();

		// Show updating gif icon and update button text.
		$(this).addClass('updating-message').text(zakraRedirectDemoPage.btn_text);

		var btnData = {
			action: 'import_button',
			security: zakraRedirectDemoPage.nonce,
		};

		$.ajax({
			type: 'POST',
			url: ajaxurl, // URL to "wp-admin/admin-ajax.php"
			data: btnData,
			success: function (response) {
				var redirectUri,
					dismissNonce,
					extraUri = '',
					btnDismiss = $('.zakra-message-close');

				if (btnDismiss.length) {
					dismissNonce = btnDismiss
						.attr('href')
						.split('_zakra_notice_nonce=')[1];
					extraUri = '&_zakra_notice_nonce=' + dismissNonce;
				}

				redirectUri = response.redirect;
				console.log(redirectUri);
				window.location.href = redirectUri;
			},
			error: function (xhr, ajaxOptions, thrownError) {
				console.log(thrownError);
			},
		});
	});
});

jQuery(document).ready(function ($) {
	$('#zak-notification').click(function (e) {
		// Add the 'open' class to the zak-dialog element
		e.stopPropagation(); // Prevent the click event from reaching the document
		$('#zak-dialog').addClass('open');
		$('.zak-wrap').addClass('overlay');
	});

	// Remove 'open' class when clicking on zak-dialog-close
	$('#zak-dialog-close').click(function (e) {
		e.stopPropagation(); // Prevent the click event from reaching the overlay
		$('#zak-dialog').removeClass('open');
		$('.zak-wrap').removeClass('overlay');
	});

	// Close the dialog when clicking outside the dialog area
	$(document).on('click', function (e) {
		if (
			!$('#zak-dialog').is(e.target) &&
			$('#zak-dialog').has(e.target).length === 0
		) {
			$('#zak-dialog').removeClass('open');
			$('.zak-wrap').removeClass('overlay');
		}
	});
});

jQuery(document).ready(function ($) {
	// Access the admin URL from the localized script vars
	var adminUrl = zakraRedirectDemoPage.admin_url;

	var targetUrl = adminUrl + 'themes.php?page=zakra';

	// Get the current page URL
	var currentPage = window.location.href;

	// Loop through each menu item and check if it corresponds to the current page
	$('.zak-dashboard-menu-container ul li').each(function () {
		var pageURL = $(this).find('a').attr('href');
		$;
		// If the page URL matches the current URL, add the 'active' class
		if (currentPage === pageURL) {
			$(this).addClass('active');
		}
	});

	// Check if the current page URL matches the specific URL
	if (currentPage === targetUrl) {
		// Add the 'active' class to the first li
		$('.zak-dashboard-menu-container ul li:first').addClass('active');
	}
});

jQuery(document).ready(function ($) {
	$('.install-plugin, .activate-plugin').on('click', function (e) {
		e.preventDefault();
		var button = $(this);
		var plugin = button.data('plugin');
		var pluginSlug = button.data('slug');
		var action = button.hasClass('install-plugin')
			? 'install_plugin'
			: 'activate_plugin';
		var data = {
			action: action,
			plugin: plugin,
			slug: pluginSlug,
			security: zakraRedirectDemoPage.nonce,
		};

		// Add loading animation and update text
		var originalText = button.html();
		button.html(
			'<i class="fa fa-spinner fa-spin"></i> ' + zakraRedirectDemoPage.btn_text,
		);

		$.post(zakraRedirectDemoPage.ajaxurl, data, function (response) {
			// Restore the button text after completion
			button.html('Activated');

			if (response.success) {
				// Optional: You can perform additional actions here if needed.
				if (
					button.hasClass('activate-plugin') ||
					button.hasClass('install-plugin')
				) {
					button.removeClass('activate-plugin install-plugin');
					button.addClass('activated-plugin');

					// Add the 'activated-plugin' class to the parent span
					button.parent('span').addClass('activated');
				}
			} else {
				// Handle the case when the response is not successful
			}
		});
	});
});

function showTab(tabId, button) {
	var buttons = document.getElementsByClassName('zak-tab-button');
	for (var i = 0; i < buttons.length; i++) {
		buttons[i].classList.remove('active-button');
	}

	// Add 'active-button' class to the clicked button
	button.classList.add('active-button');
	// Hide all tabs
	var tabs = document.getElementsByClassName('zak-tab-content');
	for (var i = 0; i < tabs.length; i++) {
		tabs[i].classList.remove('active-tab');
	}

	// Show the selected tab
	document.getElementById(tabId).classList.add('active-tab');
}

document.addEventListener('DOMContentLoaded', function () {
	// Select all elements with the class name 'fvp-heading'
	var headings = document.querySelectorAll('.nfvp-heading');

	// Get the offsetTop of each heading
	var offsets = Array.from(headings).map(function (heading) {
		return heading.offsetTop;
	});

	// Add a scroll event listener
	window.addEventListener('scroll', function () {
		var scrollPosition = window.scrollY;

		// Loop through each heading and add/remove 'sticky' class based on scroll position
		headings.forEach(function (heading, index) {
			if (scrollPosition >= offsets[index]) {
				heading.classList.add('sticky');
			} else {
				heading.classList.remove('sticky');
			}
		});
	});
});
