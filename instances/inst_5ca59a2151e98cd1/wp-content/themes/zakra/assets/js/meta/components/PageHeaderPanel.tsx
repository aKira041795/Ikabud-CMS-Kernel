import {
	CheckboxControl,
	Flex,
	PanelRow,
	withFilters,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';
import { withMeta } from '../hoc/withMeta';
import { MetaProps } from './types';

const PageHeaderPanel = ({ meta, updateMeta }: MetaProps) => {
	return (
		<PanelRow>
			<Flex align="baseline">
				<p>{__('Enable Page Header', 'zakra')}</p>
				<CheckboxControl
					checked={!!meta?.zakra_page_header}
					onChange={(value) => {
						updateMeta?.('zakra_page_header', value);
					}}
					className="checkboxWidth"
				/>
			</Flex>
		</PanelRow>
	);
};

export default withMeta(
	withFilters('ZakraMetaPageHeaderPanel')(PageHeaderPanel),
);
