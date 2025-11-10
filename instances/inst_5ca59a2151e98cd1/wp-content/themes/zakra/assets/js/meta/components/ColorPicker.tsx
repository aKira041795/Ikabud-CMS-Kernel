import { ColorIndicator, ColorPicker, Popover } from '@wordpress/components';
import React, { useState } from 'react';
import { useDebounceCallback } from '../hooks/useDebounceCallback';

interface ColorPickerComponentProps {
	value?: string;
	onChange: (val: string) => void;
}
const ColorPickerComponent = ({
	value: _value,
	onChange,
}: ColorPickerComponentProps) => {
	const [value, setValue] = useState(_value);
	const debouncedOnChange = useDebounceCallback((v) => {
		onChange(v);
	});
	const [isVisible, setIsVisible] = useState(false);
	const toggleVisible = () => {
		setIsVisible((prev) => !prev);
	};
	return (
		<>
			<ColorIndicator colorValue={value} onClick={toggleVisible} />

			{isVisible && (
				<Popover onFocusOutside={() => setIsVisible(false)}>
					<ColorPicker
						onChange={(v) => {
							setValue(v);
							debouncedOnChange(v);
						}}
					/>
				</Popover>
			)}
		</>
	);
};

export default ColorPickerComponent;
