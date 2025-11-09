import { useState, useEffect } from 'react'
import { api } from '../lib/api'
import { Zap, Package, Server, AlertCircle, CheckCircle, XCircle } from 'lucide-react'
import toast from 'react-hot-toast'

interface Extension {
  file: string
  name: string
  enabled: boolean
  priority: number
  routes: string[]
  load_in_admin: boolean
}

interface InstanceStats {
  instance_id: string
  enabled: boolean
  total_plugins: number
  plugins: Extension[]
}

export default function ConditionalLoading() {
  const [instances, setInstances] = useState<any[]>([])
  const [selectedInstance, setSelectedInstance] = useState<string>('')
  const [stats, setStats] = useState<InstanceStats | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    loadInstances()
  }, [])

  const loadInstances = async () => {
    try {
      const data = await api.get('/instances/list.php')
      setInstances(data.instances || [])
      if (data.instances?.length > 0) {
        setSelectedInstance(data.instances[0].instance_id)
        loadStats(data.instances[0].instance_id)
      }
    } catch (error) {
      toast.error('Failed to load instances')
      console.error('Load instances error:', error)
    }
  }

  const loadStats = async (instanceId: string) => {
    setLoading(true)
    try {
      const data = await api.get(`/instances/${instanceId}/conditional-loading/stats`)
      setStats(data)
    } catch (error) {
      toast.error('Failed to load conditional loading stats')
    } finally {
      setLoading(false)
    }
  }

  const handleInstanceChange = (instanceId: string) => {
    setSelectedInstance(instanceId)
    loadStats(instanceId)
  }

  const generateManifest = async () => {
    if (!selectedInstance) return
    
    setLoading(true)
    try {
      await api.post(`/instances/${selectedInstance}/conditional-loading/generate`)
      toast.success('Manifest generated successfully')
      loadStats(selectedInstance)
    } catch (error) {
      toast.error('Failed to generate manifest')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <Zap className="w-8 h-8 text-primary-600" />
            Conditional Loading
          </h1>
          <p className="mt-2 text-gray-600">
            Manage CMS-agnostic conditional extension loading for optimal performance
          </p>
        </div>
        <button
          onClick={generateManifest}
          disabled={!selectedInstance || loading}
          className="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <Package className="w-4 h-4" />
          Generate Manifest
        </button>
      </div>

      {/* Instance Selector */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <label className="block text-sm font-medium text-gray-700 mb-2">
          Select Instance
        </label>
        <select
          value={selectedInstance}
          onChange={(e) => handleInstanceChange(e.target.value)}
          className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
        >
          {instances.map((instance) => (
            <option key={instance.instance_id} value={instance.instance_id}>
              {instance.instance_name || instance.instance_id} ({instance.cms_type || 'wordpress'})
            </option>
          ))}
        </select>
      </div>

      {/* Stats Overview */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">Status</p>
                <p className="text-2xl font-bold text-gray-900 mt-1">
                  {stats.enabled ? 'Enabled' : 'Disabled'}
                </p>
              </div>
              {stats.enabled ? (
                <CheckCircle className="w-12 h-12 text-success-500" />
              ) : (
                <XCircle className="w-12 h-12 text-gray-400" />
              )}
            </div>
          </div>

          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">Total Extensions</p>
                <p className="text-2xl font-bold text-gray-900 mt-1">
                  {stats.total_plugins}
                </p>
              </div>
              <Package className="w-12 h-12 text-primary-500" />
            </div>
          </div>

          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">Instance ID</p>
                <p className="text-lg font-semibold text-gray-900 mt-1 truncate">
                  {stats.instance_id}
                </p>
              </div>
              <Server className="w-12 h-12 text-indigo-500" />
            </div>
          </div>
        </div>
      )}

      {/* Extensions List */}
      {stats && stats.plugins.length > 0 && (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
          <div className="px-6 py-4 border-b border-gray-200">
            <h2 className="text-lg font-semibold text-gray-900">Extensions Configuration</h2>
          </div>
          <div className="divide-y divide-gray-200">
            {stats.plugins.map((plugin, index) => (
              <div key={index} className="px-6 py-4 hover:bg-gray-50">
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <div className="flex items-center gap-3">
                      <h3 className="text-sm font-medium text-gray-900">{plugin.name}</h3>
                      <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                        plugin.enabled 
                          ? 'bg-success-100 text-success-700' 
                          : 'bg-gray-100 text-gray-700'
                      }`}>
                        {plugin.enabled ? 'Enabled' : 'Disabled'}
                      </span>
                      <span className="px-2 py-1 text-xs font-medium bg-primary-100 text-primary-700 rounded-full">
                        Priority: {plugin.priority}
                      </span>
                    </div>
                    <p className="text-xs text-gray-500 mt-1">{plugin.file}</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {plugin.routes.length > 0 ? (
                        plugin.routes.map((route, idx) => (
                          <span key={idx} className="px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded">
                            {route}
                          </span>
                        ))
                      ) : (
                        <span className="px-2 py-1 text-xs bg-gray-50 text-gray-500 rounded">
                          No routes configured
                        </span>
                      )}
                      {plugin.load_in_admin && (
                        <span className="px-2 py-1 text-xs bg-purple-50 text-purple-700 rounded">
                          Admin
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Help Section */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <div className="flex gap-3">
          <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
          <div>
            <h3 className="text-sm font-semibold text-blue-900 mb-2">About Conditional Loading</h3>
            <p className="text-sm text-blue-800 mb-2">
              Conditional loading is a CMS-agnostic system that loads extensions (plugins, modules, components) 
              only when needed, based on request context.
            </p>
            <ul className="text-sm text-blue-800 space-y-1 list-disc list-inside">
              <li><strong>Cache Hit:</strong> 60ms, 0 extensions loaded (26x faster)</li>
              <li><strong>Conditional Load:</strong> 800ms, 2-4 extensions loaded (2x faster)</li>
              <li><strong>Full Load:</strong> Admin only, all extensions loaded</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  )
}
