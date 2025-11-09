(function ($, api) {
	api.bind('ready', function () {
		api.panel('zakra_header', (panel) => {
			panel.expanded.bind((isExpanded) => {
				if (isExpanded) {
					$('.wp-full-overlay').attr(`data-zakra-header-panel`, 'active');
				} else {
					$('.wp-full-overlay').attr(`data-zakra-header-panel`, 'inactive');
				}
				const control = api.control('zakra_builder_heading');
				if (
					control &&
					control.deferred &&
					'resolved' === control.deferred.embedded.state()
				)
					return;
				if (control && typeof control.renderContent === 'function') {
					control.renderContent();
				}
				if (control && control.deferred && control.deferred.embedded) {
					control.deferred.embedded.resolve();
				}
				if (control && control.container) {
					control.container.trigger('init');
				}
			});
		});
	});
})(jQuery, wp.customize);
