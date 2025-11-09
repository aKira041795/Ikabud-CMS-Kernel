import { __ } from '@wordpress/i18n';
import React, { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import announcement from '../../../../inc/admin/images/changelog-announcement.gif';
import logo from '../../../../inc/admin/images/zak-logo.png';
import { ROUTES } from '../constants';
import { localized } from '../utils/global';
import Changelog from './Changelog';

const Header: React.FC = () => {
	const location = useLocation();
	const [isOpen, setIsOpen] = useState(false);

	const toggleDrawer = () => {
		setIsOpen(!isOpen);
	};
	return (
		<>
			<div className="py-3 px-5 bt-1 border-b border-t-0 border-x-0 border-[#E9E9E9] border-solid mb-8 bg-[#FFFFFF] ">
				<div className="container mx-auto lg:max-w-screen-xl px-5 box-border flex justify-between">
					<div className="header flex items-center gap-8">
						<div className="header__logo flex items-center h-[32px]">
							<Link to="/dashboard">
								<img src={logo} alt="Zakra" className="w-8 h-8" />
							</Link>
						</div>
						<div className="header__menu">
							<ul className="flex items-center gap-4">
								{ROUTES.map(
									(route) =>
										route.enable && (
											<li
												className={`m-0 text-sm font-semibold ${location.pathname === route.route ? 'active' : ''}`}
											>
												{route.route && (
													<Link
														className="no-underline px-2 text-[#383838] focus:outline-0 hover:text-primary-500 focus:shadow-none"
														to={route.route}
													>
														{route.label}
													</Link>
												)}

												{route.href && (
													<a
														className="no-underline px-2 text-[#383838] focus:outline-0 hover:text-primary-500 focus:shadow-none"
														href={route.href}
													>
														{route.label}
													</a>
												)}
											</li>
										),
								)}
							</ul>
						</div>
					</div>
					<div className="flex gap-4 items-center">
						<span className="border border-solid border-[#2563EB] py-[2px] px-[10px] font-semibold text-[#2563EB] bg-[#F8FAFF] rounded-[12px]">
							{localized.version} {__('Core', 'zakra')}
						</span>
						<a
							className="text-[#2563EB] relative after:content-[''] after:absolute after:left-[-10px] after:top-[2px] after:w-[1px] after:h-[16px] after:bg-[#D3D3D3] after:rounded-[12px] after:transform-[translateX(-50%)] after:translate-x-[50%] after:transition-all after:duration-300 after:ease-in-out"
							href="https://zakratheme.com/pricing/?utm_medium=dash-header&utm_source=zakra-theme&utm_campaign=header-upgrade-btn&utm_content=upgrade-to-pro"
						>
							{__('Upgrade to Pro', 'zakra')}
						</a>
						<button
							type="button"
							onClick={toggleDrawer}
							className="border border-solid border-[#d3d3d366] p-0 bg-transparent rounded-[20px] hover:cursor-pointer"
						>
							<img
								src={announcement}
								alt="announcement"
								className="w-[35px] h-[35px] relative"
							/>
						</button>
					</div>
				</div>
			</div>

			<div className={`drawer ${isOpen ? 'open' : ''}`}>
				<div className="drawer-content z-[99] relative bg-[#FFFFFF]">
					<div className="zak-dialog-head mt-[16px] py-[30px] px-[24px] border-b-[1px] border-t-0 border-x-0 border-solid border-[#E9E9E9]">
						<h3>{__('Latest Updates', 'zakra')}</h3>

						<div id="zak-dialog-close" className="zak-dialog-close">
							<button className="drawer-close" onClick={toggleDrawer}>
								&times;
							</button>
						</div>
					</div>

					<div className="drawer-body overflow-y-auto h-[100vh] p-[20px]">
						<Changelog />
					</div>
				</div>
				{isOpen && (
					<div className="drawer-overlay" onClick={toggleDrawer}></div>
				)}
			</div>
		</>
	);
};

export { Header };
