/**
 * Version History Panel
 * Shows revision history and allows restoring previous versions
 */

import React, { useState, useEffect, useCallback } from 'react';
import { X, Clock, RotateCcw, AlertCircle, Loader2 } from 'lucide-react';
import { authFetch } from '@/lib/api';

interface Version {
  id: number;
  snapshot_json: string;
  revision_number: number;
  created_at: string;
  note?: string;
}

interface VersionHistoryProps {
  contentId: number;
  isOpen: boolean;
  onClose: () => void;
  onRestore: (content: string) => void;
  currentContent: string;
}

const VersionHistory: React.FC<VersionHistoryProps> = ({
  contentId,
  isOpen,
  onClose,
  onRestore,
  currentContent,
}) => {
  const [versions, setVersions] = useState<Version[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedVersion, setSelectedVersion] = useState<Version | null>(null);
  const [previewContent, setPreviewContent] = useState<string | null>(null);
  const [restoring, setRestoring] = useState(false);

  // Fetch versions when opened
  useEffect(() => {
    if (isOpen && contentId) {
      fetchVersions();
    }
  }, [isOpen, contentId]);

  const fetchVersions = async () => {
    try {
      setLoading(true);
      setError(null);
      
      const response = await authFetch(`/api/v1/cms/content/${contentId}/builder/revisions`);
      const data = await response.json();
      
      if (data.ok || data.success) {
        setVersions(data.data || []);
      } else {
        setError(data.error || 'Failed to load versions');
      }
    } catch (err) {
      setError('Failed to load version history');
      console.error('Error fetching versions:', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePreview = useCallback((version: Version) => {
    setSelectedVersion(version);
    setPreviewContent(version.snapshot_json);
  }, []);

  const handleRestore = useCallback(async () => {
    if (!selectedVersion) return;
    
    try {
      setRestoring(true);
      const response = await authFetch(`/api/v1/cms/content/${contentId}/builder/revisions/${selectedVersion.id}/restore`, { method: 'POST' });
      const data = await response.json();
      if (!data.ok && !data.success) {
        throw new Error(data.error || 'Failed to restore version');
      }
      onRestore(selectedVersion.snapshot_json);
      onClose();
    } catch (err) {
      setError('Failed to restore version');
    } finally {
      setRestoring(false);
    }
  }, [selectedVersion, onRestore, onClose, contentId]);

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return date.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const getVersionLabel = (type: string) => {
    switch (type) {
      case 'publish': return 'Published';
      case 'manual': return 'Saved';
      case 'auto': return 'Auto-save';
      default: return 'Saved';
    }
  };

  const getVersionColor = (type: string) => {
    switch (type) {
      case 'publish': return 'text-emerald-400 bg-emerald-500/20';
      case 'manual': return 'text-blue-400 bg-blue-500/20';
      case 'auto': return 'text-gray-400 bg-gray-500/20';
      default: return 'text-gray-400 bg-gray-500/20';
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-[#252526] border border-[#3c3c3c] w-full max-w-2xl max-h-[80vh] flex flex-col shadow-2xl">
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <div className="flex items-center gap-2">
            <Clock className="w-4 h-4 text-white/70" />
            <h2 className="text-sm font-medium text-white">Version History</h2>
          </div>
          <button
            onClick={onClose}
            className="p-1 hover:bg-white/10 transition-colors"
          >
            <X className="w-4 h-4 text-white/70" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-hidden flex">
          {/* Version List */}
          <div className="w-1/2 border-r border-[#3c3c3c] overflow-y-auto">
            {loading ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="w-6 h-6 text-white/50 animate-spin" />
              </div>
            ) : error ? (
              <div className="flex flex-col items-center justify-center py-12 px-4">
                <AlertCircle className="w-8 h-8 text-red-400 mb-2" />
                <p className="text-sm text-red-400 text-center">{error}</p>
                <button
                  onClick={fetchVersions}
                  className="mt-3 text-xs text-blue-400 hover:underline"
                >
                  Try again
                </button>
              </div>
            ) : versions.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 px-4">
                <Clock className="w-8 h-8 text-white/30 mb-2" />
                <p className="text-sm text-white/50 text-center">No versions yet</p>
                <p className="text-xs text-white/30 text-center mt-1">
                  Versions are created when you save
                </p>
              </div>
            ) : (
              <div className="divide-y divide-[#3c3c3c]">
                {/* Current version */}
                <div
                  className={`px-4 py-3 cursor-pointer transition-colors ${
                    !selectedVersion ? 'bg-[#0078d4]/20' : 'hover:bg-white/5'
                  }`}
                  onClick={() => {
                    setSelectedVersion(null);
                    setPreviewContent(null);
                  }}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-sm font-medium text-white">Current</span>
                    <span className="text-[10px] px-1.5 py-0.5 text-emerald-400 bg-emerald-500/20">
                      Active
                    </span>
                  </div>
                  <p className="text-xs text-white/50">Working version</p>
                </div>

                {/* Past versions */}
                {versions.map((version) => (
                  <div
                    key={version.id}
                    className={`px-4 py-3 cursor-pointer transition-colors ${
                      selectedVersion?.id === version.id ? 'bg-[#0078d4]/20' : 'hover:bg-white/5'
                    }`}
                    onClick={() => handlePreview(version)}
                  >
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-sm text-white/90">
                        {formatDate(version.created_at)}
                      </span>
                      <span className={`text-[10px] px-1.5 py-0.5 ${getVersionColor(version.note || '')}`}>
                        {getVersionLabel(version.note || '')}
                      </span>
                    </div>
                    {version.note && (
                      <p className="text-xs text-white/50 truncate">{version.note}</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Preview Panel */}
          <div className="w-1/2 flex flex-col">
            <div className="px-4 py-2 border-b border-[#3c3c3c]">
              <h3 className="text-xs font-medium text-white/70 uppercase tracking-wide">
                {selectedVersion ? 'Preview' : 'Current Version'}
              </h3>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
              <pre className="text-xs text-white/70 whitespace-pre-wrap font-mono bg-[#1e1e1e] p-3 rounded max-h-[300px] overflow-auto">
                {previewContent 
                  ? JSON.stringify(JSON.parse(previewContent), null, 2).slice(0, 2000) + '...'
                  : JSON.stringify(JSON.parse(currentContent || '{}'), null, 2).slice(0, 2000) + '...'
                }
              </pre>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between px-4 py-3 border-t border-[#3c3c3c]">
          <p className="text-xs text-white/50">
            {versions.length} version{versions.length !== 1 ? 's' : ''} available
          </p>
          <div className="flex items-center gap-2">
            <button
              onClick={onClose}
              className="px-3 py-1.5 text-sm text-white/70 hover:text-white hover:bg-white/10 transition-colors"
            >
              Cancel
            </button>
            {selectedVersion && (
              <button
                onClick={handleRestore}
                disabled={restoring}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-[#0078d4] text-white text-sm hover:bg-[#006cbd] transition-colors disabled:opacity-50"
              >
                {restoring ? (
                  <Loader2 className="w-3 h-3 animate-spin" />
                ) : (
                  <RotateCcw className="w-3 h-3" />
                )}
                Restore This Version
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default VersionHistory;
