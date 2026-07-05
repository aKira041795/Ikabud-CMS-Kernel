/**
 * Ikabud Page Builder - State Management Hook
 * Manages builder state with undo/redo support
 */

import { useReducer, useCallback, useMemo, useRef } from 'react';
import {
  DiSyLNode,
  BuilderState,
  BuilderAction,
  NodeProps,
  NodeStyle,
  NodeMeta,
  generateId,
  createEmptyDocument,
} from './types';
import { canAcceptChild, canBeChildOf } from './components';
import type { ComponentType } from './types';

// =============================================================================
// Initial State
// =============================================================================

const initialState: BuilderState = {
  document: createEmptyDocument(),
  isDirty: false,
  selectedIds: [],
  hoveredId: null,
  activeTool: 'select',
  sidebarTab: 'components',
  zoom: 100,
  viewport: 'desktop',
  clipboard: null,
};

// =============================================================================
// Node Tree Utilities
// =============================================================================

function findNode(root: DiSyLNode, nodeId: string): DiSyLNode | null {
  if (root.id === nodeId) return root;
  for (const child of root.children) {
    const found = findNode(child, nodeId);
    if (found) return found;
  }
  return null;
}

function findParent(root: DiSyLNode, nodeId: string): DiSyLNode | null {
  for (const child of root.children) {
    if (child.id === nodeId) return root;
    const found = findParent(child, nodeId);
    if (found) return found;
  }
  return null;
}

// Get path from root to a node (for breadcrumb navigation)
function getNodePath(root: DiSyLNode, nodeId: string, path: DiSyLNode[] = []): DiSyLNode[] | null {
  const currentPath = [...path, root];
  if (root.id === nodeId) return currentPath;
  for (const child of root.children) {
    const found = getNodePath(child, nodeId, currentPath);
    if (found) return found;
  }
  return null;
}

function cloneNode(node: DiSyLNode): DiSyLNode {
  return {
    ...node,
    id: generateId(),
    children: node.children.map(cloneNode),
    meta: { ...node.meta },
    props: { ...node.props },
    style: { ...node.style },
  };
}

function ensureUniqueNodeTree(node: DiSyLNode): DiSyLNode {
  return {
    ...node,
    id: generateId(),
    children: node.children.map(ensureUniqueNodeTree),
    meta: { ...node.meta },
    props: { ...node.props },
    style: { ...node.style },
  };
}

function updateNodeInTree(
  root: DiSyLNode,
  nodeId: string,
  updater: (node: DiSyLNode) => DiSyLNode
): DiSyLNode {
  if (root.id === nodeId) {
    return updater(root);
  }
  return {
    ...root,
    children: root.children.map(child => updateNodeInTree(child, nodeId, updater)),
  };
}

function removeNodeFromTree(root: DiSyLNode, nodeId: string): DiSyLNode {
  return {
    ...root,
    children: root.children
      .filter(child => child.id !== nodeId)
      .map(child => removeNodeFromTree(child, nodeId)),
  };
}

function insertNodeInTree(
  root: DiSyLNode,
  parentId: string,
  node: DiSyLNode,
  index: number
): DiSyLNode {
  if (root.id === parentId) {
    const newChildren = [...root.children];
    newChildren.splice(index, 0, node);
    return { ...root, children: newChildren };
  }
  return {
    ...root,
    children: root.children.map(child => insertNodeInTree(child, parentId, node, index)),
  };
}

/**
 * Validates whether a node can be inserted as a child of the given parent.
 * Uses the canAcceptChild / canBeChildOf constraint functions from components.ts.
 */
function isValidInsertion(parentType: string, childType: string): boolean {
  const pType = parentType as ComponentType;
  const cType = childType as ComponentType;
  return canAcceptChild(pType, cType) && canBeChildOf(cType, pType);
}

/**
 * Walk up the tree from `startId` to find the nearest ancestor that can accept `childType`.
 * Returns the valid parent ID, or the document root ID as last resort.
 */
function findValidParent(root: DiSyLNode, startId: string, childType: string): string | null {
  // Build path from root to startId
  const path: DiSyLNode[] = [];
  function buildPath(node: DiSyLNode, target: string): boolean {
    path.push(node);
    if (node.id === target) return true;
    for (const child of node.children) {
      if (buildPath(child, target)) return true;
    }
    path.pop();
    return false;
  }
  buildPath(root, startId);

  // Walk backwards (from startId towards root) to find first valid parent
  for (let i = path.length - 1; i >= 0; i--) {
    if (isValidInsertion(path[i].type, childType)) {
      return path[i].id;
    }
  }
  return null;
}

// =============================================================================
// Reducer
// =============================================================================

function builderReducer(state: BuilderState, action: BuilderAction): BuilderState {
  switch (action.type) {
    case 'SELECT_NODE': {
      if (action.addToSelection) {
        const isSelected = state.selectedIds.includes(action.nodeId);
        return {
          ...state,
          selectedIds: isSelected
            ? state.selectedIds.filter(id => id !== action.nodeId)
            : [...state.selectedIds, action.nodeId],
        };
      }
      return {
        ...state,
        selectedIds: [action.nodeId],
      };
    }

    case 'DESELECT_ALL':
      return {
        ...state,
        selectedIds: [],
      };

    case 'HOVER_NODE':
      return {
        ...state,
        hoveredId: action.nodeId,
      };

    case 'INSERT_NODE': {
      // Validate insertion: walk up from requested parent to find a valid container
      const validParentId = findValidParent(state.document, action.parentId, action.node.type);
      if (validParentId === null) {
        console.warn(`INSERT_NODE blocked: no valid parent found for ${action.node.type}`);
        return state;
      }
      const validParent = findNode(state.document, validParentId);
      const insertIndex = validParentId === action.parentId
        ? action.index
        : (validParent?.children.length || 0); // append at end if reparented
      return {
        ...state,
        document: insertNodeInTree(state.document, validParentId, action.node, insertIndex),
        selectedIds: [action.node.id],
        isDirty: true,
      };
    }

    case 'DELETE_NODE': {
      const newSelectedIds = state.selectedIds.filter(id => id !== action.nodeId);
      return {
        ...state,
        document: removeNodeFromTree(state.document, action.nodeId),
        selectedIds: newSelectedIds,
        isDirty: true,
      };
    }

    case 'MOVE_NODE': {
      const node = findNode(state.document, action.nodeId);
      if (!node) return state;

      // Validate: ensure the target parent can accept this node type
      if (!isValidInsertion(
        (findNode(state.document, action.newParentId)?.type || 'document'),
        node.type
      )) {
        console.warn(`MOVE_NODE blocked: ${node.type} cannot be child of target parent`);
        return state;
      }
      
      let newDoc = removeNodeFromTree(state.document, action.nodeId);
      newDoc = insertNodeInTree(newDoc, action.newParentId, node, action.newIndex);
      
      return {
        ...state,
        document: newDoc,
        isDirty: true,
      };
    }

    case 'MOVE_NODE_DIRECTION': {
      const parent = findParent(state.document, action.nodeId);
      if (!parent) return state;

      const idx = parent.children.findIndex(c => c.id === action.nodeId);
      if (idx < 0) return state;

      const newIdx = action.direction === 'up' ? idx - 1 : idx + 1;
      if (newIdx < 0 || newIdx >= parent.children.length) return state;

      // Swap adjacent children
      const newChildren = [...parent.children];
      [newChildren[idx], newChildren[newIdx]] = [newChildren[newIdx], newChildren[idx]];

      return {
        ...state,
        document: updateNodeInTree(state.document, parent.id, p => ({
          ...p,
          children: newChildren,
        })),
        isDirty: true,
      };
    }

    case 'UPDATE_PROPS':
      return {
        ...state,
        document: updateNodeInTree(state.document, action.nodeId, node => ({
          ...node,
          props: { ...node.props, ...action.props },
        })),
        isDirty: true,
      };

    case 'UPDATE_STYLE':
      return {
        ...state,
        document: updateNodeInTree(state.document, action.nodeId, node => ({
          ...node,
          style: { ...node.style, ...action.style },
        })),
        isDirty: true,
      };

    case 'UPDATE_META':
      return {
        ...state,
        document: updateNodeInTree(state.document, action.nodeId, node => ({
          ...node,
          meta: { ...node.meta, ...action.meta },
        })),
        isDirty: true,
      };

    case 'DUPLICATE_NODE': {
      const node = findNode(state.document, action.nodeId);
      const parent = findParent(state.document, action.nodeId);
      if (!node || !parent) return state;
      
      const cloned = cloneNode(node);
      const index = parent.children.findIndex(c => c.id === action.nodeId);
      
      return {
        ...state,
        document: insertNodeInTree(state.document, parent.id, cloned, index + 1),
        selectedIds: [cloned.id],
        isDirty: true,
      };
    }

    case 'COPY_NODES': {
      const nodes = action.nodeIds
        .map(id => findNode(state.document, id))
        .filter((n): n is DiSyLNode => n !== null)
        .map(cloneNode);
      
      return {
        ...state,
        clipboard: nodes,
      };
    }

    case 'PASTE_NODES': {
      if (!state.clipboard || state.clipboard.length === 0) return state;
      
      let newDoc = state.document;
      const newIds: string[] = [];
      let insertedCount = 0;
      
      state.clipboard.forEach((node, i) => {
        const cloned = cloneNode(node);
        const validParentId = findValidParent(newDoc, action.parentId, cloned.type);
        if (validParentId === null) {
          console.warn(`PASTE_NODES blocked: no valid parent found for ${cloned.type}`);
          return;
        }
        newIds.push(cloned.id);
        const validParent = findNode(newDoc, validParentId);
        const insertIndex = validParentId === action.parentId
          ? action.index + insertedCount
          : (validParent?.children.length || 0);
        newDoc = insertNodeInTree(newDoc, validParentId, cloned, insertIndex);
        insertedCount++;
      });
      if (newIds.length === 0) return state;
      
      return {
        ...state,
        document: newDoc,
        selectedIds: newIds,
        isDirty: true,
      };
    }

    case 'SET_DOCUMENT':
      return {
        ...state,
        document: action.document,
        selectedIds: [],
        isDirty: false,
      };

    case 'SET_VIEWPORT':
      return {
        ...state,
        viewport: action.viewport,
      };

    case 'SET_ZOOM':
      return {
        ...state,
        zoom: Math.max(25, Math.min(200, action.zoom)),
      };

    case 'SET_SIDEBAR_TAB':
      return {
        ...state,
        sidebarTab: action.tab,
      };

    case 'MARK_CLEAN':
      return {
        ...state,
        isDirty: false,
      };

    default:
      return state;
  }
}

// =============================================================================
// Hook
// =============================================================================

const MAX_HISTORY_SIZE = 50;

export function useBuilderState(initialDocument?: DiSyLNode) {
  const [state, dispatch] = useReducer(
    builderReducer,
    initialDocument
      ? { ...initialState, document: initialDocument }
      : initialState
  );
  
  // Undo/Redo history
  const historyRef = useRef<{
    past: DiSyLNode[];
    future: DiSyLNode[];
  }>({ past: [], future: [] });
  
  const lastDocumentRef = useRef<string>(JSON.stringify(state.document));

  // Selection actions
  const selectNode = useCallback((nodeId: string, addToSelection = false) => {
    dispatch({ type: 'SELECT_NODE', nodeId, addToSelection });
  }, []);

  const deselectAll = useCallback(() => {
    dispatch({ type: 'DESELECT_ALL' });
  }, []);

  const hoverNode = useCallback((nodeId: string | null) => {
    dispatch({ type: 'HOVER_NODE', nodeId });
  }, []);

  // Node manipulation actions
  const insertNode = useCallback((node: DiSyLNode, parentId: string, index: number) => {
    dispatch({ type: 'INSERT_NODE', node: ensureUniqueNodeTree(node), parentId, index });
  }, []);

  const deleteNode = useCallback((nodeId: string) => {
    dispatch({ type: 'DELETE_NODE', nodeId });
  }, []);

  const moveNode = useCallback((nodeId: string, newParentId: string, newIndex: number) => {
    dispatch({ type: 'MOVE_NODE', nodeId, newParentId, newIndex });
  }, []);

  const moveNodeInDirection = useCallback((nodeId: string, direction: 'up' | 'down') => {
    dispatch({ type: 'MOVE_NODE_DIRECTION', nodeId, direction });
  }, []);

  const updateProps = useCallback((nodeId: string, props: Partial<NodeProps>) => {
    dispatch({ type: 'UPDATE_PROPS', nodeId, props });
  }, []);

  const updateStyle = useCallback((nodeId: string, style: Partial<NodeStyle>) => {
    dispatch({ type: 'UPDATE_STYLE', nodeId, style });
  }, []);

  const updateMeta = useCallback((nodeId: string, meta: Partial<NodeMeta>) => {
    dispatch({ type: 'UPDATE_META', nodeId, meta });
  }, []);

  const duplicateNode = useCallback((nodeId: string) => {
    dispatch({ type: 'DUPLICATE_NODE', nodeId });
  }, []);

  // Clipboard actions
  const copyNodes = useCallback((nodeIds: string[]) => {
    dispatch({ type: 'COPY_NODES', nodeIds });
  }, []);

  const pasteNodes = useCallback((parentId: string, index: number) => {
    dispatch({ type: 'PASTE_NODES', parentId, index });
  }, []);

  // Document actions
  const setDocument = useCallback((document: DiSyLNode) => {
    dispatch({ type: 'SET_DOCUMENT', document });
  }, []);

  const markClean = useCallback(() => {
    dispatch({ type: 'MARK_CLEAN' });
  }, []);

  // UI actions
  const setViewport = useCallback((viewport: 'desktop' | 'tablet' | 'mobile') => {
    dispatch({ type: 'SET_VIEWPORT', viewport });
  }, []);

  const setZoom = useCallback((zoom: number) => {
    dispatch({ type: 'SET_ZOOM', zoom });
  }, []);

  const setSidebarTab = useCallback((tab: 'components' | 'layers' | 'settings') => {
    dispatch({ type: 'SET_SIDEBAR_TAB', tab });
  }, []);

  // Computed values
  const selectedNode = useMemo(() => {
    if (state.selectedIds.length !== 1) return null;
    return findNode(state.document, state.selectedIds[0]);
  }, [state.document, state.selectedIds]);

  const selectedNodes = useMemo(() => {
    return state.selectedIds
      .map(id => findNode(state.document, id))
      .filter((n): n is DiSyLNode => n !== null);
  }, [state.document, state.selectedIds]);

  // Get path to selected node (for breadcrumb navigation)
  const selectedNodePath = useMemo(() => {
    if (state.selectedIds.length !== 1) return [];
    return getNodePath(state.document, state.selectedIds[0]) || [];
  }, [state.document, state.selectedIds]);

  // Track document changes for undo/redo
  const currentDocStr = JSON.stringify(state.document);
  if (currentDocStr !== lastDocumentRef.current && state.isDirty) {
    // Document changed, push to history
    try {
      const lastDoc = JSON.parse(lastDocumentRef.current) as DiSyLNode;
      historyRef.current.past.push(lastDoc);
      if (historyRef.current.past.length > MAX_HISTORY_SIZE) {
        historyRef.current.past.shift();
      }
      // Clear future on new change
      historyRef.current.future = [];
    } catch {
      // Ignore parse errors
    }
    lastDocumentRef.current = currentDocStr;
  }
  
  // Undo action
  const undo = useCallback(() => {
    const { past, future } = historyRef.current;
    if (past.length === 0) return;
    
    const previous = past.pop()!;
    future.push(state.document);
    lastDocumentRef.current = JSON.stringify(previous);
    dispatch({ type: 'SET_DOCUMENT', document: previous });
  }, [state.document]);
  
  // Redo action
  const redo = useCallback(() => {
    const { past, future } = historyRef.current;
    if (future.length === 0) return;
    
    const next = future.pop()!;
    past.push(state.document);
    lastDocumentRef.current = JSON.stringify(next);
    dispatch({ type: 'SET_DOCUMENT', document: next });
  }, [state.document]);
  
  // Check if can undo/redo
  const canUndo = historyRef.current.past.length > 0;
  const canRedo = historyRef.current.future.length > 0;

  return {
    // State
    state,
    document: state.document,
    selectedIds: state.selectedIds,
    selectedNode,
    selectedNodes,
    selectedNodePath,
    hoveredId: state.hoveredId,
    isDirty: state.isDirty,
    viewport: state.viewport,
    zoom: state.zoom,
    sidebarTab: state.sidebarTab,
    clipboard: state.clipboard,
    
    // Undo/Redo
    canUndo,
    canRedo,
    undo,
    redo,

    // Actions
    selectNode,
    deselectAll,
    hoverNode,
    insertNode,
    deleteNode,
    moveNode,
    moveNodeInDirection,
    updateProps,
    updateStyle,
    updateMeta,
    duplicateNode,
    copyNodes,
    pasteNodes,
    setDocument,
    markClean,
    setViewport,
    setZoom,
    setSidebarTab,

    // Utilities
    findNode: (nodeId: string) => findNode(state.document, nodeId),
    findParent: (nodeId: string) => findParent(state.document, nodeId),
  };
}

export type BuilderStateHook = ReturnType<typeof useBuilderState>;
