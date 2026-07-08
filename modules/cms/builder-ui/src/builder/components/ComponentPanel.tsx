/**
 * Ikabud Page Builder - Component Panel
 * Displays available components for drag & drop
 */

import React, { memo, useState } from 'react';
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
  Share2,
  List,
  MousePointerClick,
  X,
  Hash,
  BarChart3,
  Quote,
} from 'lucide-react';
import { COMPONENT_CATEGORIES, ComponentDefinition } from '../core/components';
import { DiSyLNode, createNode } from '../core/types';
import { buildRatioFlexValue } from '../core/layoutSizing';
import LayoutPresetPicker from './LayoutPresetPicker';

const COMPONENT_DND_MIME = 'application/x-cms-component';
const RENDER_DEFAULT_LAYOUT_TYPES = new Set(['section', 'container', 'layout_container', 'row', 'column']);

function newNodeStyle(component: ComponentDefinition) {
  return RENDER_DEFAULT_LAYOUT_TYPES.has(component.type) ? {} : component.defaultStyle;
}

// =============================================================================
// Icon Mapping
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
};

function getIcon(iconName: string): LucideIcon {
  return ICON_MAP[iconName] || Square;
}

// =============================================================================
// Component Item
// =============================================================================

interface ComponentItemProps {
  component: ComponentDefinition;
  onAdd: (node: DiSyLNode) => void;
}

const ComponentItem: React.FC<ComponentItemProps> = memo(({ component, onAdd }) => {
  const Icon = getIcon(component.icon);

  const handleClick = () => {
    const node = createNode(
      component.type,
      component.defaultProps,
      newNodeStyle(component),
      component.defaultChildren || []
    );
    onAdd(node);
  };

  const handleDragStart = (e: React.DragEvent) => {
    const payload = JSON.stringify({
      type: component.type,
      props: component.defaultProps,
      style: newNodeStyle(component),
      children: component.defaultChildren || [],
    });
    e.dataTransfer.setData(COMPONENT_DND_MIME, payload);
    e.dataTransfer.setData('application/json', payload);
    e.dataTransfer.effectAllowed = 'copy';
  };

  return (
    <button
      onClick={handleClick}
      draggable
      onDragStart={handleDragStart}
      className="component-item flex flex-col items-center gap-1.5 p-2.5 bg-[#2d2d2d] border border-[#3c3c3c] hover:border-[#0078d4] hover:bg-[#353535] cursor-grab active:cursor-grabbing group focus:outline-none focus:ring-2 focus:ring-[#0078d4] focus:ring-offset-1 focus:ring-offset-[#252526]"
      title={component.description}
      aria-label={`Add ${component.name} element. ${component.description}`}
      role="button"
    >
      <Icon className="w-4 h-4 text-white/50 group-hover:text-[#0078d4]" aria-hidden="true" />
      <span className="text-[10px] text-white/60 group-hover:text-white/90">{component.name}</span>
    </button>
  );
});

ComponentItem.displayName = 'ComponentItem';

// =============================================================================
// Section Wizard - Column Presets
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
  { id: '3-equal', name: '3 Equal', columns: [33.333, 33.334, 33.333], icon: <div className="flex gap-0.5 w-full"><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '4-equal', name: '4 Equal', columns: [25, 25, 25, 25], icon: <div className="flex gap-0.5 w-full"><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /><div className="flex-1 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-1-3', name: '1/3 + 2/3', columns: [33.333, 66.667], icon: <div className="flex gap-0.5 w-full"><div className="w-1/3 h-4 bg-white/30 rounded-sm" /><div className="w-2/3 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-3-1', name: '2/3 + 1/3', columns: [66.667, 33.333], icon: <div className="flex gap-0.5 w-full"><div className="w-2/3 h-4 bg-white/30 rounded-sm" /><div className="w-1/3 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '3-1-2-1', name: '1/4 + 1/2 + 1/4', columns: [25, 50, 25], icon: <div className="flex gap-0.5 w-full"><div className="w-1/4 h-4 bg-white/30 rounded-sm" /><div className="w-1/2 h-4 bg-white/30 rounded-sm" /><div className="w-1/4 h-4 bg-white/30 rounded-sm" /></div> },
  { id: '2-1-4', name: '1/4 + 3/4', columns: [25, 75], icon: <div className="flex gap-0.5 w-full"><div className="w-1/4 h-4 bg-white/30 rounded-sm" /><div className="w-3/4 h-4 bg-white/30 rounded-sm" /></div> },
];

interface SectionWizardProps {
  onSelect: (node: DiSyLNode) => void;
  onClose: () => void;
}

const SectionWizard: React.FC<SectionWizardProps> = ({ onSelect, onClose }) => {
  const createSectionWithColumns = (preset: ColumnPreset) => {
    const columns = preset.columns.map((width) =>
      createNode('column', {}, {
        flex: buildRatioFlexValue(width),
      }, [])
    );

    const row = createNode('row', {}, {
      flexWrap: 'wrap',
      width: '100%',
    }, columns);

    const container = createNode('container', {}, {}, [row]);

    const section = createNode('section', {}, {}, [container]);

    onSelect(section);
    onClose();
  };

  return (
    <div className="absolute inset-0 bg-[#1e1e1e] z-10 p-3 overflow-y-auto">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-xs font-medium text-white/90">Choose Layout</h3>
        <button
          onClick={onClose}
          className="p-1 text-white/50 hover:text-white hover:bg-white/10 rounded"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      <div className="grid grid-cols-2 gap-2">
        {COLUMN_PRESETS.map(preset => (
          <button
            key={preset.id}
            onClick={() => createSectionWithColumns(preset)}
            className="component-item p-3 bg-[#2d2d2d] border border-[#3c3c3c] hover:border-[#0078d4] hover:bg-[#353535] rounded group"
            title={preset.name}
          >
            <div className="mb-2">{preset.icon}</div>
            <span className="text-[10px] text-white/50 group-hover:text-white/80">{preset.name}</span>
          </button>
        ))}
      </div>

      <div className="mt-4 pt-4 border-t border-[#3c3c3c]">
        <button
          onClick={onClose}
          className="w-full py-2 text-xs text-white/50 hover:text-white/80 hover:bg-white/5 rounded transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
};

// =============================================================================
// Component Panel
// =============================================================================

interface ComponentPanelProps {
  onAddComponent: (node: DiSyLNode) => void;
  horizontal?: boolean; // For bottom drawer layout
}

const ComponentPanel: React.FC<ComponentPanelProps> = ({ onAddComponent, horizontal = false }) => {
  const [showSectionWizard, setShowSectionWizard] = useState(false);
  const [showLayoutPresetPicker, setShowLayoutPresetPicker] = useState(false);

  const handleAddComponent = (node: DiSyLNode) => {
    // Show wizard for section type
    if (node.type === 'section') {
      setShowSectionWizard(true);
      return;
    }
    // Show layout preset picker for layout container type
    if (node.type === 'layout_container') {
      setShowLayoutPresetPicker(true);
      return;
    }
    onAddComponent(node);
  };

  // Horizontal layout for bottom drawer
  if (horizontal) {
    // Flatten all components for horizontal display
    const allComponents = COMPONENT_CATEGORIES.flatMap(cat => cat.components);

    return (
      <div
        className="h-full flex items-center gap-2 px-3 bg-[#252526]"
        role="region"
        aria-label="Component library"
      >
        {allComponents.map(component => {
          const Icon = getIcon(component.icon);
          return (
            <button
              key={component.type}
              draggable
              onDragStart={(e) => {
                e.dataTransfer.setData('application/json', JSON.stringify({
                  type: component.type,
                  props: component.defaultProps,
                  style: newNodeStyle(component),
                  children: component.defaultChildren || [],
                }));
                e.dataTransfer.effectAllowed = 'copy';
              }}
              onClick={() => {
                if (component.type === 'section') {
                  setShowSectionWizard(true);
                  return;
                }
                if (component.type === 'layout_container') {
                  setShowLayoutPresetPicker(true);
                  return;
                }
                const node = createNode(
                  component.type,
                  component.defaultProps,
                  newNodeStyle(component),
                  component.defaultChildren || []
                );
                onAddComponent(node);
              }}
              className="component-item flex flex-col items-center gap-1 px-3 py-1.5 bg-[#2d2d2d] border border-[#3c3c3c] hover:border-[#0078d4] cursor-grab active:cursor-grabbing group flex-shrink-0"
              title={component.description}
            >
              <Icon className="w-4 h-4 text-white/50 group-hover:text-[#0078d4]" />
              <span className="text-[9px] text-white/60 group-hover:text-white/90 whitespace-nowrap">{component.name}</span>
            </button>
          );
        })}

        {showSectionWizard && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <SectionWizard
              onSelect={onAddComponent}
              onClose={() => setShowSectionWizard(false)}
            />
          </div>
        )}

        {showLayoutPresetPicker && (
          <LayoutPresetPicker
            onSelect={onAddComponent}
            onClose={() => setShowLayoutPresetPicker(false)}
          />
        )}
      </div>
    );
  }

  // Vertical layout (default)
  return (
    <div
      className="h-full overflow-y-auto p-3 bg-[#252526] relative"
      role="region"
      aria-label="Component library"
    >
      {showSectionWizard && (
        <SectionWizard
          onSelect={onAddComponent}
          onClose={() => setShowSectionWizard(false)}
        />
      )}

      {showLayoutPresetPicker && (
        <LayoutPresetPicker
          onSelect={onAddComponent}
          onClose={() => setShowLayoutPresetPicker(false)}
        />
      )}

      {COMPONENT_CATEGORIES.map(category => {
        const CategoryIcon = ICON_MAP[category.icon] || LayoutGrid;

        return (
          <div key={category.id} className="mb-4" role="group" aria-labelledby={`category-${category.id}`}>
            <div className="flex items-center gap-1.5 mb-2 px-1">
              <CategoryIcon className="w-3 h-3 text-white/40" aria-hidden="true" />
              <span
                id={`category-${category.id}`}
                className="text-[10px] font-medium text-white/40 uppercase tracking-wider"
              >
                {category.name}
              </span>
            </div>

            <div className="grid grid-cols-3 gap-1" role="list">
              {category.components.map(component => (
                <ComponentItem
                  key={component.type}
                  component={component}
                  onAdd={handleAddComponent}
                />
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default memo(ComponentPanel);
