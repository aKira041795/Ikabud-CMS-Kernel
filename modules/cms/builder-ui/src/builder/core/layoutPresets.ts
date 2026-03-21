/**
 * Ikabud Page Builder - Layout Presets
 * Pre-defined layout configurations for quick container setup
 * 
 * Architecture: Sections + Flexbox (engine) + Columns (presets)
 * - Basic presets: For most users (flex-based, predictable)
 * - Advanced presets: For experienced users (grid-based, more control)
 * - Custom: Full control for power users
 * 
 * All values reference LAYOUT_CONSTRAINTS and PRESET_DEFAULTS for consistency
 */

import { DiSyLNode, createNode, LAYOUT_CONSTRAINTS, DEFAULT_MOBILE_COLLAPSE, TEMPLATE_DEFAULTS } from './types';

// =============================================================================
// Preset Defaults (Configurable - no hardcoded values in presets)
// =============================================================================

export const PRESET_DEFAULTS = {
  /** Default gap between items - references LAYOUT_CONSTRAINTS */
  gap: LAYOUT_CONSTRAINTS.DEFAULT_GAP,
  
  /** Smaller gap for tighter layouts */
  gapSmall: '16px',
  
  /** Larger gap for spacious layouts */
  gapLarge: '32px',
  
  /** Container padding */
  containerPadding: TEMPLATE_DEFAULTS.container.padding,
  
  /** Minimum height for containers */
  containerMinHeight: TEMPLATE_DEFAULTS.container.minHeight,
  
  /** Placeholder child min height */
  placeholderMinHeight: '80px',
  
  /** Placeholder child padding */
  placeholderPadding: '16px',
  
  /** Placeholder background - transparent for WYSIWYG (no visual artifacts) */
  placeholderBg: 'transparent',
  
  /** Placeholder border - none for WYSIWYG (no visual artifacts) */
  placeholderBorder: 'none',
} as const;

// =============================================================================
// Types
// =============================================================================

export interface LayoutPreset {
  id: string;
  name: string;
  description: string;
  layoutMode: 'flex' | 'grid';
  icon: string; // SVG path or identifier
  settings: FlexSettings | GridSettings;
  placeholders: number; // Number of placeholder drop zones
  category: 'basic' | 'advanced' | 'custom'; // Preset tier
  mobileCollapse?: boolean; // Auto-collapse to vertical on mobile (default: true)
}

export interface FlexSettings {
  direction: 'row' | 'column';
  justifyContent?: string;
  alignItems?: string;
  gap?: string;
  wrap?: string;
}

export interface GridSettings {
  columns: string;
  rows?: string;
  gap?: string;
}

// =============================================================================
// Preset Definitions
// =============================================================================

// =============================================================================
// Basic Presets (Flex-based, predictable, for most users)
// Uses PRESET_DEFAULTS for all values - no hardcoding
// =============================================================================

export const BASIC_PRESETS: LayoutPreset[] = [
  {
    id: 'stacked',
    name: 'Stacked',
    description: 'Single column, items stacked vertically',
    layoutMode: 'flex',
    icon: 'stacked',
    category: 'basic',
    settings: { direction: 'column', gap: PRESET_DEFAULTS.gap } as FlexSettings,
    placeholders: 1,
    mobileCollapse: false, // Already vertical
  },
  {
    id: 'two-column-equal',
    name: '50/50',
    description: 'Two equal columns',
    layoutMode: 'flex',
    icon: 'two-equal',
    category: 'basic',
    settings: { direction: 'row', gap: PRESET_DEFAULTS.gap, wrap: 'wrap' } as FlexSettings,
    placeholders: 2,
    mobileCollapse: true,
  },
  {
    id: 'two-column-left',
    name: '33/67',
    description: 'Narrow left, wide right (sidebar layout)',
    layoutMode: 'flex',
    icon: 'two-left',
    category: 'basic',
    settings: { direction: 'row', gap: PRESET_DEFAULTS.gap, wrap: 'wrap' } as FlexSettings,
    placeholders: 2,
    mobileCollapse: true,
  },
  {
    id: 'two-column-right',
    name: '67/33',
    description: 'Wide left, narrow right (sidebar layout)',
    layoutMode: 'flex',
    icon: 'two-right',
    category: 'basic',
    settings: { direction: 'row', gap: PRESET_DEFAULTS.gap, wrap: 'wrap' } as FlexSettings,
    placeholders: 2,
    mobileCollapse: true,
  },
  {
    id: 'three-column',
    name: '3-Column',
    description: 'Three equal columns',
    layoutMode: 'flex',
    icon: 'three-equal',
    category: 'basic',
    settings: { direction: 'row', gap: PRESET_DEFAULTS.gap, wrap: 'wrap' } as FlexSettings,
    placeholders: 3,
    mobileCollapse: true,
  },
  {
    id: 'centered',
    name: 'Centered',
    description: 'Content centered horizontally and vertically',
    layoutMode: 'flex',
    icon: 'centered',
    category: 'basic',
    settings: { direction: 'column', alignItems: 'center', justifyContent: 'center', gap: PRESET_DEFAULTS.gapSmall } as FlexSettings,
    placeholders: 1,
    mobileCollapse: false, // Already vertical
  },
];

// =============================================================================
// Advanced Presets (Grid-based, more control, for experienced users)
// Uses PRESET_DEFAULTS for all values - no hardcoding
// =============================================================================

export const ADVANCED_PRESETS: LayoutPreset[] = [
  {
    id: 'grid-2x2',
    name: 'Grid 2×2',
    description: 'Four items in a 2x2 grid',
    layoutMode: 'grid',
    icon: 'grid-2x2',
    category: 'advanced',
    settings: { columns: 'repeat(2, 1fr)', rows: 'auto auto', gap: PRESET_DEFAULTS.gap } as GridSettings,
    placeholders: 4,
    mobileCollapse: true,
  },
  {
    id: 'four-column',
    name: '4-Column',
    description: 'Four equal columns',
    layoutMode: 'grid',
    icon: 'four-equal',
    category: 'advanced',
    settings: { columns: 'repeat(4, 1fr)', gap: PRESET_DEFAULTS.gap } as GridSettings,
    placeholders: 4,
    mobileCollapse: true,
  },
  {
    id: 'grid-3x2',
    name: 'Grid 3×2',
    description: 'Six items in a 3x2 grid',
    layoutMode: 'grid',
    icon: 'grid-3x2',
    category: 'advanced',
    settings: { columns: 'repeat(3, 1fr)', rows: 'auto auto', gap: PRESET_DEFAULTS.gap } as GridSettings,
    placeholders: 6,
    mobileCollapse: true,
  },
  {
    id: 'masonry-3',
    name: 'Card Gallery',
    description: 'Three-column card layout',
    layoutMode: 'grid',
    icon: 'masonry',
    category: 'advanced',
    settings: { columns: 'repeat(3, 1fr)', gap: PRESET_DEFAULTS.gapSmall } as GridSettings,
    placeholders: 6,
    mobileCollapse: true,
  },
];

// =============================================================================
// Custom Preset (Full control for power users)
// Uses PRESET_DEFAULTS for initial values - user can customize
// =============================================================================

export const CUSTOM_PRESET: LayoutPreset = {
  id: 'custom',
  name: 'Custom',
  description: 'Full control over layout settings',
  layoutMode: 'flex',
  icon: 'settings',
  category: 'custom',
  settings: { direction: 'row', gap: PRESET_DEFAULTS.gap } as FlexSettings,
  placeholders: 0, // User decides
  mobileCollapse: true,
};

// Combined list for backward compatibility
export const LAYOUT_PRESETS: LayoutPreset[] = [
  ...BASIC_PRESETS,
  ...ADVANCED_PRESETS,
  CUSTOM_PRESET,
];

// =============================================================================
// Preset Application
// =============================================================================

/**
 * Creates a container node with the specified preset applied
 * Includes automatic mobile collapse behavior for horizontal layouts
 * All values reference PRESET_DEFAULTS for consistency
 */
export function createContainerWithPreset(preset: LayoutPreset, customSettings?: Partial<FlexSettings | GridSettings>): DiSyLNode {
  const style: Record<string, unknown> = {
    width: '100%',
    padding: PRESET_DEFAULTS.containerPadding,
    minHeight: PRESET_DEFAULTS.containerMinHeight,
  };

  // Merge custom settings if provided (for Custom preset)
  const settings = customSettings ? { ...preset.settings, ...customSettings } : preset.settings;

  // Apply layout mode
  if (preset.layoutMode === 'flex') {
    const flexSettings = settings as FlexSettings;
    style.display = 'flex';
    style.flexDirection = flexSettings.direction;
    if (flexSettings.justifyContent) style.justifyContent = flexSettings.justifyContent;
    if (flexSettings.alignItems) style.alignItems = flexSettings.alignItems;
    style.gap = flexSettings.gap || PRESET_DEFAULTS.gap;
    if (flexSettings.wrap) style.flexWrap = flexSettings.wrap;
  } else {
    const gridSettings = settings as GridSettings;
    style.display = 'grid';
    style.gridTemplateColumns = gridSettings.columns;
    if (gridSettings.rows) style.gridTemplateRows = gridSettings.rows;
    style.gap = gridSettings.gap || PRESET_DEFAULTS.gap;
  }

  // Apply automatic mobile collapse for horizontal layouts
  if (preset.mobileCollapse !== false) {
    style.mobile = { ...DEFAULT_MOBILE_COLLAPSE };
  }

  // Create placeholder children
  const children: DiSyLNode[] = [];
  const placeholderCount = Math.min(preset.placeholders, LAYOUT_CONSTRAINTS.MAX_COLUMNS);
  
  if (placeholderCount > 1) {
    // Calculate flex basis for flex layouts
    const flexBasis = preset.layoutMode === 'flex' 
      ? getFlexBasisForPreset(preset.id, placeholderCount)
      : undefined;
    
    for (let i = 0; i < placeholderCount; i++) {
      const childStyle: Record<string, unknown> = {
        minHeight: PRESET_DEFAULTS.placeholderMinHeight,
        padding: PRESET_DEFAULTS.placeholderPadding,
        backgroundColor: PRESET_DEFAULTS.placeholderBg,
        border: PRESET_DEFAULTS.placeholderBorder,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
      };
      
      // Apply flex basis for proper column widths
      if (flexBasis) {
        childStyle.flex = flexBasis[i] || '1 1 0';
      }
      
      // Mobile: full width
      childStyle.mobile = { flex: '1 1 100%' };
      
      children.push(createNode('container', {}, childStyle));
    }
  }

  return createNode(
    'container',
    { 
      layoutMode: preset.layoutMode, 
      presetId: preset.id,
      category: preset.category,
    },
    style,
    children
  );
}

/**
 * Get flex basis values for different preset layouts
 */
function getFlexBasisForPreset(presetId: string, count: number): string[] | undefined {
  switch (presetId) {
    case 'two-column-equal':
      return ['1 1 calc(50% - 12px)', '1 1 calc(50% - 12px)'];
    case 'two-column-left':
      return ['1 1 calc(33.333% - 16px)', '2 1 calc(66.666% - 8px)'];
    case 'two-column-right':
      return ['2 1 calc(66.666% - 8px)', '1 1 calc(33.333% - 16px)'];
    case 'three-column':
      return Array(count).fill('1 1 calc(33.333% - 16px)');
    default:
      return undefined;
  }
}

/**
 * Get preset by ID
 */
export function getPresetById(id: string): LayoutPreset | undefined {
  return LAYOUT_PRESETS.find(p => p.id === id);
}

/**
 * Get presets by category
 */
export function getPresetsByCategory(category: 'basic' | 'advanced' | 'custom'): LayoutPreset[] {
  return LAYOUT_PRESETS.filter(p => p.category === category);
}

/**
 * Check if a preset is advanced (requires toggle/pro mode)
 */
export function isAdvancedPreset(presetId: string): boolean {
  const preset = getPresetById(presetId);
  return preset?.category === 'advanced' || preset?.category === 'custom';
}

/**
 * Create a custom container with user-defined settings
 * For experienced users who need full control
 * Uses PRESET_DEFAULTS as base values - user can override
 */
export function createCustomContainer(settings: {
  layoutMode: 'flex' | 'grid';
  columns?: number;
  direction?: 'row' | 'column';
  gap?: string;
  alignItems?: string;
  justifyContent?: string;
  padding?: string;
  minHeight?: string;
}): DiSyLNode {
  const { 
    layoutMode, 
    columns = 2, 
    direction = 'row', 
    gap = PRESET_DEFAULTS.gap, 
    alignItems, 
    justifyContent,
    padding = PRESET_DEFAULTS.containerPadding,
    minHeight = PRESET_DEFAULTS.containerMinHeight,
  } = settings;
  
  // Enforce constraints
  const safeColumns = Math.min(columns, LAYOUT_CONSTRAINTS.MAX_COLUMNS);
  
  const style: Record<string, unknown> = {
    width: '100%',
    padding,
    minHeight,
    gap,
  };
  
  if (layoutMode === 'flex') {
    style.display = 'flex';
    style.flexDirection = direction;
    style.flexWrap = 'wrap';
    if (alignItems) style.alignItems = alignItems;
    if (justifyContent) style.justifyContent = justifyContent;
  } else {
    style.display = 'grid';
    style.gridTemplateColumns = `repeat(${safeColumns}, 1fr)`;
  }
  
  // Auto mobile collapse for horizontal layouts
  if (direction === 'row' || layoutMode === 'grid') {
    style.mobile = { ...DEFAULT_MOBILE_COLLAPSE };
  }
  
  return createNode(
    'container',
    { layoutMode, category: 'custom', isCustom: true },
    style,
    []
  );
}
