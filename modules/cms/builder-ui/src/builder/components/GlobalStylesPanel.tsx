/**
 * Ikabud Page Builder - Global Styles Panel
 * Theme settings and global style configuration
 */

import React, { memo, useState, useCallback } from 'react';
import { 
  Palette, 
  Type, 
  Box, 
  ChevronDown, 
  ChevronRight,
  RotateCcw,
  Sparkles
} from 'lucide-react';

// =============================================================================
// Types
// =============================================================================

export interface GlobalStyles {
  colors: {
    primary: string;
    secondary: string;
    accent: string;
    text: string;
    textLight: string;
    background: string;
    backgroundAlt: string;
  };
  typography: {
    fontFamily: string;
    headingFontFamily: string;
    baseFontSize: string;
    lineHeight: string;
    h1Size: string;
    h2Size: string;
    h3Size: string;
    h4Size: string;
  };
  spacing: {
    sectionPadding: string;
    containerMaxWidth: string;
    elementGap: string;
  };
  buttons: {
    borderRadius: string;
    paddingX: string;
    paddingY: string;
    fontSize: string;
  };
  page: {
    hidePageTitle: boolean;
  };
}

export const defaultGlobalStyles: GlobalStyles = {
  colors: {
    primary: '#3b82f6',
    secondary: '#64748b',
    accent: '#f59e0b',
    text: '#0f172a',
    textLight: '#64748b',
    background: '#ffffff',
    backgroundAlt: '#f8fafc',
  },
  typography: {
    fontFamily: 'Inter, system-ui, sans-serif',
    headingFontFamily: 'Inter, system-ui, sans-serif',
    baseFontSize: '16px',
    lineHeight: '1.6',
    h1Size: '48px',
    h2Size: '36px',
    h3Size: '24px',
    h4Size: '20px',
  },
  spacing: {
    sectionPadding: '80px',
    containerMaxWidth: '1200px',
    elementGap: '24px',
  },
  buttons: {
    borderRadius: '4px',
    paddingX: '24px',
    paddingY: '12px',
    fontSize: '14px',
  },
  page: {
    hidePageTitle: false,
  },
};

// Typography Presets for quick styling
export const typographyPresets: Record<string, Partial<GlobalStyles>> = {
  modern: {
    typography: {
      fontFamily: 'Inter, system-ui, sans-serif',
      headingFontFamily: 'Inter, system-ui, sans-serif',
      baseFontSize: '16px',
      lineHeight: '1.6',
      h1Size: '48px',
      h2Size: '36px',
      h3Size: '24px',
      h4Size: '20px',
    },
    colors: {
      primary: '#3b82f6',
      secondary: '#64748b',
      accent: '#f59e0b',
      text: '#0f172a',
      textLight: '#64748b',
      background: '#ffffff',
      backgroundAlt: '#f8fafc',
    },
  },
  elegant: {
    typography: {
      fontFamily: 'Georgia, serif',
      headingFontFamily: '"Playfair Display", serif',
      baseFontSize: '18px',
      lineHeight: '1.8',
      h1Size: '56px',
      h2Size: '42px',
      h3Size: '28px',
      h4Size: '22px',
    },
    colors: {
      primary: '#1e3a5f',
      secondary: '#8b7355',
      accent: '#c9a959',
      text: '#2c2c2c',
      textLight: '#6b6b6b',
      background: '#faf9f7',
      backgroundAlt: '#f0ede8',
    },
  },
  bold: {
    typography: {
      fontFamily: '"Montserrat", sans-serif',
      headingFontFamily: '"Montserrat", sans-serif',
      baseFontSize: '16px',
      lineHeight: '1.5',
      h1Size: '64px',
      h2Size: '48px',
      h3Size: '32px',
      h4Size: '24px',
    },
    colors: {
      primary: '#000000',
      secondary: '#333333',
      accent: '#ff4444',
      text: '#000000',
      textLight: '#555555',
      background: '#ffffff',
      backgroundAlt: '#f5f5f5',
    },
  },
  minimal: {
    typography: {
      fontFamily: 'system-ui, sans-serif',
      headingFontFamily: 'system-ui, sans-serif',
      baseFontSize: '15px',
      lineHeight: '1.7',
      h1Size: '40px',
      h2Size: '32px',
      h3Size: '24px',
      h4Size: '18px',
    },
    colors: {
      primary: '#333333',
      secondary: '#666666',
      accent: '#0066cc',
      text: '#333333',
      textLight: '#888888',
      background: '#ffffff',
      backgroundAlt: '#fafafa',
    },
  },
  playful: {
    typography: {
      fontFamily: '"Poppins", sans-serif',
      headingFontFamily: '"Poppins", sans-serif',
      baseFontSize: '16px',
      lineHeight: '1.6',
      h1Size: '52px',
      h2Size: '40px',
      h3Size: '28px',
      h4Size: '20px',
    },
    colors: {
      primary: '#6366f1',
      secondary: '#ec4899',
      accent: '#14b8a6',
      text: '#1e1b4b',
      textLight: '#6b7280',
      background: '#ffffff',
      backgroundAlt: '#f5f3ff',
    },
  },
};

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

interface ColorInputProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
}

const ColorInput: React.FC<ColorInputProps> = ({ label, value, onChange }) => (
  <div className="flex items-center justify-between mb-2">
    <label className="text-[10px] text-white/50">{label}</label>
    <div className="flex items-center gap-2">
      <input
        type="color"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-6 h-6 cursor-pointer bg-transparent border border-[#3c3c3c]"
      />
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-20 px-2 py-1 text-[10px] bg-[#1e1e1e] border border-[#3c3c3c] text-white/80 focus:outline-none focus:border-[#0078d4]"
      />
    </div>
  </div>
);

interface TextInputProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

const TextInput: React.FC<TextInputProps> = ({ label, value, onChange, placeholder }) => (
  <div className="mb-2">
    <label className="block text-[10px] text-white/50 mb-1">{label}</label>
    <input
      type="text"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="w-full px-2 py-1.5 text-xs bg-[#1e1e1e] border border-[#3c3c3c] text-white/80 placeholder-white/30 focus:outline-none focus:border-[#0078d4]"
    />
  </div>
);

interface SelectInputProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
}

const SelectInput: React.FC<SelectInputProps> = ({ label, value, onChange, options }) => (
  <div className="mb-2">
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

// =============================================================================
// Main Component
// =============================================================================

interface GlobalStylesPanelProps {
  styles: GlobalStyles;
  onUpdateStyles: (styles: GlobalStyles) => void;
}

const GlobalStylesPanel: React.FC<GlobalStylesPanelProps> = ({
  styles,
  onUpdateStyles,
}) => {
  const updateColor = useCallback((key: keyof GlobalStyles['colors'], value: string) => {
    onUpdateStyles({
      ...styles,
      colors: { ...styles.colors, [key]: value },
    });
  }, [styles, onUpdateStyles]);

  const updateTypography = useCallback((key: keyof GlobalStyles['typography'], value: string) => {
    onUpdateStyles({
      ...styles,
      typography: { ...styles.typography, [key]: value },
    });
  }, [styles, onUpdateStyles]);

  const updateSpacing = useCallback((key: keyof GlobalStyles['spacing'], value: string) => {
    onUpdateStyles({
      ...styles,
      spacing: { ...styles.spacing, [key]: value },
    });
  }, [styles, onUpdateStyles]);

  const updateButtons = useCallback((key: keyof GlobalStyles['buttons'], value: string) => {
    onUpdateStyles({
      ...styles,
      buttons: { ...styles.buttons, [key]: value },
    });
  }, [styles, onUpdateStyles]);

  const resetToDefaults = useCallback(() => {
    onUpdateStyles(defaultGlobalStyles);
  }, [onUpdateStyles]);

  const fontOptions = [
    { value: 'Inter, system-ui, sans-serif', label: 'Inter' },
    { value: 'system-ui, sans-serif', label: 'System UI' },
    { value: 'Georgia, serif', label: 'Georgia' },
    { value: '"Playfair Display", serif', label: 'Playfair Display' },
    { value: '"Roboto", sans-serif', label: 'Roboto' },
    { value: '"Open Sans", sans-serif', label: 'Open Sans' },
    { value: '"Lato", sans-serif', label: 'Lato' },
    { value: '"Montserrat", sans-serif', label: 'Montserrat' },
    { value: '"Poppins", sans-serif', label: 'Poppins' },
  ];

  return (
    <div className="h-full flex flex-col bg-[#252526]">
      {/* Header */}
      <div className="flex items-center justify-between px-3 py-2 border-b border-[#3c3c3c]">
        <h3 className="text-xs font-medium text-white/90">Global Styles</h3>
        <button
          onClick={resetToDefaults}
          className="p-1 hover:bg-white/10 transition-colors"
          title="Reset to defaults"
        >
          <RotateCcw className="w-3.5 h-3.5 text-white/50" />
        </button>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-y-auto">
        {/* Style Presets */}
        <CollapsibleSection title="Style Presets" icon={<Sparkles className="w-3 h-3" />}>
          <p className="text-[9px] text-white/40 mb-2">Quick-apply a complete style theme</p>
          <div className="grid grid-cols-2 gap-1.5">
            {Object.entries(typographyPresets).map(([key, preset]) => (
              <button
                key={key}
                onClick={() => {
                  onUpdateStyles({
                    ...styles,
                    ...preset,
                    typography: { ...styles.typography, ...preset.typography },
                    colors: { ...styles.colors, ...preset.colors },
                  } as GlobalStyles);
                }}
                className="flex flex-col items-center p-2 border border-[#3c3c3c] hover:border-[#0078d4] transition-colors group"
              >
                <div 
                  className="w-full h-8 mb-1.5 flex items-center justify-center text-[10px] font-medium"
                  style={{ 
                    backgroundColor: preset.colors?.backgroundAlt,
                    color: preset.colors?.text,
                    fontFamily: preset.typography?.fontFamily,
                  }}
                >
                  Aa
                </div>
                <span className="text-[9px] text-white/60 capitalize group-hover:text-white/90">{key}</span>
              </button>
            ))}
          </div>
        </CollapsibleSection>
        
        {/* Colors */}
        <CollapsibleSection title="Colors" icon={<Palette className="w-3 h-3" />}>
          <ColorInput label="Primary" value={styles.colors.primary} onChange={(v) => updateColor('primary', v)} />
          <ColorInput label="Secondary" value={styles.colors.secondary} onChange={(v) => updateColor('secondary', v)} />
          <ColorInput label="Accent" value={styles.colors.accent} onChange={(v) => updateColor('accent', v)} />
          <ColorInput label="Text" value={styles.colors.text} onChange={(v) => updateColor('text', v)} />
          <ColorInput label="Text Light" value={styles.colors.textLight} onChange={(v) => updateColor('textLight', v)} />
          <ColorInput label="Background" value={styles.colors.background} onChange={(v) => updateColor('background', v)} />
          <ColorInput label="Background Alt" value={styles.colors.backgroundAlt} onChange={(v) => updateColor('backgroundAlt', v)} />
        </CollapsibleSection>

        {/* Typography */}
        <CollapsibleSection title="Typography" icon={<Type className="w-3 h-3" />}>
          <SelectInput
            label="Body Font"
            value={styles.typography.fontFamily}
            onChange={(v) => updateTypography('fontFamily', v)}
            options={fontOptions}
          />
          <SelectInput
            label="Heading Font"
            value={styles.typography.headingFontFamily}
            onChange={(v) => updateTypography('headingFontFamily', v)}
            options={fontOptions}
          />
          <TextInput
            label="Base Font Size"
            value={styles.typography.baseFontSize}
            onChange={(v) => updateTypography('baseFontSize', v)}
            placeholder="16px"
          />
          <TextInput
            label="Line Height"
            value={styles.typography.lineHeight}
            onChange={(v) => updateTypography('lineHeight', v)}
            placeholder="1.6"
          />
          <div className="mt-3 pt-2 border-t border-[#3c3c3c]">
            <p className="text-[9px] text-white/30 uppercase tracking-wide mb-2">Heading Sizes</p>
            <TextInput label="H1" value={styles.typography.h1Size} onChange={(v) => updateTypography('h1Size', v)} />
            <TextInput label="H2" value={styles.typography.h2Size} onChange={(v) => updateTypography('h2Size', v)} />
            <TextInput label="H3" value={styles.typography.h3Size} onChange={(v) => updateTypography('h3Size', v)} />
            <TextInput label="H4" value={styles.typography.h4Size} onChange={(v) => updateTypography('h4Size', v)} />
          </div>
        </CollapsibleSection>

        {/* Spacing */}
        <CollapsibleSection title="Layout & Spacing" icon={<Box className="w-3 h-3" />}>
          <TextInput
            label="Container Max Width"
            value={styles.spacing.containerMaxWidth}
            onChange={(v) => updateSpacing('containerMaxWidth', v)}
            placeholder="1200px"
          />
          <TextInput
            label="Section Padding"
            value={styles.spacing.sectionPadding}
            onChange={(v) => updateSpacing('sectionPadding', v)}
            placeholder="80px"
          />
          <TextInput
            label="Element Gap"
            value={styles.spacing.elementGap}
            onChange={(v) => updateSpacing('elementGap', v)}
            placeholder="24px"
          />
        </CollapsibleSection>

        {/* Buttons */}
        <CollapsibleSection title="Buttons" icon={<Box className="w-3 h-3" />} defaultOpen={false}>
          <TextInput
            label="Border Radius"
            value={styles.buttons.borderRadius}
            onChange={(v) => updateButtons('borderRadius', v)}
            placeholder="4px"
          />
          <TextInput
            label="Padding X"
            value={styles.buttons.paddingX}
            onChange={(v) => updateButtons('paddingX', v)}
            placeholder="24px"
          />
          <TextInput
            label="Padding Y"
            value={styles.buttons.paddingY}
            onChange={(v) => updateButtons('paddingY', v)}
            placeholder="12px"
          />
          <TextInput
            label="Font Size"
            value={styles.buttons.fontSize}
            onChange={(v) => updateButtons('fontSize', v)}
            placeholder="14px"
          />
        </CollapsibleSection>

        {/* Page Settings */}
        <CollapsibleSection title="Page Settings" icon={<Box className="w-3 h-3" />} defaultOpen={false}>
          <div className="flex items-center justify-between mb-3">
            <label className="text-xs text-white/70">Hide Page Title</label>
            <button
              onClick={() => onUpdateStyles({ ...styles, page: { ...styles.page, hidePageTitle: !styles.page.hidePageTitle } })}
              className={`relative w-10 h-5 rounded-full transition-colors ${
                styles.page.hidePageTitle ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'
              }`}
            >
              <span
                className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${
                  styles.page.hidePageTitle ? 'translate-x-5' : 'translate-x-0'
                }`}
              />
            </button>
          </div>
          <p className="text-[10px] text-white/40 leading-relaxed">
            Hide the page title to allow slideshow or hero sections to sit directly below the header.
          </p>
        </CollapsibleSection>

        {/* CSS Variables Preview */}
        <CollapsibleSection title="CSS Variables" icon={<Type className="w-3 h-3" />} defaultOpen={false}>
          <div className="bg-[#1e1e1e] p-2 text-[9px] font-mono text-white/50 overflow-x-auto">
            <pre className="whitespace-pre-wrap">
{`:root {
  --color-primary: ${styles.colors.primary};
  --color-secondary: ${styles.colors.secondary};
  --color-accent: ${styles.colors.accent};
  --color-text: ${styles.colors.text};
  --color-text-light: ${styles.colors.textLight};
  --color-bg: ${styles.colors.background};
  --color-bg-alt: ${styles.colors.backgroundAlt};
  --font-body: ${styles.typography.fontFamily};
  --font-heading: ${styles.typography.headingFontFamily};
  --font-size-base: ${styles.typography.baseFontSize};
  --line-height: ${styles.typography.lineHeight};
}`}
            </pre>
          </div>
        </CollapsibleSection>
      </div>
    </div>
  );
};

export default memo(GlobalStylesPanel);
