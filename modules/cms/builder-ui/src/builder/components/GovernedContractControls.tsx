/**
 * GovernedContractControls — remaining Phase 7 / 5.2 builder controls
 *
 * Adds to PropertiesPanel for governed DiSyL components:
 *   - Permission role preview (admin / staff / guest)
 *   - Empty/error state preview toggle
 *   - Export button format config (CSV / DOCX / PDF)
 *   - AI block mode config (draft_only / suggest / auto)
 */

import React, { memo, useState } from 'react';
import { Eye, EyeOff, Shield, FileDown, Sparkles, ChevronDown } from 'lucide-react';

// =============================================================================
// Types
// =============================================================================

interface GovernedContractControlsProps {
  nodeType: string;
  props: Record<string, unknown>;
  onChange: (key: string, value: unknown) => void;
}

// =============================================================================
// Sub-components
// =============================================================================

const CollapsibleSection: React.FC<{
  title: string;
  icon: React.ReactNode;
  defaultOpen?: boolean;
  children: React.ReactNode;
}> = memo(({ title, icon, defaultOpen = false, children }) => {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <div className="border-b border-[#3c3c3c] last:border-b-0">
      <button
        onClick={() => setOpen(!open)}
        className="w-full flex items-center gap-2 px-3 py-2.5 text-xs font-medium text-white/60 hover:text-white/80 transition-colors"
      >
        <span className="text-white/40">{icon}</span>
        <span className="flex-1 text-left">{title}</span>
        <ChevronDown className={`w-3 h-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && <div className="px-3 pb-3 space-y-2">{children}</div>}
    </div>
  );
});

CollapsibleSection.displayName = 'CollapsibleSection';

// =============================================================================
// Toggle switch
// =============================================================================

const Toggle: React.FC<{
  label: string;
  value: boolean;
  onChange: (v: boolean) => void;
  hint?: string;
}> = memo(({ label, value, onChange, hint }) => (
  <div>
    <div className="flex items-center justify-between">
      <label className="text-xs text-white/70">{label}</label>
      <button
        onClick={() => onChange(!value)}
        className={`relative w-9 h-5 rounded-full transition-colors ${value ? 'bg-[#0078d4]' : 'bg-[#3c3c3c]'}`}
      >
        <span className={`absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform ${value ? 'translate-x-4' : 'translate-x-0'}`} />
      </button>
    </div>
    {hint && <p className="text-[10px] text-white/30 mt-0.5">{hint}</p>}
  </div>
));

Toggle.displayName = 'Toggle';

// =============================================================================
// Select dropdown
// =============================================================================

const SelectRow: React.FC<{
  label: string;
  value: string;
  options: { value: string; label: string; hint?: string }[];
  onChange: (v: string) => void;
}> = memo(({ label, value, options, onChange }) => (
  <div>
    <label className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1 block">{label}</label>
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="w-full bg-[#2d2d2d] border border-[#3c3c3c] rounded-md text-white text-xs px-2 py-1.5 focus:outline-none focus:border-[#6366f1]"
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
    {options.find(o => o.value === value)?.hint && (
      <p className="text-[10px] text-white/30 mt-0.5">{options.find(o => o.value === value)!.hint}</p>
    )}
  </div>
));

SelectRow.displayName = 'SelectRow';

// =============================================================================
// Main component
// =============================================================================

const GovernedContractControls: React.FC<GovernedContractControlsProps> = memo(({
  nodeType,
  props,
  onChange,
}) => {
  const isEntityList = nodeType === 'entity_list' || nodeType === 'entity_view';
  const governedName = String(props._governedName ?? '');
  const isExport = nodeType === 'export_button' || governedName === 'ikb_export_button';
  const isAI = nodeType === 'ai_block' || governedName.startsWith('ikb_ai_');
  const isGoverned = Boolean(props._governed);

  if (!isGoverned) return null;

  return (
    <div className="border-t border-[#3c3c3c] mt-2">
      <div className="px-3 py-2 flex items-center gap-2">
        <Shield className="w-3.5 h-3.5 text-[#6366f1]" />
        <span className="text-[10px] font-semibold text-[#6366f1] uppercase tracking-wide">Governed Contract</span>
      </div>

      {/* ── Permission Preview ── */}
      {(isEntityList || isExport) && (
        <CollapsibleSection title="Permission Preview" icon={<Eye className="w-3 h-3" />}>
          <SelectRow
            label="Preview as role"
            value={String(props._previewRole || 'administrator')}
            onChange={(v) => onChange('_previewRole', v)}
            options={[
              { value: 'administrator', label: 'Administrator — full access', hint: 'Can view, edit, export all fields' },
              { value: 'editor', label: 'Editor — content access', hint: 'Can view and edit, limited export' },
              { value: 'author', label: 'Author — own content', hint: 'Can view own items, limited fields' },
              { value: 'subscriber', label: 'Subscriber — read only', hint: 'Can view published items only' },
              { value: 'guest', label: 'Guest — public', hint: 'Can view published items, no actions' },
            ]}
          />
          <div className="p-2 bg-[#1a1a2e] rounded border border-white/5 text-[10px] text-white/40">
            <Eye className="w-3 h-3 inline mr-1" />
            Preview shows what this role would see at render time. Actions, fields, and exports are filtered by capability gates.
          </div>
        </CollapsibleSection>
      )}

      {/* ── Empty / Error State Preview ── */}
      {isEntityList && (
        <CollapsibleSection title="State Preview" icon={<EyeOff className="w-3 h-3" />}>
          <Toggle
            label="Show empty state"
            value={props._previewEmpty === true}
            onChange={(v) => onChange('_previewEmpty', v)}
            hint="Preview the 'No records found' message"
          />
          <Toggle
            label="Show error state"
            value={props._previewError === true}
            onChange={(v) => onChange('_previewError', v)}
            hint="Preview the 'Unable to load data' message"
          />
          <Toggle
            label="Show loading state"
            value={props._previewLoading === true}
            onChange={(v) => onChange('_previewLoading', v)}
            hint="Preview spinner/skeleton state"
          />
        </CollapsibleSection>
      )}

      {/* ── Export Button Config ── */}
      {isExport && (
        <CollapsibleSection title="Export Config" icon={<FileDown className="w-3 h-3" />} defaultOpen>
          <SelectRow
            label="Default format"
            value={String(props.format || 'csv')}
            onChange={(v) => onChange('format', v)}
            options={[
              { value: 'csv', label: 'CSV — spreadsheet compatible', hint: 'Always available, no dependencies' },
              { value: 'docx', label: 'DOCX — Word document', hint: 'Requires PHPWord (included in 5.0)' },
              { value: 'pdf', label: 'PDF — portable document', hint: 'Planned for 5.3' },
              { value: 'xlsx', label: 'XLSX — Excel workbook', hint: 'Planned for 5.3' },
            ]}
          />
          <SelectRow
            label="Button variant"
            value={String(props.variant || 'primary')}
            onChange={(v) => onChange('variant', v)}
            options={[
              { value: 'primary', label: 'Primary — prominent' },
              { value: 'secondary', label: 'Secondary — subtle' },
              { value: 'outline', label: 'Outline — bordered' },
            ]}
          />
          <SelectRow
            label="Button size"
            value={String(props.size || 'md')}
            onChange={(v) => onChange('size', v)}
            options={[
              { value: 'sm', label: 'Small' },
              { value: 'md', label: 'Medium' },
              { value: 'lg', label: 'Large' },
            ]}
          />
        </CollapsibleSection>
      )}

      {/* ── AI Block Config ── */}
      {isAI && (
        <CollapsibleSection title="AI Config" icon={<Sparkles className="w-3 h-3" />} defaultOpen>
          <SelectRow
            label="AI Mode"
            value={String(props.aiMode || 'draft_only')}
            onChange={(v) => onChange('aiMode', v)}
            options={[
              { value: 'draft_only', label: 'Draft Only — requires human review', hint: 'Safest mode. Output must be approved before publish.' },
              { value: 'suggest', label: 'Suggest — inline suggestions', hint: 'AI proposes changes, user accepts/rejects.' },
              { value: 'auto_publish', label: 'Auto Publish — with audit trail', hint: 'AI publishes directly. All output logged for review.' },
            ]}
          />
          <Toggle
            label="Show review badge"
            value={props.showReviewBadge !== false}
            onChange={(v) => onChange('showReviewBadge', v)}
            hint="Displays 'AI-generated · Needs review' badge"
          />
          <Toggle
            label="Enable redaction"
            value={props.enableRedaction === true}
            onChange={(v) => onChange('enableRedaction', v)}
            hint="Redacts PII/sensitive data before sending to AI provider"
          />
          <div className="p-2 bg-[#1a1a2e] rounded border border-white/5 text-[10px] text-white/40">
            <Sparkles className="w-3 h-3 inline mr-1" />
            AI blocks are governed by the kernel AI Policy engine (kill switch, model allowlist, token cap).
          </div>
        </CollapsibleSection>
      )}

      {/* ── Theme Token hint ── */}
      <div className="px-3 py-2 text-[10px] text-white/25 border-t border-[#3c3c3c]">
        Theme tokens (colors, spacing, radius) are controlled via the <span className="text-white/40">Global Styles</span> panel.
        Component-level overrides use the <span className="text-white/40">Style</span> tab.
      </div>
    </div>
  );
});

GovernedContractControls.displayName = 'GovernedContractControls';

export default GovernedContractControls;
