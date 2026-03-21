/**
 * Save as Block Modal
 * 
 * Allows users to save a selected section/element as a reusable block
 */

import { useState } from 'react';
import { X, Save, Loader2, Bookmark, Tag } from 'lucide-react';
import { authFetch } from '@/lib/api';
import type { DiSyLNode } from '../core';

interface SaveBlockModalProps {
  isOpen: boolean;
  onClose: () => void;
  node: DiSyLNode;
  onSuccess?: (block: SavedBlock) => void;
}

export interface SavedBlock {
  id: number;
  name: string;
  description: string;
  category: string;
  content: DiSyLNode;
  created_at: string;
}

const BLOCK_CATEGORIES = [
  { value: 'header', label: 'Header' },
  { value: 'hero', label: 'Hero Section' },
  { value: 'content', label: 'Content Block' },
  { value: 'cta', label: 'Call to Action' },
  { value: 'feature', label: 'Feature Section' },
  { value: 'testimonial', label: 'Testimonial' },
  { value: 'footer', label: 'Footer' },
  { value: 'custom', label: 'Custom' },
];

export function SaveBlockModal({
  isOpen,
  onClose,
  node,
  onSuccess,
}: SaveBlockModalProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [category, setCategory] = useState('custom');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async () => {
    if (!name.trim()) {
      setError('Block name is required');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const response = await authFetch('/api/v1/cms/saved-blocks', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim(),
          category,
          blocks: [node],
        }),
      });

      const data = await response.json();

      if (data.ok || data.success) {
        onSuccess?.({
          id: data.id ?? data.data?.id ?? 0,
          name: name.trim(),
          description: description.trim(),
          category,
          content: node,
          created_at: new Date().toISOString(),
        });
        handleClose();
      } else {
        setError(data.error || 'Failed to save block');
      }
    } catch (err) {
      console.error('Save block error:', err);
      setError('Failed to save block. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const handleClose = () => {
    setName('');
    setDescription('');
    setCategory('custom');
    setError(null);
    onClose();
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={handleClose}
      />

      {/* Modal */}
      <div className="relative bg-[#252526] border border-[#3c3c3c] rounded-lg shadow-2xl w-full max-w-md mx-4">
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <div className="flex items-center gap-2">
            <Bookmark className="w-5 h-5 text-[#0078d4]" />
            <h2 className="text-white font-medium">Save as Reusable Block</h2>
          </div>
          <button
            onClick={handleClose}
            className="p-1 hover:bg-white/10 rounded transition-colors"
          >
            <X className="w-4 h-4 text-white/70" />
          </button>
        </div>

        {/* Content */}
        <div className="p-4 space-y-4">
          {/* Error Message */}
          {error && (
            <div className="bg-red-500/10 border border-red-500/30 text-red-400 px-3 py-2 rounded text-sm">
              {error}
            </div>
          )}

          {/* Preview */}
          <div className="bg-[#1e1e1e] border border-[#3c3c3c] rounded p-3">
            <div className="flex items-center gap-2 text-xs text-white/50 mb-2">
              <span className="px-2 py-0.5 bg-[#0078d4]/20 text-[#0078d4] rounded">
                {node.type}
              </span>
              <span>•</span>
              <span>{node.children?.length || 0} children</span>
            </div>
            <p className="text-xs text-white/40">
              This block will be saved and can be reused across all your pages.
            </p>
          </div>

          {/* Block Name */}
          <div>
            <label className="block text-sm text-white/70 mb-1.5">
              <Tag className="w-3.5 h-3.5 inline mr-1.5" />
              Block Name *
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g., Hero with CTA"
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors"
              autoFocus
            />
          </div>

          {/* Category */}
          <div>
            <label className="block text-sm text-white/70 mb-1.5">
              Category
            </label>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors"
            >
              {BLOCK_CATEGORIES.map((cat) => (
                <option key={cat.value} value={cat.value}>
                  {cat.label}
                </option>
              ))}
            </select>
          </div>

          {/* Description */}
          <div>
            <label className="block text-sm text-white/70 mb-1.5">
              Description (optional)
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Brief description of this block..."
              rows={2}
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors resize-none"
            />
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-2 px-4 py-3 border-t border-[#3c3c3c]">
          <button
            onClick={handleClose}
            className="px-4 py-2 text-sm text-white/70 hover:text-white hover:bg-white/10 rounded transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={saving || !name.trim()}
            className="flex items-center gap-2 px-4 py-2 bg-[#0078d4] text-white text-sm font-medium rounded hover:bg-[#006cbd] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {saving ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            Save Block
          </button>
        </div>
      </div>
    </div>
  );
}

export default SaveBlockModal;
