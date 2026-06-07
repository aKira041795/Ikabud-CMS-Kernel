/**
 * EntitySourcePicker — DiSyL Contract Composer control
 *
 * Provides dropdowns for selecting entity-view sources and view contracts.
 * Used in PropertiesPanel when editing governed components (entity_list, entity_detail).
 * Validates selections against the /builder/entity-sources and /builder/entity-views APIs.
 */

import React, { memo, useState, useMemo } from 'react';
import { Layers, Eye, AlertCircle, CheckCircle, RefreshCw } from 'lucide-react';
import { useEntitySources, useEntityViews, useContractValidation } from '../core/useGovernedComponents';

interface EntitySourcePickerProps {
    /** Current source value (e.g. "cms.post.recent") */
    source: string;
    /** Current view value (e.g. "compact") */
    view: string;
    /** Called when source changes */
    onSourceChange: (source: string) => void;
    /** Called when view changes */
    onViewChange: (view: string) => void;
    /** Component type for validation */
    componentType?: string;
}

const EntitySourcePicker: React.FC<EntitySourcePickerProps> = memo(({
    source,
    view,
    onSourceChange,
    onViewChange,
    componentType = 'entity_list',
}) => {
    const { sources, loading: sourcesLoading } = useEntitySources();
    const { views } = useEntityViews(source || null);
    const { validate, validating, validation } = useContractValidation();
    const [showValidation, setShowValidation] = useState(false);

    // Extract entity type from source (e.g. "cms.post.recent" → "cms.post")
    const entityType = useMemo(() => {
        const lastDot = source.lastIndexOf('.');
        return lastDot > 0 ? source.substring(0, lastDot) : source;
    }, [source]);

    const handleValidate = async () => {
        setShowValidation(true);
        await validate({ type: componentType, source, view });
    };

    if (sourcesLoading) {
        return (
            <div className="flex items-center gap-2 py-2 text-white/30 text-xs">
                <RefreshCw className="w-3 h-3 animate-spin" />
                Loading sources...
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {/* Source picker */}
            <div>
                <label className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1 block">
                    Data Source
                </label>
                <select
                    value={source}
                    onChange={(e) => onSourceChange(e.target.value)}
                    className="w-full bg-[#2d2d2d] border border-[#3c3c3c] rounded-md text-white text-xs px-2 py-1.5 focus:outline-none focus:border-[#6366f1]"
                >
                    <option value="">— Select source —</option>
                    {sources.map((s) => (
                        <option key={s.entity_type} value={s.entity_type}>
                            {s.label} ({s.views.length} views)
                        </option>
                    ))}
                </select>
            </div>

            {/* View picker */}
            {source && (
                <div>
                    <label className="text-[10px] font-medium text-white/40 uppercase tracking-wide mb-1 block">
                        View Contract
                    </label>
                    <select
                        value={view}
                        onChange={(e) => onViewChange(e.target.value)}
                        className="w-full bg-[#2d2d2d] border border-[#3c3c3c] rounded-md text-white text-xs px-2 py-1.5 focus:outline-none focus:border-[#6366f1]"
                    >
                        <option value="compact">compact (default)</option>
                        {views.map((v) => (
                            <option key={v.view} value={v.view}>
                                {v.view} — {Array.isArray(v.fields) ? v.fields.slice(0, 3).join(', ') : 'all fields'}
                                {v.exportable ? ' 📤' : ''}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {/* Validate button */}
            {source && (
                <button
                    onClick={handleValidate}
                    disabled={validating}
                    className="w-full flex items-center justify-center gap-2 py-1.5 text-xs rounded-md border border-[#3c3c3c] hover:border-[#6366f1] text-white/60 hover:text-white/90 transition-colors disabled:opacity-50"
                >
                    {validating ? (
                        <RefreshCw className="w-3 h-3 animate-spin" />
                    ) : validation ? (
                        validation.valid ? <CheckCircle className="w-3 h-3 text-green-400" /> : <AlertCircle className="w-3 h-3 text-yellow-400" />
                    ) : (
                        <Eye className="w-3 h-3" />
                    )}
                    {validating ? 'Validating...' : validation ? (validation.valid ? 'Contract valid' : 'Has warnings') : 'Validate contract'}
                </button>
            )}

            {/* Validation results */}
            {showValidation && validation && (
                <div className={`p-2 rounded-md text-[10px] space-y-1 ${validation.valid ? 'bg-green-400/5 border border-green-400/20' : 'bg-yellow-400/5 border border-yellow-400/20'
                    }`}>
                    {validation.errors.map((e, i) => (
                        <div key={i} className="flex items-start gap-1 text-red-400">
                            <AlertCircle className="w-3 h-3 mt-0.5 flex-shrink-0" />
                            <span>{e}</span>
                        </div>
                    ))}
                    {validation.warnings.map((w, i) => (
                        <div key={i} className="flex items-start gap-1 text-yellow-400">
                            <AlertCircle className="w-3 h-3 mt-0.5 flex-shrink-0" />
                            <span>{w}</span>
                        </div>
                    ))}
                    {validation.preview && (
                        <div className="text-green-400 flex items-center gap-1">
                            <CheckCircle className="w-3 h-3" />
                            <span>Preview available ({validation.preview.length} rows)</span>
                        </div>
                    )}
                    {validation.errors.length === 0 && validation.warnings.length === 0 && (
                        <div className="text-green-400 flex items-center gap-1">
                            <CheckCircle className="w-3 h-3" />
                            <span>All checks passed</span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
});

EntitySourcePicker.displayName = 'EntitySourcePicker';

export default EntitySourcePicker;
