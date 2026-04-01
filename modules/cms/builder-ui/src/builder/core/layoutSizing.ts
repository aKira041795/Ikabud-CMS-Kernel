import type { NodeStyle } from './types';

export interface FlexParts {
    flexGrow: string;
    flexShrink: string;
    flexBasis: string;
}

const DEFAULT_FLEX_PARTS: FlexParts = {
    flexGrow: '1',
    flexShrink: '1',
    flexBasis: '0%',
};

function cleanValue(value: unknown): string | undefined {
    if (typeof value !== 'string') return undefined;
    const trimmed = value.trim();
    return trimmed === '' ? undefined : trimmed;
}

function isNumericToken(token: string): boolean {
    return /^-?\d+(?:\.\d+)?$/.test(token);
}

function isPercentageValue(value: string): boolean {
    return /^-?\d+(?:\.\d+)?%$/.test(value);
}

function isKeywordWidth(value: string): boolean {
    return /^(fit-content|max-content|min-content)$/.test(value);
}

function normalizeNumericToken(token: string): string {
    const parsed = Number.parseFloat(token);
    if (!Number.isFinite(parsed)) {
        return token.trim();
    }
    return `${parsed}`;
}

function getExplicitFlexParts(style: Partial<NodeStyle>): FlexParts | null {
    const flexGrow = cleanValue(style.flexGrow);
    const flexShrink = cleanValue(style.flexShrink);
    const flexBasis = cleanValue(style.flexBasis);

    if (!flexGrow && !flexShrink && !flexBasis) {
        return null;
    }

    return {
        flexGrow: flexGrow || DEFAULT_FLEX_PARTS.flexGrow,
        flexShrink: flexShrink || DEFAULT_FLEX_PARTS.flexShrink,
        flexBasis: flexBasis || DEFAULT_FLEX_PARTS.flexBasis,
    };
}

export function parseFlexShorthand(flexValue: unknown): FlexParts | null {
    const cleaned = cleanValue(flexValue);
    if (!cleaned) {
        return null;
    }

    if (cleaned === 'auto') {
        return {
            flexGrow: '1',
            flexShrink: '1',
            flexBasis: 'auto',
        };
    }

    if (cleaned === 'none') {
        return {
            flexGrow: '0',
            flexShrink: '0',
            flexBasis: 'auto',
        };
    }

    const parts = cleaned.split(/\s+/);

    if (parts.length === 1) {
        if (isNumericToken(parts[0])) {
            return {
                flexGrow: normalizeNumericToken(parts[0]),
                flexShrink: '1',
                flexBasis: '0%',
            };
        }

        return {
            flexGrow: '1',
            flexShrink: '1',
            flexBasis: parts[0],
        };
    }

    if (parts.length === 2) {
        if (isNumericToken(parts[0]) && isNumericToken(parts[1])) {
            return {
                flexGrow: normalizeNumericToken(parts[0]),
                flexShrink: normalizeNumericToken(parts[1]),
                flexBasis: '0%',
            };
        }

        if (isNumericToken(parts[0])) {
            return {
                flexGrow: normalizeNumericToken(parts[0]),
                flexShrink: '1',
                flexBasis: parts[1],
            };
        }
    }

    return {
        flexGrow: normalizeNumericToken(parts[0] || DEFAULT_FLEX_PARTS.flexGrow),
        flexShrink: normalizeNumericToken(parts[1] || DEFAULT_FLEX_PARTS.flexShrink),
        flexBasis: parts.slice(2).join(' ') || DEFAULT_FLEX_PARTS.flexBasis,
    };
}

export function formatFlexShorthand(
    parts: Partial<FlexParts>,
    fallback: Partial<FlexParts> = DEFAULT_FLEX_PARTS,
): string | undefined {
    const flexGrow = cleanValue(parts.flexGrow) || cleanValue(fallback.flexGrow) || DEFAULT_FLEX_PARTS.flexGrow;
    const flexShrink = cleanValue(parts.flexShrink) || cleanValue(fallback.flexShrink) || DEFAULT_FLEX_PARTS.flexShrink;
    const flexBasis = cleanValue(parts.flexBasis) || cleanValue(fallback.flexBasis) || DEFAULT_FLEX_PARTS.flexBasis;

    if (!flexGrow && !flexShrink && !flexBasis) {
        return undefined;
    }

    return `${flexGrow} ${flexShrink} ${flexBasis}`;
}

export function hasExplicitFlexSizing(style: Partial<NodeStyle>): boolean {
    return Boolean(
        cleanValue(style.flex)
        || cleanValue(style.flexGrow)
        || cleanValue(style.flexShrink)
        || cleanValue(style.flexBasis),
    );
}

export function deriveLayoutWidth(style: Partial<NodeStyle>): string {
    const width = cleanValue(style.width);
    if (width) {
        return width;
    }

    const explicitParts = getExplicitFlexParts(style);
    if (explicitParts) {
        if (explicitParts.flexBasis !== '0%' && explicitParts.flexBasis !== 'auto') {
            return explicitParts.flexBasis;
        }

        if (explicitParts.flexBasis === '0%' && isNumericToken(explicitParts.flexGrow)) {
            return `${normalizeNumericToken(explicitParts.flexGrow)}%`;
        }
    }

    const parsed = parseFlexShorthand(style.flex);
    if (!parsed) {
        return '';
    }

    if (parsed.flexBasis !== '0%' && parsed.flexBasis !== 'auto') {
        return parsed.flexBasis;
    }

    if (parsed.flexBasis === '0%' && isNumericToken(parsed.flexGrow)) {
        return `${normalizeNumericToken(parsed.flexGrow)}%`;
    }

    return '';
}

export function deriveFlexPart(style: Partial<NodeStyle>, key: keyof FlexParts): string {
    const explicitValue = cleanValue(style[key]);
    if (explicitValue) {
        return explicitValue;
    }

    const parsed = parseFlexShorthand(style.flex);
    if (!parsed) {
        return '';
    }

    return parsed[key] || '';
}

export function buildRatioFlexValue(value: number | string): string {
    const parsed = typeof value === 'number' ? value : Number.parseFloat(value);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return '1 1 0%';
    }

    return `${normalizeNumericToken(String(parsed))} 1 0%`;
}

export function buildFlexWidthStyle(value: string, autoFlex = '1 1 0%'): Partial<NodeStyle> {
    const cleaned = cleanValue(value);

    if (!cleaned || cleaned === 'auto') {
        return {
            width: undefined,
            flex: autoFlex,
            flexGrow: undefined,
            flexShrink: undefined,
            flexBasis: undefined,
        };
    }

    if (isPercentageValue(cleaned)) {
        return {
            width: undefined,
            flex: buildRatioFlexValue(cleaned.slice(0, -1)),
            flexGrow: undefined,
            flexShrink: undefined,
            flexBasis: undefined,
        };
    }

    if (isKeywordWidth(cleaned)) {
        return {
            width: cleaned,
            flex: undefined,
            flexGrow: undefined,
            flexShrink: undefined,
            flexBasis: undefined,
        };
    }

    return {
        width: undefined,
        flex: `0 0 ${cleaned}`,
        flexGrow: undefined,
        flexShrink: undefined,
        flexBasis: undefined,
    };
}

export function buildFlexPartStyle(
    style: Partial<NodeStyle>,
    key: keyof FlexParts,
    value: string,
): Partial<NodeStyle> {
    const parsed = getExplicitFlexParts(style) || parseFlexShorthand(style.flex) || DEFAULT_FLEX_PARTS;

    const nextParts: FlexParts = {
        ...DEFAULT_FLEX_PARTS,
        ...parsed,
        [key]: cleanValue(value) || DEFAULT_FLEX_PARTS[key],
    };

    return {
        width: undefined,
        flex: formatFlexShorthand(nextParts),
        flexGrow: undefined,
        flexShrink: undefined,
        flexBasis: undefined,
    };
}