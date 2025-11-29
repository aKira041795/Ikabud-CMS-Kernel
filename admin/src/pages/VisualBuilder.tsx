import { useState, useCallback, useEffect, useRef } from 'react'
import {
  Layers,
  Type,
  Image,
  Layout,
  Grid3X3,
  Box,
  ChevronRight,
  ChevronDown,
  Plus,
  Trash2,
  Copy,
  Eye,
  EyeOff,
  Settings2,
  Code2,
  Undo2,
  Redo2,
  Save,
  Smartphone,
  Monitor,
  Tablet,
  Sparkles,
  GripVertical,
  X,
  Search,
  LayoutGrid,
  List,
  FileText,
  Database,
  Repeat,
  GitBranch,
  FolderOpen,
  LucideIcon,
  Globe,
  FileCode,
  Package,
  Download,
  Menu,
  Navigation,
  Sliders,
  ImageIcon,
  // New icons for expanded components
  Columns,
  Minus,
  Link,
  MousePointer,
  Tag,
  Star,
  Quote,
  Video,
  ExternalLink,
  User,
  MoreHorizontal,
  Maximize2,
  MessageSquare,
  AlignLeft,
  CheckSquare,
  Circle,
  Zap,
  HelpCircle
} from 'lucide-react'

// CMS Types supported
type CMSType = 'wordpress' | 'joomla' | 'drupal' | 'native'

interface CMSConfig {
  id: CMSType
  name: string
  icon: string
  color: string
  description: string
  fileExtensions: {
    template: string
    style: string
    script: string
  }
  features: string[]
}

const CMS_CONFIGS: Record<CMSType, CMSConfig> = {
  wordpress: {
    id: 'wordpress',
    name: 'WordPress',
    icon: '🔵',
    color: 'blue',
    description: 'Create themes for WordPress CMS',
    fileExtensions: { template: 'php', style: 'css', script: 'js' },
    features: ['Customizer API', 'Widget Areas', 'Menu Locations', 'Theme Mods', 'Post Types']
  },
  joomla: {
    id: 'joomla',
    name: 'Joomla',
    icon: '🟠',
    color: 'orange',
    description: 'Create templates for Joomla CMS',
    fileExtensions: { template: 'php', style: 'css', script: 'js' },
    features: ['Module Positions', 'Template Params', 'Menu Items', 'Component Views']
  },
  drupal: {
    id: 'drupal',
    name: 'Drupal',
    icon: '🔷',
    color: 'cyan',
    description: 'Create themes for Drupal CMS',
    fileExtensions: { template: 'twig', style: 'css', script: 'js' },
    features: ['Regions', 'Block Types', 'Views', 'Twig Templates', 'Libraries']
  },
  native: {
    id: 'native',
    name: 'Native/Static',
    icon: '⚪',
    color: 'gray',
    description: 'Create standalone HTML templates',
    fileExtensions: { template: 'html', style: 'css', script: 'js' },
    features: ['Static HTML', 'No CMS Required', 'Portable']
  }
}

// Theme template types aligned with Phoenix theme
interface ThemeTemplate {
  id: string
  name: string
  icon: LucideIcon
  description: string
  required: boolean
}

const THEME_TEMPLATES: ThemeTemplate[] = [
  { id: 'home', name: 'Homepage', icon: Layout, description: 'Main landing page template', required: true },
  { id: 'single', name: 'Single Post', icon: FileText, description: 'Individual post/article template', required: true },
  { id: 'page', name: 'Page', icon: FileCode, description: 'Static page template', required: true },
  { id: 'archive', name: 'Archive', icon: Database, description: 'Post listing/archive template', required: true },
  { id: 'category', name: 'Category', icon: FolderOpen, description: 'Category archive template', required: false },
  { id: 'search', name: 'Search Results', icon: Search, description: 'Search results template', required: false },
  { id: '404', name: '404 Error', icon: X, description: 'Page not found template', required: true },
  { id: 'blog', name: 'Blog Index', icon: List, description: 'Blog listing page', required: false },
]

// Theme components (header, footer, etc.)
const THEME_COMPONENTS: ThemeTemplate[] = [
  { id: 'header', name: 'Header', icon: Navigation, description: 'Site header with logo and navigation', required: true },
  { id: 'footer', name: 'Footer', icon: Menu, description: 'Site footer with widgets and copyright', required: true },
  { id: 'sidebar', name: 'Sidebar', icon: Layout, description: 'Sidebar widget area', required: false },
  { id: 'slider', name: 'Slider', icon: ImageIcon, description: 'Image slider/carousel component', required: false },
]

// Type definitions
interface AttributeDefinition {
  type: 'string' | 'enum' | 'number' | 'boolean' | 'color' | 'expression'
  options?: string[]
  default?: string | number | boolean
  required?: boolean
  min?: number
  max?: number
}

interface ComponentDefinition {
  id: string
  name: string
  icon: LucideIcon
  description: string
  leaf: boolean
  attributes: Record<string, AttributeDefinition>
}

interface CategoryDefinition {
  label: string
  icon: LucideIcon
  color: string
  components: ComponentDefinition[]
}

// ============================================================================
// EXPRESSION SUGGESTIONS - For autocomplete in expression fields
// ============================================================================

interface ExpressionSuggestion {
  label: string
  value: string
  description: string
  category: 'variable' | 'property' | 'filter' | 'operator' | 'function'
}

// Common variables available in templates
const VARIABLE_SUGGESTIONS: ExpressionSuggestion[] = [
  // Site/Global
  { label: 'site.name', value: 'site.name', description: 'Site name', category: 'variable' },
  { label: 'site.url', value: 'site.url', description: 'Site URL', category: 'variable' },
  { label: 'site.description', value: 'site.description', description: 'Site tagline', category: 'variable' },
  { label: 'site.logo', value: 'site.logo', description: 'Site logo URL', category: 'variable' },
  
  // Current post/page
  { label: 'post.id', value: 'post.id', description: 'Post ID', category: 'variable' },
  { label: 'post.title', value: 'post.title', description: 'Post title', category: 'variable' },
  { label: 'post.content', value: 'post.content', description: 'Post content', category: 'variable' },
  { label: 'post.excerpt', value: 'post.excerpt', description: 'Post excerpt', category: 'variable' },
  { label: 'post.url', value: 'post.url', description: 'Post permalink', category: 'variable' },
  { label: 'post.date', value: 'post.date', description: 'Post date', category: 'variable' },
  { label: 'post.author', value: 'post.author', description: 'Post author name', category: 'variable' },
  { label: 'post.thumbnail', value: 'post.thumbnail', description: 'Featured image URL', category: 'variable' },
  { label: 'post.categories', value: 'post.categories', description: 'Post categories', category: 'variable' },
  { label: 'post.tags', value: 'post.tags', description: 'Post tags', category: 'variable' },
  
  // Loop item (inside for loops)
  { label: 'item.id', value: 'item.id', description: 'Current item ID', category: 'variable' },
  { label: 'item.title', value: 'item.title', description: 'Current item title', category: 'variable' },
  { label: 'item.content', value: 'item.content', description: 'Current item content', category: 'variable' },
  { label: 'item.excerpt', value: 'item.excerpt', description: 'Current item excerpt', category: 'variable' },
  { label: 'item.url', value: 'item.url', description: 'Current item URL', category: 'variable' },
  { label: 'item.thumbnail', value: 'item.thumbnail', description: 'Current item image', category: 'variable' },
  { label: 'item.date', value: 'item.date', description: 'Current item date', category: 'variable' },
  { label: 'index', value: 'index', description: 'Loop index (0-based)', category: 'variable' },
  
  // User
  { label: 'user.id', value: 'user.id', description: 'Current user ID', category: 'variable' },
  { label: 'user.name', value: 'user.name', description: 'Current user name', category: 'variable' },
  { label: 'user.email', value: 'user.email', description: 'Current user email', category: 'variable' },
  { label: 'user.role', value: 'user.role', description: 'Current user role', category: 'variable' },
  { label: 'user.logged_in', value: 'user.logged_in', description: 'Is user logged in', category: 'variable' },
  
  // Menu
  { label: 'menu.primary', value: 'menu.primary', description: 'Primary menu items', category: 'variable' },
  { label: 'menu.footer', value: 'menu.footer', description: 'Footer menu items', category: 'variable' },
  
  // Widgets
  { label: 'widgets.sidebar', value: 'widgets.sidebar', description: 'Sidebar widgets', category: 'variable' },
  { label: 'widgets.footer_1', value: 'widgets.footer_1', description: 'Footer widget area 1', category: 'variable' },
  { label: 'widgets.footer_2', value: 'widgets.footer_2', description: 'Footer widget area 2', category: 'variable' },
  
  // Theme options
  { label: 'theme.primary_color', value: 'theme.primary_color', description: 'Primary theme color', category: 'variable' },
  { label: 'theme.copyright', value: 'theme.copyright', description: 'Copyright text', category: 'variable' },
  
  // Search
  { label: 'search.query', value: 'search.query', description: 'Search query string', category: 'variable' },
  { label: 'search.results', value: 'search.results', description: 'Search results', category: 'variable' },
  
  // Pagination
  { label: 'pagination.current', value: 'pagination.current', description: 'Current page number', category: 'variable' },
  { label: 'pagination.total', value: 'pagination.total', description: 'Total pages', category: 'variable' },
  { label: 'pagination.prev_url', value: 'pagination.prev_url', description: 'Previous page URL', category: 'variable' },
  { label: 'pagination.next_url', value: 'pagination.next_url', description: 'Next page URL', category: 'variable' },
]

// Filter suggestions
const FILTER_SUGGESTIONS: ExpressionSuggestion[] = [
  // Security filters
  { label: 'esc_html', value: '| esc_html', description: 'Escape HTML entities', category: 'filter' },
  { label: 'esc_url', value: '| esc_url', description: 'Escape and validate URL', category: 'filter' },
  { label: 'esc_attr', value: '| esc_attr', description: 'Escape HTML attribute', category: 'filter' },
  { label: 'strip_tags', value: '| strip_tags', description: 'Remove HTML tags', category: 'filter' },
  
  // Text manipulation
  { label: 'upper', value: '| upper', description: 'Convert to uppercase', category: 'filter' },
  { label: 'lower', value: '| lower', description: 'Convert to lowercase', category: 'filter' },
  { label: 'capitalize', value: '| capitalize', description: 'Capitalize first letter', category: 'filter' },
  { label: 'truncate', value: '| truncate:50', description: 'Truncate to length', category: 'filter' },
  { label: 'trim', value: '| trim', description: 'Trim whitespace', category: 'filter' },
  
  // Date/Number formatting
  { label: 'date', value: '| date:format="F j, Y"', description: 'Format date', category: 'filter' },
  { label: 'number_format', value: '| number_format:2', description: 'Format number', category: 'filter' },
  
  // Logic
  { label: 'default', value: '| default:"fallback"', description: 'Default value if empty', category: 'filter' },
  { label: 'raw', value: '| raw', description: 'Output without escaping', category: 'filter' },
  { label: 'json', value: '| json', description: 'JSON encode', category: 'filter' },
  
  // WordPress-specific
  { label: 'wp_trim_words', value: '| wp_trim_words:20', description: 'Trim to word count', category: 'filter' },
  { label: 'wp_kses_post', value: '| wp_kses_post', description: 'Sanitize allowing safe HTML', category: 'filter' },
]

// Comparison operators for conditions
const OPERATOR_SUGGESTIONS: ExpressionSuggestion[] = [
  { label: '==', value: ' == ', description: 'Equal to', category: 'operator' },
  { label: '!=', value: ' != ', description: 'Not equal to', category: 'operator' },
  { label: '>', value: ' > ', description: 'Greater than', category: 'operator' },
  { label: '<', value: ' < ', description: 'Less than', category: 'operator' },
  { label: '>=', value: ' >= ', description: 'Greater or equal', category: 'operator' },
  { label: '<=', value: ' <= ', description: 'Less or equal', category: 'operator' },
  { label: '&&', value: ' && ', description: 'Logical AND', category: 'operator' },
  { label: '||', value: ' || ', description: 'Logical OR', category: 'operator' },
  { label: '!', value: '!', description: 'Logical NOT', category: 'operator' },
]

// Common condition patterns
const CONDITION_PATTERNS: ExpressionSuggestion[] = [
  { label: 'Has thumbnail', value: 'post.thumbnail', description: 'Check if post has featured image', category: 'property' },
  { label: 'User logged in', value: 'user.logged_in', description: 'Check if user is logged in', category: 'property' },
  { label: 'Is admin', value: "user.role == 'admin'", description: 'Check if user is admin', category: 'property' },
  { label: 'Has children', value: 'item.children', description: 'Check if menu item has children', category: 'property' },
  { label: 'Widget active', value: 'widgets.sidebar.active', description: 'Check if widget area is active', category: 'property' },
  { label: 'Has posts', value: 'posts', description: 'Check if posts exist', category: 'property' },
  { label: 'First item', value: 'index == 0', description: 'Check if first loop item', category: 'property' },
  { label: 'Even index', value: 'index % 2 == 0', description: 'Check if even loop index', category: 'property' },
  { label: 'Odd index', value: 'index % 2 == 1', description: 'Check if odd loop index', category: 'property' },
]


// ============================================================================
// COMPONENT LIBRARY - Complete DiSyL components from EBNF grammar v1.2.0
// ============================================================================

const COMPONENT_LIBRARY: Record<string, CategoryDefinition> = {
  layout: {
    label: 'Layout',
    icon: Layout,
    color: 'blue',
    components: [
      {
        id: 'ikb_section',
        name: 'Section',
        icon: Layers,
        description: 'Main structural container for page sections',
        leaf: false,
        attributes: {
          type: { type: 'enum', options: ['hero', 'content', 'features', 'blog', 'cta', 'slider', 'footer', 'sidebar', 'testimonials', 'pricing', 'contact', 'gallery'], default: 'content' },
          id: { type: 'string', required: false },
          class: { type: 'string', required: false },
          padding: { type: 'enum', options: ['none', 'small', 'normal', 'large', 'xlarge'], default: 'normal' },
          bg: { type: 'enum', options: ['none', 'light', 'dark', 'primary', 'gradient', 'image'], default: 'none' }
        }
      },
      {
        id: 'ikb_container',
        name: 'Container',
        icon: Box,
        description: 'Responsive container with max-width',
        leaf: false,
        attributes: {
          size: { type: 'enum', options: ['sm', 'md', 'lg', 'xl', 'xlarge', 'full'], default: 'xlarge' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_row',
        name: 'Row',
        icon: Columns,
        description: 'Flexbox row container',
        leaf: false,
        attributes: {
          gap: { type: 'enum', options: ['none', 'small', 'normal', 'large'], default: 'normal' },
          align: { type: 'enum', options: ['start', 'center', 'end', 'stretch'], default: 'stretch' },
          justify: { type: 'enum', options: ['start', 'center', 'end', 'between', 'around'], default: 'start' },
          wrap: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_col',
        name: 'Column',
        icon: Columns,
        description: 'Flexbox column',
        leaf: false,
        attributes: {
          span: { type: 'number', min: 1, max: 12, default: 6 },
          sm: { type: 'number', min: 1, max: 12, required: false },
          md: { type: 'number', min: 1, max: 12, required: false },
          lg: { type: 'number', min: 1, max: 12, required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_grid',
        name: 'Grid',
        icon: Grid3X3,
        description: 'CSS Grid layout',
        leaf: false,
        attributes: {
          cols: { type: 'number', min: 1, max: 12, default: 3 },
          gap: { type: 'enum', options: ['none', 'small', 'normal', 'large'], default: 'normal' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_block',
        name: 'Block',
        icon: Box,
        description: 'Generic content block',
        leaf: false,
        attributes: {
          class: { type: 'string', required: false },
          id: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_spacer',
        name: 'Spacer',
        icon: Minus,
        description: 'Vertical spacing element',
        leaf: true,
        attributes: {
          size: { type: 'enum', options: ['xs', 'sm', 'md', 'lg', 'xl', '2xl'], default: 'md' }
        }
      },
      {
        id: 'ikb_divider',
        name: 'Divider',
        icon: Minus,
        description: 'Horizontal divider line',
        leaf: true,
        attributes: {
          style: { type: 'enum', options: ['solid', 'dashed', 'dotted'], default: 'solid' },
          color: { type: 'color', required: false },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  content: {
    label: 'Content',
    icon: Type,
    color: 'green',
    components: [
      {
        id: 'ikb_text',
        name: 'Text',
        icon: Type,
        description: 'Text content with formatting',
        leaf: false,
        attributes: {
          tag: { type: 'enum', options: ['p', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], default: 'p' },
          size: { type: 'enum', options: ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'], default: 'base' },
          weight: { type: 'enum', options: ['light', 'normal', 'medium', 'semibold', 'bold', 'extrabold'], default: 'normal' },
          color: { type: 'color', required: false },
          align: { type: 'enum', options: ['left', 'center', 'right', 'justify'], default: 'left' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_heading',
        name: 'Heading',
        icon: Type,
        description: 'Heading element (h1-h6)',
        leaf: false,
        attributes: {
          level: { type: 'enum', options: ['1', '2', '3', '4', '5', '6'], default: '2' },
          size: { type: 'enum', options: ['sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'], default: '2xl' },
          weight: { type: 'enum', options: ['normal', 'medium', 'semibold', 'bold', 'extrabold'], default: 'bold' },
          align: { type: 'enum', options: ['left', 'center', 'right'], default: 'left' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_link',
        name: 'Link',
        icon: Link,
        description: 'Hyperlink element',
        leaf: false,
        attributes: {
          href: { type: 'string', required: true },
          target: { type: 'enum', options: ['_self', '_blank'], default: '_self' },
          rel: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_button',
        name: 'Button',
        icon: MousePointer,
        description: 'Button or call-to-action',
        leaf: false,
        attributes: {
          href: { type: 'string', required: false },
          variant: { type: 'enum', options: ['primary', 'secondary', 'outline', 'ghost', 'link'], default: 'primary' },
          size: { type: 'enum', options: ['sm', 'md', 'lg'], default: 'md' },
          icon: { type: 'string', required: false },
          target: { type: 'enum', options: ['_self', '_blank'], default: '_self' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_card',
        name: 'Card',
        icon: FileText,
        description: 'Card component for content',
        leaf: false,
        attributes: {
          variant: { type: 'enum', options: ['default', 'outlined', 'elevated', 'filled'], default: 'default' },
          hover: { type: 'boolean', default: true },
          link: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_badge',
        name: 'Badge',
        icon: Tag,
        description: 'Small badge or label',
        leaf: false,
        attributes: {
          variant: { type: 'enum', options: ['default', 'primary', 'secondary', 'success', 'warning', 'danger'], default: 'default' },
          size: { type: 'enum', options: ['sm', 'md'], default: 'sm' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_list',
        name: 'List',
        icon: List,
        description: 'Ordered or unordered list',
        leaf: false,
        attributes: {
          type: { type: 'enum', options: ['ul', 'ol'], default: 'ul' },
          style: { type: 'enum', options: ['disc', 'circle', 'square', 'decimal', 'none'], default: 'disc' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_list_item',
        name: 'List Item',
        icon: List,
        description: 'List item element',
        leaf: false,
        attributes: {
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_icon',
        name: 'Icon',
        icon: Star,
        description: 'Icon element',
        leaf: true,
        attributes: {
          name: { type: 'string', required: true },
          size: { type: 'enum', options: ['xs', 'sm', 'md', 'lg', 'xl'], default: 'md' },
          color: { type: 'color', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_quote',
        name: 'Quote',
        icon: Quote,
        description: 'Blockquote element',
        leaf: false,
        attributes: {
          cite: { type: 'string', required: false },
          author: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  media: {
    label: 'Media',
    icon: Image,
    color: 'purple',
    components: [
      {
        id: 'ikb_image',
        name: 'Image',
        icon: Image,
        description: 'Responsive image with optimization',
        leaf: true,
        attributes: {
          src: { type: 'string', required: true },
          alt: { type: 'string', required: true },
          width: { type: 'number', required: false },
          height: { type: 'number', required: false },
          lazy: { type: 'boolean', default: true },
          responsive: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_video',
        name: 'Video',
        icon: Video,
        description: 'Video player element',
        leaf: true,
        attributes: {
          src: { type: 'string', required: true },
          poster: { type: 'string', required: false },
          autoplay: { type: 'boolean', default: false },
          loop: { type: 'boolean', default: false },
          muted: { type: 'boolean', default: false },
          controls: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_embed',
        name: 'Embed',
        icon: ExternalLink,
        description: 'Embed external content (YouTube, Vimeo, etc.)',
        leaf: true,
        attributes: {
          url: { type: 'string', required: true },
          type: { type: 'enum', options: ['youtube', 'vimeo', 'twitter', 'instagram', 'iframe'], default: 'iframe' },
          aspectRatio: { type: 'enum', options: ['16:9', '4:3', '1:1', '9:16'], default: '16:9' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_gallery',
        name: 'Gallery',
        icon: Grid3X3,
        description: 'Image gallery grid',
        leaf: false,
        attributes: {
          cols: { type: 'number', min: 1, max: 6, default: 3 },
          gap: { type: 'enum', options: ['none', 'small', 'normal', 'large'], default: 'normal' },
          lightbox: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_slider',
        name: 'Slider',
        icon: Layers,
        description: 'Image/content slider carousel',
        leaf: false,
        attributes: {
          autoplay: { type: 'boolean', default: false },
          interval: { type: 'number', min: 1000, max: 10000, default: 5000 },
          arrows: { type: 'boolean', default: true },
          dots: { type: 'boolean', default: true },
          loop: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_avatar',
        name: 'Avatar',
        icon: User,
        description: 'User avatar image',
        leaf: true,
        attributes: {
          src: { type: 'string', required: false },
          alt: { type: 'string', required: false },
          size: { type: 'enum', options: ['xs', 'sm', 'md', 'lg', 'xl'], default: 'md' },
          fallback: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  data: {
    label: 'Data',
    icon: Database,
    color: 'orange',
    components: [
      {
        id: 'ikb_query',
        name: 'Query',
        icon: Database,
        description: 'Query and loop over content items',
        leaf: false,
        attributes: {
          type: { type: 'enum', options: ['post', 'page', 'product', 'custom'], default: 'post' },
          limit: { type: 'number', min: 1, max: 100, default: 10 },
          orderby: { type: 'enum', options: ['date', 'title', 'modified', 'random', 'menu_order'], default: 'date' },
          order: { type: 'enum', options: ['asc', 'desc'], default: 'desc' },
          category: { type: 'string', required: false },
          tag: { type: 'string', required: false },
          taxonomy: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_menu',
        name: 'Menu',
        icon: Menu,
        description: 'Navigation menu',
        leaf: false,
        attributes: {
          location: { type: 'enum', options: ['primary', 'footer', 'social', 'mobile'], default: 'primary' },
          depth: { type: 'number', min: 1, max: 5, default: 2 },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_widget',
        name: 'Widget Area',
        icon: Layout,
        description: 'Widget/sidebar area',
        leaf: true,
        attributes: {
          area: { type: 'enum', options: ['sidebar', 'footer-1', 'footer-2', 'footer-3', 'footer-4'], default: 'sidebar' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_breadcrumb',
        name: 'Breadcrumb',
        icon: ChevronRight,
        description: 'Breadcrumb navigation',
        leaf: true,
        attributes: {
          separator: { type: 'string', default: '/' },
          showHome: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_pagination',
        name: 'Pagination',
        icon: MoreHorizontal,
        description: 'Page navigation',
        leaf: true,
        attributes: {
          type: { type: 'enum', options: ['numbers', 'prev-next', 'load-more'], default: 'numbers' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_search',
        name: 'Search Form',
        icon: Search,
        description: 'Search input form',
        leaf: true,
        attributes: {
          placeholder: { type: 'string', default: 'Search...' },
          button: { type: 'boolean', default: true },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  interactive: {
    label: 'Interactive',
    icon: MousePointer,
    color: 'cyan',
    components: [
      {
        id: 'ikb_accordion',
        name: 'Accordion',
        icon: ChevronDown,
        description: 'Collapsible accordion container',
        leaf: false,
        attributes: {
          multiple: { type: 'boolean', default: false },
          defaultOpen: { type: 'number', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_accordion_item',
        name: 'Accordion Item',
        icon: ChevronDown,
        description: 'Single accordion panel',
        leaf: false,
        attributes: {
          title: { type: 'string', required: true },
          open: { type: 'boolean', default: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_tabs',
        name: 'Tabs',
        icon: Layers,
        description: 'Tabbed content container',
        leaf: false,
        attributes: {
          defaultTab: { type: 'number', default: 0 },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_tab',
        name: 'Tab Panel',
        icon: Layers,
        description: 'Single tab panel',
        leaf: false,
        attributes: {
          title: { type: 'string', required: true },
          icon: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_modal',
        name: 'Modal',
        icon: Maximize2,
        description: 'Modal dialog',
        leaf: false,
        attributes: {
          id: { type: 'string', required: true },
          title: { type: 'string', required: false },
          size: { type: 'enum', options: ['sm', 'md', 'lg', 'xl', 'full'], default: 'md' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_tooltip',
        name: 'Tooltip',
        icon: MessageSquare,
        description: 'Tooltip on hover',
        leaf: false,
        attributes: {
          content: { type: 'string', required: true },
          position: { type: 'enum', options: ['top', 'bottom', 'left', 'right'], default: 'top' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_dropdown',
        name: 'Dropdown',
        icon: ChevronDown,
        description: 'Dropdown menu',
        leaf: false,
        attributes: {
          trigger: { type: 'enum', options: ['click', 'hover'], default: 'click' },
          align: { type: 'enum', options: ['left', 'right'], default: 'left' },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  form: {
    label: 'Form',
    icon: FileText,
    color: 'pink',
    components: [
      {
        id: 'ikb_form',
        name: 'Form',
        icon: FileText,
        description: 'Form container',
        leaf: false,
        attributes: {
          action: { type: 'string', required: false },
          method: { type: 'enum', options: ['get', 'post'], default: 'post' },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_input',
        name: 'Input',
        icon: Type,
        description: 'Text input field',
        leaf: true,
        attributes: {
          type: { type: 'enum', options: ['text', 'email', 'password', 'number', 'tel', 'url', 'search'], default: 'text' },
          name: { type: 'string', required: true },
          placeholder: { type: 'string', required: false },
          required: { type: 'boolean', default: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_textarea',
        name: 'Textarea',
        icon: AlignLeft,
        description: 'Multi-line text input',
        leaf: true,
        attributes: {
          name: { type: 'string', required: true },
          placeholder: { type: 'string', required: false },
          rows: { type: 'number', min: 2, max: 20, default: 4 },
          required: { type: 'boolean', default: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_select',
        name: 'Select',
        icon: ChevronDown,
        description: 'Dropdown select',
        leaf: false,
        attributes: {
          name: { type: 'string', required: true },
          placeholder: { type: 'string', required: false },
          required: { type: 'boolean', default: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_checkbox',
        name: 'Checkbox',
        icon: CheckSquare,
        description: 'Checkbox input',
        leaf: true,
        attributes: {
          name: { type: 'string', required: true },
          label: { type: 'string', required: false },
          checked: { type: 'boolean', default: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_radio',
        name: 'Radio',
        icon: Circle,
        description: 'Radio button input',
        leaf: true,
        attributes: {
          name: { type: 'string', required: true },
          value: { type: 'string', required: true },
          label: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      },
      {
        id: 'ikb_label',
        name: 'Label',
        icon: Tag,
        description: 'Form label',
        leaf: false,
        attributes: {
          for: { type: 'string', required: false },
          class: { type: 'string', required: false }
        }
      }
    ]
  },
  control: {
    label: 'Control',
    icon: GitBranch,
    color: 'red',
    components: [
      {
        id: 'if',
        name: 'If Condition',
        icon: GitBranch,
        description: 'Conditional rendering',
        leaf: false,
        attributes: {
          condition: { type: 'expression', required: true }
        }
      },
      {
        id: 'for',
        name: 'For Loop',
        icon: Repeat,
        description: 'Loop over items',
        leaf: false,
        attributes: {
          items: { type: 'expression', required: true },
          as: { type: 'string', default: 'item' },
          key: { type: 'string', required: false }
        }
      },
      {
        id: 'switch',
        name: 'Switch',
        icon: GitBranch,
        description: 'Switch/case statement',
        leaf: false,
        attributes: {
          value: { type: 'expression', required: true }
        }
      },
      {
        id: 'include',
        name: 'Include',
        icon: FolderOpen,
        description: 'Include another template',
        leaf: true,
        attributes: {
          file: { type: 'string', required: true }
        }
      }
    ]
  },
  cms: {
    label: 'CMS',
    icon: Globe,
    color: 'indigo',
    components: [
      {
        id: 'wp:query',
        name: 'WP Query',
        icon: Database,
        description: 'WordPress WP_Query',
        leaf: false,
        attributes: {
          post_type: { type: 'string', default: 'post' },
          posts_per_page: { type: 'number', default: 10 },
          category_name: { type: 'string', required: false },
          tag: { type: 'string', required: false },
          orderby: { type: 'enum', options: ['date', 'title', 'modified', 'rand', 'menu_order'], default: 'date' },
          order: { type: 'enum', options: ['ASC', 'DESC'], default: 'DESC' }
        }
      },
      {
        id: 'wp:the_content',
        name: 'WP Content',
        icon: FileText,
        description: 'WordPress post content',
        leaf: true,
        attributes: {}
      },
      {
        id: 'wp:the_excerpt',
        name: 'WP Excerpt',
        icon: FileText,
        description: 'WordPress post excerpt',
        leaf: true,
        attributes: {
          length: { type: 'number', default: 55 }
        }
      },
      {
        id: 'wp:featured_image',
        name: 'WP Featured Image',
        icon: Image,
        description: 'WordPress featured image',
        leaf: true,
        attributes: {
          size: { type: 'enum', options: ['thumbnail', 'medium', 'large', 'full'], default: 'large' }
        }
      },
      {
        id: 'wp:comments',
        name: 'WP Comments',
        icon: MessageSquare,
        description: 'WordPress comments section',
        leaf: true,
        attributes: {}
      }
    ]
  }
}

// Types
interface ComponentNode {
  id: string
  componentId: string
  name: string
  attributes: Record<string, unknown>
  children: ComponentNode[]
  textContent?: string
  expanded?: boolean
  visible?: boolean
}

interface DragState {
  isDragging: boolean
  draggedNode: ComponentNode | null
  draggedFromLibrary: ComponentDefinition | null
  dropTarget: string | null
  dropPosition: 'before' | 'after' | 'inside' | null
}

// Generate unique ID
const generateId = () => `node_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`

// Create new node from component definition
const createNode = (component: ComponentDefinition): ComponentNode => {
  const defaultAttrs: Record<string, unknown> = {}
  Object.entries(component.attributes).forEach(([key, def]) => {
    if ('default' in def) {
      defaultAttrs[key] = def.default
    }
  })
  
  return {
    id: generateId(),
    componentId: component.id,
    name: component.name,
    attributes: defaultAttrs,
    children: [],
    expanded: true,
    visible: true
  }
}

// Generate DiSyL code from tree
const generateDisyl = (nodes: ComponentNode[], indent = 0): string => {
  const spaces = '  '.repeat(indent)
  let output = ''
  
  for (const node of nodes) {
    const attrs = Object.entries(node.attributes)
      .filter(([, v]) => v !== undefined && v !== '')
      .map(([k, v]) => {
        if (typeof v === 'boolean') return `${k}=${v}`
        if (typeof v === 'number') return `${k}=${v}`
        if (typeof v === 'string' && v.startsWith('{') && v.endsWith('}')) return `${k}=${v}`
        return `${k}="${v}"`
      })
      .join(' ')
    
    const attrStr = attrs ? ` ${attrs}` : ''
    const isLeaf = node.children.length === 0 && !node.textContent
    
    if (isLeaf && !['if', 'for', 'ikb_section', 'ikb_container', 'ikb_block', 'ikb_card', 'ikb_text', 'ikb_query'].includes(node.componentId)) {
      output += `${spaces}{${node.componentId}${attrStr} /}\n`
    } else {
      output += `${spaces}{${node.componentId}${attrStr}}\n`
      if (node.textContent) {
        output += `${spaces}  ${node.textContent}\n`
      }
      if (node.children.length > 0) {
        output += generateDisyl(node.children, indent + 1)
      }
      output += `${spaces}{/${node.componentId}}\n`
    }
  }
  
  return output
}

// Generate full DiSyL template with CMS header
const generateFullTemplate = (nodes: ComponentNode[], cms: CMSType, templateName: string): string => {
  const cmsTargets: Record<CMSType, string> = {
    wordpress: 'wordpress',
    joomla: 'joomla',
    drupal: 'drupal',
    native: 'native'
  }
  
  let output = `{!-- ${templateName.charAt(0).toUpperCase() + templateName.slice(1)} Template --}\n`
  output += `{ikb_platform type="web" targets="${cmsTargets[cms]}" /}\n`
  
  // Add include for header if not a component template
  if (!templateName.startsWith('components/')) {
    output += `{include file="components/header.disyl"}\n\n`
  }
  
  output += generateDisyl(nodes)
  
  // Add include for footer if not a component template
  if (!templateName.startsWith('components/')) {
    output += `\n{include file="components/footer.disyl"}\n`
  }
  
  return output
}

// Component Panel
function ComponentPanel({ 
  onDragStart, 
  searchTerm, 
  setSearchTerm,
  selectedCategory,
  setSelectedCategory
}: { 
  onDragStart: (component: ComponentDefinition) => void
  searchTerm: string
  setSearchTerm: (term: string) => void
  selectedCategory: string | null
  setSelectedCategory: (category: string | null) => void
}) {
  const categories = Object.entries(COMPONENT_LIBRARY)
  
  const filteredCategories = categories.map(([key, category]) => ({
    key,
    ...category,
    components: category.components.filter(c => 
      c.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      c.description.toLowerCase().includes(searchTerm.toLowerCase())
    )
  })).filter(c => c.components.length > 0)
  
  return (
    <div className="h-full flex flex-col">
      {/* Search */}
      <div className="p-3 border-b border-gray-200">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            type="text"
            placeholder="Search components..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>
      </div>
      
      {/* Category Filter */}
      <div className="p-2 border-b border-gray-200 flex flex-wrap gap-1">
        <button
          onClick={() => setSelectedCategory(null)}
          className={`px-2 py-1 text-xs rounded-full transition-colors ${
            selectedCategory === null 
              ? 'bg-gray-900 text-white' 
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          }`}
        >
          All
        </button>
        {categories.map(([key, category]) => (
          <button
            key={key}
            onClick={() => setSelectedCategory(key)}
            className={`px-2 py-1 text-xs rounded-full transition-colors ${
              selectedCategory === key 
                ? 'bg-gray-900 text-white' 
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {category.label}
          </button>
        ))}
      </div>
      
      {/* Components */}
      <div className="flex-1 overflow-y-auto p-3 space-y-4">
        {filteredCategories
          .filter(c => selectedCategory === null || c.key === selectedCategory)
          .map(category => (
          <div key={category.key}>
            <div className="flex items-center gap-2 mb-2">
              <category.icon className={`w-4 h-4 text-${category.color}-500`} />
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                {category.label}
              </span>
            </div>
            <div className="space-y-1">
              {category.components.map(component => (
                <div
                  key={component.id}
                  draggable
                  onDragStart={(e) => {
                    e.dataTransfer.effectAllowed = 'copy'
                    onDragStart(component)
                  }}
                  className="group flex items-center gap-3 p-2 rounded-lg border border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm cursor-grab active:cursor-grabbing transition-all"
                >
                  <div className={`p-1.5 rounded-md bg-${category.color}-50`}>
                    <component.icon className={`w-4 h-4 text-${category.color}-500`} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium text-gray-900">{component.name}</div>
                    <div className="text-xs text-gray-500 truncate">{component.description}</div>
                  </div>
                  <GripVertical className="w-4 h-4 text-gray-300 group-hover:text-gray-400" />
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

// Tree Node Component
function TreeNode({
  node,
  depth,
  selectedId,
  onSelect,
  onToggleExpand,
  onToggleVisibility,
  onDelete,
  onDuplicate,
  dragState,
  onDragOver,
  onDrop
}: {
  node: ComponentNode
  depth: number
  selectedId: string | null
  onSelect: (id: string) => void
  onToggleExpand: (id: string) => void
  onToggleVisibility: (id: string) => void
  onDelete: (id: string) => void
  onDuplicate: (id: string) => void
  dragState: DragState
  onDragOver: (id: string, position: 'before' | 'after' | 'inside') => void
  onDrop: () => void
}) {
  const isSelected = selectedId === node.id
  const isDropTarget = dragState.dropTarget === node.id
  const hasChildren = node.children.length > 0
  
  const componentDef = Object.values(COMPONENT_LIBRARY)
    .flatMap(c => c.components)
    .find(c => c.id === node.componentId)
  
  const Icon = componentDef?.icon || Box
  
  return (
    <div className="select-none">
      <div
        className={`
          group flex items-center gap-1 py-1 px-2 rounded-md cursor-pointer transition-all
          ${isSelected ? 'bg-blue-100 text-blue-900' : 'hover:bg-gray-100'}
          ${isDropTarget && dragState.dropPosition === 'inside' ? 'ring-2 ring-blue-500 ring-inset' : ''}
          ${!node.visible ? 'opacity-50' : ''}
        `}
        style={{ paddingLeft: `${depth * 16 + 8}px` }}
        onClick={() => onSelect(node.id)}
        onDragOver={(e) => {
          e.preventDefault()
          e.stopPropagation()
          const rect = e.currentTarget.getBoundingClientRect()
          const y = e.clientY - rect.top
          const position = y < rect.height / 3 ? 'before' : y > rect.height * 2 / 3 ? 'after' : 'inside'
          onDragOver(node.id, position)
        }}
        onDrop={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onDrop()
        }}
      >
        {/* Drop indicator - before */}
        {isDropTarget && dragState.dropPosition === 'before' && (
          <div className="absolute left-0 right-0 h-0.5 bg-blue-500 -top-0.5" />
        )}
        
        {/* Expand/Collapse */}
        <button
          onClick={(e) => {
            e.stopPropagation()
            onToggleExpand(node.id)
          }}
          className={`p-0.5 rounded hover:bg-gray-200 ${!hasChildren ? 'invisible' : ''}`}
        >
          {node.expanded ? (
            <ChevronDown className="w-3 h-3 text-gray-500" />
          ) : (
            <ChevronRight className="w-3 h-3 text-gray-500" />
          )}
        </button>
        
        {/* Icon */}
        <Icon className="w-4 h-4 text-gray-500" />
        
        {/* Name */}
        <span className="flex-1 text-sm truncate">{node.name}</span>
        
        {/* Actions */}
        <div className="hidden group-hover:flex items-center gap-0.5">
          <button
            onClick={(e) => {
              e.stopPropagation()
              onToggleVisibility(node.id)
            }}
            className="p-1 rounded hover:bg-gray-200"
            title={node.visible ? 'Hide' : 'Show'}
          >
            {node.visible ? (
              <Eye className="w-3 h-3 text-gray-400" />
            ) : (
              <EyeOff className="w-3 h-3 text-gray-400" />
            )}
          </button>
          <button
            onClick={(e) => {
              e.stopPropagation()
              onDuplicate(node.id)
            }}
            className="p-1 rounded hover:bg-gray-200"
            title="Duplicate"
          >
            <Copy className="w-3 h-3 text-gray-400" />
          </button>
          <button
            onClick={(e) => {
              e.stopPropagation()
              onDelete(node.id)
            }}
            className="p-1 rounded hover:bg-red-100"
            title="Delete"
          >
            <Trash2 className="w-3 h-3 text-red-400" />
          </button>
        </div>
        
        {/* Drop indicator - after */}
        {isDropTarget && dragState.dropPosition === 'after' && (
          <div className="absolute left-0 right-0 h-0.5 bg-blue-500 -bottom-0.5" />
        )}
      </div>
      
      {/* Children */}
      {node.expanded && hasChildren && (
        <div>
          {node.children.map(child => (
            <TreeNode
              key={child.id}
              node={child}
              depth={depth + 1}
              selectedId={selectedId}
              onSelect={onSelect}
              onToggleExpand={onToggleExpand}
              onToggleVisibility={onToggleVisibility}
              onDelete={onDelete}
              onDuplicate={onDuplicate}
              dragState={dragState}
              onDragOver={onDragOver}
              onDrop={onDrop}
            />
          ))}
        </div>
      )}
    </div>
  )
}

// Expression Editor with Autocomplete
function ExpressionEditor({
  value,
  onChange,
  placeholder = '{expression}',
  isCondition = false
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  isCondition?: boolean
}) {
  const [showSuggestions, setShowSuggestions] = useState(false)
  const [filter, setFilter] = useState('')
  const [selectedIndex, setSelectedIndex] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)
  const suggestionsRef = useRef<HTMLDivElement>(null)
  
  // Get relevant suggestions based on context
  const getSuggestions = () => {
    const searchTerm = filter.toLowerCase()
    let suggestions: ExpressionSuggestion[] = []
    
    if (isCondition) {
      // For conditions, prioritize condition patterns and operators
      suggestions = [...CONDITION_PATTERNS, ...VARIABLE_SUGGESTIONS, ...OPERATOR_SUGGESTIONS]
    } else if (value.includes('|')) {
      // After pipe, show filters
      suggestions = FILTER_SUGGESTIONS
    } else {
      // Default: show variables first
      suggestions = [...VARIABLE_SUGGESTIONS, ...FILTER_SUGGESTIONS]
    }
    
    if (searchTerm) {
      suggestions = suggestions.filter(s => 
        s.label.toLowerCase().includes(searchTerm) ||
        s.description.toLowerCase().includes(searchTerm)
      )
    }
    
    return suggestions.slice(0, 12)
  }
  
  const suggestions = getSuggestions()
  
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value
    onChange(newValue)
    
    // Extract last word for filtering
    const words = newValue.split(/[\s|{}]+/)
    setFilter(words[words.length - 1] || '')
    setShowSuggestions(true)
    setSelectedIndex(0)
  }
  
  const insertSuggestion = (suggestion: ExpressionSuggestion) => {
    let newValue = value
    
    if (suggestion.category === 'filter') {
      // Append filter
      newValue = value.trim() + ' ' + suggestion.value
    } else if (suggestion.category === 'operator') {
      // Append operator
      newValue = value.trim() + suggestion.value
    } else {
      // Replace or append variable/pattern
      const words = value.split(/[\s]+/)
      words[words.length - 1] = suggestion.value
      newValue = words.join(' ')
    }
    
    onChange(newValue)
    setShowSuggestions(false)
    inputRef.current?.focus()
  }
  
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!showSuggestions || suggestions.length === 0) return
    
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setSelectedIndex(i => Math.min(i + 1, suggestions.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setSelectedIndex(i => Math.max(i - 1, 0))
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault()
      insertSuggestion(suggestions[selectedIndex])
    } else if (e.key === 'Escape') {
      setShowSuggestions(false)
    }
  }
  
  const getCategoryColor = (category: string) => {
    switch (category) {
      case 'variable': return 'bg-blue-100 text-blue-700'
      case 'filter': return 'bg-purple-100 text-purple-700'
      case 'operator': return 'bg-orange-100 text-orange-700'
      case 'property': return 'bg-green-100 text-green-700'
      default: return 'bg-gray-100 text-gray-700'
    }
  }
  
  return (
    <div className="relative">
      <div className="flex items-center gap-1">
        <input
          ref={inputRef}
          type="text"
          value={value}
          onChange={handleInputChange}
          onFocus={() => setShowSuggestions(true)}
          onBlur={() => setTimeout(() => setShowSuggestions(false), 200)}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
        />
        <button
          type="button"
          onClick={() => setShowSuggestions(!showSuggestions)}
          className="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded"
          title="Show suggestions"
        >
          <Zap className="w-4 h-4" />
        </button>
      </div>
      
      {/* Suggestions Dropdown */}
      {showSuggestions && suggestions.length > 0 && (
        <div 
          ref={suggestionsRef}
          className="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto"
        >
          <div className="p-2 border-b border-gray-100 bg-gray-50">
            <div className="flex items-center gap-2 text-xs text-gray-500">
              <HelpCircle className="w-3 h-3" />
              <span>Use ↑↓ to navigate, Enter to select</span>
            </div>
          </div>
          {suggestions.map((suggestion, index) => (
            <button
              key={`${suggestion.category}-${suggestion.value}`}
              onClick={() => insertSuggestion(suggestion)}
              className={`w-full px-3 py-2 text-left flex items-center gap-2 hover:bg-gray-50 ${
                index === selectedIndex ? 'bg-blue-50' : ''
              }`}
            >
              <span className={`px-1.5 py-0.5 text-xs rounded ${getCategoryColor(suggestion.category)}`}>
                {suggestion.category.charAt(0).toUpperCase()}
              </span>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-mono text-gray-900 truncate">{suggestion.label}</div>
                <div className="text-xs text-gray-500 truncate">{suggestion.description}</div>
              </div>
            </button>
          ))}
        </div>
      )}
      
      {/* Quick Insert Buttons for Conditions */}
      {isCondition && (
        <div className="flex flex-wrap gap-1 mt-2">
          {CONDITION_PATTERNS.slice(0, 4).map(pattern => (
            <button
              key={pattern.value}
              type="button"
              onClick={() => onChange(pattern.value)}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition-colors"
              title={pattern.description}
            >
              {pattern.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

// Properties Panel
function PropertiesPanel({
  node,
  onUpdate
}: {
  node: ComponentNode | null
  onUpdate: (id: string, updates: Partial<ComponentNode>) => void
}) {
  if (!node) {
    return (
      <div className="h-full flex items-center justify-center text-gray-400 text-sm">
        <div className="text-center">
          <Settings2 className="w-8 h-8 mx-auto mb-2 opacity-50" />
          <p>Select a component to edit its properties</p>
        </div>
      </div>
    )
  }
  
  const componentDef = Object.values(COMPONENT_LIBRARY)
    .flatMap(c => c.components)
    .find(c => c.id === node.componentId)
  
  if (!componentDef) return null
  
  const handleAttributeChange = (key: string, value: unknown) => {
    onUpdate(node.id, {
      attributes: { ...node.attributes, [key]: value }
    })
  }
  
  // Check if this is a control structure (if, for, switch)
  const isControlStructure = ['if', 'for', 'switch'].includes(node.componentId)
  
  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="p-4 border-b border-gray-200">
        <div className="flex items-center gap-2">
          <componentDef.icon className="w-5 h-5 text-gray-500" />
          <div>
            <h3 className="font-semibold text-gray-900">{node.name}</h3>
            <p className="text-xs text-gray-500">{node.componentId}</p>
          </div>
        </div>
        {isControlStructure && (
          <div className="mt-2 px-2 py-1 bg-amber-50 border border-amber-200 rounded text-xs text-amber-700">
            💡 Use the expression editor below with autocomplete
          </div>
        )}
      </div>
      
      {/* Properties */}
      <div className="flex-1 overflow-y-auto p-4 space-y-4">
        {/* Text Content (for text-like components) */}
        {!componentDef.leaf && (
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">
              Text Content
            </label>
            <textarea
              value={node.textContent || ''}
              onChange={(e) => onUpdate(node.id, { textContent: e.target.value })}
              placeholder="Enter text content or expression like {variable}"
              className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
              rows={2}
            />
            <p className="mt-1 text-xs text-gray-400">
              Use {'{variable}'} for dynamic content, e.g., {'{post.title}'}
            </p>
          </div>
        )}
        
        {/* Attributes */}
        {Object.entries(componentDef.attributes).map(([key, def]) => (
          <div key={key}>
            <label className="block text-xs font-medium text-gray-700 mb-1">
              {key}
              {def.required && <span className="text-red-500 ml-1">*</span>}
            </label>
            
            {def.type === 'enum' && (
              <select
                value={String((node.attributes[key] as string) || def.default || '')}
                onChange={(e) => handleAttributeChange(key, e.target.value)}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                {def.options?.map(opt => (
                  <option key={opt} value={opt}>{opt}</option>
                ))}
              </select>
            )}
            
            {def.type === 'string' && (
              <input
                type="text"
                value={(node.attributes[key] as string) || ''}
                onChange={(e) => handleAttributeChange(key, e.target.value)}
                placeholder={`Enter ${key}`}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            )}
            
            {def.type === 'expression' && (
              <ExpressionEditor
                value={(node.attributes[key] as string) || ''}
                onChange={(val) => handleAttributeChange(key, val)}
                placeholder={key === 'condition' ? 'e.g., post.thumbnail' : '{expression}'}
                isCondition={key === 'condition'}
              />
            )}
            
            {def.type === 'number' && (
              <input
                type="number"
                value={Number((node.attributes[key] as number) || def.default || 0)}
                onChange={(e) => handleAttributeChange(key, parseFloat(e.target.value))}
                min={def.min}
                max={def.max}
                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            )}
            
            {def.type === 'boolean' && (
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={(node.attributes[key] as boolean) ?? def.default ?? false}
                  onChange={(e) => handleAttributeChange(key, e.target.checked)}
                  className="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <span className="text-sm text-gray-600">Enabled</span>
              </label>
            )}
            
            {def.type === 'color' && (
              <div className="flex gap-2">
                <input
                  type="color"
                  value={(node.attributes[key] as string) || '#000000'}
                  onChange={(e) => handleAttributeChange(key, e.target.value)}
                  className="w-10 h-10 rounded border border-gray-200 cursor-pointer"
                />
                <input
                  type="text"
                  value={(node.attributes[key] as string) || ''}
                  onChange={(e) => handleAttributeChange(key, e.target.value)}
                  placeholder="#000000 or color name"
                  className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            )}
          </div>
        ))}
        
        {/* Expression Reference (for control structures) */}
        {isControlStructure && (
          <div className="mt-4 p-3 bg-gray-50 rounded-lg">
            <h4 className="text-xs font-semibold text-gray-700 mb-2 flex items-center gap-1">
              <Zap className="w-3 h-3" />
              Quick Reference
            </h4>
            <div className="space-y-2 text-xs">
              <div>
                <span className="font-medium text-gray-600">Variables:</span>
                <div className="flex flex-wrap gap-1 mt-1">
                  {['post.title', 'item.url', 'user.name', 'site.name'].map(v => (
                    <code key={v} className="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded">{v}</code>
                  ))}
                </div>
              </div>
              <div>
                <span className="font-medium text-gray-600">Filters:</span>
                <div className="flex flex-wrap gap-1 mt-1">
                  {['| esc_html', '| truncate:50', '| upper', '| date'].map(f => (
                    <code key={f} className="px-1.5 py-0.5 bg-purple-100 text-purple-700 rounded">{f}</code>
                  ))}
                </div>
              </div>
              <div>
                <span className="font-medium text-gray-600">Operators:</span>
                <div className="flex flex-wrap gap-1 mt-1">
                  {['==', '!=', '&&', '||', '!'].map(o => (
                    <code key={o} className="px-1.5 py-0.5 bg-orange-100 text-orange-700 rounded">{o}</code>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

// Canvas Preview Component
function CanvasPreview({
  nodes,
  selectedId,
  onSelect,
  dragState,
  onDragOver,
  onDrop
}: {
  nodes: ComponentNode[]
  selectedId: string | null
  onSelect: (id: string) => void
  dragState: DragState
  onDragOver: (id: string, position: 'before' | 'after' | 'inside') => void
  onDrop: () => void
}) {
  const renderNode = (node: ComponentNode, depth = 0): React.ReactNode => {
    if (!node.visible) return null
    
    const isSelected = selectedId === node.id
    const isDropTarget = dragState.dropTarget === node.id
    
    const componentDef = Object.values(COMPONENT_LIBRARY)
      .flatMap(c => c.components)
      .find(c => c.id === node.componentId)
    
    const Icon = componentDef?.icon || Box
    
    // Style based on component type
    const getComponentStyle = () => {
      switch (node.componentId) {
        case 'ikb_section':
          return 'bg-blue-50 border-blue-200 min-h-[100px]'
        case 'ikb_container':
          return 'bg-indigo-50 border-indigo-200 min-h-[80px]'
        case 'ikb_block':
          return 'bg-purple-50 border-purple-200 min-h-[60px]'
        case 'ikb_card':
          return 'bg-green-50 border-green-200 min-h-[80px]'
        case 'ikb_text':
          return 'bg-gray-50 border-gray-200 min-h-[40px]'
        case 'ikb_image':
          return 'bg-pink-50 border-pink-200 min-h-[60px]'
        case 'ikb_query':
          return 'bg-orange-50 border-orange-200 min-h-[80px]'
        case 'if':
        case 'for':
          return 'bg-red-50 border-red-200 border-dashed min-h-[60px]'
        default:
          return 'bg-gray-50 border-gray-200 min-h-[40px]'
      }
    }
    
    return (
      <div
        key={node.id}
        className={`
          relative p-3 m-2 rounded-lg border-2 transition-all cursor-pointer
          ${getComponentStyle()}
          ${isSelected ? 'ring-2 ring-blue-500 ring-offset-2' : ''}
          ${isDropTarget && dragState.dropPosition === 'inside' ? 'ring-2 ring-green-500' : ''}
        `}
        onClick={(e) => {
          e.stopPropagation()
          onSelect(node.id)
        }}
        onDragOver={(e) => {
          e.preventDefault()
          e.stopPropagation()
          const rect = e.currentTarget.getBoundingClientRect()
          const y = e.clientY - rect.top
          const position = y < rect.height / 3 ? 'before' : y > rect.height * 2 / 3 ? 'after' : 'inside'
          onDragOver(node.id, position)
        }}
        onDrop={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onDrop()
        }}
      >
        {/* Drop indicator - before */}
        {isDropTarget && dragState.dropPosition === 'before' && (
          <div className="absolute left-0 right-0 h-1 bg-green-500 -top-2 rounded" />
        )}
        
        {/* Component Label */}
        <div className="flex items-center gap-2 mb-2">
          <Icon className="w-4 h-4 text-gray-500" />
          <span className="text-xs font-medium text-gray-600">{node.componentId}</span>
          {typeof node.attributes.type === 'string' && (
            <span className="text-xs text-gray-400">({String(node.attributes.type)})</span>
          )}
        </div>
        
        {/* Text Content Preview */}
        {node.textContent && (
          <div className="text-sm text-gray-700 mb-2">{node.textContent}</div>
        )}
        
        {/* Children */}
        {node.children.length > 0 && (
          <div className={`
            ${node.componentId === 'ikb_block' && node.attributes.cols && (node.attributes.cols as number) > 1
              ? `grid grid-cols-${Math.min(node.attributes.cols as number, 4)} gap-2`
              : ''
            }
          `}>
            {node.children.map(child => renderNode(child, depth + 1))}
          </div>
        )}
        
        {/* Empty State */}
        {node.children.length === 0 && !node.textContent && !componentDef?.leaf && (
          <div className="flex items-center justify-center h-12 border-2 border-dashed border-gray-300 rounded-lg text-gray-400 text-sm">
            <Plus className="w-4 h-4 mr-1" />
            Drop components here
          </div>
        )}
        
        {/* Drop indicator - after */}
        {isDropTarget && dragState.dropPosition === 'after' && (
          <div className="absolute left-0 right-0 h-1 bg-green-500 -bottom-2 rounded" />
        )}
      </div>
    )
  }
  
  return (
    <div
      className="min-h-full p-4"
      onDragOver={(e) => {
        e.preventDefault()
        if (nodes.length === 0) {
          onDragOver('root', 'inside')
        }
      }}
      onDrop={(e) => {
        e.preventDefault()
        onDrop()
      }}
    >
      {nodes.length === 0 ? (
        <div className={`
          flex flex-col items-center justify-center h-64 border-2 border-dashed rounded-xl transition-colors
          ${dragState.isDragging ? 'border-blue-400 bg-blue-50' : 'border-gray-300'}
        `}>
          <Layers className="w-12 h-12 text-gray-300 mb-4" />
          <p className="text-gray-500 text-lg font-medium mb-2">Start Building</p>
          <p className="text-gray-400 text-sm">Drag components from the left panel</p>
        </div>
      ) : (
        nodes.map(node => renderNode(node))
      )}
    </div>
  )
}

// Code Preview Panel
function CodePreview({ code }: { code: string }) {
  return (
    <div className="h-full flex flex-col">
      <div className="p-3 border-b border-gray-200 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Code2 className="w-4 h-4 text-gray-500" />
          <span className="text-sm font-medium text-gray-700">DiSyL Output</span>
        </div>
        <button
          onClick={() => navigator.clipboard.writeText(code)}
          className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors"
        >
          Copy
        </button>
      </div>
      <pre className="flex-1 overflow-auto p-4 text-sm font-mono bg-gray-900 text-gray-100">
        {code || '// No components added yet'}
      </pre>
    </div>
  )
}

// CMS Selector Component
function CMSSelector({ 
  selectedCMS, 
  onSelect,
  themeName,
  setThemeName,
  currentTemplate,
  setCurrentTemplate,
  onExport,
  isExporting
}: { 
  selectedCMS: CMSType
  onSelect: (cms: CMSType) => void
  themeName: string
  setThemeName: (name: string) => void
  currentTemplate: string
  setCurrentTemplate: (template: string) => void
  onExport: () => void
  isExporting: boolean
}) {
  const cmsOptions = Object.values(CMS_CONFIGS)
  const config = CMS_CONFIGS[selectedCMS]
  
  return (
    <div className="bg-white border-b border-gray-200 px-4 py-3">
      <div className="flex items-center gap-6">
        {/* Theme Name */}
        <div className="flex items-center gap-2">
          <Package className="w-4 h-4 text-gray-500" />
          <input
            type="text"
            value={themeName}
            onChange={(e) => setThemeName(e.target.value)}
            placeholder="Theme Name"
            className="px-2 py-1 text-sm border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 w-40"
          />
        </div>
        
        {/* CMS Selector */}
        <div className="flex items-center gap-2">
          <Globe className="w-4 h-4 text-gray-500" />
          <span className="text-sm text-gray-600">Target CMS:</span>
          <div className="flex items-center bg-gray-100 rounded-lg p-0.5">
            {cmsOptions.map((cms) => (
              <button
                key={cms.id}
                onClick={() => onSelect(cms.id)}
                className={`px-3 py-1.5 text-sm rounded-md transition-all ${
                  selectedCMS === cms.id 
                    ? 'bg-white shadow-sm font-medium' 
                    : 'text-gray-600 hover:text-gray-900'
                }`}
                title={cms.description}
              >
                <span className="mr-1">{cms.icon}</span>
                {cms.name}
              </button>
            ))}
          </div>
        </div>
        
        {/* Template Selector */}
        <div className="flex items-center gap-2">
          <FileCode className="w-4 h-4 text-gray-500" />
          <span className="text-sm text-gray-600">Template:</span>
          <select
            value={currentTemplate}
            onChange={(e) => setCurrentTemplate(e.target.value)}
            className="px-2 py-1 text-sm border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <optgroup label="Page Templates">
              {THEME_TEMPLATES.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} {t.required ? '*' : ''}
                </option>
              ))}
            </optgroup>
            <optgroup label="Components">
              {THEME_COMPONENTS.map((c) => (
                <option key={c.id} value={`components/${c.id}`}>
                  {c.name} {c.required ? '*' : ''}
                </option>
              ))}
            </optgroup>
          </select>
        </div>
        
        {/* CMS Features Badge */}
        <div className="ml-auto flex items-center gap-2">
          <Sliders className="w-4 h-4 text-gray-400" />
          <div className="flex gap-1">
            {config.features.slice(0, 3).map((feature) => (
              <span 
                key={feature}
                className="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded"
              >
                {feature}
              </span>
            ))}
            {config.features.length > 3 && (
              <span className="px-2 py-0.5 text-xs bg-gray-100 text-gray-500 rounded">
                +{config.features.length - 3}
              </span>
            )}
          </div>
        </div>
        
        {/* Export Button */}
        <button 
          onClick={onExport}
          disabled={isExporting}
          className="flex items-center gap-2 px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <Download className={`w-4 h-4 ${isExporting ? 'animate-spin' : ''}`} />
          {isExporting ? 'Exporting...' : 'Export Theme'}
        </button>
      </div>
    </div>
  )
}

// Main Visual Builder Component
export default function VisualBuilder() {
  // State
  const [nodes, setNodes] = useState<ComponentNode[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null)
  const [viewMode, setViewMode] = useState<'canvas' | 'code' | 'split'>('split')
  const [devicePreview, setDevicePreview] = useState<'desktop' | 'tablet' | 'mobile'>('desktop')
  const [showTree, setShowTree] = useState(true)
  const [history, setHistory] = useState<ComponentNode[][]>([])
  const [historyIndex, setHistoryIndex] = useState(-1)
  
  // CMS and Theme state
  const [selectedCMS, setSelectedCMS] = useState<CMSType>('wordpress')
  const [themeName, setThemeName] = useState('My Theme')
  const [currentTemplate, setCurrentTemplate] = useState('home')
  const [isExporting, setIsExporting] = useState(false)
  const [templateContents] = useState<Record<string, string>>({})
  
  // Export theme handler
  const handleExportTheme = async () => {
    setIsExporting(true)
    
    try {
      // Collect all template contents
      const templates = {
        ...templateContents,
        [currentTemplate]: generateFullTemplate(nodes, selectedCMS, currentTemplate)
      }
      
      const response = await fetch('/api/theme/generate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          cms: selectedCMS,
          themeName: themeName,
          themeSlug: themeName.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
          author: 'Ikabud Theme Builder',
          description: `A DiSyL-powered ${CMS_CONFIGS[selectedCMS].name} theme`,
          version: '1.0.0',
          templates: templates,
          options: {
            includeCustomizer: true,
            includeWidgetAreas: true,
            menuLocations: ['primary', 'footer'],
          }
        })
      })
      
      const result = await response.json()
      
      if (result.success && result.downloadUrl) {
        // Trigger download
        window.open(result.downloadUrl, '_blank')
        alert(`Theme "${themeName}" exported successfully!`)
      } else {
        alert('Export failed: ' + (result.error || 'Unknown error'))
      }
    } catch (error) {
      console.error('Export error:', error)
      alert('Export failed. Check console for details.')
    } finally {
      setIsExporting(false)
    }
  }
  
  const [dragState, setDragState] = useState<DragState>({
    isDragging: false,
    draggedNode: null,
    draggedFromLibrary: null,
    dropTarget: null,
    dropPosition: null
  })
  
  // Find node by ID
  const findNode = useCallback((id: string, nodeList: ComponentNode[] = nodes): ComponentNode | null => {
    for (const node of nodeList) {
      if (node.id === id) return node
      const found = findNode(id, node.children)
      if (found) return found
    }
    return null
  }, [nodes])
  
  // Find parent of node
  const findParent = useCallback((id: string, nodeList: ComponentNode[] = nodes, parent: ComponentNode | null = null): ComponentNode | null => {
    for (const node of nodeList) {
      if (node.id === id) return parent
      const found = findParent(id, node.children, node)
      if (found !== undefined) return found
    }
    return null
  }, [nodes])
  
  // Save to history
  const saveHistory = useCallback((newNodes: ComponentNode[]) => {
    const newHistory = history.slice(0, historyIndex + 1)
    newHistory.push(JSON.parse(JSON.stringify(newNodes)))
    setHistory(newHistory)
    setHistoryIndex(newHistory.length - 1)
  }, [history, historyIndex])
  
  // Undo
  const undo = useCallback(() => {
    if (historyIndex > 0) {
      setHistoryIndex(historyIndex - 1)
      setNodes(JSON.parse(JSON.stringify(history[historyIndex - 1])))
    }
  }, [history, historyIndex])
  
  // Redo
  const redo = useCallback(() => {
    if (historyIndex < history.length - 1) {
      setHistoryIndex(historyIndex + 1)
      setNodes(JSON.parse(JSON.stringify(history[historyIndex + 1])))
    }
  }, [history, historyIndex])
  
  // Update nodes with history
  const updateNodes = useCallback((newNodes: ComponentNode[]) => {
    setNodes(newNodes)
    saveHistory(newNodes)
  }, [saveHistory])
  
  // Handle drag start from library
  const handleLibraryDragStart = useCallback((component: ComponentDefinition) => {
    setDragState({
      isDragging: true,
      draggedNode: null,
      draggedFromLibrary: component,
      dropTarget: null,
      dropPosition: null
    })
  }, [])
  
  // Handle drag over
  const handleDragOver = useCallback((targetId: string, position: 'before' | 'after' | 'inside') => {
    setDragState(prev => ({
      ...prev,
      dropTarget: targetId,
      dropPosition: position
    }))
  }, [])
  
  // Handle drop
  const handleDrop = useCallback(() => {
    const { draggedFromLibrary, dropTarget, dropPosition } = dragState
    
    if (draggedFromLibrary) {
      const newNode = createNode(draggedFromLibrary)
      
      if (dropTarget === 'root' || nodes.length === 0) {
        updateNodes([...nodes, newNode])
      } else if (dropTarget && dropPosition) {
        const updateTree = (nodeList: ComponentNode[]): ComponentNode[] => {
          const result: ComponentNode[] = []
          
          for (const node of nodeList) {
            if (node.id === dropTarget) {
              if (dropPosition === 'before') {
                result.push(newNode, { ...node, children: updateTree(node.children) })
              } else if (dropPosition === 'after') {
                result.push({ ...node, children: updateTree(node.children) }, newNode)
              } else {
                result.push({ ...node, children: [...node.children, newNode] })
              }
            } else {
              result.push({ ...node, children: updateTree(node.children) })
            }
          }
          
          return result
        }
        
        updateNodes(updateTree(nodes))
      }
      
      setSelectedId(newNode.id)
    }
    
    setDragState({
      isDragging: false,
      draggedNode: null,
      draggedFromLibrary: null,
      dropTarget: null,
      dropPosition: null
    })
  }, [dragState, nodes, updateNodes])
  
  // Handle drag end
  useEffect(() => {
    const handleDragEnd = () => {
      setDragState({
        isDragging: false,
        draggedNode: null,
        draggedFromLibrary: null,
        dropTarget: null,
        dropPosition: null
      })
    }
    
    window.addEventListener('dragend', handleDragEnd)
    return () => window.removeEventListener('dragend', handleDragEnd)
  }, [])
  
  // Toggle expand
  const handleToggleExpand = useCallback((id: string) => {
    const updateTree = (nodeList: ComponentNode[]): ComponentNode[] => {
      return nodeList.map(node => {
        if (node.id === id) {
          return { ...node, expanded: !node.expanded }
        }
        return { ...node, children: updateTree(node.children) }
      })
    }
    setNodes(updateTree(nodes))
  }, [nodes])
  
  // Toggle visibility
  const handleToggleVisibility = useCallback((id: string) => {
    const updateTree = (nodeList: ComponentNode[]): ComponentNode[] => {
      return nodeList.map(node => {
        if (node.id === id) {
          return { ...node, visible: !node.visible }
        }
        return { ...node, children: updateTree(node.children) }
      })
    }
    updateNodes(updateTree(nodes))
  }, [nodes, updateNodes])
  
  // Delete node
  const handleDelete = useCallback((id: string) => {
    const removeNode = (nodeList: ComponentNode[]): ComponentNode[] => {
      return nodeList
        .filter(node => node.id !== id)
        .map(node => ({ ...node, children: removeNode(node.children) }))
    }
    updateNodes(removeNode(nodes))
    if (selectedId === id) setSelectedId(null)
  }, [nodes, selectedId, updateNodes])
  
  // Duplicate node
  const handleDuplicate = useCallback((id: string) => {
    const duplicateNode = (node: ComponentNode): ComponentNode => ({
      ...node,
      id: generateId(),
      children: node.children.map(duplicateNode)
    })
    
    const insertDuplicate = (nodeList: ComponentNode[]): ComponentNode[] => {
      const result: ComponentNode[] = []
      for (const node of nodeList) {
        result.push({ ...node, children: insertDuplicate(node.children) })
        if (node.id === id) {
          result.push(duplicateNode(node))
        }
      }
      return result
    }
    
    updateNodes(insertDuplicate(nodes))
  }, [nodes, updateNodes])
  
  // Update node
  const handleUpdateNode = useCallback((id: string, updates: Partial<ComponentNode>) => {
    const updateTree = (nodeList: ComponentNode[]): ComponentNode[] => {
      return nodeList.map(node => {
        if (node.id === id) {
          return { ...node, ...updates }
        }
        return { ...node, children: updateTree(node.children) }
      })
    }
    updateNodes(updateTree(nodes))
  }, [nodes, updateNodes])
  
  // Get selected node
  const selectedNode = selectedId ? findNode(selectedId) : null
  
  // Generate code with CMS header
  const generatedCode = generateFullTemplate(nodes, selectedCMS, currentTemplate)
  
  // Device width
  const deviceWidth = devicePreview === 'desktop' ? '100%' : devicePreview === 'tablet' ? '768px' : '375px'
  
  return (
    <div className="h-[calc(100vh-4rem)] flex flex-col -m-8">
      {/* CMS Selector Bar */}
      <CMSSelector
        selectedCMS={selectedCMS}
        onSelect={setSelectedCMS}
        themeName={themeName}
        setThemeName={setThemeName}
        currentTemplate={currentTemplate}
        setCurrentTemplate={setCurrentTemplate}
        onExport={handleExportTheme}
        isExporting={isExporting}
      />
      
      {/* Toolbar */}
      <div className="flex items-center justify-between px-4 py-2 bg-white border-b border-gray-200">
        <div className="flex items-center gap-2">
          <Sparkles className="w-5 h-5 text-blue-500" />
          <h1 className="text-lg font-semibold text-gray-900">Visual Builder</h1>
          <span className="px-2 py-0.5 text-xs bg-blue-100 text-blue-700 rounded-full">DiSyL</span>
          <span className="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full">
            {currentTemplate}.disyl
          </span>
        </div>
        
        <div className="flex items-center gap-2">
          {/* Undo/Redo */}
          <div className="flex items-center border-r border-gray-200 pr-2 mr-2">
            <button
              onClick={undo}
              disabled={historyIndex <= 0}
              className="p-2 rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
              title="Undo"
            >
              <Undo2 className="w-4 h-4" />
            </button>
            <button
              onClick={redo}
              disabled={historyIndex >= history.length - 1}
              className="p-2 rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
              title="Redo"
            >
              <Redo2 className="w-4 h-4" />
            </button>
          </div>
          
          {/* Device Preview */}
          <div className="flex items-center bg-gray-100 rounded-lg p-0.5">
            <button
              onClick={() => setDevicePreview('desktop')}
              className={`p-1.5 rounded ${devicePreview === 'desktop' ? 'bg-white shadow-sm' : ''}`}
              title="Desktop"
            >
              <Monitor className="w-4 h-4" />
            </button>
            <button
              onClick={() => setDevicePreview('tablet')}
              className={`p-1.5 rounded ${devicePreview === 'tablet' ? 'bg-white shadow-sm' : ''}`}
              title="Tablet"
            >
              <Tablet className="w-4 h-4" />
            </button>
            <button
              onClick={() => setDevicePreview('mobile')}
              className={`p-1.5 rounded ${devicePreview === 'mobile' ? 'bg-white shadow-sm' : ''}`}
              title="Mobile"
            >
              <Smartphone className="w-4 h-4" />
            </button>
          </div>
          
          {/* View Mode */}
          <div className="flex items-center bg-gray-100 rounded-lg p-0.5">
            <button
              onClick={() => setViewMode('canvas')}
              className={`p-1.5 rounded ${viewMode === 'canvas' ? 'bg-white shadow-sm' : ''}`}
              title="Canvas Only"
            >
              <LayoutGrid className="w-4 h-4" />
            </button>
            <button
              onClick={() => setViewMode('split')}
              className={`p-1.5 rounded ${viewMode === 'split' ? 'bg-white shadow-sm' : ''}`}
              title="Split View"
            >
              <Layout className="w-4 h-4" />
            </button>
            <button
              onClick={() => setViewMode('code')}
              className={`p-1.5 rounded ${viewMode === 'code' ? 'bg-white shadow-sm' : ''}`}
              title="Code Only"
            >
              <Code2 className="w-4 h-4" />
            </button>
          </div>
          
          {/* Actions */}
          <button className="flex items-center gap-2 px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <Save className="w-4 h-4" />
            <span className="text-sm">Save</span>
          </button>
        </div>
      </div>
      
      {/* Main Content */}
      <div className="flex-1 flex overflow-hidden">
        {/* Component Library */}
        <div className="w-72 bg-white border-r border-gray-200 flex flex-col">
          <ComponentPanel
            onDragStart={handleLibraryDragStart}
            searchTerm={searchTerm}
            setSearchTerm={setSearchTerm}
            selectedCategory={selectedCategory}
            setSelectedCategory={setSelectedCategory}
          />
        </div>
        
        {/* Tree Panel */}
        {showTree && (
          <div className="w-64 bg-white border-r border-gray-200 flex flex-col">
            <div className="p-3 border-b border-gray-200 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <List className="w-4 h-4 text-gray-500" />
                <span className="text-sm font-medium text-gray-700">Structure</span>
              </div>
              <button
                onClick={() => setShowTree(false)}
                className="p-1 rounded hover:bg-gray-100"
              >
                <X className="w-4 h-4 text-gray-400" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto py-2">
              {nodes.length === 0 ? (
                <div className="px-4 py-8 text-center text-gray-400 text-sm">
                  No components yet
                </div>
              ) : (
                nodes.map(node => (
                  <TreeNode
                    key={node.id}
                    node={node}
                    depth={0}
                    selectedId={selectedId}
                    onSelect={setSelectedId}
                    onToggleExpand={handleToggleExpand}
                    onToggleVisibility={handleToggleVisibility}
                    onDelete={handleDelete}
                    onDuplicate={handleDuplicate}
                    dragState={dragState}
                    onDragOver={handleDragOver}
                    onDrop={handleDrop}
                  />
                ))
              )}
            </div>
          </div>
        )}
        
        {/* Canvas / Code */}
        <div className="flex-1 flex overflow-hidden bg-gray-100">
          {/* Canvas */}
          {(viewMode === 'canvas' || viewMode === 'split') && (
            <div className={`${viewMode === 'split' ? 'w-1/2' : 'w-full'} overflow-auto`}>
              <div 
                className="mx-auto bg-white min-h-full shadow-sm transition-all duration-300"
                style={{ maxWidth: deviceWidth }}
              >
                <CanvasPreview
                  nodes={nodes}
                  selectedId={selectedId}
                  onSelect={setSelectedId}
                  dragState={dragState}
                  onDragOver={handleDragOver}
                  onDrop={handleDrop}
                />
              </div>
            </div>
          )}
          
          {/* Code */}
          {(viewMode === 'code' || viewMode === 'split') && (
            <div className={`${viewMode === 'split' ? 'w-1/2 border-l border-gray-200' : 'w-full'}`}>
              <CodePreview code={generatedCode} />
            </div>
          )}
        </div>
        
        {/* Properties Panel */}
        <div className="w-80 bg-white border-l border-gray-200">
          <PropertiesPanel
            node={selectedNode}
            onUpdate={handleUpdateNode}
          />
        </div>
      </div>
      
      {/* Toggle Tree Button (when hidden) */}
      {!showTree && (
        <button
          onClick={() => setShowTree(true)}
          className="fixed left-72 top-1/2 -translate-y-1/2 p-2 bg-white border border-gray-200 rounded-r-lg shadow-sm hover:bg-gray-50"
          title="Show Structure"
        >
          <ChevronRight className="w-4 h-4" />
        </button>
      )}
    </div>
  )
}
