(function ($) {

	'use strict';
	var addNew    = $( '#elespare-new-template__form__submit' );
	var btnAddNew = $( '#elespare-new-template__form__submit' );
    var back      = $( '.elespare-templates-modal__header__close--normal' );

	addNew.on(
		'click',
		function( e ) {
			e.preventDefault();
			var data = $( '#elespare-new-template__form' ).serialize();
			data    += '&_ajax_nonce=' + elesapreadminloco.nonce + '&action=elespare_create_post';
			$.ajax(
				{
					type: 'POST',
					url: elesapreadminloco.url,
					data: data,
					beforeSend: function (response) {
						btnAddNew.addClass( 'loading' );
					},
					success: function (response) {
						btnAddNew.removeClass( 'loading' );
						window.location.href = response;
					},
				}
			);
		}
	);
    back.on(
		'click',
		function( e ) {
			e.preventDefault();
			var url              = elesapreadminloco.edit;
			window.location.href = url;
		}
	);
})( jQuery );