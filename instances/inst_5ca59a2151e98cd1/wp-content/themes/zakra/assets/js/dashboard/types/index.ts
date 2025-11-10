export type Prettify<T> = {
	[K in keyof T]: T[K];
} & {};

export type zakraLocalized = {
	version: string;
	plugins: {
		'everest-forms/everest-forms.php': 'active' | 'inactive' | 'not-installed';
		'user-registration/user-registration.php':
			| 'active'
			| 'inactive'
			| 'not-installed';
		'learning-management-system/lms.php':
			| 'active'
			| 'inactive'
			| 'not-installed';
		'magazine-blocks/magazine-blocks.php':
			| 'active'
			| 'inactive'
			| 'not-installed';
		'themegrill-demo-importer/themegrill-demo-importer.php':
			| 'active'
			| 'inactive'
			| 'not-installed';
	};
	builder: boolean;
	demoUrl: string;
	demoImporter: string;
	customizerUrl: string;
	pro_version: string;
	zakraProUpdateNotice: boolean;
	zakraProUpdateUrl: string;
	adminUrl: string;
	themes: {
		zakra: 'active' | 'inactive' | 'no-installed';
		colormag: 'active' | 'inactive' | 'no-installed';
	};
	googleFonts: Array<{
		id: string;
		lastModified: string;
		family: string;
		popularity?: number;
		defSubset?: string;
		defVariant: string;
		subsets: string[];
		variants: string[];
		version: string;
	}>;
	fs?: Record<string, any>;
	userRoles: Record<string, string>;
	nonce: string;
	ajaxUrl: string;
	popupEditUrl: string;
};

export type EditorSettingsMap = {
	'section-width': number;
	'editor-blocks-spacing': number;
	'design-library': boolean;
	'responsive-breakpoints': {
		tablet: number;
		mobile: number;
	};
	'copy-paste-styles': boolean;
	'auto-collapse-panels': boolean;
};

export type PerformanceSettingsMap = {
	'local-google-fonts': boolean;
	'preload-local-fonts': boolean;
	'allow-only-selected-fonts': boolean;
	'allowed-fonts': Array<{
		label: string;
		value: string;
		id: string;
		lastModified: string;
		family: string;
		popularity?: number;
		defSubset?: string;
		defVariant: string;
		subsets: string[];
		variants: string[];
		version: string;
	}>;
};

export type AssetGenerationSettingsMap = {
	'external-file': boolean;
};

export type VersionControlSettingsMap = {
	'beta-tester': boolean;
};

export type MaintenanceModeSettingsMap = {
	mode: 'none' | 'maintenance' | 'coming-soon';
	'maintenance-page'?: number;
};

export type WPPagesResponse = Array<{
	id: number;
	title: {
		rendered: string;
	};
	[key: string]: unknown;
}>;

export type WPPluginsResponse = Array<{
	author: string;
	author_uri: string;
	description: {
		raw: string;
		rendered: string;
	};
	name: string;
	plugin: string;
	plugin_uri: string;
	requires_php: string;
	requires_wp: string;
	status: 'active' | 'inactive';
	textdomain: string;
	version: string;
	network_only: boolean;
	_link: {
		self: Array<{
			href: string;
		}>;
	};
}>;

export type ChangelogsMap = Array<{
	version: string;
	date: string;
	changes: {
		[key: string]: Array<string>;
	};
}>;
