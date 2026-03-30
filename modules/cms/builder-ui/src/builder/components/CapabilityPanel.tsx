/**
 * Ikabud Page Builder - Capability Panel
 *
 * Allows editors to attach and configure entity capability feature profiles
 * (pricing, inventory, booking, inquiry, progress_tracking, lessons_index,
 * media_gallery) and to apply configuration presets (ecommerce, education,
 * business, portfolio, and commerce-aware service/course presets) to the
 * current content entity.
 */

import React, { memo, useState, useEffect, useCallback } from 'react';
import {
  Tag,
  Package,
  Calendar,
  Mail,
  Activity,
  List,
  Image,
  ChevronDown,
  ChevronRight,
  Zap,
  Loader2,
  CheckCircle,
  AlertCircle,
} from 'lucide-react';
import { cmsApi } from '../../lib/api';

// ──────────────────────────────────────────────────────────────────────────────
// Types
// ──────────────────────────────────────────────────────────────────────────────

export interface CapabilityType {
  id: string;
  label: string;
  description: string;
  icon: string;
  config_schema: Record<string, { type: string; label: string; default?: unknown; required?: boolean }>;
  default_config: Record<string, unknown>;
}

export interface EntityPreset {
  id: string;
  label: string;
  description: string;
  icon: string;
  entity_types?: string[];
  context_bases?: string[];
  context_extensions?: string[];
  recommendation_priority?: number;
  default_capabilities: Array<{ id: string; config?: Record<string, unknown> }>;
}

interface ResolvedContext {
  entity_type?: string;
  binding?: {
    base?: string | null;
    extensions?: string[];
  };
}

export interface CapabilityPanelProps {
  contentId: number;
}

// ──────────────────────────────────────────────────────────────────────────────
// Icon map (maps PHP icon names → Lucide components)
// ──────────────────────────────────────────────────────────────────────────────

const ICON_MAP: Record<string, React.ElementType> = {
  tag: Tag,
  package: Package,
  calendar: Calendar,
  mail: Mail,
  activity: Activity,
  list: List,
  image: Image,
};

function CapIcon({ name, className }: { name: string; className?: string }) {
  const Icon = ICON_MAP[name] ?? Zap;
  return <Icon className={className ?? 'w-4 h-4'} />;
}

// ──────────────────────────────────────────────────────────────────────────────
// Config field editor
// ──────────────────────────────────────────────────────────────────────────────

interface ConfigFieldProps {
  fieldKey: string;
  schema: { type: string; label: string; default?: unknown };
  value: unknown;
  onChange: (key: string, value: unknown) => void;
}

const ConfigField = memo(function ConfigField({ fieldKey, schema, value, onChange }: ConfigFieldProps) {
  const id = `cap-cfg-${fieldKey}`;

  if (schema.type === 'boolean') {
    return (
      <label className="flex items-center gap-2 cursor-pointer" htmlFor={id}>
        <input
          id={id}
          type="checkbox"
          className="rounded border-gray-300 text-sky-600 focus:ring-sky-500"
          checked={!!value}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(fieldKey, e.target.checked)}
        />
        <span className="text-sm text-gray-700">{schema.label}</span>
      </label>
    );
  }

  if (schema.type === 'number' || schema.type === 'integer') {
    return (
      <div>
        <label className="block text-xs font-medium text-gray-600 mb-1" htmlFor={id}>
          {schema.label}
        </label>
        <input
          id={id}
          type="number"
          className="w-full text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-sky-500"
          value={(value as number) ?? ''}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(fieldKey, schema.type === 'integer' ? parseInt(e.target.value, 10) : parseFloat(e.target.value))}
        />
      </div>
    );
  }

  return (
    <div>
      <label className="block text-xs font-medium text-gray-600 mb-1" htmlFor={id}>
        {schema.label}
      </label>
      <input
        id={id}
        type="text"
        className="w-full text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-sky-500"
        value={(value as string) ?? ''}
        onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(fieldKey, e.target.value)}
      />
    </div>
  );
});

// ──────────────────────────────────────────────────────────────────────────────
// Single capability row
// ──────────────────────────────────────────────────────────────────────────────

interface CapabilityRowProps {
  capType: CapabilityType;
  attached: boolean;
  config: Record<string, unknown>;
  saving: boolean;
  onToggle: (capId: string, attach: boolean) => void;
  onConfigChange: (capId: string, config: Record<string, unknown>) => void;
  onSave: (capId: string) => void;
}

const CapabilityRow = memo(function CapabilityRow({
  capType, attached, config, saving, onToggle, onConfigChange, onSave,
}: CapabilityRowProps) {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className={`border rounded-xl overflow-hidden transition-colors ${attached ? 'border-sky-200 bg-sky-50/30' : 'border-gray-100 bg-white'}`}>
      {/* Header row */}
      <div className="flex items-center gap-3 px-4 py-3">
        <div className={`p-1.5 rounded-lg ${attached ? 'bg-sky-100 text-sky-600' : 'bg-gray-100 text-gray-400'}`}>
          <CapIcon name={capType.icon} />
        </div>

        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-gray-800 leading-none">{capType.label}</p>
          <p className="text-xs text-gray-400 mt-0.5 truncate">{capType.description}</p>
        </div>

        {/* Toggle */}
        <button
          type="button"
          onClick={() => onToggle(capType.id, !attached)}
          disabled={saving}
          className={`relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1 ${attached ? 'bg-sky-500' : 'bg-gray-200'} ${saving ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          aria-pressed={attached}
          aria-label={`${attached ? 'Disable' : 'Enable'} ${capType.label}`}
        >
          <span
            className={`pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow ring-0 transition-transform ${attached ? 'translate-x-4' : 'translate-x-0'}`}
          />
        </button>

        {/* Expand config */}
        {attached && Object.keys(capType.config_schema).length > 0 && (
          <button
            type="button"
            onClick={() => setExpanded((v: boolean) => !v)}
            className="p-1 text-gray-400 hover:text-gray-600 rounded"
            aria-label="Toggle config"
          >
            {expanded ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
          </button>
        )}
      </div>

      {/* Config form */}
      {attached && expanded && Object.keys(capType.config_schema).length > 0 && (
        <div className="border-t border-sky-100 px-4 py-3 space-y-3 bg-white">
          {Object.entries(capType.config_schema).map(([key, schema]) => (
            <ConfigField
              key={key}
              fieldKey={key}
              schema={schema}
              value={config[key] ?? schema.default}
              onChange={(k: string, v: unknown) => onConfigChange(capType.id, { ...config, [k]: v })}
            />
          ))}
          <button
            type="button"
            onClick={() => onSave(capType.id)}
            disabled={saving}
            className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-sky-600 text-white text-sm font-medium hover:bg-sky-700 disabled:opacity-50 transition-colors"
          >
            {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle className="w-3.5 h-3.5" />}
            Save Config
          </button>
        </div>
      )}
    </div>
  );
});

// ──────────────────────────────────────────────────────────────────────────────
// Main Panel
// ──────────────────────────────────────────────────────────────────────────────

export const CapabilityPanel = memo(function CapabilityPanel({ contentId }: CapabilityPanelProps) {
  const [capTypes, setCapTypes] = useState<CapabilityType[]>([]);
  const [presets, setPresets] = useState<EntityPreset[]>([]);
  const [attached, setAttached] = useState<Record<string, Record<string, unknown>>>({});
  const [localCfg, setLocalCfg] = useState<Record<string, Record<string, unknown>>>({});
  const [resolvedContext, setResolvedContext] = useState<ResolvedContext | null>(null);
  const [saving, setSaving] = useState<Record<string, boolean>>({});
  const [loadState, setLoadState] = useState<'idle' | 'loading' | 'error'>('loading');
  const [statusMsg, setStatusMsg] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Load capability types, presets, and current attached capabilities
  useEffect(() => {
    let cancelled = false;
    setLoadState('loading');

    Promise.all([
      cmsApi.listEntityCapabilityTypes().then((r) => r.json()),
      cmsApi.listEntityPresets().then((r) => r.json()),
      cmsApi.getEntityCapabilities(contentId).then((r) => r.json()),
    ])
      .then(([typesResp, presetsResp, attachedResp]) => {
        if (cancelled) return;
        setCapTypes(typesResp.capabilities ?? []);
        setPresets(presetsResp.presets ?? []);
        const a: Record<string, Record<string, unknown>> = attachedResp.attached ?? {};
        setAttached(a);
        setLocalCfg(JSON.parse(JSON.stringify(a))); // deep copy for local edits
        setResolvedContext(attachedResp.resolved_context ?? null);
        setLoadState('idle');
      })
      .catch(() => {
        if (!cancelled) setLoadState('error');
      });

    return () => { cancelled = true; };
  }, [contentId]);

  const flash = useCallback((type: 'success' | 'error', text: string) => {
    setStatusMsg({ type, text });
    setTimeout(() => setStatusMsg(null), 3000);
  }, []);

  const handleToggle = useCallback(async (capId: string, attach: boolean) => {
    setSaving((s: Record<string, boolean>) => ({ ...s, [capId]: true }));
    try {
      if (attach) {
        const capType = capTypes.find((c: CapabilityType) => c.id === capId);
        const cfg = localCfg[capId] ?? capType?.default_config ?? {};
        const resp = await cmsApi.attachEntityCapability(contentId, capId, cfg);
        const data = await resp.json();
        if (data.success) {
          setAttached(data.attached);
          setLocalCfg((prev: Record<string, Record<string, unknown>>) => ({ ...prev, ...data.attached }));
          flash('success', `${capType?.label ?? capId} enabled`);
        } else {
          flash('error', data.error ?? 'Failed to enable capability');
        }
      } else {
        const resp = await cmsApi.detachEntityCapability(contentId, capId);
        const data = await resp.json();
        if (data.success) {
          setAttached(data.attached);
          flash('success', 'Capability removed');
        } else {
          flash('error', data.error ?? 'Failed to remove capability');
        }
      }
    } catch {
      flash('error', 'Network error');
    } finally {
      setSaving((s: Record<string, boolean>) => ({ ...s, [capId]: false }));
    }
  }, [contentId, capTypes, localCfg, flash]);

  const handleConfigChange = useCallback((capId: string, config: Record<string, unknown>) => {
    setLocalCfg((prev: Record<string, Record<string, unknown>>) => ({ ...prev, [capId]: config }));
  }, []);

  const handleSaveConfig = useCallback(async (capId: string) => {
    setSaving((s: Record<string, boolean>) => ({ ...s, [capId]: true }));
    try {
      const cfg = localCfg[capId] ?? {};
      const resp = await cmsApi.attachEntityCapability(contentId, capId, cfg);
      const data = await resp.json();
      if (data.success) {
        setAttached(data.attached);
        flash('success', 'Config saved');
      } else {
        flash('error', data.error ?? 'Failed to save config');
      }
    } catch {
      flash('error', 'Network error');
    } finally {
      setSaving((s: Record<string, boolean>) => ({ ...s, [capId]: false }));
    }
  }, [contentId, localCfg, flash]);

  const handleApplyPreset = useCallback(async (presetId: string) => {
    setSaving((s: Record<string, boolean>) => ({ ...s, [`__preset_${presetId}`]: true }));
    try {
      const resp = await cmsApi.applyEntityPreset(contentId, presetId);
      const data = await resp.json();
      if (data.success) {
        const preset = presets.find((item: EntityPreset) => item.id === presetId);
        setAttached(data.attached);
        setLocalCfg(JSON.parse(JSON.stringify(data.attached)));
        flash('success', `${preset?.label ?? presetId} applied`);
      } else {
        flash('error', data.error ?? 'Failed to apply preset');
      }
    } catch {
      flash('error', 'Network error');
    } finally {
      setSaving((s: Record<string, boolean>) => ({ ...s, [`__preset_${presetId}`]: false }));
    }
  }, [contentId, flash, presets]);

  const normalizeList = (value: unknown): string[] => {
    if (!Array.isArray(value)) {
      return [];
    }

    const normalized = new Set<string>();
    value.forEach((item) => {
      if (typeof item !== 'string') {
        return;
      }

      const next = item.trim().toLowerCase();
      if (next !== '') {
        normalized.add(next);
      }
    });

    return Array.from(normalized);
  };

  const presetRecommendationScore = (preset: EntityPreset): number => {
    const entityType = (resolvedContext?.entity_type ?? '').trim().toLowerCase();
    const contextBase = (resolvedContext?.binding?.base ?? '').trim().toLowerCase();
    const contextExtensions = normalizeList(resolvedContext?.binding?.extensions ?? []);
    const targetTypes = normalizeList(preset.entity_types ?? []);
    const targetBases = normalizeList(preset.context_bases ?? []);
    const targetExtensions = normalizeList(preset.context_extensions ?? []);
    const hasTargeting = targetTypes.length > 0 || targetBases.length > 0 || targetExtensions.length > 0;

    if (!hasTargeting) {
      return 0;
    }
    if (targetTypes.length > 0 && (!entityType || !targetTypes.includes(entityType))) {
      return -1;
    }
    if (targetBases.length > 0 && (!contextBase || !targetBases.includes(contextBase))) {
      return -1;
    }
    if (targetExtensions.some((extension) => !contextExtensions.includes(extension))) {
      return -1;
    }

    let score = 100 + (preset.recommendation_priority ?? 0);
    if (entityType && targetTypes.includes(entityType)) {
      score += 30;
    }
    if (contextBase && targetBases.includes(contextBase)) {
      score += 20;
    }
    score += targetExtensions.filter((extension) => contextExtensions.includes(extension)).length * 10;

    return score;
  };

  const sortedPresets = [...presets].sort((left, right) => {
    const scoreCompare = presetRecommendationScore(right) - presetRecommendationScore(left);
    if (scoreCompare !== 0) {
      return scoreCompare;
    }
    return left.label.localeCompare(right.label);
  });

  const recommendedPresets = sortedPresets.filter((preset) => presetRecommendationScore(preset) > 0);
  const additionalPresets = sortedPresets.filter((preset) => presetRecommendationScore(preset) <= 0);
  const entityLabel = resolvedContext?.entity_type ?? '';
  const contextLabel = resolvedContext?.binding?.base ?? '';

  // ── Render ────────────────────────────────────────────────────────────────

  if (loadState === 'loading') {
    return (
      <div className="flex flex-col items-center justify-center py-12 gap-2 text-gray-400">
        <Loader2 className="w-5 h-5 animate-spin" />
        <span className="text-sm">Loading capabilities…</span>
      </div>
    );
  }

  if (loadState === 'error') {
    return (
      <div className="flex flex-col items-center justify-center py-12 gap-2 text-red-400">
        <AlertCircle className="w-5 h-5" />
        <span className="text-sm">Failed to load capabilities</span>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4 p-4">
      {/* Status flash */}
      {statusMsg && (
        <div className={`flex items-center gap-2 text-sm px-3 py-2 rounded-lg ${statusMsg.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'}`}>
          {statusMsg.type === 'success' ? <CheckCircle className="w-4 h-4 shrink-0" /> : <AlertCircle className="w-4 h-4 shrink-0" />}
          {statusMsg.text}
        </div>
      )}

      {/* Presets */}
      {recommendedPresets.length > 0 && (
        <div>
          <div className="flex items-center justify-between gap-3 mb-2">
            <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Recommended Presets</h3>
            {(entityLabel || contextLabel) && (
              <span className="text-[11px] text-gray-400">
                {[entityLabel, contextLabel].filter(Boolean).join(' / ')}
              </span>
            )}
          </div>
          <div className="grid grid-cols-2 gap-2">
            {recommendedPresets.map((preset) => (
              <button
                key={preset.id}
                type="button"
                disabled={saving[`__preset_${preset.id}`]}
                onClick={() => handleApplyPreset(preset.id)}
                className="flex flex-col items-start gap-0.5 px-3 py-2 rounded-lg border border-sky-200 bg-sky-50/60 hover:border-sky-300 hover:bg-sky-50 text-left transition-colors disabled:opacity-50"
                title={preset.description}
              >
                <span className="text-sm font-medium text-gray-700">{preset.label}</span>
                <span className="text-xs text-gray-400 line-clamp-1">{preset.description}</span>
                {saving[`__preset_${preset.id}`] && <Loader2 className="w-3 h-3 animate-spin mt-1 text-sky-500" />}
              </button>
            ))}
          </div>
        </div>
      )}

      {additionalPresets.length > 0 && (
        <div>
          <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
            {recommendedPresets.length > 0 ? 'More Presets' : 'Quick Presets'}
          </h3>
          <div className="grid grid-cols-2 gap-2">
            {additionalPresets.map((preset) => (
              <button
                key={preset.id}
                type="button"
                disabled={saving[`__preset_${preset.id}`]}
                onClick={() => handleApplyPreset(preset.id)}
                className="flex flex-col items-start gap-0.5 px-3 py-2 rounded-lg border border-gray-200 hover:border-sky-300 hover:bg-sky-50 text-left transition-colors disabled:opacity-50"
                title={preset.description}
              >
                <span className="text-sm font-medium text-gray-700">{preset.label}</span>
                <span className="text-xs text-gray-400 line-clamp-1">{preset.description}</span>
                {saving[`__preset_${preset.id}`] && <Loader2 className="w-3 h-3 animate-spin mt-1 text-sky-500" />}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Capability list */}
      <div>
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Feature Capabilities</h3>
        <div className="flex flex-col gap-2">
          {capTypes.map((cap) => (
            <CapabilityRow
              key={cap.id}
              capType={cap}
              attached={cap.id in attached}
              config={localCfg[cap.id] ?? cap.default_config}
              saving={!!saving[cap.id]}
              onToggle={handleToggle}
              onConfigChange={handleConfigChange}
              onSave={handleSaveConfig}
            />
          ))}
        </div>
      </div>
    </div>
  );
});

export default CapabilityPanel;
