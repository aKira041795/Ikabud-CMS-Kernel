import { useState, useEffect, useRef, useCallback } from 'react'
import { authFetch } from '@/lib/api'
import {
  Image,
  Upload,
  Search,
  Grid,
  List,
  X,
  Loader2,
  File,
  Film,
  Music,
  FileText,
  Check,
} from 'lucide-react'

interface MediaItem {
  id: number
  filename: string
  original_filename: string
  mime_type: string
  file_size: number
  url: string
  thumbnail_url: string | null
  width: number | null
  height: number | null
  alt_text: string | null
  title: string | null
  folder_id: number | null
  created_at: string
}

interface ImageSettings {
  alt: string
  className: string
  width: string
  height: string
  caption: boolean
}

interface MediaPickerProps {
  isOpen: boolean
  onClose: () => void
  onSelect: (item: MediaItem, settings?: ImageSettings) => void
  onSelectMultiple?: (items: MediaItem[]) => void
  allowMultiple?: boolean
  acceptedTypes?: string[]
  title?: string
  showImageSettings?: boolean
}

const getFileIcon = (mimeType: string) => {
  if (mimeType.startsWith('image/')) return <Image className="w-8 h-8" />
  if (mimeType.startsWith('video/')) return <Film className="w-8 h-8" />
  if (mimeType.startsWith('audio/')) return <Music className="w-8 h-8" />
  if (mimeType.includes('pdf')) return <FileText className="w-8 h-8" />
  return <File className="w-8 h-8" />
}

const formatFileSize = (bytes: number) => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

export default function MediaPicker({
  isOpen,
  onClose,
  onSelect,
  onSelectMultiple,
  allowMultiple = false,
  acceptedTypes = ['image/*'],
  title = 'Select Media',
  showImageSettings = false,
}: MediaPickerProps) {
  const [media, setMedia] = useState<MediaItem[]>([])
  const [loading, setLoading] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [search, setSearch] = useState('')
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid')
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null)
  const [currentFolder, _setCurrentFolder] = useState<number | null>(null)
  const [selectedItems, setSelectedItems] = useState<Set<number>>(new Set())
  const [activeTab, setActiveTab] = useState<'library' | 'upload'>('library')
  const fileInputRef = useRef<HTMLInputElement>(null)
  
  // Image settings state
  const [imageSettings, setImageSettings] = useState<ImageSettings>({
    alt: '',
    className: 'aligncenter',
    width: '',
    height: '',
    caption: false,
  })

  const fetchMedia = useCallback(async () => {
    try {
      setLoading(true)
      const params = new URLSearchParams()
      if (search) params.append('search', search)
      if (currentFolder) params.append('folder_id', currentFolder.toString())

      const response = await authFetch(`/api/v1/cms/media?${params}`)
      const data = await response.json()

      if (data.ok || data.success) {
        setMedia(data.data || [])
      }
    } catch (error) {
      console.error('Failed to fetch media:', error)
    } finally {
      setLoading(false)
    }
  }, [search, currentFolder])

  useEffect(() => {
    if (isOpen) {
      fetchMedia()
    }
  }, [isOpen, fetchMedia])

  const handleUpload = async (files: FileList | null) => {
    if (!files || files.length === 0) return

    setUploading(true)
    try {
      for (const file of Array.from(files)) {
        const formData = new FormData()
        formData.append('file', file)
        if (currentFolder) {
          formData.append('folder_id', currentFolder.toString())
        }

        const response = await authFetch('/api/v1/cms/media/upload', {
          method: 'POST',
          body: formData,
        })

        const data = await response.json()
        if ((data.ok || data.success) && (data.data || data.url)) {
          const uploadedItem = data.data || {
            id: data.id,
            filename: data.filename,
            original_filename: data.filename,
            mime_type: file.type,
            file_size: file.size,
            url: data.url,
            thumbnail_url: data.thumbnails?.medium || data.url,
            width: null,
            height: null,
            alt_text: '',
            title: file.name,
            folder_id: currentFolder,
            created_at: new Date().toISOString(),
          }
          setSelectedItem(uploadedItem)
          if (showImageSettings) {
            setImageSettings(prev => ({
              ...prev,
              alt: uploadedItem.alt_text || uploadedItem.title || '',
              width: uploadedItem.width ? String(uploadedItem.width) : '',
              height: uploadedItem.height ? String(uploadedItem.height) : '',
            }))
          }
        }
      }
      // Refresh media list and switch to library tab after all uploads complete
      await fetchMedia()
      setActiveTab('library')
    } catch (error) {
      console.error('Upload failed:', error)
    } finally {
      setUploading(false)
    }
  }

  const handleSelect = () => {
    if (allowMultiple && onSelectMultiple) {
      // For multiple selection, get all selected items
      const selectedMediaItems = media.filter(item => selectedItems.has(item.id))
      if (selectedMediaItems.length > 0) {
        onSelectMultiple(selectedMediaItems)
        onClose()
      }
    } else {
      // Single selection (fallback)
      if (selectedItem) {
        onSelect(selectedItem, showImageSettings ? imageSettings : undefined)
        onClose()
      }
    }
  }
  
  // Update alt text when item is selected
  const handleItemSelect = (item: MediaItem) => {
    setSelectedItem(item)
    if (showImageSettings) {
      setImageSettings(prev => ({
        ...prev,
        alt: item.alt_text || item.title || '',
        width: item.width ? String(item.width) : '',
        height: item.height ? String(item.height) : '',
      }))
    }
  }

  const toggleItemSelection = (item: MediaItem) => {
    if (allowMultiple) {
      const newSelected = new Set(selectedItems)
      if (newSelected.has(item.id)) {
        newSelected.delete(item.id)
      } else {
        newSelected.add(item.id)
      }
      setSelectedItems(newSelected)
    } else {
      handleItemSelect(item)
    }
  }

  const filteredMedia = media.filter((item) => {
    // Filter by accepted types
    if (acceptedTypes.length > 0 && !acceptedTypes.includes('*/*')) {
      const typeMatch = acceptedTypes.some((type) => {
        if (type.endsWith('/*')) {
          return item.mime_type.startsWith(type.replace('/*', '/'))
        }
        return item.mime_type === type
      })
      if (!typeMatch) return false
    }
    return true
  })

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[85vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b">
          <h3 className="text-lg font-semibold">{title}</h3>
          <button
            onClick={onClose}
            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex border-b">
          <button
            onClick={() => setActiveTab('library')}
            className={`px-6 py-3 text-sm font-medium transition-colors ${
              activeTab === 'library'
                ? 'text-blue-600 border-b-2 border-blue-600'
                : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            Media Library
          </button>
          <button
            onClick={() => setActiveTab('upload')}
            className={`px-6 py-3 text-sm font-medium transition-colors ${
              activeTab === 'upload'
                ? 'text-blue-600 border-b-2 border-blue-600'
                : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            Upload New
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 flex overflow-hidden">
          {activeTab === 'library' ? (
            <>
              {/* Media Grid */}
              <div className="flex-1 flex flex-col overflow-hidden">
                {/* Toolbar */}
                <div className="flex items-center gap-4 p-4 border-b bg-gray-50">
                  <div className="relative flex-1 max-w-md">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                    <input
                      type="text"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder="Search media..."
                      className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                  <div className="flex items-center gap-1 border border-gray-200 rounded-lg p-1">
                    <button
                      onClick={() => setViewMode('grid')}
                      className={`p-1.5 rounded ${viewMode === 'grid' ? 'bg-gray-200' : 'hover:bg-gray-100'}`}
                    >
                      <Grid className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => setViewMode('list')}
                      className={`p-1.5 rounded ${viewMode === 'list' ? 'bg-gray-200' : 'hover:bg-gray-100'}`}
                    >
                      <List className="w-4 h-4" />
                    </button>
                  </div>
                </div>

                {/* Media Items */}
                <div className="flex-1 overflow-auto p-4">
                  {loading ? (
                    <div className="flex items-center justify-center h-64">
                      <Loader2 className="w-8 h-8 animate-spin text-blue-600" />
                    </div>
                  ) : filteredMedia.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                      <Image className="w-12 h-12 mb-4" />
                      <p>No media found</p>
                      <button
                        onClick={() => setActiveTab('upload')}
                        className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                      >
                        <Upload className="w-4 h-4 inline mr-2" />
                        Upload New
                      </button>
                    </div>
                  ) : viewMode === 'grid' ? (
                    <div className="grid grid-cols-4 gap-4">
                      {filteredMedia.map((item) => (
                        <button
                          key={item.id}
                          onClick={() => toggleItemSelection(item)}
                          className={`relative aspect-square rounded-lg overflow-hidden border-2 transition-all ${
                            selectedItem?.id === item.id
                              ? 'border-blue-500 ring-2 ring-blue-200'
                              : 'border-transparent hover:border-gray-300'
                          }`}
                        >
                          {item.mime_type.startsWith('image/') ? (
                            <img
                              src={item.thumbnail_url || item.url}
                              alt={item.alt_text || item.filename}
                              className="w-full h-full object-cover"
                            />
                          ) : (
                            <div className="w-full h-full flex items-center justify-center bg-gray-100">
                              {getFileIcon(item.mime_type)}
                            </div>
                          )}
                          {selectedItem?.id === item.id && (
                            <div className="absolute top-2 right-2 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center">
                              <Check className="w-4 h-4 text-white" />
                            </div>
                          )}
                        </button>
                      ))}
                    </div>
                  ) : (
                    <div className="space-y-2">
                      {filteredMedia.map((item) => (
                        <button
                          key={item.id}
                          onClick={() => toggleItemSelection(item)}
                          className={`w-full flex items-center gap-4 p-3 rounded-lg border transition-all ${
                            selectedItem?.id === item.id
                              ? 'border-blue-500 bg-blue-50'
                              : 'border-gray-200 hover:border-gray-300'
                          }`}
                        >
                          <div className="w-12 h-12 rounded overflow-hidden flex-shrink-0">
                            {item.mime_type.startsWith('image/') ? (
                              <img
                                src={item.thumbnail_url || item.url}
                                alt={item.alt_text || item.filename}
                                className="w-full h-full object-cover"
                              />
                            ) : (
                              <div className="w-full h-full flex items-center justify-center bg-gray-100">
                                {getFileIcon(item.mime_type)}
                              </div>
                            )}
                          </div>
                          <div className="flex-1 text-left">
                            <p className="font-medium text-gray-900 truncate">
                              {item.title || item.original_filename}
                            </p>
                            <p className="text-sm text-gray-500">
                              {formatFileSize(item.file_size)}
                              {item.width && item.height && ` • ${item.width}×${item.height}`}
                            </p>
                          </div>
                          {selectedItem?.id === item.id && (
                            <Check className="w-5 h-5 text-blue-600" />
                          )}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              {/* Details Panel */}
              {selectedItem && (
                <div className="w-80 border-l bg-gray-50 p-4 overflow-auto">
                  {selectedItem.mime_type.startsWith('image/') && (
                    <div className="mb-4 rounded-lg overflow-hidden border">
                      <img
                        src={selectedItem.url}
                        alt={selectedItem.alt_text || selectedItem.filename}
                        className="w-full"
                      />
                    </div>
                  )}
                  
                  {/* Image Settings */}
                  {showImageSettings && selectedItem.mime_type.startsWith('image/') ? (
                    <div className="space-y-4">
                      <h4 className="font-medium text-gray-900">Image Settings</h4>
                      
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                          Alt Text
                        </label>
                        <input
                          type="text"
                          value={imageSettings.alt}
                          onChange={(e) => setImageSettings(prev => ({ ...prev, alt: e.target.value }))}
                          placeholder="Describe the image..."
                          className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                      </div>
                      
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                          Alignment
                        </label>
                        <select
                          value={imageSettings.className}
                          onChange={(e) => setImageSettings(prev => ({ ...prev, className: e.target.value }))}
                          className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                          <option value="">None</option>
                          <option value="alignleft">Align Left</option>
                          <option value="aligncenter">Align Center</option>
                          <option value="alignright">Align Right</option>
                          <option value="alignfull">Full Width</option>
                        </select>
                      </div>
                      
                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-1">
                            Width
                          </label>
                          <input
                            type="text"
                            value={imageSettings.width}
                            onChange={(e) => setImageSettings(prev => ({ ...prev, width: e.target.value }))}
                            placeholder="Auto"
                            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-1">
                            Height
                          </label>
                          <input
                            type="text"
                            value={imageSettings.height}
                            onChange={(e) => setImageSettings(prev => ({ ...prev, height: e.target.value }))}
                            placeholder="Auto"
                            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                      </div>
                      
                      <div className="pt-2 border-t text-xs text-gray-500">
                        <p>{selectedItem.original_filename}</p>
                        <p>{formatFileSize(selectedItem.file_size)} • {selectedItem.width}×{selectedItem.height}</p>
                      </div>
                    </div>
                  ) : (
                    /* Default details view */
                    <>
                      <h4 className="font-medium text-gray-900 mb-4">Details</h4>
                      <dl className="space-y-3 text-sm">
                        <div>
                          <dt className="text-gray-500">Filename</dt>
                          <dd className="font-medium text-gray-900 break-all">
                            {selectedItem.original_filename}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-gray-500">Size</dt>
                          <dd className="font-medium text-gray-900">
                            {formatFileSize(selectedItem.file_size)}
                          </dd>
                        </div>
                        {selectedItem.width && selectedItem.height && (
                          <div>
                            <dt className="text-gray-500">Dimensions</dt>
                            <dd className="font-medium text-gray-900">
                              {selectedItem.width} × {selectedItem.height}
                            </dd>
                          </div>
                        )}
                        <div>
                          <dt className="text-gray-500">Type</dt>
                          <dd className="font-medium text-gray-900">{selectedItem.mime_type}</dd>
                        </div>
                        <div>
                          <dt className="text-gray-500">URL</dt>
                          <dd className="font-medium text-gray-900 break-all text-xs">
                            {selectedItem.url}
                          </dd>
                        </div>
                      </dl>
                    </>
                  )}
                </div>
              )}
            </>
          ) : (
            /* Upload Tab */
            <div className="flex-1 p-8">
              <div
                className={`border-2 border-dashed rounded-xl p-12 text-center transition-colors ${
                  uploading ? 'border-blue-300 bg-blue-50' : 'border-gray-300 hover:border-blue-400'
                }`}
                onDragOver={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                }}
                onDrop={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  handleUpload(e.dataTransfer.files)
                }}
              >
                {uploading ? (
                  <>
                    <Loader2 className="w-12 h-12 mx-auto mb-4 text-blue-600 animate-spin" />
                    <p className="text-lg font-medium text-gray-900">Uploading...</p>
                  </>
                ) : (
                  <>
                    <Upload className="w-12 h-12 mx-auto mb-4 text-gray-400" />
                    <p className="text-lg font-medium text-gray-900 mb-2">
                      Drop files here or click to upload
                    </p>
                    <p className="text-sm text-gray-500 mb-4">
                      Supports images, videos, PDFs, and documents
                    </p>
                    <button
                      onClick={() => fileInputRef.current?.click()}
                      className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    >
                      Select Files
                    </button>
                    <input
                      ref={fileInputRef}
                      type="file"
                      multiple
                      accept={acceptedTypes.join(',')}
                      onChange={(e) => handleUpload(e.target.files)}
                      className="hidden"
                    />
                  </>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 p-4 border-t bg-gray-50">
          <button
            onClick={onClose}
            className="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={handleSelect}
            disabled={allowMultiple ? selectedItems.size === 0 : !selectedItem}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            Insert Selected
          </button>
        </div>
      </div>
    </div>
  )
}
