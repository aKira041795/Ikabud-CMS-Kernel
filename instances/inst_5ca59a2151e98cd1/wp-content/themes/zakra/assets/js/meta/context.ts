import { createContext } from 'react';

export const MetaContext = createContext<{
	meta: Record<string, any>;
	setMeta: (key: string, value: any) => void;
} | null>(null);
