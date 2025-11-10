import {
	Flex,
	PanelRow,
	SelectControl,
	withFilters,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';
import { withMeta } from '../hoc/withMeta';
import ColorPicker from './ColorPicker';
import { MetaProps } from './types';

const PrimaryMenuPanel = ({ meta, updateMeta }: MetaProps) => {
	return (
		<PanelRow>
			<Flex direction={'column'} style={{ flex: 1, marginTop: '6px' }}>
				<Flex className={'flexbox'}>
					<p>{__('Menu Item Color', 'zakra')}</p>
					<div style={{ marginLeft: 'auto' }}>
						<ColorPicker
							value={meta?.zakra_menu_item_color}
							onChange={(value) => {
								updateMeta?.('zakra_menu_item_color', value);
							}}
						/>
					</div>
				</Flex>
				<Flex className={'flexbox'}>
					<p>{__('Menu Item Hover Color', 'zakra')}</p>
					<div style={{ marginLeft: 'auto' }}>
						<ColorPicker
							value={meta?.zakra_menu_item_hover_color}
							onChange={(value) => {
								updateMeta?.('zakra_menu_item_hover_color', value);
							}}
						/>
					</div>
				</Flex>
				<Flex className={'flexbox'}>
					<p>{__('Menu Item Active Color', 'zakra')}</p>
					<div style={{ marginLeft: 'auto' }}>
						<ColorPicker
							value={meta?.zakra_menu_item_active_color}
							onChange={(value) => {
								updateMeta?.('zakra_menu_item_active_color', value);
							}}
						/>
					</div>
				</Flex>
				<Flex className={'flexbox'} direction={'column'} style={{ gap: '0px' }}>
					<p>{__('Active Menu Item Style', 'zakra')}</p>
					<div className="mainFlexbox">
						<SelectControl
							onBlur={function noRefCheck() {}}
							onFocus={function noRefCheck() {}}
							options={[
								{
									label: 'Default',
									value: '',
								},
								{
									label: 'None',
									value: 'style-1',
								},
								{
									label: 'Underline Border',
									value: 'style-2',
								},
								{
									label: 'Left Border',
									value: 'style-3',
								},
								{
									label: 'Right Border',
									value: 'style-4',
								},
							]}
							value={meta?.zakra_menu_active_style}
							size="__unstable-large"
							style={{ width: '100%' }}
							onChange={(value) => {
								updateMeta?.('zakra_menu_active_style', value);
							}}
						/>
					</div>
				</Flex>
			</Flex>
		</PanelRow>
	);
};

export default withMeta(
	withFilters('ZakraMetaPrimaryMenuPanel')(PrimaryMenuPanel),
);
