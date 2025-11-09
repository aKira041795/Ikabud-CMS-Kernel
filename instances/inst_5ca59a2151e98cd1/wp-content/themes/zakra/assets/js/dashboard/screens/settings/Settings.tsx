import apiFetch from '@wordpress/api-fetch';
import { TabPanel, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';
import { Controller, FormProvider, useForm } from 'react-hook-form';
import { localized } from '../../utils/utils';

const Settings: React.FC = () => {
	const form = useForm({
		defaultValues: {
			enable_mega_menu: localized.enable_mega_menu,
		},
	});

	const onSubmit = async (data: any) => {
		const response = await apiFetch({
			method: 'POST',
			path: 'zakra/v1/settings',
			data: data,
		});
	};

	const handleToggleChange = async (value: boolean, field: any) => {
		field.onChange(value);
		await onSubmit(form.getValues());
	};

	return (
		<>
			<FormProvider {...form}>
				<form onSubmit={form.handleSubmit(onSubmit)}>
					<TabPanel
						style={{ marginTop: '1rem' }}
						className="zak-setting-tab [&>div]:transition-none border border-solid border-[#E1E1E1] h-[100vh]"
						onSelect={() => {}}
						tabs={[
							{
								name: 'mega-menu',
								title: __('Mega Menu', 'zakra'),
								className: 'zak-mega-menu-tabpanel__mega-menu',
							},
							{
								name: 'style',
								title: __('Style', 'zakra'),
								className: 'zak-mega-menu-tabpanel__style',
							},
						]}
					>
						{(tab) => {
							return (
								<>
									<div
										className={`${tab.name === 'mega-menu' ? 'block' : 'hidden'} p-6`}
									>
										<div className="border rounded border-solid border-[#E1E1E1]">
											<div className=" p-[24px]">
												<Controller
													name={'enable_mega_menu'}
													control={form.control}
													render={({ field }) => (
														<ToggleControl
															className="[&>div>div]:flex-row-reverse zak-setting-label"
															__nextHasNoMarginBottom
															checked={field.value}
															label={__('Mega Menu', 'zakra')}
															onChange={(value) =>
																handleToggleChange(value, field)
															}
														/>
													)}
												/>
												<p className="text-[#6B6B6B] w-9/12">
													{__(
														'Modern and professional WordPress magazine and news portal-styled theme perfect for creating websites for your user-engaging magazines, news channels, etc.',
														'zakra',
													)}
												</p>
											</div>
											<div className="flex items-center gap-3 py-[20px] px-[24px] border-x-0 border-b-0 border-t border-solid border-[#E1E1E1]">
												<a
													className="zak-setting-learn-more text-[#7A7A7A]"
													href="#"
												>
													{__('Documentation', 'zakra')}
												</a>
												<a className="text-[#7A7A7A]" href="#">
													{__('Live Demo', 'zakra')}
												</a>
											</div>
										</div>
									</div>
									<div className={tab.name === 'style' ? 'block' : 'hidden'}>
										{'style'}
									</div>
								</>
							);
						}}
					</TabPanel>
				</form>
			</FormProvider>
		</>
	);
};

export default Settings;
