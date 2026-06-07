/**
 * GovernedComponentsPanel — DiSyL Contract Composer (Phase 7 / 5.2)
 *
 * Fetches governed DiSyL components from /api/v1/cms/builder/components
 * and renders them in the builder sidebar. Each component exposes its
 * attribute schema so the PropertiesPanel can show typed editors.
 *
 * Also provides entity-view source + view pickers for entity_list/entity_detail.
 */

import React, { memo, useState, useMemo, useCallback } from 'react';
import {
  LayoutGrid, Database, BarChart3, FileText, Shield, Sparkles,
  Search, X, ChevronDown, ChevronRight, RefreshCw, ExternalLink,
} from 'lucide-react';
import { useGovernedComponents } from '../core/useGovernedComponents';
import type { GovernedComponent, GovernedAttribute } from '../core/types';
import { createNode } from '../core/types';
import type { DiSyLNode } from '../core/types';

// =============================================================================
// Category icons for governed components
// =============================================================================

const GOV_CATEGORY_ICONS: Record<string, React.ReactNode> = {
  structural: <LayoutGrid className="w-4 h-4" />,
  data: <Database className="w-4 h-4" />,
  form: <FileText className="w-4 h-4" />,
  interactive: <BarChart3 className="w-4 h-4" />,
  layout: <LayoutGrid className="w-4 h-4" />,
  content: <FileText className="w-4 h-4" />,
  report: <FileText className="w-4 h-4" />,
  ai: <Sparkles className="w-4 h-4" />,
};

const GOV_CATEGORY_COLORS: Record<string, string> = {
  structural: '#9333ea',
  data: '#3b82f6',
  form: '#10b981',
  interactive: '#f59e0b',
  layout: '#6366f1',
  content: '#14b8a6',
  report: '#f97316',
  ai: '#ec4899',
};

// =============================================================================
// Governed Component Item
// =============================================================================

interface GovComponentItemProps {
  component: GovernedComponent;
  onAdd: (node: DiSyLNode) => void;
}

const GovComponentItem: React.FC<GovComponentItemProps> = memo(({ component, onAdd }) => {
  const [expanded, setExpanded] = useState(false);
  const catColor = GOV_CATEGORY_COLORS[component.category] || '#6366f1';
  const catIcon = GOV_CATEGORY_ICONS[component.category] || <LayoutGrid className="w-4 h-4" />;

  const handleAdd = () => {
    // Build a DiSyL node from the governed component definition
    const props: Record<string, unknown> = {
      _governed: true,
      _governedName: component.name,
    };
    // Apply default values from attribute schema
    for (const attr of component.attributes) {
      if (attr.default !== null && attr.default !== undefined) {
        props[attr.name] = attr.default;
      }
    }
    const node = createNode(
      'entity_view' as any,
      props,
      {
        padding: '16px',
        border: '1px dashed #6366f1',
        borderRadius: '8px',
        backgroundColor: 'rgba(99,102,241,.05)',
        minHeight: '60px',
      },
      []
    );
    onAdd(node);
  };

  const requiredAttrs = component.attributes.filter(a => a.required);
  const optionalAttrs = component.attributes.filter(a => !a.required);

  return (
    <div
      className="group border border-[#3c3c3c] rounded-lg hover:border-[#0078d4] transition-all duration-150 overflow-hidden"
      style={{ borderLeftColor: catColor, borderLeftWidth: '3px' }}
    >
      {/* Header */}
      <button
        onClick={handleAdd}
        className="w-full flex items-center gap-2 px-3 py-2.5 bg-[#2d2d2d] hover:bg-[#353535] text-left"
        title={component.description}
      >
        <span className="text-white/70">{catIcon}</span>
        <div className="flex-1 min-w-0">
          <div className="text-xs font-semibold text-white/90 truncate">{component.label}</div>
          <div className="text-[10px] text-white/40 truncate">{component.name}</div>
        </div>
        <button
          onClick={(e) => { e.stopPropagation(); setExpanded(!expanded); }}
          className="p-0.5 text-white/30 hover:text-white/70"
          title={expanded ? 'Hide schema' : 'Show schema'}
        >
          {expanded ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
        </button>
        <span className="text-[10px] text-white/20 group-hover:text-white/40">Add</span>
      </button>

      {/* Expanded attribute schema */}
      {expanded && (
        <div className="px-3 py-2 bg-[#1a1a1a] border-t border-[#3c3c3c] text-[10px] space-y-1.5">
          {component.description && (
            <p className="text-white/50 italic mb-2">{component.description}</p>
          )}
          {requiredAttrs.length > 0 && (
            <div>
              <span className="text-red-400/80 font-semibold">Required:</span>
              <div className="flex flex-wrap gap-1 mt-0.5">
                {requiredAttrs.map(a => (
                  <span key={a.name} className="px-1.5 py-0.5 bg-red-400/10 text-red-300 rounded font-mono">
                    {a.name}:{a.type}
                  </span>
                ))}
              </div>
            </div>
          )}
          {optionalAttrs.length > 0 && (
            <div>
              <span className="text-white/40 font-semibold">Optional:</span>
              <div className="flex flex-wrap gap-1 mt-0.5">
                {optionalAttrs.slice(0, 6).map(a => (
                  <span key={a.name} className="px-1.5 py-0.5 bg-white/5 text-white/50 rounded font-mono">
                    {a.name}:{a.type}
                  </span>
                ))}
                {optionalAttrs.length > 6 && (
                  <span className="text-white/30">+{optionalAttrs.length - 6} more</span>
                )}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
});

GovComponentItem.displayName = 'GovComponentItem';

// =============================================================================
// Main Panel
// =============================================================================

interface GovernedComponentsPanelProps {
  onAddComponent: (node: DiSyLNode) => void;
}

const GovernedComponentsPanel: React.FC<GovernedComponentsPanelProps> = ({ onAddComponent }) => {
  const { components, loading, error } = useGovernedComponents();
  const [searchQuery, setSearchQuery] = useState('');
  const [collapsedCategories, setCollapsedCategories] = useState<Set<string>>(new Set());

  // Group by category
  const byCategory = useMemo(() => {
    const map = new Map<string, GovernedComponent[]>();
    for (const c of components) {
      const cat = c.category || 'other';
      if (!map.has(cat)) map.set(cat, []);
      map.get(cat)!.push(c);
    }
    // Sort categories
    return new Map([...map.entries()].sort(([a], [b]) => a.localeCompare(b)));
  }, [components]);

  // Filter by search
  const filteredCategories = useMemo(() => {
    if (!searchQuery.trim()) return byCategory;
    const q = searchQuery.toLowerCase();
    const filtered = new Map<string, GovernedComponent[]>();
    for (const [cat, comps] of byCategory) {
      const matches = comps.filter(c =>
        c.name.toLowerCase().includes(q) ||
        c.label.toLowerCase().includes(q) ||
        c.description.toLowerCase().includes(q)
      );
      if (matches.length > 0) filtered.set(cat, matches);
    }
    return filtered;
  }, [byCategory, searchQuery]);

  const toggleCategory = useCallback((cat: string) => {
    setCollapsedCategories(prev => {
      const next = new Set(prev);
      if (next.has(cat)) next.delete(cat); else next.add(cat);
      return next;
    });
  }, []);

  // Loading
  if (loading) {
    return (
      <div className="p-4 text-center text-white/40 text-xs">
        <RefreshCw className="w-4 h-4 mx-auto mb-2 animate-spin" />
        Loading governed components...
      </div>
    );
  }

  // Error
  if (error) {
    return (
      <div className="p-4 text-center text-red-400/70 text-xs">
        <Shield className="w-4 h-4 mx-auto mb-2" />
        Failed to load components
        <br />
        <span className="text-white/30">{error}</span>
      </div>
    );
  }

  // Empty
  if (components.length === 0) {
    return (
      <div className="p-4 text-center text-white/30 text-xs">
        <LayoutGrid className="w-4 h-4 mx-auto mb-2 opacity-50" />
        No governed components registered.
        <br />
        Add components via <code className="text-white/40">ComponentRegistry</code>.
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="px-3 py-2 border-b border-[#3c3c3c] flex items-center gap-2">
        <Shield className="w-4 h-4 text-[#6366f1]" />
        <span className="text-xs font-semibold text-white/80 flex-1">Governed</span>
        <span className="text-[10px] text-white/40 bg-white/10 px-1.5 py-0.5 rounded-full">
          {components.length}
        </span>
      </div>

      {/* Search */}
      <div className="px-3 py-2">
        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-white/30" />
          <input
            type="text"
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
            placeholder="Filter governed components..."
            className="w-full pl-8 pr-3 py-1.5 text-[11px] bg-[#2d2d2d] border border-[#3c3c3c] rounded-md text-white placeholder-white/30 focus:outline-none focus:border-[#6366f1]"
          />
          {searchQuery && (
            <button onClick={() => setSearchQuery('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-white/30 hover:text-white">
              <X className="w-3 h-3" />
            </button>
          )}
        </div>
      </div>

      {/* Component List */}
      <div className="flex-1 overflow-y-auto px-2 pb-4 space-y-3">
        {[...filteredCategories.entries()].map(([category, comps]) => {
          const isCollapsed = collapsedCategories.has(category);
          const catColor = GOV_CATEGORY_COLORS[category] || '#6366f1';
          const catIcon = GOV_CATEGORY_ICONS[category] || <LayoutGrid className="w-3 h-3" />;

          return (
            <div key={category}>
              <button
                onClick={() => toggleCategory(category)}
                className="w-full flex items-center gap-2 px-2 py-1.5 hover:bg-white/5 rounded text-xs font-medium text-white/60 hover:text-white/80 transition-colors"
              >
                <span style={{ color: catColor }}>{catIcon}</span>
                <span className="flex-1 text-left capitalize">{category}</span>
                <span className="text-[10px] text-white/30">{comps.length}</span>
                {isCollapsed ? <ChevronRight className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
              </button>

              {!isCollapsed && (
                <div className="space-y-1.5 mt-1 pl-6 pr-1">
                  {comps.map(c => (
                    <GovComponentItem key={c.name} component={c} onAdd={onAddComponent} />
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* Footer hint */}
      <div className="px-3 py-2 border-t border-[#3c3c3c] text-[10px] text-white/25 text-center">
        Governed components resolve through CapabilityBus
        <br />
        <span className="text-white/15">Phase 7 · Builder Contract Composer</span>
      </div>
    </div>
  );
};

export default memo(GovernedComponentsPanel);
