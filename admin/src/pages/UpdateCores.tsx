import { useState, useEffect, useCallback } from 'react'
import { 
  RefreshCw, 
  Download, 
  CheckCircle2, 
  AlertCircle, 
  Clock,
  HardDrive,
  FileCode,
  ArrowUpCircle,
  History,
  Loader2,
  ExternalLink
} from 'lucide-react'

interface Core {
  id: string
  name: string
  icon: string
  path: string
  installed_version: string | null
  size: number
  size_formatted: string
  last_modified: string
}

interface UpdateInfo {
  id: string
  name: string
  installed_version: string | null
  latest_version: string | null
  download_url: string | null
  release_date: string | null
  update_available: boolean
}

interface Backup {
  filename: string
  size: number
  size_formatted: string
  created_at: string
}

const cmsColors: Record<string, { bg: string; text: string; border: string }> = {
  wordpress: { bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200' },
  drupal: { bg: 'bg-cyan-50', text: 'text-cyan-700', border: 'border-cyan-200' },
  joomla: { bg: 'bg-orange-50', text: 'text-orange-700', border: 'border-orange-200' }
}

const getCmsColor = (icon: string) => {
  return cmsColors[icon] || { bg: 'bg-gray-50', text: 'text-gray-700', border: 'border-gray-200' }
}

export default function UpdateCores() {
  const [cores, setCores] = useState<Core[]>([])
  const [updates, setUpdates] = useState<UpdateInfo[]>([])
  const [backups, setBackups] = useState<Record<string, Backup[]>>({})
  const [loading, setLoading] = useState(true)
  const [checkingUpdates, setCheckingUpdates] = useState(false)
  const [updatingCore, setUpdatingCore] = useState<string | null>(null)
  const [lastChecked, setLastChecked] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [expandedCore, setExpandedCore] = useState<string | null>(null)

  const fetchCores = useCallback(async () => {
    try {
      const response = await fetch('/api/v1/cores')
      const data = await response.json()
      if (data.success) {
        setCores(data.cores)
      }
    } catch (err) {
      setError('Failed to fetch cores')
    }
  }, [])

  const checkForUpdates = useCallback(async () => {
    setCheckingUpdates(true)
    setError(null)
    try {
      const response = await fetch('/api/v1/cores/check-updates')
      const data = await response.json()
      if (data.success) {
        setUpdates(data.updates)
        setLastChecked(data.checked_at)
      } else {
        setError('Failed to check for updates')
      }
    } catch (err) {
      setError('Failed to check for updates')
    } finally {
      setCheckingUpdates(false)
    }
  }, [])

  const fetchBackups = useCallback(async (coreId: string) => {
    try {
      const response = await fetch(`/api/v1/cores/${coreId}/backups`)
      const data = await response.json()
      if (data.success) {
        setBackups(prev => ({ ...prev, [coreId]: data.backups }))
      }
    } catch (err) {
      console.error('Failed to fetch backups:', err)
    }
  }, [])

  const updateCore = async (coreId: string) => {
    setUpdatingCore(coreId)
    setError(null)
    setSuccessMessage(null)
    try {
      const response = await fetch(`/api/v1/cores/${coreId}/update`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      })
      const data = await response.json()
      if (data.success) {
        setSuccessMessage(`${coreId} updated from ${data.previous_version} to ${data.new_version}`)
        await fetchCores()
        await checkForUpdates()
        await fetchBackups(coreId)
      } else {
        setError(data.error || 'Failed to update core')
      }
    } catch (err) {
      setError('Failed to update core')
    } finally {
      setUpdatingCore(null)
    }
  }

  const restoreBackup = async (coreId: string, backupFile: string) => {
    if (!confirm(`Are you sure you want to restore ${coreId} from backup ${backupFile}?`)) {
      return
    }
    setUpdatingCore(coreId)
    setError(null)
    try {
      const response = await fetch(`/api/v1/cores/${coreId}/restore`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ backup_file: backupFile })
      })
      const data = await response.json()
      if (data.success) {
        setSuccessMessage(`${coreId} restored to version ${data.restored_version}`)
        await fetchCores()
        await checkForUpdates()
      } else {
        setError(data.error || 'Failed to restore backup')
      }
    } catch (err) {
      setError('Failed to restore backup')
    } finally {
      setUpdatingCore(null)
    }
  }

  useEffect(() => {
    const init = async () => {
      setLoading(true)
      await fetchCores()
      await checkForUpdates()
      setLoading(false)
    }
    init()
  }, [fetchCores, checkForUpdates])

  const getUpdateInfo = (coreId: string) => {
    return updates.find(u => u.id === coreId)
  }

  const hasAnyUpdates = updates.some(u => u.update_available)

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 animate-spin text-blue-500" />
        <span className="ml-3 text-gray-600">Loading cores...</span>
      </div>
    )
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Update Cores</h1>
          <p className="text-gray-600 mt-1">Manage and update shared CMS cores</p>
        </div>
        <div className="flex items-center gap-3">
          {lastChecked && (
            <span className="text-sm text-gray-500 flex items-center">
              <Clock className="w-4 h-4 mr-1" />
              Last checked: {new Date(lastChecked).toLocaleTimeString()}
            </span>
          )}
          <button
            onClick={checkForUpdates}
            disabled={checkingUpdates}
            className="btn btn-primary flex items-center"
          >
            <RefreshCw className={`w-4 h-4 mr-2 ${checkingUpdates ? 'animate-spin' : ''}`} />
            {checkingUpdates ? 'Checking...' : 'Check for Updates'}
          </button>
        </div>
      </div>

      {/* Status Banner */}
      {hasAnyUpdates && (
        <div className="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg flex items-center">
          <AlertCircle className="w-5 h-5 text-amber-600 mr-3" />
          <span className="text-amber-800">
            Updates available for {updates.filter(u => u.update_available).length} core(s)
          </span>
        </div>
      )}

      {error && (
        <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center">
          <AlertCircle className="w-5 h-5 text-red-600 mr-3" />
          <span className="text-red-800">{error}</span>
          <button onClick={() => setError(null)} className="ml-auto text-red-600 hover:text-red-800">
            ×
          </button>
        </div>
      )}

      {successMessage && (
        <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center">
          <CheckCircle2 className="w-5 h-5 text-green-600 mr-3" />
          <span className="text-green-800">{successMessage}</span>
          <button onClick={() => setSuccessMessage(null)} className="ml-auto text-green-600 hover:text-green-800">
            ×
          </button>
        </div>
      )}

      {/* Cores Grid */}
      <div className="grid gap-6">
        {cores.map(core => {
          const updateInfo = getUpdateInfo(core.id)
          const colors = getCmsColor(core.icon)
          const isExpanded = expandedCore === core.id
          const coreBackups = backups[core.id] || []

          return (
            <div 
              key={core.id} 
              className={`card border ${colors.border} ${colors.bg}`}
            >
              <div className="flex items-start justify-between">
                <div className="flex items-start">
                  <div className={`p-3 rounded-lg ${colors.bg} border ${colors.border}`}>
                    <FileCode className={`w-8 h-8 ${colors.text}`} />
                  </div>
                  <div className="ml-4">
                    <h3 className="text-lg font-semibold text-gray-900">{core.name}</h3>
                    <div className="flex items-center gap-4 mt-1 text-sm text-gray-600">
                      <span className="flex items-center">
                        <HardDrive className="w-4 h-4 mr-1" />
                        {core.size_formatted}
                      </span>
                      <span>
                        Modified: {new Date(core.last_modified).toLocaleDateString()}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  {/* Version Info */}
                  <div className="text-right">
                    <div className="text-sm text-gray-500">Installed</div>
                    <div className="font-mono font-medium text-gray-900">
                      {core.installed_version || 'Unknown'}
                    </div>
                  </div>

                  {updateInfo && (
                    <>
                      <div className="text-gray-300">→</div>
                      <div className="text-right">
                        <div className="text-sm text-gray-500">Latest</div>
                        <div className={`font-mono font-medium ${updateInfo.update_available ? 'text-green-600' : 'text-gray-900'}`}>
                          {updateInfo.latest_version || 'Unknown'}
                        </div>
                      </div>
                    </>
                  )}

                  {/* Status Badge */}
                  {updateInfo?.update_available ? (
                    <span className="px-3 py-1 bg-amber-100 text-amber-800 rounded-full text-sm font-medium flex items-center">
                      <ArrowUpCircle className="w-4 h-4 mr-1" />
                      Update Available
                    </span>
                  ) : (
                    <span className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium flex items-center">
                      <CheckCircle2 className="w-4 h-4 mr-1" />
                      Up to Date
                    </span>
                  )}
                </div>
              </div>

              {/* Actions */}
              <div className="mt-4 pt-4 border-t border-gray-200 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => {
                      setExpandedCore(isExpanded ? null : core.id)
                      if (!isExpanded) fetchBackups(core.id)
                    }}
                    className="btn btn-secondary text-sm flex items-center"
                  >
                    <History className="w-4 h-4 mr-1" />
                    {isExpanded ? 'Hide Backups' : 'View Backups'}
                  </button>
                  {updateInfo?.download_url && (
                    <a
                      href={updateInfo.download_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="btn btn-secondary text-sm flex items-center"
                    >
                      <ExternalLink className="w-4 h-4 mr-1" />
                      Download Manual
                    </a>
                  )}
                </div>

                {updateInfo?.update_available && (
                  <button
                    onClick={() => updateCore(core.id)}
                    disabled={updatingCore === core.id}
                    className="btn btn-primary flex items-center"
                  >
                    {updatingCore === core.id ? (
                      <>
                        <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                        Updating...
                      </>
                    ) : (
                      <>
                        <Download className="w-4 h-4 mr-2" />
                        Update to {updateInfo.latest_version}
                      </>
                    )}
                  </button>
                )}
              </div>

              {/* Backups Panel */}
              {isExpanded && (
                <div className="mt-4 pt-4 border-t border-gray-200">
                  <h4 className="text-sm font-medium text-gray-700 mb-3">Available Backups</h4>
                  {coreBackups.length === 0 ? (
                    <p className="text-sm text-gray-500">No backups available</p>
                  ) : (
                    <div className="space-y-2">
                      {coreBackups.map(backup => (
                        <div 
                          key={backup.filename}
                          className="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200"
                        >
                          <div>
                            <div className="font-mono text-sm text-gray-900">{backup.filename}</div>
                            <div className="text-xs text-gray-500">
                              {backup.size_formatted} • {new Date(backup.created_at).toLocaleString()}
                            </div>
                          </div>
                          <button
                            onClick={() => restoreBackup(core.id, backup.filename)}
                            disabled={updatingCore === core.id}
                            className="btn btn-secondary text-sm"
                          >
                            Restore
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )
        })}
      </div>

      {cores.length === 0 && (
        <div className="card text-center py-12">
          <FileCode className="w-12 h-12 text-gray-400 mx-auto mb-4" />
          <h3 className="text-lg font-medium text-gray-900 mb-2">No Shared Cores Found</h3>
          <p className="text-gray-600">
            Shared cores should be installed in the <code className="bg-gray-100 px-2 py-1 rounded">shared-cores/</code> directory.
          </p>
        </div>
      )}
    </div>
  )
}
