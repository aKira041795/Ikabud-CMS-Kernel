/**
 * Ikabud Page Builder - Enhanced Component Panel
 * Elementor-level component library with search, categories, favorites, and recent
 */

import React, { memo, useState, useCallback, useMemo, useEffect } from 'react';
import {
  LayoutTemplate,
  Square,
  Columns,
  RectangleVertical,
  Type,
  AlignLeft,
  MousePointer,
  Image,
  Video,
  MoveVertical,
  Minus,
  LayoutGrid,
  Wrench,
  LucideIcon,
  Star,
  SquareAsterisk,
  LayoutList,
  ChevronDown,
  ChevronRight,
  Share2,
  List,
  MousePointerClick,
  X,
  Hash,
  BarChart3,
  Quote,
  Search,
  Clock,
  Heart,
  Sparkles,
  FileInput,
  GalleryHorizontal,
  MapPin,
  Table,
  AlertCircle,
  Link2,
  Users,
  ShoppingBag,
  Plus,
  CreditCard,
  Timer,
  Megaphone,
  RotateCcw,
  ImagePlus,
  Grid3X3,
  ToggleLeft,
  Code,
  Home,
  Zap,
} from 'lucide-react';
import { COMPONENT_CATEGORIES, ComponentDefinition } from '../core/components';
import { DiSyLNode, createNode } from '../core/types';
import LayoutPresetPicker from './LayoutPresetPicker';

const COMPONENT_DND_MIME = 'application/x-cms-component';

// =============================================================================
// Icon Mapping (Extended)
// =============================================================================

const ICON_MAP: Record<string, LucideIcon> = {
  LayoutTemplate,
  Square,
  Columns,
  RectangleVertical,
  Type,
  AlignLeft,
  MousePointer,
  Image,
  Video,
  MoveVertical,
  Minus,
  LayoutGrid,
  Wrench,
  Star,
  SquareAsterisk,
  LayoutList,
  ChevronDown,
  Share2,
  List,
  MousePointerClick,
  Hash,
  BarChart3,
  Quote,
  FileInput,
  GalleryHorizontal,
  MapPin,
  Table,
  AlertCircle,
  Link2,
  Users,
  ShoppingBag,
  // New icons for Jan 2026 components
  CreditCard,
  Timer,
  Megaphone,
  RotateCcw,
  ImagePlus,
  Grid3X3,
  ToggleLeft,
  Code,
  Home,
  Zap,
  Search,
  Images: GalleryHorizontal,
};

function getIcon(iconName: string): LucideIcon {
  return ICON_MAP[iconName] || Square;
}

// =============================================================================
// Local Storage Keys
// =============================================================================

const STORAGE_KEYS = {
  FAVORITES: 'ikabud_pb_favorites',
  RECENT: 'ikabud_pb_recent',
  COLLAPSED_CATEGORIES: 'ikabud_pb_collapsed_cats',
};

// =============================================================================
// Category Colors (Elementor-inspired)
// =============================================================================

const CATEGORY_COLORS: Record<string, { bg: string; border: string; text: string }> = {
  layout: { bg: 'rgba(147, 51, 234, 0.1)', border: '#9333ea', text: '#a855f7' },
  content: { bg: 'rgba(59, 130, 246, 0.1)', border: '#3b82f6', text: '#60a5fa' },
  media: { bg: 'rgba(16, 185, 129, 0.1)', border: '#10b981', text: '#34d399' },
  interactive: { bg: 'rgba(245, 158, 11, 0.1)', border: '#f59e0b', text: '#fbbf24' },
  utility: { bg: 'rgba(107, 114, 128, 0.1)', border: '#6b7280', text: '#9ca3af' },
};

// =============================================================================
// Enhanced Component Item
// =============================================================================

interface EnhancedComponentItemProps {
  component: ComponentDefinition;
  onAdd: (node: DiSyLNode) => void;
  isFavorite: boolean;
  onToggleFavorite: (type: string) => void;
  compact?: boolean;
  showCategory?: boolean;
}

const EnhancedComponentItem: React.FC<EnhancedComponentItemProps> = memo(({
  component,
  onAdd,
  isFavorite,
  onToggleFavorite,
  compact = false,
  showCategory = false,
}) => {
  const Icon = getIcon(component.icon);
  const categoryColor = CATEGORY_COLORS[component.category] || CATEGORY_COLORS.utility;
  
  const handleClick = () => {
    const node = createNode(
      component.type,
      component.defaultProps,
      component.defaultStyle,
      component.defaultChildren || []
    );
    onAdd(node);
  };
  
  const handleDragStart = (e: React.DragEvent) => {
    const payload = JSON.stringify({
      type: component.type,
      props: component.defaultProps,
      style: component.defaultStyle,
      children: component.defaultChildren || [],
    });
    e.dataTransfer.setData(COMPONENT_DND_MIME, payload);
    e.dataTransfer.setData('application/json', payload);
    e.dataTransfer.effectAllowed = 'copy';
  };

  const handleFavoriteClick = (e: React.MouseEvent) => {
    e.stopPropagation();
    onToggleFavorite(component.type);
  };
  
  if (compact) {
    return (
      <button
        onClick={handleClick}
        draggable
        onDragStart={handleDragStart}
        className="group relative flex flex-col items-center gap-1 px-3 py-2 bg-[#2d2d2d] border border-[#3c3c3c] rounded-lg hover:border-[#0078d4] hover:bg-[#353535] cursor-grab active:cursor-grabbing transition-all duration-150 flex-shrink-0"
        style={{ borderLeftColor: categoryColor.border, borderLeftWidth: '3px' }}
        title={component.description}
      >
        <Icon className="w-5 h-5 text-white/60 group-hover:text-[#0078d4] transition-colors" />
        <span className="text-[10px] text-white/70 group-hover:text-white whitespace-nowrap font-medium">
          {component.name}
        </span>
        {showCategory && (
          <span 
            className="absolute -top-1 -right-1 text-[8px] px-1 py-0.5 rounded-full font-medium"
            style={{ backgroundColor: categoryColor.bg, color: categoryColor.text }}
          >
            {component.category.charAt(0).toUpperCase()}
          </span>
        )}
      </button>
    );
  }
  
  return (
    <div className="group relative">
      <button
        onClick={handleClick}
        draggable
        onDragStart={handleDragStart}
        className="w-full flex flex-col items-center gap-1.5 p-3 bg-[#2d2d2d] border border-[#3c3c3c] rounded-lg hover:border-[#0078d4] hover:bg-[#353535] cursor-grab active:cursor-grabbing transition-all duration-150"
        style={{ borderTopColor: categoryColor.border, borderTopWidth: '2px' }}
        title={component.description}
        aria-label={`Add ${component.name} element. ${component.description}`}
      >
        <Icon className="w-6 h-6 text-white/60 group-hover:text-[#0078d4] transition-colors" />
        <span className="text-[11px] text-white/70 group-hover:text-white font-medium text-center leading-tight">
          {component.name}
        </span>
      </button>
      
      {/* Favorite button */}
      <button
        onClick={handleFavoriteClick}
        className={`absolute top-1 right-1 p-1 rounded-full transition-all duration-150 ${
          isFavorite 
            ? 'text-yellow-400 bg-yellow-400/20' 
            : 'text-white/30 hover:text-yellow-400 hover:bg-white/10 opacity-0 group-hover:opacity-100'
        }`}
        title={isFavorite ? 'Remove from favorites' : 'Add to favorites'}
      >
        <Heart className={`w-3 h-3 ${isFavorite ? 'fill-current' : ''}`} />
      </button>
    </div>
  );
});

EnhancedComponentItem.displayName = 'EnhancedComponentItem';

// =============================================================================
// Category Header (Collapsible)
// =============================================================================

interface CategoryHeaderProps {
  category: { id: string; name: string; icon: string };
  isExpanded: boolean;
  onToggle: () => void;
  componentCount: number;
}

const CategoryHeader: React.FC<CategoryHeaderProps> = memo(({
  category,
  isExpanded,
  onToggle,
  componentCount,
}) => {
  const CategoryIcon = ICON_MAP[category.icon] || LayoutGrid;
  const categoryColor = CATEGORY_COLORS[category.id] || CATEGORY_COLORS.utility;
  
  return (
    <button
      onClick={onToggle}
      className="w-full flex items-center gap-2 px-2 py-2 hover:bg-white/5 rounded-lg transition-colors group"
    >
      <div 
        className="p-1.5 rounded-md"
        style={{ backgroundColor: categoryColor.bg }}
      >
        <CategoryIcon className="w-4 h-4" style={{ color: categoryColor.text }} />
      </div>
      <span className="flex-1 text-left text-xs font-semibold text-white/80 group-hover:text-white">
        {category.name}
      </span>
      <span className="text-[10px] text-white/40 mr-1">{componentCount}</span>
      {isExpanded ? (
        <ChevronDown className="w-4 h-4 text-white/40" />
      ) : (
        <ChevronRight className="w-4 h-4 text-white/40" />
      )}
    </button>
  );
});

CategoryHeader.displayName = 'CategoryHeader';

// =============================================================================
// Horizontal Category Dropdown
// =============================================================================

interface HorizontalCategoryDropdownProps {
  category: { id: string; name: string; icon: string; components: ComponentDefinition[] };
  onAddComponent: (node: DiSyLNode) => void;
  favorites: string[];
  onToggleFavorite: (type: string) => void;
}

const HorizontalCategoryDropdown: React.FC<HorizontalCategoryDropdownProps> = memo(({
  category,
  onAddComponent,
  favorites,
  onToggleFavorite,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const buttonRef = React.useRef<HTMLButtonElement>(null);
  const [dropdownPosition, setDropdownPosition] = useState({ left: 0, bottom: 0 });
  const CategoryIcon = ICON_MAP[category.icon] || LayoutGrid;
  const categoryColor = CATEGORY_COLORS[category.id] || CATEGORY_COLORS.utility;
  
  const handleToggle = useCallback(() => {
    if (!isOpen && buttonRef.current) {
      const rect = buttonRef.current.getBoundingClientRect();
      setDropdownPosition({
        left: rect.left,
        bottom: window.innerHeight - rect.top + 8,
      });
    }
    setIsOpen(!isOpen);
  }, [isOpen]);
  
  return (
    <div className="relative">
      <button
        ref={buttonRef}
        onClick={handleToggle}
        className={`flex items-center gap-2 px-3 py-2 rounded-lg border transition-all duration-150 ${
          isOpen 
            ? 'bg-[#353535] border-[#0078d4]' 
            : 'bg-[#2d2d2d] border-[#3c3c3c] hover:border-[#0078d4]'
        }`}
        style={{ borderLeftColor: categoryColor.border, borderLeftWidth: '3px' }}
      >
        <CategoryIcon className="w-4 h-4" style={{ color: categoryColor.text }} />
        <span className="text-xs font-medium text-white/80">{category.name}</span>
        <span className="text-[10px] text-white/40 bg-white/10 px-1.5 py-0.5 rounded-full">
          {category.components.length}
        </span>
        <ChevronDown className={`w-3 h-3 text-white/40 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
      </button>
      
      {isOpen && (
        <>
          {/* Backdrop */}
          <div 
            className="fixed inset-0 z-40" 
            onClick={() => setIsOpen(false)}
          />
          
          {/* Dropdown - using fixed positioning to escape overflow:hidden */}
          <div 
            className="fixed p-3 bg-[#1e1e1e] border border-[#3c3c3c] rounded-xl shadow-2xl z-50 min-w-[280px] max-h-[400px] overflow-y-auto"
            style={{ 
              left: dropdownPosition.left,
              bottom: dropdownPosition.bottom,
              boxShadow: '0 -10px 40px rgba(0,0,0,0.5)',
              borderTopColor: categoryColor.border,
              borderTopWidth: '2px',
            }}
          >
            <div className="flex items-center gap-2 mb-3 pb-2 border-b border-[#3c3c3c]">
              <CategoryIcon className="w-5 h-5" style={{ color: categoryColor.text }} />
              <span className="text-sm font-semibold text-white">{category.name}</span>
            </div>
            
            <div className="grid grid-cols-3 gap-2">
              {category.components.map(component => (
                <EnhancedComponentItem
                  key={component.type}
                  component={component}
                  onAdd={(node) => {
                    onAddComponent(node);
                    setIsOpen(false);
                  }}
                  isFavorite={favorites.includes(component.type)}
                  onToggleFavorite={onToggleFavorite}
                />
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
});

HorizontalCategoryDropdown.displayName = 'HorizontalCategoryDropdown';

// =============================================================================
// Section Wizard
// =============================================================================

interface ColumnPreset {
  id: string;
  name: string;
  columns: number[];
  icon: React.ReactNode;
}

const COLUMN_PRESETS: ColumnPreset[] = [
  { id: '1', name: '1 Column', columns: [100], icon: <div className="w-full h-4 bg-white/30 rounded-sm" /> },
  { id: '2-equal', name: '2 Equal', columns: [50, 50], icon: <div className="flex gap-0.5 w-full"><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '3-equal', name: '3 Equal', columns: [33, 34, 33], icon: <div className="flex gap-0.5 w-full"><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '4-equal', name: '4 Equal', columns: [25, 25, 25, 25], icon: <div className="flex gap-0.5 w-full"><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-1-3', name: '1/3 + 2/3', columns: [33, 67], icon: <div className="flex gap-0.5 w-full"><div className="w-1/3 h-4 bg-white/30 rounded-sm" /><div className="w-2/3 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-3-1', name: '2/3 + 1/3', columns: [67, 33], icon: <div className="flex gap-0.5 w-full"><div className="w-2/3 h-4 bg-white/30 rounded-sm" /><div className="w-1/3 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '3-1-2-1', name: '1/4 + 1/2 + 1/4', columns: [25, 50, 25], icon: <div className="flex gap-0.5 w-full"><div className="w-1/4 h-4 bg-white/30 rounded-sm" /><div className="w-1/2 h-4 bg-white/30 rounded-sm" /><div className="w-1/4 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-1-4', name: '1/4 + 3/4', columns: [25, 75], icon: <div className="flex gap-0.5 w-full"><div className="w-1/4 h-4 bg-white/30 rounded-sm" /><div className="w-3/4 h-4 bg-white/30 rounded-sm" /></div> },
];

interface SectionWizardProps {
  onSelect: (node: DiSyLNode) => void;
  onClose: () => void;
}

const SectionWizard: React.FC<SectionWizardProps> = ({ onSelect, onClose }) => {
  const createSectionWithColumns = (preset: ColumnPreset) => {
    // Use flex-grow ratios for column widths - more reliable than calc()
    // For [50, 50] -> both get flex: 1, for [33, 67] -> flex: 1 and flex: 2
    const columns = preset.columns.map((width) => 
      createNode('column', {}, { 
        // Use flex with grow ratio based on percentage (e.g., 50% = 1, 33% ≈ 1, 67% ≈ 2)
        flex: `${Math.round(width / 25)} 1 0%`,
        padding: '16px',
        minHeight: '100px',
        boxSizing: 'border-box',
      }, [])
    );
    
    const row = createNode('row', {}, {
      display: 'flex',
      flexDirection: 'row',
      gap: '24px',
      width: '100%',
    }, columns);
    
    const container = createNode('container', {}, {
      width: '100%',
      maxWidth: '1200px',
      margin: '0 auto',
      padding: '0 24px',
      boxSizing: 'border-box',
    }, [row]);
    
    const section = createNode('section', {}, {
      padding: '48px 0',
      width: '100%',
    }, [container]);
    
    onSelect(section);
    onClose();
  };
  
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
      <div className="bg-[#1e1e1e] border border-[#3c3c3c] rounded-2xl p-6 w-[400px] shadow-2xl">
        <div className="flex items-center justify-between mb-6">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-lg" style={{ backgroundColor: CATEGORY_COLORS.layout.bg }}>
              <LayoutTemplate className="w-5 h-5" style={{ color: CATEGORY_COLORS.layout.text }} />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white">Choose Section Layout</h3>
              <p className="text-xs text-white/50">Select a column structure</p>
            </div>
          </div>
          <button 
            onClick={onClose}
            className="p-2 text-white/50 hover:text-white hover:bg-white/10 rounded-lg transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        
        <div className="grid grid-cols-4 gap-3">
          {COLUMN_PRESETS.map(preset => (
            <button
              key={preset.id}
              onClick={() => createSectionWithColumns(preset)}
              className="flex flex-col items-center gap-2 p-3 bg-[#2d2d2d] border border-[#3c3c3c] rounded-xl hover:border-[#0078d4] hover:bg-[#353535] transition-all group"
              title={preset.name}
            >
              <div className="w-full">{preset.icon}</div>
              <span className="text-[9px] text-white/50 group-hover:text-white/80 text-center leading-tight">
                {preset.name}
              </span>
            </button>
          ))}
        </div>
        
        <div className="mt-6 pt-4 border-t border-[#3c3c3c] flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 py-2.5 text-xs text-white/60 hover:text-white hover:bg-white/5 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={() => {
              const section = createNode('section', {}, {
                padding: '48px 0',
                width: '100%',
                minHeight: '200px',
              }, []);
              onSelect(section);
              onClose();
            }}
            className="flex-1 py-2.5 text-xs text-white bg-[#0078d4] hover:bg-[#006cbd] rounded-lg transition-colors font-medium"
          >
            Empty Section
          </button>
        </div>
      </div>
    </div>
  );
};

// =============================================================================
// Enhanced Component Panel
// =============================================================================

interface ComponentPanelEnhancedProps {
  onAddComponent: (node: DiSyLNode) => void;
  horizontal?: boolean;
}

const ComponentPanelEnhanced: React.FC<ComponentPanelEnhancedProps> = ({ 
  onAddComponent, 
  horizontal = false 
}) => {
  // State
  const [searchQuery, setSearchQuery] = useState('');
  const [showSectionWizard, setShowSectionWizard] = useState(false);
  const [showLayoutPresetPicker, setShowLayoutPresetPicker] = useState(false);
  const [activeTab, setActiveTab] = useState<'all' | 'favorites' | 'recent'>('all');
  const [collapsedCategories, setCollapsedCategories] = useState<string[]>(() => {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEYS.COLLAPSED_CATEGORIES) || '[]');
    } catch {
      return [];
    }
  });
  const [favorites, setFavorites] = useState<string[]>(() => {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEYS.FAVORITES) || '[]');
    } catch {
      return [];
    }
  });
  const [recentlyUsed, setRecentlyUsed] = useState<string[]>(() => {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEYS.RECENT) || '[]');
    } catch {
      return [];
    }
  });

  // Persist to localStorage
  useEffect(() => {
    localStorage.setItem(STORAGE_KEYS.FAVORITES, JSON.stringify(favorites));
  }, [favorites]);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEYS.RECENT, JSON.stringify(recentlyUsed));
  }, [recentlyUsed]);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEYS.COLLAPSED_CATEGORIES, JSON.stringify(collapsedCategories));
  }, [collapsedCategories]);

  // All components flattened
  const allComponents = useMemo(() => 
    COMPONENT_CATEGORIES.flatMap(cat => cat.components),
    []
  );

  // Filtered components based on search
  const filteredComponents = useMemo(() => {
    if (!searchQuery.trim()) return null;
    const query = searchQuery.toLowerCase();
    return allComponents.filter(c => 
      c.name.toLowerCase().includes(query) ||
      c.description.toLowerCase().includes(query) ||
      c.category.toLowerCase().includes(query) ||
      c.type.toLowerCase().includes(query)
    );
  }, [searchQuery, allComponents]);

  // Favorite components
  const favoriteComponents = useMemo(() => 
    allComponents.filter(c => favorites.includes(c.type)),
    [allComponents, favorites]
  );

  // Recent components
  const recentComponents = useMemo(() => 
    recentlyUsed
      .map(type => allComponents.find(c => c.type === type))
      .filter((c): c is ComponentDefinition => c !== undefined)
      .slice(0, 8),
    [recentlyUsed, allComponents]
  );

  // Toggle favorite
  const toggleFavorite = useCallback((type: string) => {
    setFavorites(prev => 
      prev.includes(type) 
        ? prev.filter(t => t !== type)
        : [...prev, type]
    );
  }, []);

  // Toggle category collapse
  const toggleCategory = useCallback((categoryId: string) => {
    setCollapsedCategories(prev =>
      prev.includes(categoryId)
        ? prev.filter(id => id !== categoryId)
        : [...prev, categoryId]
    );
  }, []);

  // Add to recent
  const addToRecent = useCallback((type: string) => {
    setRecentlyUsed(prev => {
      const filtered = prev.filter(t => t !== type);
      return [type, ...filtered].slice(0, 20);
    });
  }, []);

  // Handle add component
  const handleAddComponent = useCallback((node: DiSyLNode) => {
    if (node.type === 'section') {
      setShowSectionWizard(true);
      return;
    }
    if (node.type === 'container') {
      setShowLayoutPresetPicker(true);
      return;
    }
    addToRecent(node.type);
    onAddComponent(node);
  }, [onAddComponent, addToRecent]);

  // Horizontal layout for bottom drawer
  if (horizontal) {
    return (
      <div 
        className="h-full flex items-center bg-[#1e1e1e] border-t border-[#3c3c3c]"
        role="region"
        aria-label="Component library"
      >
        {/* Quick Add Button */}
        <div className="flex items-center gap-2 px-4 border-r border-[#3c3c3c] h-full">
          <div className="flex items-center gap-1.5 text-white/60">
            <Plus className="w-4 h-4" />
            <span className="text-xs font-medium">Add</span>
          </div>
        </div>

        {/* Search */}
        <div className="relative px-3">
          <Search className="absolute left-5 top-1/2 -translate-y-1/2 w-4 h-4 text-white/40" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search..."
            className="w-40 pl-8 pr-3 py-1.5 text-xs bg-[#2d2d2d] border border-[#3c3c3c] rounded-lg text-white placeholder-white/40 focus:outline-none focus:border-[#0078d4]"
          />
        </div>

        {/* Quick Access: Recent */}
        {recentComponents.length > 0 && !searchQuery && (
          <div className="flex items-center gap-2 px-3 border-l border-[#3c3c3c] h-full">
            <Clock className="w-4 h-4 text-white/40" />
            <div className="flex items-center gap-1.5">
              {recentComponents.slice(0, 4).map(component => (
                <EnhancedComponentItem
                  key={component.type}
                  component={component}
                  onAdd={handleAddComponent}
                  isFavorite={favorites.includes(component.type)}
                  onToggleFavorite={toggleFavorite}
                  compact
                />
              ))}
            </div>
          </div>
        )}

        {/* Search Results */}
        {filteredComponents && (
          <div className="flex items-center gap-2 px-3 overflow-x-auto flex-1">
            {filteredComponents.length === 0 ? (
              <span className="text-xs text-white/40">No components found</span>
            ) : (
              filteredComponents.map(component => (
                <EnhancedComponentItem
                  key={component.type}
                  component={component}
                  onAdd={handleAddComponent}
                  isFavorite={favorites.includes(component.type)}
                  onToggleFavorite={toggleFavorite}
                  compact
                  showCategory
                />
              ))
            )}
          </div>
        )}

        {/* Category Dropdowns */}
        {!searchQuery && (
          <div className="flex items-center gap-2 px-3 overflow-x-auto flex-1">
            {COMPONENT_CATEGORIES.map(category => (
              <HorizontalCategoryDropdown
                key={category.id}
                category={category}
                onAddComponent={handleAddComponent}
                favorites={favorites}
                onToggleFavorite={toggleFavorite}
              />
            ))}
          </div>
        )}
        
        {showSectionWizard && (
          <SectionWizard 
            onSelect={(node) => {
              addToRecent('section');
              onAddComponent(node);
            }} 
            onClose={() => setShowSectionWizard(false)} 
          />
        )}
        
        {showLayoutPresetPicker && (
          <LayoutPresetPicker 
            onSelect={(node) => {
              addToRecent('container');
              onAddComponent(node);
            }} 
            onClose={() => setShowLayoutPresetPicker(false)} 
          />
        )}
      </div>
    );
  }
  
  // Vertical layout (default - sidebar)
  return (
    <div 
      className="h-full flex flex-col bg-[#1e1e1e]"
      role="region"
      aria-label="Component library"
    >
      {/* Header with Search */}
      <div className="p-3 border-b border-[#3c3c3c]">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/40" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search components..."
            className="w-full pl-9 pr-3 py-2 text-xs bg-[#2d2d2d] border border-[#3c3c3c] rounded-lg text-white placeholder-white/40 focus:outline-none focus:border-[#0078d4]"
          />
          {searchQuery && (
            <button
              onClick={() => setSearchQuery('')}
              className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-white/40 hover:text-white"
            >
              <X className="w-3 h-3" />
            </button>
          )}
        </div>
        
        {/* Tabs */}
        <div className="flex gap-1 mt-3">
          <button
            onClick={() => setActiveTab('all')}
            className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[10px] font-medium rounded-md transition-colors ${
              activeTab === 'all' 
                ? 'bg-[#0078d4] text-white' 
                : 'text-white/60 hover:text-white hover:bg-white/5'
            }`}
          >
            <LayoutGrid className="w-3 h-3" />
            All
          </button>
          <button
            onClick={() => setActiveTab('favorites')}
            className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[10px] font-medium rounded-md transition-colors ${
              activeTab === 'favorites' 
                ? 'bg-[#0078d4] text-white' 
                : 'text-white/60 hover:text-white hover:bg-white/5'
            }`}
          >
            <Heart className="w-3 h-3" />
            Favorites
            {favorites.length > 0 && (
              <span className="text-[9px] bg-white/20 px-1 rounded">{favorites.length}</span>
            )}
          </button>
          <button
            onClick={() => setActiveTab('recent')}
            className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 text-[10px] font-medium rounded-md transition-colors ${
              activeTab === 'recent' 
                ? 'bg-[#0078d4] text-white' 
                : 'text-white/60 hover:text-white hover:bg-white/5'
            }`}
          >
            <Clock className="w-3 h-3" />
            Recent
          </button>
        </div>
      </div>
      
      {/* Content */}
      <div className="flex-1 overflow-y-auto p-3">
        {showSectionWizard && (
          <SectionWizard 
            onSelect={(node) => {
              addToRecent('section');
              onAddComponent(node);
            }} 
            onClose={() => setShowSectionWizard(false)} 
          />
        )}
        
        {showLayoutPresetPicker && (
          <LayoutPresetPicker 
            onSelect={(node) => {
              addToRecent('container');
              onAddComponent(node);
            }} 
            onClose={() => setShowLayoutPresetPicker(false)} 
          />
        )}

        {/* Search Results */}
        {filteredComponents && (
          <div>
            <div className="flex items-center gap-2 mb-3">
              <Search className="w-4 h-4 text-white/40" />
              <span className="text-xs text-white/60">
                {filteredComponents.length} result{filteredComponents.length !== 1 ? 's' : ''} for "{searchQuery}"
              </span>
            </div>
            {filteredComponents.length === 0 ? (
              <div className="text-center py-8">
                <Sparkles className="w-8 h-8 text-white/20 mx-auto mb-2" />
                <p className="text-xs text-white/40">No components found</p>
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-2">
                {filteredComponents.map(component => (
                  <EnhancedComponentItem
                    key={component.type}
                    component={component}
                    onAdd={handleAddComponent}
                    isFavorite={favorites.includes(component.type)}
                    onToggleFavorite={toggleFavorite}
                  />
                ))}
              </div>
            )}
          </div>
        )}

        {/* Favorites Tab */}
        {!filteredComponents && activeTab === 'favorites' && (
          <div>
            {favoriteComponents.length === 0 ? (
              <div className="text-center py-8">
                <Heart className="w-8 h-8 text-white/20 mx-auto mb-2" />
                <p className="text-xs text-white/40">No favorites yet</p>
                <p className="text-[10px] text-white/30 mt-1">Click the heart icon on any component</p>
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-2">
                {favoriteComponents.map(component => (
                  <EnhancedComponentItem
                    key={component.type}
                    component={component}
                    onAdd={handleAddComponent}
                    isFavorite={true}
                    onToggleFavorite={toggleFavorite}
                  />
                ))}
              </div>
            )}
          </div>
        )}

        {/* Recent Tab */}
        {!filteredComponents && activeTab === 'recent' && (
          <div>
            {recentComponents.length === 0 ? (
              <div className="text-center py-8">
                <Clock className="w-8 h-8 text-white/20 mx-auto mb-2" />
                <p className="text-xs text-white/40">No recent components</p>
                <p className="text-[10px] text-white/30 mt-1">Components you use will appear here</p>
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-2">
                {recentComponents.map(component => (
                  <EnhancedComponentItem
                    key={component.type}
                    component={component}
                    onAdd={handleAddComponent}
                    isFavorite={favorites.includes(component.type)}
                    onToggleFavorite={toggleFavorite}
                  />
                ))}
              </div>
            )}
          </div>
        )}

        {/* All Categories */}
        {!filteredComponents && activeTab === 'all' && (
          <div className="space-y-2">
            {COMPONENT_CATEGORIES.map(category => {
              const isExpanded = !collapsedCategories.includes(category.id);
              
              return (
                <div key={category.id} className="rounded-lg overflow-hidden">
                  <CategoryHeader
                    category={category}
                    isExpanded={isExpanded}
                    onToggle={() => toggleCategory(category.id)}
                    componentCount={category.components.length}
                  />
                  
                  {isExpanded && (
                    <div className="grid grid-cols-3 gap-2 p-2 bg-[#252526] rounded-b-lg">
                      {category.components.map(component => (
                        <EnhancedComponentItem
                          key={component.type}
                          component={component}
                          onAdd={handleAddComponent}
                          isFavorite={favorites.includes(component.type)}
                          onToggleFavorite={toggleFavorite}
                        />
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};

export default memo(ComponentPanelEnhanced);
