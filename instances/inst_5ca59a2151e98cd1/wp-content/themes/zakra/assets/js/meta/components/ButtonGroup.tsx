import React, { useState } from 'react';

type ButtonGroupProps = {
	options: any;
	selectedValue: string;
	onChange: (value: any) => void;
};
const ButtonGroup = ({
	options,
	selectedValue,
	onChange,
}: ButtonGroupProps) => {
	const [activeButton, setActiveButton] = useState(selectedValue);

	const handleClick = (value: string) => {
		setActiveButton(value);
		onChange(value);
	};

	return (
		<div className="btn-group">
			{options?.map((option: any) => (
				<button
					key={option.value}
					className={activeButton === option.value ? 'active' : ''}
					onClick={() => handleClick(option.value)}
				>
					{option.label}
				</button>
			))}
		</div>
	);
};

export default ButtonGroup;
