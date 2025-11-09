import React from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import Dashboard from '../screens/dashboard/Dashboard';
import FreeVsPro from '../screens/free-vs-pro/FreeVsPro';
import Help from '../screens/help/Help';
import Products from '../screens/products/Products';
import { StarterTemplates } from '../screens/starter-templates/StarterTemplates';

const Router: React.FC = () => {
	const { pathname } = useLocation();

	React.useLayoutEffect(() => {
		const submenu = document.querySelector(
			`.wp-submenu a[href="admin.php?page=zakra#${pathname}"]`,
		);
		if (!submenu) return;
		submenu.parentElement?.classList.add('current');
		return () => {
			submenu.parentElement?.classList?.remove('current');
		};
	}, [pathname]);

	return (
		<Routes>
			<Route path="/" element={<Dashboard />} />
			{/*<Route path="/settings" element={<Settings />} />*/}
			<Route path="/demo-importer" element={<StarterTemplates />} />
			<Route path="/products" element={<Products />} />
			<Route path="/free-vs-pro" element={<FreeVsPro />} />
			<Route path="/help" element={<Help />} />
			<Route path="*" element={<Dashboard />} />
		</Routes>
	);
};

export default Router;
