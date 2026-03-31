/**
 * Ikabud Page Builder - Templates Panel
 * Pre-built section templates for quick page building
 */

import React, { memo, useState } from 'react';
import { Layout, ChevronRight } from 'lucide-react';
import { DiSyLNode } from '../core/types';
import {
  templateCategories,
  getTemplatesByCategory,
  TemplateCategory,
  SectionTemplate
} from '../core/templates';

interface TemplatesPanelProps {
  onInsertTemplate: (node: DiSyLNode) => void;
}

const TemplatesPanel: React.FC<TemplatesPanelProps> = ({ onInsertTemplate }) => {
  const [selectedCategory, setSelectedCategory] = useState<TemplateCategory | null>(null);

  const handleTemplateClick = (template: SectionTemplate) => {
    const node = template.createNode();
    onInsertTemplate(node);
  };

  // Category list view
  if (!selectedCategory) {
    return (
      <div className="h-full overflow-y-auto">
        <div className="p-3 border-b border-[#3c3c3c]">
          <h3 className="text-xs font-medium text-white/70 uppercase tracking-wide">
            Section Templates
          </h3>
          <p className="text-[10px] text-white/40 mt-1">
            Click to browse pre-built sections
          </p>
        </div>

        <div className="p-2">
          {templateCategories.map(category => {
            const templates = getTemplatesByCategory(category.id);
            return (
              <button
                key={category.id}
                onClick={() => setSelectedCategory(category.id)}
                className="w-full flex items-center justify-between p-3 mb-1 bg-[#1e1e1e] hover:bg-white/5 transition-colors group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 bg-[#0078d4]/20 flex items-center justify-center">
                    <Layout className="w-4 h-4 text-[#0078d4]" />
                  </div>
                  <div className="text-left">
                    <span className="text-xs font-medium text-white/80 block">
                      {category.name}
                    </span>
                    <span className="text-[10px] text-white/40">
                      {templates.length} template{templates.length !== 1 ? 's' : ''}
                    </span>
                  </div>
                </div>
                <ChevronRight className="w-4 h-4 text-white/30 group-hover:text-white/50 transition-colors" />
              </button>
            );
          })}
        </div>
      </div>
    );
  }

  // Templates list view
  const templates = getTemplatesByCategory(selectedCategory);
  const categoryName = templateCategories.find(c => c.id === selectedCategory)?.name || '';

  return (
    <div className="h-full overflow-y-auto">
      {/* Header with back button */}
      <div className="p-3 border-b border-[#3c3c3c] flex items-center gap-2">
        <button
          onClick={() => setSelectedCategory(null)}
          className="p-1 hover:bg-white/10 transition-colors"
        >
          <ChevronRight className="w-4 h-4 text-white/50 rotate-180" />
        </button>
        <div>
          <h3 className="text-xs font-medium text-white/70 uppercase tracking-wide">
            {categoryName}
          </h3>
          <p className="text-[10px] text-white/40">
            Click to insert
          </p>
        </div>
      </div>

      {/* Templates grid */}
      <div className="p-2 grid gap-2">
        {templates.map(template => (
          <button
            key={template.id}
            onClick={() => handleTemplateClick(template)}
            className="w-full p-3 bg-[#1e1e1e] hover:bg-white/5 border border-transparent hover:border-[#0078d4]/50 transition-all text-left group"
          >
            {/* Template preview placeholder */}
            <div className="w-full h-20 bg-[#2d2d2d] mb-3 flex items-center justify-center overflow-hidden">
              <div className="w-full h-full flex flex-col items-center justify-center gap-1 p-2">
                {/* Mini preview based on category */}
                {template.category === 'hero' && (
                  <>
                    <div className="w-16 h-2 bg-white/20 rounded-sm" />
                    <div className="w-24 h-1 bg-white/10 rounded-sm" />
                    <div className="w-8 h-2 bg-[#0078d4]/50 rounded-sm mt-1" />
                  </>
                )}
                {template.category === 'features' && (
                  <div className="flex gap-2">
                    <div className="w-6 h-8 bg-white/10 rounded-sm" />
                    <div className="w-6 h-8 bg-white/10 rounded-sm" />
                    <div className="w-6 h-8 bg-white/10 rounded-sm" />
                  </div>
                )}
                {template.category === 'content' && (
                  <div className="flex gap-2 w-full px-2">
                    <div className="flex-1 h-10 bg-white/10 rounded-sm" />
                    <div className="flex-1 flex flex-col gap-1">
                      <div className="w-full h-2 bg-white/15 rounded-sm" />
                      <div className="w-3/4 h-1 bg-white/10 rounded-sm" />
                    </div>
                  </div>
                )}
                {template.category === 'entity' && (
                  <div className="w-full px-2">
                    <div className="w-20 h-2 bg-white/20 rounded-sm mx-auto mb-2" />
                    <div className="grid grid-cols-3 gap-1 mb-2">
                      <div className="h-7 bg-white/10 rounded-sm" />
                      <div className="h-7 bg-[#22c55e]/20 rounded-sm" />
                      <div className="h-7 bg-white/10 rounded-sm" />
                    </div>
                    <div className="flex justify-center gap-1">
                      <div className="w-5 h-1 bg-[#22c55e]/50 rounded-full" />
                      <div className="w-5 h-1 bg-[#38bdf8]/40 rounded-full" />
                      <div className="w-5 h-1 bg-[#f59e0b]/40 rounded-full" />
                    </div>
                  </div>
                )}
                {template.category === 'cta' && (
                  <>
                    <div className="w-20 h-2 bg-white/20 rounded-sm" />
                    <div className="w-10 h-3 bg-[#0078d4]/50 rounded-sm mt-1" />
                  </>
                )}
                {template.category === 'testimonials' && (
                  <div className="flex gap-1">
                    <div className="w-8 h-10 bg-white/10 rounded-sm p-1">
                      <div className="w-full h-1 bg-white/20 rounded-sm mb-1" />
                      <div className="w-2/3 h-0.5 bg-white/10 rounded-sm" />
                    </div>
                    <div className="w-8 h-10 bg-white/10 rounded-sm p-1">
                      <div className="w-full h-1 bg-white/20 rounded-sm mb-1" />
                      <div className="w-2/3 h-0.5 bg-white/10 rounded-sm" />
                    </div>
                    <div className="w-8 h-10 bg-white/10 rounded-sm p-1">
                      <div className="w-full h-1 bg-white/20 rounded-sm mb-1" />
                      <div className="w-2/3 h-0.5 bg-white/10 rounded-sm" />
                    </div>
                  </div>
                )}
                {template.category === 'pricing' && (
                  <div className="flex gap-1">
                    <div className="w-6 h-12 bg-white/10 rounded-sm" />
                    <div className="w-6 h-12 bg-[#0078d4]/30 rounded-sm" />
                    <div className="w-6 h-12 bg-white/10 rounded-sm" />
                  </div>
                )}
                {template.category === 'contact' && (
                  <>
                    <div className="w-12 h-2 bg-white/20 rounded-sm" />
                    <div className="w-16 h-1 bg-white/10 rounded-sm" />
                    <div className="w-8 h-2 bg-[#0078d4]/50 rounded-sm mt-1" />
                  </>
                )}
                {template.category === 'footer' && (
                  <div className="w-full px-2">
                    <div className="flex justify-center gap-2 mb-1">
                      <div className="w-4 h-1 bg-white/15 rounded-sm" />
                      <div className="w-4 h-1 bg-white/15 rounded-sm" />
                      <div className="w-4 h-1 bg-white/15 rounded-sm" />
                    </div>
                    <div className="w-full h-px bg-white/10" />
                    <div className="w-12 h-1 bg-white/10 rounded-sm mt-1 mx-auto" />
                  </div>
                )}
              </div>
            </div>

            <h4 className="text-xs font-medium text-white/80 group-hover:text-white transition-colors">
              {template.name}
            </h4>
            <p className="text-[10px] text-white/40 mt-0.5">
              {template.description}
            </p>
          </button>
        ))}
      </div>
    </div>
  );
};

export default memo(TemplatesPanel);
