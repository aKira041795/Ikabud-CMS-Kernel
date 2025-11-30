import { useState, useEffect, useCallback, useRef } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import Editor, { OnMount } from '@monaco-editor/react'
import {
  Code2,
  FolderOpen,
  File,
  ChevronRight,
  ChevronDown,
  Save,
  RefreshCw,
  ArrowLeft,
  FileCode,
  FileText,
  Image,
  Folder,
  AlertCircle,
  Check,
  X,
  Settings,
  Sun,
  Moon,
  Columns,
  Eye,
  SplitSquareHorizontal,
  Layers,
  Box,
  Type,
  Grid3X3,
  Link,
  MousePointer,
  Repeat,
  GitBranch,
  Monitor,
  Tablet,
  Smartphone,
  LucideIcon
} from 'lucide-react'

// Types
interface FileNode {
  name: string
  path: string
  type: 'file' | 'directory'
  extension?: string
  size?: number
  modified?: number
  children?: FileNode[]
}

interface Theme {
  id: string
  name: string
  path: string
  cms_type: string
  has_disyl: boolean
}

// File icon helper
const getFileIcon = (extension?: string) => {
  switch (extension) {
    case 'php':
      return <FileCode className="w-4 h-4 text-purple-500" />
    case 'css':
    case 'scss':
      return <FileCode className="w-4 h-4 text-blue-500" />
    case 'js':
    case 'ts':
      return <FileCode className="w-4 h-4 text-yellow-500" />
    case 'json':
      return <FileCode className="w-4 h-4 text-green-500" />
    case 'disyl':
      return <FileCode className="w-4 h-4 text-indigo-500" />
    case 'html':
    case 'twig':
      return <FileCode className="w-4 h-4 text-orange-500" />
    case 'md':
      return <FileText className="w-4 h-4 text-gray-500" />
    case 'png':
    case 'jpg':
    case 'jpeg':
    case 'gif':
    case 'svg':
      return <Image className="w-4 h-4 text-pink-500" />
    default:
      return <File className="w-4 h-4 text-gray-400" />
  }
}

// File Tree Component
function FileTree({
  nodes,
  selectedPath,
  onSelect,
  expandedPaths,
  onToggle
}: {
  nodes: FileNode[]
  selectedPath: string | null
  onSelect: (node: FileNode) => void
  expandedPaths: Set<string>
  onToggle: (path: string) => void
}) {
  return (
    <div className="text-sm">
      {nodes.map(node => (
        <FileTreeNode
          key={node.path}
          node={node}
          depth={0}
          selectedPath={selectedPath}
          onSelect={onSelect}
          expandedPaths={expandedPaths}
          onToggle={onToggle}
        />
      ))}
    </div>
  )
}

function FileTreeNode({
  node,
  depth,
  selectedPath,
  onSelect,
  expandedPaths,
  onToggle
}: {
  node: FileNode
  depth: number
  selectedPath: string | null
  onSelect: (node: FileNode) => void
  expandedPaths: Set<string>
  onToggle: (path: string) => void
}) {
  const isExpanded = expandedPaths.has(node.path)
  const isSelected = selectedPath === node.path
  const isDirectory = node.type === 'directory'

  return (
    <div>
      <button
        onClick={() => {
          if (isDirectory) {
            onToggle(node.path)
          } else {
            onSelect(node)
          }
        }}
        className={`w-full flex items-center gap-1 px-2 py-1 hover:bg-gray-100 rounded text-left ${
          isSelected ? 'bg-blue-50 text-blue-700' : 'text-gray-700'
        }`}
        style={{ paddingLeft: `${depth * 12 + 8}px` }}
      >
        {isDirectory ? (
          isExpanded ? (
            <ChevronDown className="w-4 h-4 text-gray-400 flex-shrink-0" />
          ) : (
            <ChevronRight className="w-4 h-4 text-gray-400 flex-shrink-0" />
          )
        ) : (
          <span className="w-4" />
        )}
        {isDirectory ? (
          <Folder className={`w-4 h-4 flex-shrink-0 ${isExpanded ? 'text-blue-500' : 'text-yellow-500'}`} />
        ) : (
          getFileIcon(node.extension)
        )}
        <span className="truncate">{node.name}</span>
      </button>
      {isDirectory && isExpanded && node.children && (
        <div>
          {node.children.map(child => (
            <FileTreeNode
              key={child.path}
              node={child}
              depth={depth + 1}
              selectedPath={selectedPath}
              onSelect={onSelect}
              expandedPaths={expandedPaths}
              onToggle={onToggle}
            />
          ))}
        </div>
      )}
    </div>
  )
}

// Language mapping for Monaco
const getMonacoLanguage = (extension?: string): string => {
  const languageMap: Record<string, string> = {
    'php': 'php',
    'js': 'javascript',
    'ts': 'typescript',
    'jsx': 'javascript',
    'tsx': 'typescript',
    'css': 'css',
    'scss': 'scss',
    'less': 'less',
    'html': 'html',
    'htm': 'html',
    'twig': 'twig',
    'json': 'json',
    'xml': 'xml',
    'yml': 'yaml',
    'yaml': 'yaml',
    'md': 'markdown',
    'sql': 'sql',
    'sh': 'shell',
    'bash': 'shell',
    'disyl': 'html', // DiSyL uses HTML-like syntax, we'll add custom highlighting
  }
  return languageMap[extension || ''] || 'plaintext'
}

// DiSyL token patterns for custom highlighting
const DISYL_PATTERNS = {
  components: [
    'ikb_section', 'ikb_container', 'ikb_row', 'ikb_col', 'ikb_grid', 'ikb_block',
    'ikb_text', 'ikb_heading', 'ikb_link', 'ikb_button', 'ikb_image', 'ikb_video',
    'ikb_card', 'ikb_list', 'ikb_menu', 'ikb_query', 'ikb_widget', 'ikb_form',
    'ikb_input', 'ikb_select', 'ikb_textarea', 'ikb_platform', 'ikb_cms'
  ],
  controls: ['if', 'else', 'elseif', 'for', 'switch', 'case', 'include', 'set'],
  filters: [
    'esc_html', 'esc_attr', 'esc_url', 'raw', 'upper', 'lower', 'capitalize',
    'truncate', 'date', 'number_format', 'json_encode', 'strip_tags', 'nl2br'
  ],
  attributes: [
    'type', 'id', 'class', 'src', 'href', 'alt', 'title', 'size', 'cols', 'span',
    'padding', 'margin', 'bg', 'color', 'align', 'justify', 'limit', 'orderby'
  ]
}

// ============================================================================
// VISUAL PREVIEW TYPES AND PARSER
// ============================================================================

interface ParsedNode {
  id: string
  componentId: string
  attributes: Record<string, string>
  textContent: string
  children: ParsedNode[]
  line: number
}

// Component icon mapping - includes both DiSyL and HTML elements
const COMPONENT_ICONS: Record<string, LucideIcon> = {
  // DiSyL components
  'ikb_section': Layers,
  'ikb_container': Box,
  'ikb_row': Columns,
  'ikb_col': Columns,
  'ikb_grid': Grid3X3,
  'ikb_block': Box,
  'ikb_text': Type,
  'ikb_heading': Type,
  'ikb_link': Link,
  'ikb_button': MousePointer,
  'ikb_image': Image,
  'ikb_card': FileText,
  'ikb_query': Repeat,
  'if': GitBranch,
  'for': Repeat,
  'include': FolderOpen,
  // HTML elements
  'div': Box,
  'section': Layers,
  'article': FileText,
  'header': Layers,
  'footer': Layers,
  'nav': Columns,
  'main': Box,
  'aside': Box,
  'span': Type,
  'p': Type,
  'h1': Type,
  'h2': Type,
  'h3': Type,
  'h4': Type,
  'h5': Type,
  'h6': Type,
  'a': Link,
  'img': Image,
  'button': MousePointer,
  'ul': Columns,
  'ol': Columns,
  'li': Box,
  'form': Box,
  'input': Box,
  'textarea': Box,
  'select': Box,
}

// Component style mapping - includes both DiSyL and HTML elements
const getComponentStyle = (componentId: string): string => {
  switch (componentId) {
    // DiSyL components
    case 'ikb_section':
      return 'bg-blue-50 border-blue-200 min-h-[80px]'
    case 'ikb_container':
      return 'bg-indigo-50 border-indigo-200 min-h-[60px]'
    case 'ikb_row':
      return 'bg-violet-50 border-violet-200 min-h-[50px]'
    case 'ikb_col':
      return 'bg-purple-50 border-purple-200 min-h-[40px]'
    case 'ikb_block':
      return 'bg-slate-50 border-slate-200 min-h-[40px]'
    case 'ikb_grid':
      return 'bg-cyan-50 border-cyan-200 min-h-[60px]'
    case 'ikb_card':
      return 'bg-green-50 border-green-200 min-h-[60px]'
    case 'ikb_text':
    case 'ikb_heading':
      return 'bg-gray-50 border-gray-200 min-h-[30px]'
    case 'ikb_image':
      return 'bg-pink-50 border-pink-200 min-h-[50px]'
    case 'ikb_button':
    case 'ikb_link':
      return 'bg-amber-50 border-amber-200 min-h-[30px]'
    case 'ikb_query':
      return 'bg-orange-50 border-orange-200 min-h-[60px]'
    case 'if':
    case 'for':
      return 'bg-red-50 border-red-200 border-dashed min-h-[50px]'
    case 'include':
      return 'bg-teal-50 border-teal-200 min-h-[30px]'
    // HTML layout elements
    case 'div':
      return 'bg-slate-50 border-slate-300 min-h-[40px]'
    case 'section':
    case 'article':
    case 'main':
      return 'bg-blue-50 border-blue-200 min-h-[60px]'
    case 'header':
    case 'footer':
    case 'nav':
    case 'aside':
      return 'bg-indigo-50 border-indigo-200 min-h-[50px]'
    // HTML text elements
    case 'p':
    case 'span':
      return 'bg-gray-50 border-gray-200 min-h-[24px]'
    case 'h1':
    case 'h2':
    case 'h3':
    case 'h4':
    case 'h5':
    case 'h6':
      return 'bg-emerald-50 border-emerald-200 min-h-[28px]'
    // HTML interactive elements
    case 'a':
      return 'bg-amber-50 border-amber-200 min-h-[24px]'
    case 'button':
      return 'bg-purple-50 border-purple-200 min-h-[28px]'
    // HTML media elements
    case 'img':
      return 'bg-pink-50 border-pink-200 min-h-[40px]'
    // HTML list elements
    case 'ul':
    case 'ol':
      return 'bg-cyan-50 border-cyan-200 min-h-[40px]'
    case 'li':
      return 'bg-sky-50 border-sky-200 min-h-[24px]'
    // HTML form elements
    case 'form':
      return 'bg-violet-50 border-violet-200 min-h-[60px]'
    case 'input':
    case 'textarea':
    case 'select':
      return 'bg-fuchsia-50 border-fuchsia-200 min-h-[28px]'
    default:
      return 'bg-gray-50 border-gray-200 min-h-[30px]'
  }
}

// Parse DiSyL code into visual nodes - handles both DiSyL tags and HTML elements
function parseDisylCode(code: string): ParsedNode[] {
  const nodes: ParsedNode[] = []
  const stack: ParsedNode[] = []
  let nodeId = 0
  
  // Split into lines for line tracking
  const lines = code.split('\n')
  
  // HTML elements we want to parse
  const htmlElements = ['div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 
                        'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img', 'button',
                        'ul', 'ol', 'li', 'form', 'input', 'textarea', 'select', 'label', 'table',
                        'tr', 'td', 'th', 'thead', 'tbody', 'video', 'audio', 'figure', 'figcaption']
  const htmlElementsPattern = htmlElements.join('|')
  
  // Regex patterns for HTML
  const htmlOpenTagRegex = new RegExp(`<(${htmlElementsPattern})([^>]*?)(?:>|/>)`, 'gi')
  const htmlCloseTagRegex = new RegExp(`</(${htmlElementsPattern})>`, 'gi')
  const htmlSelfClosingRegex = new RegExp(`<(${htmlElementsPattern})([^>]*?)/>`, 'gi')
  
  // Regex patterns for DiSyL
  const disylOpenTagRegex = /\{(ikb_\w+|if|for|switch|case)([^}]*?)\}/g
  const disylCloseTagRegex = /\{\/(ikb_\w+|if|for|switch|case)\}/g
  const disylSelfClosingRegex = /\{(include|set)([^}]*?)\/\}/g
  
  // Variable expression regex
  const variableRegex = /\{([a-zA-Z_][\w.]*(?:\s*\|[^}]+)?)\}/g
  
  let lineNumber = 0
  
  for (const line of lines) {
    lineNumber++
    let match
    
    // Track positions of all tags on this line for proper ordering
    const tagEvents: Array<{pos: number, type: 'open' | 'close' | 'self', tagName: string, attrs: Record<string, string>, isHtml: boolean}> = []
    
    // Find HTML self-closing tags
    htmlSelfClosingRegex.lastIndex = 0
    while ((match = htmlSelfClosingRegex.exec(line)) !== null) {
      const [, tagName, attrString] = match
      tagEvents.push({
        pos: match.index,
        type: 'self',
        tagName: tagName.toLowerCase(),
        attrs: parseHtmlAttributes(attrString),
        isHtml: true
      })
    }
    
    // Find HTML open tags (but not self-closing)
    htmlOpenTagRegex.lastIndex = 0
    while ((match = htmlOpenTagRegex.exec(line)) !== null) {
      const fullMatch = match[0]
      if (fullMatch.endsWith('/>')) continue // Skip self-closing
      
      const [, tagName, attrString] = match
      tagEvents.push({
        pos: match.index,
        type: 'open',
        tagName: tagName.toLowerCase(),
        attrs: parseHtmlAttributes(attrString),
        isHtml: true
      })
    }
    
    // Find HTML close tags
    htmlCloseTagRegex.lastIndex = 0
    while ((match = htmlCloseTagRegex.exec(line)) !== null) {
      const [, tagName] = match
      tagEvents.push({
        pos: match.index,
        type: 'close',
        tagName: tagName.toLowerCase(),
        attrs: {},
        isHtml: true
      })
    }
    
    // Find DiSyL self-closing tags
    disylSelfClosingRegex.lastIndex = 0
    while ((match = disylSelfClosingRegex.exec(line)) !== null) {
      const [, tagName, attrString] = match
      tagEvents.push({
        pos: match.index,
        type: 'self',
        tagName,
        attrs: parseDisylAttributes(attrString),
        isHtml: false
      })
    }
    
    // Find DiSyL open tags
    disylOpenTagRegex.lastIndex = 0
    while ((match = disylOpenTagRegex.exec(line)) !== null) {
      const fullMatch = match[0]
      if (fullMatch.endsWith('/}')) continue // Skip self-closing
      
      const [, tagName, attrString] = match
      tagEvents.push({
        pos: match.index,
        type: 'open',
        tagName,
        attrs: parseDisylAttributes(attrString),
        isHtml: false
      })
    }
    
    // Find DiSyL close tags
    disylCloseTagRegex.lastIndex = 0
    while ((match = disylCloseTagRegex.exec(line)) !== null) {
      const [, tagName] = match
      tagEvents.push({
        pos: match.index,
        type: 'close',
        tagName,
        attrs: {},
        isHtml: false
      })
    }
    
    // Sort by position
    tagEvents.sort((a, b) => a.pos - b.pos)
    
    // Process events in order
    for (const event of tagEvents) {
      if (event.type === 'self') {
        const node: ParsedNode = {
          id: `node-${++nodeId}`,
          componentId: event.tagName,
          attributes: event.attrs,
          textContent: '',
          children: [],
          line: lineNumber
        }
        
        if (stack.length > 0) {
          stack[stack.length - 1].children.push(node)
        } else {
          nodes.push(node)
        }
      } else if (event.type === 'open') {
        const node: ParsedNode = {
          id: `node-${++nodeId}`,
          componentId: event.tagName,
          attributes: event.attrs,
          textContent: '',
          children: [],
          line: lineNumber
        }
        stack.push(node)
      } else if (event.type === 'close') {
        // Find matching open tag
        for (let i = stack.length - 1; i >= 0; i--) {
          if (stack[i].componentId === event.tagName) {
            const closedNode = stack.splice(i, 1)[0]
            if (stack.length > 0) {
              stack[stack.length - 1].children.push(closedNode)
            } else {
              nodes.push(closedNode)
            }
            break
          }
        }
      }
    }
    
    // Extract text content and variable expressions for current stack top
    if (stack.length > 0) {
      // Remove all tags from line to get text content
      let textContent = line
        .replace(/<[^>]+>/g, '') // Remove HTML tags
        .replace(/\{[^}]+\}/g, '') // Remove DiSyL tags
        .trim()
      
      // Also capture variable expressions
      variableRegex.lastIndex = 0
      while ((match = variableRegex.exec(line)) !== null) {
        const varExpr = match[1]
        // Skip if it's a component tag
        if (varExpr.startsWith('ikb_') || varExpr.startsWith('/') || 
            ['if', 'for', 'switch', 'case', 'include', 'set'].includes(varExpr.split(' ')[0])) {
          continue
        }
        textContent += ` {${varExpr}}`
      }
      
      textContent = textContent.trim()
      if (textContent) {
        const currentNode = stack[stack.length - 1]
        if (currentNode.textContent) {
          currentNode.textContent += ' ' + textContent
        } else {
          currentNode.textContent = textContent
        }
      }
    }
  }
  
  // Close any remaining open tags
  while (stack.length > 0) {
    const closedNode = stack.pop()!
    if (stack.length > 0) {
      stack[stack.length - 1].children.push(closedNode)
    } else {
      nodes.push(closedNode)
    }
  }
  
  return nodes
}

// Parse HTML attributes
function parseHtmlAttributes(attrString: string): Record<string, string> {
  const attrs: Record<string, string> = {}
  // Match both quoted and unquoted attributes
  const attrRegex = /([\w-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|(\S+))/g
  let match
  
  while ((match = attrRegex.exec(attrString)) !== null) {
    const key = match[1]
    const value = match[2] || match[3] || match[4] || ''
    attrs[key] = value
  }
  
  return attrs
}

// Parse DiSyL attributes
function parseDisylAttributes(attrString: string): Record<string, string> {
  const attrs: Record<string, string> = {}
  const attrRegex = /(\w+)\s*=\s*["']([^"']*)["']/g
  let match
  
  while ((match = attrRegex.exec(attrString)) !== null) {
    attrs[match[1]] = match[2]
  }
  
  return attrs
}

// Visual Preview Component
function VisualPreview({
  nodes,
  selectedNode,
  onSelectNode,
  deviceWidth
}: {
  nodes: ParsedNode[]
  selectedNode: ParsedNode | null
  onSelectNode: (node: ParsedNode) => void
  deviceWidth: string
}) {
  const renderNode = (node: ParsedNode, depth = 0): React.ReactNode => {
    const isSelected = selectedNode?.id === node.id
    const Icon = COMPONENT_ICONS[node.componentId] || Box
    
    return (
      <div
        key={node.id}
        className={`
          relative p-2 m-1.5 rounded-lg border-2 transition-all cursor-pointer
          ${getComponentStyle(node.componentId)}
          ${isSelected ? 'ring-2 ring-blue-500 ring-offset-2 shadow-md' : ''}
          hover:ring-1 hover:ring-blue-300
        `}
        onClick={(e) => {
          e.stopPropagation()
          onSelectNode(node)
        }}
        title={`Line ${node.line}: ${node.componentId}`}
      >
        {/* Component Label */}
        <div className="flex items-center gap-1.5 mb-1">
          <Icon className="w-3.5 h-3.5 text-gray-500" />
          <span className="text-xs font-medium text-gray-600">{node.componentId}</span>
          {node.attributes.type && (
            <span className="text-xs text-gray-400">({node.attributes.type})</span>
          )}
          <span className="ml-auto text-[10px] text-gray-400">L{node.line}</span>
        </div>
        
        {/* Attributes Preview - show class if available */}
        {node.attributes.class && (
          <div className="mb-1">
            <span className="text-[10px] px-1.5 py-0.5 bg-blue-100 rounded text-blue-600">
              .{node.attributes.class.split(' ')[0]}
              {node.attributes.class.split(' ').length > 1 && ` +${node.attributes.class.split(' ').length - 1}`}
            </span>
          </div>
        )}
        
        {/* Text Content Preview */}
        {node.textContent && (
          <div className="text-xs text-gray-600 mb-1 truncate max-w-full">
            {node.textContent.length > 40 ? node.textContent.slice(0, 40) + '...' : node.textContent}
          </div>
        )}
        
        {/* Children */}
        {node.children.length > 0 && (
          <div className={`
            ${node.componentId === 'ikb_row' ? 'flex flex-wrap gap-1' : ''}
            ${node.componentId === 'ikb_grid' && node.attributes.cols 
              ? `grid grid-cols-${Math.min(parseInt(node.attributes.cols) || 3, 4)} gap-1` 
              : ''}
          `}>
            {node.children.map(child => renderNode(child, depth + 1))}
          </div>
        )}
        
        {/* Empty State */}
        {node.children.length === 0 && !node.textContent && !node.attributes.class && (
          <div className="flex items-center justify-center h-6 border border-dashed border-gray-300 rounded text-gray-400 text-[10px]">
            Empty
          </div>
        )}
      </div>
    )
  }
  
  return (
    <div 
      className="h-full overflow-auto bg-white"
      style={{ maxWidth: deviceWidth }}
    >
      <div className="p-3 min-h-full">
        {nodes.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-48 border-2 border-dashed border-gray-200 rounded-xl text-gray-400">
            <Layers className="w-10 h-10 mb-2 text-gray-300" />
            <p className="text-sm font-medium">No Components Found</p>
            <p className="text-xs">Add HTML or DiSyL tags to see the visual preview</p>
          </div>
        ) : (
          nodes.map(node => renderNode(node))
        )}
      </div>
    </div>
  )
}

// Properties Panel Component
function PropertiesPanel({
  node,
  onClose
}: {
  node: ParsedNode | null
  onClose: () => void
}) {
  if (!node) return null
  
  const Icon = COMPONENT_ICONS[node.componentId] || Box
  
  return (
    <div className="w-72 bg-white border-l border-gray-200 flex flex-col h-full">
      {/* Header */}
      <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-gray-50">
        <div className="flex items-center gap-2">
          <Icon className="w-4 h-4 text-blue-500" />
          <span className="font-medium text-gray-900">{node.componentId}</span>
        </div>
        <button
          onClick={onClose}
          className="p-1 hover:bg-gray-200 rounded text-gray-500"
        >
          <X className="w-4 h-4" />
        </button>
      </div>
      
      {/* Content */}
      <div className="flex-1 overflow-auto p-4">
        {/* Line Info */}
        <div className="mb-4 p-2 bg-blue-50 rounded-lg">
          <span className="text-xs text-blue-600 font-medium">Line {node.line}</span>
        </div>
        
        {/* Attributes Section */}
        <div className="mb-4">
          <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Attributes</h4>
          {Object.keys(node.attributes).length > 0 ? (
            <div className="space-y-2">
              {Object.entries(node.attributes).map(([key, value]) => (
                <div key={key} className="bg-gray-50 rounded-lg p-2">
                  <div className="text-xs font-medium text-gray-700 mb-1">{key}</div>
                  <div className="text-xs text-gray-600 break-all font-mono bg-white p-1.5 rounded border border-gray-200">
                    {value || <span className="text-gray-400 italic">empty</span>}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-xs text-gray-400 italic">No attributes</p>
          )}
        </div>
        
        {/* Text Content Section */}
        {node.textContent && (
          <div className="mb-4">
            <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Content</h4>
            <div className="bg-gray-50 rounded-lg p-2">
              <div className="text-xs text-gray-600 break-all">
                {node.textContent}
              </div>
            </div>
          </div>
        )}
        
        {/* Children Count */}
        {node.children.length > 0 && (
          <div className="mb-4">
            <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Children</h4>
            <div className="bg-gray-50 rounded-lg p-2">
              <span className="text-xs text-gray-600">
                {node.children.length} child element{node.children.length !== 1 ? 's' : ''}
              </span>
              <div className="mt-2 space-y-1">
                {node.children.slice(0, 5).map((child, i) => {
                  const ChildIcon = COMPONENT_ICONS[child.componentId] || Box
                  return (
                    <div key={i} className="flex items-center gap-1.5 text-xs text-gray-500">
                      <ChildIcon className="w-3 h-3" />
                      <span>{child.componentId}</span>
                      {child.attributes.class && (
                        <span className="text-blue-500">.{child.attributes.class.split(' ')[0]}</span>
                      )}
                    </div>
                  )
                })}
                {node.children.length > 5 && (
                  <div className="text-xs text-gray-400">+{node.children.length - 5} more...</div>
                )}
              </div>
            </div>
          </div>
        )}
        
        {/* Element Type Info */}
        <div className="mt-4 pt-4 border-t border-gray-200">
          <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Element Type</h4>
          <div className={`inline-flex items-center gap-1.5 px-2 py-1 rounded text-xs ${
            node.componentId.startsWith('ikb_') 
              ? 'bg-purple-100 text-purple-700' 
              : node.componentId.match(/^(if|for|switch|case|include|set)$/)
                ? 'bg-red-100 text-red-700'
                : 'bg-slate-100 text-slate-700'
          }`}>
            <Icon className="w-3 h-3" />
            {node.componentId.startsWith('ikb_') 
              ? 'DiSyL Component' 
              : node.componentId.match(/^(if|for|switch|case|include|set)$/)
                ? 'DiSyL Control'
                : 'HTML Element'}
          </div>
        </div>
      </div>
    </div>
  )
}

export default function CodeEditor() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const editorRef = useRef<any>(null)
  
  const instanceId = searchParams.get('instance')
  const themeId = searchParams.get('theme')

  // State
  const [themes, setThemes] = useState<Theme[]>([])
  const [selectedTheme, setSelectedTheme] = useState<string | null>(themeId)
  const [fileTree, setFileTree] = useState<FileNode[]>([])
  const [expandedPaths, setExpandedPaths] = useState<Set<string>>(new Set(['disyl']))
  const [selectedFile, setSelectedFile] = useState<FileNode | null>(null)
  const [fileContent, setFileContent] = useState('')
  const [originalContent, setOriginalContent] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saveSuccess, setSaveSuccess] = useState(false)
  const [editorTheme, setEditorTheme] = useState<'vs-dark' | 'light'>('vs-dark')
  const [fontSize, setFontSize] = useState(14)
  const [showMinimap, setShowMinimap] = useState(true)
  
  // Visual preview state
  const [viewMode, setViewMode] = useState<'code' | 'split' | 'preview'>('split')
  const [devicePreview, setDevicePreview] = useState<'desktop' | 'tablet' | 'mobile'>('desktop')
  const [parsedNodes, setParsedNodes] = useState<ParsedNode[]>([])
  const [selectedNode, setSelectedNode] = useState<ParsedNode | null>(null)
  const [showPropertiesPanel, setShowPropertiesPanel] = useState(false)
  
  // Cross-instance context for federation
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [instanceContext, setInstanceContext] = useState<any>(null)
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [allInstances, setAllInstances] = useState<any[]>([])
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [instanceContextCache, setInstanceContextCache] = useState<Record<string, any>>({})

  // Fetch themes and context for instance
  useEffect(() => {
    if (instanceId) {
      fetchThemes()
      fetchInstanceContext(instanceId)
      fetchAllInstances()
    }
  }, [instanceId])
  
  // Fetch all available instances for cross-instance suggestions
  const fetchAllInstances = async () => {
    try {
      const res = await fetch('/api/v1/filesystem/instances')
      const data = await res.json()
      setAllInstances(data.instances || [])
    } catch (err) {
      console.error('Failed to load instances:', err)
    }
  }
  
  // Fetch instance context (variables, filters, operators from DB)
  // Supports caching for cross-instance lookups
  const fetchInstanceContext = async (instId: string, cache = true) => {
    // Check cache first
    if (cache && instanceContextCache[instId]) {
      if (instId === instanceId) {
        setInstanceContext(instanceContextCache[instId])
      }
      return instanceContextCache[instId]
    }
    
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${instId}/context`)
      const data = await res.json()
      
      // Cache the result
      setInstanceContextCache(prev => ({ ...prev, [instId]: data }))
      
      // Set as current context if it's the main instance
      if (instId === instanceId) {
        setInstanceContext(data)
      }
      
      return data
    } catch (err) {
      console.error('Failed to load instance context:', err)
      return null
    }
  }
  
  // Fetch file tree when theme changes
  useEffect(() => {
    if (instanceId && selectedTheme) {
      fetchFileTree()
    }
  }, [instanceId, selectedTheme])

  // Parse DiSyL code for visual preview when content changes
  useEffect(() => {
    if (selectedFile?.extension === 'disyl' && fileContent) {
      const nodes = parseDisylCode(fileContent)
      setParsedNodes(nodes)
    } else {
      setParsedNodes([])
    }
  }, [fileContent, selectedFile?.extension])

  // Handle node selection from visual preview - scroll to line in editor and show properties
  const handleSelectNode = useCallback((node: ParsedNode) => {
    setSelectedNode(node)
    setShowPropertiesPanel(true)
    
    if (editorRef.current) {
      // Highlight the line in the editor
      editorRef.current.revealLineInCenter(node.line)
      editorRef.current.setPosition({ lineNumber: node.line, column: 1 })
      
      // Select the entire line for highlighting
      const model = editorRef.current.getModel()
      if (model) {
        const lineLength = model.getLineLength(node.line)
        editorRef.current.setSelection({
          startLineNumber: node.line,
          startColumn: 1,
          endLineNumber: node.line,
          endColumn: lineLength + 1
        })
      }
      
      editorRef.current.focus()
    }
  }, [])

  // Device width for preview
  const deviceWidth = devicePreview === 'desktop' ? '100%' : devicePreview === 'tablet' ? '768px' : '375px'

  const fetchThemes = async () => {
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${instanceId}/themes`)
      const data = await res.json()
      setThemes(data.themes || [])
      
      // Auto-select theme if provided or first one
      if (themeId && data.themes?.some((t: Theme) => t.id === themeId)) {
        setSelectedTheme(themeId)
      } else if (data.themes?.length > 0 && !selectedTheme) {
        setSelectedTheme(data.themes[0].id)
      }
    } catch (err) {
      setError('Failed to load themes')
      console.error(err)
    } finally {
      setLoading(false)
    }
  }

  const fetchFileTree = async () => {
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${instanceId}/themes/${selectedTheme}/tree`)
      const data = await res.json()
      setFileTree(data.tree || [])
    } catch (err) {
      console.error('Failed to load file tree:', err)
      setFileTree([])
    }
  }

  const loadFile = async (node: FileNode) => {
    if (node.type !== 'file') return
    
    // Check for unsaved changes
    if (fileContent !== originalContent) {
      if (!confirm('You have unsaved changes. Discard them?')) {
        return
      }
    }
    
    try {
      const res = await fetch(
        `/api/v1/filesystem/instances/${instanceId}/themes/${selectedTheme}/files?path=${encodeURIComponent(node.path)}`
      )
      const data = await res.json()
      
      if (data.error) {
        setError(data.error)
        return
      }
      
      setSelectedFile(node)
      setFileContent(data.content)
      setOriginalContent(data.content)
    } catch (err) {
      setError('Failed to load file')
      console.error(err)
    }
  }

  const saveFile = async () => {
    if (!selectedFile) return
    
    setSaving(true)
    setSaveSuccess(false)
    
    try {
      const res = await fetch(
        `/api/v1/filesystem/instances/${instanceId}/themes/${selectedTheme}/files`,
        {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            path: selectedFile.path,
            content: fileContent
          })
        }
      )
      
      const data = await res.json()
      
      if (data.success) {
        setOriginalContent(fileContent)
        setSaveSuccess(true)
        setTimeout(() => setSaveSuccess(false), 2000)
      } else {
        setError(data.error || 'Failed to save file')
      }
    } catch (err) {
      setError('Failed to save file')
      console.error(err)
    } finally {
      setSaving(false)
    }
  }

  const handleToggle = useCallback((path: string) => {
    setExpandedPaths(prev => {
      const next = new Set(prev)
      if (next.has(path)) {
        next.delete(path)
      } else {
        next.add(path)
      }
      return next
    })
  }, [])

  const hasUnsavedChanges = fileContent !== originalContent

  // Monaco Editor mount handler
  const handleEditorMount: OnMount = (editor, monaco) => {
    editorRef.current = editor
    
    // Register DiSyL language
    monaco.languages.register({ id: 'disyl' })
    
    // DiSyL syntax highlighting
    monaco.languages.setMonarchTokensProvider('disyl', {
      tokenizer: {
        root: [
          // Comments
          [/{#.*?#}/, 'comment'],
          
          // DiSyL tags
          [/\{\/?(ikb_\w+)/, { token: 'tag', next: '@tag' }],
          [/\{\/?(?:if|else|elseif|for|switch|case|include|set)/, { token: 'keyword', next: '@tag' }],
          
          // Variables
          [/\{[\w.]+(?:\s*\|[^}]+)?\}/, 'variable'],
          
          // HTML
          [/<\/?[\w-]+/, { token: 'tag.html', next: '@htmlTag' }],
          [/[^<{]+/, 'text'],
        ],
        tag: [
          [/\s+/, 'white'],
          [/[\w-]+=/, 'attribute.name'],
          [/"[^"]*"/, 'string'],
          [/'[^']*'/, 'string'],
          [/\}/, { token: 'tag', next: '@root' }],
          [/\/\}/, { token: 'tag', next: '@root' }],
        ],
        htmlTag: [
          [/\s+/, 'white'],
          [/[\w-]+=/, 'attribute.name'],
          [/"[^"]*"/, 'string'],
          [/'[^']*'/, 'string'],
          [/\/?>/, { token: 'tag.html', next: '@root' }],
        ],
      }
    })
    
    // DiSyL language configuration
    monaco.languages.setLanguageConfiguration('disyl', {
      brackets: [['{', '}'], ['<', '>'], ['[', ']'], ['(', ')']],
      autoClosingPairs: [
        { open: '{', close: '}' },
        { open: '<', close: '>' },
        { open: '[', close: ']' },
        { open: '(', close: ')' },
        { open: '"', close: '"' },
        { open: "'", close: "'" },
      ],
      surroundingPairs: [
        { open: '{', close: '}' },
        { open: '<', close: '>' },
        { open: '"', close: '"' },
        { open: "'", close: "'" },
      ],
    })
    
    // DiSyL autocomplete with cross-instance context
    monaco.languages.registerCompletionItemProvider('disyl', {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      provideCompletionItems: (model: any, position: any) => {
        const word = model.getWordUntilPosition(position)
        const range = {
          startLineNumber: position.lineNumber,
          endLineNumber: position.lineNumber,
          startColumn: word.startColumn,
          endColumn: word.endColumn,
        }
        
        // Get line content to determine context
        const lineContent = model.getLineContent(position.lineNumber)
        const textBeforeCursor = lineContent.substring(0, position.column - 1)
        
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const suggestions: any[] = []
        
        // Check if we're typing instance="" attribute
        const isInstanceAttr = /instance\s*=\s*["'][^"']*$/.test(textBeforeCursor)
        
        // Check if we're typing cms="" attribute  
        const isCmsAttr = /cms\s*=\s*["'][^"']*$/.test(textBeforeCursor)
        
        // Detect if current tag has cms="xxx" or instance="xxx" for context switching
        const cmsMatch = lineContent.match(/cms\s*=\s*["'](\w+)["']/)
        const instanceMatch = lineContent.match(/instance\s*=\s*["']([\w-]+)["']/)
        
        // Determine which context to use based on tag attributes
        let activeContext = instanceContext
        if (instanceMatch && instanceContextCache[instanceMatch[1]]) {
          activeContext = instanceContextCache[instanceMatch[1]]
        } else if (cmsMatch) {
          // Find an instance with matching CMS type
          const matchingInstance = allInstances.find(i => i.cms_type === cmsMatch[1])
          if (matchingInstance && instanceContextCache[matchingInstance.id]) {
            activeContext = instanceContextCache[matchingInstance.id]
          }
        }
        
        // Check if we're after a pipe (filter context)
        const isFilterContext = /\|\s*\w*$/.test(textBeforeCursor)
        
        // Check if we're in a condition attribute
        const isConditionContext = /condition\s*=\s*["'][^"']*$/.test(textBeforeCursor)
        
        // Check if we're inside a variable expression {
        const isVariableContext = /\{[^}]*$/.test(textBeforeCursor) && !textBeforeCursor.includes('/')
        
        // Instance attribute suggestions
        if (isInstanceAttr) {
          allInstances.forEach(inst => {
            const cmsIcon = inst.cms_type === 'wordpress' ? '🔵' : 
                           inst.cms_type === 'joomla' ? '🟠' : 
                           inst.cms_type === 'drupal' ? '🔷' : '⚪'
            suggestions.push({
              label: inst.id,
              kind: monaco.languages.CompletionItemKind.Reference,
              insertText: inst.id,
              documentation: `${cmsIcon} ${inst.name}\nCMS: ${inst.cms_type}\nThemes: ${inst.theme_count}`,
              detail: `${inst.cms_type} instance`,
              range,
            })
          })
          return { suggestions }
        }
        
        // CMS attribute suggestions
        if (isCmsAttr) {
          const cmsTypes = [
            { id: 'wordpress', icon: '🔵', desc: 'WordPress CMS' },
            { id: 'joomla', icon: '🟠', desc: 'Joomla CMS' },
            { id: 'drupal', icon: '🔷', desc: 'Drupal CMS' },
            { id: 'native', icon: '⚪', desc: 'Native/Static' },
          ]
          cmsTypes.forEach(cms => {
            const instanceCount = allInstances.filter(i => i.cms_type === cms.id).length
            suggestions.push({
              label: cms.id,
              kind: monaco.languages.CompletionItemKind.Enum,
              insertText: cms.id,
              documentation: `${cms.icon} ${cms.desc}\n${instanceCount} instance(s) available`,
              detail: cms.desc,
              range,
            })
          })
          return { suggestions }
        }
        
        if (isFilterContext) {
          // Filter suggestions from active context (may be cross-instance)
          if (activeContext?.filters) {
            activeContext.filters.forEach((filter: { name: string; description: string; example: string }) => {
              suggestions.push({
                label: filter.name,
                kind: monaco.languages.CompletionItemKind.Function,
                insertText: filter.name,
                documentation: `${filter.description}\nExample: ${filter.example}`,
                detail: 'Filter',
                range,
              })
            })
          } else {
            // Fallback to static filters
            DISYL_PATTERNS.filters.forEach(filter => {
              suggestions.push({
                label: filter,
                kind: monaco.languages.CompletionItemKind.Function,
                insertText: filter,
                documentation: `Filter: ${filter}`,
                range,
              })
            })
          }
        } else if (isConditionContext) {
          // Variable suggestions for conditions (uses active context for cross-instance)
          if (activeContext?.variables) {
            activeContext.variables.forEach((v: { name: string; type: string; description: string }) => {
              suggestions.push({
                label: v.name,
                kind: monaco.languages.CompletionItemKind.Variable,
                insertText: v.name,
                documentation: `${v.description} (${v.type})`,
                detail: v.type,
                range,
              })
            })
          }
          
          // Operator suggestions
          if (activeContext?.operators) {
            activeContext.operators.forEach((op: { name: string; description: string; example: string }) => {
              suggestions.push({
                label: op.name,
                kind: monaco.languages.CompletionItemKind.Operator,
                insertText: op.name,
                documentation: `${op.description}\nExample: ${op.example}`,
                detail: 'Operator',
                range,
              })
            })
          }
        } else if (isVariableContext) {
          // Variable suggestions (uses active context for cross-instance)
          if (activeContext?.variables) {
            activeContext.variables.forEach((v: { name: string; type: string; description: string }) => {
              suggestions.push({
                label: v.name,
                kind: monaco.languages.CompletionItemKind.Variable,
                insertText: v.name,
                documentation: `${v.description} (${v.type})`,
                detail: v.type,
                range,
              })
            })
          }
          
          // Post fields (WordPress) - from active context
          if (activeContext?.post_fields) {
            activeContext.post_fields.forEach((f: { name: string; type: string; description: string }) => {
              suggestions.push({
                label: f.name,
                kind: monaco.languages.CompletionItemKind.Field,
                insertText: f.name,
                documentation: `${f.description} (${f.type})`,
                detail: `Post Field (${f.type})`,
                range,
              })
            })
          }
          
          // Article fields (Joomla) - from active context
          if (activeContext?.article_fields) {
            activeContext.article_fields.forEach((f: { name: string; type: string; description: string }) => {
              suggestions.push({
                label: f.name,
                kind: monaco.languages.CompletionItemKind.Field,
                insertText: f.name,
                documentation: `${f.description} (${f.type})`,
                detail: `Article Field (${f.type})`,
                range,
              })
            })
          }
          
          // Node fields (Drupal) - from active context
          if (activeContext?.node_fields) {
            activeContext.node_fields.forEach((f: { name: string; type: string; description: string }) => {
              suggestions.push({
                label: f.name,
                kind: monaco.languages.CompletionItemKind.Field,
                insertText: f.name,
                documentation: `${f.description} (${f.type})`,
                detail: `Node Field (${f.type})`,
                range,
              })
            })
          }
        } else {
          // Component suggestions
          DISYL_PATTERNS.components.forEach(comp => {
            suggestions.push({
              label: comp,
              kind: monaco.languages.CompletionItemKind.Class,
              insertText: `{${comp} \$1}\n\t\$0\n{/${comp}}`,
              insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
              documentation: `DiSyL ${comp} component`,
              range,
            })
          })
          
          // Control structure suggestions
          DISYL_PATTERNS.controls.forEach(ctrl => {
            if (ctrl === 'if') {
              suggestions.push({
                label: ctrl,
                kind: monaco.languages.CompletionItemKind.Keyword,
                insertText: `{if condition="\$1"}\n\t\$0\n{/if}`,
                insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
                documentation: 'Conditional block',
                range,
              })
            } else if (ctrl === 'for') {
              suggestions.push({
                label: ctrl,
                kind: monaco.languages.CompletionItemKind.Keyword,
                insertText: `{for items="\$1" as="\$2"}\n\t\$0\n{/for}`,
                insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
                documentation: 'Loop block',
                range,
              })
            } else if (ctrl === 'include') {
              suggestions.push({
                label: ctrl,
                kind: monaco.languages.CompletionItemKind.Keyword,
                insertText: `{include file="\$1" /}`,
                insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
                documentation: 'Include another template',
                range,
              })
            }
          })
        }
        
        return { suggestions }
      },
      triggerCharacters: ['{', '|', ' ', '.']
    })
    
    // Add save command
    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
      if (hasUnsavedChanges) {
        saveFile()
      }
    })
  }

  if (!instanceId) {
    return (
      <div className="flex items-center justify-center h-[calc(100vh-200px)]">
        <div className="text-center">
          <AlertCircle className="w-12 h-12 mx-auto mb-4 text-gray-300" />
          <p className="text-gray-600">No instance selected</p>
          <button
            onClick={() => navigate('/themes')}
            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            Go to Theme Builder
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="h-[calc(100vh-120px)] flex flex-col">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 bg-white border-b border-gray-200">
        <div className="flex items-center gap-4">
          <button
            onClick={() => navigate('/themes')}
            className="p-2 hover:bg-gray-100 rounded-lg"
          >
            <ArrowLeft className="w-5 h-5 text-gray-500" />
          </button>
          <div className="flex items-center gap-2">
            <Code2 className="w-5 h-5 text-purple-500" />
            <h1 className="text-lg font-semibold text-gray-900">Code Editor</h1>
          </div>
          
          {/* Theme Selector */}
          <select
            value={selectedTheme || ''}
            onChange={(e) => {
              setSelectedTheme(e.target.value)
              setSelectedFile(null)
              setFileContent('')
              setOriginalContent('')
            }}
            className="px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
          >
            {themes.map(theme => (
              <option key={theme.id} value={theme.id}>{theme.name}</option>
            ))}
          </select>
        </div>
        
        <div className="flex items-center gap-3">
          {selectedFile && (
            <>
              <span className="text-sm text-gray-500">
                {selectedFile.path}
                {hasUnsavedChanges && <span className="text-orange-500 ml-1">•</span>}
              </span>
              
              {/* View Mode Toggle - Only for DiSyL files */}
              {selectedFile?.extension === 'disyl' && (
                <div className="flex items-center bg-gray-100 rounded-lg p-0.5 border-l border-gray-200 ml-3">
                  <button
                    onClick={() => setViewMode('code')}
                    className={`p-1.5 rounded flex items-center gap-1 text-xs ${viewMode === 'code' ? 'bg-white shadow-sm text-purple-600' : 'text-gray-500'}`}
                    title="Code Only"
                  >
                    <Code2 className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setViewMode('split')}
                    className={`p-1.5 rounded flex items-center gap-1 text-xs ${viewMode === 'split' ? 'bg-white shadow-sm text-purple-600' : 'text-gray-500'}`}
                    title="Split View"
                  >
                    <SplitSquareHorizontal className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setViewMode('preview')}
                    className={`p-1.5 rounded flex items-center gap-1 text-xs ${viewMode === 'preview' ? 'bg-white shadow-sm text-purple-600' : 'text-gray-500'}`}
                    title="Preview Only"
                  >
                    <Eye className="w-3.5 h-3.5" />
                  </button>
                </div>
              )}

              {/* Device Preview - Only for DiSyL files in split/preview mode */}
              {selectedFile?.extension === 'disyl' && viewMode !== 'code' && (
                <div className="flex items-center bg-gray-100 rounded-lg p-0.5">
                  <button
                    onClick={() => setDevicePreview('desktop')}
                    className={`p-1.5 rounded ${devicePreview === 'desktop' ? 'bg-white shadow-sm' : ''}`}
                    title="Desktop"
                  >
                    <Monitor className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setDevicePreview('tablet')}
                    className={`p-1.5 rounded ${devicePreview === 'tablet' ? 'bg-white shadow-sm' : ''}`}
                    title="Tablet"
                  >
                    <Tablet className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setDevicePreview('mobile')}
                    className={`p-1.5 rounded ${devicePreview === 'mobile' ? 'bg-white shadow-sm' : ''}`}
                    title="Mobile"
                  >
                    <Smartphone className="w-3.5 h-3.5" />
                  </button>
                </div>
              )}

              {/* Editor Settings */}
              <div className="flex items-center gap-1 border-l border-gray-200 pl-3">
                <button
                  onClick={() => setEditorTheme(t => t === 'vs-dark' ? 'light' : 'vs-dark')}
                  className="p-1.5 hover:bg-gray-100 rounded"
                  title="Toggle theme"
                >
                  {editorTheme === 'vs-dark' ? <Sun className="w-4 h-4 text-gray-500" /> : <Moon className="w-4 h-4 text-gray-500" />}
                </button>
                <select
                  value={fontSize}
                  onChange={(e) => setFontSize(Number(e.target.value))}
                  className="px-2 py-1 text-xs border border-gray-200 rounded"
                  title="Font size"
                >
                  {[12, 13, 14, 15, 16, 18, 20].map(size => (
                    <option key={size} value={size}>{size}px</option>
                  ))}
                </select>
                <button
                  onClick={() => setShowMinimap(m => !m)}
                  className={`p-1.5 rounded ${showMinimap ? 'bg-purple-100 text-purple-600' : 'hover:bg-gray-100 text-gray-500'}`}
                  title="Toggle minimap"
                >
                  <Settings className="w-4 h-4" />
                </button>
              </div>
              
              <button
                onClick={saveFile}
                disabled={saving || !hasUnsavedChanges}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                  hasUnsavedChanges
                    ? 'bg-purple-600 text-white hover:bg-purple-700'
                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                }`}
              >
                {saving ? (
                  <RefreshCw className="w-4 h-4 animate-spin" />
                ) : saveSuccess ? (
                  <Check className="w-4 h-4" />
                ) : (
                  <Save className="w-4 h-4" />
                )}
                {saving ? 'Saving...' : saveSuccess ? 'Saved!' : 'Save'}
              </button>
            </>
          )}
        </div>
      </div>

      {/* Error Alert */}
      {error && (
        <div className="mx-4 mt-2 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2">
          <AlertCircle className="w-4 h-4 text-red-500" />
          <span className="text-sm text-red-700">{error}</span>
          <button onClick={() => setError(null)} className="ml-auto">
            <X className="w-4 h-4 text-red-500" />
          </button>
        </div>
      )}

      {/* Main Content */}
      <div className="flex-1 flex overflow-hidden">
        {/* File Tree Sidebar */}
        <div className="w-64 border-r border-gray-200 bg-gray-50 overflow-y-auto">
          <div className="p-3 border-b border-gray-200 flex items-center justify-between">
            <span className="text-xs font-medium text-gray-500 uppercase">Files</span>
            <button
              onClick={fetchFileTree}
              className="p-1 hover:bg-gray-200 rounded"
              title="Refresh"
            >
              <RefreshCw className="w-3 h-3 text-gray-400" />
            </button>
          </div>
          <div className="p-2">
            {loading ? (
              <div className="flex items-center justify-center py-8">
                <RefreshCw className="w-5 h-5 text-gray-400 animate-spin" />
              </div>
            ) : fileTree.length > 0 ? (
              <FileTree
                nodes={fileTree}
                selectedPath={selectedFile?.path || null}
                onSelect={loadFile}
                expandedPaths={expandedPaths}
                onToggle={handleToggle}
              />
            ) : (
              <div className="text-center py-8 text-gray-500 text-sm">
                <FolderOpen className="w-8 h-8 mx-auto mb-2 text-gray-300" />
                No files found
              </div>
            )}
          </div>
        </div>

        {/* Editor Area */}
        <div className="flex-1 flex overflow-hidden">
          {selectedFile ? (
            <>
              {/* Code Editor Panel */}
              {(viewMode === 'code' || viewMode === 'split' || selectedFile.extension !== 'disyl') && (
                <div className={`flex flex-col bg-gray-900 ${
                  viewMode === 'split' && selectedFile.extension === 'disyl' ? 'w-1/2' : 'flex-1'
                }`}>
                  {/* Editor Header */}
                  <div className="px-4 py-2 bg-gray-800 border-b border-gray-700 flex items-center gap-2">
                    {getFileIcon(selectedFile.extension)}
                    <span className="text-sm text-gray-300">{selectedFile.name}</span>
                    <span className="text-xs text-gray-500 ml-2">
                      {selectedFile.extension?.toUpperCase() || 'TEXT'}
                    </span>
                  </div>
                  
                  {/* Monaco Code Editor */}
                  <div className="flex-1 overflow-hidden">
                    <Editor
                      height="100%"
                      language={selectedFile?.extension === 'disyl' ? 'disyl' : getMonacoLanguage(selectedFile?.extension)}
                      value={fileContent}
                      onChange={(value) => setFileContent(value || '')}
                      onMount={handleEditorMount}
                      theme={editorTheme}
                      options={{
                        fontSize: fontSize,
                        minimap: { enabled: showMinimap },
                        wordWrap: 'on',
                        lineNumbers: 'on',
                        renderWhitespace: 'selection',
                        scrollBeyondLastLine: false,
                        automaticLayout: true,
                        tabSize: 2,
                        insertSpaces: true,
                        formatOnPaste: true,
                        formatOnType: true,
                        suggestOnTriggerCharacters: true,
                        quickSuggestions: true,
                        folding: true,
                        foldingStrategy: 'indentation',
                        bracketPairColorization: { enabled: true },
                        guides: {
                          bracketPairs: true,
                          indentation: true,
                        },
                      }}
                    />
                  </div>
                </div>
              )}

              {/* Visual Preview Panel - Only for DiSyL files */}
              {selectedFile.extension === 'disyl' && (viewMode === 'preview' || viewMode === 'split') && (
                <div className={`flex bg-gray-100 border-l border-gray-200 ${
                  viewMode === 'split' ? 'w-1/2' : 'flex-1'
                }`}>
                  {/* Preview + Properties Container */}
                  <div className="flex-1 flex flex-col">
                    {/* Preview Header */}
                    <div className="px-4 py-2 bg-white border-b border-gray-200 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Eye className="w-4 h-4 text-blue-500" />
                        <span className="text-sm font-medium text-gray-700">Visual Preview</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-500">
                          {parsedNodes.length} component{parsedNodes.length !== 1 ? 's' : ''}
                        </span>
                        {selectedNode && (
                          <span className="text-xs px-2 py-0.5 bg-blue-100 text-blue-600 rounded">
                            {selectedNode.componentId}
                          </span>
                        )}
                      </div>
                    </div>
                    
                    {/* Visual Preview Content */}
                    <div className="flex-1 overflow-auto flex justify-center">
                      <VisualPreview
                        nodes={parsedNodes}
                        selectedNode={selectedNode}
                        onSelectNode={handleSelectNode}
                        deviceWidth={deviceWidth}
                      />
                    </div>
                  </div>
                  
                  {/* Properties Panel */}
                  {showPropertiesPanel && selectedNode && (
                    <PropertiesPanel
                      node={selectedNode}
                      onClose={() => {
                        setShowPropertiesPanel(false)
                        setSelectedNode(null)
                      }}
                    />
                  )}
                </div>
              )}
            </>
          ) : (
            <div className="flex-1 flex items-center justify-center text-gray-500 bg-gray-900">
              <div className="text-center">
                <FileCode className="w-16 h-16 mx-auto mb-4 text-gray-600" />
                <p className="text-lg">Select a file to edit</p>
                <p className="text-sm text-gray-600 mt-1">
                  Choose a file from the sidebar to start editing
                </p>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
