/**
 * Ikabud Page Builder - Layers Panel
 * Tree view of document structure with drag & drop reordering
 */

import React, { memo, useState, useCallback, useRef, useEffect } from 'react';
import {
  ChevronRight,
  ChevronDown,
  Trash2,
  Copy,
  GripVertical,
  ArrowUp,
  ArrowDown,
} from 'lucide-react';
import { DiSyLNode } from '../core/types';
import { canAcceptChild, canBeChildOf, getComponentDefinition } from '../core/components';

const LAYER_DND_MIME = 'application/x-cms-layer-node-id';
const LAYER_DND_TYPE_MIME = 'application/x-cms-layer-node-type';

// =============================================================================
// Layer Item
// =============================================================================

interface LayerItemProps {
  node: DiSyLNode;
  depth: number;
  selectedIds: string[];
  hoveredId: string | null;
  canMoveUp: boolean;
  canMoveDown: boolean;
  parentId: string | null;
  parentType: string | null;
  indexInParent: number;
  onSelect: (nodeId: string, addToSelection?: boolean) => void;
  onHover: (nodeId: string | null) => void;
  onDelete: (nodeId: string) => void;
  onDuplicate: (nodeId: string) => void;
  onMoveNode?: (nodeId: string, direction: 'up' | 'down') => void;
  onDragMoveNode?: (nodeId: string, newParentId: string, newIndex: number) => void;
  onToggleVisibility?: (nodeId: string) => void;
  onToggleLock?: (nodeId: string) => void;
}

const LayerItem: React.FC<LayerItemProps> = memo(({
  node,
  depth,
  selectedIds,
  hoveredId,
  canMoveUp,
  canMoveDown,
  parentId,
  parentType,
  indexInParent,
  onSelect,
  onHover,
  onDelete,
  onDuplicate,
  onMoveNode,
  onDragMoveNode,
}) => {
  const isSelected = selectedIds.includes(node.id);
  const isHovered = hoveredId === node.id;
  const [isExpanded, setIsExpanded] = useState(true);
  const [showActions, setShowActions] = useState(false);
  const [dropPosition, setDropPosition] = useState<'before' | 'after' | 'inside' | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const itemRef = useRef<HTMLDivElement>(null);

  // Auto-scroll into view when selected
  useEffect(() => {
    if (isSelected && itemRef.current) {
      itemRef.current.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }, [isSelected]);

  const hasChildren = node.children && node.children.length > 0;
  const componentDef = getComponentDefinition(node.type);
  const displayName = node.meta.name || componentDef?.name || node.type;

  const handleClick = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    onSelect(node.id, e.shiftKey);
  }, [node.id, onSelect]);

  const handleToggle = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    setIsExpanded(!isExpanded);
  }, [isExpanded]);

  const handleDelete = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    onDelete(node.id);
  }, [node.id, onDelete]);

  const handleDuplicate = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    onDuplicate(node.id);
  }, [node.id, onDuplicate]);

  const handleMoveUp = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    onMoveNode?.(node.id, 'up');
  }, [node.id, onMoveNode]);

  const handleMoveDown = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    onMoveNode?.(node.id, 'down');
  }, [node.id, onMoveNode]);

  // DnD handlers for tree reordering
  const handleDragStart = useCallback((e: React.DragEvent) => {
    e.stopPropagation();
    setIsDragging(true);
    e.dataTransfer.setData(LAYER_DND_MIME, node.id);
    e.dataTransfer.setData(LAYER_DND_TYPE_MIME, node.type);
    e.dataTransfer.effectAllowed = 'move';
  }, [node.id, node.type]);

  const handleDragEnd = useCallback(() => {
    setIsDragging(false);
    setDropPosition(null);
  }, []);

  const handleDragOver = useCallback((e: React.DragEvent) => {
    if (!e.dataTransfer.types.includes(LAYER_DND_MIME)) return;
    e.preventDefault();
    e.stopPropagation();

    const draggedType = e.dataTransfer.getData(LAYER_DND_TYPE_MIME) as DiSyLNode['type'];
    if (!draggedType) {
      setDropPosition(null);
      e.dataTransfer.dropEffect = 'none';
      return;
    }

    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const y = e.clientY - rect.top;
    const height = rect.height;
    const isContainer = ['document', 'section', 'container', 'layout_container', 'row', 'column'].includes(node.type);
    const canDropInside = canAcceptChild(node.type, draggedType) && canBeChildOf(draggedType, node.type);
    const canDropAsSibling = parentType !== null && canAcceptChild(parentType as DiSyLNode['type'], draggedType) && canBeChildOf(draggedType, parentType as DiSyLNode['type']);

    if (isContainer && canDropInside && y > height * 0.25 && y < height * 0.75) {
      setDropPosition('inside');
    } else if (canDropAsSibling && y < height * 0.5) {
      setDropPosition('before');
    } else if (canDropAsSibling) {
      setDropPosition('after');
    } else {
      setDropPosition(null);
      e.dataTransfer.dropEffect = 'none';
      return;
    }
    e.dataTransfer.dropEffect = 'move';
  }, [node.type, parentType]);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDropPosition(null);
  }, []);

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();

    const draggedNodeId = e.dataTransfer.getData(LAYER_DND_MIME);
    if (!draggedNodeId || draggedNodeId === node.id || !onDragMoveNode) {
      setDropPosition(null);
      return;
    }

    if (dropPosition === 'inside') {
      onDragMoveNode(draggedNodeId, node.id, node.children.length);
    } else if (parentId) {
      const newIndex = dropPosition === 'before' ? indexInParent : indexInParent + 1;
      onDragMoveNode(draggedNodeId, parentId, newIndex);
    }
    setDropPosition(null);
  }, [node.id, node.children.length, dropPosition, onDragMoveNode, parentId, indexInParent]);

  // Drop indicator styles
  const getDropStyle = (): React.CSSProperties | undefined => {
    if (!dropPosition) return undefined;
    if (dropPosition === 'inside') return { outline: '1px solid #0078d4', outlineOffset: '-1px' };
    return undefined;
  };

  const getDropBarStyle = (): React.CSSProperties | undefined => {
    if (!dropPosition || dropPosition === 'inside') return undefined;
    return {
      position: 'absolute' as const,
      left: `${depth * 12 + 8}px`,
      right: '4px',
      height: '2px',
      backgroundColor: '#0078d4',
      zIndex: 10,
      ...(dropPosition === 'before' ? { top: 0 } : { bottom: 0 }),
    };
  };

  return (
    <>
      <div
        ref={itemRef}
        className={`
          relative flex items-center gap-1 py-1 px-2 cursor-pointer transition-colors
          ${isDragging ? 'opacity-40' : ''}
          ${isSelected ? 'bg-[#0078d4] text-white' : isHovered ? 'bg-white/5' : 'hover:bg-white/5'}
        `}
        style={{ paddingLeft: `${depth * 12 + 8}px`, ...getDropStyle() }}
        onClick={handleClick}
        onMouseEnter={() => {
          onHover(node.id);
          setShowActions(true);
        }}
        onMouseLeave={() => {
          onHover(null);
          setShowActions(false);
        }}
        draggable={node.type !== 'document'}
        onDragStart={handleDragStart}
        onDragEnd={handleDragEnd}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
      >
        {/* Drop bar indicator */}
        {dropPosition && dropPosition !== 'inside' && (
          <div style={getDropBarStyle()} />
        )}

        {/* Expand/Collapse */}
        <button
          onClick={handleToggle}
          className={`p-0.5 hover:bg-white/10 ${!hasChildren ? 'invisible' : ''}`}
        >
          {isExpanded ? (
            <ChevronDown className="w-3 h-3 text-white/40" />
          ) : (
            <ChevronRight className="w-3 h-3 text-white/40" />
          )}
        </button>

        {/* Drag Handle */}
        {node.type !== 'document' && (
          <GripVertical className="w-3 h-3 text-white/20 cursor-grab" />
        )}

        {/* Name */}
        <span className={`flex-1 text-[11px] truncate ${isSelected ? 'text-white' : 'text-white/70'}`}>
          {displayName}
        </span>

        {/* Actions */}
        {(showActions || isSelected) && node.type !== 'document' && (
          <div className="flex items-center gap-0.5" onClick={(e) => e.stopPropagation()}>
            {onMoveNode && canMoveUp && (
              <button
                onClick={handleMoveUp}
                className="p-0.5 hover:bg-white/10"
                title="Move Up (Alt+↑)"
              >
                <ArrowUp className="w-3 h-3 text-white/40" />
              </button>
            )}
            {onMoveNode && canMoveDown && (
              <button
                onClick={handleMoveDown}
                className="p-0.5 hover:bg-white/10"
                title="Move Down (Alt+↓)"
              >
                <ArrowDown className="w-3 h-3 text-white/40" />
              </button>
            )}
            <button
              onClick={handleDuplicate}
              className="p-0.5 hover:bg-white/10"
              title="Duplicate"
            >
              <Copy className="w-3 h-3 text-white/40" />
            </button>
            <button
              onClick={handleDelete}
              className="p-0.5 hover:bg-red-500/20"
              title="Delete"
            >
              <Trash2 className="w-3 h-3 text-white/40 hover:text-red-400" />
            </button>
          </div>
        )}
      </div>

      {/* Children */}
      {hasChildren && isExpanded && (
        <div>
          {node.children.map((child, index) => (
            <LayerItem
              key={child.id}
              node={child}
              depth={depth + 1}
              selectedIds={selectedIds}
              hoveredId={hoveredId}
              canMoveUp={index > 0}
              canMoveDown={index < node.children.length - 1}
              parentId={node.id}
              parentType={node.type}
              indexInParent={index}
              onSelect={onSelect}
              onHover={onHover}
              onDelete={onDelete}
              onDuplicate={onDuplicate}
              onMoveNode={onMoveNode}
              onDragMoveNode={onDragMoveNode}
            />
          ))}
        </div>
      )}
    </>
  );
});

LayerItem.displayName = 'LayerItem';

// =============================================================================
// Layers Panel
// =============================================================================

interface LayersPanelProps {
  document: DiSyLNode;
  selectedIds: string[];
  hoveredId: string | null;
  onSelect: (nodeId: string, addToSelection?: boolean) => void;
  onHover: (nodeId: string | null) => void;
  onDelete: (nodeId: string) => void;
  onDuplicate: (nodeId: string) => void;
  onMoveNode?: (nodeId: string, direction: 'up' | 'down') => void;
  onDragMoveNode?: (nodeId: string, newParentId: string, newIndex: number) => void;
}

const LayersPanel: React.FC<LayersPanelProps> = ({
  document,
  selectedIds,
  hoveredId,
  onSelect,
  onHover,
  onDelete,
  onDuplicate,
  onMoveNode,
  onDragMoveNode,
}) => {
  return (
    <div className="h-full overflow-y-auto py-1 bg-[#252526]">
      <LayerItem
        node={document}
        depth={0}
        selectedIds={selectedIds}
        hoveredId={hoveredId}
        canMoveUp={false}
        canMoveDown={false}
        parentId={null}
        parentType={null}
        indexInParent={0}
        onSelect={onSelect}
        onHover={onHover}
        onDelete={onDelete}
        onDuplicate={onDuplicate}
        onMoveNode={onMoveNode}
        onDragMoveNode={onDragMoveNode}
      />
    </div>
  );
};

export default memo(LayersPanel);
