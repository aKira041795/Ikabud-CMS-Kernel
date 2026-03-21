/**
 * Ikabud Page Builder - SEO Panel
 * Meta tags, Open Graph, and search engine optimization settings
 */

import React, { memo, useState, useCallback } from 'react';
import { 
  Search, 
  Globe, 
  Share2, 
  Image as ImageIcon,
  ChevronDown, 
  ChevronRight,
  AlertCircle,
  CheckCircle,
  FileText,
} from 'lucide-react';

// =============================================================================
// Types
// =============================================================================

export interface SEOSettings {
  // Basic SEO
  metaTitle: string;
  metaDescription: string;
  focusKeyword: string;
  canonicalUrl: string;
  
  // Open Graph (Social Sharing)
  ogTitle: string;
  ogDescription: string;
  ogImage: string;
  ogType: 'website' | 'article' | 'product';
  
  // Twitter Card
  twitterCard: 'summary' | 'summary_large_image';
  twitterTitle: string;
  twitterDescription: string;
  twitterImage: string;
  
  // Advanced
  noIndex: boolean;
  noFollow: boolean;
  structuredData: string;
}

export const defaultSEOSettings: SEOSettings = {
  metaTitle: '',
  metaDescription: '',
  focusKeyword: '',
  canonicalUrl: '',
  ogTitle: '',
  ogDescription: '',
  ogImage: '',
  ogType: 'website',
  twitterCard: 'summary_large_image',
  twitterTitle: '',
  twitterDescription: '',
  twitterImage: '',
  noIndex: false,
  noFollow: false,
  structuredData: '',
};

// =============================================================================
// SEO Score Calculator
// =============================================================================

interface SEOCheck {
  id: string;
  label: string;
  status: 'good' | 'warning' | 'error';
  message: string;
}

function calculateSEOScore(settings: SEOSettings, pageTitle: string): { score: number; checks: SEOCheck[] } {
  const checks: SEOCheck[] = [];
  
  // Meta Title
  const title = settings.metaTitle || pageTitle;
  if (!title) {
    checks.push({ id: 'title', label: 'Meta Title', status: 'error', message: 'Missing meta title' });
  } else if (title.length < 30) {
    checks.push({ id: 'title', label: 'Meta Title', status: 'warning', message: 'Title is too short (< 30 chars)' });
  } else if (title.length > 60) {
    checks.push({ id: 'title', label: 'Meta Title', status: 'warning', message: 'Title is too long (> 60 chars)' });
  } else {
    checks.push({ id: 'title', label: 'Meta Title', status: 'good', message: `Good length (${title.length} chars)` });
  }
  
  // Meta Description
  if (!settings.metaDescription) {
    checks.push({ id: 'description', label: 'Meta Description', status: 'error', message: 'Missing meta description' });
  } else if (settings.metaDescription.length < 120) {
    checks.push({ id: 'description', label: 'Meta Description', status: 'warning', message: 'Description is too short (< 120 chars)' });
  } else if (settings.metaDescription.length > 160) {
    checks.push({ id: 'description', label: 'Meta Description', status: 'warning', message: 'Description is too long (> 160 chars)' });
  } else {
    checks.push({ id: 'description', label: 'Meta Description', status: 'good', message: `Good length (${settings.metaDescription.length} chars)` });
  }
  
  // Focus Keyword
  if (!settings.focusKeyword) {
    checks.push({ id: 'keyword', label: 'Focus Keyword', status: 'warning', message: 'No focus keyword set' });
  } else {
    const keywordInTitle = title.toLowerCase().includes(settings.focusKeyword.toLowerCase());
    const keywordInDesc = settings.metaDescription.toLowerCase().includes(settings.focusKeyword.toLowerCase());
    
    if (keywordInTitle && keywordInDesc) {
      checks.push({ id: 'keyword', label: 'Focus Keyword', status: 'good', message: 'Keyword in title and description' });
    } else if (keywordInTitle || keywordInDesc) {
      checks.push({ id: 'keyword', label: 'Focus Keyword', status: 'warning', message: 'Keyword missing from title or description' });
    } else {
      checks.push({ id: 'keyword', label: 'Focus Keyword', status: 'error', message: 'Keyword not found in title or description' });
    }
  }
  
  // Open Graph Image
  if (!settings.ogImage) {
    checks.push({ id: 'ogImage', label: 'Social Image', status: 'warning', message: 'No social sharing image set' });
  } else {
    checks.push({ id: 'ogImage', label: 'Social Image', status: 'good', message: 'Social image configured' });
  }
  
  // Calculate score
  const goodCount = checks.filter(c => c.status === 'good').length;
  const warningCount = checks.filter(c => c.status === 'warning').length;
  const score = Math.round((goodCount * 100 + warningCount * 50) / checks.length);
  
  return { score, checks };
}

// =============================================================================
// Components
// =============================================================================

interface CollapsibleSectionProps {
  title: string;
  icon: React.ReactNode;
  children: React.ReactNode;
  defaultOpen?: boolean;
}

const CollapsibleSection: React.FC<CollapsibleSectionProps> = ({
  title,
  icon,
  children,
  defaultOpen = true,
}) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  return (
    <div className="border-b border-[#3c3c3c]">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex items-center gap-2 px-3 py-2 hover:bg-white/5 transition-colors"
      >
        {isOpen ? (
          <ChevronDown className="w-3 h-3 text-white/40" />
        ) : (
          <ChevronRight className="w-3 h-3 text-white/40" />
        )}
        <span className="text-white/40">{icon}</span>
        <span className="text-xs font-medium text-white/70">{title}</span>
      </button>
      {isOpen && <div className="px-3 pb-3">{children}</div>}
    </div>
  );
};

interface TextInputProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  maxLength?: number;
  showCount?: boolean;
  multiline?: boolean;
  rows?: number;
}

const TextInput: React.FC<TextInputProps> = ({ 
  label, 
  value, 
  onChange, 
  placeholder, 
  maxLength,
  showCount = false,
  multiline = false,
  rows = 3,
}) => (
  <div className="mb-3">
    <div className="flex items-center justify-between mb-1">
      <label className="text-[10px] text-white/50">{label}</label>
      {showCount && maxLength && (
        <span className={`text-[10px] ${value.length > maxLength ? 'text-red-400' : 'text-white/30'}`}>
          {value.length}/{maxLength}
        </span>
      )}
    </div>
    {multiline ? (
      <textarea
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        rows={rows}
        className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/80 placeholder-white/30 focus:outline-none focus:border-[#0078d4] resize-none"
      />
    ) : (
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/80 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
      />
    )}
  </div>
);

interface SelectInputProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
}

const SelectInput: React.FC<SelectInputProps> = ({ label, value, onChange, options }) => (
  <div className="mb-3">
    <label className="block text-[10px] text-white/50 mb-1">{label}</label>
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/80 focus:outline-none focus:border-[#0078d4]"
    >
      {options.map((opt) => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  </div>
);

interface CheckboxInputProps {
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  description?: string;
}

const CheckboxInput: React.FC<CheckboxInputProps> = ({ label, checked, onChange, description }) => (
  <label className="flex items-start gap-2 mb-2 cursor-pointer group">
    <input
      type="checkbox"
      checked={checked}
      onChange={(e) => onChange(e.target.checked)}
      className="mt-0.5 w-3.5 h-3.5 bg-[#1e1e1e] border border-[#3c3c3c] rounded-sm checked:bg-[#0078d4] checked:border-[#0078d4]"
    />
    <div>
      <span className="text-xs text-white/70 group-hover:text-white/90">{label}</span>
      {description && <p className="text-[10px] text-white/40 mt-0.5">{description}</p>}
    </div>
  </label>
);

// Google Preview Component
const GooglePreview: React.FC<{ title: string; description: string; url: string }> = ({ title, description, url }) => (
  <div className="bg-white rounded-lg p-3 mb-3">
    <div className="text-[10px] text-green-700 mb-0.5 truncate">{url || 'https://example.com/page'}</div>
    <div className="text-sm text-blue-800 hover:underline cursor-pointer mb-1 line-clamp-1">
      {title || 'Page Title'}
    </div>
    <div className="text-xs text-gray-600 line-clamp-2">
      {description || 'Add a meta description to see how your page will appear in search results.'}
    </div>
  </div>
);

// Social Preview Component
const SocialPreview: React.FC<{ title: string; description: string; image: string; type: 'facebook' | 'twitter' }> = ({ 
  title, 
  description, 
  image,
  type,
}) => (
  <div className={`rounded-lg overflow-hidden border ${type === 'facebook' ? 'border-gray-300 bg-white' : 'border-[#3c3c3c] bg-[#15202b]'}`}>
    {image ? (
      <div className="aspect-[1.91/1] bg-gray-200 relative">
        <img src={image} alt="Social preview" className="w-full h-full object-cover" />
      </div>
    ) : (
      <div className="aspect-[1.91/1] bg-gray-200 flex items-center justify-center">
        <ImageIcon className="w-8 h-8 text-gray-400" />
      </div>
    )}
    <div className={`p-2 ${type === 'twitter' ? 'text-white' : ''}`}>
      <div className={`text-xs font-medium line-clamp-1 ${type === 'twitter' ? 'text-white' : 'text-gray-900'}`}>
        {title || 'Page Title'}
      </div>
      <div className={`text-[10px] line-clamp-2 mt-0.5 ${type === 'twitter' ? 'text-gray-400' : 'text-gray-600'}`}>
        {description || 'Add a description for social sharing.'}
      </div>
    </div>
  </div>
);

// =============================================================================
// Main Component
// =============================================================================

interface SEOPanelProps {
  settings: SEOSettings;
  onUpdateSettings: (settings: SEOSettings) => void;
  pageTitle: string;
  pageUrl?: string;
}

const SEOPanel: React.FC<SEOPanelProps> = ({
  settings,
  onUpdateSettings,
  pageTitle,
  pageUrl = '',
}) => {
  const [previewTab, setPreviewTab] = useState<'google' | 'facebook' | 'twitter'>('google');
  
  const updateSetting = useCallback(<K extends keyof SEOSettings>(key: K, value: SEOSettings[K]) => {
    onUpdateSettings({ ...settings, [key]: value });
  }, [settings, onUpdateSettings]);
  
  const { score, checks } = calculateSEOScore(settings, pageTitle);
  
  const effectiveTitle = settings.metaTitle || pageTitle;
  const effectiveOgTitle = settings.ogTitle || effectiveTitle;
  const effectiveTwitterTitle = settings.twitterTitle || effectiveOgTitle;
  const effectiveOgDesc = settings.ogDescription || settings.metaDescription;
  const effectiveTwitterDesc = settings.twitterDescription || effectiveOgDesc;
  const effectiveTwitterImage = settings.twitterImage || settings.ogImage;

  return (
    <div className="h-full overflow-y-auto">
      {/* SEO Score */}
      <div className="p-3 border-b border-[#3c3c3c]">
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-medium text-white/70">SEO Score</span>
          <span className={`text-sm font-bold ${
            score >= 80 ? 'text-emerald-400' : score >= 50 ? 'text-amber-400' : 'text-red-400'
          }`}>
            {score}/100
          </span>
        </div>
        <div className="h-2 bg-[#1e1e1e] rounded-full overflow-hidden">
          <div 
            className={`h-full transition-all ${
              score >= 80 ? 'bg-emerald-500' : score >= 50 ? 'bg-amber-500' : 'bg-red-500'
            }`}
            style={{ width: `${score}%` }}
          />
        </div>
        
        {/* Quick Checks */}
        <div className="mt-3 space-y-1">
          {checks.map(check => (
            <div key={check.id} className="flex items-center gap-2 text-[10px]">
              {check.status === 'good' ? (
                <CheckCircle className="w-3 h-3 text-emerald-400" />
              ) : check.status === 'warning' ? (
                <AlertCircle className="w-3 h-3 text-amber-400" />
              ) : (
                <AlertCircle className="w-3 h-3 text-red-400" />
              )}
              <span className="text-white/60">{check.label}:</span>
              <span className={`${
                check.status === 'good' ? 'text-emerald-400' : 
                check.status === 'warning' ? 'text-amber-400' : 'text-red-400'
              }`}>
                {check.message}
              </span>
            </div>
          ))}
        </div>
      </div>
      
      {/* Preview Tabs */}
      <div className="border-b border-[#3c3c3c]">
        <div className="flex">
          {(['google', 'facebook', 'twitter'] as const).map(tab => (
            <button
              key={tab}
              onClick={() => setPreviewTab(tab)}
              className={`flex-1 px-3 py-2 text-[10px] font-medium transition-colors ${
                previewTab === tab 
                  ? 'text-[#0078d4] border-b-2 border-[#0078d4]' 
                  : 'text-white/50 hover:text-white/70'
              }`}
            >
              {tab === 'google' ? 'Google' : tab === 'facebook' ? 'Facebook' : 'Twitter'}
            </button>
          ))}
        </div>
        <div className="p-3">
          {previewTab === 'google' && (
            <GooglePreview 
              title={effectiveTitle}
              description={settings.metaDescription}
              url={pageUrl}
            />
          )}
          {previewTab === 'facebook' && (
            <SocialPreview 
              title={effectiveOgTitle}
              description={effectiveOgDesc}
              image={settings.ogImage}
              type="facebook"
            />
          )}
          {previewTab === 'twitter' && (
            <SocialPreview 
              title={effectiveTwitterTitle}
              description={effectiveTwitterDesc}
              image={effectiveTwitterImage}
              type="twitter"
            />
          )}
        </div>
      </div>
      
      {/* Basic SEO */}
      <CollapsibleSection title="Basic SEO" icon={<Search className="w-3.5 h-3.5" />}>
        <TextInput
          label="Meta Title"
          value={settings.metaTitle}
          onChange={(v) => updateSetting('metaTitle', v)}
          placeholder={pageTitle || 'Enter meta title...'}
          maxLength={60}
          showCount
        />
        <TextInput
          label="Meta Description"
          value={settings.metaDescription}
          onChange={(v) => updateSetting('metaDescription', v)}
          placeholder="Describe your page in 120-160 characters..."
          maxLength={160}
          showCount
          multiline
          rows={3}
        />
        <TextInput
          label="Focus Keyword"
          value={settings.focusKeyword}
          onChange={(v) => updateSetting('focusKeyword', v)}
          placeholder="Main keyword for this page"
        />
        <TextInput
          label="Canonical URL"
          value={settings.canonicalUrl}
          onChange={(v) => updateSetting('canonicalUrl', v)}
          placeholder="https://example.com/page (optional)"
        />
      </CollapsibleSection>
      
      {/* Open Graph */}
      <CollapsibleSection title="Social Sharing (Open Graph)" icon={<Share2 className="w-3.5 h-3.5" />} defaultOpen={false}>
        <TextInput
          label="OG Title"
          value={settings.ogTitle}
          onChange={(v) => updateSetting('ogTitle', v)}
          placeholder={effectiveTitle || 'Social title...'}
        />
        <TextInput
          label="OG Description"
          value={settings.ogDescription}
          onChange={(v) => updateSetting('ogDescription', v)}
          placeholder={settings.metaDescription || 'Social description...'}
          multiline
          rows={2}
        />
        <TextInput
          label="OG Image URL"
          value={settings.ogImage}
          onChange={(v) => updateSetting('ogImage', v)}
          placeholder="https://example.com/image.jpg"
        />
        <SelectInput
          label="OG Type"
          value={settings.ogType}
          onChange={(v) => updateSetting('ogType', v as SEOSettings['ogType'])}
          options={[
            { value: 'website', label: 'Website' },
            { value: 'article', label: 'Article' },
            { value: 'product', label: 'Product' },
          ]}
        />
      </CollapsibleSection>
      
      {/* Twitter Card */}
      <CollapsibleSection title="Twitter Card" icon={<Globe className="w-3.5 h-3.5" />} defaultOpen={false}>
        <SelectInput
          label="Card Type"
          value={settings.twitterCard}
          onChange={(v) => updateSetting('twitterCard', v as SEOSettings['twitterCard'])}
          options={[
            { value: 'summary', label: 'Summary' },
            { value: 'summary_large_image', label: 'Summary with Large Image' },
          ]}
        />
        <TextInput
          label="Twitter Title"
          value={settings.twitterTitle}
          onChange={(v) => updateSetting('twitterTitle', v)}
          placeholder={effectiveOgTitle || 'Twitter title...'}
        />
        <TextInput
          label="Twitter Description"
          value={settings.twitterDescription}
          onChange={(v) => updateSetting('twitterDescription', v)}
          placeholder={effectiveOgDesc || 'Twitter description...'}
          multiline
          rows={2}
        />
        <TextInput
          label="Twitter Image URL"
          value={settings.twitterImage}
          onChange={(v) => updateSetting('twitterImage', v)}
          placeholder={settings.ogImage || 'https://example.com/image.jpg'}
        />
      </CollapsibleSection>
      
      {/* Advanced */}
      <CollapsibleSection title="Advanced" icon={<FileText className="w-3.5 h-3.5" />} defaultOpen={false}>
        <CheckboxInput
          label="No Index"
          checked={settings.noIndex}
          onChange={(v) => updateSetting('noIndex', v)}
          description="Prevent search engines from indexing this page"
        />
        <CheckboxInput
          label="No Follow"
          checked={settings.noFollow}
          onChange={(v) => updateSetting('noFollow', v)}
          description="Prevent search engines from following links on this page"
        />
        <TextInput
          label="Structured Data (JSON-LD)"
          value={settings.structuredData}
          onChange={(v) => updateSetting('structuredData', v)}
          placeholder='{"@context": "https://schema.org", ...}'
          multiline
          rows={5}
        />
      </CollapsibleSection>
    </div>
  );
};

export default memo(SEOPanel);
