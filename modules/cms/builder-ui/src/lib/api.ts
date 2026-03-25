/**
 * CMS Page Builder - API Client
 * 
 * Uses cookie-based session auth (PHP sessions) instead of JWT.
 * All API calls go to CMS module endpoints (/api/v1/cms/...).
 */

/**
 * Boot data injected by the DiSyL shell template
 */
export interface BuilderBootData {
  contentId: number | null;
  baseUrl: string;
  csrfToken: string;
  user: {
    id: number;
    username: string;
    role: string;
  } | null;
}

/**
 * Read boot data from the DOM (injected by PHP template)
 */
export function getBootData(): BuilderBootData {
  const el = document.getElementById('builder-boot-data');
  if (!el) {
    return { contentId: null, baseUrl: '', csrfToken: '', user: null };
  }
  try {
    return JSON.parse(el.textContent || '{}');
  } catch {
    return { contentId: null, baseUrl: '', csrfToken: '', user: null };
  }
}

/**
 * Authenticated fetch wrapper for CMS module API calls.
 * Uses session cookies (credentials: 'include') — no JWT needed.
 * Adds CSRF token header for POST/PUT/DELETE.
 */
export async function authFetch(url: string, options: RequestInit = {}): Promise<Response> {
  const boot = getBootData();
  const headers = new Headers(options.headers || {});

  // Add CSRF token for mutating requests
  if (boot.csrfToken && options.method && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(options.method.toUpperCase())) {
    headers.set('X-CSRF-Token', boot.csrfToken);
  }

  // Default content type for non-FormData bodies
  if (!headers.has('Content-Type') && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  headers.set('Accept', 'application/json');

  const response = await fetch(url, {
    ...options,
    headers,
    credentials: 'include', // Send session cookies
  });

  // Handle auth failure — redirect to CMS login
  if (response.status === 401) {
    window.location.href = boot.baseUrl + '/cms/login';
  }

  return response;
}

// =============================================================================
// CMS API Route Helpers
// =============================================================================

const API = '/api/v1/cms';

export const cmsApi = {
  // Content CRUD
  getContent: (id: number) => authFetch(`${API}/content/${id}`),
  createContent: (data: Record<string, unknown>) =>
    authFetch(`${API}/content`, { method: 'POST', body: JSON.stringify(data) }),
  updateContent: (id: number, data: Record<string, unknown>) =>
    authFetch(`${API}/content/${id}`, { method: 'POST', body: JSON.stringify(data) }),

  // Builder Document
  getBuilderDocument: (contentId: number) =>
    authFetch(`${API}/content/${contentId}/builder`),
  saveBuilderDocument: (contentId: number, data: Record<string, unknown>) =>
    authFetch(`${API}/content/${contentId}/builder`, { method: 'POST', body: JSON.stringify(data) }),
  autosaveBuilderDocument: (contentId: number, data: Record<string, unknown>) =>
    authFetch(`${API}/content/${contentId}/builder/autosave`, { method: 'POST', body: JSON.stringify(data) }),
  publishBuilderDocument: (contentId: number, data: Record<string, unknown>) =>
    authFetch(`${API}/content/${contentId}/builder/publish`, { method: 'POST', body: JSON.stringify(data) }),
  previewBuilderDocument: (contentId: number) =>
    authFetch(`${API}/content/${contentId}/builder/preview`),

  // Revisions
  listRevisions: (contentId: number) =>
    authFetch(`${API}/content/${contentId}/builder/revisions`),
  restoreRevision: (contentId: number, revisionId: number) =>
    authFetch(`${API}/content/${contentId}/builder/revisions/${revisionId}/restore`, { method: 'POST' }),

  // Reusable Sections
  listReusableSections: () =>
    authFetch(`${API}/builder/reusable-sections`),
  saveReusableSection: (data: Record<string, unknown>) =>
    authFetch(`${API}/builder/reusable-sections`, { method: 'POST', body: JSON.stringify(data) }),
  deleteReusableSection: (id: number) =>
    authFetch(`${API}/builder/reusable-sections/${id}/delete`, { method: 'POST' }),

  // Templates
  listTemplates: () =>
    authFetch(`${API}/builder/templates`),
  saveTemplate: (data: Record<string, unknown>) =>
    authFetch(`${API}/builder/templates`, { method: 'POST', body: JSON.stringify(data) }),
  deleteTemplate: (id: number) =>
    authFetch(`${API}/builder/templates/${id}/delete`, { method: 'POST' }),

  // Saved Blocks
  listBlocks: () =>
    authFetch(`${API}/saved-blocks`),
  saveBlock: (data: Record<string, unknown>) =>
    authFetch(`${API}/saved-blocks`, { method: 'POST', body: JSON.stringify(data) }),
  updateBlock: (id: number, data: Record<string, unknown>) =>
    authFetch(`${API}/saved-blocks/${id}`, { method: 'POST', body: JSON.stringify(data) }),
  deleteBlock: (id: number) =>
    authFetch(`${API}/saved-blocks/${id}/delete`, { method: 'POST' }),

  // Widgets & Dynamic Sources
  listWidgets: () =>
    authFetch(`${API}/builder/widgets`),
  listDynamicSources: () =>
    authFetch(`${API}/builder/dynamic-sources`),

  // Media
  listMedia: (params?: URLSearchParams) =>
    authFetch(`${API}/media${params ? '?' + params.toString() : ''}`),
  uploadMedia: (formData: FormData) =>
    authFetch(`${API}/media/upload`, { method: 'POST', body: formData }),

  // Entity Capabilities
  listEntityCapabilityTypes: () =>
    authFetch(`${API}/entity-capabilities`),
  listEntityPresets: () =>
    authFetch(`${API}/entity-presets`),
  getEntityCapabilities: (contentId: number) =>
    authFetch(`${API}/content/${contentId}/capabilities`),
  attachEntityCapability: (contentId: number, capabilityId: string, config: Record<string, unknown>) =>
    authFetch(`${API}/content/${contentId}/capabilities`, {
      method: 'POST',
      body: JSON.stringify({ capability_id: capabilityId, config }),
    }),
  detachEntityCapability: (contentId: number, capabilityId: string) =>
    authFetch(`${API}/content/${contentId}/capabilities/${capabilityId}/detach`, { method: 'POST' }),
  applyEntityPreset: (contentId: number, presetId: string) =>
    authFetch(`${API}/content/${contentId}/capabilities/preset`, {
      method: 'POST',
      body: JSON.stringify({ preset_id: presetId }),
    }),
};
