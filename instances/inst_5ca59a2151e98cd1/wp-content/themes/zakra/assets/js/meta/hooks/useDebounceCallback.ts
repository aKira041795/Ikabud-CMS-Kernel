import { useCallback, useRef } from 'react';

export const useDebounceCallback = <T extends (...args: any[]) => any>(
	callback: T,
	delay = 250,
) => {
	const timeout = useRef<any>();

	const debouncedCallback = useCallback(
		(...args: Parameters<T>) => {
			clearTimeout(timeout.current);
			timeout.current = setTimeout(() => {
				callback(...args);
			}, delay);
		},
		[callback, delay],
	);

	return debouncedCallback;
};
