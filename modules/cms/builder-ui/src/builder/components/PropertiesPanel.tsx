/**
 * Ikabud Page Builder - Properties Panel
 * Elementor-style tabbed interface: Content, Style, Advanced
 */

import React, { memo, useCallback, useState, useRef, useEffect } from 'react';
import MediaPicker from '@/components/MediaPicker';

// Inline SVG placeholder — zero-dependency fallback for slide thumbnails
function placeholderSvg(w: number, h: number, bg: string, text: string): string {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">` +
    `<rect width="100%" height="100%" fill="${bg}"/>` +
    `<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" ` +
    `fill="#fff" font-family="system-ui,sans-serif" font-size="${Math.max(14, Math.round(h / 8))}px" font-weight="600">` +
    `${text}</text></svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

import {
  Type,
  Palette,
  Settings,
  AlignLeft,
  AlignCenter,
  AlignRight,
  AlignJustify,
  AlignStartVertical,
  AlignCenterVertical,
  AlignEndVertical,
  ArrowUp,
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  Grid3X3,
  Move,
  Maximize,
  ChevronDown,
  ChevronRight,
  Link,
  Image,
  Play,
  Monitor,
  Tablet,
  Smartphone,
  FolderOpen,
  Star,
  List,
  Share2,
  Plus,
  X,
  Hash,
  Code,
  Clock,
  AlertTriangle,
  Search,
  Navigation,
  ToggleLeft,
  MessageSquare,
  Layers,
} from 'lucide-react';
import { Editor } from '@tinymce/tinymce-react';
import { DiSyLNode, NodeProps, NodeStyle } from '../core/types';
import { getComponentDefinition } from '../core/components';
import MediaLibrary from './MediaLibrary';

// =============================================================================
// Property Input Components
// =============================================================================

// =============================================================================
// Input Components
// =============================================================================

interface InputProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  type?: 'text' | 'number' | 'color' | 'url';
  className?: string;
}

const TextInput: React.FC<InputProps> = ({ label, value, onChange, placeholder, type = 'text', className = '' }) => (
  <div className={`mb-3 ${className}`}>
    {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
    <input
      type={type}
      value={value || ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
    />
  </div>
);

interface SelectProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
  className?: string;
}

const SelectInput: React.FC<SelectProps> = ({ label, value, onChange, options, className = '' }) => (
  <div className={`mb-3 ${className}`}>
    {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
    <select
      value={value || ''}
      onChange={(e) => onChange(e.target.value)}
      className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
    >
      {options.map(opt => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  </div>
);

interface TextAreaProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  rows?: number;
}

const TextAreaInput: React.FC<TextAreaProps> = ({ label, value, onChange, placeholder, rows = 6 }) => (
  <div className="mb-3">
    {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
    <textarea
      value={value || ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      rows={rows}
      className="w-full px-2 py-2 text-sm bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4] resize-y min-h-[120px]"
      style={{ lineHeight: '1.5' }}
    />
  </div>
);

// Rich Text Editor using TinyMCE
interface RichTextEditorProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

const RichTextEditor: React.FC<RichTextEditorProps> = ({ label, value, onChange, placeholder }) => {
  const editorRef = useRef<any>(null);

  return (
    <div className="mb-3">
      {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
      <div className="border border-[#3c3c3c] rounded overflow-hidden" style={{ minHeight: '200px' }}>
        <Editor
          tinymceScriptSrc="/assets/cms/tinymce/tinymce.min.js"
          licenseKey="gpl"
          onInit={(_evt, editor) => {
            editorRef.current = editor;
          }}
          value={value || ''}
          onEditorChange={(newValue) => onChange(newValue)}
          init={{
            height: 250,
            menubar: false,
            statusbar: false,
            placeholder: placeholder || 'Enter text...',
            skin: 'oxide-dark',
            content_css: 'dark',

            // Toolbar with formatting options
            toolbar: 'blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link | removeformat',

            // Required plugins
            plugins: ['link', 'lists'],

            // Block formats
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',

            // Content styling for dark theme
            content_style: `
              body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                line-height: 1.6;
                color: #e0e0e0;
                background-color: #1e1e1e;
                margin: 8px;
                padding: 0;
              }
              p { margin: 0 0 0.75em 0; }
              ul, ol { margin: 0.5em 0; padding-left: 1.5em; }
              li { margin: 0.25em 0; }
              h2, h3, h4 { margin: 0.5em 0; color: #fff; }
              a { color: #0078d4; }
            `,
          }}
        />
      </div>
    </div>
  );
};

// Color input with preview
const ColorInput: React.FC<InputProps> = ({ label, value, onChange, placeholder }) => (
  <div className="mb-3">
    {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
    <div className="flex gap-2">
      <input
        type="color"
        value={value || '#000000'}
        onChange={(e) => onChange(e.target.value)}
        className="w-8 h-8 bg-transparent border border-[#3c3c3c] cursor-pointer"
      />
      <input
        type="text"
        value={value || ''}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder || '#000000'}
        className="flex-1 px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
      />
    </div>
  </div>
);

// Button group for alignment/options
interface ButtonGroupProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  options: { value: string; icon: React.ReactNode; title?: string }[];
}

const ButtonGroup: React.FC<ButtonGroupProps> = ({ label, value, onChange, options }) => (
  <div className="mb-3">
    {label && <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">{label}</label>}
    <div className="flex bg-[#1e1e1e] border border-[#3c3c3c]">
      {options.map(opt => (
        <button
          key={opt.value}
          onClick={() => onChange(opt.value)}
          title={opt.title}
          className={`flex-1 p-1.5 flex items-center justify-center transition-colors ${value === opt.value ? 'bg-[#0078d4] text-white' : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
        >
          {opt.icon}
        </button>
      ))}
    </div>
  </div>
);

// Spacing control (4 sides)
interface SpacingControlProps {
  label: string;
  values: { top: string; right: string; bottom: string; left: string };
  onChange: (side: 'top' | 'right' | 'bottom' | 'left', value: string) => void;
  linked?: boolean;
  onLinkChange?: (linked: boolean) => void;
}

const SpacingControl: React.FC<SpacingControlProps> = ({ label, values, onChange }) => (
  <div className="mb-3">
    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">{label}</label>
    <div className="grid grid-cols-3 gap-1 items-center">
      <div />
      <input
        type="text"
        value={values.top}
        onChange={(e) => onChange('top', e.target.value)}
        placeholder="0"
        className="w-full px-1 py-1 text-[10px] text-center bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
        title="Top"
      />
      <div />
      <input
        type="text"
        value={values.left}
        onChange={(e) => onChange('left', e.target.value)}
        placeholder="0"
        className="w-full px-1 py-1 text-[10px] text-center bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
        title="Left"
      />
      <div className="w-full h-6 border border-dashed border-white/20 flex items-center justify-center">
        <Move className="w-3 h-3 text-white/30" />
      </div>
      <input
        type="text"
        value={values.right}
        onChange={(e) => onChange('right', e.target.value)}
        placeholder="0"
        className="w-full px-1 py-1 text-[10px] text-center bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
        title="Right"
      />
      <div />
      <input
        type="text"
        value={values.bottom}
        onChange={(e) => onChange('bottom', e.target.value)}
        placeholder="0"
        className="w-full px-1 py-1 text-[10px] text-center bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
        title="Bottom"
      />
      <div />
    </div>
  </div>
);

// =============================================================================
// Collapsible Section
// =============================================================================

interface CollapsibleSectionProps {
  title: string;
  icon?: React.ReactNode;
  children: React.ReactNode;
  defaultOpen?: boolean;
}

const CollapsibleSection: React.FC<CollapsibleSectionProps> = ({ title, icon, children, defaultOpen = true }) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  return (
    <div className="border-b border-[#3c3c3c]">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex items-center gap-2 py-2.5 px-1 text-left hover:bg-white/5 transition-colors"
      >
        {isOpen ? <ChevronDown className="w-3 h-3 text-white/40" /> : <ChevronRight className="w-3 h-3 text-white/40" />}
        {icon && <span className="text-white/50">{icon}</span>}
        <span className="text-[11px] font-medium text-white/70 uppercase tracking-wide">{title}</span>
      </button>
      {isOpen && <div className="pb-3 px-1">{children}</div>}
    </div>
  );
};

// =============================================================================
// Properties Panel - Elementor Style with 3 Tabs
// =============================================================================

interface PropertiesPanelProps {
  node: DiSyLNode | null;
  onUpdateProps: (nodeId: string, props: Partial<NodeProps>) => void;
  onUpdateStyle: (nodeId: string, style: Partial<NodeStyle>) => void;
  viewport?: 'desktop' | 'tablet' | 'mobile';
  onViewportChange?: (viewport: 'desktop' | 'tablet' | 'mobile') => void;
}

// =============================================================================
// Category Selector Component
// =============================================================================

interface CategorySelectorProps {
  value: number[];
  onChange: (value: number[]) => void;
}

const CategorySelector: React.FC<CategorySelectorProps> = ({ value, onChange }) => {
  const [categories, setCategories] = useState<Array<{ id: number; name: string; slug: string }>>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchCategories = async () => {
      try {
        const response = await fetch('/api/v1/cms/categories', {
          credentials: 'include',
          headers: { 'Accept': 'application/json' },
        });
        const data = await response.json();
        if ((data.ok || data.success) && Array.isArray(data.data)) {
          setCategories(data.data);
        }
      } catch (error) {
        console.error('Failed to fetch categories:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchCategories();
  }, []);

  const handleToggle = (categoryId: number) => {
    if (value.includes(categoryId)) {
      onChange(value.filter(id => id !== categoryId));
    } else {
      onChange([...value, categoryId]);
    }
  };

  if (loading) {
    return <div className="text-xs text-white/60">Loading categories...</div>;
  }

  if (categories.length === 0) {
    return <div className="text-xs text-white/60">No categories found</div>;
  }

  return (
    <div className="space-y-2 max-h-32 overflow-y-auto">
      {categories.map(category => (
        <label key={category.id} className="flex items-center gap-2 text-xs text-white/80 cursor-pointer hover:text-white">
          <input
            type="checkbox"
            checked={value.includes(category.id)}
            onChange={() => handleToggle(category.id)}
            className="w-3 h-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded focus:outline-none focus:border-[#0078d4]"
          />
          <span>{category.name}</span>
        </label>
      ))}
    </div>
  );
};

const DepartmentSelector: React.FC<CategorySelectorProps> = ({ value, onChange }) => {
  const [departments, setDepartments] = useState<Array<{ id: number; name: string; slug: string }>>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchDepartments = async () => {
      try {
        const response = await fetch('/api/v1/cms/categories?exclude_taxonomy=product', {
          credentials: 'include',
          headers: { 'Accept': 'application/json' },
        });
        const data = await response.json();
        if ((data.ok || data.success) && Array.isArray(data.data)) {
          setDepartments(data.data);
        }
      } catch (error) {
        console.error('Failed to fetch departments:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchDepartments();
  }, []);

  const handleToggle = (departmentId: number) => {
    if (value.includes(departmentId)) {
      onChange(value.filter(id => id !== departmentId));
    } else {
      onChange([...value, departmentId]);
    }
  };

  if (loading) {
    return <div className="text-xs text-white/60">Loading departments...</div>;
  }

  if (departments.length === 0) {
    return <div className="text-xs text-white/60">No departments found</div>;
  }

  return (
    <div className="space-y-2 max-h-32 overflow-y-auto">
      {departments.map(department => (
        <label key={department.id} className="flex items-center gap-2 text-xs text-white/80 cursor-pointer hover:text-white">
          <input
            type="checkbox"
            checked={value.includes(department.id)}
            onChange={() => handleToggle(department.id)}
            className="w-3 h-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded focus:outline-none focus:border-[#0078d4]"
          />
          <span>{department.name}</span>
        </label>
      ))}
    </div>
  );
};

const ProductCategorySelector: React.FC<CategorySelectorProps> = ({ value, onChange }) => {
  const [categories, setCategories] = useState<Array<{ id: number; name: string; slug: string }>>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchCategories = async () => {
      try {
        const response = await fetch('/api/v1/ecommerce/categories', {
          credentials: 'include',
          headers: { 'Accept': 'application/json' },
        });
        const data = await response.json();
        const nextCategories = Array.isArray(data.categories)
          ? data.categories
          : Array.isArray(data.data?.categories)
            ? data.data.categories
            : Array.isArray(data.data)
              ? data.data
              : [];

        if ((data.ok || data.success) && nextCategories.length > 0) {
          setCategories(nextCategories);
        }
      } catch (error) {
        console.error('Failed to fetch product categories:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchCategories();
  }, []);

  const handleToggle = (categoryId: number) => {
    if (value.includes(categoryId)) {
      onChange(value.filter(id => id !== categoryId));
    } else {
      onChange([...value, categoryId]);
    }
  };

  if (loading) {
    return <div className="text-xs text-white/60">Loading categories...</div>;
  }

  if (categories.length === 0) {
    return <div className="text-xs text-white/60">No categories yet — all products will show.</div>;
  }

  return (
    <div className="space-y-2 max-h-32 overflow-y-auto">
      {categories.map(category => (
        <label key={category.id} className="flex items-center gap-2 text-xs text-white/80 cursor-pointer hover:text-white">
          <input
            type="checkbox"
            checked={value.includes(category.id)}
            onChange={() => handleToggle(category.id)}
            className="w-3 h-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded focus:outline-none focus:border-[#0078d4]"
          />
          <span>{category.name}</span>
        </label>
      ))}
    </div>
  );
};

type TabType = 'content' | 'style' | 'advanced';
type StyleViewport = 'desktop' | 'tablet' | 'mobile';

const PropertiesPanel: React.FC<PropertiesPanelProps> = ({
  node,
  onUpdateProps,
  onUpdateStyle,
  viewport: canvasViewport,
  onViewportChange,
}) => {
  const [activeTab, setActiveTab] = useState<TabType>('content');
  const [styleViewport, setStyleViewport] = useState<StyleViewport>(canvasViewport || 'desktop');
  const [mediaLibraryOpen, setMediaLibraryOpen] = useState(false);
  const [currentSlideIndex, setCurrentSlideIndex] = useState<number | null>(null);

  // Sync panel viewport when canvas viewport changes (toolbar button clicks)
  useEffect(() => {
    if (canvasViewport && canvasViewport !== styleViewport) {
      setStyleViewport(canvasViewport);
    }
  }, [canvasViewport]);  // intentionally omit styleViewport to avoid feedback loop

  // When user changes viewport in the panel, also update canvas
  const handleViewportChange = useCallback((vp: StyleViewport) => {
    setStyleViewport(vp);
    onViewportChange?.(vp);
  }, [onViewportChange]);
  // Media Picker state for Gallery (must be before conditional return to satisfy React hooks rules)
  const [isMediaPickerOpen, setIsMediaPickerOpen] = useState(false);

  const handlePropChange = useCallback((key: string, value: unknown) => {
    if (!node) return;
    onUpdateProps(node.id, { [key]: value });
  }, [node, onUpdateProps]);

  const createRepeaterId = useCallback((prefix: string) => `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`, []);

  const normalizeTabs = useCallback((tabs: any[] = []) => tabs.filter((tab) => tab && typeof tab === 'object').map((tab, index) => ({
    id: String(tab.id || createRepeaterId('tab')),
    label: String(tab.label || `Tab ${index + 1}`),
    content: String(tab.content || ''),
  })), [createRepeaterId]);

  const normalizeAccordionItems = useCallback((items: any[] = []) => items.filter((item) => item && typeof item === 'object').map((item, index) => ({
    id: String(item.id || createRepeaterId('item')),
    title: String(item.title || `Item ${index + 1}`),
    content: String(item.content || ''),
    isOpen: Boolean(item.isOpen),
  })), [createRepeaterId]);

  const normalizeSlides = useCallback((slides: any[] = []) => slides.filter((slide) => slide && typeof slide === 'object').map((slide, index) => ({
    id: String(slide.id || createRepeaterId('slide')),
    image: String(slide.image || slide.src || ''),
    title: String(slide.title || `Slide ${index + 1}`),
    description: String(slide.description || slide.content || ''),
    link: String(slide.link || ''),
    ctaText: String(slide.ctaText || ''),
  })), [createRepeaterId]);

  const normalizeGalleryImages = useCallback((images: any[] = []) => images.filter((image) => image && typeof image === 'object').map((image, index) => ({
    id: String(image.id || createRepeaterId(`img${index + 1}`)),
    src: String(image.src || image.image || ''),
    alt: String(image.alt || ''),
    caption: String(image.caption || image.title || ''),
  })), [createRepeaterId]);

  const normalizePricingFeatures = useCallback((features: any[] = []) => features.filter((feature) => feature && typeof feature === 'object').map((feature, index) => ({
    id: String(feature.id || createRepeaterId('feature')),
    text: String(feature.text || feature.label || `Feature ${index + 1}`),
    included: feature.included !== false,
  })), [createRepeaterId]);

  // Get the effective style value for current viewport.
  // For tablet/mobile, returns the viewport-specific override if set,
  // otherwise falls through to the desktop (inherited) value.
  const getStyleValue = useCallback((key: string): string => {
    if (!node) return '';
    if (styleViewport === 'desktop') {
      return (node.style as Record<string, unknown>)[key] as string || '';
    }
    // Check viewport-specific value first
    const viewportStyles = node.style[styleViewport] as Record<string, unknown> | undefined;
    const viewportVal = viewportStyles?.[key] as string | undefined;
    if (viewportVal !== undefined && viewportVal !== null && viewportVal !== '') {
      return viewportVal;
    }
    // Mobile also inherits tablet overrides
    if (styleViewport === 'mobile') {
      const tabletStyles = node.style.tablet as Record<string, unknown> | undefined;
      const tabletVal = tabletStyles?.[key] as string | undefined;
      if (tabletVal !== undefined && tabletVal !== null && tabletVal !== '') {
        return tabletVal;
      }
    }
    // Fall through to desktop (inherited) value
    return (node.style as Record<string, unknown>)[key] as string || '';
  }, [node, styleViewport]);

  // Check whether current viewport has an explicit override for this key
  const isStyleOverridden = useCallback((key: string): boolean => {
    if (!node || styleViewport === 'desktop') return true;
    const viewportStyles = node.style[styleViewport] as Record<string, unknown> | undefined;
    const val = viewportStyles?.[key];
    return val !== undefined && val !== null && val !== '';
  }, [node, styleViewport]);

  // Handle style change for current viewport
  const handleStyleChange = useCallback((key: string, value: string) => {
    if (!node) return;

    if (styleViewport === 'desktop') {
      // Desktop styles go directly on the node
      onUpdateStyle(node.id, { [key]: value });
    } else {
      // Tablet/mobile styles go in responsive overrides
      const currentViewportStyles = (node.style[styleViewport] as Record<string, unknown>) || {};
      onUpdateStyle(node.id, {
        [styleViewport]: {
          ...currentViewportStyles,
          [key]: value || undefined, // Remove empty values
        }
      });
    }
  }, [node, onUpdateStyle, styleViewport]);


  // Helper to parse spacing values
  const parseSpacing = (value: string | undefined): { top: string; right: string; bottom: string; left: string } => {
    if (!value) return { top: '', right: '', bottom: '', left: '' };
    const parts = value.split(' ').filter(Boolean);
    if (parts.length === 1) return { top: parts[0], right: parts[0], bottom: parts[0], left: parts[0] };
    if (parts.length === 2) return { top: parts[0], right: parts[1], bottom: parts[0], left: parts[1] };
    if (parts.length === 3) return { top: parts[0], right: parts[1], bottom: parts[2], left: parts[1] };
    return { top: parts[0] || '', right: parts[1] || '', bottom: parts[2] || '', left: parts[3] || '' };
  };

  const formatSpacing = (values: { top: string; right: string; bottom: string; left: string }): string => {
    const { top, right, bottom, left } = values;
    if (!top && !right && !bottom && !left) return '';
    if (top === right && right === bottom && bottom === left) return top;
    if (top === bottom && left === right) return `${top} ${right}`;
    return `${top || '0'} ${right || '0'} ${bottom || '0'} ${left || '0'}`;
  };

  if (!node) {
    return (
      <div className="h-full flex items-center justify-center p-4 bg-[#252526]">
        <p className="text-xs text-white/30 text-center">
          Select an element to edit
        </p>
      </div>
    );
  }

  const componentDef = getComponentDefinition(node.type);
  const isContainer = ['section', 'container', 'row', 'column'].includes(node.type);
  const isTextElement = ['heading', 'text', 'button'].includes(node.type);

  // Handle media selection for Gallery
  const handleMediaSelect = useCallback((item: any) => {
    const galleryImage = {
      id: `img_${item.id}`,
      src: item.url,
      alt: item.alt_text || item.original_filename || '',
      caption: item.title || ''
    };

    // Add to existing images
    const currentImages = normalizeGalleryImages((node.props.images as any[]) || []);
    const updatedImages = normalizeGalleryImages([...currentImages, galleryImage]);

    handlePropChange('images', updatedImages);
    setIsMediaPickerOpen(false);
  }, [node.props.images, handlePropChange, normalizeGalleryImages]);

  // Handle multiple media selection for Gallery
  const handleMediaSelectMultiple = useCallback((items: any[]) => {
    const galleryImages = items.map(item => ({
      id: `img_${item.id}`,
      src: item.url,
      alt: item.alt_text || item.original_filename || '',
      caption: item.title || ''
    }));

    // Add to existing images
    const currentImages = normalizeGalleryImages((node.props.images as any[]) || []);
    const updatedImages = normalizeGalleryImages([...currentImages, ...galleryImages]);

    handlePropChange('images', updatedImages);
    setIsMediaPickerOpen(false);
  }, [node.props.images, handlePropChange, normalizeGalleryImages]);

  // ==========================================================================
  // Content Tab
  // ==========================================================================
  const renderContentTab = () => (
    <div className="p-3">
      {/* Text Content */}
      {isTextElement && (
        <CollapsibleSection title="Content" icon={<Type className="w-3 h-3" />}>
          {node.type === 'text' ? (
            <RichTextEditor
              label="Text Content"
              value={node.props.content as string || ''}
              onChange={(v) => handlePropChange('content', v)}
              placeholder="Enter text..."
            />
          ) : (
            <TextInput
              label={node.type === 'heading' ? 'Heading Text' : 'Button Text'}
              value={node.props.content as string || ''}
              onChange={(v) => handlePropChange('content', v)}
              placeholder="Enter text..."
            />
          )}

          {node.type === 'heading' && (
            <SelectInput
              label="HTML Tag"
              value={String(node.props.level || 2)}
              onChange={(v) => handlePropChange('level', parseInt(v))}
              options={[
                { value: '1', label: 'H1' },
                { value: '2', label: 'H2' },
                { value: '3', label: 'H3' },
                { value: '4', label: 'H4' },
                { value: '5', label: 'H5' },
                { value: '6', label: 'H6' },
              ]}
            />
          )}
        </CollapsibleSection>
      )}

      {/* Button Link */}
      {node.type === 'button' && (
        <CollapsibleSection title="Link" icon={<Link className="w-3 h-3" />}>
          <TextInput
            label="URL"
            value={node.props.href as string || ''}
            onChange={(v) => handlePropChange('href', v)}
            placeholder="https://..."
          />
          <SelectInput
            label="Open in"
            value={node.props.target as string || '_self'}
            onChange={(v) => handlePropChange('target', v)}
            options={[
              { value: '_self', label: 'Same Window' },
              { value: '_blank', label: 'New Window' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Image */}
      {node.type === 'image' && (
        <CollapsibleSection title="Image" icon={<Image className="w-3 h-3" />}>
          <div className="mb-3">
            <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Image</label>
            <div className="flex gap-2">
              <input
                type="text"
                value={node.props.src as string || ''}
                onChange={(e) => handlePropChange('src', e.target.value)}
                placeholder="https://..."
                className="flex-1 px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
              />
              <button
                onClick={() => setMediaLibraryOpen(true)}
                className="px-2 py-1.5 bg-[#1e1e1e] border border-[#3c3c3c] text-white/60 hover:text-white hover:bg-white/5 transition-colors"
                title="Browse Media Library"
              >
                <FolderOpen className="w-4 h-4" />
              </button>
            </div>
          </div>
          <TextInput
            label="Alt Text"
            value={node.props.alt as string || ''}
            onChange={(v) => handlePropChange('alt', v)}
            placeholder="Describe the image..."
          />
          <SelectInput
            label="Object Fit"
            value={(node.style as Record<string, string>).objectFit || 'cover'}
            onChange={(v) => handleStyleChange('objectFit', v)}
            options={[
              { value: 'cover', label: 'Cover' },
              { value: 'contain', label: 'Contain' },
              { value: 'fill', label: 'Fill' },
              { value: 'none', label: 'None' },
            ]}
          />
          <ButtonGroup
            label="Alignment"
            value={node.style.display === 'block' ? (node.style.marginLeft === 'auto' && node.style.marginRight === 'auto' ? 'center' : node.style.marginLeft === 'auto' ? 'right' : 'left') : 'left'}
            onChange={(v) => {
              if (v === 'left') {
                handleStyleChange('display', 'block');
                handleStyleChange('marginLeft', '0');
                handleStyleChange('marginRight', 'auto');
              } else if (v === 'center') {
                handleStyleChange('display', 'block');
                handleStyleChange('marginLeft', 'auto');
                handleStyleChange('marginRight', 'auto');
              } else if (v === 'right') {
                handleStyleChange('display', 'block');
                handleStyleChange('marginLeft', 'auto');
                handleStyleChange('marginRight', '0');
              }
            }}
            options={[
              { value: 'left', icon: <AlignLeft className="w-3 h-3" />, title: 'Left' },
              { value: 'center', icon: <AlignCenter className="w-3 h-3" />, title: 'Center' },
              { value: 'right', icon: <AlignRight className="w-3 h-3" />, title: 'Right' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Video */}
      {node.type === 'video' && (
        <CollapsibleSection title="Video" icon={<Play className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Video URL"
            value={node.props.src as string || ''}
            onChange={(v) => handlePropChange('src', v)}
            placeholder="https://..."
          />
          <TextInput
            label="Poster Image"
            value={node.props.poster as string || ''}
            onChange={(v) => handlePropChange('poster', v)}
            placeholder="https://..."
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Controls</label>
            <button
              onClick={() => handlePropChange('controls', !(node.props.controls !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.controls !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.controls !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Autoplay</label>
            <button
              onClick={() => handlePropChange('autoplay', !node.props.autoplay)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.autoplay ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.autoplay ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Loop</label>
            <button
              onClick={() => handlePropChange('loop', !node.props.loop)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.loop ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.loop ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Muted</label>
            <button
              onClick={() => handlePropChange('muted', !node.props.muted)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.muted ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.muted ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Slideshow */}
      {node.type === 'slideshow' && (
        <>
          <CollapsibleSection title="Slides" icon={<Image className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              {normalizeSlides((node.props.slides as any[]) || []).map((slide: any, index: number) => (
                <div key={slide.id || index} className="p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded space-y-2">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-medium text-white/60">Slide {index + 1}</span>
                    <button
                      onClick={() => {
                        const slides = normalizeSlides((node.props.slides as any[]) || []);
                        slides.splice(index, 1);
                        handlePropChange('slides', slides);
                      }}
                      className="p-1 text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded transition-colors"
                      title="Delete slide"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </div>

                  <div>
                    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Image URL</label>
                    <div className="flex gap-2">
                      <input
                        type="text"
                        value={slide.image || ''}
                        onChange={(e) => {
                          const slides = normalizeSlides((node.props.slides as any[]) || []);
                          slides[index] = { ...slides[index], image: e.target.value };
                          handlePropChange('slides', slides);
                        }}
                        placeholder="https://..."
                        className="flex-1 px-2 py-1.5 text-xs bg-[#0d0d0d] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
                      />
                      <button
                        onClick={() => {
                          setCurrentSlideIndex(index);
                          setMediaLibraryOpen(true);
                        }}
                        className="px-2 py-1.5 bg-[#0d0d0d] border border-[#3c3c3c] text-white/60 hover:text-white hover:bg-white/5 transition-colors"
                        title="Browse Media Library"
                      >
                        <FolderOpen className="w-4 h-4" />
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Title</label>
                    <input
                      type="text"
                      value={slide.title || ''}
                      onChange={(e) => {
                        const slides = normalizeSlides((node.props.slides as any[]) || []);
                        slides[index] = { ...slides[index], title: e.target.value };
                        handlePropChange('slides', slides);
                      }}
                      placeholder="Slide title..."
                      className="w-full px-2 py-1.5 text-xs bg-[#0d0d0d] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Description</label>
                    <textarea
                      value={slide.description || ''}
                      onChange={(e) => {
                        const slides = normalizeSlides((node.props.slides as any[]) || []);
                        slides[index] = { ...slides[index], description: e.target.value };
                        handlePropChange('slides', slides);
                      }}
                      placeholder="Slide description..."
                      rows={2}
                      className="w-full px-2 py-1.5 text-xs bg-[#0d0d0d] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4] resize-none"
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Link (Optional)</label>
                    <input
                      type="text"
                      value={slide.link || ''}
                      onChange={(e) => {
                        const slides = normalizeSlides((node.props.slides as any[]) || []);
                        slides[index] = { ...slides[index], link: e.target.value };
                        handlePropChange('slides', slides);
                      }}
                      placeholder="https://..."
                      className="w-full px-2 py-1.5 text-xs bg-[#0d0d0d] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Button Text (Optional)</label>
                    <input
                      type="text"
                      value={slide.ctaText || ''}
                      onChange={(e) => {
                        const slides = normalizeSlides((node.props.slides as any[]) || []);
                        slides[index] = { ...slides[index], ctaText: e.target.value };
                        handlePropChange('slides', slides);
                      }}
                      placeholder="Learn More"
                      className="w-full px-2 py-1.5 text-xs bg-[#0d0d0d] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
                    />
                  </div>
                </div>
              ))}

              <button
                onClick={() => {
                  const slides = normalizeSlides((node.props.slides as any[]) || []);
                  slides.push({
                    id: createRepeaterId('slide'),
                    image: placeholderSvg(1200, 500, '#3B82F6', `Slide ${slides.length + 1}`),
                    title: `Slide ${slides.length + 1}`,
                    description: '',
                    link: '',
                    ctaText: '',
                  });
                  handlePropChange('slides', slides);
                }}
                className="w-full px-3 py-2 bg-[#0078d4] text-white text-xs font-medium hover:bg-[#006cbd] transition-colors rounded flex items-center justify-center gap-2"
              >
                <Plus className="w-3 h-3" />
                Add Slide
              </button>
            </div>
          </CollapsibleSection>

          <CollapsibleSection title="Slideshow Settings" icon={<Settings className="w-3 h-3" />}>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Autoplay</label>
                <button
                  onClick={() => handlePropChange('autoplay', !node.props.autoplay)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.autoplay ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.autoplay ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <SelectInput
                label="Interval (seconds)"
                value={String((node.props.interval as number || 5000) / 1000)}
                onChange={(v) => handlePropChange('interval', parseInt(v) * 1000)}
                options={[
                  { value: '3', label: '3 seconds' },
                  { value: '4', label: '4 seconds' },
                  { value: '5', label: '5 seconds' },
                  { value: '6', label: '6 seconds' },
                  { value: '7', label: '7 seconds' },
                  { value: '8', label: '8 seconds' },
                  { value: '10', label: '10 seconds' },
                ]}
              />

              <SelectInput
                label="Animation Style"
                value={node.props.animationStyle as string || 'slide'}
                onChange={(v) => handlePropChange('animationStyle', v)}
                options={[
                  { value: 'slide', label: 'Slide' },
                  { value: 'fade', label: 'Fade' },
                  { value: 'zoom', label: 'Zoom' },
                  { value: 'cube', label: 'Cube (3D)' },
                  { value: 'kenburns', label: 'Ken Burns' },
                  { value: 'flip', label: 'Flip' },
                  { value: 'carousel', label: 'Carousel' },
                  { value: 'coverflow', label: 'Coverflow' },
                ]}
              />

              <SelectInput
                label="Height"
                value={node.props.height as string || '500px'}
                onChange={(v) => handlePropChange('height', v)}
                options={[
                  { value: '300px', label: 'Small (300px)' },
                  { value: '400px', label: 'Medium (400px)' },
                  { value: '500px', label: 'Large (500px)' },
                  { value: '600px', label: 'Extra Large (600px)' },
                  { value: '700px', label: 'Huge (700px)' },
                  { value: '100vh', label: 'Full Screen' },
                ]}
              />

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Arrows</label>
                <button
                  onClick={() => handlePropChange('showArrows', node.props.showArrows === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showArrows !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showArrows !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Dots</label>
                <button
                  onClick={() => handlePropChange('showDots', node.props.showDots === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showDots !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showDots !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Full Width</label>
                <button
                  onClick={() => handlePropChange('fullWidth', !node.props.fullWidth)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.fullWidth ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.fullWidth ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>
            </div>
          </CollapsibleSection>

          <CollapsibleSection title="Caption Typography" icon={<Type className="w-3 h-3" />}>
            <TextInput
              label="Title Font Size"
              value={(node.props.captionTitleSize as string) || '32px'}
              onChange={(v) => handlePropChange('captionTitleSize', v)}
              placeholder="32px"
            />
            <TextInput
              label="Description Font Size"
              value={(node.props.captionDescSize as string) || '18px'}
              onChange={(v) => handlePropChange('captionDescSize', v)}
              placeholder="18px"
            />
            <ColorInput
              label="Text Color"
              value={(node.props.captionColor as string) || '#ffffff'}
              onChange={(v) => handlePropChange('captionColor', v)}
            />
            <SelectInput
              label="Text Align"
              value={(node.props.captionAlign as string) || 'center'}
              onChange={(v) => handlePropChange('captionAlign', v)}
              options={[
                { value: 'left', label: 'Left' },
                { value: 'center', label: 'Center' },
                { value: 'right', label: 'Right' },
              ]}
            />
            <SelectInput
              label="Caption Position"
              value={(node.props.captionPosition as string) || 'bottom'}
              onChange={(v) => handlePropChange('captionPosition', v)}
              options={[
                { value: 'top', label: 'Top' },
                { value: 'center', label: 'Center' },
                { value: 'bottom', label: 'Bottom' },
              ]}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Posts Grid - Progressive Disclosure */}
      {node.type === 'posts_grid' && (
        <>
          {/* Summary Panel - Read-only config overview */}
          <div className="mb-3 p-3 bg-[#1a1a2e] rounded-lg border border-white/10">
            <div className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">This block currently shows</div>
            <div className="text-xs text-white/80 space-y-1">
              <div><span className="text-white/50">Source:</span> {(node.props.categoryIds as number[])?.length > 0 ? `${(node.props.categoryIds as number[]).length} categories` : 'All posts'}</div>
              <div><span className="text-white/50">Display:</span> {node.props.postCount || 3} posts in {node.props.gridColumns || 3} columns</div>
              <div><span className="text-white/50">Order:</span> {node.props.orderBy === 'title' ? 'By title' : node.props.orderBy === 'random' ? 'Random' : 'Newest first'}</div>
            </div>
          </div>

          {/* LEVEL 1: Essential - Works immediately */}
          <CollapsibleSection title="How many posts?" icon={<Grid3X3 className="w-3 h-3" />} defaultOpen>
            <SelectInput
              label="Post Count"
              value={String(node.props.postCount || 3)}
              onChange={(v) => handlePropChange('postCount', parseInt(v))}
              options={[
                { value: '1', label: '1 Post' },
                { value: '2', label: '2 Posts' },
                { value: '3', label: '3 Posts' },
                { value: '4', label: '4 Posts' },
                { value: '6', label: '6 Posts' },
                { value: '8', label: '8 Posts' },
                { value: '9', label: '9 Posts' },
                { value: '12', label: '12 Posts' },
              ]}
            />
            <SelectInput
              label="Grid Columns"
              value={String(node.props.gridColumns || 3)}
              onChange={(v) => handlePropChange('gridColumns', parseInt(v))}
              options={[
                { value: '1', label: '1 Column' },
                { value: '2', label: '2 Columns' },
                { value: '3', label: '3 Columns' },
                { value: '4', label: '4 Columns' },
                { value: '5', label: '5 Columns' },
                { value: '6', label: '6 Columns' },
              ]}
            />
          </CollapsibleSection>

          {/* LEVEL 2: Common Controls - 80% use case */}
          <CollapsibleSection title="What to show?" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Featured Image</label>
                <button
                  onClick={() => handlePropChange('showFeaturedImage', node.props.showFeaturedImage === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showFeaturedImage !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showFeaturedImage !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Date</label>
                <button
                  onClick={() => handlePropChange('showDate', node.props.showDate === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showDate !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showDate !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Excerpt</label>
                <button
                  onClick={() => handlePropChange('showExcerpt', node.props.showExcerpt === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showExcerpt !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showExcerpt !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Read More Link</label>
                <button
                  onClick={() => handlePropChange('showReadMore', node.props.showReadMore === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showReadMore !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showReadMore !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              {node.props.showReadMore !== false && (
                <TextInput
                  label="Link Label"
                  value={String(node.props.readMoreText || 'Read More')}
                  onChange={(v) => handlePropChange('readMoreText', v)}
                  placeholder="Read More"
                />
              )}
            </div>
          </CollapsibleSection>

          {/* LEVEL 3: Advanced - Opt-in, collapsed by default */}
          <CollapsibleSection title="Advanced Options" icon={<Settings className="w-3 h-3" />} defaultOpen={false}>
            <div className="text-[10px] text-white/50 mb-3 pb-2 border-b border-white/10">
              Fine-tune which posts appear and how they're sorted
            </div>
            <SelectInput
              label="Content Type"
              value={(node.props.postType as string) || 'post'}
              onChange={(v) => handlePropChange('postType', v)}
              options={[
                { value: 'post', label: 'Blog Posts' },
                { value: 'page', label: 'Pages' },
              ]}
            />
            <div className="mt-3">
              <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Filter by Category</label>
              <div className="text-[10px] text-white/50 mb-2">
                Leave empty to show all posts
              </div>
              <CategorySelector
                value={(node.props.categoryIds as number[]) || []}
                onChange={(ids) => handlePropChange('categoryIds', ids)}
              />
            </div>
            <SelectInput
              label="Sort By"
              value={(node.props.orderBy as string) || 'date'}
              onChange={(v) => handlePropChange('orderBy', v)}
              options={[
                { value: 'date', label: 'Publication Date' },
                { value: 'title', label: 'Title (A-Z)' },
                { value: 'random', label: 'Random' },
              ]}
            />
            <SelectInput
              label="Sort Order"
              value={(node.props.order as string) || 'desc'}
              onChange={(v) => handlePropChange('order', v)}
              options={[
                { value: 'desc', label: 'Newest First' },
                { value: 'asc', label: 'Oldest First' },
              ]}
            />
            {node.props.showExcerpt !== false && (
              <SelectInput
                label="Excerpt Length"
                value={String(node.props.excerptLength || 120)}
                onChange={(v) => handlePropChange('excerptLength', parseInt(v))}
                options={[
                  { value: '50', label: 'Short (50 chars)' },
                  { value: '80', label: 'Medium (80 chars)' },
                  { value: '120', label: 'Default (120 chars)' },
                  { value: '160', label: 'Long (160 chars)' },
                ]}
              />
            )}
            <div className="flex items-center justify-between mt-3">
              <label className="text-xs text-white/70">Show Author</label>
              <button
                onClick={() => handlePropChange('showAuthor', !node.props.showAuthor)}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showAuthor ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showAuthor ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
          </CollapsibleSection>
        </>
      )}

      {/* Spacer */}
      {node.type === 'spacer' && (
        <CollapsibleSection title="Spacer" icon={<Maximize className="w-3 h-3" />}>
          <SelectInput
            label="Height"
            value={node.props.height as string || '48px'}
            onChange={(v) => handlePropChange('height', v)}
            options={[
              { value: '16px', label: 'XS (16px)' },
              { value: '24px', label: 'SM (24px)' },
              { value: '32px', label: 'MD (32px)' },
              { value: '48px', label: 'LG (48px)' },
              { value: '64px', label: 'XL (64px)' },
              { value: '96px', label: '2XL (96px)' },
              { value: '128px', label: '3XL (128px)' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Products Grid - Progressive Disclosure */}
      {node.type === 'products_grid' && (
        <>
          {/* Summary Panel - Read-only config overview */}
          <div className="mb-3 p-3 bg-[#1a1a2e] rounded-lg border border-white/10">
            <div className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">This block currently shows</div>
            <div className="text-xs text-white/80 space-y-1">
              <div><span className="text-white/50">Categories:</span> {(node.props.categoryIds as number[])?.length > 0 ? `${(node.props.categoryIds as number[]).length} selected` : 'All categories'}</div>
              <div><span className="text-white/50">Display:</span> {String(node.props.itemCount || 6)} products in {String(node.props.gridColumns || 3)} columns</div>
              <div><span className="text-white/50">Order:</span> {node.props.orderBy === 'title' ? 'By name' : node.props.orderBy === 'price' ? 'By price' : node.props.orderBy === 'random' ? 'Random' : 'Newest first'}</div>
            </div>
          </div>

          {/* LEVEL 1: Essential */}
          <CollapsibleSection title="How many products?" icon={<Grid3X3 className="w-3 h-3" />} defaultOpen>
            <SelectInput
              label="Product Count"
              value={String(node.props.itemCount || 6)}
              onChange={(v) => handlePropChange('itemCount', parseInt(v))}
              options={[
                { value: '3', label: '3 Products' },
                { value: '6', label: '6 Products' },
                { value: '9', label: '9 Products' },
                { value: '12', label: '12 Products' },
                { value: '16', label: '16 Products' },
                { value: '20', label: '20 Products' },
              ]}
            />
            <SelectInput
              label="Layout"
              value={String(node.props.gridColumns || 3)}
              onChange={(v) => handlePropChange('gridColumns', parseInt(v))}
              options={[
                { value: '1', label: '1 Column' },
                { value: '2', label: '2 Columns' },
                { value: '3', label: '3 Columns' },
                { value: '4', label: '4 Columns' },
                { value: '5', label: '5 Columns' },
                { value: '6', label: '6 Columns' },
              ]}
            />
          </CollapsibleSection>

          {/* LEVEL 2: Common Controls */}
          <CollapsibleSection title="What to show?" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Product Image</label>
                <button
                  onClick={() => handlePropChange('showImage', node.props.showImage === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showImage !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showImage !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Title</label>
                <button
                  onClick={() => handlePropChange('showTitle', node.props.showTitle === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showTitle !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showTitle !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Description</label>
                <button
                  onClick={() => handlePropChange('showExcerpt', node.props.showExcerpt === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showExcerpt !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showExcerpt !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              {node.props.showExcerpt !== false && (
                <SelectInput
                  label="Description Length"
                  value={String(node.props.excerptLength || 120)}
                  onChange={(v) => handlePropChange('excerptLength', parseInt(v))}
                  options={[
                    { value: '50', label: 'Short (50 chars)' },
                    { value: '80', label: 'Medium (80 chars)' },
                    { value: '120', label: 'Default (120 chars)' },
                    { value: '160', label: 'Long (160 chars)' },
                  ]}
                />
              )}

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Price</label>
                <button
                  onClick={() => handlePropChange('showMeta', node.props.showMeta === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showMeta !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showMeta !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Action Button</label>
                <button
                  onClick={() => handlePropChange('showAction', node.props.showAction === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showAction !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showAction !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              {node.props.showAction !== false && (
                <TextInput
                  label="Button Label"
                  value={String(node.props.actionText || 'View Product')}
                  onChange={(v) => handlePropChange('actionText', v)}
                  placeholder="View Product"
                />
              )}
            </div>
          </CollapsibleSection>

          {/* LEVEL 3: Advanced - Opt-in */}
          <CollapsibleSection title="Advanced Options" icon={<Settings className="w-3 h-3" />} defaultOpen={false}>
            <div className="text-[10px] text-white/50 mb-3 pb-2 border-b border-white/10">
              Filter and sort your products
            </div>
            <div className="mb-3">
              <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Filter by Category</label>
              <div className="text-[10px] text-white/50 mb-2">
                Leave empty to show all products
              </div>
              <ProductCategorySelector
                value={(node.props.categoryIds as number[]) || []}
                onChange={(ids) => handlePropChange('categoryIds', ids)}
              />
            </div>
            <SelectInput
              label="Sort By"
              value={(node.props.orderBy as string) || 'date'}
              onChange={(v) => handlePropChange('orderBy', v)}
              options={[
                { value: 'date', label: 'Date Added' },
                { value: 'title', label: 'Name' },
                { value: 'price', label: 'Price' },
                { value: 'random', label: 'Random' },
              ]}
            />
            <SelectInput
              label="Sort Order"
              value={(node.props.order as string) || 'desc'}
              onChange={(v) => handlePropChange('order', v)}
              options={[
                { value: 'desc', label: 'Highest First' },
                { value: 'asc', label: 'Lowest First' },
              ]}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Team Grid - Progressive Disclosure */}
      {node.type === 'team_grid' && (
        <>
          {/* Summary Panel - Read-only config overview */}
          <div className="mb-3 p-3 bg-[#1a1a2e] rounded-lg border border-white/10">
            <div className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">This block currently shows</div>
            <div className="text-xs text-white/80 space-y-1">
              <div><span className="text-white/50">Content Type:</span> {(node.props.teamType as string || '').trim() !== '' ? String(node.props.teamType) : 'Auto detect'}</div>
              <div><span className="text-white/50">Source:</span> {(node.props.departmentIds as number[])?.length > 0 ? `${(node.props.departmentIds as number[]).length} departments` : 'All team members'}</div>
              <div><span className="text-white/50">Display:</span> {String(node.props.itemCount || 4)} members in {String(node.props.gridColumns || 4)} columns</div>
              <div><span className="text-white/50">Order:</span> {node.props.orderBy === 'role' ? 'By role' : node.props.orderBy === 'date' ? 'By join date' : node.props.orderBy === 'random' ? 'Random' : 'By name'}</div>
            </div>
          </div>

          {/* LEVEL 1: Essential */}
          <CollapsibleSection title="How many team members?" icon={<Grid3X3 className="w-3 h-3" />} defaultOpen>
            <SelectInput
              label="Team Count"
              value={String(node.props.itemCount || 4)}
              onChange={(v) => handlePropChange('itemCount', parseInt(v))}
              options={[
                { value: '2', label: '2 Members' },
                { value: '4', label: '4 Members' },
                { value: '6', label: '6 Members' },
                { value: '8', label: '8 Members' },
                { value: '12', label: '12 Members' },
              ]}
            />
            <SelectInput
              label="Layout"
              value={String(node.props.gridColumns || 4)}
              onChange={(v) => handlePropChange('gridColumns', parseInt(v))}
              options={[
                { value: '1', label: '1 Column' },
                { value: '2', label: '2 Columns' },
                { value: '3', label: '3 Columns' },
                { value: '4', label: '4 Columns' },
                { value: '5', label: '5 Columns' },
                { value: '6', label: '6 Columns' },
              ]}
            />
          </CollapsibleSection>

          {/* LEVEL 2: Common Controls */}
          <CollapsibleSection title="What to show?" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Photo</label>
                <button
                  onClick={() => handlePropChange('showImage', node.props.showImage === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showImage !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showImage !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Name</label>
                <button
                  onClick={() => handlePropChange('showTitle', node.props.showTitle === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showTitle !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showTitle !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Role</label>
                <button
                  onClick={() => handlePropChange('showExcerpt', node.props.showExcerpt === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showExcerpt !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showExcerpt !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>

              {node.props.showExcerpt !== false && (
                <SelectInput
                  label="Role Length"
                  value={String(node.props.excerptLength || 100)}
                  onChange={(v) => handlePropChange('excerptLength', parseInt(v))}
                  options={[
                    { value: '30', label: 'Short (30 chars)' },
                    { value: '50', label: 'Medium (50 chars)' },
                    { value: '100', label: 'Default (100 chars)' },
                    { value: '150', label: 'Long (150 chars)' },
                  ]}
                />
              )}

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Profile Button</label>
                <button
                  onClick={() => handlePropChange('showAction', node.props.showAction === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showAction !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                    }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showAction !== false ? 'translate-x-5' : 'translate-x-0'
                      }`}
                  />
                </button>
              </div>
            </div>
          </CollapsibleSection>

          {/* LEVEL 3: Advanced - Opt-in */}
          <CollapsibleSection title="Advanced Options" icon={<Settings className="w-3 h-3" />} defaultOpen={false}>
            <div className="text-[10px] text-white/50 mb-3 pb-2 border-b border-white/10">
              Choose the team content type, then filter and sort team members
            </div>
            <TextInput
              label="Team Content Type"
              value={node.props.teamType as string || ''}
              onChange={(v) => handlePropChange('teamType', v)}
              placeholder="team_member"
            />
            <div className="text-[10px] text-white/50 -mt-2 mb-3">
              Leave empty to auto-detect common team types like team_member or team.
            </div>
            <div className="mb-3">
              <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Filter by Department</label>
              <div className="text-[10px] text-white/50 mb-2">
                Uses CMS categories and excludes product taxonomy categories.
              </div>
              <DepartmentSelector
                value={(node.props.departmentIds as number[]) || []}
                onChange={(ids) => handlePropChange('departmentIds', ids)}
              />
            </div>
            <SelectInput
              label="Sort By"
              value={(node.props.orderBy as string) || 'name'}
              onChange={(v) => handlePropChange('orderBy', v)}
              options={[
                { value: 'name', label: 'Name' },
                { value: 'role', label: 'Role' },
                { value: 'date', label: 'Date Joined' },
                { value: 'random', label: 'Random' },
              ]}
            />
            <SelectInput
              label="Sort Order"
              value={(node.props.order as string) || 'asc'}
              onChange={(v) => handlePropChange('order', v)}
              options={[
                { value: 'asc', label: 'A to Z' },
                { value: 'desc', label: 'Z to A' },
              ]}
            />
          </CollapsibleSection>
        </>
      )}

      {node.type === 'entity_view' && (
        <>
          <div className="mb-3 p-3 bg-[#1a1a2e] rounded-lg border border-white/10">
            <div className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">This block currently shows</div>
            <div className="text-xs text-white/80 space-y-1">
              <div><span className="text-white/50">Context:</span> The current entity page</div>
              <div><span className="text-white/50">Modules:</span> {node.props.showPricing !== false || node.props.showInventory !== false ? 'Content plus capability data' : 'Content only'}</div>
            </div>
          </div>

          <CollapsibleSection title="What to show?" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Media</label>
                <button
                  onClick={() => handlePropChange('showFeaturedImage', node.props.showFeaturedImage === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showFeaturedImage !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showFeaturedImage !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Title</label>
                <button
                  onClick={() => handlePropChange('showTitle', node.props.showTitle === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showTitle !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showTitle !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Meta</label>
                <button
                  onClick={() => handlePropChange('showMeta', node.props.showMeta === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showMeta !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showMeta !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              {node.props.showMeta !== false && (
                <>
                  <div className="flex items-center justify-between">
                    <label className="text-xs text-white/70">Show Type Label</label>
                    <button
                      onClick={() => handlePropChange('showTypeLabel', node.props.showTypeLabel === false ? true : false)}
                      className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showTypeLabel !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                    >
                      <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showTypeLabel !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                    </button>
                  </div>

                  <div className="flex items-center justify-between">
                    <label className="text-xs text-white/70">Show Author</label>
                    <button
                      onClick={() => handlePropChange('showAuthor', node.props.showAuthor === false ? true : false)}
                      className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showAuthor !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                    >
                      <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showAuthor !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                    </button>
                  </div>

                  <div className="flex items-center justify-between">
                    <label className="text-xs text-white/70">Show Publish Date</label>
                    <button
                      onClick={() => handlePropChange('showDate', node.props.showDate === false ? true : false)}
                      className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showDate !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                    >
                      <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showDate !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                    </button>
                  </div>
                </>
              )}

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Pricing</label>
                <button
                  onClick={() => handlePropChange('showPricing', node.props.showPricing === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showPricing !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showPricing !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Inventory</label>
                <button
                  onClick={() => handlePropChange('showInventory', node.props.showInventory === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showInventory !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showInventory !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              {node.props.showInventory !== false && (
                <div className="flex items-center justify-between">
                  <label className="text-xs text-white/70">Show SKU</label>
                  <button
                    onClick={() => handlePropChange('showSku', node.props.showSku === false ? true : false)}
                    className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showSku !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                  >
                    <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showSku !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                  </button>
                </div>
              )}

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Progress</label>
                <button
                  onClick={() => handlePropChange('showProgress', node.props.showProgress === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showProgress !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showProgress !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Lessons</label>
                <button
                  onClick={() => handlePropChange('showLessons', node.props.showLessons === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showLessons !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showLessons !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Actions</label>
                <button
                  onClick={() => handlePropChange('showActions', node.props.showActions === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showActions !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showActions !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Body</label>
                <button
                  onClick={() => handlePropChange('showBody', node.props.showBody === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showBody !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showBody !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>
            </div>
          </CollapsibleSection>
        </>
      )}

      {node.type === 'entity_list' && (
        <>
          <div className="mb-3 p-3 bg-[#1a1a2e] rounded-lg border border-white/10">
            <div className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-2">This block currently shows</div>
            <div className="text-xs text-white/80 space-y-1">
              <div><span className="text-white/50">Entity Type:</span> {String(node.props.entityType || 'post')}</div>
              <div><span className="text-white/50">Display:</span> {String(node.props.itemCount || 6)} items in {String(node.props.layout || 'grid')} mode{String(node.props.layout || 'grid') !== 'list' ? `, ${String(node.props.gridColumns || 3)} columns` : ''}</div>
              <div><span className="text-white/50">Order:</span> {node.props.orderBy === 'title' || node.props.orderBy === 'name' ? 'By name' : 'Newest first'}</div>
            </div>
          </div>

          <CollapsibleSection title="Source" icon={<Layers className="w-3 h-3" />} defaultOpen>
            <TextInput
              label="Entity Type Slug"
              value={String(node.props.entityType || 'post')}
              onChange={(v) => handlePropChange('entityType', v.trim() || 'post')}
              placeholder="post"
            />
            <SelectInput
              label="Layout"
              value={String(node.props.layout || 'grid')}
              onChange={(v) => handlePropChange('layout', v)}
              options={[
                { value: 'grid', label: 'Grid' },
                { value: 'list', label: 'List' },
              ]}
            />
            <SelectInput
              label="Item Count"
              value={String(node.props.itemCount || 6)}
              onChange={(v) => handlePropChange('itemCount', parseInt(v))}
              options={[
                { value: '3', label: '3 Items' },
                { value: '6', label: '6 Items' },
                { value: '9', label: '9 Items' },
                { value: '12', label: '12 Items' },
              ]}
            />
            {String(node.props.layout || 'grid') !== 'list' && (
              <SelectInput
                label="Columns"
                value={String(node.props.gridColumns || 3)}
                onChange={(v) => handlePropChange('gridColumns', parseInt(v))}
                options={[
                  { value: '1', label: '1 Column' },
                  { value: '2', label: '2 Columns' },
                  { value: '3', label: '3 Columns' },
                  { value: '4', label: '4 Columns' },
                  { value: '5', label: '5 Columns' },
                  { value: '6', label: '6 Columns' },
                ]}
              />
            )}
          </CollapsibleSection>

          <CollapsibleSection title="What to show?" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Featured Image</label>
                <button
                  onClick={() => handlePropChange('showFeaturedImage', node.props.showFeaturedImage === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showFeaturedImage !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showFeaturedImage !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Title</label>
                <button
                  onClick={() => handlePropChange('showTitle', node.props.showTitle === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showTitle !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showTitle !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Excerpt</label>
                <button
                  onClick={() => handlePropChange('showExcerpt', node.props.showExcerpt === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showExcerpt !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showExcerpt !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              {node.props.showExcerpt !== false && (
                <SelectInput
                  label="Excerpt Length"
                  value={String(node.props.excerptLength || 120)}
                  onChange={(v) => handlePropChange('excerptLength', parseInt(v))}
                  options={[
                    { value: '60', label: 'Short (60 chars)' },
                    { value: '90', label: 'Medium (90 chars)' },
                    { value: '120', label: 'Default (120 chars)' },
                    { value: '160', label: 'Long (160 chars)' },
                  ]}
                />
              )}

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Pricing</label>
                <button
                  onClick={() => handlePropChange('showPricing', node.props.showPricing === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showPricing !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showPricing !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>

              <div className="flex items-center justify-between">
                <label className="text-xs text-white/70">Show Inventory</label>
                <button
                  onClick={() => handlePropChange('showInventory', node.props.showInventory === false ? true : false)}
                  className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showInventory !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
                >
                  <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showInventory !== false ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>
            </div>
          </CollapsibleSection>

          <CollapsibleSection title="Advanced Options" icon={<Settings className="w-3 h-3" />} defaultOpen={false}>
            <SelectInput
              label="Sort By"
              value={String(node.props.orderBy || 'date')}
              onChange={(v) => handlePropChange('orderBy', v)}
              options={[
                { value: 'date', label: 'Date Added' },
                { value: 'title', label: 'Name' },
              ]}
            />
            <SelectInput
              label="Sort Order"
              value={String(node.props.order || 'desc')}
              onChange={(v) => handlePropChange('order', v)}
              options={[
                { value: 'desc', label: 'Newest First' },
                { value: 'asc', label: 'Oldest First' },
              ]}
            />
            <TextInput
              label="Empty State Message"
              value={String(node.props.emptyMessage || 'No items found.')}
              onChange={(v) => handlePropChange('emptyMessage', v)}
              placeholder="No items found."
            />
          </CollapsibleSection>
        </>
      )}

      {/* Icon */}
      {node.type === 'icon' && (
        <CollapsibleSection title="Icon" icon={<Star className="w-3 h-3" />}>
          <SelectInput
            label="Icon"
            value={node.props.icon as string || 'Star'}
            onChange={(v) => handlePropChange('icon', v)}
            options={[
              { value: 'Star', label: 'Star' },
              { value: 'Heart', label: 'Heart' },
              { value: 'Check', label: 'Check' },
              { value: 'ArrowRight', label: 'Arrow Right' },
              { value: 'Mail', label: 'Mail' },
              { value: 'Phone', label: 'Phone' },
              { value: 'MapPin', label: 'Map Pin' },
              { value: 'Zap', label: 'Zap' },
              { value: 'Shield', label: 'Shield' },
              { value: 'Clock', label: 'Clock' },
            ]}
          />
          <SelectInput
            label="Size"
            value={node.props.size as string || '24'}
            onChange={(v) => handlePropChange('size', v)}
            options={[
              { value: '16', label: 'SM (16px)' },
              { value: '20', label: 'MD (20px)' },
              { value: '24', label: 'LG (24px)' },
              { value: '32', label: 'XL (32px)' },
              { value: '48', label: '2XL (48px)' },
              { value: '64', label: '3XL (64px)' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Icon Box */}
      {node.type === 'icon_box' && (
        <CollapsibleSection title="Icon Box" icon={<Star className="w-3 h-3" />}>
          <SelectInput
            label="Icon"
            value={node.props.icon as string || 'Star'}
            onChange={(v) => handlePropChange('icon', v)}
            options={[
              { value: 'Star', label: 'Star' },
              { value: 'Heart', label: 'Heart' },
              { value: 'Check', label: 'Check' },
              { value: 'Zap', label: 'Zap' },
              { value: 'Shield', label: 'Shield' },
              { value: 'Clock', label: 'Clock' },
            ]}
          />
          <TextInput
            label="Title"
            value={node.props.title as string || ''}
            onChange={(v) => handlePropChange('title', v)}
            placeholder="Feature Title"
          />
          <TextAreaInput
            label="Description"
            value={node.props.description as string || ''}
            onChange={(v) => handlePropChange('description', v)}
            placeholder="Feature description..."
          />
        </CollapsibleSection>
      )}

      {/* Pricing Table */}
      {node.type === 'pricing_table' && (
        <>
          <CollapsibleSection title="Pricing Content" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <TextInput
              label="Plan Name"
              value={node.props.planName as string || ''}
              onChange={(v) => handlePropChange('planName', v)}
              placeholder="Professional"
            />
            <div className="grid grid-cols-3 gap-2">
              <TextInput
                label="Currency"
                value={node.props.currency as string || '$'}
                onChange={(v) => handlePropChange('currency', v)}
                placeholder="$"
              />
              <TextInput
                label="Price"
                value={node.props.price as string || ''}
                onChange={(v) => handlePropChange('price', v)}
                placeholder="49"
              />
              <TextInput
                label="Period"
                value={node.props.period as string || '/month'}
                onChange={(v) => handlePropChange('period', v)}
                placeholder="/month"
              />
            </div>
            <div className="flex items-center gap-2 mt-3">
              <input
                type="checkbox"
                id="pricing-highlighted"
                checked={Boolean(node.props.highlighted)}
                onChange={(e) => handlePropChange('highlighted', e.target.checked)}
                className="w-3 h-3"
              />
              <label htmlFor="pricing-highlighted" className="text-[10px] text-white/70">Highlight this plan</label>
            </div>
            {Boolean(node.props.highlighted) && (
              <TextInput
                label="Ribbon Label"
                value={node.props.ribbon as string || ''}
                onChange={(v) => handlePropChange('ribbon', v)}
                placeholder="Most Popular"
              />
            )}
          </CollapsibleSection>

          <CollapsibleSection title="Features" icon={<List className="w-3 h-3" />} defaultOpen>
            <div className="space-y-3">
              {normalizePricingFeatures((node.props.features as any[]) || []).map((feature, index) => (
                <div key={feature.id} className="p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded space-y-2">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-medium text-white/60">Feature {index + 1}</span>
                    <button
                      onClick={() => handlePropChange('features', normalizePricingFeatures(((node.props.features as any[]) || []).filter((_: any, i: number) => i !== index)))}
                      className="p-1 text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded transition-colors"
                      title="Delete feature"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </div>
                  <TextInput
                    label="Text"
                    value={feature.text}
                    onChange={(value) => {
                      const features = normalizePricingFeatures((node.props.features as any[]) || []);
                      features[index] = { ...features[index], text: value };
                      handlePropChange('features', features);
                    }}
                    placeholder="Feature description"
                  />
                  <div className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      id={`pricing-feature-${feature.id}`}
                      checked={feature.included}
                      onChange={(e) => {
                        const features = normalizePricingFeatures((node.props.features as any[]) || []);
                        features[index] = { ...features[index], included: e.target.checked };
                        handlePropChange('features', features);
                      }}
                      className="w-3 h-3"
                    />
                    <label htmlFor={`pricing-feature-${feature.id}`} className="text-[10px] text-white/70">Included</label>
                  </div>
                </div>
              ))}
              <button
                onClick={() => {
                  const features = normalizePricingFeatures((node.props.features as any[]) || []);
                  features.push({ id: createRepeaterId('feature'), text: `Feature ${features.length + 1}`, included: true });
                  handlePropChange('features', features);
                }}
                className="w-full px-3 py-2 bg-[#0078d4] text-white text-xs font-medium hover:bg-[#006cbd] transition-colors rounded flex items-center justify-center gap-2"
              >
                <Plus className="w-3 h-3" />
                Add Feature
              </button>
            </div>
          </CollapsibleSection>

          <CollapsibleSection title="Call To Action" icon={<Link className="w-3 h-3" />}>
            <TextInput
              label="Button Text"
              value={node.props.buttonText as string || 'Get Started'}
              onChange={(v) => handlePropChange('buttonText', v)}
              placeholder="Get Started"
            />
            <TextInput
              label="Button URL"
              value={node.props.buttonUrl as string || '#'}
              onChange={(v) => handlePropChange('buttonUrl', v)}
              placeholder="#"
            />
          </CollapsibleSection>
        </>
      )}

      {/* Tabs */}
      {node.type === 'tabs' && (
        <CollapsibleSection title="Tabs" icon={<List className="w-3 h-3" />}>
          <div className="space-y-3">
            {normalizeTabs((node.props.tabs as any[]) || []).map((tab, index) => (
              <div key={tab.id} className="p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-white/60">Tab {index + 1}</span>
                  <button
                    onClick={() => handlePropChange('tabs', normalizeTabs(((node.props.tabs as any[]) || []).filter((_: any, i: number) => i !== index)))}
                    className="p-1 text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded transition-colors"
                    title="Delete tab"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </div>
                <TextInput
                  label="Label"
                  value={tab.label}
                  onChange={(value) => {
                    const tabs = normalizeTabs((node.props.tabs as any[]) || []);
                    tabs[index] = { ...tabs[index], label: value };
                    handlePropChange('tabs', tabs);
                  }}
                  placeholder="Tab label"
                />
                <TextAreaInput
                  label="Content"
                  value={tab.content}
                  onChange={(value) => {
                    const tabs = normalizeTabs((node.props.tabs as any[]) || []);
                    tabs[index] = { ...tabs[index], content: value };
                    handlePropChange('tabs', tabs);
                  }}
                  placeholder="Tab content"
                  rows={4}
                />
              </div>
            ))}
            <button
              onClick={() => {
                const tabs = normalizeTabs((node.props.tabs as any[]) || []);
                tabs.push({ id: createRepeaterId('tab'), label: `Tab ${tabs.length + 1}`, content: '' });
                handlePropChange('tabs', tabs);
              }}
              className="w-full px-3 py-2 bg-[#0078d4] text-white text-xs font-medium hover:bg-[#006cbd] transition-colors rounded flex items-center justify-center gap-2"
            >
              <Plus className="w-3 h-3" />
              Add Tab
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Accordion */}
      {node.type === 'accordion' && (
        <CollapsibleSection title="Accordion" icon={<ChevronDown className="w-3 h-3" />}>
          <div className="flex items-center gap-2 mb-3">
            <input
              type="checkbox"
              id="allowMultiple"
              checked={node.props.allowMultiple as boolean || false}
              onChange={(e) => handlePropChange('allowMultiple', e.target.checked)}
              className="w-3 h-3"
            />
            <label htmlFor="allowMultiple" className="text-[10px] text-white/70">Allow multiple open</label>
          </div>
          <div className="space-y-3">
            {normalizeAccordionItems((node.props.items as any[]) || []).map((item, index) => (
              <div key={item.id} className="p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-white/60">Item {index + 1}</span>
                  <button
                    onClick={() => handlePropChange('items', normalizeAccordionItems(((node.props.items as any[]) || []).filter((_: any, i: number) => i !== index)))}
                    className="p-1 text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded transition-colors"
                    title="Delete item"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </div>
                <TextInput
                  label="Title"
                  value={item.title}
                  onChange={(value) => {
                    const items = normalizeAccordionItems((node.props.items as any[]) || []);
                    items[index] = { ...items[index], title: value };
                    handlePropChange('items', items);
                  }}
                  placeholder="Item title"
                />
                <TextAreaInput
                  label="Content"
                  value={item.content}
                  onChange={(value) => {
                    const items = normalizeAccordionItems((node.props.items as any[]) || []);
                    items[index] = { ...items[index], content: value };
                    handlePropChange('items', items);
                  }}
                  placeholder="Accordion content"
                  rows={4}
                />
                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id={`accordion-open-${item.id}`}
                    checked={item.isOpen}
                    onChange={(e) => {
                      const items = normalizeAccordionItems((node.props.items as any[]) || []);
                      items[index] = { ...items[index], isOpen: e.target.checked };
                      handlePropChange('items', items);
                    }}
                    className="w-3 h-3"
                  />
                  <label htmlFor={`accordion-open-${item.id}`} className="text-[10px] text-white/70">Open by default</label>
                </div>
              </div>
            ))}
            <button
              onClick={() => {
                const items = normalizeAccordionItems((node.props.items as any[]) || []);
                items.push({ id: createRepeaterId('item'), title: `Item ${items.length + 1}`, content: '', isOpen: false });
                handlePropChange('items', items);
              }}
              className="w-full px-3 py-2 bg-[#0078d4] text-white text-xs font-medium hover:bg-[#006cbd] transition-colors rounded flex items-center justify-center gap-2"
            >
              <Plus className="w-3 h-3" />
              Add Item
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Social Icons */}
      {node.type === 'social_icons' && (
        <CollapsibleSection title="Social Icons" icon={<Share2 className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Icon Size"
            value={node.props.size as string || '24'}
            onChange={(v) => handlePropChange('size', v)}
            placeholder="24"
          />
          <div className="space-y-3">
            {((node.props.icons as any[]) || []).map((icon: any, index: number) => (
              <div key={index} className="p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded space-y-2">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs font-medium text-white/60">Icon {index + 1}</span>
                  <button
                    onClick={() => {
                      const icons = [...((node.props.icons as any[]) || [])];
                      icons.splice(index, 1);
                      handlePropChange('icons', icons);
                    }}
                    className="p-1 text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded transition-colors"
                    title="Remove icon"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </div>
                <SelectInput
                  label="Platform"
                  value={icon.platform || 'facebook'}
                  onChange={(v) => {
                    const icons = [...((node.props.icons as any[]) || [])];
                    icons[index] = { ...icons[index], platform: v };
                    handlePropChange('icons', icons);
                  }}
                  options={[
                    { value: 'facebook', label: 'Facebook' },
                    { value: 'twitter', label: 'Twitter / X' },
                    { value: 'instagram', label: 'Instagram' },
                    { value: 'linkedin', label: 'LinkedIn' },
                    { value: 'youtube', label: 'YouTube' },
                    { value: 'tiktok', label: 'TikTok' },
                    { value: 'github', label: 'GitHub' },
                    { value: 'pinterest', label: 'Pinterest' },
                    { value: 'whatsapp', label: 'WhatsApp' },
                    { value: 'email', label: 'Email' },
                  ]}
                />
                <TextInput
                  label="URL"
                  value={icon.url || ''}
                  onChange={(v) => {
                    const icons = [...((node.props.icons as any[]) || [])];
                    icons[index] = { ...icons[index], url: v };
                    handlePropChange('icons', icons);
                  }}
                  placeholder="https://..."
                />
              </div>
            ))}
          </div>
          <button
            onClick={() => {
              const icons = [...((node.props.icons as any[]) || []), { platform: 'facebook', url: '#' }];
              handlePropChange('icons', icons);
            }}
            className="w-full mt-2 px-3 py-2 text-xs bg-[#0078d4]/20 text-[#0078d4] border border-[#0078d4]/30 hover:bg-[#0078d4]/30 rounded transition-colors flex items-center justify-center gap-1"
          >
            <Plus className="w-3 h-3" /> Add Social Icon
          </button>
        </CollapsibleSection>
      )}

      {/* List */}
      {node.type === 'list' && (
        <CollapsibleSection title="List" icon={<List className="w-3 h-3" />}>
          <SelectInput
            label="List Type"
            value={node.props.listType as string || 'bullet'}
            onChange={(v) => handlePropChange('listType', v)}
            options={[
              { value: 'bullet', label: 'Bullet' },
              { value: 'number', label: 'Numbered' },
              { value: 'check', label: 'Checkmarks' },
            ]}
          />
          <TextAreaInput
            label="Items (one per line)"
            value={(node.props.items as string[] || []).join('\n')}
            onChange={(v) => handlePropChange('items', v.split('\n').filter(Boolean))}
            placeholder="Item 1&#10;Item 2&#10;Item 3"
          />
        </CollapsibleSection>
      )}

      {/* Gallery */}
      {node.type === 'gallery' && (
        <>
          {/* LEVEL 1: Summary - Always Visible */}
          <CollapsibleSection
            title="This block currently shows"
            icon={<Grid3X3 className="w-3 h-3" />}
            defaultOpen={true}
          >
            <div className="text-[10px] text-white/50 mb-3">
              <strong>Layout:</strong> {node.props.columns || 3} columns<br />
              <strong>Images:</strong> {normalizeGalleryImages((node.props.images as any[]) || []).length} images<br />
              <strong>Lightbox:</strong> {node.props.lightbox !== false ? 'Enabled' : 'Disabled'}
            </div>
          </CollapsibleSection>

          {/* LEVEL 2: Content - Essential Settings */}
          <CollapsibleSection
            title="Gallery Content"
            icon={<Grid3X3 className="w-3 h-3" />}
            defaultOpen={true}
          >
            <div className="text-[10px] text-white/50 mb-3 pb-2 border-b border-white/10">
              Add and arrange your images
            </div>

            <div className="mb-3">
              <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">
                Images
              </label>
              <div className="text-[10px] text-white/50 mb-2">
                Click to open media library and select images
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setIsMediaPickerOpen(true)}
                  className="flex-1 px-3 py-2 bg-[#0078d4] text-white text-sm rounded hover:bg-[#106ebe] transition-colors"
                >
                  Add Images
                </button>
                {normalizeGalleryImages((node.props.images as any[]) || []).length > 0 && (
                  <button
                    onClick={() => handlePropChange('images', [])}
                    className="px-3 py-2 bg-[#d83b01] text-white text-sm rounded hover:bg-[#b32b00] transition-colors"
                  >
                    Clear All
                  </button>
                )}
              </div>
              <div className="mt-2 text-[10px] text-white/50">
                {normalizeGalleryImages((node.props.images as any[]) || []).length} image(s) selected
              </div>

              {/* Show selected images */}
              {(node.props.images as any[] || []).length > 0 && (
                <div className="mt-3 p-2 bg-[#2d2d2d] rounded">
                  <div className="text-[10px] text-white/70 mb-2">Selected Images:</div>
                  <div className="space-y-1">
                    {normalizeGalleryImages((node.props.images as any[]) || []).map((img: any, index: number) => (
                      <div key={img.id} className="flex items-center justify-between text-[10px] text-white/50">
                        <span className="truncate flex-1">{index + 1}. {img.alt || 'Untitled'}</span>
                        <button
                          onClick={() => {
                            const currentImages = normalizeGalleryImages((node.props.images as any[]) || []);
                            const updatedImages = normalizeGalleryImages(currentImages.filter((_: any, i: number) => i !== index));
                            handlePropChange('images', updatedImages);
                          }}
                          className="text-[#d83b01] hover:text-[#b32b00] ml-2"
                        >
                          Remove
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            <TextInput
              label="Columns"
              value={String(node.props.columns as number || 3)}
              onChange={(v) => handlePropChange('columns', parseInt(v) || 3)}
              placeholder="3"
            />
          </CollapsibleSection>

          {/* LEVEL 3: Display - Opt-in Settings */}
          <CollapsibleSection
            title="Display Options"
            icon={<Settings className="w-3 h-3" />}
            defaultOpen={false}
          >
            <div className="text-[10px] text-white/50 mb-3 pb-2 border-b border-white/10">
              Customize how images are displayed
            </div>

            <div className="mb-3">
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="gallery-lightbox"
                  checked={node.props.lightbox !== false}
                  onChange={(e) => handlePropChange('lightbox', e.target.checked)}
                  className="w-3 h-3"
                />
                <label htmlFor="gallery-lightbox" className="text-[10px] text-white/70">
                  Enable lightbox (click to enlarge)
                </label>
              </div>
            </div>

            <TextInput
              label="Gap (px)"
              value={String(node.props.gap as number || 16)}
              onChange={(v) => handlePropChange('gap', parseInt(v) || 16)}
              placeholder="16"
            />

            <SelectInput
              label="Image Aspect Ratio"
              value={node.props.aspectRatio as string || 'auto'}
              onChange={(v) => handlePropChange('aspectRatio', v)}
              options={[
                { value: 'auto', label: 'Original' },
                { value: '1/1', label: 'Square (1:1)' },
                { value: '16/9', label: 'Widescreen (16:9)' },
                { value: '4/3', label: 'Standard (4:3)' },
                { value: '3/2', label: 'Classic (3:2)' },
              ]}
            />

            <SelectInput
              label="Layout"
              value={node.props.layout as string || 'grid'}
              onChange={(v) => handlePropChange('layout', v)}
              options={[
                { value: 'grid', label: 'Grid' },
                { value: 'masonry', label: 'Masonry' },
              ]}
            />

            <SelectInput
              label="Image Size"
              value={node.props.imageSize as string || 'medium'}
              onChange={(v) => handlePropChange('imageSize', v)}
              options={[
                { value: 'thumbnail', label: 'Thumbnail (1:1)' },
                { value: 'small', label: 'Small (4:3)' },
                { value: 'medium', label: 'Medium (16:9)' },
                { value: 'large', label: 'Large (21:9)' },
                { value: 'full', label: 'Full Size (Original)' },
              ]}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Counter */}
      {node.type === 'counter' && (
        <CollapsibleSection title="Counter" icon={<Hash className="w-3 h-3" />} defaultOpen>
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Start Value"
              value={node.props.startValue as string || '0'}
              onChange={(v) => handlePropChange('startValue', v)}
              placeholder="0"
            />
            <TextInput
              label="End Value"
              value={node.props.endValue as string || '100'}
              onChange={(v) => handlePropChange('endValue', v)}
              placeholder="100"
            />
          </div>
          <TextInput
            label="Duration (ms)"
            value={node.props.duration as string || '2000'}
            onChange={(v) => handlePropChange('duration', v)}
            placeholder="2000"
          />
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Prefix"
              value={node.props.prefix as string || ''}
              onChange={(v) => handlePropChange('prefix', v)}
              placeholder="$"
            />
            <TextInput
              label="Suffix"
              value={node.props.suffix as string || ''}
              onChange={(v) => handlePropChange('suffix', v)}
              placeholder="%"
            />
          </div>
          <TextInput
            label="Title"
            value={node.props.title as string || ''}
            onChange={(v) => handlePropChange('title', v)}
            placeholder="Counter Title"
          />
        </CollapsibleSection>
      )}

      {/* Progress Bar */}
      {node.type === 'progress' && (
        <CollapsibleSection title="Progress Bar" icon={<Layers className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Label"
            value={node.props.label as string || ''}
            onChange={(v) => handlePropChange('label', v)}
            placeholder="Progress Label"
          />
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Value (0-100)"
              value={node.props.value as string || '50'}
              onChange={(v) => handlePropChange('value', v)}
              placeholder="50"
            />
            <TextInput
              label="Max"
              value={node.props.max as string || '100'}
              onChange={(v) => handlePropChange('max', v)}
              placeholder="100"
            />
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Show Value</label>
            <button
              onClick={() => handlePropChange('showValue', !(node.props.showValue !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showValue !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showValue !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <ColorInput
            label="Bar Color"
            value={node.props.color as string || '#0078d4'}
            onChange={(v) => handlePropChange('color', v)}
          />
        </CollapsibleSection>
      )}

      {/* Testimonial */}
      {node.type === 'testimonial' && (
        <CollapsibleSection title="Testimonial" icon={<MessageSquare className="w-3 h-3" />} defaultOpen>
          <TextAreaInput
            label="Quote"
            value={node.props.quote as string || ''}
            onChange={(v) => handlePropChange('quote', v)}
            placeholder="What the customer said..."
            rows={3}
          />
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Author"
              value={node.props.author as string || ''}
              onChange={(v) => handlePropChange('author', v)}
              placeholder="John Doe"
            />
            <TextInput
              label="Role"
              value={node.props.role as string || ''}
              onChange={(v) => handlePropChange('role', v)}
              placeholder="CEO, Company"
            />
          </div>
          <TextInput
            label="Avatar URL"
            value={node.props.avatar as string || ''}
            onChange={(v) => handlePropChange('avatar', v)}
            placeholder="https://..."
          />
          <SelectInput
            label="Rating"
            value={node.props.rating as string || '5'}
            onChange={(v) => handlePropChange('rating', v)}
            options={[
              { value: '1', label: '1 Star' },
              { value: '2', label: '2 Stars' },
              { value: '3', label: '3 Stars' },
              { value: '4', label: '4 Stars' },
              { value: '5', label: '5 Stars' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Form */}
      {node.type === 'form' && (
        <>
          <CollapsibleSection title="Form Settings" icon={<Settings className="w-3 h-3" />} defaultOpen>
            <TextInput
              label="Submit Button Text"
              value={node.props.submitText as string || 'Submit'}
              onChange={(v) => handlePropChange('submitText', v)}
              placeholder="Submit"
            />
            <TextInput
              label="Success Message"
              value={node.props.successMessage as string || 'Thank you!'}
              onChange={(v) => handlePropChange('successMessage', v)}
              placeholder="Thank you for your submission!"
            />
          </CollapsibleSection>
          <CollapsibleSection title="Form Fields" icon={<List className="w-3 h-3" />}>
            <TextAreaInput
              label="Fields (JSON)"
              value={JSON.stringify(node.props.fields || [], null, 2)}
              onChange={(v) => {
                try {
                  const parsed = JSON.parse(v);
                  handlePropChange('fields', parsed);
                } catch {
                  // Invalid JSON, ignore
                }
              }}
              placeholder='[{"label":"Name","type":"text","placeholder":"Your name","required":true,"id":"name"}]'
              rows={6}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Map */}
      {node.type === 'map' && (
        <CollapsibleSection title="Map" icon={<Navigation className="w-3 h-3" />} defaultOpen>
          <SelectInput
            label="Map Source"
            value={node.props.mapType as string || 'embed'}
            onChange={(v) => handlePropChange('mapType', v)}
            options={[
              { value: 'embed', label: 'Custom Embed URL' },
              { value: 'openstreetmap', label: 'Coordinates (OpenStreetMap)' },
              { value: 'google', label: 'Google / External Embed' },
            ]}
          />
          {((node.props.mapType as string) || 'embed') !== 'openstreetmap' ? (
            <TextInput
              label="Embed URL"
              value={node.props.embedUrl as string || ''}
              onChange={(v) => handlePropChange('embedUrl', v)}
              placeholder="https://www.google.com/maps/embed?..."
            />
          ) : (
            <>
              <div className="grid grid-cols-2 gap-2">
                <TextInput
                  label="Latitude"
                  value={node.props.latitude as string || '14.5995'}
                  onChange={(v) => handlePropChange('latitude', v)}
                  placeholder="14.5995"
                />
                <TextInput
                  label="Longitude"
                  value={node.props.longitude as string || '120.9842'}
                  onChange={(v) => handlePropChange('longitude', v)}
                  placeholder="120.9842"
                />
              </div>
              <TextInput
                label="Marker Title"
                value={node.props.markerTitle as string || 'Our Location'}
                onChange={(v) => handlePropChange('markerTitle', v)}
                placeholder="Our Location"
              />
            </>
          )}
          <SelectInput
            label="Zoom Level"
            value={String(node.props.zoom ?? '14')}
            onChange={(v) => handlePropChange('zoom', v)}
            options={[
              { value: '8', label: '8 - Region' },
              { value: '10', label: '10 - City' },
              { value: '12', label: '12 - Neighborhood' },
              { value: '14', label: '14 - Street' },
              { value: '16', label: '16 - Building' },
              { value: '18', label: '18 - Close-up' },
            ]}
          />
          <SelectInput
            label="Height"
            value={node.props.height as string || '300px'}
            onChange={(v) => handlePropChange('height', v)}
            options={[
              { value: '200px', label: 'Small (200px)' },
              { value: '300px', label: 'Medium (300px)' },
              { value: '400px', label: 'Large (400px)' },
              { value: '500px', label: 'X-Large (500px)' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Table */}
      {node.type === 'table' && (
        <CollapsibleSection title="Table" icon={<Grid3X3 className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Headers (comma-separated)"
            value={Array.isArray(node.props.headers) ? (node.props.headers as string[]).join(', ') : String(node.props.headers || '')}
            onChange={(v) => handlePropChange('headers', v)}
            placeholder="Name, Price, Qty"
          />
          <TextAreaInput
            label="Rows (JSON)"
            value={typeof node.props.rows === 'string' ? node.props.rows as string : JSON.stringify(node.props.rows || [], null, 2)}
            onChange={(v) => {
              try {
                const parsed = JSON.parse(v);
                handlePropChange('rows', parsed);
              } catch {
                handlePropChange('rows', v);
              }
            }}
            placeholder='[["Item 1","$10","2"],["Item 2","$20","1"]]'
            rows={4}
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Striped Rows</label>
            <button
              onClick={() => handlePropChange('striped', !node.props.striped)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.striped ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.striped ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Bordered</label>
            <button
              onClick={() => handlePropChange('bordered', !node.props.bordered)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.bordered ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.bordered ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Alert */}
      {node.type === 'alert' && (
        <CollapsibleSection title="Alert" icon={<AlertTriangle className="w-3 h-3" />} defaultOpen>
          <TextAreaInput
            label="Content"
            value={node.props.content as string || ''}
            onChange={(v) => handlePropChange('content', v)}
            placeholder="Alert message..."
            rows={2}
          />
          <SelectInput
            label="Type"
            value={node.props.alertType as string || 'info'}
            onChange={(v) => handlePropChange('alertType', v)}
            options={[
              { value: 'info', label: 'Info' },
              { value: 'success', label: 'Success' },
              { value: 'warning', label: 'Warning' },
              { value: 'error', label: 'Error' },
            ]}
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Dismissible</label>
            <button
              onClick={() => handlePropChange('dismissible', !node.props.dismissible)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.dismissible ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.dismissible ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Anchor */}
      {node.type === 'anchor' && (
        <CollapsibleSection title="Anchor" icon={<Hash className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Anchor ID"
            value={node.props.anchorId as string || ''}
            onChange={(v) => handlePropChange('anchorId', v)}
            placeholder="my-section"
          />
        </CollapsibleSection>
      )}

      {/* Countdown */}
      {node.type === 'countdown' && (
        <>
          <CollapsibleSection title="Countdown" icon={<Clock className="w-3 h-3" />} defaultOpen>
            <div className="mb-3">
              <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Target Date</label>
              <input
                type="datetime-local"
                value={node.props.targetDate as string || ''}
                onChange={(e) => handlePropChange('targetDate', e.target.value)}
                className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 focus:outline-none focus:border-[#0078d4]"
              />
            </div>
            <TextInput
              label="Expired Message"
              value={node.props.expiredMessage as string || 'Expired!'}
              onChange={(v) => handlePropChange('expiredMessage', v)}
              placeholder="Time's up!"
            />
          </CollapsibleSection>
          <CollapsibleSection title="Labels & Visibility" icon={<Settings className="w-3 h-3" />}>
            <div className="flex items-center justify-between">
              <label className="text-xs text-white/70">Show Days</label>
              <button
                onClick={() => handlePropChange('showDays', !(node.props.showDays !== false))}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showDays !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showDays !== false ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
            {node.props.showDays !== false && (
              <TextInput
                label="Days Label"
                value={(node.props.labels as any)?.days as string || 'Days'}
                onChange={(v) => handlePropChange('labels', { ...(node.props.labels as object || {}), days: v })}
                placeholder="Days"
              />
            )}
            <div className="flex items-center justify-between">
              <label className="text-xs text-white/70">Show Hours</label>
              <button
                onClick={() => handlePropChange('showHours', !(node.props.showHours !== false))}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showHours !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showHours !== false ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
            {node.props.showHours !== false && (
              <TextInput
                label="Hours Label"
                value={(node.props.labels as any)?.hours as string || 'Hours'}
                onChange={(v) => handlePropChange('labels', { ...(node.props.labels as object || {}), hours: v })}
                placeholder="Hours"
              />
            )}
            <div className="flex items-center justify-between">
              <label className="text-xs text-white/70">Show Minutes</label>
              <button
                onClick={() => handlePropChange('showMinutes', !(node.props.showMinutes !== false))}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showMinutes !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showMinutes !== false ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
            {node.props.showMinutes !== false && (
              <TextInput
                label="Minutes Label"
                value={(node.props.labels as any)?.minutes as string || 'Minutes'}
                onChange={(v) => handlePropChange('labels', { ...(node.props.labels as object || {}), minutes: v })}
                placeholder="Minutes"
              />
            )}
            <div className="flex items-center justify-between">
              <label className="text-xs text-white/70">Show Seconds</label>
              <button
                onClick={() => handlePropChange('showSeconds', !(node.props.showSeconds !== false))}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showSeconds !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showSeconds !== false ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
            {node.props.showSeconds !== false && (
              <TextInput
                label="Seconds Label"
                value={(node.props.labels as any)?.seconds as string || 'Seconds'}
                onChange={(v) => handlePropChange('labels', { ...(node.props.labels as object || {}), seconds: v })}
                placeholder="Seconds"
              />
            )}
          </CollapsibleSection>
        </>
      )}

      {/* Star Rating */}
      {node.type === 'star_rating' && (
        <CollapsibleSection title="Star Rating" icon={<Star className="w-3 h-3" />} defaultOpen>
          <SelectInput
            label="Rating"
            value={String(node.props.rating || '5')}
            onChange={(v) => handlePropChange('rating', parseFloat(v))}
            options={[
              { value: '0', label: '0' },
              { value: '0.5', label: '0.5' },
              { value: '1', label: '1' },
              { value: '1.5', label: '1.5' },
              { value: '2', label: '2' },
              { value: '2.5', label: '2.5' },
              { value: '3', label: '3' },
              { value: '3.5', label: '3.5' },
              { value: '4', label: '4' },
              { value: '4.5', label: '4.5' },
              { value: '5', label: '5' },
            ]}
          />
          <SelectInput
            label="Max Rating"
            value={String(node.props.maxRating || '5')}
            onChange={(v) => handlePropChange('maxRating', parseInt(v))}
            options={[
              { value: '5', label: '5 Stars' },
              { value: '6', label: '6 Stars' },
              { value: '7', label: '7 Stars' },
              { value: '8', label: '8 Stars' },
              { value: '10', label: '10 Stars' },
            ]}
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Show Number</label>
            <button
              onClick={() => handlePropChange('showNumber', !(node.props.showNumber !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showNumber !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showNumber !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <ColorInput
            label="Star Color"
            value={node.props.color as string || '#f59e0b'}
            onChange={(v) => handlePropChange('color', v)}
          />
        </CollapsibleSection>
      )}

      {/* Call to Action */}
      {node.type === 'call_to_action' && (
        <>
          <CollapsibleSection title="CTA Content" icon={<Maximize className="w-3 h-3" />} defaultOpen>
            <TextInput
              label="Title"
              value={node.props.title as string || ''}
              onChange={(v) => handlePropChange('title', v)}
              placeholder="Call to Action Title"
            />
            <TextAreaInput
              label="Description"
              value={node.props.description as string || ''}
              onChange={(v) => handlePropChange('description', v)}
              placeholder="Compelling description..."
              rows={2}
            />
            <SelectInput
              label="Layout"
              value={node.props.layout as string || 'horizontal'}
              onChange={(v) => handlePropChange('layout', v)}
              options={[
                { value: 'horizontal', label: 'Horizontal' },
                { value: 'vertical', label: 'Vertical' },
              ]}
            />
          </CollapsibleSection>
          <CollapsibleSection title="CTA Buttons" icon={<Link className="w-3 h-3" />}>
            <TextInput
              label="Button Text"
              value={node.props.buttonText as string || ''}
              onChange={(v) => handlePropChange('buttonText', v)}
              placeholder="Get Started"
            />
            <TextInput
              label="Button URL"
              value={node.props.buttonUrl as string || ''}
              onChange={(v) => handlePropChange('buttonUrl', v)}
              placeholder="https://..."
            />
            <TextInput
              label="Secondary Button Text"
              value={node.props.secondaryButtonText as string || ''}
              onChange={(v) => handlePropChange('secondaryButtonText', v)}
              placeholder="Learn More"
            />
            {(node.props.secondaryButtonText as string || '').trim() !== '' && (
              <TextInput
                label="Secondary Button URL"
                value={node.props.secondaryButtonUrl as string || ''}
                onChange={(v) => handlePropChange('secondaryButtonUrl', v)}
                placeholder="https://..."
              />
            )}
          </CollapsibleSection>
        </>
      )}

      {/* Flip Box */}
      {node.type === 'flip_box' && (
        <>
          <CollapsibleSection title="Front Side" icon={<Layers className="w-3 h-3" />} defaultOpen>
            <SelectInput
              label="Front Icon"
              value={node.props.frontIcon as string || 'Star'}
              onChange={(v) => handlePropChange('frontIcon', v)}
              options={[
                { value: 'Star', label: 'Star' },
                { value: 'Heart', label: 'Heart' },
                { value: 'Check', label: 'Check' },
                { value: 'Zap', label: 'Zap' },
                { value: 'Shield', label: 'Shield' },
                { value: 'Clock', label: 'Clock' },
                { value: 'Mail', label: 'Mail' },
                { value: 'Phone', label: 'Phone' },
              ]}
            />
            <TextInput
              label="Front Title"
              value={node.props.frontTitle as string || ''}
              onChange={(v) => handlePropChange('frontTitle', v)}
              placeholder="Front Title"
            />
            <TextAreaInput
              label="Front Description"
              value={node.props.frontDescription as string || ''}
              onChange={(v) => handlePropChange('frontDescription', v)}
              placeholder="Front side description..."
              rows={2}
            />
          </CollapsibleSection>
          <CollapsibleSection title="Back Side" icon={<Layers className="w-3 h-3" />}>
            <TextInput
              label="Back Title"
              value={node.props.backTitle as string || ''}
              onChange={(v) => handlePropChange('backTitle', v)}
              placeholder="Back Title"
            />
            <TextAreaInput
              label="Back Description"
              value={node.props.backDescription as string || ''}
              onChange={(v) => handlePropChange('backDescription', v)}
              placeholder="Back side description..."
              rows={2}
            />
            <TextInput
              label="Button Text"
              value={node.props.backButtonText as string || ''}
              onChange={(v) => handlePropChange('backButtonText', v)}
              placeholder="Learn More"
            />
            <TextInput
              label="Button URL"
              value={node.props.backButtonUrl as string || ''}
              onChange={(v) => handlePropChange('backButtonUrl', v)}
              placeholder="https://..."
            />
            <SelectInput
              label="Flip Direction"
              value={node.props.flipDirection as string || 'horizontal'}
              onChange={(v) => handlePropChange('flipDirection', v)}
              options={[
                { value: 'horizontal', label: 'Horizontal' },
                { value: 'vertical', label: 'Vertical' },
              ]}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Image Box */}
      {node.type === 'image_box' && (
        <CollapsibleSection title="Image Box" icon={<Image className="w-3 h-3" />} defaultOpen>
          <div className="mb-3">
            <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Image</label>
            <div className="flex gap-2">
              <input
                type="text"
                value={node.props.src as string || ''}
                onChange={(e) => handlePropChange('src', e.target.value)}
                placeholder="https://..."
                className="flex-1 px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
              />
              <button
                onClick={() => setMediaLibraryOpen(true)}
                className="px-2 py-1.5 bg-[#0d0d0d] border border-[#3c3c3c] text-white/60 hover:text-white hover:bg-white/5 transition-colors"
                title="Browse Media Library"
              >
                <FolderOpen className="w-4 h-4" />
              </button>
            </div>
          </div>
          <TextInput
            label="Alt Text"
            value={node.props.alt as string || ''}
            onChange={(v) => handlePropChange('alt', v)}
            placeholder="Describe the image..."
          />
          <TextInput
            label="Title"
            value={node.props.title as string || ''}
            onChange={(v) => handlePropChange('title', v)}
            placeholder="Image Box Title"
          />
          <TextAreaInput
            label="Description"
            value={node.props.description as string || ''}
            onChange={(v) => handlePropChange('description', v)}
            placeholder="Image description..."
            rows={2}
          />
          <TextInput
            label="Link URL"
            value={node.props.linkUrl as string || ''}
            onChange={(v) => handlePropChange('linkUrl', v)}
            placeholder="https://..."
          />
          <SelectInput
            label="Title Position"
            value={node.props.titlePosition as string || 'below'}
            onChange={(v) => handlePropChange('titlePosition', v)}
            options={[
              { value: 'below', label: 'Below Image' },
              { value: 'above', label: 'Above Image' },
              { value: 'overlay', label: 'Overlay' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Logo Grid */}
      {node.type === 'logo_grid' && (
        <>
          <CollapsibleSection title="Logo Grid Settings" icon={<Grid3X3 className="w-3 h-3" />} defaultOpen>
            <SelectInput
              label="Columns"
              value={String(node.props.columns || '4')}
              onChange={(v) => handlePropChange('columns', parseInt(v))}
              options={[
                { value: '2', label: '2 Columns' },
                { value: '3', label: '3 Columns' },
                { value: '4', label: '4 Columns' },
                { value: '5', label: '5 Columns' },
                { value: '6', label: '6 Columns' },
              ]}
            />
            <div className="flex items-center justify-between">
              <label className="text-xs text-white/70">Grayscale</label>
              <button
                onClick={() => handlePropChange('grayscale', !node.props.grayscale)}
                className={`relative w-10 h-5 rounded-full transition-colors ${node.props.grayscale ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                  }`}
              >
                <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.grayscale ? 'translate-x-5' : 'translate-x-0'
                  }`} />
              </button>
            </div>
          </CollapsibleSection>
          <CollapsibleSection title="Logos" icon={<Image className="w-3 h-3" />}>
            <TextAreaInput
              label="Logos (JSON)"
              value={JSON.stringify(node.props.logos || [], null, 2)}
              onChange={(v) => {
                try {
                  const parsed = JSON.parse(v);
                  handlePropChange('logos', parsed);
                } catch {
                  // Invalid JSON, ignore
                }
              }}
              placeholder='[{"src":"https://...","alt":"Logo","url":"#"}]'
              rows={5}
            />
          </CollapsibleSection>
        </>
      )}

      {/* Blockquote */}
      {node.type === 'blockquote' && (
        <CollapsibleSection title="Blockquote" icon={<Type className="w-3 h-3" />} defaultOpen>
          <TextAreaInput
            label="Content"
            value={node.props.content as string || ''}
            onChange={(v) => handlePropChange('content', v)}
            placeholder="Quote text..."
            rows={3}
          />
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Author"
              value={node.props.author as string || ''}
              onChange={(v) => handlePropChange('author', v)}
              placeholder="Author Name"
            />
            <TextInput
              label="Author Title"
              value={node.props.authorTitle as string || ''}
              onChange={(v) => handlePropChange('authorTitle', v)}
              placeholder="CEO, Company"
            />
          </div>
          <SelectInput
            label="Style"
            value={node.props.style as string || 'modern'}
            onChange={(v) => handlePropChange('style', v)}
            options={[
              { value: 'modern', label: 'Modern' },
              { value: 'classic', label: 'Classic' },
              { value: 'minimal', label: 'Minimal' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Toggle */}
      {node.type === 'toggle' && (
        <CollapsibleSection title="Toggle" icon={<ToggleLeft className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Title"
            value={node.props.title as string || ''}
            onChange={(v) => handlePropChange('title', v)}
            placeholder="Toggle Title"
          />
          <TextAreaInput
            label="Content"
            value={node.props.content as string || ''}
            onChange={(v) => handlePropChange('content', v)}
            placeholder="Toggle content..."
            rows={3}
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Open by Default</label>
            <button
              onClick={() => handlePropChange('isOpen', !node.props.isOpen)}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.isOpen ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.isOpen ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Search Box */}
      {node.type === 'search_box' && (
        <CollapsibleSection title="Search Box" icon={<Search className="w-3 h-3" />} defaultOpen>
          <TextInput
            label="Placeholder"
            value={node.props.placeholder as string || 'Search...'}
            onChange={(v) => handlePropChange('placeholder', v)}
            placeholder="Search..."
          />
          <TextInput
            label="Button Text"
            value={node.props.buttonText as string || 'Search'}
            onChange={(v) => handlePropChange('buttonText', v)}
            placeholder="Search"
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Show Button</label>
            <button
              onClick={() => handlePropChange('showButton', !(node.props.showButton !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showButton !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showButton !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <TextInput
            label="Search URL"
            value={node.props.searchUrl as string || ''}
            onChange={(v) => handlePropChange('searchUrl', v)}
            placeholder="/search?q="
          />
          <SelectInput
            label="Style"
            value={node.props.style as string || 'rounded'}
            onChange={(v) => handlePropChange('style', v)}
            options={[
              { value: 'rounded', label: 'Rounded' },
              { value: 'square', label: 'Square' },
              { value: 'pill', label: 'Pill' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Breadcrumbs */}
      {node.type === 'breadcrumbs' && (
        <CollapsibleSection title="Breadcrumbs" icon={<Navigation className="w-3 h-3" />} defaultOpen>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Show Home</label>
            <button
              onClick={() => handlePropChange('showHome', !(node.props.showHome !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showHome !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showHome !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <SelectInput
            label="Separator"
            value={node.props.separator as string || '>'}
            onChange={(v) => handlePropChange('separator', v)}
            options={[
              { value: '>', label: '>' },
              { value: '→', label: '→' },
              { value: '|', label: '|' },
              { value: '·', label: '·' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Code Block */}
      {node.type === 'code_block' && (
        <CollapsibleSection title="Code Block" icon={<Code className="w-3 h-3" />} defaultOpen>
          <TextAreaInput
            label="Code"
            value={node.props.code as string || ''}
            onChange={(v) => handlePropChange('code', v)}
            placeholder="Enter your code..."
            rows={8}
          />
          <SelectInput
            label="Language"
            value={node.props.language as string || 'javascript'}
            onChange={(v) => handlePropChange('language', v)}
            options={[
              { value: 'javascript', label: 'JavaScript' },
              { value: 'typescript', label: 'TypeScript' },
              { value: 'html', label: 'HTML' },
              { value: 'css', label: 'CSS' },
              { value: 'php', label: 'PHP' },
              { value: 'python', label: 'Python' },
              { value: 'json', label: 'JSON' },
              { value: 'bash', label: 'Bash' },
            ]}
          />
          <SelectInput
            label="Theme"
            value={node.props.theme as string || 'dark'}
            onChange={(v) => handlePropChange('theme', v)}
            options={[
              { value: 'dark', label: 'Dark' },
              { value: 'light', label: 'Light' },
            ]}
          />
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Line Numbers</label>
            <button
              onClick={() => handlePropChange('showLineNumbers', !(node.props.showLineNumbers !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showLineNumbers !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showLineNumbers !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
          <div className="flex items-center justify-between">
            <label className="text-xs text-white/70">Copy Button</label>
            <button
              onClick={() => handlePropChange('showCopyButton', !(node.props.showCopyButton !== false))}
              className={`relative w-10 h-5 rounded-full transition-colors ${node.props.showCopyButton !== false ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
                }`}
            >
              <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${node.props.showCopyButton !== false ? 'translate-x-5' : 'translate-x-0'
                }`} />
            </button>
          </div>
        </CollapsibleSection>
      )}

      {/* Divider */}
      {node.type === 'divider' && (
        <CollapsibleSection title="Divider" icon={<Layers className="w-3 h-3" />} defaultOpen>
          <SelectInput
            label="Style"
            value={node.props.dividerStyle as string || 'solid'}
            onChange={(v) => handlePropChange('dividerStyle', v)}
            options={[
              { value: 'solid', label: 'Solid' },
              { value: 'dashed', label: 'Dashed' },
              { value: 'dotted', label: 'Dotted' },
              { value: 'double', label: 'Double' },
            ]}
          />
          <SelectInput
            label="Thickness"
            value={node.props.thickness as string || '1px'}
            onChange={(v) => handlePropChange('thickness', v)}
            options={[
              { value: '1px', label: 'Thin (1px)' },
              { value: '2px', label: 'Medium (2px)' },
              { value: '3px', label: 'Thick (3px)' },
              { value: '4px', label: 'Extra Thick (4px)' },
            ]}
          />
          <ColorInput
            label="Color"
            value={node.props.color as string || '#e5e7eb'}
            onChange={(v) => handlePropChange('color', v)}
          />
        </CollapsibleSection>
      )}

      {/* Media Picker for Gallery */}
      {node.type === 'gallery' && (
        <MediaPicker
          isOpen={isMediaPickerOpen}
          onClose={() => setIsMediaPickerOpen(false)}
          onSelect={handleMediaSelect}
          onSelectMultiple={handleMediaSelectMultiple}
          allowMultiple={true}
          acceptedTypes={['image/*']}
          title="Select Images for Gallery"
          showImageSettings={false}
        />
      )}

      {/* Container Layout */}
      {isContainer && (() => {
        // Compute effective (viewport-inherited) layout values so controls
        // update when the user switches between Desktop / Tablet / Mobile.
        const effDisplay = getStyleValue('display') || (
          node.type === 'container' && !getStyleValue('flexDirection') && !getStyleValue('gap') && !getStyleValue('gridTemplateColumns')
            ? 'block'
            : 'flex'
        );
        const effDirection = getStyleValue('flexDirection') || (node.type === 'row' ? 'row' : node.type === 'section' || node.type === 'column' ? 'column' : 'row');
        const showFlex = effDisplay === 'flex' ||
          (!getStyleValue('display') && (
            node.type !== 'container' ||
            getStyleValue('flexDirection') ||
            getStyleValue('gap') ||
            getStyleValue('justifyContent') ||
            getStyleValue('alignItems')
          ));
        return (
          <CollapsibleSection title="Layout" icon={<Grid3X3 className="w-3 h-3" />}>
            <SelectInput
              label="Display"
              value={effDisplay}
              onChange={(v) => handleStyleChange('display', v)}
              options={[
                { value: 'flex', label: 'Flexbox' },
                { value: 'grid', label: 'Grid' },
                { value: 'block', label: 'Block' },
              ]}
            />

            {/* Flexbox Options — show when display is flex, or when it defaults to flex */}
            {showFlex && (
              <>
                <ButtonGroup
                  label="Direction"
                  value={effDirection}
                  onChange={(v) => handleStyleChange('flexDirection', v)}
                  options={[
                    { value: 'row', icon: <ArrowRight className="w-3 h-3" />, title: 'Row' },
                    { value: 'column', icon: <ArrowDown className="w-3 h-3" />, title: 'Column' },
                    { value: 'row-reverse', icon: <ArrowLeft className="w-3 h-3" />, title: 'Row Reverse' },
                    { value: 'column-reverse', icon: <ArrowUp className="w-3 h-3" />, title: 'Column Reverse' },
                  ]}
                />

                <SelectInput
                  label="Justify Content"
                  value={getStyleValue('justifyContent') || 'flex-start'}
                  onChange={(v) => handleStyleChange('justifyContent', v)}
                  options={[
                    { value: 'flex-start', label: 'Start' },
                    { value: 'center', label: 'Center' },
                    { value: 'flex-end', label: 'End' },
                    { value: 'space-between', label: 'Space Between' },
                    { value: 'space-around', label: 'Space Around' },
                    { value: 'space-evenly', label: 'Space Evenly' },
                  ]}
                />

                <SelectInput
                  label="Align Items"
                  value={getStyleValue('alignItems') || 'stretch'}
                  onChange={(v) => handleStyleChange('alignItems', v)}
                  options={[
                    { value: 'stretch', label: 'Stretch' },
                    { value: 'flex-start', label: 'Start' },
                    { value: 'center', label: 'Center' },
                    { value: 'flex-end', label: 'End' },
                    { value: 'baseline', label: 'Baseline' },
                  ]}
                />

                <SelectInput
                  label="Wrap"
                  value={getStyleValue('flexWrap') || 'nowrap'}
                  onChange={(v) => handleStyleChange('flexWrap', v)}
                  options={[
                    { value: 'nowrap', label: 'No Wrap' },
                    { value: 'wrap', label: 'Wrap' },
                    { value: 'wrap-reverse', label: 'Wrap Reverse' },
                  ]}
                />
              </>
            )}

            {/* Grid Options */}
            {effDisplay === 'grid' && (
              <>
                {/* Quick Grid Presets */}
                <div className="mb-3">
                  <label className="block text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1">Quick Presets</label>
                  <div className="grid grid-cols-4 gap-1">
                    {[
                      { cols: '1fr 1fr', label: '2 Col' },
                      { cols: 'repeat(3, 1fr)', label: '3 Col' },
                      { cols: 'repeat(4, 1fr)', label: '4 Col' },
                      { cols: '1fr 2fr', label: '1:2' },
                    ].map((preset) => (
                      <button
                        key={preset.cols}
                        onClick={() => handleStyleChange('gridTemplateColumns', preset.cols)}
                        className={`px-2 py-1 text-[9px] border transition-colors ${getStyleValue('gridTemplateColumns') === preset.cols
                          ? 'bg-[#0078d4] border-[#0078d4] text-white'
                          : 'bg-[#2d2d2d] border-[#3c3c3c] text-white/60 hover:border-[#0078d4]'
                          }`}
                      >
                        {preset.label}
                      </button>
                    ))}
                  </div>
                </div>

                <TextInput
                  label="Columns"
                  value={getStyleValue('gridTemplateColumns')}
                  onChange={(v) => handleStyleChange('gridTemplateColumns', v)}
                  placeholder="repeat(3, 1fr)"
                />
                <TextInput
                  label="Rows"
                  value={getStyleValue('gridTemplateRows')}
                  onChange={(v) => handleStyleChange('gridTemplateRows', v)}
                  placeholder="auto"
                />
              </>
            )}

            <SelectInput
              label="Gap"
              value={getStyleValue('gap') || ''}
              onChange={(v) => handleStyleChange('gap', v)}
              options={[
                { value: '', label: 'None' },
                { value: '4px', label: 'XS (4px)' },
                { value: '8px', label: 'SM (8px)' },
                { value: '12px', label: 'MD (12px)' },
                { value: '16px', label: 'LG (16px)' },
                { value: '24px', label: 'XL (24px)' },
                { value: '32px', label: '2XL (32px)' },
                { value: '48px', label: '3XL (48px)' },
              ]}
            />
          </CollapsibleSection>
        );
      })()}
    </div>
  );

  // ==========================================================================
  // Responsive Viewport Selector
  // ==========================================================================
  const renderViewportSelector = () => (
    <div className="mb-3 p-2 bg-[#1e1e1e] border border-[#3c3c3c]">
      <div className="flex items-center justify-between mb-1">
        <span className="text-[10px] text-white/40 uppercase tracking-wide">Editing styles for</span>
      </div>
      <div className="flex gap-1">
        <button
          onClick={() => handleViewportChange('desktop')}
          className={`flex-1 flex items-center justify-center gap-1 py-1.5 text-[10px] transition-colors ${styleViewport === 'desktop'
            ? 'bg-[#0078d4] text-white'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
          title="Desktop styles (base)"
        >
          <Monitor className="w-3 h-3" />
          Desktop
        </button>
        <button
          onClick={() => handleViewportChange('tablet')}
          className={`flex-1 flex items-center justify-center gap-1 py-1.5 text-[10px] transition-colors ${styleViewport === 'tablet'
            ? 'bg-[#0078d4] text-white'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
          title="Tablet overrides (≤1024px)"
        >
          <Tablet className="w-3 h-3" />
          Tablet
        </button>
        <button
          onClick={() => handleViewportChange('mobile')}
          className={`flex-1 flex items-center justify-center gap-1 py-1.5 text-[10px] transition-colors ${styleViewport === 'mobile'
            ? 'bg-[#0078d4] text-white'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
          title="Mobile overrides (≤640px)"
        >
          <Smartphone className="w-3 h-3" />
          Mobile
        </button>
      </div>
      {styleViewport !== 'desktop' && (
        <p className="text-[9px] text-white/30 mt-1.5">
          {styleViewport === 'tablet' ? 'Tablet' : 'Mobile'} styles override desktop. Empty = inherit from desktop.
        </p>
      )}
    </div>
  );

  // ==========================================================================
  // Style Tab
  // ==========================================================================
  const renderStyleTab = () => (
    <div className="p-3">
      {/* Responsive Viewport Selector */}
      {renderViewportSelector()}

      {/* Typography */}
      {isTextElement && (
        <CollapsibleSection title="Typography" icon={<Type className="w-3 h-3" />}>
          <SelectInput
            label="Font Family"
            value={getStyleValue('fontFamily')}
            onChange={(v) => handleStyleChange('fontFamily', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Default' },
              { value: 'system-ui, -apple-system, sans-serif', label: 'System UI' },
              { value: 'Inter, sans-serif', label: 'Inter' },
              { value: 'Roboto, sans-serif', label: 'Roboto' },
              { value: 'Open Sans, sans-serif', label: 'Open Sans' },
              { value: 'Lato, sans-serif', label: 'Lato' },
              { value: 'Poppins, sans-serif', label: 'Poppins' },
              { value: 'Montserrat, sans-serif', label: 'Montserrat' },
              { value: 'Playfair Display, serif', label: 'Playfair Display' },
              { value: 'Merriweather, serif', label: 'Merriweather' },
              { value: 'Georgia, serif', label: 'Georgia' },
              { value: 'monospace', label: 'Monospace' },
            ]}
          />

          <SelectInput
            label="Font Size"
            value={getStyleValue('fontSize')}
            onChange={(v) => handleStyleChange('fontSize', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Default' },
              { value: '12px', label: 'XS (12px)' },
              { value: '14px', label: 'SM (14px)' },
              { value: '16px', label: 'Base (16px)' },
              { value: '18px', label: 'LG (18px)' },
              { value: '20px', label: 'XL (20px)' },
              { value: '24px', label: '2XL (24px)' },
              { value: '30px', label: '3XL (30px)' },
              { value: '36px', label: '4XL (36px)' },
              { value: '48px', label: '5XL (48px)' },
              { value: '60px', label: '6XL (60px)' },
            ]}
          />

          <SelectInput
            label="Font Weight"
            value={getStyleValue('fontWeight')}
            onChange={(v) => handleStyleChange('fontWeight', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Default' },
              { value: '300', label: 'Light (300)' },
              { value: '400', label: 'Normal (400)' },
              { value: '500', label: 'Medium (500)' },
              { value: '600', label: 'Semi Bold (600)' },
              { value: '700', label: 'Bold (700)' },
              { value: '800', label: 'Extra Bold (800)' },
            ]}
          />

          <SelectInput
            label="Line Height"
            value={getStyleValue('lineHeight')}
            onChange={(v) => handleStyleChange('lineHeight', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Default' },
              { value: '1', label: 'None (1)' },
              { value: '1.25', label: 'Tight (1.25)' },
              { value: '1.375', label: 'Snug (1.375)' },
              { value: '1.5', label: 'Normal (1.5)' },
              { value: '1.625', label: 'Relaxed (1.625)' },
              { value: '2', label: 'Loose (2)' },
            ]}
          />

          <SelectInput
            label="Letter Spacing"
            value={getStyleValue('letterSpacing')}
            onChange={(v) => handleStyleChange('letterSpacing', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Default' },
              { value: '-0.05em', label: 'Tighter' },
              { value: '-0.025em', label: 'Tight' },
              { value: '0', label: 'Normal' },
              { value: '0.025em', label: 'Wide' },
              { value: '0.05em', label: 'Wider' },
              { value: '0.1em', label: 'Widest' },
            ]}
          />

          <SelectInput
            label="Text Transform"
            value={getStyleValue('textTransform')}
            onChange={(v) => handleStyleChange('textTransform', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: 'uppercase', label: 'UPPERCASE' },
              { value: 'lowercase', label: 'lowercase' },
              { value: 'capitalize', label: 'Capitalize' },
            ]}
          />

          <SelectInput
            label="Text Decoration"
            value={getStyleValue('textDecoration')}
            onChange={(v) => handleStyleChange('textDecoration', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: 'underline', label: 'Underline' },
              { value: 'line-through', label: 'Strikethrough' },
              { value: 'overline', label: 'Overline' },
            ]}
          />

          <ButtonGroup
            label="Text Align"
            value={getStyleValue('textAlign') || 'left'}
            onChange={(v) => handleStyleChange('textAlign', v)}
            options={[
              { value: 'left', icon: <AlignLeft className="w-3 h-3" />, title: 'Left' },
              { value: 'center', icon: <AlignCenter className="w-3 h-3" />, title: 'Center' },
              { value: 'right', icon: <AlignRight className="w-3 h-3" />, title: 'Right' },
              { value: 'justify', icon: <AlignJustify className="w-3 h-3" />, title: 'Justify' },
            ]}
          />

          <ColorInput
            label="Text Color"
            value={getStyleValue('color')}
            onChange={(v) => handleStyleChange('color', v)}
          />
        </CollapsibleSection>
      )}

      {/* Vertical Alignment - for elements that can be vertically positioned */}
      {['column', 'container', 'heading', 'text', 'image', 'button', 'icon', 'icon_box', 'video', 'spacer', 'divider'].includes(node.type) && (
        <CollapsibleSection title="Vertical Alignment" icon={<AlignCenterVertical className="w-3 h-3" />} defaultOpen={false}>
          <ButtonGroup
            label="Vertical Position"
            value={getStyleValue('alignSelf') || 'auto'}
            onChange={(v) => handleStyleChange('alignSelf', v === 'auto' ? '' : v)}
            options={[
              { value: 'auto', icon: <span className="text-[10px] font-medium">Auto</span>, title: 'Auto' },
              { value: 'flex-start', icon: <AlignStartVertical className="w-3 h-3" />, title: 'Top' },
              { value: 'center', icon: <AlignCenterVertical className="w-3 h-3" />, title: 'Middle' },
              { value: 'flex-end', icon: <AlignEndVertical className="w-3 h-3" />, title: 'Bottom' },
            ]}
          />
          <p className="text-[10px] text-white/50 mt-1">
            Controls vertical position within parent container (works best in flex layouts)
          </p>
        </CollapsibleSection>
      )}

      {/* Background */}
      <CollapsibleSection title="Background" icon={<Palette className="w-3 h-3" />}>
        <ColorInput
          label="Background Color"
          value={getStyleValue('backgroundColor')}
          onChange={(v) => handleStyleChange('backgroundColor', v)}
        />
        <TextInput
          label="Background Image"
          value={node.style.backgroundImage || ''}
          onChange={(v) => handleStyleChange('backgroundImage', v)}
          placeholder="url(...)"
        />
        {node.style.backgroundImage && (
          <>
            <SelectInput
              label="Background Size"
              value={node.style.backgroundSize || 'cover'}
              onChange={(v) => handleStyleChange('backgroundSize', v)}
              options={[
                { value: 'cover', label: 'Cover' },
                { value: 'contain', label: 'Contain' },
                { value: 'auto', label: 'Auto' },
              ]}
            />
            <SelectInput
              label="Background Position"
              value={node.style.backgroundPosition || 'center'}
              onChange={(v) => handleStyleChange('backgroundPosition', v)}
              options={[
                { value: 'center', label: 'Center' },
                { value: 'top', label: 'Top' },
                { value: 'bottom', label: 'Bottom' },
                { value: 'left', label: 'Left' },
                { value: 'right', label: 'Right' },
              ]}
            />
          </>
        )}
      </CollapsibleSection>

      {/* Border */}
      <CollapsibleSection title="Border" defaultOpen={false}>
        <SelectInput
          label="Border Width"
          value={node.style.borderWidth || ''}
          onChange={(v) => handleStyleChange('borderWidth', v)}
          options={[
            { value: '', label: 'None' },
            { value: '1px', label: 'Thin (1px)' },
            { value: '2px', label: 'Medium (2px)' },
            { value: '4px', label: 'Thick (4px)' },
            { value: '8px', label: 'Extra Thick (8px)' },
          ]}
        />
        <SelectInput
          label="Border Style"
          value={node.style.borderStyle || ''}
          onChange={(v) => handleStyleChange('borderStyle', v)}
          options={[
            { value: '', label: 'None' },
            { value: 'solid', label: 'Solid' },
            { value: 'dashed', label: 'Dashed' },
            { value: 'dotted', label: 'Dotted' },
            { value: 'double', label: 'Double' },
          ]}
        />
        <ColorInput
          label="Border Color"
          value={node.style.borderColor || ''}
          onChange={(v) => handleStyleChange('borderColor', v)}
        />
        <SelectInput
          label="Border Radius"
          value={node.style.borderRadius || '0'}
          onChange={(v) => handleStyleChange('borderRadius', v)}
          options={[
            { value: '0', label: 'None (Square)' },
            { value: '4px', label: 'Small (4px)' },
            { value: '8px', label: 'Medium (8px)' },
            { value: '12px', label: 'Large (12px)' },
            { value: '16px', label: 'XL (16px)' },
            { value: '24px', label: '2XL (24px)' },
            { value: '9999px', label: 'Full (Pill)' },
          ]}
        />
      </CollapsibleSection>

      {/* Button Style */}
      {node.type === 'button' && (
        <CollapsibleSection title="Button Style">
          <SelectInput
            label="Variant"
            value={node.props.variant as string || 'primary'}
            onChange={(v) => handlePropChange('variant', v)}
            options={[
              { value: 'primary', label: 'Primary' },
              { value: 'secondary', label: 'Secondary' },
              { value: 'outline', label: 'Outline' },
              { value: 'ghost', label: 'Ghost' },
            ]}
          />
        </CollapsibleSection>
      )}
    </div>
  );

  // ==========================================================================
  // Advanced Tab
  // ==========================================================================
  const renderAdvancedTab = () => (
    <div className="p-3">
      {/* Responsive Viewport Selector */}
      {renderViewportSelector()}

      {/* Spacing */}
      <CollapsibleSection title="Margin" icon={<Maximize className="w-3 h-3" />}>
        <SpacingControl
          label="Margin"
          values={parseSpacing(getStyleValue('margin'))}
          onChange={(side, value) => {
            const current = parseSpacing(getStyleValue('margin'));
            current[side] = value;
            handleStyleChange('margin', formatSpacing(current));
          }}
        />
      </CollapsibleSection>

      <CollapsibleSection title="Padding">
        <SpacingControl
          label="Padding"
          values={parseSpacing(getStyleValue('padding'))}
          onChange={(side, value) => {
            const current = parseSpacing(getStyleValue('padding'));
            current[side] = value;
            handleStyleChange('padding', formatSpacing(current));
          }}
        />
      </CollapsibleSection>

      {/* Size */}
      <CollapsibleSection title="Size" defaultOpen={false}>
        <div className="grid grid-cols-2 gap-2">
          <SelectInput
            label="Width"
            value={getStyleValue('width')}
            onChange={(v) => handleStyleChange('width', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Auto' },
              { value: '25%', label: '25%' },
              { value: '33.333%', label: '33%' },
              { value: '50%', label: '50%' },
              { value: '66.666%', label: '66%' },
              { value: '75%', label: '75%' },
              { value: '100%', label: '100%' },
              { value: 'fit-content', label: 'Fit Content' },
              { value: 'max-content', label: 'Max Content' },
              { value: 'min-content', label: 'Min Content' },
            ]}
          />
          <SelectInput
            label="Height"
            value={getStyleValue('height')}
            onChange={(v) => handleStyleChange('height', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'Auto' },
              { value: '100px', label: '100px' },
              { value: '200px', label: '200px' },
              { value: '300px', label: '300px' },
              { value: '400px', label: '400px' },
              { value: '500px', label: '500px' },
              { value: '100vh', label: 'Full Screen' },
              { value: 'fit-content', label: 'Fit Content' },
            ]}
          />
          <SelectInput
            label="Min Width"
            value={getStyleValue('minWidth')}
            onChange={(v) => handleStyleChange('minWidth', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: '200px', label: '200px' },
              { value: '300px', label: '300px' },
              { value: '400px', label: '400px' },
              { value: '500px', label: '500px' },
              { value: '100%', label: '100%' },
            ]}
          />
          <SelectInput
            label="Min Height"
            value={getStyleValue('minHeight')}
            onChange={(v) => handleStyleChange('minHeight', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: '100px', label: '100px' },
              { value: '200px', label: '200px' },
              { value: '300px', label: '300px' },
              { value: '400px', label: '400px' },
              { value: '500px', label: '500px' },
              { value: '100vh', label: 'Full Screen' },
            ]}
          />
          <SelectInput
            label="Max Width"
            value={getStyleValue('maxWidth')}
            onChange={(v) => handleStyleChange('maxWidth', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: '640px', label: 'SM (640px)' },
              { value: '768px', label: 'MD (768px)' },
              { value: '1024px', label: 'LG (1024px)' },
              { value: '1280px', label: 'XL (1280px)' },
              { value: '1536px', label: '2XL (1536px)' },
              { value: '100%', label: '100%' },
            ]}
          />
          <SelectInput
            label="Max Height"
            value={getStyleValue('maxHeight')}
            onChange={(v) => handleStyleChange('maxHeight', v)}
            options={[
              { value: '', label: styleViewport !== 'desktop' ? 'Inherit' : 'None' },
              { value: '300px', label: '300px' },
              { value: '400px', label: '400px' },
              { value: '500px', label: '500px' },
              { value: '600px', label: '600px' },
              { value: '100vh', label: 'Full Screen' },
            ]}
          />
        </div>
      </CollapsibleSection>

      {/* Self Alignment - for containers/columns inside flex parents */}
      {(node.type === 'container' || node.type === 'column') && (
        <CollapsibleSection title="Self Alignment" defaultOpen={false}>
          <SelectInput
            label="Align Self"
            value={getStyleValue('alignSelf')}
            onChange={(v) => handleStyleChange('alignSelf', v)}
            options={[
              { value: '', label: 'Auto (inherit)' },
              { value: 'flex-start', label: 'Start' },
              { value: 'center', label: 'Center' },
              { value: 'flex-end', label: 'End' },
              { value: 'stretch', label: 'Stretch' },
              { value: 'baseline', label: 'Baseline' },
            ]}
          />
          <TextInput
            label="Flex"
            value={getStyleValue('flex')}
            onChange={(v) => handleStyleChange('flex', v)}
            placeholder="1 1 auto"
          />
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Flex Grow"
              value={getStyleValue('flexGrow')}
              onChange={(v) => handleStyleChange('flexGrow', v)}
              placeholder="0"
            />
            <TextInput
              label="Flex Shrink"
              value={getStyleValue('flexShrink')}
              onChange={(v) => handleStyleChange('flexShrink', v)}
              placeholder="1"
            />
          </div>
          <TextInput
            label="Flex Basis"
            value={getStyleValue('flexBasis')}
            onChange={(v) => handleStyleChange('flexBasis', v)}
            placeholder="auto"
          />
          <SelectInput
            label="Order"
            value={getStyleValue('order')}
            onChange={(v) => handleStyleChange('order', v)}
            options={[
              { value: '', label: 'Default (0)' },
              { value: '-1', label: 'First (-1)' },
              { value: '1', label: 'After (1)' },
              { value: '2', label: 'Later (2)' },
              { value: '99', label: 'Last (99)' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* Position */}
      <CollapsibleSection title="Position" defaultOpen={false}>
        <SelectInput
          label="Position"
          value={node.style.position || 'static'}
          onChange={(v) => handleStyleChange('position', v)}
          options={[
            { value: 'static', label: 'Static' },
            { value: 'relative', label: 'Relative' },
            { value: 'absolute', label: 'Absolute' },
            { value: 'fixed', label: 'Fixed' },
            { value: 'sticky', label: 'Sticky' },
          ]}
        />
        {node.style.position && node.style.position !== 'static' && (
          <div className="grid grid-cols-2 gap-2">
            <TextInput
              label="Top"
              value={node.style.top || ''}
              onChange={(v) => handleStyleChange('top', v)}
              placeholder="auto"
            />
            <TextInput
              label="Right"
              value={node.style.right || ''}
              onChange={(v) => handleStyleChange('right', v)}
              placeholder="auto"
            />
            <TextInput
              label="Bottom"
              value={node.style.bottom || ''}
              onChange={(v) => handleStyleChange('bottom', v)}
              placeholder="auto"
            />
            <TextInput
              label="Left"
              value={node.style.left || ''}
              onChange={(v) => handleStyleChange('left', v)}
              placeholder="auto"
            />
          </div>
        )}
        <TextInput
          label="Z-Index"
          value={node.style.zIndex || ''}
          onChange={(v) => handleStyleChange('zIndex', v)}
          placeholder="auto"
        />
      </CollapsibleSection>

      {/* Effects */}
      <CollapsibleSection title="Effects" defaultOpen={false}>
        <SelectInput
          label="Opacity"
          value={node.style.opacity || '1'}
          onChange={(v) => handleStyleChange('opacity', v)}
          options={[
            { value: '1', label: '100% (Default)' },
            { value: '0.9', label: '90%' },
            { value: '0.8', label: '80%' },
            { value: '0.7', label: '70%' },
            { value: '0.6', label: '60%' },
            { value: '0.5', label: '50%' },
            { value: '0.4', label: '40%' },
            { value: '0.3', label: '30%' },
            { value: '0.2', label: '20%' },
            { value: '0.1', label: '10%' },
            { value: '0', label: '0% (Hidden)' },
          ]}
        />
        <SelectInput
          label="Box Shadow"
          value={node.style.boxShadow || ''}
          onChange={(v) => handleStyleChange('boxShadow', v)}
          options={[
            { value: '', label: 'None' },
            { value: '0 1px 3px rgba(0,0,0,0.12)', label: 'Small' },
            { value: '0 4px 6px rgba(0,0,0,0.1)', label: 'Medium' },
            { value: '0 10px 15px rgba(0,0,0,0.1)', label: 'Large' },
            { value: '0 20px 25px rgba(0,0,0,0.15)', label: 'Extra Large' },
            { value: '0 0 20px rgba(0,120,212,0.5)', label: 'Glow (Blue)' },
            { value: '0 0 20px rgba(16,185,129,0.5)', label: 'Glow (Green)' },
            { value: '0 0 20px rgba(239,68,68,0.5)', label: 'Glow (Red)' },
            { value: 'inset 0 2px 4px rgba(0,0,0,0.1)', label: 'Inner Shadow' },
          ]}
        />
        <SelectInput
          label="Overflow"
          value={node.style.overflow || 'visible'}
          onChange={(v) => handleStyleChange('overflow', v)}
          options={[
            { value: 'visible', label: 'Visible' },
            { value: 'hidden', label: 'Hidden' },
            { value: 'scroll', label: 'Scroll' },
            { value: 'auto', label: 'Auto' },
          ]}
        />
        <SelectInput
          label="Cursor"
          value={(node.style as Record<string, string>).cursor || ''}
          onChange={(v) => handleStyleChange('cursor', v)}
          options={[
            { value: '', label: 'Default' },
            { value: 'pointer', label: 'Pointer (Hand)' },
            { value: 'grab', label: 'Grab' },
            { value: 'move', label: 'Move' },
            { value: 'text', label: 'Text' },
            { value: 'crosshair', label: 'Crosshair' },
            { value: 'not-allowed', label: 'Not Allowed' },
            { value: 'wait', label: 'Wait' },
            { value: 'zoom-in', label: 'Zoom In' },
            { value: 'zoom-out', label: 'Zoom Out' },
          ]}
        />
        <SelectInput
          label="Filter"
          value={(node.style as Record<string, string>).filter || ''}
          onChange={(v) => handleStyleChange('filter', v)}
          options={[
            { value: '', label: 'None' },
            { value: 'blur(2px)', label: 'Blur (Light)' },
            { value: 'blur(5px)', label: 'Blur (Medium)' },
            { value: 'blur(10px)', label: 'Blur (Heavy)' },
            { value: 'grayscale(100%)', label: 'Grayscale' },
            { value: 'grayscale(50%)', label: 'Grayscale (50%)' },
            { value: 'sepia(100%)', label: 'Sepia' },
            { value: 'brightness(1.2)', label: 'Brighten' },
            { value: 'brightness(0.8)', label: 'Darken' },
            { value: 'contrast(1.2)', label: 'High Contrast' },
            { value: 'saturate(1.5)', label: 'Saturate' },
            { value: 'saturate(0.5)', label: 'Desaturate' },
            { value: 'invert(100%)', label: 'Invert' },
            { value: 'hue-rotate(90deg)', label: 'Hue Rotate 90°' },
            { value: 'hue-rotate(180deg)', label: 'Hue Rotate 180°' },
          ]}
        />
        <SelectInput
          label="Backdrop Filter"
          value={(node.style as Record<string, string>).backdropFilter || ''}
          onChange={(v) => handleStyleChange('backdropFilter', v)}
          options={[
            { value: '', label: 'None' },
            { value: 'blur(5px)', label: 'Blur (Glass Effect)' },
            { value: 'blur(10px)', label: 'Blur (Frosted Glass)' },
            { value: 'blur(20px)', label: 'Blur (Heavy)' },
            { value: 'brightness(1.2)', label: 'Brighten Background' },
            { value: 'brightness(0.8)', label: 'Darken Background' },
          ]}
        />
      </CollapsibleSection>

      {/* Animations */}
      <CollapsibleSection title="Animations" defaultOpen={false}>
        <SelectInput
          label="Entrance Animation"
          value={node.props.entranceAnimation as string || ''}
          onChange={(v) => handlePropChange('entranceAnimation', v)}
          options={[
            { value: '', label: 'None' },
            { value: 'fadeIn', label: 'Fade In' },
            { value: 'fadeInUp', label: 'Fade In Up' },
            { value: 'fadeInDown', label: 'Fade In Down' },
            { value: 'fadeInLeft', label: 'Fade In Left' },
            { value: 'fadeInRight', label: 'Fade In Right' },
            { value: 'zoomIn', label: 'Zoom In' },
            { value: 'slideInUp', label: 'Slide In Up' },
            { value: 'slideInDown', label: 'Slide In Down' },
            { value: 'slideInLeft', label: 'Slide In Left' },
            { value: 'slideInRight', label: 'Slide In Right' },
            { value: 'bounceIn', label: 'Bounce In' },
            { value: 'flipInX', label: 'Flip In X' },
            { value: 'flipInY', label: 'Flip In Y' },
            { value: 'rotateIn', label: 'Rotate In' },
          ]}
        />
        {(node.props.entranceAnimation as string) && (
          <>
            <SelectInput
              label="Duration"
              value={node.props.animationDuration as string || '0.6s'}
              onChange={(v) => handlePropChange('animationDuration', v)}
              options={[
                { value: '0.3s', label: 'Fast (0.3s)' },
                { value: '0.6s', label: 'Normal (0.6s)' },
                { value: '1s', label: 'Slow (1s)' },
                { value: '1.5s', label: 'Very Slow (1.5s)' },
              ]}
            />
            <SelectInput
              label="Delay"
              value={node.props.animationDelay as string || '0s'}
              onChange={(v) => handlePropChange('animationDelay', v)}
              options={[
                { value: '0s', label: 'None' },
                { value: '0.1s', label: '0.1s' },
                { value: '0.2s', label: '0.2s' },
                { value: '0.3s', label: '0.3s' },
                { value: '0.5s', label: '0.5s' },
                { value: '1s', label: '1s' },
              ]}
            />
          </>
        )}

        <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
          <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Hover Effects</p>
          <SelectInput
            label="Hover Animation"
            value={node.props.hoverAnimation as string || ''}
            onChange={(v) => handlePropChange('hoverAnimation', v)}
            options={[
              { value: '', label: 'None' },
              { value: 'grow', label: 'Grow' },
              { value: 'shrink', label: 'Shrink' },
              { value: 'lift', label: 'Lift' },
              { value: 'float', label: 'Float' },
              { value: 'pulse', label: 'Pulse' },
              { value: 'bob', label: 'Bob' },
              { value: 'shake', label: 'Shake' },
              { value: 'glow', label: 'Glow' },
              { value: 'shadow', label: 'Shadow' },
              { value: 'shadowGrow', label: 'Shadow Grow' },
            ]}
          />
        </div>

        <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
          <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Transitions</p>
          <TextInput
            label="Transition"
            value={(node.style as Record<string, string>).transition || ''}
            onChange={(v) => handleStyleChange('transition', v)}
            placeholder="all 0.3s ease"
          />
          <TextInput
            label="Transform"
            value={(node.style as Record<string, string>).transform || ''}
            onChange={(v) => handleStyleChange('transform', v)}
            placeholder="none"
          />
        </div>
      </CollapsibleSection>

      {/* Dynamic / Conditional */}
      <CollapsibleSection title="Dynamic" defaultOpen={false}>
        <p className="text-[9px] text-white/40 mb-2">Control visibility and data binding</p>

        <SelectInput
          label="Visibility"
          value={node.props.visibility as string || 'all'}
          onChange={(v) => handlePropChange('visibility', v)}
          options={[
            { value: 'all', label: 'Always Visible' },
            { value: 'desktop', label: 'Desktop Only' },
            { value: 'tablet', label: 'Tablet Only' },
            { value: 'mobile', label: 'Mobile Only' },
            { value: 'desktop-tablet', label: 'Desktop & Tablet' },
            { value: 'tablet-mobile', label: 'Tablet & Mobile' },
            { value: 'hidden', label: 'Hidden' },
          ]}
        />

        <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
          <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Conditional Display</p>
          <SelectInput
            label="Show If"
            value={node.props.conditionalField as string || ''}
            onChange={(v) => handlePropChange('conditionalField', v)}
            options={[
              { value: '', label: 'No Condition' },
              { value: 'user_logged_in', label: 'User is Logged In' },
              { value: 'user_logged_out', label: 'User is Logged Out' },
              { value: 'custom', label: 'Custom Field...' },
            ]}
          />
          {node.props.conditionalField === 'custom' && (
            <>
              <TextInput
                label="Field Name"
                value={node.props.customConditionField as string || ''}
                onChange={(v) => handlePropChange('customConditionField', v)}
                placeholder="e.g., user.role"
              />
              <SelectInput
                label="Operator"
                value={node.props.conditionOperator as string || 'equals'}
                onChange={(v) => handlePropChange('conditionOperator', v)}
                options={[
                  { value: 'equals', label: 'Equals' },
                  { value: 'not_equals', label: 'Not Equals' },
                  { value: 'contains', label: 'Contains' },
                  { value: 'not_empty', label: 'Not Empty' },
                  { value: 'empty', label: 'Is Empty' },
                ]}
              />
              {node.props.conditionOperator !== 'not_empty' && node.props.conditionOperator !== 'empty' && (
                <TextInput
                  label="Value"
                  value={node.props.conditionValue as string || ''}
                  onChange={(v) => handlePropChange('conditionValue', v)}
                  placeholder="Expected value"
                />
              )}
            </>
          )}
        </div>

        <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
          <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Data Binding</p>
          <TextInput
            label="Data Source"
            value={node.props.dataSource as string || ''}
            onChange={(v) => handlePropChange('dataSource', v)}
            placeholder="e.g., {{posts}} or API endpoint"
          />
          {(node.props.dataSource as string) && (
            <>
              <TextInput
                label="Item Variable"
                value={node.props.itemVariable as string || 'item'}
                onChange={(v) => handlePropChange('itemVariable', v)}
                placeholder="item"
              />
              <TextInput
                label="Limit"
                value={node.props.dataLimit as string || ''}
                onChange={(v) => handlePropChange('dataLimit', v)}
                placeholder="No limit"
                type="number"
              />
            </>
          )}
        </div>

        <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
          <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Custom Attributes</p>
          <TextInput
            label="Custom ID"
            value={node.props.customId as string || ''}
            onChange={(v) => handlePropChange('customId', v)}
            placeholder="my-element"
          />
          <TextInput
            label="Custom Classes"
            value={node.props.customClasses as string || ''}
            onChange={(v) => handlePropChange('customClasses', v)}
            placeholder="class1 class2"
          />
          <TextAreaInput
            label="Custom Attributes"
            value={node.props.customAttributes as string || ''}
            onChange={(v) => handlePropChange('customAttributes', v)}
            placeholder='data-aos="fade-up"&#10;aria-label="..."'
          />
        </div>
      </CollapsibleSection>
    </div>
  );

  return (
    <div className="h-full flex flex-col bg-[#252526]">
      {/* Element Info */}
      <div className="px-3 py-2 border-b border-[#3c3c3c]">
        <h3 className="text-xs font-medium text-white/90">
          {componentDef?.name || node.type}
        </h3>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-[#3c3c3c]">
        <button
          onClick={() => setActiveTab('content')}
          className={`flex-1 flex items-center justify-center gap-1.5 py-2 text-[10px] font-medium uppercase tracking-wide transition-colors ${activeTab === 'content'
            ? 'text-white bg-[#0078d4]'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
        >
          <Type className="w-3 h-3" />
          Content
        </button>
        <button
          onClick={() => setActiveTab('style')}
          className={`flex-1 flex items-center justify-center gap-1.5 py-2 text-[10px] font-medium uppercase tracking-wide transition-colors ${activeTab === 'style'
            ? 'text-white bg-[#0078d4]'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
        >
          <Palette className="w-3 h-3" />
          Style
        </button>
        <button
          onClick={() => setActiveTab('advanced')}
          className={`flex-1 flex items-center justify-center gap-1.5 py-2 text-[10px] font-medium uppercase tracking-wide transition-colors ${activeTab === 'advanced'
            ? 'text-white bg-[#0078d4]'
            : 'text-white/50 hover:text-white/80 hover:bg-white/5'
            }`}
        >
          <Settings className="w-3 h-3" />
          Advanced
        </button>
      </div>

      {/* Tab Content */}
      <div className="flex-1 overflow-y-auto">
        {activeTab === 'content' && renderContentTab()}
        {activeTab === 'style' && renderStyleTab()}
        {activeTab === 'advanced' && renderAdvancedTab()}
      </div>

      {/* Media Library Modal */}
      <MediaLibrary
        isOpen={mediaLibraryOpen}
        onClose={() => {
          setMediaLibraryOpen(false);
          setCurrentSlideIndex(null);
        }}
        onSelect={(url, alt) => {
          // Handle slideshow slide image selection
          if (node?.type === 'slideshow' && currentSlideIndex !== null) {
            const slides = normalizeSlides((node.props.slides as any[]) || []);
            slides[currentSlideIndex] = { ...slides[currentSlideIndex], image: url };
            handlePropChange('slides', slides);
            setCurrentSlideIndex(null);
          } else {
            // Handle regular image component
            handlePropChange('src', url);
            if (alt) handlePropChange('alt', alt);
          }
          setMediaLibraryOpen(false);
        }}
      />
    </div>
  );
};

export default memo(PropertiesPanel);
