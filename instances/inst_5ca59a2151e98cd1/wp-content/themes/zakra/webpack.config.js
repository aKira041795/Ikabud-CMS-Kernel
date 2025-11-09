const defaults = require('@wordpress/scripts/config/webpack.config');
const { resolve } = require('path');
const ForkTsCheckerPlugin = require('fork-ts-checker-webpack-plugin');

module.exports = {
	...defaults,
	output: {
		filename: '[name].js',
		path: resolve(process.cwd(), 'assets/build'),
	},
	entry: {
		meta: resolve(process.cwd(), 'assets/js/meta', 'meta.tsx'),
		dashboard: resolve(process.cwd(), 'assets/js/dashboard', 'index.tsx'),
	},
	plugins: [...defaults.plugins, new ForkTsCheckerPlugin()],
	module: {
		...defaults.module,
		rules: [
			...defaults.module.rules,
			{
				test: /\.(svg)$/i,
				use: [
					{
						loader: 'file-loader',
					},
				],
			},
		],
	},
	// devServer:
	// 	process.env.NODE_ENV === 'production'xx
	// 		? undefined
	// 		: {
	// 				...defaults.devServer,
	// 				headers: { 'Access-Control-Allow-Origin': '*' },
	// 				allowedHosts: 'all',
	// 			},
};
