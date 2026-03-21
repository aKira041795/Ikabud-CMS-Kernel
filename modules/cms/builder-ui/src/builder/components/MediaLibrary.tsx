/**
 * Ikabud Page Builder - Media Library Modal
 * Browse and select images from the CMS media library
 */

import React, { memo, useState, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { X, Search, Upload, Image, Loader2, Check } from 'lucide-react';
import { authFetch } from '@/lib/api';

interface MediaItem {
  id: number;
  url: string;
  filename: string;
  alt?: string;
  width?: number;
  height?: number;
  mime_type: string;
  created_at: string;
}

interface MediaLibraryProps {
  isOpen: boolean;
  onClose: () => void;
  onSelect: (url: string, alt?: string) => void;
}

const MediaLibrary: React.FC<MediaLibraryProps> = ({
  isOpen,
  onClose,
  onSelect,
}) => {
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [uploading, setUploading] = useState(false);

  // Fetch media from API
  const fetchMedia = useCallback(async () => {
    setLoading(true);
    setError(null);
    
    try {
      const params = new URLSearchParams();
      if (searchQuery) params.append('search', searchQuery);
      params.append('type', 'image'); // Only fetch images for the builder
      params.append('limit', '50');
      
      const response = await authFetch(`/api/v1/cms/media?${params.toString()}`);
      const data = await response.json();
      
      if ((data.ok || data.success) && data.data) {
        // Map API response to our MediaItem interface
        const items = data.data.map((item: Record<string, unknown>) => ({
          id: item.id as number,
          url: item.url as string,
          filename: item.filename as string || item.original_filename as string || 'image',
          alt: item.alt_text as string || item.alt as string || '',
          width: item.width as number,
          height: item.height as number,
          mime_type: item.mime_type as string || 'image/jpeg',
          created_at: item.created_at as string,
        }));
        setMedia(items);
      } else {
        setMedia([]);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load media');
      setMedia([]);
    } finally {
      setLoading(false);
    }
  }, [searchQuery]);

  useEffect(() => {
    if (isOpen) {
      fetchMedia();
    }
  }, [isOpen, fetchMedia]);

  // Handle file upload
  const handleUpload = useCallback(async (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files || files.length === 0) return;

    setUploading(true);
    setError(null);
    
    try {
      const formData = new FormData();
      formData.append('file', files[0]);

      const response = await authFetch('/api/v1/cms/media/upload', {
        method: 'POST',
        body: formData,
      });
      const data = await response.json();
      if (!data.ok && !data.success) {
        throw new Error(data.error || 'Upload failed');
      }
      
      // Refresh media list
      await fetchMedia();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Upload failed');
    } finally {
      setUploading(false);
      // Reset the input
      e.target.value = '';
    }
  }, [fetchMedia]);

  // Handle selection
  const handleSelect = useCallback(() => {
    const selected = media.find(m => m.id === selectedId);
    if (selected) {
      onSelect(selected.url, selected.alt || selected.filename);
      onClose();
    }
  }, [media, selectedId, onSelect, onClose]);

  if (!isOpen) return null;

  const modalContent = (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50">
      <div className="bg-[#252526] w-full max-w-4xl max-h-[80vh] flex flex-col shadow-2xl">
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <h2 className="text-sm font-medium text-white">Media Library</h2>
          <button
            onClick={onClose}
            className="p-1 hover:bg-white/10 transition-colors"
          >
            <X className="w-4 h-4 text-white/60" />
          </button>
        </div>

        {/* Toolbar */}
        <div className="flex items-center gap-3 px-4 py-3 border-b border-[#3c3c3c]">
          {/* Search */}
          <div className="flex-1 relative">
            <Search className="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-white/30" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search media..."
              className="w-full pl-8 pr-3 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/90 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
            />
          </div>
          
          {/* Upload button */}
          <label className="flex items-center gap-2 px-3 py-1.5 bg-[#0078d4] text-white text-xs cursor-pointer hover:bg-[#006cbd] transition-colors">
            {uploading ? (
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
            ) : (
              <Upload className="w-3.5 h-3.5" />
            )}
            Upload
            <input
              type="file"
              accept="image/*"
              onChange={handleUpload}
              className="hidden"
              disabled={uploading}
            />
          </label>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-4">
          {loading ? (
            <div className="flex items-center justify-center h-48">
              <Loader2 className="w-6 h-6 animate-spin text-white/50" />
            </div>
          ) : error && media.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-48 text-center">
              <Image className="w-10 h-10 text-white/20 mb-3" />
              <p className="text-xs text-white/40">{error}</p>
            </div>
          ) : media.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-48 text-center">
              <Image className="w-10 h-10 text-white/20 mb-3" />
              <p className="text-xs text-white/40">No media found</p>
              <p className="text-[10px] text-white/30 mt-1">Upload images to get started</p>
            </div>
          ) : (
            <div className="grid grid-cols-4 gap-3">
              {media.map((item) => (
                <button
                  key={item.id}
                  onClick={() => setSelectedId(item.id)}
                  className={`relative aspect-square bg-[#1e1e1e] overflow-hidden group transition-all ${
                    selectedId === item.id
                      ? 'ring-2 ring-[#0078d4]'
                      : 'hover:ring-1 hover:ring-white/20'
                  }`}
                >
                  <img
                    src={item.url}
                    alt={item.alt || item.filename}
                    className="w-full h-full object-cover"
                  />
                  {selectedId === item.id && (
                    <div className="absolute inset-0 bg-[#0078d4]/20 flex items-center justify-center">
                      <div className="w-6 h-6 bg-[#0078d4] flex items-center justify-center">
                        <Check className="w-4 h-4 text-white" />
                      </div>
                    </div>
                  )}
                  <div className="absolute bottom-0 left-0 right-0 p-2 bg-gradient-to-t from-black/80 to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                    <p className="text-[10px] text-white truncate">{item.filename}</p>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between px-4 py-3 border-t border-[#3c3c3c]">
          <p className="text-[10px] text-white/40">
            {selectedId ? '1 item selected' : `${media.length} items`}
          </p>
          <div className="flex gap-2">
            <button
              onClick={onClose}
              className="px-4 py-1.5 text-xs text-white/70 hover:text-white hover:bg-white/5 transition-colors"
            >
              Cancel
            </button>
            <button
              onClick={handleSelect}
              disabled={!selectedId}
              className={`px-4 py-1.5 text-xs transition-colors ${
                selectedId
                  ? 'bg-[#0078d4] text-white hover:bg-[#006cbd]'
                  : 'bg-white/10 text-white/30 cursor-not-allowed'
              }`}
            >
              Insert
            </button>
          </div>
        </div>
      </div>
    </div>
  );

  return createPortal(modalContent, document.body);
};

export default memo(MediaLibrary);
