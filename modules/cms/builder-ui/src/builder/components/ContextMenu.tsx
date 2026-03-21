/**
 * Ikabud Page Builder - Context Menu
 * Right-click menu for component actions
 */

import React, { memo, useEffect, useRef } from 'react';
import {
  Copy,
  Clipboard,
  Trash2,
  ArrowUp,
  ArrowDown,
  Layers,
  Bookmark,
  WrapText,
} from 'lucide-react';

interface ContextMenuProps {
  x: number;
  y: number;
  nodeId: string;
  nodeType: string;
  onClose: () => void;
  onCopy: () => void;
  onPaste: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onSaveAsBlock?: () => void;
  onWrapInContainer?: () => void;
  canPaste: boolean;
  canMoveUp: boolean;
  canMoveDown: boolean;
}

interface MenuItemProps {
  icon: React.ReactNode;
  label: string;
  shortcut?: string;
  onClick: () => void;
  disabled?: boolean;
  danger?: boolean;
}

const MenuItem: React.FC<MenuItemProps> = ({ icon, label, shortcut, onClick, disabled, danger }) => (
  <button
    onClick={onClick}
    disabled={disabled}
    className={`
      w-full flex items-center gap-2 px-3 py-1.5 text-left text-xs transition-colors
      ${disabled 
        ? 'text-white/20 cursor-not-allowed' 
        : danger 
          ? 'text-white/80 hover:bg-red-500/20 hover:text-red-400'
          : 'text-white/80 hover:bg-white/10'
      }
    `}
  >
    <span className="w-4 h-4 flex items-center justify-center">{icon}</span>
    <span className="flex-1">{label}</span>
    {shortcut && <span className="text-white/30 text-[10px]">{shortcut}</span>}
  </button>
);

const MenuDivider: React.FC = () => (
  <div className="h-px bg-white/10 my-1" />
);

const ContextMenu: React.FC<ContextMenuProps> = memo(({
  x,
  y,
  nodeType,
  onClose,
  onCopy,
  onPaste,
  onDuplicate,
  onDelete,
  onMoveUp,
  onMoveDown,
  onSaveAsBlock,
  onWrapInContainer,
  canPaste,
  canMoveUp,
  canMoveDown,
}) => {
  const menuRef = useRef<HTMLDivElement>(null);
  
  // Close on click outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        onClose();
      }
    };
    
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
    
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [onClose]);
  
  // Adjust position to stay within viewport
  useEffect(() => {
    if (menuRef.current) {
      const rect = menuRef.current.getBoundingClientRect();
      const viewportWidth = window.innerWidth;
      const viewportHeight = window.innerHeight;
      
      if (rect.right > viewportWidth) {
        menuRef.current.style.left = `${x - rect.width}px`;
      }
      if (rect.bottom > viewportHeight) {
        menuRef.current.style.top = `${y - rect.height}px`;
      }
    }
  }, [x, y]);
  
  return (
    <div
      ref={menuRef}
      className="fixed z-50 min-w-[180px] bg-[#252526] border border-[#3c3c3c] shadow-xl py-1"
      style={{ left: x, top: y }}
    >
      {/* Header */}
      <div className="px-3 py-1.5 text-[10px] text-white/40 uppercase tracking-wide border-b border-white/10 mb-1">
        {nodeType}
      </div>
      
      {/* Edit Actions */}
      <MenuItem
        icon={<Copy className="w-3.5 h-3.5" />}
        label="Copy"
        shortcut="Ctrl+C"
        onClick={() => { onCopy(); onClose(); }}
      />
      <MenuItem
        icon={<Clipboard className="w-3.5 h-3.5" />}
        label="Paste"
        shortcut="Ctrl+V"
        onClick={() => { onPaste(); onClose(); }}
        disabled={!canPaste}
      />
      <MenuItem
        icon={<Layers className="w-3.5 h-3.5" />}
        label="Duplicate"
        shortcut="Ctrl+D"
        onClick={() => { onDuplicate(); onClose(); }}
      />
      
      <MenuDivider />
      
      {/* Arrange Actions */}
      <MenuItem
        icon={<ArrowUp className="w-3.5 h-3.5" />}
        label="Move Up"
        onClick={() => { onMoveUp(); onClose(); }}
        disabled={!canMoveUp}
      />
      <MenuItem
        icon={<ArrowDown className="w-3.5 h-3.5" />}
        label="Move Down"
        onClick={() => { onMoveDown(); onClose(); }}
        disabled={!canMoveDown}
      />
      
      <MenuDivider />
      
      {/* Advanced Actions */}
      {onSaveAsBlock && (
        <MenuItem
          icon={<Bookmark className="w-3.5 h-3.5" />}
          label="Save as Block"
          onClick={() => { onSaveAsBlock(); onClose(); }}
        />
      )}
      {onWrapInContainer && (
        <MenuItem
          icon={<WrapText className="w-3.5 h-3.5" />}
          label="Wrap in Container"
          onClick={() => { onWrapInContainer(); onClose(); }}
        />
      )}
      
      {(onSaveAsBlock || onWrapInContainer) && <MenuDivider />}
      
      {/* Delete */}
      <MenuItem
        icon={<Trash2 className="w-3.5 h-3.5" />}
        label="Delete"
        shortcut="Del"
        onClick={() => { onDelete(); onClose(); }}
        danger
      />
    </div>
  );
});

ContextMenu.displayName = 'ContextMenu';

export default ContextMenu;
