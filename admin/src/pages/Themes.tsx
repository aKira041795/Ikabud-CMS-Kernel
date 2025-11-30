import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { 
  Palette, 
  Sparkles, 
  Code2, 
  ArrowRight,
  Layers,
  FileCode,
  Server,
  ChevronDown,
  Upload,
  FolderOpen,
  RefreshCw,
  AlertCircle
} from 'lucide-react'

// Types
interface Instance {
  id: string
  name: string
  path: string
  cms_type: 'wordpress' | 'joomla' | 'drupal' | 'native'
  theme_path: string
  theme_count: number
}

interface Theme {
  id: string
  name: string
  path: string
  cms_type: string
  version?: string
  author?: string
  has_disyl: boolean
}

// CMS Icons/Colors
const CMS_CONFIG: Record<string, { icon: string; color: string; label: string }> = {
  wordpress: { icon: '🔵', color: 'blue', label: 'WordPress' },
  joomla: { icon: '🟠', color: 'orange', label: 'Joomla' },
  drupal: { icon: '🔷', color: 'cyan', label: 'Drupal' },
  native: { icon: '⚪', color: 'gray', label: 'Native' }
}

export default function Themes() {
  const navigate = useNavigate()
  
  // State
  const [instances, setInstances] = useState<Instance[]>([])
  const [selectedInstance, setSelectedInstance] = useState<Instance | null>(null)
  const [themes, setThemes] = useState<Theme[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showInstanceDropdown, setShowInstanceDropdown] = useState(false)
  const [newThemeName, setNewThemeName] = useState('')
  const [showNewThemeModal, setShowNewThemeModal] = useState(false)
  const [creatingTheme, setCreatingTheme] = useState(false)

  // Fetch instances on mount
  useEffect(() => {
    fetchInstances()
  }, [])

  // Fetch themes when instance changes
  useEffect(() => {
    if (selectedInstance) {
      fetchThemes(selectedInstance.id)
      // Store in localStorage for shared state
      localStorage.setItem('selectedInstance', JSON.stringify(selectedInstance))
    }
  }, [selectedInstance])

  // Load saved instance on mount
  useEffect(() => {
    const saved = localStorage.getItem('selectedInstance')
    if (saved) {
      try {
        const parsed = JSON.parse(saved)
        setSelectedInstance(parsed)
      } catch {
        // ignore
      }
    }
  }, [])

  const fetchInstances = async () => {
    try {
      setLoading(true)
      const res = await fetch('/api/v1/filesystem/instances')
      const data = await res.json()
      setInstances(data.instances || [])
      
      // Auto-select first instance if none selected
      if (!selectedInstance && data.instances?.length > 0) {
        setSelectedInstance(data.instances[0])
      }
    } catch (err) {
      setError('Failed to load instances')
      console.error(err)
    } finally {
      setLoading(false)
    }
  }

  const fetchThemes = async (instanceId: string) => {
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${instanceId}/themes`)
      const data = await res.json()
      setThemes(data.themes || [])
    } catch (err) {
      console.error('Failed to load themes:', err)
      setThemes([])
    }
  }

  const handleNewTheme = async () => {
    if (!selectedInstance || !newThemeName.trim()) return
    
    setCreatingTheme(true)
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${selectedInstance.id}/themes`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: newThemeName.trim() })
      })
      
      const data = await res.json()
      
      if (data.success) {
        setShowNewThemeModal(false)
        setNewThemeName('')
        // Navigate to Visual Builder with the new theme
        navigate(`/themes/visual-builder?instance=${selectedInstance.id}&theme=${data.theme_id}`)
      } else {
        setError(data.error || 'Failed to create theme')
      }
    } catch (err) {
      setError('Failed to create theme')
      console.error(err)
    } finally {
      setCreatingTheme(false)
    }
  }

  const handleUploadTheme = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!selectedInstance || !e.target.files?.[0]) return
    
    const file = e.target.files[0]
    const formData = new FormData()
    formData.append('theme', file)
    
    try {
      const res = await fetch(`/api/v1/filesystem/instances/${selectedInstance.id}/themes/upload`, {
        method: 'POST',
        body: formData
      })
      
      const data = await res.json()
      
      if (data.success) {
        fetchThemes(selectedInstance.id)
      } else {
        setError(data.error || 'Failed to upload theme')
      }
    } catch (err) {
      setError('Failed to upload theme')
      console.error(err)
    }
    
    // Reset input
    e.target.value = ''
  }

  const cmsConfig = selectedInstance ? CMS_CONFIG[selectedInstance.cms_type] : null

  return (
    <div>
      {/* Header with Instance Selector */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <Palette className="w-8 h-8 text-blue-500" />
            Theme Builder
          </h1>
          <p className="text-gray-600 mt-1">
            Create and manage DiSyL themes for your CMS instances
          </p>
        </div>
        
        {/* Instance Selector */}
        <div className="relative">
          <button
            onClick={() => setShowInstanceDropdown(!showInstanceDropdown)}
            className="flex items-center gap-3 px-4 py-2.5 bg-white border border-gray-200 rounded-lg hover:border-gray-300 transition-colors min-w-[240px]"
          >
            <Server className="w-4 h-4 text-gray-400" />
            {selectedInstance ? (
              <div className="flex-1 text-left">
                <div className="flex items-center gap-2">
                  <span>{cmsConfig?.icon}</span>
                  <span className="font-medium text-gray-900">{selectedInstance.name}</span>
                </div>
                <div className="text-xs text-gray-500">{cmsConfig?.label} • {selectedInstance.theme_count} themes</div>
              </div>
            ) : (
              <span className="text-gray-500">Select Instance</span>
            )}
            <ChevronDown className="w-4 h-4 text-gray-400" />
          </button>
          
          {showInstanceDropdown && (
            <div className="absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-lg shadow-lg z-50">
              <div className="p-2 border-b border-gray-100">
                <div className="flex items-center justify-between px-2">
                  <span className="text-xs font-medium text-gray-500 uppercase">Instances</span>
                  <button 
                    onClick={() => fetchInstances()}
                    className="p-1 hover:bg-gray-100 rounded"
                    title="Refresh"
                  >
                    <RefreshCw className="w-3 h-3 text-gray-400" />
                  </button>
                </div>
              </div>
              <div className="max-h-64 overflow-y-auto">
                {instances.map(instance => {
                  const config = CMS_CONFIG[instance.cms_type]
                  return (
                    <button
                      key={instance.id}
                      onClick={() => {
                        setSelectedInstance(instance)
                        setShowInstanceDropdown(false)
                      }}
                      className={`w-full px-4 py-3 text-left hover:bg-gray-50 flex items-center gap-3 ${
                        selectedInstance?.id === instance.id ? 'bg-blue-50' : ''
                      }`}
                    >
                      <span className="text-lg">{config?.icon}</span>
                      <div className="flex-1">
                        <div className="font-medium text-gray-900">{instance.name}</div>
                        <div className="text-xs text-gray-500">{config?.label} • {instance.theme_count} themes</div>
                      </div>
                    </button>
                  )
                })}
                {instances.length === 0 && (
                  <div className="px-4 py-6 text-center text-gray-500 text-sm">
                    No instances found
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Error Alert */}
      {error && (
        <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3">
          <AlertCircle className="w-5 h-5 text-red-500" />
          <span className="text-red-700">{error}</span>
          <button onClick={() => setError(null)} className="ml-auto text-red-500 hover:text-red-700">×</button>
        </div>
      )}

      {/* Loading State */}
      {loading && (
        <div className="flex items-center justify-center py-12">
          <RefreshCw className="w-6 h-6 text-gray-400 animate-spin" />
        </div>
      )}

      {/* Main Actions */}
      {!loading && selectedInstance && (
        <>
          {/* Action Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            {/* New Theme - Visual Builder */}
            <button
              onClick={() => setShowNewThemeModal(true)}
              className="group relative bg-white rounded-xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-lg transition-all duration-200 text-left"
            >
              <span className="absolute top-4 right-4 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
                New
              </span>
              <div className="inline-flex p-3 rounded-xl bg-blue-50 mb-4">
                <Sparkles className="w-6 h-6 text-blue-500" />
              </div>
              <h3 className="text-lg font-semibold text-gray-900 mb-2 group-hover:text-blue-600 transition-colors">
                New Theme
              </h3>
              <p className="text-gray-600 text-sm mb-4">
                Create a new theme using the Visual Builder with drag-and-drop components
              </p>
              <div className="flex items-center text-sm font-medium text-blue-600">
                Create <ArrowRight className="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" />
              </div>
            </button>

            {/* Code Editor */}
            <button
              onClick={() => navigate(`/themes/editor?instance=${selectedInstance.id}`)}
              className="group relative bg-white rounded-xl border border-gray-200 p-6 hover:border-purple-300 hover:shadow-lg transition-all duration-200 text-left"
            >
              <div className="inline-flex p-3 rounded-xl bg-purple-50 mb-4">
                <Code2 className="w-6 h-6 text-purple-500" />
              </div>
              <h3 className="text-lg font-semibold text-gray-900 mb-2 group-hover:text-purple-600 transition-colors">
                Code Editor
              </h3>
              <p className="text-gray-600 text-sm mb-4">
                Edit theme files directly with syntax highlighting for DiSyL, PHP, CSS, and more
              </p>
              <div className="flex items-center text-sm font-medium text-purple-600">
                Open Editor <ArrowRight className="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" />
              </div>
            </button>

            {/* Upload Theme */}
            <label className="group relative bg-white rounded-xl border border-gray-200 p-6 hover:border-green-300 hover:shadow-lg transition-all duration-200 text-left cursor-pointer">
              <input
                type="file"
                accept=".zip"
                onChange={handleUploadTheme}
                className="hidden"
              />
              <div className="inline-flex p-3 rounded-xl bg-green-50 mb-4">
                <Upload className="w-6 h-6 text-green-500" />
              </div>
              <h3 className="text-lg font-semibold text-gray-900 mb-2 group-hover:text-green-600 transition-colors">
                Add Theme
              </h3>
              <p className="text-gray-600 text-sm mb-4">
                Upload a theme ZIP file to install it in the selected instance
              </p>
              <div className="flex items-center text-sm font-medium text-green-600">
                Upload ZIP <ArrowRight className="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" />
              </div>
            </label>
          </div>

          {/* Existing Themes */}
          <div className="bg-white rounded-xl border border-gray-200 p-6 mb-8">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-gray-900">
                Themes in {selectedInstance.name}
              </h2>
              <span className="text-sm text-gray-500">{themes.length} themes</span>
            </div>
            
            {themes.length > 0 ? (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {themes.map(theme => (
                  <div
                    key={theme.id}
                    className="p-4 border border-gray-200 rounded-lg hover:border-gray-300 transition-colors"
                  >
                    <div className="flex items-start justify-between mb-2">
                      <div>
                        <h3 className="font-medium text-gray-900">{theme.name}</h3>
                        <p className="text-xs text-gray-500">{theme.id}</p>
                      </div>
                      {theme.has_disyl && (
                        <span className="px-2 py-0.5 text-xs bg-blue-100 text-blue-700 rounded">DiSyL</span>
                      )}
                    </div>
                    {theme.version && (
                      <p className="text-xs text-gray-500 mb-3">v{theme.version}</p>
                    )}
                    <div className="flex gap-2">
                      <button
                        onClick={() => navigate(`/themes/visual-builder?instance=${selectedInstance.id}&theme=${theme.id}`)}
                        className="flex-1 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                      >
                        <Sparkles className="w-3 h-3 inline mr-1" />
                        Visual
                      </button>
                      <button
                        onClick={() => navigate(`/themes/editor?instance=${selectedInstance.id}&theme=${theme.id}`)}
                        className="flex-1 px-3 py-1.5 text-xs font-medium text-purple-600 bg-purple-50 rounded hover:bg-purple-100 transition-colors"
                      >
                        <Code2 className="w-3 h-3 inline mr-1" />
                        Code
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-8 text-gray-500">
                <FolderOpen className="w-12 h-12 mx-auto mb-3 text-gray-300" />
                <p>No themes found in this instance</p>
                <p className="text-sm">Create a new theme or upload one to get started</p>
              </div>
            )}
          </div>
        </>
      )}

      {/* No Instance Selected */}
      {!loading && !selectedInstance && instances.length > 0 && (
        <div className="text-center py-12 bg-white rounded-xl border border-gray-200">
          <Server className="w-12 h-12 mx-auto mb-3 text-gray-300" />
          <p className="text-gray-600 mb-2">Select an instance to manage themes</p>
          <p className="text-sm text-gray-500">Use the dropdown above to choose a CMS instance</p>
        </div>
      )}

      {/* DiSyL Info */}
      <div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-100 p-6">
        <div className="flex items-start gap-4">
          <div className="p-3 bg-white rounded-xl shadow-sm">
            <FileCode className="w-6 h-6 text-blue-500" />
          </div>
          <div className="flex-1">
            <h3 className="text-lg font-semibold text-gray-900 mb-2">
              What is DiSyL?
            </h3>
            <p className="text-gray-600 text-sm mb-4">
              DiSyL (Display Syntax Language) is a powerful templating language designed for creating 
              cross-platform themes. It supports WordPress, Joomla, Drupal, and native rendering with 
              a unified syntax.
            </p>
            <div className="flex flex-wrap gap-2">
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                <Layers className="w-3 h-3 inline mr-1" /> Components
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Filters
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Control Structures
              </span>
              <span className="px-3 py-1 bg-white text-sm text-gray-700 rounded-full border border-gray-200">
                Expressions
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* New Theme Modal */}
      {showNewThemeModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
            <h2 className="text-xl font-semibold text-gray-900 mb-4">Create New Theme</h2>
            <p className="text-sm text-gray-600 mb-4">
              This will create a new theme in <strong>{selectedInstance?.name}</strong> ({cmsConfig?.label})
            </p>
            <div className="mb-4">
              <label className="block text-sm font-medium text-gray-700 mb-1">Theme Name</label>
              <input
                type="text"
                value={newThemeName}
                onChange={(e) => setNewThemeName(e.target.value)}
                placeholder="My Awesome Theme"
                className="w-full px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                autoFocus
              />
            </div>
            <div className="flex gap-3">
              <button
                onClick={() => {
                  setShowNewThemeModal(false)
                  setNewThemeName('')
                }}
                className="flex-1 px-4 py-2 border border-gray-200 rounded-lg text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleNewTheme}
                disabled={!newThemeName.trim() || creatingTheme}
                className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {creatingTheme ? 'Creating...' : 'Create & Open Builder'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
