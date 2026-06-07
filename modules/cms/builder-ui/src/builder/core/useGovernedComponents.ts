/**
 * Ikabud Page Builder — Governed Components Hook (Phase 7 / 5.2)
 *
 * Fetches governed DiSyL components, entity-view sources, and handles
 * contract validation. Powers the builder's contract composer mode.
 */

import { useState, useEffect, useCallback } from 'react';
import { cmsApi } from '@/lib/api';
import type { GovernedComponent, EntitySource, EntityViewContract, ContractValidation } from '../core/types';

// =============================================================================
// Hook: useGovernedComponents
// =============================================================================

export function useGovernedComponents() {
  const [components, setComponents] = useState<GovernedComponent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    cmsApi.listGovernedComponents()
      .then(res => res.json())
      .then(data => {
        if (!cancelled && data.ok) {
          setComponents(data.components || []);
        }
      })
      .catch(err => {
        if (!cancelled) setError(err.message);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return { components, loading, error };
}

// =============================================================================
// Hook: useEntitySources
// =============================================================================

export function useEntitySources() {
  const [sources, setSources] = useState<EntitySource[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    cmsApi.listEntitySources()
      .then(res => res.json())
      .then(data => {
        if (!cancelled && data.ok) {
          setSources(data.sources || []);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return { sources, loading };
}

// =============================================================================
// Hook: useEntityViews
// =============================================================================

export function useEntityViews(entityType: string | null) {
  const [views, setViews] = useState<EntityViewContract[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!entityType) {
      setViews([]);
      return;
    }
    let cancelled = false;
    setLoading(true);
    cmsApi.listEntityViews(entityType)
      .then(res => res.json())
      .then(data => {
        if (!cancelled && data.ok) {
          setViews(data.views || []);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [entityType]);

  return { views, loading };
}

// =============================================================================
// Hook: useContractValidation
// =============================================================================

export function useContractValidation() {
  const [validating, setValidating] = useState(false);
  const [validation, setValidation] = useState<ContractValidation | null>(null);

  const validate = useCallback(async (contract: Record<string, unknown>) => {
    setValidating(true);
    try {
      const res = await cmsApi.validateContract(contract);
      const data = await res.json();
      setValidation(data);
      return data;
    } catch {
      setValidation({ ok: false, valid: false, errors: ['Validation request failed'], warnings: [] });
      return null;
    } finally {
      setValidating(false);
    }
  }, []);

  const clearValidation = useCallback(() => setValidation(null), []);

  return { validate, validating, validation, clearValidation };
}
