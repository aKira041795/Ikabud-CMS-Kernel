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
  Moon
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

  // Fetch themes for instance
  useEffect(() => {
    if (instanceId) {
      fetchThemes()
    }
  }, [instanceId])

  // Fetch file tree when theme changes
  useEffect(() => {
    if (instanceId && selectedTheme) {
      fetchFileTree()
    }
  }, [instanceId, selectedTheme])

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
    
    // DiSyL autocomplete
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
        
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const suggestions: any[] = []
        
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
        
        // Filter suggestions
        DISYL_PATTERNS.filters.forEach(filter => {
          suggestions.push({
            label: `| ${filter}`,
            kind: monaco.languages.CompletionItemKind.Function,
            insertText: `| ${filter}`,
            documentation: `Filter: ${filter}`,
            range,
          })
        })
        
        return { suggestions }
      },
      triggerCharacters: ['{', '|', ' ']
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
        <div className="flex-1 flex flex-col bg-gray-900">
          {selectedFile ? (
            <>
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
            </>
          ) : (
            <div className="flex-1 flex items-center justify-center text-gray-500">
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
