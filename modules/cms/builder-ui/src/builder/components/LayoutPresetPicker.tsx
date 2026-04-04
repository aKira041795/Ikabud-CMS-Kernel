/**
 * Ikabud Page Builder - Layout Preset Picker
 * Modal for selecting a layout preset when adding a layout container
 */

import React, { memo } from 'react';
import { X } from 'lucide-react';
import { LAYOUT_PRESETS, LayoutPreset, createContainerWithPreset } from '../core/layoutPresets';
import { DiSyLNode, createNode } from '../core/types';

// =============================================================================
// Preset Icon Component
// =============================================================================

interface PresetIconProps {
  preset: LayoutPreset;
}

const PresetIcon: React.FC<PresetIconProps> = ({ preset }) => {
  // Render visual representation based on preset
  const renderIcon = () => {
    switch (preset.icon) {
      case 'stacked':
        return (
          <div className="w-full h-full flex flex-col gap-1 p-1">
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
          </div>
        );
      case 'two-equal':
        return (
          <div className="w-full h-full flex gap-1 p-1">
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
          </div>
        );
      case 'two-left':
        return (
          <div className="w-full h-full flex gap-1 p-1">
            <div className="w-1/3 bg-white/40 rounded-sm" />
            <div className="w-2/3 bg-white/40 rounded-sm" />
          </div>
        );
      case 'two-right':
        return (
          <div className="w-full h-full flex gap-1 p-1">
            <div className="w-2/3 bg-white/40 rounded-sm" />
            <div className="w-1/3 bg-white/40 rounded-sm" />
          </div>
        );
      case 'three-equal':
        return (
          <div className="w-full h-full flex gap-1 p-1">
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
          </div>
        );
      case 'centered':
        return (
          <div className="w-full h-full flex items-center justify-center p-1">
            <div className="w-1/2 h-1/2 bg-white/40 rounded-sm" />
          </div>
        );
      case 'grid-2x2':
        return (
          <div className="w-full h-full grid grid-cols-2 grid-rows-2 gap-1 p-1">
            <div className="bg-white/40 rounded-sm" />
            <div className="bg-white/40 rounded-sm" />
            <div className="bg-white/40 rounded-sm" />
            <div className="bg-white/40 rounded-sm" />
          </div>
        );
      case 'four-equal':
        return (
          <div className="w-full h-full flex gap-1 p-1">
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
            <div className="flex-1 bg-white/40 rounded-sm" />
          </div>
        );
      default:
        return (
          <div className="w-full h-full flex items-center justify-center">
            <div className="w-3/4 h-3/4 bg-white/40 rounded-sm" />
          </div>
        );
    }
  };

  return (
    <div className="w-full h-12 bg-[#3c3c3c] rounded">
      {renderIcon()}
    </div>
  );
};

// =============================================================================
// Layout Preset Picker
// =============================================================================

interface LayoutPresetPickerProps {
  onSelect: (node: DiSyLNode) => void;
  onClose: () => void;
}

const LayoutPresetPicker: React.FC<LayoutPresetPickerProps> = ({ onSelect, onClose }) => {
  const handlePresetSelect = (preset: LayoutPreset) => {
    const layoutNode = createContainerWithPreset(preset);
    onSelect(layoutNode);
    onClose();
  };

  const handleEmptyContainer = () => {
    // Create empty layout container with default flex column
    const node = createNode('layout_container', {}, {
      padding: '24px',
      minHeight: '100px',
      display: 'flex',
      flexDirection: 'column',
      gap: '16px',
    });
    onSelect(node);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
      <div
        className="bg-[#252526] border border-[#3c3c3c] rounded-lg shadow-2xl w-[480px] max-h-[80vh] overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <div>
            <h2 className="text-sm font-medium text-white">Choose a Layout</h2>
            <p className="text-xs text-white/50 mt-0.5">Select a preset or start with an empty layout</p>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 hover:bg-white/10 rounded transition-colors"
          >
            <X className="w-4 h-4 text-white/50" />
          </button>
        </div>

        {/* Presets Grid */}
        <div className="p-4 overflow-y-auto max-h-[60vh]">
          <div className="grid grid-cols-4 gap-3">
            {LAYOUT_PRESETS.map((preset) => (
              <button
                key={preset.id}
                onClick={() => handlePresetSelect(preset)}
                className="flex flex-col items-center p-3 bg-[#2d2d2d] border border-[#3c3c3c] rounded hover:border-[#0078d4] hover:bg-[#2d2d2d]/80 transition-all group focus:outline-none focus:ring-2 focus:ring-[#0078d4]"
                title={preset.description}
              >
                <PresetIcon preset={preset} />
                <span className="text-[10px] text-white/60 group-hover:text-white/90 mt-2 text-center">
                  {preset.name}
                </span>
              </button>
            ))}
          </div>
        </div>

        {/* Footer */}
        <div className="px-4 py-3 border-t border-[#3c3c3c] flex justify-between items-center">
          <button
            onClick={handleEmptyContainer}
            className="text-xs text-white/50 hover:text-white/80 transition-colors"
          >
            Skip - Empty Layout
          </button>
          <button
            onClick={onClose}
            className="px-3 py-1.5 text-xs text-white/70 hover:text-white hover:bg-white/10 rounded transition-colors"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
};

export default memo(LayoutPresetPicker);
