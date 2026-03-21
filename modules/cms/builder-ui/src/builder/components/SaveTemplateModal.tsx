/**
 * Save as Template Modal
 * 
 * Allows users to save the current Page Builder content as a reusable template
 */

import { useState } from 'react';
import { X, Save, Loader2, FileText, Tag, FolderOpen } from 'lucide-react';
import { authFetch } from '@/lib/api';
import type { DiSyLNode } from '../core';
import type { GlobalStyles } from './GlobalStylesPanel';

interface SaveTemplateModalProps {
  isOpen: boolean;
  onClose: () => void;
  content: DiSyLNode;
  globalStyles?: GlobalStyles;
  onSuccess?: (template: Template) => void;
}

interface Template {
  id: number;
  name: string;
  slug: string;
  description: string;
  category: string;
  content: DiSyLNode;
  global_styles?: GlobalStyles;
}

const CATEGORIES = [
  { value: 'landing', label: 'Landing Page' },
  { value: 'content', label: 'Content Page' },
  { value: 'blog', label: 'Blog / Article' },
  { value: 'portfolio', label: 'Portfolio' },
  { value: 'ecommerce', label: 'E-Commerce' },
  { value: 'custom', label: 'Custom' },
];

export function SaveTemplateModal({
  isOpen,
  onClose,
  content,
  globalStyles,
  onSuccess,
}: SaveTemplateModalProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [category, setCategory] = useState('custom');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async () => {
    if (!name.trim()) {
      setError('Template name is required');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const response = await authFetch('/api/v1/cms/builder/templates', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim(),
          category,
          content,
          global_styles: globalStyles,
        }),
      });

      const data = await response.json();
      if (data.ok || data.success) {
        onSuccess?.(data.data);
        handleClose();
      } else {
        setError(data.error || 'Failed to save template');
      }
    } catch (err) {
      console.error('Save template error:', err);
      setError('Failed to save template. Please try again.');
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
            <FileText className="w-5 h-5 text-[#0078d4]" />
            <h2 className="text-white font-medium">Save as Template</h2>
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

          {/* Template Name */}
          <div>
            <label className="block text-sm text-white/70 mb-1.5">
              <Tag className="w-3.5 h-3.5 inline mr-1.5" />
              Template Name *
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g., Hero Landing Page"
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors"
              autoFocus
            />
          </div>

          {/* Category */}
          <div>
            <label className="block text-sm text-white/70 mb-1.5">
              <FolderOpen className="w-3.5 h-3.5 inline mr-1.5" />
              Category
            </label>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors"
            >
              {CATEGORIES.map((cat) => (
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
              placeholder="Brief description of this template..."
              rows={3}
              className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white px-3 py-2 rounded text-sm focus:outline-none focus:border-[#0078d4] transition-colors resize-none"
            />
          </div>

          {/* Info */}
          <div className="bg-[#1e1e1e] border border-[#3c3c3c] rounded p-3">
            <p className="text-xs text-white/50">
              This will save the current page content as a reusable template. 
              You can use it to quickly create new pages with the same layout.
            </p>
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
            Save Template
          </button>
        </div>
      </div>
    </div>
  );
}

export default SaveTemplateModal;
