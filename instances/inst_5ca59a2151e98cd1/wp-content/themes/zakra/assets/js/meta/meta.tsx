import { registerPlugin } from '@wordpress/plugins';
import { Plugin } from './Plugin';
import './meta.scss';

registerPlugin('zakra-meta-setting-sidebar', { render: Plugin });
