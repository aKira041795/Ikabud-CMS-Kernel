import { withFilters } from '@wordpress/components';
import { withMeta } from '../hoc/withMeta';

interface PluginEndPanelProps {
	activePanel: any;
	togglePanel: any;
}

const PluginPanelEnd: React.FC<any> = (props: PluginEndPanelProps) => {
	console.log(props.activePanel, 'PluginPanelEnd');

	return null;
};

export default withMeta(withFilters('ZakraMetaPluginPanelEnd')(PluginPanelEnd));
