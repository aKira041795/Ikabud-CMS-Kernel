/**
 * Ikabud Page Builder - Node Renderer
 * Renders DiSyL nodes as React components
 */

import React, { memo, useCallback, useState, useRef, useEffect, CSSProperties, lazy, Suspense } from 'react';
import { DiSyLNode, NodeStyle } from '../core/types';

// Inline SVG placeholder — zero-dependency fallback for missing images
function placeholderSvg(w: number, h: number, bg: string, text: string): string {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">` +
    `<rect width="100%" height="100%" fill="${bg}"/>` +
    `<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" ` +
    `fill="#fff" font-family="system-ui,sans-serif" font-size="${Math.max(14, Math.round(h / 8))}px" font-weight="600">` +
    `${text}</text></svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

// Lazy load TinyMCE editor to reduce initial bundle size
const InlineEditor = lazy(() => import('./InlineEditor'));

// Import MediaLibrary for image selection
import MediaLibrary from './MediaLibrary';

// =============================================================================
// Style Conversion
// =============================================================================

function nodeStyleToCSS(
  style: NodeStyle,
  viewport: 'desktop' | 'tablet' | 'mobile',
  nodeType?: string,
): CSSProperties {
  const baseStyle: CSSProperties = {};

  // Copy all style properties except responsive overrides
  const { tablet, mobile, ...rest } = style;

  Object.entries(rest).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      (baseStyle as Record<string, unknown>)[key] = value;
    }
  });

  // Apply responsive overrides
  if (viewport === 'tablet' && tablet) {
    Object.entries(tablet).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        (baseStyle as Record<string, unknown>)[key] = value;
      }
    });
  } else if (viewport === 'mobile' && mobile) {
    // Mobile inherits tablet first, then applies mobile
    if (tablet) {
      Object.entries(tablet).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          (baseStyle as Record<string, unknown>)[key] = value;
        }
      });
    }
    Object.entries(mobile).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        (baseStyle as Record<string, unknown>)[key] = value;
      }
    });
  }

  // ─── Automatic responsive defaults ───────────────────────────────
  // These mirror the production CSS media-query rules so the preview
  // matches the live site.  Only applied when the user has NOT set an
  // explicit responsive override for the affected property.

  if (nodeType && viewport !== 'desktop') {
    const effectiveDirection = baseStyle.flexDirection || (nodeType === 'row' ? 'row' : undefined);
    const isHorizontalFlex = baseStyle.display === 'flex' || nodeType === 'row' || nodeType === 'column';
    const hasExplicitMobileDir = mobile?.flexDirection;
    const hasExplicitTabletDir = tablet?.flexDirection;

    if (viewport === 'mobile') {
      // Row nodes auto-stack to column on mobile (matches .cms-builder-node--row rule)
      if (nodeType === 'row' && !hasExplicitMobileDir) {
        baseStyle.flexDirection = 'column';
      }
      // Flex-row containers auto-stack on mobile (matches data-mobile-layout rule)
      if (nodeType === 'container' && isHorizontalFlex && effectiveDirection === 'row' && !hasExplicitMobileDir) {
        baseStyle.flexDirection = 'column';
      }
      // Columns inside (now-stacked) rows: full-width + no flex sizing
      // Matches: .cms-builder-node--row > .cms-builder-node--column { width:100%!important; flex:none!important; }
      if (nodeType === 'column' && !mobile?.width) {
        baseStyle.width = '100%';
        baseStyle.flex = 'none';
      }
      // Containers inside layout containers: also go full-width when parent stacks
      // Matches: .cms-builder-node--container[data-mobile-layout="1"] > .cms-builder-node--container
      if (nodeType === 'container' && (baseStyle.flex || baseStyle.flexBasis)) {
        if (!mobile?.width && !mobile?.flex) {
          baseStyle.width = '100%';
          baseStyle.flex = 'none';
        }
      }
    }

    if (viewport === 'tablet') {
      // Tablet: enable wrapping on rows so columns shrink instead of overflowing
      if (nodeType === 'row' && !hasExplicitTabletDir && !tablet?.flexWrap) {
        baseStyle.flexWrap = 'wrap';
      }
      // Tablet: columns with fixed widths should have min-width:0 so they can wrap
      if (nodeType === 'column' && !tablet?.minWidth) {
        baseStyle.minWidth = '0';
      }
    }
  }

  return baseStyle;
}

function sanitizeCustomId(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined;
  const sanitized = value.trim().replace(/[^a-zA-Z0-9_-]/g, '');
  return sanitized || undefined;
}

function sanitizeCustomClasses(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined;
  const sanitized = value.trim().replace(/[^a-zA-Z0-9_ -]/g, ' ').replace(/\s+/g, ' ').trim();
  return sanitized || undefined;
}

function parseCustomAttributes(value: unknown): Record<string, string> {
  if (typeof value !== 'string' || value.trim() === '') return {};

  const reserved = new Set(['class', 'className', 'data-node-id', 'draggable', 'id', 'role', 'style', 'tabIndex']);
  const attributes: Record<string, string> = {};

  value.split(/\r\n|\r|\n/).forEach((line) => {
    const trimmed = line.trim();
    if (!trimmed) return;

    const match = trimmed.match(/^([a-zA-Z_:][a-zA-Z0-9_.:-]*)(?:\s*=\s*(.+))?$/);
    if (!match) return;

    const [, name, rawValue = ''] = match;
    const lowerName = name.toLowerCase();
    if (reserved.has(name) || reserved.has(lowerName) || lowerName.startsWith('on')) return;

    const valueText = rawValue.trim();
    if (!valueText) return;

    let normalizedValue = valueText;
    if ((normalizedValue.startsWith('"') && normalizedValue.endsWith('"')) || (normalizedValue.startsWith("'") && normalizedValue.endsWith("'"))) {
      normalizedValue = normalizedValue.slice(1, -1);
    }

    attributes[name] = normalizedValue;
  });

  return attributes;
}

function mapVisibilityClassName(value: unknown): string | undefined {
  switch (value) {
    case 'desktop':
      return 'cms-builder-visible--desktop-only';
    case 'tablet':
      return 'cms-builder-visible--tablet-only';
    case 'mobile':
      return 'cms-builder-visible--mobile-only';
    case 'desktop-tablet':
      return 'cms-builder-visible--desktop-tablet';
    case 'tablet-mobile':
      return 'cms-builder-visible--tablet-mobile';
    default:
      return undefined;
  }
}

function getPreviewVisibilityStyle(value: unknown, viewport: 'desktop' | 'tablet' | 'mobile'): CSSProperties {
  const visibility = typeof value === 'string' ? value : 'all';

  if (visibility === 'hidden') {
    return {
      opacity: 0.25,
      filter: 'grayscale(1)',
    };
  }

  const isVisible =
    visibility === 'all'
    || visibility === ''
    || (visibility === 'desktop' && viewport === 'desktop')
    || (visibility === 'tablet' && viewport === 'tablet')
    || (visibility === 'mobile' && viewport === 'mobile')
    || (visibility === 'desktop-tablet' && (viewport === 'desktop' || viewport === 'tablet'))
    || (visibility === 'tablet-mobile' && (viewport === 'tablet' || viewport === 'mobile'));

  if (isVisible) {
    return {};
  }

  return {
    opacity: 0.35,
    filter: 'grayscale(0.9)',
  };
}

// =============================================================================
// Component Props
// =============================================================================

interface NodeRendererProps {
  node: DiSyLNode;
  viewport: 'desktop' | 'tablet' | 'mobile';
  isSelected: boolean;
  isHovered: boolean;
  isParentOfSelected?: boolean; // NEW: Highlight when child is selected
  structureMode?: boolean; // NEW: Structure Mode toggle
  onSelect: (nodeId: string, addToSelection?: boolean) => void;
  onHover: (nodeId: string | null) => void;
  onContentChange?: (nodeId: string, content: string) => void;
  onPropsChange?: (nodeId: string, props: Record<string, unknown>) => void; // For updating any props (e.g., image src)
  onMoveNode?: (nodeId: string, newParentId: string, newIndex: number) => void;
  onStyleChange?: (nodeId: string, style: Partial<NodeStyle>) => void;
  selectedIds?: string[];
  parentId?: string;
  indexInParent?: number;
}

const INTERNAL_NODE_DND_MIME = 'application/x-cms-node-id';

// =============================================================================
// Element Label Bar Component
// =============================================================================

interface ElementLabelBarProps {
  nodeType: string;
  nodeName?: string;
  isSelected: boolean;
  isHovered: boolean;
}

const ElementLabelBar: React.FC<ElementLabelBarProps> = memo(({ nodeType, nodeName, isSelected, isHovered }) => {
  if (!isSelected && !isHovered) return null;

  // Format type name for display
  const displayName = nodeName || nodeType.charAt(0).toUpperCase() + nodeType.slice(1).replace(/_/g, ' ');

  return (
    <div
      data-drag-handle
      style={{
        position: 'absolute',
        top: '-24px',
        left: '0',
        display: 'flex',
        alignItems: 'center',
        gap: '4px',
        padding: '2px 8px',
        backgroundColor: isSelected ? '#0078d4' : '#6b7280',
        color: 'white',
        fontSize: '11px',
        fontWeight: 500,
        borderRadius: '4px 4px 0 0',
        whiteSpace: 'nowrap',
        zIndex: 1000,
        pointerEvents: 'auto',
        userSelect: 'none',
        cursor: 'grab',
      }}
      title="Drag to reorder"
    >
      {/* Drag handle icon */}
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="9" cy="5" r="1" fill="currentColor" />
        <circle cx="9" cy="12" r="1" fill="currentColor" />
        <circle cx="9" cy="19" r="1" fill="currentColor" />
        <circle cx="15" cy="5" r="1" fill="currentColor" />
        <circle cx="15" cy="12" r="1" fill="currentColor" />
        <circle cx="15" cy="19" r="1" fill="currentColor" />
      </svg>
      {displayName}
    </div>
  );
});

ElementLabelBar.displayName = 'ElementLabelBar';

// =============================================================================
// Resize Handles Component
// =============================================================================

interface ResizeHandlesProps {
  onResize: (direction: string, deltaX: number, deltaY: number, initialWidth: number, initialHeight: number) => void;
  onResizeEnd?: () => void;
  resizable?: { horizontal: boolean; vertical: boolean };
  nodeRef: React.RefObject<HTMLDivElement>;
  showDimensions?: boolean;
  lockAspectRatio?: boolean;
}

const ResizeHandles: React.FC<ResizeHandlesProps> = memo(({ onResize, onResizeEnd, resizable = { horizontal: true, vertical: true }, nodeRef, showDimensions = true, lockAspectRatio = false }) => {
  const [dimensions, setDimensions] = useState<{ width: number; height: number; locked?: boolean } | null>(null);
  const [tooltipPosition, setTooltipPosition] = useState<{ x: number; y: number }>({ x: 0, y: 0 });

  const handleMouseDown = useCallback((direction: string) => (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();

    // Capture initial size at the start of drag
    const element = nodeRef.current;
    if (!element) return;

    const rect = element.getBoundingClientRect();
    const computedStyle = window.getComputedStyle(element);

    // Use computed width/height which respects CSS box-sizing
    const initialWidth = parseFloat(computedStyle.width) || rect.width;
    const initialHeight = parseFloat(computedStyle.height) || rect.height;
    const aspectRatio = initialWidth / initialHeight;
    const startX = e.clientX;
    const startY = e.clientY;

    // Show initial dimensions
    setDimensions({ width: Math.round(initialWidth), height: Math.round(initialHeight), locked: lockAspectRatio });
    setTooltipPosition({ x: e.clientX, y: e.clientY - 40 });

    const handleMouseMove = (moveEvent: MouseEvent) => {
      let deltaX = moveEvent.clientX - startX;
      let deltaY = moveEvent.clientY - startY;

      // Calculate new dimensions
      let newWidth = initialWidth;
      let newHeight = initialHeight;

      if (direction.includes('e')) newWidth += deltaX;
      if (direction.includes('w')) newWidth -= deltaX;
      if (direction.includes('s')) newHeight += deltaY;
      if (direction.includes('n')) newHeight -= deltaY;

      newWidth = Math.max(40, newWidth);
      newHeight = Math.max(20, newHeight);

      // Apply aspect ratio lock (for images)
      // Hold Shift to temporarily unlock, or if lockAspectRatio is true, hold Shift to lock
      const shouldLock = lockAspectRatio ? !moveEvent.shiftKey : moveEvent.shiftKey;
      if (shouldLock && aspectRatio > 0) {
        // Determine which dimension changed more and adjust the other
        const widthChange = Math.abs(newWidth - initialWidth);
        const heightChange = Math.abs(newHeight - initialHeight);

        if (widthChange > heightChange || direction === 'e' || direction === 'w') {
          newHeight = newWidth / aspectRatio;
        } else {
          newWidth = newHeight * aspectRatio;
        }

        // Recalculate deltas for the adjusted dimensions
        if (direction.includes('e')) deltaX = newWidth - initialWidth;
        if (direction.includes('w')) deltaX = initialWidth - newWidth;
        if (direction.includes('s')) deltaY = newHeight - initialHeight;
        if (direction.includes('n')) deltaY = initialHeight - newHeight;
      }

      // Update tooltip
      setDimensions({
        width: Math.round(newWidth),
        height: Math.round(newHeight),
        locked: shouldLock
      });
      setTooltipPosition({ x: moveEvent.clientX, y: moveEvent.clientY - 40 });

      onResize(direction, deltaX, deltaY, initialWidth, initialHeight);
    };

    const handleMouseUp = () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      setDimensions(null);
      onResizeEnd?.();
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
  }, [onResize, onResizeEnd, nodeRef, lockAspectRatio]);

  const handleStyle: CSSProperties = {
    position: 'absolute',
    backgroundColor: '#0078d4',
    border: '1px solid #fff',
    zIndex: 1000,
  };

  const cornerStyle: CSSProperties = {
    ...handleStyle,
    width: '8px',
    height: '8px',
  };

  const edgeStyleH: CSSProperties = {
    ...handleStyle,
    width: '20px',
    height: '6px',
  };

  const edgeStyleV: CSSProperties = {
    ...handleStyle,
    width: '6px',
    height: '20px',
  };

  return (
    <>
      {/* Corners */}
      {resizable.horizontal && resizable.vertical && (
        <>
          <div
            style={{ ...cornerStyle, top: '-4px', left: '-4px', cursor: 'nw-resize' }}
            onMouseDown={handleMouseDown('nw')}
          />
          <div
            style={{ ...cornerStyle, top: '-4px', right: '-4px', cursor: 'ne-resize' }}
            onMouseDown={handleMouseDown('ne')}
          />
          <div
            style={{ ...cornerStyle, bottom: '-4px', left: '-4px', cursor: 'sw-resize' }}
            onMouseDown={handleMouseDown('sw')}
          />
          <div
            style={{ ...cornerStyle, bottom: '-4px', right: '-4px', cursor: 'se-resize' }}
            onMouseDown={handleMouseDown('se')}
          />
        </>
      )}

      {/* Edges */}
      {resizable.horizontal && (
        <>
          <div
            style={{ ...edgeStyleV, top: '50%', left: '-3px', transform: 'translateY(-50%)', cursor: 'w-resize' }}
            onMouseDown={handleMouseDown('w')}
          />
          <div
            style={{ ...edgeStyleV, top: '50%', right: '-3px', transform: 'translateY(-50%)', cursor: 'e-resize' }}
            onMouseDown={handleMouseDown('e')}
          />
        </>
      )}
      {resizable.vertical && (
        <>
          <div
            style={{ ...edgeStyleH, top: '-3px', left: '50%', transform: 'translateX(-50%)', cursor: 'n-resize' }}
            onMouseDown={handleMouseDown('n')}
          />
          <div
            style={{ ...edgeStyleH, bottom: '-3px', left: '50%', transform: 'translateX(-50%)', cursor: 's-resize' }}
            onMouseDown={handleMouseDown('s')}
          />
        </>
      )}

      {/* Live Dimensions Tooltip */}
      {showDimensions && dimensions && (
        <div
          style={{
            position: 'fixed',
            left: tooltipPosition.x,
            top: tooltipPosition.y,
            transform: 'translateX(-50%)',
            backgroundColor: '#1e1e1e',
            color: '#fff',
            padding: '4px 8px',
            borderRadius: '4px',
            fontSize: '11px',
            fontWeight: 500,
            fontFamily: 'monospace',
            whiteSpace: 'nowrap',
            zIndex: 10000,
            pointerEvents: 'none',
            boxShadow: '0 2px 8px rgba(0,0,0,0.3)',
            border: dimensions.locked ? '1px solid #f59e0b' : '1px solid #3c3c3c',
          }}
        >
          {dimensions.locked && (
            <span style={{ color: '#f59e0b', marginRight: '4px' }} title="Aspect ratio locked">🔒</span>
          )}
          <span>{dimensions.width}</span>
          <span style={{ color: '#888' }}> × </span>
          <span>{dimensions.height}</span>
          <span style={{ color: '#666', marginLeft: '2px' }}>px</span>
        </div>
      )}
    </>
  );
});

ResizeHandles.displayName = 'ResizeHandles';

// =============================================================================
// Quick Width Toolbar - Visual presets for container/column widths
// =============================================================================

interface QuickWidthToolbarProps {
  currentWidth?: string;
  onWidthChange: (width: string) => void;
  onFlexChange: (flex: string) => void;
}

const WIDTH_PRESETS = [
  { label: '25%', value: '25%', flex: '1 1 25%' },
  { label: '33%', value: '33.333%', flex: '1 1 33.333%' },
  { label: '50%', value: '50%', flex: '1 1 50%' },
  { label: '67%', value: '66.666%', flex: '2 1 66.666%' },
  { label: '75%', value: '75%', flex: '3 1 75%' },
  { label: '100%', value: '100%', flex: '1 1 100%' },
  { label: 'Auto', value: 'auto', flex: '1 1 0' },
];

const QuickWidthToolbar: React.FC<QuickWidthToolbarProps> = memo(({ currentWidth, onWidthChange: _onWidthChange, onFlexChange }) => {
  // Determine which preset is currently active
  const getActivePreset = () => {
    if (!currentWidth) return 'Auto';
    const normalized = currentWidth.replace(/\s/g, '');
    for (const preset of WIDTH_PRESETS) {
      if (normalized.includes(preset.value) || normalized === preset.flex) {
        return preset.label;
      }
    }
    return null;
  };

  const activePreset = getActivePreset();

  return (
    <div
      style={{
        position: 'absolute',
        bottom: '-36px',
        left: '50%',
        transform: 'translateX(-50%)',
        display: 'flex',
        gap: '2px',
        padding: '4px',
        backgroundColor: '#1e1e1e',
        borderRadius: '6px',
        boxShadow: '0 2px 8px rgba(0,0,0,0.3)',
        zIndex: 1001,
        whiteSpace: 'nowrap',
      }}
      onClick={(e) => e.stopPropagation()}
    >
      {WIDTH_PRESETS.map((preset) => (
        <button
          key={preset.label}
          onClick={(e) => {
            e.stopPropagation();
            if (preset.label === 'Auto') {
              onFlexChange(preset.flex);
            } else {
              onFlexChange(preset.flex);
            }
          }}
          style={{
            padding: '4px 8px',
            fontSize: '11px',
            fontWeight: activePreset === preset.label ? 600 : 400,
            backgroundColor: activePreset === preset.label ? '#0078d4' : 'transparent',
            color: activePreset === preset.label ? '#fff' : '#ccc',
            border: 'none',
            borderRadius: '4px',
            cursor: 'pointer',
            transition: 'all 0.15s ease',
          }}
          onMouseEnter={(e) => {
            if (activePreset !== preset.label) {
              e.currentTarget.style.backgroundColor = '#333';
            }
          }}
          onMouseLeave={(e) => {
            if (activePreset !== preset.label) {
              e.currentTarget.style.backgroundColor = 'transparent';
            }
          }}
          title={`Set width to ${preset.label}`}
        >
          {preset.label}
        </button>
      ))}
    </div>
  );
});

QuickWidthToolbar.displayName = 'QuickWidthToolbar';

// =============================================================================
// Individual Component Renderers
// =============================================================================

// Document is the root wrapper - stacks sections vertically
const DocumentRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; children: React.ReactNode }> =
  ({ style, children }) => (
    <div style={{
      ...style,
      display: 'flex',
      flexDirection: 'column',
      width: '100%',
      minHeight: '100%',
    }}>
      {children}
    </div>
  );

// Container renderers - NO outlines by default (WYSIWYG)
// Outlines are added via selection/hover states in the main NodeRenderer
// IMPORTANT: User styles must be spread LAST to allow overriding defaults

const SectionRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; children: React.ReactNode }> =
  ({ style, children }) => (
    <section style={{
      // Defaults first — must match cmsBuilderDefaultStyle('section') in helpers.php
      width: '100%',
      boxSizing: 'border-box',
      minHeight: '80px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '48px 24px',
      // User styles override defaults
      ...style,
    }}>
      {children}
    </section>
  );

const ContainerRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; children: React.ReactNode }> =
  ({ node, style, children }) => {
    const isLayoutItem = Boolean(style.flex || style.flexBasis || style.order || style.alignSelf);
    const isExplicitLayout = style.display === 'flex' || style.display === 'grid';
    const hasExplicitConstraint = style.maxWidth !== undefined || style.margin !== undefined;

    const wrapperDefaults: CSSProperties = !isLayoutItem && !isExplicitLayout && !hasExplicitConstraint
      ? {
        maxWidth: '1200px',
        margin: '0 auto',
        padding: (style.padding as CSSProperties['padding']) ?? '0 24px',
      }
      : {};

    return (
      <div
        style={{
          minHeight: '60px',
          boxSizing: 'border-box',
          ...wrapperDefaults,
          // Editor visual aid - subtle border to show container boundaries
          outline: '1px dashed rgba(59, 130, 246, 0.2)',
          outlineOffset: '-1px',
          // User styles override defaults
          ...style,
        }}
      >
        {children}
      </div>
    );
  };

const RowRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; children: React.ReactNode }> =
  ({ style, children }) => (
    <div style={{
      // Defaults first — must match cmsBuilderDefaultStyle('row') in helpers.php
      display: 'flex',
      flexDirection: 'row',
      flexWrap: 'wrap',
      gap: '24px',
      justifyContent: 'center',
      alignItems: 'stretch',
      minHeight: '50px',
      // Editor visual aid - subtle border to show row boundaries
      outline: '1px dashed rgba(16, 185, 129, 0.2)',
      outlineOffset: '-1px',
      // User styles override defaults
      ...style,
    }}>
      {children}
    </div>
  );

const ColumnRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; children: React.ReactNode }> =
  ({ style, children }) => {
    // Check if column has explicit flex or width set
    const hasExplicitSize = style.flex || style.width;
    // Check if children array is empty (React passes empty array for no children)
    const isEmpty = !children || (Array.isArray(children) && children.length === 0);

    return (
      <div style={{
        // Defaults — must match cmsBuilderDefaultStyle('column') in helpers.php
        display: 'flex',
        flexDirection: 'column',
        gap: '16px',
        alignItems: 'stretch',
        ...(hasExplicitSize ? {} : { flex: 1 }),
        minHeight: '50px',
        minWidth: '50px',
        boxSizing: 'border-box',
        // Editor visual aid - dashed border to show column boundaries
        outline: '1px dashed rgba(255, 255, 255, 0.2)',
        outlineOffset: '-1px',
        // User styles override defaults
        ...style,
      }}>
        {children}
        {/* Empty column placeholder */}
        {isEmpty && (
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            height: '100%',
            minHeight: '80px',
            color: 'rgba(255, 255, 255, 0.3)',
            fontSize: '11px',
            fontWeight: 500,
            textAlign: 'center',
            padding: '8px',
            background: 'repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,0.02) 10px, rgba(255,255,255,0.02) 20px)',
          }}>
            + Drop content here
          </div>
        )}
      </div>
    );
  };

// Editable text component for inline editing
interface EditableTextProps {
  node: DiSyLNode;
  style: CSSProperties;
  isEditing: boolean;
  onStartEdit: () => void;
  onEndEdit: (content: string) => void;
  tag?: keyof JSX.IntrinsicElements;
  placeholder?: string;
}

const EditableText: React.FC<EditableTextProps> = memo(({
  node,
  style,
  isEditing,
  onStartEdit,
  onEndEdit,
  tag: Tag = 'p',
  placeholder = 'Enter text...'
}) => {
  const [localContent, setLocalContent] = useState(node.props.content as string || '');

  useEffect(() => {
    setLocalContent(node.props.content as string || '');
  }, [node.props.content]);

  const handleSave = useCallback((content: string) => {
    onEndEdit(content);
  }, [onEndEdit]);

  const handleCancel = useCallback(() => {
    onEndEdit(localContent);
  }, [onEndEdit, localContent]);

  if (isEditing) {
    return (
      <Suspense fallback={
        <div style={{ ...style, minHeight: '1em', opacity: 0.5 }}>
          {localContent || placeholder}
        </div>
      }>
        <InlineEditor
          content={localContent}
          onSave={handleSave}
          onCancel={handleCancel}
          placeholder={placeholder}
          style={style}
        />
      </Suspense>
    );
  }

  return (
    <Tag
      style={style}
      onDoubleClick={(e) => {
        e.stopPropagation();
        onStartEdit();
      }}
      dangerouslySetInnerHTML={{ __html: localContent || `<span style="opacity:0.5">${placeholder}</span>` }}
    />
  );
});

EditableText.displayName = 'EditableText';

const HeadingRenderer: React.FC<{
  node: DiSyLNode;
  style: CSSProperties;
  isEditing: boolean;
  onStartEdit: () => void;
  onEndEdit: (content: string) => void;
}> = ({ node, style, isEditing, onStartEdit, onEndEdit }) => {
  const level = node.props.level || 2;
  const Tag = `h${level}` as keyof JSX.IntrinsicElements;

  return (
    <EditableText
      node={node}
      style={style}
      isEditing={isEditing}
      onStartEdit={onStartEdit}
      onEndEdit={onEndEdit}
      tag={Tag}
      placeholder="Heading"
    />
  );
};

const TextRenderer: React.FC<{
  node: DiSyLNode;
  style: CSSProperties;
  isEditing: boolean;
  onStartEdit: () => void;
  onEndEdit: (content: string) => void;
}> = ({ node, style, isEditing, onStartEdit, onEndEdit }) => (
  <EditableText
    node={node}
    style={style}
    isEditing={isEditing}
    onStartEdit={onStartEdit}
    onEndEdit={onEndEdit}
    tag="div"
    placeholder="Enter text here..."
  />
);

interface ImageRendererProps {
  node: DiSyLNode;
  style: CSSProperties;
  onOpenMediaLibrary?: () => void;
}

const ImageRenderer: React.FC<ImageRendererProps> = ({ node, style, onOpenMediaLibrary }) => {
  // Extract alignment-related styles to ensure they're applied correctly
  const alignmentStyle: CSSProperties = {
    display: 'block',
    marginLeft: style.marginLeft,
    marginRight: style.marginRight,
  };

  if (!node.props.src) {
    return (
      <div
        style={{
          ...style,
          backgroundColor: '#f8f9fa',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          minHeight: '120px',
          maxWidth: '100%',
          border: '2px dashed #dee2e6',
          cursor: 'pointer',
          gap: '8px',
        }}
        onDoubleClick={(e) => {
          e.stopPropagation();
          onOpenMediaLibrary?.();
        }}
      >
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#adb5bd" strokeWidth="1.5">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
          <circle cx="8.5" cy="8.5" r="1.5" />
          <polyline points="21,15 16,10 5,21" />
        </svg>
        <span style={{ color: '#6c757d', fontSize: '12px', fontWeight: 500 }}>Double-click to add image</span>
      </div>
    );
  }
  return (
    <img
      src={node.props.src}
      alt={node.props.alt || ''}
      style={{
        ...style,
        ...alignmentStyle,
        maxWidth: '100%',
        height: style.height || 'auto',
        objectFit: (style.objectFit as CSSProperties['objectFit']) || (node.props.objectFit as CSSProperties['objectFit']) || 'cover',
      }}
      onDoubleClick={(e) => {
        e.stopPropagation();
        onOpenMediaLibrary?.();
      }}
    />
  );
};

const ButtonRenderer: React.FC<{
  node: DiSyLNode;
  style: CSSProperties;
  isEditing: boolean;
  onStartEdit: () => void;
  onEndEdit: (content: string) => void;
}> = ({ node, style, isEditing, onStartEdit, onEndEdit }) => {
  const variant = node.props.variant || 'primary';
  const variantStyles: Record<string, CSSProperties> = {
    primary: { backgroundColor: '#3b82f6', color: '#fff' },
    secondary: { backgroundColor: '#64748b', color: '#fff' },
    outline: { backgroundColor: 'transparent', border: '2px solid currentColor', color: '#3b82f6' },
    ghost: { backgroundColor: 'transparent', color: '#3b82f6' },
  };

  const buttonStyle: CSSProperties = {
    cursor: 'pointer',
    border: 'none',
    padding: '12px 24px',
    fontWeight: 500,
    fontSize: '14px',
    width: 'fit-content',
    minWidth: '120px',
    ...variantStyles[variant],
    ...style,
  };

  return (
    <EditableText
      node={node}
      style={buttonStyle}
      isEditing={isEditing}
      onStartEdit={onStartEdit}
      onEndEdit={onEndEdit}
      tag="button"
      placeholder="Button"
    />
  );
};

const SpacerRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => (
    <div style={{ height: node.props.height || '48px', ...style }} />
  );

const DividerRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ style }) => (
    <hr style={{
      border: 'none',
      width: '100%',
      height: '1px',
      backgroundColor: '#E5E7EB',
      margin: '24px 0',
      ...style
    }} />
  );

const VideoRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    if (!node.props.src) {
      return (
        <div
          style={{
            ...style,
            backgroundColor: '#212529',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            minHeight: '180px',
          }}
        >
          <span style={{ color: '#6c757d', fontSize: '12px' }}>Add video</span>
        </div>
      );
    }
    return (
      <video
        src={node.props.src}
        controls={node.props.controls !== false}
        autoPlay={node.props.autoplay}
        loop={node.props.loop}
        muted={node.props.muted}
        style={{ ...style, display: 'block', maxWidth: '100%' }}
      />
    );
  };

// =============================================================================
// Icon Renderer
// =============================================================================

const IconRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const iconName = (node.props.icon as string) || 'Star';
    const size = parseInt(node.props.size as string) || 24;

    // Dynamic icon lookup from lucide-react
    const iconMap: Record<string, React.FC<{ size?: number; style?: CSSProperties }>> = {
      Star: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" style={style}><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" /></svg>,
      Heart: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" style={style}><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" /></svg>,
      Check: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><polyline points="20 6 9 17 4 12" /></svg>,
      ArrowRight: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>,
      Mail: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></svg>,
      Phone: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" /></svg>,
      MapPin: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" /></svg>,
      Zap: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" style={style}><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" /></svg>,
      Shield: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>,
      Clock: ({ size, style }) => <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={style}><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>,
    };

    const IconComponent = iconMap[iconName] || iconMap.Star;

    return (
      <div style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', ...style }}>
        <IconComponent size={size} style={{ color: 'inherit' }} />
      </div>
    );
  };

// =============================================================================
// Icon Box Renderer
// =============================================================================

const IconBoxRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const iconName = (node.props.icon as string) || 'Star';
    const title = (node.props.title as string) || 'Feature Title';
    const description = (node.props.description as string) || 'Feature description';

    return (
      <div style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        textAlign: 'center',
        padding: '24px',
        gap: '12px',
        ...style
      }}>
        <div style={{
          width: '64px',
          height: '64px',
          borderRadius: '50%',
          backgroundColor: '#EBF5FF',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: '#3B82F6',
        }}>
          <IconRenderer node={{ ...node, props: { icon: iconName, size: '28' } } as DiSyLNode} style={{}} />
        </div>
        <h3 style={{ fontSize: '18px', fontWeight: 600, margin: 0, color: '#1f2937' }}>{title}</h3>
        <p style={{ fontSize: '14px', color: '#6b7280', margin: 0, lineHeight: 1.5 }}>{description}</p>
      </div>
    );
  };

// =============================================================================
// Tabs Renderer
// =============================================================================

interface TabItem {
  id: string;
  label: string;
  content: string;
}

const TabsRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const tabs = (node.props.tabs as TabItem[]) || [
      { id: 'tab1', label: 'Tab 1', content: 'Content for tab 1' },
      { id: 'tab2', label: 'Tab 2', content: 'Content for tab 2' },
    ];
    const [activeTab, setActiveTab] = useState(tabs[0]?.id || 'tab1');

    const activeContent = tabs.find(t => t.id === activeTab)?.content || '';

    return (
      <div style={{ width: '100%', ...style }}>
        <div style={{
          display: 'flex',
          borderBottom: '1px solid #e5e7eb',
          gap: '4px',
          marginBottom: '16px',
        }}>
          {tabs.map(tab => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              style={{
                padding: '12px 20px',
                border: 'none',
                background: 'none',
                cursor: 'pointer',
                fontSize: '14px',
                fontWeight: 500,
                color: activeTab === tab.id ? '#3B82F6' : '#6b7280',
                borderBottom: activeTab === tab.id ? '2px solid #3B82F6' : '2px solid transparent',
                marginBottom: '-1px',
                transition: 'all 0.2s',
              }}
            >
              {tab.label}
            </button>
          ))}
        </div>
        <div style={{ padding: '20px 0', color: '#374151', fontSize: '14px', lineHeight: 1.6 }}>
          {activeContent}
        </div>
      </div>
    );
  };

// =============================================================================
// Accordion Renderer
// =============================================================================

interface AccordionItem {
  id: string;
  title: string;
  content: string;
  isOpen?: boolean;
}

const AccordionRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const items = (node.props.items as AccordionItem[]) || [
      { id: 'item1', title: 'Accordion Item 1', content: 'Content for item 1', isOpen: true },
      { id: 'item2', title: 'Accordion Item 2', content: 'Content for item 2', isOpen: false },
    ];
    const allowMultiple = node.props.allowMultiple as boolean ?? false;

    const [openItems, setOpenItems] = useState<string[]>(
      items.filter(i => i.isOpen).map(i => i.id)
    );

    const toggleItem = (id: string) => {
      if (allowMultiple) {
        setOpenItems(prev =>
          prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
        );
      } else {
        setOpenItems(prev => prev.includes(id) ? [] : [id]);
      }
    };

    return (
      <div style={{ width: '100%', ...style }}>
        {items.map((item, index) => {
          const isOpen = openItems.includes(item.id);
          return (
            <div
              key={item.id}
              style={{
                borderBottom: index < items.length - 1 ? '1px solid #e5e7eb' : 'none',
              }}
            >
              <button
                onClick={() => toggleItem(item.id)}
                style={{
                  width: '100%',
                  padding: '16px 0',
                  border: 'none',
                  background: 'none',
                  cursor: 'pointer',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  fontSize: '15px',
                  fontWeight: 500,
                  color: '#1f2937',
                  textAlign: 'left',
                }}
              >
                {item.title}
                <svg
                  width="20"
                  height="20"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  style={{
                    transform: isOpen ? 'rotate(180deg)' : 'rotate(0deg)',
                    transition: 'transform 0.2s',
                    color: '#9ca3af',
                  }}
                >
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </button>
              {isOpen && (
                <div style={{
                  padding: '0 0 16px 0',
                  color: '#6b7280',
                  fontSize: '14px',
                  lineHeight: 1.6,
                }}>
                  {item.content}
                </div>
              )}
            </div>
          );
        })}
      </div>
    );
  };

// =============================================================================
// Social Icons Renderer
// =============================================================================

interface SocialIcon {
  platform: string;
  url: string;
}

const SocialIconsRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const icons = (node.props.icons as SocialIcon[]) || [
      { platform: 'facebook', url: '#' },
      { platform: 'twitter', url: '#' },
      { platform: 'instagram', url: '#' },
    ];
    const size = parseInt(node.props.size as string) || 24;

    const socialIcons: Record<string, React.ReactNode> = {
      facebook: <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" /></svg>,
      twitter: <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z" /></svg>,
      instagram: <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" /><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" /><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" /></svg>,
      linkedin: <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z" /><rect x="2" y="9" width="4" height="12" /><circle cx="4" cy="4" r="2" /></svg>,
      youtube: <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z" /><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="white" /></svg>,
      github: <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22" /></svg>,
    };

    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'center', ...style }}>
        {icons.map((icon, index) => (
          <a
            key={index}
            href={icon.url}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: size + 16,
              height: size + 16,
              borderRadius: '50%',
              backgroundColor: '#f3f4f6',
              color: '#374151',
              transition: 'all 0.2s',
            }}
            onClick={(e) => e.preventDefault()}
          >
            {socialIcons[icon.platform] || socialIcons.facebook}
          </a>
        ))}
      </div>
    );
  };

// =============================================================================
// List Renderer
// =============================================================================

const ListRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const items = (node.props.items as string[]) || ['List item 1', 'List item 2', 'List item 3'];
    const listType = (node.props.listType as string) || 'bullet';

    const getBullet = (index: number) => {
      if (listType === 'number') {
        return <span style={{ fontWeight: 600, color: '#3B82F6', minWidth: '24px' }}>{index + 1}.</span>;
      }
      if (listType === 'check') {
        return (
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10B981" strokeWidth="2" style={{ flexShrink: 0 }}>
            <polyline points="20 6 9 17 4 12" />
          </svg>
        );
      }
      return <span style={{ color: '#3B82F6', marginRight: '8px' }}>•</span>;
    };

    return (
      <ul style={{
        listStyle: 'none',
        padding: 0,
        margin: 0,
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        ...style
      }}>
        {items.map((item, index) => (
          <li
            key={index}
            style={{
              display: 'flex',
              alignItems: 'flex-start',
              gap: '12px',
              fontSize: '14px',
              color: '#374151',
              lineHeight: 1.5,
            }}
          >
            {getBullet(index)}
            <span>{item}</span>
          </li>
        ))}
      </ul>
    );
  };

// =============================================================================
// Counter Renderer
// =============================================================================

const CounterRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const startValue = parseInt(node.props.startValue as string) || 0;
    const endValue = parseInt(node.props.endValue as string) || 100;
    const prefix = (node.props.prefix as string) || '';
    const suffix = (node.props.suffix as string) || '';
    const title = (node.props.title as string) || '';

    // In builder, just show the end value (animation would be for preview)
    return (
      <div style={{ textAlign: 'center', padding: '24px', ...style }}>
        <div style={{
          fontSize: '48px',
          fontWeight: 700,
          color: '#1f2937',
          lineHeight: 1,
          marginBottom: '8px',
        }}>
          {prefix}{endValue}{suffix}
        </div>
        {title && (
          <div style={{ fontSize: '14px', color: '#6b7280' }}>{title}</div>
        )}
        <div style={{ fontSize: '10px', color: '#9ca3af', marginTop: '4px' }}>
          Animates from {startValue} to {endValue}
        </div>
      </div>
    );
  };

// =============================================================================
// Progress Bar Renderer
// =============================================================================

const ProgressRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const value = parseInt(node.props.value as string) || 75;
    const max = parseInt(node.props.max as string) || 100;
    const label = (node.props.label as string) || 'Progress';
    const showValue = node.props.showValue !== false;
    const color = (node.props.color as string) || '#3B82F6';

    const percentage = Math.min(100, Math.max(0, (value / max) * 100));

    return (
      <div style={{ width: '100%', ...style }}>
        <div style={{
          display: 'flex',
          justifyContent: 'space-between',
          marginBottom: '8px',
          fontSize: '14px',
        }}>
          <span style={{ color: '#374151', fontWeight: 500 }}>{label}</span>
          {showValue && <span style={{ color: '#6b7280' }}>{value}/{max}</span>}
        </div>
        <div style={{
          width: '100%',
          height: '8px',
          backgroundColor: '#e5e7eb',
          borderRadius: '4px',
          overflow: 'hidden',
        }}>
          <div style={{
            width: `${percentage}%`,
            height: '100%',
            backgroundColor: color,
            borderRadius: '4px',
            transition: 'width 0.3s ease',
          }} />
        </div>
      </div>
    );
  };

// =============================================================================
// Testimonial Renderer
// =============================================================================

const TestimonialRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const quote = (node.props.quote as string) || 'Great product!';
    const author = (node.props.author as string) || 'John Doe';
    const role = (node.props.role as string) || '';
    const avatar = (node.props.avatar as string) || '';
    const rating = parseInt(node.props.rating as string) || 5;

    return (
      <div style={{
        padding: '24px',
        backgroundColor: '#f9fafb',
        borderRadius: '8px',
        ...style
      }}>
        {/* Quote Icon */}
        <svg
          width="32"
          height="32"
          viewBox="0 0 24 24"
          fill="#d1d5db"
          style={{ marginBottom: '16px' }}
        >
          <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z" />
        </svg>

        {/* Quote Text */}
        <p style={{
          fontSize: '16px',
          lineHeight: 1.6,
          color: '#374151',
          marginBottom: '16px',
          fontStyle: 'italic',
        }}>
          "{quote}"
        </p>

        {/* Rating Stars */}
        <div style={{ display: 'flex', gap: '4px', marginBottom: '16px' }}>
          {[1, 2, 3, 4, 5].map(star => (
            <svg
              key={star}
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill={star <= rating ? '#FBBF24' : '#E5E7EB'}
            >
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </svg>
          ))}
        </div>

        {/* Author */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          {avatar ? (
            <img
              src={avatar}
              alt={author}
              style={{
                width: '48px',
                height: '48px',
                borderRadius: '50%',
                objectFit: 'cover',
              }}
            />
          ) : (
            <div style={{
              width: '48px',
              height: '48px',
              borderRadius: '50%',
              backgroundColor: '#3B82F6',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: 'white',
              fontWeight: 600,
              fontSize: '18px',
            }}>
              {author.charAt(0).toUpperCase()}
            </div>
          )}
          <div>
            <div style={{ fontWeight: 600, color: '#1f2937' }}>{author}</div>
            {role && <div style={{ fontSize: '14px', color: '#6b7280' }}>{role}</div>}
          </div>
        </div>
      </div>
    );
  };

// =============================================================================
// Slideshow Renderer
// =============================================================================

interface SlideItem {
  id: string;
  image: string;
  title?: string;
  description?: string;
  link?: string;
  ctaText?: string;
}

const SlideshowRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const slides = (node.props.slides as SlideItem[]) || [
      { id: 'slide1', image: placeholderSvg(1200, 500, '#3B82F6', 'Slide 1'), title: 'Slide 1', description: 'First slide description' },
      { id: 'slide2', image: placeholderSvg(1200, 500, '#10B981', 'Slide 2'), title: 'Slide 2', description: 'Second slide description' },
      { id: 'slide3', image: placeholderSvg(1200, 500, '#F59E0B', 'Slide 3'), title: 'Slide 3', description: 'Third slide description' },
    ];

    const [currentSlide, setCurrentSlide] = useState(0);
    const [kenBurnsIndex, setKenBurnsIndex] = useState(0);
    const showArrows = node.props.showArrows !== false;
    const showDots = node.props.showDots !== false;
    const fullWidth = node.props.fullWidth === true;
    const captionAlign = (node.props.captionAlign as string) || 'center';
    const captionPosition = (node.props.captionPosition as string) || 'bottom';
    const captionColor = (node.props.captionColor as string) || '#ffffff';
    const captionTitleSize = (node.props.captionTitleSize as string) || '32px';
    const captionDescSize = (node.props.captionDescSize as string) || '18px';
    const slideHeight = (node.props.height as string) || '500px';
    const animationStyle = (node.props.animationStyle as string) || 'slide';
    const autoplay = node.props.autoplay === true;
    const interval = (node.props.interval as number) || 5000;

    const nextSlide = useCallback(() => {
      setCurrentSlide((prev) => (prev + 1) % slides.length);
      if (animationStyle === 'kenburns') setKenBurnsIndex((prev) => (prev + 1) % 4);
    }, [slides.length, animationStyle]);

    const prevSlide = () => {
      setCurrentSlide((prev) => (prev - 1 + slides.length) % slides.length);
      if (animationStyle === 'kenburns') setKenBurnsIndex((prev) => (prev + 1) % 4);
    };

    const goToSlide = (index: number) => {
      setCurrentSlide(index);
    };

    // Autoplay timer
    useEffect(() => {
      if (!autoplay || slides.length < 2) return;
      const timer = setInterval(nextSlide, interval);
      return () => clearInterval(timer);
    }, [autoplay, interval, slides.length, nextSlide]);

    return (
      <div style={{
        position: 'relative',
        width: fullWidth ? '100vw' : '100%',
        marginLeft: fullWidth ? 'calc(-50vw + 50%)' : '0',
        overflow: 'hidden',
        backgroundColor: '#000',
        ...style
      }}>
        {/* Slides */}
        {animationStyle === 'slide' || animationStyle === 'carousel' || animationStyle === 'coverflow' ? (
          <div style={{
            position: 'relative',
            height: slideHeight,
            display: 'flex',
            transition: 'transform 0.5s ease-in-out',
            transform: `translateX(-${currentSlide * 100}%)`,
          }}>
            {slides.map((slide, slideIdx) => (
              <div
                key={slide.id}
                style={{
                  minWidth: '100%',
                  height: '100%',
                  position: 'relative',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <img
                  src={slide.image}
                  alt={slide.title || 'Slide'}
                  style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                />
                {(slide.title || slide.description || (slide.ctaText && slide.link)) && (
                  <div style={{
                    position: 'absolute',
                    ...(captionPosition === 'top' ? { top: 0 } : captionPosition === 'center' ? { top: '50%', transform: 'translateY(-50%)' } : { bottom: 0 }),
                    left: 0, right: 0, padding: '24px',
                    background: captionPosition === 'center' ? 'rgba(0,0,0,0.4)' : 'linear-gradient(transparent, rgba(0,0,0,0.6))',
                    textAlign: captionAlign as React.CSSProperties['textAlign'],
                    color: captionColor,
                    textShadow: '0 2px 4px rgba(0,0,0,0.5)',
                  }}>
                    {slide.title && <h3 style={{ fontSize: captionTitleSize, fontWeight: 700, margin: '0 0 8px 0' }}>{slide.title}</h3>}
                    {slide.description && <p style={{ fontSize: captionDescSize, margin: 0, opacity: 0.9 }}>{slide.description}</p>}
                    {slide.ctaText && slide.link && (
                      <a href={slide.link} style={{ display: 'inline-block', marginTop: '12px', padding: '10px 20px', background: '#2563EB', color: '#fff', borderRadius: '6px', textDecoration: 'none', fontSize: '14px', fontWeight: 500, textShadow: 'none' }}>{slide.ctaText}</a>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        ) : (
          /* Fade / Zoom / Ken Burns / Flip — stacked slides with opacity transition */
          <div style={{ position: 'relative', height: slideHeight }}>
            {slides.map((slide, slideIdx) => {
              const isActive = slideIdx === currentSlide;
              const kbTransforms = ['scale(1.1) translate(-2%, -1%)', 'scale(1.15) translate(2%, 1%)', 'scale(1.2) translate(-1%, 2%)', 'scale(1.1) translate(1%, -2%)'];
              const imgStyle: CSSProperties = {
                width: '100%', height: '100%', objectFit: 'cover' as const,
                ...(animationStyle === 'kenburns' && isActive ? {
                  transition: 'transform 8s ease-in-out',
                  transform: kbTransforms[kenBurnsIndex % kbTransforms.length],
                } : animationStyle === 'kenburns' ? {
                  transition: 'none', transform: 'scale(1)',
                } : animationStyle === 'zoom' && isActive ? {
                  transition: 'transform 0.6s ease-in-out', transform: 'scale(1)',
                } : animationStyle === 'zoom' ? {
                  transition: 'transform 0.6s ease-in-out', transform: 'scale(1.1)',
                } : {}),
              };
              return (
                <div
                  key={slide.id}
                  style={{
                    position: slideIdx === 0 ? 'relative' as const : 'absolute' as const,
                    top: 0, left: 0, width: '100%', height: '100%',
                    opacity: isActive ? 1 : 0,
                    transition: animationStyle === 'flip' ? 'opacity 0s' : 'opacity 0.8s ease-in-out',
                    ...(animationStyle === 'flip' ? {
                      transform: isActive ? 'rotateY(0deg)' : 'rotateY(90deg)',
                      backfaceVisibility: 'hidden' as const,
                    } : {}),
                    overflow: 'hidden',
                    zIndex: isActive ? 1 : 0,
                  }}
                >
                  <img src={slide.image} alt={slide.title || 'Slide'} style={imgStyle} />
                  {(slide.title || slide.description || (slide.ctaText && slide.link)) && (
                    <div style={{
                      position: 'absolute',
                      ...(captionPosition === 'top' ? { top: 0 } : captionPosition === 'center' ? { top: '50%', transform: 'translateY(-50%)' } : { bottom: 0 }),
                      left: 0, right: 0, padding: '24px', zIndex: 2,
                      background: captionPosition === 'center' ? 'rgba(0,0,0,0.4)' : 'linear-gradient(transparent, rgba(0,0,0,0.6))',
                      textAlign: captionAlign as React.CSSProperties['textAlign'],
                      color: captionColor,
                      textShadow: '0 2px 4px rgba(0,0,0,0.5)',
                    }}>
                      {slide.title && <h3 style={{ fontSize: captionTitleSize, fontWeight: 700, margin: '0 0 8px 0' }}>{slide.title}</h3>}
                      {slide.description && <p style={{ fontSize: captionDescSize, margin: 0, opacity: 0.9 }}>{slide.description}</p>}
                      {slide.ctaText && slide.link && (
                        <a href={slide.link} style={{ display: 'inline-block', marginTop: '12px', padding: '10px 20px', background: '#2563EB', color: '#fff', borderRadius: '6px', textDecoration: 'none', fontSize: '14px', fontWeight: 500, textShadow: 'none' }}>{slide.ctaText}</a>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {/* Navigation Arrows */}
        {showArrows && slides.length > 1 && (
          <>
            <button
              onClick={prevSlide}
              style={{
                position: 'absolute',
                left: '20px',
                top: '50%',
                transform: 'translateY(-50%)',
                background: 'rgba(255,255,255,0.9)',
                border: 'none',
                borderRadius: '50%',
                width: '48px',
                height: '48px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
                zIndex: 10,
              }}
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="15 18 9 12 15 6" />
              </svg>
            </button>
            <button
              onClick={nextSlide}
              style={{
                position: 'absolute',
                right: '20px',
                top: '50%',
                transform: 'translateY(-50%)',
                background: 'rgba(255,255,255,0.9)',
                border: 'none',
                borderRadius: '50%',
                width: '48px',
                height: '48px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
                zIndex: 10,
              }}
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="9 18 15 12 9 6" />
              </svg>
            </button>
          </>
        )}

        {/* Dots Navigation */}
        {showDots && slides.length > 1 && (
          <div style={{
            position: 'absolute',
            bottom: '20px',
            left: '50%',
            transform: 'translateX(-50%)',
            display: 'flex',
            gap: '8px',
            zIndex: 10,
          }}>
            {slides.map((_, index) => (
              <button
                key={index}
                onClick={() => goToSlide(index)}
                style={{
                  width: '12px',
                  height: '12px',
                  borderRadius: '50%',
                  border: 'none',
                  background: index === currentSlide ? '#fff' : 'rgba(255,255,255,0.5)',
                  cursor: 'pointer',
                  padding: 0,
                  transition: 'all 0.3s',
                }}
              />
            ))}
          </div>
        )}
      </div>
    );
  };

// =============================================================================
// Form Renderer
// =============================================================================

interface FormField {
  id: string;
  type: string;
  label: string;
  placeholder?: string;
  required?: boolean;
}

const FormRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const formType = (node.props.formType as string) || 'contact';
    const fields = (node.props.fields as FormField[]) || [
      { id: 'name', type: 'text', label: 'Name', placeholder: 'Your name', required: true },
      { id: 'email', type: 'email', label: 'Email', placeholder: 'your@email.com', required: true },
    ];
    const submitText = (node.props.submitText as string) || 'Submit';

    const inputStyle: CSSProperties = {
      width: '100%',
      padding: '12px 16px',
      fontSize: '14px',
      border: '1px solid #d1d5db',
      borderRadius: '6px',
      outline: 'none',
      transition: 'border-color 0.2s, box-shadow 0.2s',
    };

    const labelStyle: CSSProperties = {
      display: 'block',
      fontSize: '14px',
      fontWeight: 500,
      color: '#374151',
      marginBottom: '6px',
    };

    return (
      <form
        style={{
          width: '100%',
          maxWidth: '500px',
          display: 'flex',
          flexDirection: 'column',
          gap: '20px',
          ...style
        }}
        onSubmit={(e) => e.preventDefault()}
      >
        {formType === 'newsletter' ? (
          // Newsletter form - single row
          <div style={{ display: 'flex', gap: '12px' }}>
            <input
              type="email"
              placeholder="Enter your email"
              style={{ ...inputStyle, flex: 1 }}
            />
            <button
              type="submit"
              style={{
                padding: '12px 24px',
                backgroundColor: '#3B82F6',
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                fontWeight: 500,
                cursor: 'pointer',
                whiteSpace: 'nowrap',
              }}
            >
              {submitText}
            </button>
          </div>
        ) : (
          // Contact/Custom form - stacked fields
          <>
            {fields.map((field) => (
              <div key={field.id}>
                <label style={labelStyle}>
                  {field.label}
                  {field.required && <span style={{ color: '#EF4444', marginLeft: '4px' }}>*</span>}
                </label>
                {field.type === 'textarea' ? (
                  <textarea
                    placeholder={field.placeholder}
                    rows={4}
                    style={{ ...inputStyle, resize: 'vertical', minHeight: '100px' }}
                  />
                ) : field.type === 'select' ? (
                  <select style={inputStyle}>
                    <option value="">{field.placeholder || 'Select...'}</option>
                  </select>
                ) : (
                  <input
                    type={field.type}
                    placeholder={field.placeholder}
                    style={inputStyle}
                  />
                )}
              </div>
            ))}
            <button
              type="submit"
              style={{
                padding: '14px 28px',
                backgroundColor: '#3B82F6',
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                fontWeight: 500,
                fontSize: '15px',
                cursor: 'pointer',
                alignSelf: 'flex-start',
              }}
            >
              {submitText}
            </button>
          </>
        )}
      </form>
    );
  };

// =============================================================================
// Gallery Renderer
// =============================================================================

interface GalleryImage {
  id: string;
  src: string;
  alt?: string;
  caption?: string;
}

const GalleryRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> =
  ({ node, style, viewport }) => {
    const images = (node.props.images as GalleryImage[]) || [];
    const desktopCols = (node.props.columns as number) || 3;
    // Responsive grid columns: mobile=1, tablet=min(2, desktop), desktop=user value
    const columns = viewport === 'mobile' ? 1 : viewport === 'tablet' ? Math.min(2, desktopCols) : desktopCols;
    const lightbox = node.props.lightbox !== false;
    const gap = (node.props.gap as number) || 16;
    const layout = (node.props.layout as string) || 'grid';
    const imageSize = (node.props.imageSize as string) || 'medium';
    const [selectedImage, setSelectedImage] = useState<GalleryImage | null>(null);

    // Get aspect ratio and max height based on image size
    const getImageStyle = () => {
      switch (imageSize) {
        case 'thumbnail':
          return { aspectRatio: '1', maxHeight: '150px' };
        case 'small':
          return { aspectRatio: '4/3', maxHeight: '200px' };
        case 'medium':
          return { aspectRatio: '16/9', maxHeight: '300px' };
        case 'large':
          return { aspectRatio: '21/9', maxHeight: '400px' };
        case 'full':
          return { aspectRatio: 'auto', maxHeight: 'none' };
        default:
          return { aspectRatio: '16/9', maxHeight: '300px' };
      }
    };

    const imageStyle = getImageStyle();

    // Get container style based on layout
    const getContainerStyle = () => {
      const baseStyle = {
        width: '100%',
        gap: `${gap}px`,
        ...style
      };

      if (layout === 'masonry') {
        return {
          ...baseStyle,
          display: 'grid',
          gridTemplateColumns: `repeat(${columns}, 1fr)`,
          gridAutoRows: 'minmax(20px, auto)',
          alignContent: 'start',
        };
      }

      return {
        ...baseStyle,
        display: 'grid',
        gridTemplateColumns: `repeat(${columns}, 1fr)`,
      };
    };

    return (
      <>
        <div
          style={getContainerStyle()}
        >
          {images.map((image) => (
            <div
              key={image.id}
              style={{
                position: 'relative',
                overflow: 'hidden',
                borderRadius: '8px',
                cursor: lightbox ? 'pointer' : 'default',
                backgroundColor: '#f3f4f6',
                ...(layout !== 'masonry' && {
                  aspectRatio: imageStyle.aspectRatio,
                  maxHeight: imageStyle.maxHeight,
                }),
                ...(layout === 'masonry' && {
                  marginBottom: '8px',
                }),
              }}
              onClick={() => lightbox && setSelectedImage(image)}
            >
              <img
                src={image.src}
                alt={image.alt || ''}
                style={{
                  position: layout === 'masonry' ? 'relative' : 'absolute',
                  top: layout === 'masonry' ? 'auto' : 0,
                  left: layout === 'masonry' ? 'auto' : 0,
                  width: '100%',
                  height: layout === 'masonry' ? 'auto' : '100%',
                  objectFit: 'cover',
                  transition: 'transform 0.3s ease',
                  ...(layout === 'masonry' && {
                    display: 'block',
                    borderRadius: '8px',
                  }),
                  ...(imageSize === 'full' && {
                    maxHeight: imageStyle.maxHeight,
                    width: 'auto',
                  }),
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.transform = 'scale(1.05)';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.transform = 'scale(1)';
                }}
              />
              {image.caption && (
                <div style={{
                  position: 'absolute',
                  bottom: 0,
                  left: 0,
                  right: 0,
                  padding: '8px 12px',
                  background: 'linear-gradient(transparent, rgba(0,0,0,0.7))',
                  color: 'white',
                  fontSize: '13px',
                }}>
                  {image.caption}
                </div>
              )}
            </div>
          ))}
        </div>

        {/* Lightbox Modal */}
        {lightbox && selectedImage && (
          <div
            style={{
              position: 'fixed',
              inset: 0,
              backgroundColor: 'rgba(0,0,0,0.9)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              zIndex: 10000,
              padding: '40px',
            }}
            onClick={() => setSelectedImage(null)}
          >
            <button
              onClick={() => setSelectedImage(null)}
              style={{
                position: 'absolute',
                top: '20px',
                right: '20px',
                background: 'none',
                border: 'none',
                color: 'white',
                fontSize: '32px',
                cursor: 'pointer',
                padding: '8px',
              }}
            >
              ×
            </button>
            <img
              src={selectedImage.src}
              alt={selectedImage.alt || ''}
              style={{
                maxWidth: '100%',
                maxHeight: '100%',
                objectFit: 'contain',
                borderRadius: '4px',
              }}
              onClick={(e) => e.stopPropagation()}
            />
            {selectedImage.caption && (
              <div style={{
                position: 'absolute',
                bottom: '20px',
                left: '50%',
                transform: 'translateX(-50%)',
                color: 'white',
                fontSize: '14px',
                textAlign: 'center',
                maxWidth: '80%',
              }}>
                {selectedImage.caption}
              </div>
            )}
          </div>
        )}
      </>
    );
  };

// =============================================================================
// Map Renderer
// =============================================================================

const MapRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const mapType = (node.props.mapType as string) || 'embed';
    const embedUrl = (node.props.embedUrl as string) || '';
    const latitude = (node.props.latitude as string) || '14.5995';
    const longitude = (node.props.longitude as string) || '120.9842';
    const markerTitle = (node.props.markerTitle as string) || 'Location';

    // Generate OpenStreetMap embed URL if no custom embed URL
    const getMapUrl = () => {
      if (embedUrl) return embedUrl;

      if (mapType === 'openstreetmap' || mapType === 'embed') {
        // OpenStreetMap embed
        return `https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(longitude) - 0.01}%2C${parseFloat(latitude) - 0.01}%2C${parseFloat(longitude) + 0.01}%2C${parseFloat(latitude) + 0.01}&layer=mapnik&marker=${latitude}%2C${longitude}`;
      }

      return '';
    };

    const mapUrl = getMapUrl();

    if (!mapUrl) {
      return (
        <div
          style={{
            width: '100%',
            height: '400px',
            backgroundColor: '#f3f4f6',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
            borderRadius: '8px',
            gap: '12px',
            ...style
          }}
        >
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" strokeWidth="1.5">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
            <circle cx="12" cy="10" r="3" />
          </svg>
          <span style={{ color: '#6b7280', fontSize: '14px' }}>
            Add a map embed URL or coordinates
          </span>
          <span style={{ color: '#9ca3af', fontSize: '12px' }}>
            {markerTitle} • {latitude}, {longitude}
          </span>
        </div>
      );
    }

    return (
      <div style={{ width: '100%', ...style }}>
        <iframe
          src={mapUrl}
          style={{
            width: '100%',
            height: style.height || '400px',
            border: 'none',
            borderRadius: '8px',
          }}
          loading="lazy"
          referrerPolicy="no-referrer-when-downgrade"
          title={markerTitle}
        />
      </div>
    );
  };

// =============================================================================
// Table Renderer
// =============================================================================

const TableRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const headers = (node.props.headers as string[]) || ['Column 1', 'Column 2', 'Column 3'];
    const rows = (node.props.rows as string[][]) || [
      ['Row 1 Cell 1', 'Row 1 Cell 2', 'Row 1 Cell 3'],
      ['Row 2 Cell 1', 'Row 2 Cell 2', 'Row 2 Cell 3'],
    ];
    const striped = node.props.striped !== false;
    const bordered = node.props.bordered !== false;

    const cellStyle: CSSProperties = {
      padding: '12px 16px',
      textAlign: 'left',
      borderBottom: '1px solid #e5e7eb',
      ...(bordered ? { border: '1px solid #e5e7eb' } : {}),
    };

    const headerCellStyle: CSSProperties = {
      ...cellStyle,
      backgroundColor: '#f9fafb',
      fontWeight: 600,
      color: '#374151',
      fontSize: '13px',
      textTransform: 'uppercase',
      letterSpacing: '0.05em',
    };

    return (
      <div style={{ width: '100%', overflowX: 'auto', ...style }}>
        <table style={{
          width: '100%',
          borderCollapse: 'collapse',
          fontSize: '14px',
          color: '#374151',
        }}>
          <thead>
            <tr>
              {headers.map((header, index) => (
                <th key={index} style={headerCellStyle}>
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, rowIndex) => (
              <tr
                key={rowIndex}
                style={{
                  backgroundColor: striped && rowIndex % 2 === 1 ? '#f9fafb' : 'transparent',
                }}
              >
                {row.map((cell, cellIndex) => (
                  <td key={cellIndex} style={cellStyle}>
                    {cell}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  };

// =============================================================================
// Alert Renderer
// =============================================================================

const AlertRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const content = (node.props.content as string) || 'This is an alert message.';
    const alertType = (node.props.alertType as string) || 'info';
    const dismissible = node.props.dismissible !== false;
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) return null;

    const alertStyles: Record<string, { bg: string; border: string; text: string; icon: React.ReactNode }> = {
      info: {
        bg: '#EFF6FF',
        border: '#3B82F6',
        text: '#1E40AF',
        icon: <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>,
      },
      success: {
        bg: '#F0FDF4',
        border: '#22C55E',
        text: '#166534',
        icon: <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" /></svg>,
      },
      warning: {
        bg: '#FFFBEB',
        border: '#F59E0B',
        text: '#92400E',
        icon: <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>,
      },
      error: {
        bg: '#FEF2F2',
        border: '#EF4444',
        text: '#991B1B',
        icon: <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" /></svg>,
      },
    };

    const alertStyle = alertStyles[alertType] || alertStyles.info;

    return (
      <div
        style={{
          width: '100%',
          padding: '16px 20px',
          backgroundColor: alertStyle.bg,
          borderLeft: `4px solid ${alertStyle.border}`,
          borderRadius: '6px',
          display: 'flex',
          alignItems: 'flex-start',
          gap: '12px',
          color: alertStyle.text,
          ...style
        }}
      >
        <div style={{ flexShrink: 0, marginTop: '2px' }}>
          {alertStyle.icon}
        </div>
        <div style={{ flex: 1, fontSize: '14px', lineHeight: 1.5 }}>
          {content}
        </div>
        {dismissible && (
          <button
            onClick={() => setDismissed(true)}
            style={{
              background: 'none',
              border: 'none',
              padding: '4px',
              cursor: 'pointer',
              color: alertStyle.text,
              opacity: 0.7,
              flexShrink: 0,
            }}
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        )}
      </div>
    );
  };

// =============================================================================
// Anchor Renderer
// =============================================================================

const AnchorRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties }> =
  ({ node, style }) => {
    const anchorId = (node.props.anchorId as string) || 'anchor';

    return (
      <div
        id={anchorId}
        style={{
          position: 'relative',
          height: '0',
          ...style
        }}
      >
        {/* Visual indicator in builder only */}
        <div style={{
          position: 'absolute',
          top: '-12px',
          left: '0',
          display: 'flex',
          alignItems: 'center',
          gap: '6px',
          padding: '4px 10px',
          backgroundColor: '#f3f4f6',
          border: '1px dashed #9ca3af',
          borderRadius: '4px',
          fontSize: '11px',
          color: '#6b7280',
          whiteSpace: 'nowrap',
        }}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
          </svg>
          #{anchorId}
        </div>
      </div>
    );
  };

// =============================================================================
// Posts Grid Renderer
// =============================================================================

const PostsGridRenderer: React.FC<{ node: DiSyLNode; style: CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> =
  ({ node, style, viewport }) => {
    const postCount = (node.props.postCount as number) || 3;
    const showDate = node.props.showDate !== false;
    const showExcerpt = node.props.showExcerpt !== false;
    const excerptLength = (node.props.excerptLength as number) || 120;
    const showFeaturedImage = node.props.showFeaturedImage !== false;
    const showAuthor = node.props.showAuthor === true;
    const showReadMore = node.props.showReadMore !== false;
    const readMoreText = String(node.props.readMoreText || 'Read More');
    const desktopCols = (node.props.gridColumns as number) || 3;
    const gridColumns = viewport === 'mobile' ? 1 : viewport === 'tablet' ? Math.min(2, desktopCols) : desktopCols;
    const categoryIds = (node.props.categoryIds as number[]) || [];

    // Placeholder posts for builder preview
    const placeholderPosts = Array.from({ length: postCount }, (_, i) => ({
      id: i + 1,
      title: `Sample Post Title ${i + 1}`,
      excerpt: 'This is a sample excerpt that will be replaced with actual post content when rendered on the frontend.',
      date: new Date(Date.now() - i * 86400000).toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric'
      }),
      author: `Author ${i + 1}`,
      image: `https://images.unsplash.com/photo-${1499750310107 + i * 1000}-5fef28a66643?w=400&h=250&fit=crop`,
    }));

    const gridStyle: CSSProperties = {
      ...previewShellStyle(style),
      position: 'relative',
      display: 'grid',
      gridTemplateColumns: `repeat(${gridColumns}, 1fr)`,
      gap: '24px',
    };

    return (
      <div style={gridStyle}>
        {placeholderPosts.map((post) => (
          <div
            key={post.id}
            style={{
              backgroundColor: '#ffffff',
              borderRadius: '8px',
              overflow: 'hidden',
              boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
              display: 'flex',
              flexDirection: 'column',
            }}
          >
            {showFeaturedImage && (
              <div style={{
                height: '180px',
                backgroundColor: '#f3f4f6',
                backgroundImage: `url(${post.image})`,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
              }} />
            )}
            <div style={{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '8px', flex: 1 }}>
              {showDate && (
                <span style={{ fontSize: '12px', color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                  {post.date}
                </span>
              )}
              {showAuthor && (
                <span style={{ fontSize: '12px', color: '#94a3b8' }}>
                  By {post.author}
                </span>
              )}
              <h3 style={{ fontSize: '18px', fontWeight: '600', color: '#1f2937', margin: 0, lineHeight: '1.4' }}>
                {post.title}
              </h3>
              {showExcerpt && (
                <p style={{ fontSize: '14px', color: '#6b7280', margin: 0, lineHeight: '1.5' }}>
                  {post.excerpt.substring(0, excerptLength)}...
                </p>
              )}
              {showReadMore && (
                <a href="#" style={{ fontSize: '14px', color: '#3B82F6', textDecoration: 'none', marginTop: 'auto' }}>
                  {readMoreText} →
                </a>
              )}
            </div>
          </div>
        ))}
        {/* Builder hint */}
        <div style={{
          position: 'absolute',
          top: '-24px',
          left: '0',
          fontSize: '10px',
          color: '#9ca3af',
          backgroundColor: '#f9fafb',
          padding: '2px 8px',
          borderRadius: '4px',
          border: '1px dashed #d1d5db',
        }}>
          Posts Grid • {postCount} posts{categoryIds.length > 0 ? ` • ${categoryIds.length} categories` : ''}
        </div>
      </div>
    );
  };

// =============================================================================
// Products Grid Renderer
// =============================================================================

const previewShellStyle = (style: React.CSSProperties): React.CSSProperties => {
  const {
    display: _display,
    gridTemplateColumns: _gridTemplateColumns,
    gridTemplateRows: _gridTemplateRows,
    gridAutoFlow: _gridAutoFlow,
    gap: _gap,
    rowGap: _rowGap,
    columnGap: _columnGap,
    ...rest
  } = style || {};

  return {
    width: '100%',
    boxSizing: 'border-box',
    ...rest,
    display: 'block',
  };
};

const ProductsGridRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const {
    itemCount = 6,
    categoryIds = [],
    gridColumns = 3,
    showImage = true,
    showTitle = true,
    showExcerpt = true,
    showMeta = true,
    showAction = true,
  } = node.props as {
    itemCount?: number;
    categoryIds?: number[];
    gridColumns?: number;
    showImage?: boolean;
    showTitle?: boolean;
    showExcerpt?: boolean;
    showMeta?: boolean;
    showAction?: boolean;
  };
  const gridCols = viewport === 'mobile' ? 1 : viewport === 'tablet' ? Math.min(2, gridColumns) : gridColumns;

  return (
    <div style={previewShellStyle(style)} className="pb-products-grid-preview">
      <div style={{
        width: '100%',
        boxSizing: 'border-box',
        padding: '14px',
        backgroundColor: '#f8fafc',
        borderRadius: '12px',
        border: '2px dashed #cbd5e1'
      }}>
        <div style={{
          display: 'flex',
          justifyContent: 'space-between',
          gap: '10px',
          flexWrap: 'wrap',
          marginBottom: '14px',
          fontSize: '11px',
          color: '#64748b'
        }}>
          <span>Products Grid</span>
          <span>{itemCount} products</span>
          <span>{categoryIds.length > 0 ? `${categoryIds.length} categories` : 'All categories'}</span>
        </div>
        <div style={{
          display: 'grid',
          gridTemplateColumns: `repeat(${gridCols}, minmax(0, 1fr))`,
          gap: '16px',
          width: '100%'
        }}>
          {Array.from({ length: Math.min(itemCount, 6) }).map((_, index) => (
            <div key={index} style={{
              backgroundColor: '#ffffff',
              border: '1px solid #dee2e6',
              borderRadius: '6px',
              padding: '12px',
              display: 'flex',
              flexDirection: 'column',
              gap: '8px'
            }}>
              {showImage && (
                <div style={{
                  height: '120px',
                  backgroundColor: '#e9ecef',
                  borderRadius: '4px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: '#6c757d',
                  fontSize: '12px'
                }}>
                  Product Image
                </div>
              )}
              {showTitle && (
                <div style={{
                  height: '8px',
                  backgroundColor: '#dee2e6',
                  borderRadius: '4px',
                  width: '80%'
                }} />
              )}
              {showExcerpt && (
                <div style={{
                  height: '6px',
                  backgroundColor: '#e9ecef',
                  borderRadius: '4px',
                  width: '60%'
                }} />
              )}
              {showMeta && (
                <div style={{
                  height: '6px',
                  backgroundColor: '#0f766e',
                  borderRadius: '4px',
                  width: '45%'
                }} />
              )}
              {showAction && (
                <div style={{
                  marginTop: 'auto',
                  height: '28px',
                  backgroundColor: '#0f172a',
                  borderRadius: '6px',
                  width: '100%'
                }} />
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
});

// =============================================================================
// Team Grid Renderer
// =============================================================================

const TeamGridRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const {
    itemCount = 4,
    departmentIds = [],
    gridColumns = 4,
    showImage = true,
    showTitle = true,
    showExcerpt = true,
    showAction = true,
  } = node.props as {
    itemCount?: number;
    departmentIds?: number[];
    gridColumns?: number;
    showImage?: boolean;
    showTitle?: boolean;
    showExcerpt?: boolean;
    showAction?: boolean;
  };
  const gridCols = viewport === 'mobile' ? 1 : viewport === 'tablet' ? Math.min(2, gridColumns) : gridColumns;

  return (
    <div style={previewShellStyle(style)} className="pb-team-grid-preview">
      <div style={{
        width: '100%',
        boxSizing: 'border-box',
        padding: '14px',
        backgroundColor: '#f8fafc',
        borderRadius: '12px',
        border: '2px dashed #cbd5e1'
      }}>
        <div style={{
          display: 'flex',
          justifyContent: 'space-between',
          gap: '10px',
          flexWrap: 'wrap',
          marginBottom: '14px',
          fontSize: '11px',
          color: '#64748b'
        }}>
          <span>Team Grid</span>
          <span>{itemCount} members</span>
          <span>{departmentIds.length > 0 ? `${departmentIds.length} departments` : 'All departments'}</span>
        </div>
        <div style={{
          display: 'grid',
          gridTemplateColumns: `repeat(${gridCols}, minmax(0, 1fr))`,
          gap: '16px',
          width: '100%'
        }}>
          {Array.from({ length: Math.min(itemCount, 8) }).map((_, index) => (
            <div key={index} style={{
              backgroundColor: '#ffffff',
              border: '1px solid #dee2e6',
              borderRadius: '6px',
              padding: '12px',
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              gap: '8px'
            }}>
              {showImage && (
                <div style={{
                  width: '60px',
                  height: '60px',
                  backgroundColor: '#e9ecef',
                  borderRadius: '50%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: '#6c757d',
                  fontSize: '10px'
                }}>
                  Photo
                </div>
              )}
              {showTitle && (
                <div style={{
                  height: '6px',
                  backgroundColor: '#dee2e6',
                  borderRadius: '4px',
                  width: '80%'
                }} />
              )}
              {showExcerpt && (
                <div style={{
                  height: '4px',
                  backgroundColor: '#e9ecef',
                  borderRadius: '4px',
                  width: '60%'
                }} />
              )}
              {showAction && (
                <div style={{
                  height: '26px',
                  backgroundColor: '#0f172a',
                  borderRadius: '6px',
                  width: '100%'
                }} />
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
});

const EntityViewRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const showFeaturedImage = node.props.showFeaturedImage !== false;
  const showTitle = node.props.showTitle !== false;
  const showMeta = node.props.showMeta !== false;
  const showTypeLabel = node.props.showTypeLabel !== false;
  const showAuthor = node.props.showAuthor !== false;
  const showDate = node.props.showDate !== false;
  const showPricing = node.props.showPricing !== false;
  const showInventory = node.props.showInventory !== false;
  const showSku = node.props.showSku !== false;
  const showProgress = node.props.showProgress !== false;
  const showLessons = node.props.showLessons !== false;
  const showActions = node.props.showActions !== false;
  const showBody = node.props.showBody !== false;

  return (
    <article style={{ display: 'flex', flexDirection: 'column', gap: '20px', maxWidth: '860px', margin: '0 auto', ...style }}>
      {showFeaturedImage && (
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '12px' }}>
          <div style={{ borderRadius: '18px', overflow: 'hidden', backgroundColor: '#e5e7eb' }}>
            <img
              src={placeholderSvg(1200, 560, '#cbd5e1', 'Current Entity Media')}
              alt="Current entity preview"
              style={{ display: 'block', width: '100%', height: '100%', objectFit: 'cover' }}
            />
          </div>
          <div style={{ display: 'grid', gap: '12px' }}>
            {[1, 2].map((item) => (
              <div key={item} style={{ borderRadius: '16px', overflow: 'hidden', backgroundColor: item === 1 ? '#dbeafe' : '#fef3c7' }}>
                <img
                  src={placeholderSvg(480, 270, item === 1 ? '#bfdbfe' : '#fcd34d', `Gallery ${item}`)}
                  alt={`Gallery ${item}`}
                  style={{ display: 'block', width: '100%', height: '100%', objectFit: 'cover' }}
                />
              </div>
            ))}
          </div>
        </div>
      )}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
        {showTitle && <h2 style={{ margin: 0, fontSize: '32px', lineHeight: 1.2, color: '#0f172a' }}>Current Entity Title</h2>}
        {showMeta && (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', fontSize: '13px', color: '#64748b' }}>
            {showTypeLabel && <span style={{ padding: '6px 10px', borderRadius: '999px', backgroundColor: '#e0f2fe', color: '#0369a1', fontWeight: 600 }}>Product</span>}
            {showDate && <span>March 27, 2026</span>}
            {showAuthor && <span>By CMS Author</span>}
          </div>
        )}
        {(showPricing || showInventory) && (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px' }}>
            {showPricing && (
              <span style={{ padding: '8px 12px', borderRadius: '999px', backgroundColor: '#e0f2fe', color: '#0369a1', fontSize: '13px', fontWeight: 600 }}>
                Pricing block
              </span>
            )}
            {showInventory && (
              <span style={{ padding: '8px 12px', borderRadius: '999px', backgroundColor: '#ecfccb', color: '#3f6212', fontSize: '13px', fontWeight: 600 }}>
                Inventory status
              </span>
            )}
            {showInventory && showSku && (
              <span style={{ padding: '8px 12px', borderRadius: '999px', backgroundColor: '#f8fafc', color: '#475569', fontSize: '13px', fontWeight: 600 }}>
                SKU-ENTITY-001
              </span>
            )}
          </div>
        )}
      </div>
      {showProgress && (
        <div style={{ border: '1px solid #dbeafe', borderRadius: '16px', padding: '16px', display: 'flex', flexDirection: 'column', gap: '10px', backgroundColor: '#f8fbff' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', color: '#0369a1', fontWeight: 600 }}>
            <span>Learning Progress</span>
            <span>72%</span>
          </div>
          <div style={{ height: '10px', borderRadius: '999px', backgroundColor: '#dbeafe', overflow: 'hidden' }}>
            <div style={{ width: '72%', height: '100%', backgroundColor: '#0ea5e9' }} />
          </div>
        </div>
      )}
      {showLessons && (
        <div style={{ border: '1px solid #e2e8f0', borderRadius: '18px', padding: '18px', display: 'flex', flexDirection: 'column', gap: '12px' }}>
          <h3 style={{ margin: 0, fontSize: '18px', color: '#0f172a' }}>Contents</h3>
          {[1, 2, 3].map((item) => (
            <div key={item} style={{ display: 'flex', gap: '12px', alignItems: 'center', color: '#475569', fontSize: '14px' }}>
              <span style={{ width: '24px', height: '24px', borderRadius: '999px', backgroundColor: '#e2e8f0', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700 }}>{item}</span>
              <span>Lesson {item}</span>
            </div>
          ))}
        </div>
      )}
      {showActions && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px' }}>
          <a href="#" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: '12px 18px', borderRadius: '12px', backgroundColor: '#0f172a', color: '#ffffff', textDecoration: 'none', fontSize: '14px', fontWeight: 600 }}>Buy Now</a>
          <a href="#" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: '12px 18px', borderRadius: '12px', backgroundColor: '#ffffff', border: '1px solid #cbd5e1', color: '#0f172a', textDecoration: 'none', fontSize: '14px', fontWeight: 600 }}>Inquire</a>
        </div>
      )}
      {showBody && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', color: '#475569', lineHeight: 1.7, fontSize: '15px' }}>
          <p style={{ margin: 0 }}>This component uses the current entity context when rendered on the public site. In the builder it previews the same structure the theme uses for media, metadata, capability blocks, and body content.</p>
          <p style={{ margin: 0 }}>Use it when you want a page-builder section to stay aligned with the current entity view contract instead of duplicating post, product, or course details by hand.</p>
        </div>
      )}
    </article>
  );
});

const EntityListRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const {
    entityType = 'post',
    itemCount = 6,
    layout = 'grid',
    showFeaturedImage = true,
    showTitle = true,
    showExcerpt = true,
    excerptLength = 120,
    showPricing = true,
    showInventory = true,
    gridColumns = 3,
  } = node.props as {
    entityType?: string;
    itemCount?: number;
    layout?: 'grid' | 'list';
    showFeaturedImage?: boolean;
    showTitle?: boolean;
    showExcerpt?: boolean;
    excerptLength?: number;
    showPricing?: boolean;
    showInventory?: boolean;
    gridColumns?: number;
  };

  const columns = layout === 'list'
    ? 1
    : viewport === 'mobile'
      ? 1
      : viewport === 'tablet'
        ? Math.min(2, gridColumns)
        : gridColumns;

  const items = Array.from({ length: Math.min(itemCount, 6) }, (_, index) => ({
    id: index + 1,
    title: `Sample ${entityType} ${index + 1}`,
    excerpt: 'This item previews the shared entity-list contract used by the public theme, including media, pricing, and stock states.',
    price: `$${(29 + index * 5).toFixed(2)}`,
    stock: index % 3 === 0 ? 'Low stock' : index % 2 === 0 ? 'In stock' : 'Out of stock',
  }));

  return (
    <div style={{ ...previewShellStyle(style), display: 'grid', gridTemplateColumns: `repeat(${columns}, 1fr)`, gap: '20px' }}>
      {items.map((item) => (
        <article key={item.id} style={{ backgroundColor: '#ffffff', borderRadius: '18px', border: '1px solid #e2e8f0', overflow: 'hidden', boxShadow: '0 10px 30px rgba(15, 23, 42, 0.06)' }}>
          {showFeaturedImage && (
            <div style={{ aspectRatio: '16 / 9', backgroundColor: '#e2e8f0' }}>
              <img
                src={placeholderSvg(720, 405, item.id % 2 === 0 ? '#93c5fd' : '#fcd34d', item.title)}
                alt={item.title}
                style={{ display: 'block', width: '100%', height: '100%', objectFit: 'cover' }}
              />
            </div>
          )}
          <div style={{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {showTitle && <h3 style={{ margin: 0, fontSize: '18px', lineHeight: 1.35, color: '#0f172a' }}>{item.title}</h3>}
            {showExcerpt && <p style={{ margin: 0, fontSize: '14px', lineHeight: 1.6, color: '#64748b' }}>{item.excerpt.substring(0, excerptLength)}{item.excerpt.length > excerptLength ? '...' : ''}</p>}
            {(showPricing || showInventory) && (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', marginTop: '4px' }}>
                {showPricing && <span style={{ fontSize: '13px', fontWeight: 700, color: '#0f766e' }}>{item.price}</span>}
                {showInventory && <span style={{ fontSize: '12px', color: item.stock === 'Out of stock' ? '#dc2626' : item.stock === 'Low stock' ? '#d97706' : '#16a34a' }}>{item.stock}</span>}
              </div>
            )}
          </div>
        </article>
      ))}
    </div>
  );
});

// =============================================================================
// Pricing Table Renderer (Jan 2026)
// =============================================================================

const PricingTableRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { planName = 'Professional', price = '49', currency = '$', period = '/month', features = [], buttonText = 'Get Started', highlighted = false, ribbon = '' } = node.props as any;

  return (
    <div style={{
      border: highlighted ? '2px solid #3b82f6' : '1px solid #e5e7eb',
      position: 'relative',
      ...style,
    }}>
      {highlighted && ribbon && (
        <div style={{ position: 'absolute', top: '-12px', left: '50%', transform: 'translateX(-50%)', backgroundColor: '#3b82f6', color: 'white', padding: '4px 16px', borderRadius: '12px', fontSize: '11px', fontWeight: 600 }}>
          {ribbon}
        </div>
      )}
      <div style={{ fontSize: '18px', fontWeight: 600, color: '#1f2937', marginBottom: '8px' }}>{planName}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'center', gap: '2px', marginBottom: '16px' }}>
        <span style={{ fontSize: '16px', color: '#6b7280' }}>{currency}</span>
        <span style={{ fontSize: '48px', fontWeight: 700, color: '#111827' }}>{price}</span>
        <span style={{ fontSize: '14px', color: '#6b7280' }}>{period}</span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '24px' }}>
        {(features as any[]).slice(0, 5).map((feature: any, idx: number) => (
          <div key={idx} style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '14px', color: feature.included ? '#374151' : '#9ca3af' }}>
            <span style={{ color: feature.included ? '#10b981' : '#d1d5db' }}>{feature.included ? '✓' : '×'}</span>
            <span style={{ textDecoration: feature.included ? 'none' : 'line-through' }}>{feature.text}</span>
          </div>
        ))}
      </div>
      <button style={{ width: '100%', padding: '12px 24px', backgroundColor: highlighted ? '#3b82f6' : '#1f2937', color: 'white', border: 'none', borderRadius: '8px', fontSize: '14px', fontWeight: 600, cursor: 'pointer' }}>
        {buttonText}
      </button>
    </div>
  );
});

// =============================================================================
// Countdown Renderer (Jan 2026)
// =============================================================================

const CountdownRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const {
    labels = { days: 'Days', hours: 'Hours', minutes: 'Minutes', seconds: 'Seconds' },
    showDays = true,
    showHours = true,
    showMinutes = true,
    showSeconds = true,
  } = node.props as any;
  const isMobile = viewport === 'mobile';

  const TimeBox = ({ value, label }: { value: string; label: string }) => (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '4px' }}>
      <div style={{ backgroundColor: '#f9fafb', color: '#1f2937', padding: isMobile ? '10px 14px' : '16px 20px', borderRadius: '8px', fontSize: isMobile ? '24px' : '36px', fontWeight: 700, minWidth: isMobile ? '56px' : '80px', textAlign: 'center' }}>
        {value}
      </div>
      <span style={{ fontSize: isMobile ? '10px' : '12px', color: '#6b7280', marginTop: '4px', textTransform: 'uppercase' }}>{label}</span>
    </div>
  );

  const parts = [
    showDays ? { value: '07', label: labels.days } : null,
    showHours ? { value: '12', label: labels.hours } : null,
    showMinutes ? { value: '45', label: labels.minutes } : null,
    showSeconds ? { value: '30', label: labels.seconds } : null,
  ].filter(Boolean) as Array<{ value: string; label: string }>;

  if (parts.length === 0) {
    return <div style={{ color: '#6b7280', textAlign: 'center', ...style }}>Enable at least one time unit.</div>;
  }

  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: isMobile ? '8px' : '16px', ...style }}>
      {parts.map((part, index) => (
        <React.Fragment key={`${part.label}-${index}`}>
          <TimeBox value={part.value} label={part.label} />
          {index < parts.length - 1 && (
            <div style={{ fontSize: isMobile ? '22px' : '32px', fontWeight: 700, color: '#1f2937', alignSelf: 'flex-start', paddingTop: isMobile ? '10px' : '16px' }}>:</div>
          )}
        </React.Fragment>
      ))}
    </div>
  );
});

// =============================================================================
// Star Rating Renderer (Jan 2026)
// =============================================================================

const StarRatingRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { rating = 4.5, maxRating = 5, showNumber = true, color = '#fbbf24', emptyColor = '#e5e7eb' } = node.props as any;

  const stars = [];
  for (let i = 1; i <= maxRating; i++) {
    const filled = i <= Math.floor(rating);
    const partial = !filled && i === Math.ceil(rating) && rating % 1 !== 0;
    stars.push(
      <span key={i} style={{ color: filled ? color : (partial ? color : emptyColor), fontSize: '20px' }}>
        {filled ? '★' : (partial ? '★' : '☆')}
      </span>
    );
  }

  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', ...style }}>
      <div style={{ display: 'flex', gap: '2px' }}>{stars}</div>
      {showNumber && <span style={{ fontSize: '16px', fontWeight: 600, color: '#374151' }}>{rating}</span>}
    </div>
  );
});

// =============================================================================
// Call to Action Renderer (Jan 2026)
// =============================================================================

const CallToActionRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const { title = 'Ready to Get Started?', description = 'Join thousands of satisfied customers.', buttonText = 'Start Free Trial', secondaryButtonText = '', layout = 'horizontal' } = node.props as any;

  // Horizontal CTA stacks to column on mobile
  const isHorizontal = layout === 'horizontal' && viewport !== 'mobile';

  return (
    <div style={{ display: 'flex', flexDirection: isHorizontal ? 'row' : 'column', alignItems: 'center', justifyContent: 'space-between', gap: '24px', ...style }}>
      <div style={{ flex: 1, textAlign: isHorizontal ? 'left' : 'center' }}>
        <h3 style={{ fontSize: '28px', fontWeight: 700, marginBottom: '8px', color: 'inherit' }}>{title}</h3>
        <p style={{ fontSize: '16px', opacity: 0.9, margin: 0 }}>{description}</p>
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', justifyContent: isHorizontal ? 'flex-end' : 'center' }}>
        <button style={{ padding: '14px 32px', backgroundColor: 'white', color: '#3b82f6', border: 'none', borderRadius: '8px', fontSize: '16px', fontWeight: 600, cursor: 'pointer', whiteSpace: 'nowrap' }}>
          {buttonText}
        </button>
        {secondaryButtonText && (
          <button style={{ padding: '14px 32px', backgroundColor: 'transparent', color: 'white', border: '1px solid rgba(255,255,255,0.45)', borderRadius: '8px', fontSize: '16px', fontWeight: 500, cursor: 'pointer', whiteSpace: 'nowrap' }}>
            {secondaryButtonText}
          </button>
        )}
      </div>
    </div>
  );
});

// =============================================================================
// Flip Box Renderer (Jan 2026)
// =============================================================================

const FlipBoxRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const { frontTitle = 'Front Title', frontDescription = 'Hover to see more', backTitle = 'Back Title' } = node.props as any;

  return (
    <div style={{ perspective: '1000px', maxWidth: '100%', ...style }}>
      <div style={{
        width: '100%',
        height: '100%',
        minHeight: '250px',
        position: 'relative',
        backgroundColor: '#3b82f6',
        borderRadius: '12px',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '24px',
        color: 'white',
        textAlign: 'center'
      }}>
        <div style={{ fontSize: '14px', color: 'rgba(255,255,255,0.6)', marginBottom: '8px' }}>⟳ Flip Box Preview</div>
        <h4 style={{ fontSize: '20px', fontWeight: 600, marginBottom: '8px' }}>{frontTitle}</h4>
        <p style={{ fontSize: '14px', opacity: 0.8 }}>{frontDescription}</p>
        <div style={{ marginTop: '16px', padding: '8px 16px', backgroundColor: 'rgba(255,255,255,0.2)', borderRadius: '6px', fontSize: '12px' }}>
          Back: {backTitle}
        </div>
      </div>
    </div>
  );
});

// =============================================================================
// Image Box Renderer (Jan 2026)
// =============================================================================

const ImageBoxRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { src = '', title = 'Image Title', description = 'A brief description.' } = node.props as any;

  return (
    <div style={{ textAlign: 'center' as const, ...style }}>
      <div style={{
        width: '100%',
        height: '200px',
        backgroundColor: '#f3f4f6',
        borderRadius: '8px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        marginBottom: '16px',
        overflow: 'hidden'
      }}>
        {src ? (
          <img src={src} alt={title} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        ) : (
          <span style={{ color: '#9ca3af', fontSize: '14px' }}>📷 Image</span>
        )}
      </div>
      <h4 style={{ fontSize: '18px', fontWeight: 600, color: '#1f2937', marginBottom: '8px' }}>{title}</h4>
      <p style={{ fontSize: '14px', color: '#6b7280', margin: 0 }}>{description}</p>
    </div>
  );
});

// =============================================================================
// Logo Grid Renderer (Jan 2026)
// =============================================================================

const LogoGridRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties; viewport?: 'desktop' | 'tablet' | 'mobile' }> = memo(({ node, style, viewport }) => {
  const { logos = [], columns: desktopCols = 4, grayscale = true } = node.props as any;
  const columns = viewport === 'mobile' ? Math.min(2, desktopCols) : viewport === 'tablet' ? Math.min(3, desktopCols) : desktopCols;

  return (
    <div style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, 1fr)`, gap: '32px', alignItems: 'center', ...style }}>
      {(logos.length > 0 ? logos : Array(4).fill({ id: '', src: '', alt: 'Logo' })).map((logo: any, idx: number) => (
        <div key={logo.id || idx} style={{
          height: '60px',
          backgroundColor: '#f9fafb',
          borderRadius: '8px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          filter: grayscale ? 'grayscale(100%)' : 'none',
          transition: 'filter 0.3s'
        }}>
          {logo.src ? (
            <img src={logo.src} alt={logo.alt} style={{ maxHeight: '40px', maxWidth: '100%' }} />
          ) : (
            <span style={{ color: '#9ca3af', fontSize: '12px' }}>Logo {idx + 1}</span>
          )}
        </div>
      ))}
    </div>
  );
});

// =============================================================================
// Blockquote Renderer (Jan 2026)
// =============================================================================

const BlockquoteRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { content = 'The only way to do great work is to love what you do.', author = 'Steve Jobs', authorTitle = 'Co-founder, Apple Inc.' } = node.props as any;

  return (
    <blockquote style={{ margin: 0, ...style }}>
      <div style={{ fontSize: '24px', color: '#3b82f6', marginBottom: '8px' }}>"</div>
      <p style={{ fontSize: '20px', lineHeight: 1.6, color: '#374151', margin: '0 0 16px 0' }}>{content}</p>
      <footer style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
        <div style={{ width: '48px', height: '48px', backgroundColor: '#e5e7eb', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#9ca3af', fontSize: '12px' }}>
          👤
        </div>
        <div>
          <div style={{ fontWeight: 600, color: '#1f2937', fontStyle: 'normal' }}>{author}</div>
          <div style={{ fontSize: '14px', color: '#6b7280', fontStyle: 'normal' }}>{authorTitle}</div>
        </div>
      </footer>
    </blockquote>
  );
});

// =============================================================================
// Toggle Renderer (Jan 2026)
// =============================================================================

const ToggleRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { title = 'Click to expand', content = 'Hidden content here.', isOpen = false } = node.props as any;

  return (
    <div style={{ overflow: 'hidden', ...style }}>
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '16px',
        backgroundColor: '#f9fafb',
        cursor: 'pointer'
      }}>
        <span style={{ fontWeight: 500, color: '#1f2937' }}>{title}</span>
        <span style={{ color: '#6b7280', transform: isOpen ? 'rotate(180deg)' : 'none', transition: 'transform 0.2s' }}>▼</span>
      </div>
      {isOpen && (
        <div style={{ padding: '16px', borderTop: '1px solid #e5e7eb', color: '#4b5563' }}>
          {content}
        </div>
      )}
    </div>
  );
});

// =============================================================================
// Search Box Renderer (Jan 2026)
// =============================================================================

const SearchBoxRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { placeholder = 'Search...', buttonText = 'Search', showButton = true, style: inputStyle = 'rounded' } = node.props as any;

  const borderRadius = inputStyle === 'pill' ? '50px' : inputStyle === 'rounded' ? '8px' : '0';

  return (
    <div style={{ display: 'flex', gap: '8px', ...style }}>
      <input
        type="text"
        placeholder={placeholder}
        style={{
          flex: 1,
          padding: '12px 16px',
          border: '1px solid #d1d5db',
          borderRadius,
          fontSize: '14px',
          outline: 'none'
        }}
      />
      {showButton && (
        <button style={{
          padding: '12px 24px',
          backgroundColor: '#3b82f6',
          color: 'white',
          border: 'none',
          borderRadius,
          fontSize: '14px',
          fontWeight: 500,
          cursor: 'pointer'
        }}>
          {buttonText}
        </button>
      )}
    </div>
  );
});

// =============================================================================
// Breadcrumbs Renderer (Jan 2026)
// =============================================================================

const BreadcrumbsRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { items = [{ label: 'Home' }, { label: 'Products' }, { label: 'Current' }], separator = '/' } = node.props as any;

  return (
    <nav style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' as const, ...style }}>
      {(items as any[]).map((item: any, idx: number) => (
        <span key={idx} style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <span style={{
            color: idx === items.length - 1 ? '#1f2937' : '#3b82f6',
            fontWeight: idx === items.length - 1 ? 500 : 400,
            cursor: idx === items.length - 1 ? 'default' : 'pointer'
          }}>
            {item.label}
          </span>
          {idx < items.length - 1 && (
            <span style={{ color: '#9ca3af' }}>{separator}</span>
          )}
        </span>
      ))}
    </nav>
  );
});

// =============================================================================
// Code Block Renderer (Jan 2026)
// =============================================================================

const CodeBlockRenderer: React.FC<{ node: DiSyLNode; style: React.CSSProperties }> = memo(({ node, style }) => {
  const { code = 'const greeting = "Hello, World!";', language = 'javascript', showLineNumbers = true, theme = 'dark' } = node.props as any;

  const isDark = theme === 'dark';
  const lines = code.split('\n');

  return (
    <div style={{ overflow: 'hidden', ...style }}>
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '8px 16px',
        backgroundColor: isDark ? '#1f2937' : '#f3f4f6',
        borderBottom: `1px solid ${isDark ? '#374151' : '#e5e7eb'}`
      }}>
        <span style={{ fontSize: '12px', color: isDark ? '#9ca3af' : '#6b7280' }}>{language}</span>
        <button style={{
          padding: '4px 8px',
          backgroundColor: 'transparent',
          border: `1px solid ${isDark ? '#4b5563' : '#d1d5db'}`,
          borderRadius: '4px',
          color: isDark ? '#9ca3af' : '#6b7280',
          fontSize: '11px',
          cursor: 'pointer'
        }}>
          Copy
        </button>
      </div>
      <pre style={{
        margin: 0,
        padding: '16px',
        backgroundColor: isDark ? '#111827' : '#ffffff',
        color: isDark ? '#e5e7eb' : '#1f2937',
        fontSize: '13px',
        lineHeight: 1.6,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        overflow: 'auto'
      }}>
        {lines.map((line: string, idx: number) => (
          <div key={idx} style={{ display: 'flex' }}>
            {showLineNumbers && (
              <span style={{
                width: '32px',
                color: isDark ? '#4b5563' : '#9ca3af',
                userSelect: 'none',
                textAlign: 'right',
                paddingRight: '16px'
              }}>
                {idx + 1}
              </span>
            )}
            <code>{line || ' '}</code>
          </div>
        ))}
      </pre>
    </div>
  );
});

// =============================================================================
// Main Node Renderer
// =============================================================================

const NodeRenderer: React.FC<NodeRendererProps> = memo(({
  node,
  viewport,
  isSelected,
  isHovered,
  isParentOfSelected: _isParentOfSelected = false,
  structureMode = false,
  onSelect,
  onHover,
  onContentChange,
  onPropsChange,
  onMoveNode,
  onStyleChange,
  selectedIds = [],
  parentId,
  indexInParent = 0,
}) => {
  const nodeRef = useRef<HTMLDivElement>(null);
  const style = nodeStyleToCSS(node.style, viewport, node.type);
  const customId = sanitizeCustomId(node.props.customId);
  const customClasses = sanitizeCustomClasses(node.props.customClasses);
  const customAttributes = parseCustomAttributes(node.props.customAttributes);
  const visibilityClassName = mapVisibilityClassName(node.props.visibility);
  const visibilityPreviewStyle = getPreviewVisibilityStyle(node.props.visibility, viewport);
  const [isEditing, setIsEditing] = useState(false);
  const [dropPosition, setDropPosition] = useState<'before' | 'after' | 'inside' | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isResizing, setIsResizing] = useState(false);
  const [showMediaLibrary, setShowMediaLibrary] = useState(false);

  // Check if any child is selected (for parent highlighting)
  const hasSelectedChild = node.children.some(child =>
    selectedIds.includes(child.id) ||
    child.children.some(grandchild => selectedIds.includes(grandchild.id))
  );

  // Determine resize behavior based on node type
  // - Sections: height only (always full width)
  // - Containers/Columns: width and height (flex children need width control)
  // - Content elements: width and height
  const getResizeConfig = () => {
    switch (node.type) {
      case 'document':
        return { resizable: false, horizontal: false, vertical: false };
      case 'section':
        return { resizable: true, horizontal: false, vertical: true }; // Height only
      case 'container':
      case 'column':
        return { resizable: true, horizontal: true, vertical: true }; // Full control
      case 'row':
        return { resizable: true, horizontal: false, vertical: true }; // Height only
      case 'divider':
        return { resizable: false, horizontal: false, vertical: false };
      case 'spacer':
        return { resizable: true, horizontal: false, vertical: true }; // Height only
      default:
        return { resizable: true, horizontal: true, vertical: true };
    }
  };

  const resizeConfig = getResizeConfig();
  const isResizable = resizeConfig.resizable;

  // Determine if label bar should show (not for document)
  // Also keep visible while dragging so the handle doesn't vanish mid-drag
  const showLabelBar = node.type !== 'document' && (isSelected || isHovered || structureMode || isDragging);

  // Handle resize - receives the initial size and current deltas from ResizeHandles
  // For containers/columns in flex parents, we update flex property for proper sizing
  const handleResize = useCallback((direction: string, deltaX: number, deltaY: number, initialWidth: number, initialHeight: number) => {
    if (!onStyleChange) return;

    setIsResizing(true);

    let newWidth = initialWidth;
    let newHeight = initialHeight;

    // Calculate new dimensions based on direction
    if (direction.includes('e')) newWidth += deltaX;
    if (direction.includes('w')) newWidth -= deltaX;
    if (direction.includes('s')) newHeight += deltaY;
    if (direction.includes('n')) newHeight -= deltaY;

    // Enforce minimum size
    newWidth = Math.max(40, newWidth);
    newHeight = Math.max(20, newHeight);

    // Apply the new size
    const newStyle: Partial<NodeStyle> = {};
    if (direction.includes('e') || direction.includes('w')) {
      const widthPx = `${Math.round(newWidth)}px`;
      newStyle.width = widthPx;

      // For containers/columns, also set flex to use the width as flex-basis
      // This ensures the width is respected in flex layouts
      if (node.type === 'container' || node.type === 'column') {
        // Use flex: 0 0 <width> to prevent flex from overriding the width
        // flex-grow: 0, flex-shrink: 0, flex-basis: width
        newStyle.flex = `0 0 ${widthPx}`;
      }
    }
    if (direction.includes('s') || direction.includes('n')) {
      newStyle.height = `${Math.round(newHeight)}px`;
    }

    onStyleChange(node.id, newStyle);
  }, [node.id, node.type, onStyleChange]);

  // Handle resize end
  const handleResizeEnd = useCallback(() => {
    setIsResizing(false);
  }, []);

  const handleClick = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    if (!isEditing) {
      onSelect(node.id, e.shiftKey);
    }
  }, [node.id, onSelect, isEditing]);

  const handleMouseEnter = useCallback(() => {
    onHover(node.id);
  }, [node.id, onHover]);

  const handleMouseLeave = useCallback(() => {
    onHover(null);
  }, [onHover]);

  const handleStartEdit = useCallback(() => {
    setIsEditing(true);
  }, []);

  const handleEndEdit = useCallback((content: string) => {
    setIsEditing(false);
    if (onContentChange) {
      onContentChange(node.id, content);
    }
  }, [node.id, onContentChange]);

  // Drag & Drop handlers — any element can be dragged (unless editing text)
  const handleDragStart = useCallback((e: React.DragEvent) => {
    const target = e.target as HTMLElement;

    // Don't start drag from inside editable content (TinyMCE, contenteditable)
    if (target.closest('[contenteditable="true"]') || target.closest('.tox-editor-container') || target.closest('iframe')) {
      e.preventDefault();
      return;
    }

    // Don't drag the document root
    if (node.type === 'document') {
      e.preventDefault();
      return;
    }

    e.stopPropagation();
    setIsDragging(true);
    e.dataTransfer.setData(INTERNAL_NODE_DND_MIME, node.id);
    e.dataTransfer.setData('text/plain', node.id);
    e.dataTransfer.effectAllowed = 'move';

    // Compact drag preview showing element type instead of full-size screenshot
    const displayName = node.meta?.name || node.type.charAt(0).toUpperCase() + node.type.slice(1).replace(/_/g, ' ');
    const preview = document.createElement('div');
    preview.textContent = `⠿ ${displayName}`;
    preview.style.cssText = 'padding:4px 12px;background:#0078d4;color:#fff;font-size:12px;font-family:system-ui;border-radius:4px;position:fixed;top:-200px;left:-200px;white-space:nowrap;';
    document.body.appendChild(preview);
    e.dataTransfer.setDragImage(preview, 0, 12);
    requestAnimationFrame(() => preview.remove());
  }, [node.id, node.meta?.name, node.type]);

  const handleDragEnd = useCallback(() => {
    setIsDragging(false);
    setDropPosition(null);
  }, []);

  const handleDragOver = useCallback((e: React.DragEvent) => {
    const dragTypes = Array.from(e.dataTransfer.types || []);
    // Only respond to internal node drags — ignore component-panel or external drags
    if (!dragTypes.includes(INTERNAL_NODE_DND_MIME)) {
      return;
    }

    e.preventDefault();
    e.stopPropagation();

    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const y = e.clientY - rect.top;
    const height = rect.height;

    // Determine drop position based on mouse position
    const isContainer = ['document', 'section', 'container', 'row', 'column'].includes(node.type);

    if (isContainer && y > height * 0.25 && y < height * 0.75) {
      setDropPosition('inside');
    } else if (y < height * 0.5) {
      setDropPosition('before');
    } else {
      setDropPosition('after');
    }

    e.dataTransfer.dropEffect = 'move';
  }, [node.type]);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDropPosition(null);
  }, []);

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();

    const draggedNodeId =
      e.dataTransfer.getData(INTERNAL_NODE_DND_MIME) ||
      e.dataTransfer.getData('text/plain');
    if (!draggedNodeId || draggedNodeId === node.id || !onMoveNode) {
      setDropPosition(null);
      return;
    }

    // Handle the drop based on position
    if (dropPosition === 'inside') {
      // Drop inside this container
      onMoveNode(draggedNodeId, node.id, node.children.length);
    } else if (parentId) {
      // Drop before or after this node in the parent
      const newIndex = dropPosition === 'before' ? indexInParent : indexInParent + 1;
      onMoveNode(draggedNodeId, parentId, newIndex);
    }

    setDropPosition(null);
  }, [node.id, node.children.length, dropPosition, onMoveNode, parentId, indexInParent]);

  // Selection/hover outline styles - professional minimal styling
  // Structure mode: always show outlines, dim content
  const getOutlineStyle = () => {
    if (structureMode) {
      // Structure mode: always show outlines
      if (isSelected) return '2px solid #0078d4';
      if (isHovered) return '1px solid #0078d4';
      return '1px dashed #9ca3af'; // Gray dashed for all elements
    }
    // Normal mode
    if (isSelected) return '2px solid #0078d4';
    if (isHovered) return '1px dashed #0078d4';
    return 'none';
  };

  // Get animation styles from node props
  const getHoverAnimationStyles = (): CSSProperties => {
    const animStyles: CSSProperties = {};
    const hoverAnimation = node.props.hoverAnimation as string;

    // Hover animations need transition
    if (hoverAnimation) {
      animStyles.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
    }

    return animStyles;
  };

  const getEntranceAnimationStyles = (): CSSProperties => {
    const animStyles: CSSProperties = {};
    const entranceAnimation = node.props.entranceAnimation as string;
    const animationDuration = (node.props.animationDuration as string) || '0.6s';
    const animationDelay = (node.props.animationDelay as string) || '0s';

    if (entranceAnimation) {
      animStyles.animation = `${entranceAnimation} ${animationDuration} ease-out ${animationDelay} forwards`;
    }

    return animStyles;
  };

  // Determine wrapper display based on node type and alignment
  const getWrapperDisplay = (): CSSProperties['display'] => {
    // Buttons are inline-block
    if (node.type === 'button') return 'inline-block';
    // Images should be block to allow margin-based centering
    if (node.type === 'image') return 'block';
    return undefined;
  };

  const wrapperStyle: CSSProperties = {
    position: 'relative',
    outline: getOutlineStyle(),
    outlineOffset: '-1px',
    cursor: isResizing ? 'default' : isEditing ? 'text' : 'pointer',
    transition: 'outline 0.1s ease, background-color 0.1s ease',
    opacity: isDragging ? 0.5 : 1,
    // Parent highlighting: subtle background when child is selected
    backgroundColor: hasSelectedChild && !isSelected
      ? 'rgba(0, 120, 212, 0.03)'
      : undefined,
    // Display mode based on node type
    display: getWrapperDisplay(),
    // Pass through layout-participation properties so this wrapper div
    // behaves correctly as a flex/grid item inside a parent flex/grid container.
    // Without these, flex sizing (e.g. 33/67 preset) is lost because the
    // inner renderer div is NOT the direct child of the parent flex container.
    flex: style.flex,
    flexBasis: style.flexBasis,
    order: style.order,
    maxWidth: node.type === 'image' ? '100%' : undefined,
    // Width: pass through from style for non-button nodes (buttons use fit-content).
    // Naturally full-width widgets default to 100% so they don't shrink inside
    // flex parents with alignItems:'center' (e.g. section columns).
    width: node.type === 'button'
      ? 'fit-content'
      : (style.width || (
        ['slideshow', 'gallery', 'tabs', 'accordion', 'form', 'progress', 'alert', 'video', 'table', 'divider',
          'heading', 'toggle', 'posts_grid', 'products_grid', 'team_grid', 'entity_view', 'entity_list', 'logo_grid', 'call_to_action',
          'countdown', 'search_box'].includes(node.type)
          ? '100%'
          : undefined
      )),
    // Pass through text alignment to wrapper for block elements
    textAlign: style.textAlign,
    // Pass through vertical alignment (alignSelf) for flex children
    alignSelf: style.alignSelf,
    // Hover animation styles
    ...getHoverAnimationStyles(),
    ...visibilityPreviewStyle,
  };

  const entranceAnimationStyles = getEntranceAnimationStyles();
  const hasEntranceAnimation = Object.keys(entranceAnimationStyles).length > 0;

  // Drop indicator styles — prominent visual cues for drag targets
  const getDropIndicatorStyle = (): CSSProperties | null => {
    if (!dropPosition) return null;

    if (dropPosition === 'before') {
      return {
        position: 'absolute',
        left: 0,
        right: 0,
        top: '-2px',
        height: '3px',
        backgroundColor: '#0078d4',
        borderRadius: '2px',
        pointerEvents: 'none',
        zIndex: 100,
        boxShadow: '0 0 6px rgba(0,120,212,0.5)',
      };
    } else if (dropPosition === 'after') {
      return {
        position: 'absolute',
        left: 0,
        right: 0,
        bottom: '-2px',
        height: '3px',
        backgroundColor: '#0078d4',
        borderRadius: '2px',
        pointerEvents: 'none',
        zIndex: 100,
        boxShadow: '0 0 6px rgba(0,120,212,0.5)',
      };
    } else if (dropPosition === 'inside') {
      return {
        position: 'absolute',
        inset: 0,
        border: '2px dashed #0078d4',
        backgroundColor: 'rgba(0, 120, 212, 0.08)',
        borderRadius: '4px',
        pointerEvents: 'none',
        zIndex: 100,
      };
    }
    return null;
  };

  // Circle marker for before/after drop position
  const getDropCircleStyle = (): CSSProperties | null => {
    if (!dropPosition || dropPosition === 'inside') return null;
    return {
      position: 'absolute',
      left: '-4px',
      width: '9px',
      height: '9px',
      borderRadius: '50%',
      backgroundColor: '#0078d4',
      border: '2px solid #fff',
      pointerEvents: 'none',
      zIndex: 101,
      ...(dropPosition === 'before' ? { top: '-5px' } : { bottom: '-5px' }),
    };
  };

  const dropIndicatorStyle = getDropIndicatorStyle();

  // Render children recursively
  const renderChildren = () => {
    if (node.children.length === 0) return null;
    return node.children.map((child, index) => (
      <NodeRenderer
        key={child.id}
        node={child}
        viewport={viewport}
        isSelected={selectedIds.includes(child.id)}
        isHovered={false}
        isParentOfSelected={false}
        structureMode={structureMode}
        onSelect={onSelect}
        onHover={onHover}
        onContentChange={onContentChange}
        onPropsChange={onPropsChange}
        onMoveNode={onMoveNode}
        onStyleChange={onStyleChange}
        selectedIds={selectedIds}
        parentId={node.id}
        indexInParent={index}
      />
    ));
  };

  // Render based on node type
  const renderContent = () => {
    switch (node.type) {
      case 'document':
        return <DocumentRenderer node={node} style={style}>{renderChildren()}</DocumentRenderer>;
      case 'section':
        return <SectionRenderer node={node} style={style}>{renderChildren()}</SectionRenderer>;
      case 'container':
        return <ContainerRenderer node={node} style={style}>{renderChildren()}</ContainerRenderer>;
      case 'row':
        return <RowRenderer node={node} style={style}>{renderChildren()}</RowRenderer>;
      case 'column':
        return <ColumnRenderer node={node} style={style}>{renderChildren()}</ColumnRenderer>;
      case 'heading':
        return <HeadingRenderer node={node} style={style} isEditing={isEditing} onStartEdit={handleStartEdit} onEndEdit={handleEndEdit} />;
      case 'text':
        return <TextRenderer node={node} style={style} isEditing={isEditing} onStartEdit={handleStartEdit} onEndEdit={handleEndEdit} />;
      case 'image':
        return <ImageRenderer node={node} style={style} onOpenMediaLibrary={() => setShowMediaLibrary(true)} />;
      case 'button':
        return <ButtonRenderer node={node} style={style} isEditing={isEditing} onStartEdit={handleStartEdit} onEndEdit={handleEndEdit} />;
      case 'spacer':
        return <SpacerRenderer node={node} style={style} />;
      case 'divider':
        return <DividerRenderer node={node} style={style} />;
      case 'video':
        return <VideoRenderer node={node} style={style} />;
      case 'icon':
        return <IconRenderer node={node} style={style} />;
      case 'icon_box':
        return <IconBoxRenderer node={node} style={style} />;
      case 'tabs':
        return <TabsRenderer node={node} style={style} />;
      case 'accordion':
        return <AccordionRenderer node={node} style={style} />;
      case 'social_icons':
        return <SocialIconsRenderer node={node} style={style} />;
      case 'list':
        return <ListRenderer node={node} style={style} />;
      case 'counter':
        return <CounterRenderer node={node} style={style} />;
      case 'progress':
        return <ProgressRenderer node={node} style={style} />;
      case 'testimonial':
        return <TestimonialRenderer node={node} style={style} />;
      case 'slideshow':
        return <SlideshowRenderer node={node} style={style} />;
      case 'form':
        return <FormRenderer node={node} style={style} />;
      case 'gallery':
        return <GalleryRenderer node={node} style={style} viewport={viewport} />;
      case 'map':
        return <MapRenderer node={node} style={style} />;
      case 'table':
        return <TableRenderer node={node} style={style} />;
      case 'alert':
        return <AlertRenderer node={node} style={style} />;
      case 'anchor':
        return <AnchorRenderer node={node} style={style} />;
      case 'posts_grid':
        return <PostsGridRenderer node={node} style={style} viewport={viewport} />;
      case 'products_grid':
        return <ProductsGridRenderer node={node} style={style} viewport={viewport} />;
      case 'team_grid':
        return <TeamGridRenderer node={node} style={style} viewport={viewport} />;
      case 'entity_view':
        return <EntityViewRenderer node={node} style={style} />;
      case 'entity_list':
        return <EntityListRenderer node={node} style={style} viewport={viewport} />;
      // New components (Jan 2026) - Elementor-level
      case 'pricing_table':
        return <PricingTableRenderer node={node} style={style} />;
      case 'countdown':
        return <CountdownRenderer node={node} style={style} viewport={viewport} />;
      case 'star_rating':
        return <StarRatingRenderer node={node} style={style} />;
      case 'call_to_action':
        return <CallToActionRenderer node={node} style={style} viewport={viewport} />;
      case 'flip_box':
        return <FlipBoxRenderer node={node} style={style} viewport={viewport} />;
      case 'image_box':
        return <ImageBoxRenderer node={node} style={style} />;
      case 'logo_grid':
        return <LogoGridRenderer node={node} style={style} viewport={viewport} />;
      case 'blockquote':
        return <BlockquoteRenderer node={node} style={style} />;
      case 'toggle':
        return <ToggleRenderer node={node} style={style} />;
      case 'search_box':
        return <SearchBoxRenderer node={node} style={style} />;
      case 'breadcrumbs':
        return <BreadcrumbsRenderer node={node} style={style} />;
      case 'code_block':
        return <CodeBlockRenderer node={node} style={style} />;
      default:
        return <div style={style}>{renderChildren()}</div>;
    }
  };

  // Handle keyboard navigation
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (isEditing) return;

    switch (e.key) {
      case 'Enter':
        e.preventDefault();
        // Start editing if editable
        if (['heading', 'text', 'button'].includes(node.type)) {
          setIsEditing(true);
        }
        break;
      case 'Delete':
      case 'Backspace':
        e.preventDefault();
        // Delete is handled at PageBuilder level
        break;
      case 'Escape':
        e.preventDefault();
        onSelect('', false); // Deselect
        break;
    }
  }, [isEditing, node.type, onSelect]);

  return (
    <div
      ref={nodeRef}
      {...customAttributes}
      id={customId}
      className={[customClasses, visibilityClassName].filter(Boolean).join(' ') || undefined}
      style={wrapperStyle}
      onClick={handleClick}
      onMouseEnter={handleMouseEnter}
      onMouseLeave={handleMouseLeave}
      onKeyDown={handleKeyDown}
      draggable={!isEditing && !isResizing}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
      onDragOver={handleDragOver}
      onDragLeave={handleDragLeave}
      onDrop={handleDrop}
      data-node-id={node.id}
      data-node-type={node.type}
      tabIndex={0}
      role="treeitem"
      aria-selected={isSelected}
      aria-label={`${node.type} element${node.meta?.name ? `: ${node.meta.name}` : ''}`}
    >
      {/* Element Label Bar - shows type and drag handle */}
      {showLabelBar && (
        <ElementLabelBar
          nodeType={node.type}
          nodeName={node.meta?.name}
          isSelected={isSelected}
          isHovered={isHovered}
        />
      )}
      {dropIndicatorStyle && (
        <>
          <div style={dropIndicatorStyle} />
          {getDropCircleStyle() && <div style={getDropCircleStyle()!} />}
        </>
      )}
      {hasEntranceAnimation ? (
        <div style={{ display: 'contents', ...entranceAnimationStyles }}>
          {renderContent()}
        </div>
      ) : renderContent()}
      {/* Resize handles - show only when selected and resizable */}
      {isSelected && isResizable && onStyleChange && (
        <ResizeHandles
          onResize={handleResize}
          onResizeEnd={handleResizeEnd}
          resizable={{ horizontal: resizeConfig.horizontal, vertical: resizeConfig.vertical }}
          nodeRef={nodeRef}
          lockAspectRatio={node.type === 'image'}
        />
      )}

      {/* Quick Width Presets Toolbar - show for containers/columns when selected */}
      {isSelected && ['container', 'column'].includes(node.type) && onStyleChange && (
        <QuickWidthToolbar
          currentWidth={node.style.width || node.style.flex}
          onWidthChange={(width) => onStyleChange(node.id, { width, flex: undefined })}
          onFlexChange={(flex) => onStyleChange(node.id, { flex, width: undefined })}
        />
      )}

      {/* Media Library Modal for image selection */}
      {showMediaLibrary && (
        <MediaLibrary
          isOpen={showMediaLibrary}
          onClose={() => setShowMediaLibrary(false)}
          onSelect={(url, alt) => {
            if (onPropsChange) {
              // Update the image src and alt via props
              onPropsChange(node.id, { src: url, alt: alt || '' });
            }
            setShowMediaLibrary(false);
          }}
        />
      )}
    </div>
  );
});

NodeRenderer.displayName = 'NodeRenderer';

export default NodeRenderer;
