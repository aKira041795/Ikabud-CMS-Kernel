// Toggle control
wp.customize.controlConstructor[ 'bloghash-toggle' ] = wp.customize.Control.extend({
	ready: function() {
		"use strict";

		var control = this;

		// Change the value
		control.container.on( 'click', '.bloghash-toggle-switch', function() {
			control.setting.set( ! control.setting.get() );
		});
	}
});