import domReady from '@wordpress/dom-ready';
import { createRoot, render } from '@wordpress/element';
import React from 'react';
import { App } from './App';
import './dashboard.scss';

domReady(() => {
	const root = document.getElementById('render_dashboard_page');

	if (!root) return;
	if (createRoot) {
		createRoot(root).render(<App />);
	} else {
		render(<App />, root);
	}
});
