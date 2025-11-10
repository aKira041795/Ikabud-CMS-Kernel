import { Panel, PanelBody, PanelRow } from '@wordpress/components';
import React from 'react';

const Collapse = () => {
	return (
		<div>
			<Panel header="My panel">
				<React.Fragment key=".0">
					<PanelBody title="First section">
						<PanelRow>
							<div
								style={{
									background: '#ddd',
									height: 100,
									width: '100%',
								}}
							/>
						</PanelRow>
					</PanelBody>
					<PanelBody title="Second section">
						<PanelRow>
							<div
								style={{
									background: '#ddd',
									height: 100,
									width: '100%',
								}}
							/>
						</PanelRow>
					</PanelBody>
				</React.Fragment>
			</Panel>
		</div>
	);
};

export default Collapse;
