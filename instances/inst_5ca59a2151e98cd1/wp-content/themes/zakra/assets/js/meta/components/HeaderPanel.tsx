import { Button, Flex, PanelRow, withFilters } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';
import React, { useState } from 'react';
import { withMeta } from '../hoc/withMeta';
import ButtonGroup from './ButtonGroup';
import { Customizer, HeaderCenter, HeaderLeft, HeaderRight } from './Icons';
import { MetaProps } from './types';

const HeaderPanel = ({ meta, updateMeta }: MetaProps) => {
	const [isHovered, setIsHovered] = useState(false);

	const media: undefined | null | Record<string, any> = useSelect(
		(select) => {
			return meta?.zakra_logo
				? (select('core') as any).getMedia(meta.zakra_logo)
				: null;
		},
		[meta?.zakra_logo],
	);

	const currentLayout = meta?.zakra_main_header_style ?? 'default';
	const headerOptions = [
		{ label: 'Default', value: 'customizer' },
		{ label: 'Enable', value: '1' },
		{ label: 'Disable', value: '0' },
	];
	const OPTIONS = applyFilters('zakra.meta.header.layout', [
		{
			label: __('From Customizer', 'zakra'),
			icon: Customizer,
			value: 'default',
		},
		{
			label: __('Header Left', 'zakra'),
			icon: HeaderLeft,
			value: 'zak-layout-1-style-1',
		},
		{
			label: __('Header Center', 'zakra'),
			icon: HeaderCenter,
			value: 'zak-layout-1-style-2',
		},
		{
			label: __('Header Right', 'zakra'),
			icon: HeaderRight,
			value: 'zak-layout-1-style-3',
		},
	]) as Array<{
		label: string;
		icon: React.ElementType;
		value: string;
	}>;

	const handleRemoveImage = () => {
		updateMeta?.('zakra_logo', null);
	};

	return (
		<PanelRow>
			<Flex className="mainFlexbox" direction={'column'}>
				<p>{__('Enable Transparent Header', 'zakra')}</p>
				<Flex style={{ flex: 1, flexWrap: 'wrap', gap: 0 }}>
					<div className="btn-group">
						<ButtonGroup
							options={headerOptions}
							selectedValue={meta?.zakra_transparent_header}
							onChange={(value: string) =>
								updateMeta?.('zakra_transparent_header', value)
							}
						/>
					</div>
				</Flex>

				<div className="padding-logo">
					<MediaUpload
						allowedTypes={['image']}
						onSelect={(data) => {
							updateMeta?.('zakra_logo', data.id);
						}}
						render={({ open }) => (
							<Flex
								direction={'column'}
								onMouseEnter={() => setIsHovered(true)}
								onMouseLeave={() => setIsHovered(false)}
								className={'relative'}
							>
								<p style={{ marginBottom: 0, marginRight: 'auto' }}>
									{__('Logo', 'zakra')}
								</p>
								{media ? (
									<>
										<div
											className="components-responsive-wrapper"
											style={{
												width: '100%',
												background: '#F4F4F4',
											}}
										>
											<div>
												<img
													src={
														media?.media_details?.sizes?.thumbnail?.source_url
													}
													alt="Featured Image"
													className="featureRelativeImg"
												/>
											</div>
										</div>
										{isHovered ? (
											<Flex className="positionAbsolute" justify="flex-start">
												<Button className="btnHeaderPanel" onClick={open}>
													{__('Replace', 'zakra')}
												</Button>
												<Button
													className="btnHeaderPanel"
													onClick={handleRemoveImage}
												>
													{__('Remove', 'zakra')}
												</Button>
											</Flex>
										) : null}
									</>
								) : (
									<div className="centered-button-container">
										<Button onClick={open} className="featuredImage">
											{__('Set featured image', 'zakra')}
										</Button>
									</div>
								)}
							</Flex>
						)}
					/>
				</div>
				<div>
					<p>{__('Style', 'zakra')}</p>
					<Flex style={{ flex: 1, flexWrap: 'wrap', rowGap: 12 }}>
						{OPTIONS.map((option) => {
							const Icon = option.icon;
							return (
								<Flex
									key={option.value}
									style={{ width: 'calc(50% - 10px)' }}
									data-state={
										currentLayout === option.value ? 'active' : 'inactive'
									}
									onClick={() => {
										updateMeta?.('zakra_main_header_style', option.value);
									}}
								>
									<Icon className={option.value} />
								</Flex>
							);
						})}
					</Flex>
				</div>
			</Flex>
		</PanelRow>
	);
};

export default withMeta(withFilters('ZakraMetaHeaderPanel')(HeaderPanel));
