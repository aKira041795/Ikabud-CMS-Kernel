/**
 * Ikabud Page Builder - Core Type Definitions
 * DiSyL Node structure and component types
 * 
 * Shared between CMS Page Builder and Admin Theme Builder
 */

// =============================================================================
// DiSyL Node - Core data structure for all builder components
// =============================================================================

export interface DiSyLNode {
  id: string;
  type: ComponentType;
  props: NodeProps;
  style: NodeStyle;
  children: DiSyLNode[];
  meta: NodeMeta;
}

// =============================================================================
// Component Types
// =============================================================================

// CMS Page Builder components (content-focused)
export type CMSComponentType =
  | 'document'  // Root wrapper that contains sections
  | 'section'
  | 'container'
  | 'layout_container'
  | 'row'
  | 'column'
  | 'heading'
  | 'text'
  | 'image'
  | 'button'
  | 'spacer'
  | 'divider'
  | 'video'
  | 'icon'
  | 'icon_box'
  | 'tabs'
  | 'accordion'
  | 'social_icons'
  | 'list'
  | 'counter'
  | 'progress'
  | 'testimonial'
  | 'slideshow'
  // New components (Dec 2025)
  | 'form'
  | 'gallery'
  | 'map'
  | 'table'
  | 'alert'
  | 'anchor'
  | 'posts_grid'
  | 'products_grid'
  | 'team_grid'
  | 'entity_view'
  | 'entity_list'
  // New components (Jan 2026) - Elementor-level
  | 'pricing_table'
  | 'countdown'
  | 'star_rating'
  | 'call_to_action'
  | 'flip_box'
  | 'image_box'
  | 'logo_grid'
  | 'blockquote'
  | 'toggle'
  | 'search_box'
  | 'nav_menu'
  | 'recent_posts'
  | 'social_links'
  | 'contact_info'
  | 'categories'
  | 'tag_cloud'
  | 'archives'
  | 'opening_hours'
  | 'breadcrumbs'
  | 'code_block'
  | 'badge'
  | 'stat_card'
  | 'contact_card'
  | 'audio'
  | 'html_embed'
  | 'ai_block';

// Admin Theme Builder additional components (template-focused)
export type ThemeComponentType =
  | CMSComponentType
  | 'for'
  | 'if'
  | 'query'
  | 'menu'
  | 'widget'
  | 'sidebar'
  | 'include'
  | 'partial';

// Combined type
export type ComponentType = CMSComponentType | ThemeComponentType;

// =============================================================================
// Component Properties
// =============================================================================

export interface NodeProps {
  // Text content
  content?: string;

  // Media
  src?: string;
  alt?: string;

  // Links
  href?: string;
  target?: '_self' | '_blank';

  // Heading level
  level?: 1 | 2 | 3 | 4 | 5 | 6;

  // Button
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost';
  size?: 'sm' | 'md' | 'lg' | string;

  // Spacer/Divider
  height?: string;

  // Video
  autoplay?: boolean;
  loop?: boolean;
  muted?: boolean;
  controls?: boolean;

  // Icon
  icon?: string;

  // Icon Box
  title?: string;
  description?: string;

  // Tabs
  tabs?: Array<{ id: string; label: string; content: string }>;
  activeTab?: string;

  // Accordion / Generic items (flexible for various components)
  items?: string | string[] | Array<{ id?: string; title?: string; content?: string; isOpen?: boolean; label?: string; url?: string; text?: string; included?: boolean }>;
  allowMultiple?: boolean;

  // Form
  formType?: 'contact' | 'newsletter' | 'custom';
  fields?: Array<{ id: string; type: string; label: string; placeholder?: string; required?: boolean }>;
  submitText?: string;
  successMessage?: string;

  // Gallery
  images?: Array<{ id: string; src: string; alt?: string; caption?: string }>;
  columns?: number;
  lightbox?: boolean;

  // Map
  mapType?: 'google' | 'openstreetmap' | 'embed';
  embedUrl?: string;
  latitude?: string;
  longitude?: string;
  zoom?: number;
  markerTitle?: string;

  // Table
  headers?: string[];
  rows?: string[][];
  striped?: boolean;
  bordered?: boolean;

  // Alert
  alertType?: 'info' | 'success' | 'warning' | 'error';
  dismissible?: boolean;

  // Anchor
  anchorId?: string;

  // Posts Grid
  postCount?: number;
  itemCount?: number;
  categoryIds?: number[];
  departmentIds?: number[];
  showDate?: boolean;
  showExcerpt?: boolean;
  excerptLength?: number;
  showFeaturedImage?: boolean;
  showAuthor?: boolean;
  showReadMore?: boolean;
  readMoreText?: string;
  gridColumns?: number;
  postType?: 'post' | 'page';
  teamType?: string;
  orderBy?: 'date' | 'title' | 'name' | 'price' | 'role' | 'random' | 'count' | 'date_desc' | 'date_asc';
  order?: 'desc' | 'asc';

  // Entity view/list
  entityType?: string;
  showTitle?: boolean;
  showMeta?: boolean;
  showTypeLabel?: boolean;
  showPricing?: boolean;
  showInventory?: boolean;
  showSku?: boolean;
  showProgress?: boolean;
  showLessons?: boolean;
  showActions?: boolean;
  showBody?: boolean;
  emptyMessage?: string;
  layout?: 'grid' | 'list' | 'horizontal' | 'vertical' | 'split';

  // Social Icons
  icons?: Array<{ platform: string; url: string }>;
  style?: string;

  // Widget-style content blocks
  menuId?: number;
  count?: number;
  showCount?: boolean;
  displayStyle?: string;
  showIcon?: boolean;
  text?: string;
  url?: string;
  newTab?: boolean;

  // List
  listType?: 'bullet' | 'number' | 'check';

  // Template logic (Theme Builder only)
  expression?: string;
  condition?: string;

  // Generic extensibility
  [key: string]: unknown;
}

// =============================================================================
// Style Properties
// =============================================================================

export interface NodeStyle {
  // Layout
  display?: 'flex' | 'block' | 'grid' | 'inline' | 'inline-flex' | 'none';
  flexDirection?: 'row' | 'column' | 'row-reverse' | 'column-reverse';
  justifyContent?: 'flex-start' | 'flex-end' | 'center' | 'space-between' | 'space-around' | 'space-evenly';
  alignItems?: 'flex-start' | 'flex-end' | 'center' | 'stretch' | 'baseline';
  flexWrap?: 'nowrap' | 'wrap' | 'wrap-reverse';
  flex?: string;
  gap?: string;

  // Self Alignment (for flex children)
  alignSelf?: 'auto' | 'flex-start' | 'flex-end' | 'center' | 'stretch' | 'baseline';
  flexGrow?: string;
  flexShrink?: string;
  flexBasis?: string;
  order?: string;

  // Grid
  gridTemplateColumns?: string;
  gridTemplateRows?: string;

  // Spacing
  padding?: string;
  paddingTop?: string;
  paddingRight?: string;
  paddingBottom?: string;
  paddingLeft?: string;
  margin?: string;
  marginTop?: string;
  marginRight?: string;
  marginBottom?: string;
  marginLeft?: string;

  // Sizing
  width?: string;
  height?: string;
  minWidth?: string;
  minHeight?: string;
  maxWidth?: string;
  maxHeight?: string;
  boxSizing?: 'border-box' | 'content-box';

  // Typography
  fontSize?: string;
  fontWeight?: string;
  fontFamily?: string;
  lineHeight?: string;
  letterSpacing?: string;
  textAlign?: 'left' | 'center' | 'right' | 'justify';
  textDecoration?: string;
  textTransform?: 'none' | 'uppercase' | 'lowercase' | 'capitalize';
  fontStyle?: string;
  color?: string;

  // Background
  backgroundColor?: string;
  backgroundImage?: string;
  backgroundSize?: string;
  backgroundPosition?: string;
  backgroundRepeat?: string;

  // Border
  border?: string;
  borderWidth?: string;
  borderStyle?: string;
  borderColor?: string;
  borderRadius?: string;
  borderTop?: string;
  borderRight?: string;
  borderBottom?: string;
  borderLeft?: string;

  // Effects
  boxShadow?: string;
  opacity?: string;
  overflow?: 'visible' | 'hidden' | 'scroll' | 'auto';
  visibility?: 'visible' | 'hidden' | 'collapse';

  // Object (for images)
  objectFit?: 'cover' | 'contain' | 'fill' | 'none' | 'scale-down';
  objectPosition?: string;

  // Position
  position?: 'static' | 'relative' | 'absolute' | 'fixed' | 'sticky';
  top?: string;
  right?: string;
  bottom?: string;
  left?: string;
  zIndex?: string;

  // Responsive overrides
  tablet?: Partial<Omit<NodeStyle, 'tablet' | 'mobile'>>;
  mobile?: Partial<Omit<NodeStyle, 'tablet' | 'mobile'>>;
}

// =============================================================================
// Node Metadata
// =============================================================================

export interface NodeMeta {
  locked?: boolean;
  hidden?: boolean;
  name?: string;
  notes?: string;
  collapsed?: boolean;
}

// =============================================================================
// Component Definition (for component panel)
// =============================================================================

export interface ComponentDefinition {
  type: ComponentType;
  name: string;
  icon: string; // Lucide icon name
  category: 'layout' | 'content' | 'media' | 'utility' | 'interactive' | 'advanced';
  description: string;
  keywords?: string[];
  defaultProps: Partial<NodeProps>;
  defaultStyle: Partial<NodeStyle>;
  defaultChildren?: DiSyLNode[];
  allowedChildren?: ComponentType[] | null; // null = any, [] = none (leaf)
  allowedParents?: ComponentType[] | null; // null = any
  isLeaf: boolean;
}

// =============================================================================
// Builder State
// =============================================================================

export interface BuilderState {
  // Document
  document: DiSyLNode;
  isDirty: boolean;

  // Selection
  selectedIds: string[];
  hoveredId: string | null;

  // UI State
  activeTool: 'select' | 'text';
  sidebarTab: 'components' | 'layers' | 'settings';
  zoom: number;
  viewport: 'desktop' | 'tablet' | 'mobile';

  // Clipboard
  clipboard: DiSyLNode[] | null;
}

// =============================================================================
// Builder Actions
// =============================================================================

export type BuilderAction =
  | { type: 'SELECT_NODE'; nodeId: string; addToSelection?: boolean }
  | { type: 'DESELECT_ALL' }
  | { type: 'HOVER_NODE'; nodeId: string | null }
  | { type: 'INSERT_NODE'; node: DiSyLNode; parentId: string; index: number }
  | { type: 'DELETE_NODE'; nodeId: string }
  | { type: 'MOVE_NODE'; nodeId: string; newParentId: string; newIndex: number }
  | { type: 'MOVE_NODE_DIRECTION'; nodeId: string; direction: 'up' | 'down' }
  | { type: 'UPDATE_PROPS'; nodeId: string; props: Partial<NodeProps> }
  | { type: 'UPDATE_STYLE'; nodeId: string; style: Partial<NodeStyle> }
  | { type: 'UPDATE_META'; nodeId: string; meta: Partial<NodeMeta> }
  | { type: 'DUPLICATE_NODE'; nodeId: string }
  | { type: 'COPY_NODES'; nodeIds: string[] }
  | { type: 'PASTE_NODES'; parentId: string; index: number }
  | { type: 'SET_DOCUMENT'; document: DiSyLNode }
  | { type: 'SET_VIEWPORT'; viewport: 'desktop' | 'tablet' | 'mobile' }
  | { type: 'SET_ZOOM'; zoom: number }
  | { type: 'SET_SIDEBAR_TAB'; tab: 'components' | 'layers' | 'settings' }
  | { type: 'MARK_CLEAN' }
  | { type: 'UNDO' }
  | { type: 'REDO' };

// =============================================================================
// Undo/Redo Operation
// =============================================================================

export interface Operation {
  type: string;
  timestamp: number;
  data: unknown;
  inverse: unknown;
}

// =============================================================================
// Layout Constraints (Senior-Grade Architecture)
// =============================================================================

export const LAYOUT_CONSTRAINTS = {
  /** Maximum nesting depth for containers (prevents layout complexity) */
  MAX_NESTING_DEPTH: 4,

  /** Maximum columns in a single row/grid (prevents mobile chaos) */
  MAX_COLUMNS: 6,

  /** Default gap for flex/grid layouts */
  DEFAULT_GAP: '24px',

  /** Minimum section height to prevent collapse */
  MIN_SECTION_HEIGHT: '100px',

  /** Default container max-width */
  DEFAULT_MAX_WIDTH: '1200px',
} as const;

/**
 * Default mobile collapse behavior
 * Horizontal layouts automatically become vertical on mobile
 */
export const DEFAULT_MOBILE_COLLAPSE: Partial<NodeStyle> = {
  flexDirection: 'column',
  gap: '16px',
};

/**
 * Default template dimensions to ensure templates always work
 */
export const TEMPLATE_DEFAULTS = {
  section: {
    width: '100%',
    minHeight: '200px',
    padding: '48px 24px',
  },
  container: {
    width: '100%',
    maxWidth: '1200px',
    minHeight: '100px',
    padding: '24px',
  },
  image: {
    width: '100%',
    height: 'auto',
    minHeight: '200px',
    objectFit: 'cover' as const,
  },
  button: {
    minWidth: '120px',
    padding: '12px 24px',
  },
} as const;

// =============================================================================
// Utility Types
// =============================================================================

export type DeepPartial<T> = {
  [P in keyof T]?: T[P] extends object ? DeepPartial<T[P]> : T[P];
};

// =============================================================================
// Helper Functions
// =============================================================================

export function generateId(): string {
  return `node_${Date.now().toString(36)}_${Math.random().toString(36).substr(2, 9)}`;
}

export function createNode(
  type: ComponentType,
  props: Partial<NodeProps> = {},
  style: Partial<NodeStyle> = {},
  children: DiSyLNode[] = []
): DiSyLNode {
  return {
    id: generateId(),
    type,
    props,
    style,
    children,
    meta: {},
  };
}

export function createEmptyDocument(): DiSyLNode {
  // Document is a wrapper that contains sections
  // Start with one empty section inside
  return createNode('document', {}, {}, [
    createNode('section', {}, {
      padding: '48px 24px',
      minHeight: '200px',
    })
  ]);
}
