/**
 * Blocks Panel
 * 
 * Displays saved reusable blocks that can be inserted into pages
 */

import { useState, useEffect } from 'react';
import { Loader2, Bookmark, Search, Trash2, Plus } from 'lucide-react';
import { authFetch } from '@/lib/api';
import type { DiSyLNode } from '../core';

interface Block {
  id: number;
  name: string;
  slug: string;
  description: string;
  category: string;
  content: DiSyLNode;
  usage_count: number;
  created_at: string;
}

interface BlocksPanelProps {
  onInsertBlock: (node: DiSyLNode) => void;
}

const CATEGORIES = [
  { value: 'all', label: 'All Blocks' },
  { value: 'header', label: 'Header' },
  { value: 'hero', label: 'Hero' },
  { value: 'content', label: 'Content' },
  { value: 'cta', label: 'CTA' },
  { value: 'feature', label: 'Feature' },
  { value: 'testimonial', label: 'Testimonial' },
  { value: 'footer', label: 'Footer' },
  { value: 'custom', label: 'Custom' },
];

export function BlocksPanel({ onInsertBlock }: BlocksPanelProps) {
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('all');

  useEffect(() => {
    fetchBlocks();
  }, []);

  const fetchBlocks = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await authFetch('/api/v1/cms/saved-blocks');
      const data = await response.json();
      if (data.ok || data.success) {
        const rows = data.blocks || data.data || [];
        setBlocks(
          rows
            .map((block: any) => ({
              ...block,
              content: Array.isArray(block.blocks_json) ? block.blocks_json[0] ?? null : block.content,
            }))
            .filter((block: Block) => !!block.content)
        );
      } else {
        setError(data.error || 'Failed to load blocks');
      }
    } catch (err) {
      console.error('Failed to fetch blocks:', err);
      setError('Failed to load blocks');
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async (blockId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    if (!confirm('Delete this block?')) return;

    try {
      const response = await authFetch(`/api/v1/cms/saved-blocks/${blockId}/delete`, {
        method: 'POST',
      });
      const data = await response.json();
      if (data.ok || data.success) {
        setBlocks(blocks.filter(b => b.id !== blockId));
      }
    } catch (err) {
      console.error('Failed to delete block:', err);
    }
  };

  const handleInsert = (block: Block) => {
    onInsertBlock(block.content);
  };

  const filteredBlocks = blocks.filter(block => {
    const matchesSearch = !search || 
      block.name.toLowerCase().includes(search.toLowerCase()) ||
      block.description?.toLowerCase().includes(search.toLowerCase());
    const matchesCategory = category === 'all' || block.category === category;
    return matchesSearch && matchesCategory;
  });

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-6 h-6 animate-spin text-white/30" />
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full">
      {/* Search & Filter */}
      <div className="p-3 border-b border-[#3c3c3c] space-y-2">
        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-white/30" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search blocks..."
            className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white text-xs pl-8 pr-3 py-1.5 rounded focus:outline-none focus:border-[#0078d4]"
          />
        </div>
        <select
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          className="w-full bg-[#1e1e1e] border border-[#3c3c3c] text-white text-xs px-2 py-1.5 rounded focus:outline-none focus:border-[#0078d4]"
        >
          {CATEGORIES.map(cat => (
            <option key={cat.value} value={cat.value}>{cat.label}</option>
          ))}
        </select>
      </div>

      {/* Blocks List */}
      <div className="flex-1 overflow-y-auto p-2">
        {error ? (
          <div className="text-center py-8 text-red-400 text-xs">{error}</div>
        ) : filteredBlocks.length === 0 ? (
          <div className="text-center py-8">
            <Bookmark className="w-8 h-8 text-white/20 mx-auto mb-3" />
            <p className="text-white/40 text-xs">
              {blocks.length === 0 
                ? 'No saved blocks yet' 
                : 'No blocks match your search'}
            </p>
            <p className="text-white/30 text-[10px] mt-1">
              Right-click a section to save it as a block
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {filteredBlocks.map(block => (
              <div
                key={block.id}
                onClick={() => handleInsert(block)}
                className="group bg-[#1e1e1e] border border-[#3c3c3c] rounded p-3 cursor-pointer hover:border-[#0078d4] transition-colors"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="flex-1 min-w-0">
                    <h4 className="text-white text-xs font-medium truncate">
                      {block.name}
                    </h4>
                    {block.description && (
                      <p className="text-white/40 text-[10px] mt-0.5 line-clamp-2">
                        {block.description}
                      </p>
                    )}
                    <div className="flex items-center gap-2 mt-2">
                      <span className="text-[9px] px-1.5 py-0.5 bg-[#0078d4]/20 text-[#0078d4] rounded">
                        {block.category}
                      </span>
                      <span className="text-[9px] text-white/30">
                        Used {block.usage_count}x
                      </span>
                    </div>
                  </div>
                  <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      onClick={(e) => handleDelete(block.id, e)}
                      className="p-1 hover:bg-red-500/20 rounded transition-colors"
                      title="Delete block"
                    >
                      <Trash2 className="w-3 h-3 text-red-400" />
                    </button>
                    <button
                      className="p-1 hover:bg-[#0078d4]/20 rounded transition-colors"
                      title="Insert block"
                    >
                      <Plus className="w-3 h-3 text-[#0078d4]" />
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Footer */}
      <div className="p-2 border-t border-[#3c3c3c]">
        <button
          onClick={fetchBlocks}
          className="w-full text-[10px] text-white/40 hover:text-white/60 transition-colors"
        >
          Refresh blocks
        </button>
      </div>
    </div>
  );
}

export default BlocksPanel;
