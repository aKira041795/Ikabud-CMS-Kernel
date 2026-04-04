import type { ComponentType, DiSyLNode } from './types';
import { getComponentDefinition } from './components';

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasValue(value: unknown): boolean {
    return value !== undefined && value !== null && (!(typeof value === 'string') || value.trim() !== '');
}

function createGeneratedId(seenIds: Set<string>, prefix = 'node'): string {
    let id = '';
    do {
        id = `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
    } while (seenIds.has(id));
    seenIds.add(id);
    return id;
}

function mergeDefaultProps(type: ComponentType, rawProps: Record<string, unknown>): Record<string, unknown> {
    const defaults = getComponentDefinition(type)?.defaultProps ?? {};
    const mergedProps: Record<string, unknown> = { ...defaults };

    for (const [key, value] of Object.entries(rawProps)) {
        if (value !== null && value !== undefined) {
            mergedProps[key] = value;
        }
    }

    return mergedProps;
}

function hasContainerConstraint(style: Record<string, unknown>): boolean {
    return ['maxWidth', 'margin', 'marginLeft', 'marginRight'].some((key) => hasValue(style[key]));
}

function isLegacyLayoutContainer(props: Record<string, unknown>, style: Record<string, unknown>): boolean {
    if (hasValue(props.layoutMode) || hasValue(props.presetId)) {
        return true;
    }

    const display = typeof style.display === 'string' ? style.display.trim() : '';
    if (display === 'flex' || display === 'grid') {
        return true;
    }

    return [
        'flexDirection',
        'flexWrap',
        'gap',
        'gridTemplateColumns',
        'gridTemplateRows',
        'justifyContent',
        'alignItems',
        'alignContent',
        'placeItems',
        'placeContent',
        'flex',
        'flexBasis',
        'flexGrow',
        'flexShrink',
        'order',
        'alignSelf',
    ].some((key) => hasValue(style[key]));
}

interface NormalizeOptions {
    seenIds?: Set<string>;
    parentType?: ComponentType | null;
    isRoot?: boolean;
}

export function normalizeBuilderNode(node: unknown, options: NormalizeOptions = {}): DiSyLNode {
    const seenIds = options.seenIds ?? new Set<string>();
    const source = isPlainObject(node) ? node : {};

    const requestedType = options.isRoot ? 'document' : String(source.type || 'text');
    let type = requestedType as ComponentType;

    const rawProps = isPlainObject(source.props) ? source.props : {};
    const rawStyle = isPlainObject(source.style) ? source.style : {};
    const rawMeta = isPlainObject(source.meta) ? source.meta : {};

    if (type === 'container' && isLegacyLayoutContainer(rawProps, rawStyle) && !hasContainerConstraint(rawStyle)) {
        type = 'layout_container';
    }

    const childrenSource = Array.isArray(source.children) ? source.children : [];
    const children = childrenSource.map((child) => normalizeBuilderNode(child, {
        seenIds,
        parentType: type,
    }));

    const requestedId = typeof source.id === 'string' ? source.id.trim() : '';
    const id = requestedId !== '' && !seenIds.has(requestedId)
        ? (() => {
            seenIds.add(requestedId);
            return requestedId;
        })()
        : createGeneratedId(seenIds, options.isRoot ? 'doc' : 'node');

    const normalizedNode: DiSyLNode = {
        id,
        type,
        props: mergeDefaultProps(type, rawProps),
        style: { ...rawStyle },
        children,
        meta: { ...rawMeta },
    };

    if (type === 'layout_container' && options.parentType === 'section') {
        return {
            id: createGeneratedId(seenIds),
            type: 'container',
            props: {},
            style: {},
            children: [normalizedNode],
            meta: {},
        };
    }

    return normalizedNode;
}