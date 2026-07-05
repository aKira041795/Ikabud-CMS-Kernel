/**
 * Ikabud CMS Page Builder
 * Visual editor for Native CMS pages and posts
 * 
 * Separate from Admin Theme Builder (VisualBuilder.tsx)
 * - Content-focused, beginner-friendly
 * - No template logic (loops, conditions)
 * - Outputs JSON stored in database
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { authFetch, getBootData } from '@/lib/api';
import {
  ArrowLeft,
  Save,
  Loader2,
  Monitor,
  Tablet,
  Smartphone,
  Eye,
  Check,
  AlertCircle,
  Undo2,
  Redo2,
  Plus,
  Maximize2,
  Minimize2,
  ZoomIn,
  ZoomOut,
  Pencil,
  Search,
  Command,
  X,
  Keyboard,
  Layers,
  FileText,
} from 'lucide-react';

import { useBuilderState, DiSyLNode, createNode, createEmptyDocument, CMS_COMPONENTS, normalizeBuilderNode } from './builder/core';
import { NodeRenderer, ComponentPanelEnhanced, PropertiesPanel, LayersPanel, ContextMenu, GlobalStylesPanel, defaultGlobalStyles, VersionHistory, OnboardingTooltips, TemplatesPanel, SaveTemplateModal, SaveBlockModal, BlocksPanel, SEOPanel, defaultSEOSettings, CapabilityPanel } from './builder/components';
import GovernedComponentsPanel from './builder/components/GovernedComponentsPanel';
import type { GlobalStyles, SEOSettings } from './builder/components';
import { initBuilderPreviewRuntime } from './builder/runtime/previewRuntime';
import { Clock } from 'lucide-react';

// =============================================================================
// Types
// =============================================================================

interface PageData {
  id: number;
  title: string;
  slug: string;
  content: DiSyLNode | null;
  status: string;
  type: 'page' | 'post';
}

const COMPONENT_DND_MIME = 'application/x-cms-component';

type GlobalStyleOverrides = Partial<{
  colors: Partial<GlobalStyles['colors']>;
  typography: Partial<GlobalStyles['typography']>;
  spacing: Partial<GlobalStyles['spacing']>;
  buttons: Partial<GlobalStyles['buttons']>;
  page: Partial<GlobalStyles['page']>;
}>;

interface PreviewShellSettings {
  maxWidth?: string;
  paddingTop?: string;
  paddingRight?: string;
  paddingBottom?: string;
  paddingLeft?: string;
}

const BUILDER_PREVIEW_SCOPE_CLASS = 'cms-builder-live-preview';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function normalizeGlobalStyleOverrides(value: unknown): GlobalStyleOverrides {
  if (!isRecord(value)) {
    return {};
  }

  const sectionKeys: Array<keyof GlobalStyleOverrides> = ['colors', 'typography', 'spacing', 'buttons', 'page'];
  const normalized: GlobalStyleOverrides = {};

  for (const sectionKey of sectionKeys) {
    const section = value[sectionKey];
    if (!isRecord(section)) {
      continue;
    }

    const entries = Object.entries(section).filter(([, sectionValue]) => (
      typeof sectionValue === 'string' || typeof sectionValue === 'boolean'
    ));

    if (entries.length === 0) {
      continue;
    }

    normalized[sectionKey] = Object.fromEntries(entries) as NonNullable<GlobalStyleOverrides[typeof sectionKey]>;
  }

  return normalized;
}

function mergeGlobalStyles(base: GlobalStyles, overrides: unknown): GlobalStyles {
  const normalized = normalizeGlobalStyleOverrides(overrides);

  return {
    colors: { ...base.colors, ...(normalized.colors ?? {}) },
    typography: { ...base.typography, ...(normalized.typography ?? {}) },
    spacing: { ...base.spacing, ...(normalized.spacing ?? {}) },
    buttons: { ...base.buttons, ...(normalized.buttons ?? {}) },
    page: { ...base.page, ...(normalized.page ?? {}) },
  };
}

function diffSection<T extends Record<string, string | boolean>>(base: T, current: T): Partial<T> | null {
  const diff: Partial<T> = {};

  for (const key of Object.keys(current) as Array<keyof T>) {
    if (current[key] !== base[key]) {
      diff[key] = current[key];
    }
  }

  return Object.keys(diff).length > 0 ? diff : null;
}

function diffGlobalStyles(base: GlobalStyles, current: GlobalStyles): GlobalStyleOverrides {
  const diff: GlobalStyleOverrides = {};
  const colorDiff = diffSection(base.colors, current.colors);
  const typographyDiff = diffSection(base.typography, current.typography);
  const spacingDiff = diffSection(base.spacing, current.spacing);
  const buttonDiff = diffSection(base.buttons, current.buttons);
  const pageDiff = diffSection(base.page, current.page);

  if (colorDiff) {
    diff.colors = colorDiff;
  }
  if (typographyDiff) {
    diff.typography = typographyDiff;
  }
  if (spacingDiff) {
    diff.spacing = spacingDiff;
  }
  if (buttonDiff) {
    diff.buttons = buttonDiff;
  }
  if (pageDiff) {
    diff.page = pageDiff;
  }

  return diff;
}

function hasGlobalStyleOverrides(overrides: GlobalStyleOverrides): boolean {
  return Object.values(overrides).some((section) => isRecord(section) && Object.keys(section).length > 0);
}

function normalizePreviewShellSettings(value: unknown): PreviewShellSettings {
  if (!isRecord(value)) {
    return {};
  }

  const normalized: PreviewShellSettings = {};
  const shellKeys: Array<keyof PreviewShellSettings> = ['maxWidth', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'];

  for (const key of shellKeys) {
    const shellValue = value[key];
    if (typeof shellValue === 'string' && shellValue.trim() !== '') {
      normalized[key] = shellValue.trim();
    }
  }

  return normalized;
}

function renderScopedGlobalStyleCss(overrides: GlobalStyleOverrides, scopeClass: string): string {
  const normalized = normalizeGlobalStyleOverrides(overrides);
  const colors = normalized.colors ?? {};
  const typography = normalized.typography ?? {};
  const spacing = normalized.spacing ?? {};
  const buttons = normalized.buttons ?? {};
  const scope = `.${scopeClass}`;
  const rules: string[] = [];
  const rootDeclarations: string[] = [];

  if (typeof colors.background === 'string' && colors.background.trim() !== '') {
    rootDeclarations.push(`--cms-builder-background:${colors.background}`);
    rootDeclarations.push(`background-color:${colors.background}`);
  }
  if (typeof colors.backgroundAlt === 'string' && colors.backgroundAlt.trim() !== '') {
    rootDeclarations.push(`--cms-builder-background-alt:${colors.backgroundAlt}`);
  }
  if (typeof colors.primary === 'string' && colors.primary.trim() !== '') {
    rootDeclarations.push(`--cms-builder-color-primary:${colors.primary}`);
  }
  if (typeof colors.secondary === 'string' && colors.secondary.trim() !== '') {
    rootDeclarations.push(`--cms-builder-color-secondary:${colors.secondary}`);
  }
  if (typeof colors.accent === 'string' && colors.accent.trim() !== '') {
    rootDeclarations.push(`--cms-builder-color-accent:${colors.accent}`);
  }
  if (typeof colors.text === 'string' && colors.text.trim() !== '') {
    rootDeclarations.push(`--cms-builder-text:${colors.text}`);
    rootDeclarations.push(`color:${colors.text}`);
  }
  if (typeof colors.textLight === 'string' && colors.textLight.trim() !== '') {
    rootDeclarations.push(`--cms-builder-text-light:${colors.textLight}`);
  }
  if (typeof typography.fontFamily === 'string' && typography.fontFamily.trim() !== '') {
    rootDeclarations.push(`--cms-builder-font-family:${typography.fontFamily}`);
    rootDeclarations.push(`font-family:${typography.fontFamily}`);
  }
  if (typeof typography.headingFontFamily === 'string' && typography.headingFontFamily.trim() !== '') {
    rootDeclarations.push(`--cms-builder-heading-font-family:${typography.headingFontFamily}`);
  }
  if (typeof typography.baseFontSize === 'string' && typography.baseFontSize.trim() !== '') {
    rootDeclarations.push(`--cms-builder-base-font-size:${typography.baseFontSize}`);
    rootDeclarations.push(`font-size:${typography.baseFontSize}`);
  }
  if (typeof typography.lineHeight === 'string' && typography.lineHeight.trim() !== '') {
    rootDeclarations.push(`--cms-builder-line-height:${typography.lineHeight}`);
    rootDeclarations.push(`line-height:${typography.lineHeight}`);
  }
  if (typeof typography.h1Size === 'string' && typography.h1Size.trim() !== '') {
    rootDeclarations.push(`--cms-builder-h1-size:${typography.h1Size}`);
  }
  if (typeof typography.h2Size === 'string' && typography.h2Size.trim() !== '') {
    rootDeclarations.push(`--cms-builder-h2-size:${typography.h2Size}`);
  }
  if (typeof typography.h3Size === 'string' && typography.h3Size.trim() !== '') {
    rootDeclarations.push(`--cms-builder-h3-size:${typography.h3Size}`);
  }
  if (typeof typography.h4Size === 'string' && typography.h4Size.trim() !== '') {
    rootDeclarations.push(`--cms-builder-h4-size:${typography.h4Size}`);
  }
  if (typeof spacing.sectionPadding === 'string' && spacing.sectionPadding.trim() !== '') {
    rootDeclarations.push(`--cms-builder-section-padding:${spacing.sectionPadding}`);
  }
  if (typeof spacing.containerMaxWidth === 'string' && spacing.containerMaxWidth.trim() !== '') {
    rootDeclarations.push(`--cms-builder-container-max-width:${spacing.containerMaxWidth}`);
  }
  if (typeof spacing.elementGap === 'string' && spacing.elementGap.trim() !== '') {
    rootDeclarations.push(`--cms-builder-element-gap:${spacing.elementGap}`);
  }
  if (typeof buttons.borderRadius === 'string' && buttons.borderRadius.trim() !== '') {
    rootDeclarations.push(`--cms-builder-button-radius:${buttons.borderRadius}`);
  }
  if (typeof buttons.paddingX === 'string' && buttons.paddingX.trim() !== '') {
    rootDeclarations.push(`--cms-builder-button-padding-x:${buttons.paddingX}`);
  }
  if (typeof buttons.paddingY === 'string' && buttons.paddingY.trim() !== '') {
    rootDeclarations.push(`--cms-builder-button-padding-y:${buttons.paddingY}`);
  }
  if (typeof buttons.fontSize === 'string' && buttons.fontSize.trim() !== '') {
    rootDeclarations.push(`--cms-builder-button-font-size:${buttons.fontSize}`);
  }
  if (rootDeclarations.length > 0) {
    rules.push(`${scope}{${rootDeclarations.join(';')}}`);
  }

  if (typeof typography.headingFontFamily === 'string' && typography.headingFontFamily.trim() !== '') {
    rules.push(`${scope} h1,${scope} h2,${scope} h3,${scope} h4,${scope} h5,${scope} h6{font-family:${typography.headingFontFamily}}`);
  }
  if (typeof typography.h1Size === 'string' && typography.h1Size.trim() !== '') {
    rules.push(`${scope} h1{font-size:${typography.h1Size}}`);
  }
  if (typeof typography.h2Size === 'string' && typography.h2Size.trim() !== '') {
    rules.push(`${scope} h2{font-size:${typography.h2Size}}`);
  }
  if (typeof typography.h3Size === 'string' && typography.h3Size.trim() !== '') {
    rules.push(`${scope} h3{font-size:${typography.h3Size}}`);
  }
  if (typeof typography.h4Size === 'string' && typography.h4Size.trim() !== '') {
    rules.push(`${scope} h4{font-size:${typography.h4Size}}`);
  }

  return rules.join('');
}

// =============================================================================
// Smart parent resolution helper
// Drills through section → container → row → column to find the deepest
// valid insertion target for a given child type.
// Pure function — no React dependency.
// =============================================================================
function drillToInsertParent(
  parentNode: DiSyLNode,
  childType: string,
  findNode: (id: string) => DiSyLNode | null,
): string {
  const isStructural = ['document', 'section', 'container', 'layout_container', 'row', 'column'].includes(childType);

  // Row: non-column content → first column
  if (parentNode.type === 'row' && childType !== 'column') {
    const firstColumn = parentNode.children.find((c) => c.type === 'column');
    if (firstColumn) return firstColumn.id;
  }

  // Container: non-structural content → first row (then recurse)
  if ((parentNode.type === 'container' || parentNode.type === 'layout_container') && !isStructural) {
    const firstRow = parentNode.children.find((c) => c.type === 'row');
    if (firstRow) {
      const rowNode = findNode(firstRow.id);
      if (rowNode) return drillToInsertParent(rowNode, childType, findNode);
    }
  }

  // Section: non-structural content → first container (then recurse)
  if (parentNode.type === 'section' && !isStructural) {
    const firstContainer = parentNode.children.find((c) => c.type === 'container' || c.type === 'layout_container');
    if (firstContainer) {
      const containerNode = findNode(firstContainer.id);
      if (containerNode) return drillToInsertParent(containerNode, childType, findNode);
    }
  }

  // Document: non-section content → last/first section (then recurse)
  if (parentNode.type === 'document' && childType !== 'section') {
    const lastSection = [...parentNode.children].reverse().find((c) => c.type === 'section');
    if (lastSection) {
      const sectionNode = findNode(lastSection.id);
      if (sectionNode) return drillToInsertParent(sectionNode, childType, findNode);
    }
  }

  return parentNode.id;
}

// =============================================================================
// Page Builder Component
// =============================================================================

export default function PageBuilder() {
  const boot = getBootData();
  const id = boot.contentId ? String(boot.contentId) : null;
  const previewThemeOverrides = normalizeGlobalStyleOverrides(boot.previewTheme?.global_styles);
  const previewShell = normalizePreviewShellSettings(boot.previewTheme?.shell);
  const previewStyleBase = mergeGlobalStyles(defaultGlobalStyles, previewThemeOverrides);

  // Page data
  const [pageData, setPageData] = useState<PageData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Builder state
  const builder = useBuilderState();

  // Panel state - NEW LAYOUT per spec
  // Left: Properties (context-sensitive)
  // Right: Navigator + Global Settings
  // Bottom: Component drawer
  const [leftPanelCollapsed, setLeftPanelCollapsed] = useState(true); // Collapsed when no selection
  const [rightPanelTab, setRightPanelTab] = useState<'navigator' | 'templates' | 'blocks' | 'global' | 'seo' | 'capabilities' | 'governed'>('navigator');
  const [rightPanelCollapsed, setRightPanelCollapsed] = useState(false);
  const [componentDrawerOpen, setComponentDrawerOpen] = useState(true);
  const [zoom, setZoom] = useState(100);

  // Legacy - keep for backward compatibility during transition
  const [_sidebarTab, _setSidebarTab] = useState<'components' | 'layers' | 'settings'>('components');

  // Global styles state
  const [globalStyles, setGlobalStyles] = useState<GlobalStyles>(previewStyleBase);

  // SEO settings state
  const [seoSettings, setSeoSettings] = useState<SEOSettings>(defaultSEOSettings);

  const [initialGlobalStylesStr, setInitialGlobalStylesStr] = useState<string>("");
  const [initialSeoSettingsStr, setInitialSeoSettingsStr] = useState<string>("");

  // Context menu state
  const [contextMenu, setContextMenu] = useState<{ x: number; y: number; nodeId: string; nodeType: string } | null>(null);

  // Auto-save state
  const [lastAutoSave, setLastAutoSave] = useState<Date | null>(null);
  const autoSaveIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const canvasRef = useRef<HTMLDivElement>(null);

  const hasUnsavedSettings = JSON.stringify(globalStyles) !== initialGlobalStylesStr || JSON.stringify(seoSettings) !== initialSeoSettingsStr;

  const pageStyleOverrides = diffGlobalStyles(previewStyleBase, globalStyles);
  const previewThemeCss = renderScopedGlobalStyleCss(previewThemeOverrides, BUILDER_PREVIEW_SCOPE_CLASS);
  const pageOverrideCss = renderScopedGlobalStyleCss(pageStyleOverrides, BUILDER_PREVIEW_SCOPE_CLASS);
  const previewShellStyle = {
    width: '100%',
    boxSizing: 'border-box' as const,
    maxWidth: previewShell.maxWidth || undefined,
    marginLeft: 'auto',
    marginRight: 'auto',
    paddingTop: previewShell.paddingTop || undefined,
    paddingRight: previewShell.paddingRight || undefined,
    paddingBottom: previewShell.paddingBottom || undefined,
    paddingLeft: previewShell.paddingLeft || undefined,
  };

  const handleAutoSave = useCallback(async () => {
    if (!pageData || !pageData.id || saving) return;

    try {
      const response = await authFetch(`/api/v1/cms/content/${pageData.id}/builder/autosave`, {
        method: 'POST',
        body: JSON.stringify({
          document: builder.document,
          global_styles: pageStyleOverrides,
          seo_settings: seoSettings,
        }),
      });

      const data = await response.json();

      if (data.ok || data.success) {
        builder.markClean();
        setLastAutoSave(new Date());
        setInitialGlobalStylesStr(JSON.stringify(globalStyles));
        setInitialSeoSettingsStr(JSON.stringify(seoSettings));
      }
    } catch (err) {
      console.error('Auto-save failed:', err);
    }
  }, [pageData, saving, builder, pageStyleOverrides, globalStyles, seoSettings]);

  // Title editing state
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [editingTitleValue, setEditingTitleValue] = useState('');
  const titleInputRef = useRef<HTMLInputElement>(null);

  // Version history state
  const [versionHistoryOpen, setVersionHistoryOpen] = useState(false);

  // Finder state
  const [finderOpen, setFinderOpen] = useState(false);
  const [finderQuery, setFinderQuery] = useState('');
  const finderInputRef = useRef<HTMLInputElement>(null);

  // Keyboard shortcuts help state
  const [shortcutsOpen, setShortcutsOpen] = useState(false);

  // Structure Mode state - shows all outlines and labels
  const [structureMode, setStructureMode] = useState(false);

  // Save as Template modal state
  const [saveTemplateModalOpen, setSaveTemplateModalOpen] = useState(false);

  // Save as Block modal state
  const [saveBlockModalOpen, setSaveBlockModalOpen] = useState(false);
  const [blockToSave, setBlockToSave] = useState<DiSyLNode | null>(null);

  // Auto-expand left panel when element is selected
  useEffect(() => {
    if (builder.selectedIds.length > 0) {
      setLeftPanelCollapsed(false);
    }
  }, [builder.selectedIds]);

  // =============================================================================
  // Data Fetching
  // =============================================================================

  useEffect(() => {
    if (id) {
      fetchPage();
    } else {
      // New page - check if template parameter is provided
      const templateId = new URLSearchParams(window.location.search).get('template');
      if (templateId) {
        loadTemplate(templateId);
      } else {
        // Initialize with empty document
        setPageData({
          id: 0,
          title: 'Untitled Page',
          slug: '',
          content: null,
          status: 'draft',
          type: 'page',
        });
        builder.setDocument(createEmptyDocument());
        setLoading(false);
      }
    }
  }, [id]);

  // Load template content
  const loadTemplate = async (templateId: string) => {
    setLoading(true);
    try {
      // Fetch template - this also increments usage_count on the backend
      const response = await authFetch(`/api/v1/cms/builder/templates/${templateId}?use=true`);
      const data = await response.json();

      if ((data.ok || data.success) && data.data) {
        const template = data.data;

        // Set page data with template name as title
        setPageData({
          id: 0,
          title: `${template.name} (Copy)`,
          slug: '',
          content: template.content,
          status: 'draft',
          type: 'page',
        });

        // Load template content into builder with normalization
        if (template.content) {
          const normalizedContent = normalizeBuilderNode(template.content, { isRoot: template.content?.type === 'document' });
          builder.setDocument(normalizedContent);
        } else {
          builder.setDocument(createEmptyDocument());
        }

        // Load global styles if available
        if (template.global_styles) {
          const newStyles = mergeGlobalStyles(previewStyleBase, template.global_styles);
          setGlobalStyles(newStyles);
          setInitialGlobalStylesStr(JSON.stringify(newStyles));
        } else {
          setGlobalStyles(previewStyleBase);
          setInitialGlobalStylesStr(JSON.stringify(previewStyleBase));
        }

        setSaveMessage({ type: 'success', text: `Loaded template: ${template.name}` });
        setTimeout(() => setSaveMessage(null), 3000);
      } else {
        setError('Failed to load template');
        builder.setDocument(createEmptyDocument());
      }
    } catch (err) {
      console.error('Load template error:', err);
      setError('Failed to load template');
      builder.setDocument(createEmptyDocument());
    } finally {
      setLoading(false);
    }
  };

  // =============================================================================
  // Auto-Save (every 30 seconds if dirty)
  // =============================================================================

  useEffect(() => {
    autoSaveIntervalRef.current = setInterval(() => {
      if ((builder.isDirty || hasUnsavedSettings) && pageData && !saving) {
        handleAutoSave();
      }
    }, 30000); // 30 seconds

    return () => {
      if (autoSaveIntervalRef.current) {
        clearInterval(autoSaveIntervalRef.current);
      }
    };
  }, [builder.isDirty, hasUnsavedSettings, pageData, saving, handleAutoSave]);

  // Auto-save on blur (leaving page)
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (builder.isDirty || hasUnsavedSettings) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
      }
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'hidden' && (builder.isDirty || hasUnsavedSettings) && pageData && !saving) {
        handleAutoSave();
      }
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [builder.isDirty, hasUnsavedSettings, pageData, saving, handleAutoSave]);

  // Run frontend-like interactive scripts in builder canvas after updates.
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    let cleanup: (() => void) | null = null;
    const frame = requestAnimationFrame(() => {
      cleanup = initBuilderPreviewRuntime(canvas);
    });

    return () => {
      cancelAnimationFrame(frame);
      if (cleanup) cleanup();
    };
  }, [builder.document, builder.viewport]);

  const fetchPage = async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await authFetch(`/api/v1/cms/content/${id}/builder`);
      const data = await response.json();

      // API returns { ok: true, data: { content: {...}, document: {...}, ... } }
      if ((data.ok || data.success) && data.data) {
        const contentInfo = data.data.content || data.data;
        const builderDocument = data.data.document || null;

        setPageData({
          id: contentInfo.id,
          title: contentInfo.title,
          slug: contentInfo.slug,
          content: builderDocument,
          status: contentInfo.status,
          type: (contentInfo.type || 'page') as 'page' | 'post',
        });

        // Load global styles if available
        const globalStylesRaw = data.data.global_styles || contentInfo.global_styles;
        if (globalStylesRaw) {
          try {
            const parsed = typeof globalStylesRaw === 'string' ? JSON.parse(globalStylesRaw) : globalStylesRaw;
            const newStyles = mergeGlobalStyles(previewStyleBase, parsed);
            setGlobalStyles(newStyles);
            setInitialGlobalStylesStr(JSON.stringify(newStyles));
          } catch {
            setGlobalStyles(previewStyleBase);
            setInitialGlobalStylesStr(JSON.stringify(previewStyleBase));
          }
        } else {
          setGlobalStyles(previewStyleBase);
          setInitialGlobalStylesStr(JSON.stringify(previewStyleBase));
        }

        // Load SEO settings if available
        const seoRaw = data.data.seo_settings || contentInfo.seo_settings;
        if (seoRaw) {
          try {
            const parsed = typeof seoRaw === 'string' ? JSON.parse(seoRaw) : seoRaw;
            const newSeo = { ...defaultSEOSettings, ...parsed };
            setSeoSettings(newSeo);
            setInitialSeoSettingsStr(JSON.stringify(newSeo));
          } catch {
            setSeoSettings(defaultSEOSettings);
            setInitialSeoSettingsStr(JSON.stringify(defaultSEOSettings));
          }
        }

        // Load content into builder (normalize to merge defaultProps into null values)
        if (builderDocument && typeof builderDocument === 'object' && builderDocument.type) {
          if (builderDocument.type === 'section') {
            const migratedDocument = createNode('document', {}, {}, [normalizeBuilderNode(builderDocument)]);
            builder.setDocument(migratedDocument);
          } else if (builderDocument.type === 'document') {
            builder.setDocument(normalizeBuilderNode(builderDocument, { isRoot: true }));
          } else {
            builder.setDocument(createEmptyDocument());
          }
        } else {
          builder.setDocument(createEmptyDocument());
        }
      } else {
        setError(data.error || 'Failed to load page');
      }
    } catch (err) {
      setError('Failed to load page');
      console.error('Error fetching page:', err);
    } finally {
      setLoading(false);
    }
  };

  // =============================================================================
  // Save Handler
  // =============================================================================

  const handleSave = async () => {
    if (!pageData) return;

    try {
      setSaving(true);
      setSaveMessage(null);

      const isNewPage = pageData.id === 0;

      if (isNewPage) {
        // Step 1: Create the content record via CMS API
        const createResp = await authFetch('/api/v1/cms/content', {
          method: 'POST',
          body: JSON.stringify({
            title: pageData.title,
            slug: pageData.slug || pageData.title.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            type: pageData.type,
            status: pageData.status,
          }),
        });

        const createData = await createResp.json();

        if (!createData.ok || !createData.id) {
          setSaveMessage({ type: 'error', text: createData.error || 'Failed to create page' });
          return;
        }

        const newId = createData.id;

        // Step 2: Save the builder document to the new content
        const builderResp = await authFetch(`/api/v1/cms/content/${newId}/builder`, {
          method: 'POST',
          body: JSON.stringify({
            document: builder.document,
            global_styles: pageStyleOverrides,
            seo_settings: seoSettings,
          }),
        });

        const builderData = await builderResp.json();
        if (!builderData.ok && !builderData.success) {
          console.warn('Builder document save returned:', builderData);
        }

        builder.markClean();
        setInitialGlobalStylesStr(JSON.stringify(globalStyles));
        setInitialSeoSettingsStr(JSON.stringify(seoSettings));
        setPageData({ ...pageData, id: newId });
        window.history.replaceState(null, '', `/cms/admin/react-builder/${newId}`);
        setSaveMessage({ type: 'success', text: 'Page created' });
        setTimeout(() => setSaveMessage(null), 3000);

      } else {
        // Existing page: save builder document directly
        const response = await authFetch(`/api/v1/cms/content/${pageData.id}/builder`, {
          method: 'POST',
          body: JSON.stringify({
            title: pageData.title,
            slug: pageData.slug,
            status: pageData.status,
            document: builder.document,
            global_styles: pageStyleOverrides,
            seo_settings: seoSettings,
          }),
        });

        const data = await response.json();

        if (data.ok || data.success) {
          builder.markClean();
          setInitialGlobalStylesStr(JSON.stringify(globalStyles));
          setInitialSeoSettingsStr(JSON.stringify(seoSettings));
          setSaveMessage({ type: 'success', text: 'Saved successfully' });
          setTimeout(() => setSaveMessage(null), 3000);
        } else {
          setSaveMessage({ type: 'error', text: data.error || 'Failed to save' });
        }
      }
    } catch (err) {
      setSaveMessage({ type: 'error', text: 'Failed to save' });
      console.error('Error saving:', err);
    } finally {
      setSaving(false);
    }
  };

  // Handle version restore
  const handleRestoreVersion = useCallback((content: string) => {
    try {
      const parsedContent = JSON.parse(content);
      builder.setDocument(parsedContent);
      setSaveMessage({ type: 'success', text: 'Version restored' });
      setTimeout(() => setSaveMessage(null), 3000);
    } catch (err) {
      console.error('Failed to restore version:', err);
      setSaveMessage({ type: 'error', text: 'Failed to restore version' });
    }
  }, [builder]);

  // =============================================================================
  // Title Editing Handlers
  // =============================================================================

  const handleStartEditTitle = useCallback(() => {
    if (!pageData) return;
    setEditingTitleValue(pageData.title);
    setIsEditingTitle(true);
    // Focus input after render
    setTimeout(() => titleInputRef.current?.focus(), 0);
  }, [pageData]);

  const handleSaveTitle = useCallback(() => {
    if (!pageData || !editingTitleValue.trim()) {
      setIsEditingTitle(false);
      return;
    }

    const newTitle = editingTitleValue.trim();
    setPageData({ ...pageData, title: newTitle });
    setIsEditingTitle(false);

    // Mark as dirty so it gets saved
    if (newTitle !== pageData.title) {
      builder.updateProps(builder.document.id, {}); // Trigger dirty state
    }
  }, [pageData, editingTitleValue, builder]);

  const handleTitleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleSaveTitle();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      setIsEditingTitle(false);
    }
  }, [handleSaveTitle]);

  // =============================================================================
  // Component Handlers
  // =============================================================================

  const showPlacementError = useCallback((text: string) => {
    setSaveMessage({ type: 'error', text });
    setTimeout(() => setSaveMessage(null), 3500);
  }, []);

  const resolvePreferredInsertParent = useCallback((requestedParentId: string, childType: DiSyLNode['type']) => {
    const requestedParent = builder.findNode(requestedParentId);
    if (!requestedParent) {
      return requestedParentId;
    }
    return drillToInsertParent(requestedParent, childType, builder.findNode);
  }, [builder]);

  const resolveGovernedParent = useCallback((requestedParentId: string, childType: DiSyLNode['type']) => {
    const preferredParentId = resolvePreferredInsertParent(requestedParentId, childType);
    return builder.resolveInsertionParent(preferredParentId, childType);
  }, [builder, resolvePreferredInsertParent]);

  const insertNodeWithGovernedFeedback = useCallback((node: DiSyLNode, requestedParentId: string, requestedIndex: number, failurePrefix = 'Cannot place') => {
    const resolvedParentId = resolveGovernedParent(requestedParentId, node.type);
    if (resolvedParentId === null) {
      showPlacementError(`${failurePrefix} ${node.type.replace(/_/g, ' ')} in that governed location.`);
      return false;
    }

    const resolvedParent = builder.findNode(resolvedParentId);
    const insertIndex = resolvedParentId === requestedParentId
      ? requestedIndex
      : (resolvedParent?.children.length ?? 0);

    builder.insertNode(node, resolvedParentId, insertIndex);
    return true;
  }, [builder, resolveGovernedParent, showPlacementError]);

  const handleAddComponent = useCallback((node: DiSyLNode) => {
    // Add to selected node or root
    const requestedParentId = builder.selectedNode?.id || builder.document.id;
    const parentId = resolveGovernedParent(requestedParentId, node.type);
    if (parentId === null) {
      showPlacementError(`No governed placement is available for ${node.type.replace(/_/g, ' ')} in the current selection.`);
      return;
    }
    const parent = builder.findNode(parentId);
    const index = parent?.children.length || 0;
    const nodeToInsert = parent?.type === 'section' && node.type === 'layout_container'
      ? createNode('container', {}, {}, [node])
      : node;
    insertNodeWithGovernedFeedback(nodeToInsert, parentId, index);
  }, [builder, insertNodeWithGovernedFeedback, resolveGovernedParent, showPlacementError]);

  // Smart canvas move: applies parent resolution when dropping "inside" a container,
  // so content widgets always land in the deepest valid target (e.g. column, not section root).
  const handleSmartMoveNode = useCallback((nodeId: string, targetParentId: string, targetIndex: number) => {
    const draggedNode = builder.findNode(nodeId);
    if (!draggedNode) {
      builder.moveNode(nodeId, targetParentId, targetIndex);
      return;
    }
    const resolvedParentId = resolveGovernedParent(targetParentId, draggedNode.type);
    if (resolvedParentId === null) {
      showPlacementError(`Cannot move ${draggedNode.type.replace(/_/g, ' ')} into that governed location.`);
      return;
    }
    if (resolvedParentId !== targetParentId) {
      const resolvedParent = builder.findNode(resolvedParentId);
      builder.moveNode(nodeId, resolvedParentId, resolvedParent?.children.length ?? 0);
    } else {
      builder.moveNode(nodeId, targetParentId, targetIndex);
    }
  }, [builder, resolveGovernedParent, showPlacementError]);

  const handlePasteNodes = useCallback((requestedParentId: string, index: number) => {
    if (!builder.clipboard || builder.clipboard.length === 0) {
      return;
    }

    const blockedNode = builder.clipboard.find((node) => resolveGovernedParent(requestedParentId, node.type) === null);
    if (blockedNode) {
      showPlacementError(`Cannot paste ${blockedNode.type.replace(/_/g, ' ')} into that governed location.`);
      return;
    }

    builder.pasteNodes(requestedParentId, index);
  }, [builder, resolveGovernedParent, showPlacementError]);

  const governedPasteBlockReason = useCallback((requestedParentId: string): string | null => {
    if (!builder.clipboard || builder.clipboard.length === 0) {
      return 'Clipboard is empty';
    }

    const blockedNode = builder.clipboard.find((node) => resolveGovernedParent(requestedParentId, node.type) === null);
    if (!blockedNode) {
      return null;
    }

    return `${blockedNode.type.replace(/_/g, ' ')} has no governed placement here`;
  }, [builder.clipboard, resolveGovernedParent]);

  // Add a new equal-width column to an existing row node.
  const handleAddColumnToRow = useCallback((rowId: string) => {
    const row = builder.findNode(rowId);
    if (!row) return;
    const newColumn = createNode('column', {}, {
      flex: '1 1 0%',
      padding: '16px',
      minHeight: '100px',
      boxSizing: 'border-box',
    }, []);
    builder.insertNode(newColumn, rowId, row.children.length);
  }, [builder]);

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    const data =
      e.dataTransfer.getData(COMPONENT_DND_MIME) ||
      e.dataTransfer.getData('application/json');
    if (!data) return;

    try {
      const componentData = JSON.parse(data) as {
        type?: string;
        props?: Record<string, unknown>;
        style?: Record<string, unknown>;
        children?: unknown[];
      };

      if (!componentData?.type || typeof componentData.type !== 'string') {
        return;
      }

      const componentDefinition = CMS_COMPONENTS.find((component) => component.type === componentData.type);
      if (!componentDefinition) {
        console.warn('Ignored dropped component with unknown type:', componentData.type);
        return;
      }

      const node = createNode(
        componentDefinition.type,
        (componentData.props && typeof componentData.props === 'object' ? componentData.props : {}) as Record<string, unknown>,
        (componentData.style && typeof componentData.style === 'object' ? componentData.style : {}) as Record<string, string | number>,
        Array.isArray(componentData.children) ? componentData.children as DiSyLNode[] : []
      );
      handleAddComponent(node);
    } catch (err) {
      console.error('Drop error:', err);
    }
  }, [handleAddComponent]);

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  }, []);

  // Context menu handler
  const handleContextMenu = useCallback((e: React.MouseEvent) => {
    e.preventDefault();
    const target = e.target as HTMLElement;
    const nodeElement = target.closest('[data-node-id]');
    if (nodeElement) {
      const nodeId = nodeElement.getAttribute('data-node-id');
      const nodeType = nodeElement.getAttribute('data-node-type');
      if (nodeId && nodeType) {
        builder.selectNode(nodeId);
        setContextMenu({ x: e.clientX, y: e.clientY, nodeId, nodeType });
      }
    }
  }, [builder]);

  const handleContextMenuClose = useCallback(() => {
    setContextMenu(null);
  }, []);

  const handleMoveNode = useCallback((direction: 'up' | 'down') => {
    if (contextMenu) {
      builder.moveNodeInDirection(contextMenu.nodeId, direction);
    } else if (builder.selectedIds.length > 0) {
      builder.moveNodeInDirection(builder.selectedIds[0], direction);
    }
  }, [contextMenu, builder]);

  // =============================================================================
  // Keyboard Shortcuts
  // =============================================================================

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;

      // Helper to check if user is currently focused in an input field or contenteditable element
      // where we shouldn't intercept standard keyboard navigation, typing, and clipboard events.
      const isInputFocused =
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable ||
        target.closest('[contenteditable="true"]') ||
        target.closest('.tox-tinymce') ||
        target.ownerDocument !== document; // e.g. TinyMCE iframe

      // Save: Ctrl/Cmd + S
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        handleSave();
      }

      // Delete: Delete or Backspace
      if ((e.key === 'Delete' || e.key === 'Backspace') && builder.selectedIds.length > 0) {
        if (isInputFocused) return;
        e.preventDefault();
        builder.selectedIds.forEach(id => builder.deleteNode(id));
      }

      // Escape: Deselect
      if (e.key === 'Escape') {
        builder.deselectAll();
      }

      // Duplicate: Ctrl/Cmd + D
      if ((e.ctrlKey || e.metaKey) && e.key === 'd' && builder.selectedNode) {
        if (isInputFocused) return;
        e.preventDefault();
        builder.duplicateNode(builder.selectedNode.id);
      }

      // Undo: Ctrl/Cmd + Z
      if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
        if (isInputFocused) return;
        e.preventDefault();
        builder.undo();
      }

      // Redo: Ctrl/Cmd + Shift + Z or Ctrl/Cmd + Y
      if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
        if (isInputFocused) return;
        e.preventDefault();
        builder.redo();
      }

      // Copy: Ctrl/Cmd + C
      if ((e.ctrlKey || e.metaKey) && e.key === 'c' && builder.selectedIds.length > 0) {
        if (isInputFocused) return;
        e.preventDefault();
        builder.copyNodes(builder.selectedIds);
      }

      // Paste: Ctrl/Cmd + V
      if ((e.ctrlKey || e.metaKey) && e.key === 'v' && builder.clipboard) {
        if (isInputFocused) return;
        e.preventDefault();
        const parentId = builder.selectedNode?.id || builder.document.id;
        const parent = builder.findNode(parentId);
        const index = parent?.children.length || 0;
        handlePasteNodes(parentId, index);
      }

      // Finder: Ctrl/Cmd + E or Ctrl/Cmd + K
      if ((e.ctrlKey || e.metaKey) && (e.key === 'e' || e.key === 'k')) {
        if (isInputFocused) return;
        e.preventDefault();
        setFinderOpen(true);
        setTimeout(() => finderInputRef.current?.focus(), 0);
      }

      // Keyboard shortcuts: Ctrl/Cmd + / (but not bare ? to allow typing in TinyMCE)
      if ((e.ctrlKey || e.metaKey) && (e.key === '/' || e.key === '?')) {
        if (isInputFocused) return;
        e.preventDefault();
        setShortcutsOpen(prev => !prev);
      }

      // Move Up: Alt + ArrowUp
      if (e.altKey && e.key === 'ArrowUp' && builder.selectedIds.length > 0) {
        if (isInputFocused) return;
        e.preventDefault();
        handleMoveNode('up');
      }

      // Move Down: Alt + ArrowDown
      if (e.altKey && e.key === 'ArrowDown' && builder.selectedIds.length > 0) {
        if (isInputFocused) return;
        e.preventDefault();
        handleMoveNode('down');
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [builder, handlePasteNodes, handleSave, handleMoveNode]);

  // =============================================================================
  // Render
  // =============================================================================

  if (loading) {
    return (
      <div className="h-screen flex items-center justify-center bg-[#1e1e1e]">
        <Loader2 className="w-6 h-6 animate-spin text-white/50" />
      </div>
    );
  }

  if (error || !pageData) {
    return (
      <div className="h-screen flex flex-col items-center justify-center bg-[#1e1e1e] gap-4">
        <AlertCircle className="w-10 h-10 text-red-400" />
        <p className="text-white/70">{error || 'Page not found'}</p>
        <button
          onClick={() => window.history.back()}
          className="px-4 py-2 bg-white/10 text-white/80 hover:bg-white/20 transition-colors text-sm"
        >
          Go Back
        </button>
      </div>
    );
  }

  // Collect all nodes with hover animations for CSS generation
  const collectHoverAnimations = (node: DiSyLNode, result: { id: string; animation: string }[] = []): { id: string; animation: string }[] => {
    const hoverAnim = node.props?.hoverAnimation as string;
    if (hoverAnim) {
      result.push({ id: node.id, animation: hoverAnim });
    }
    if (Array.isArray(node.children)) {
      node.children.forEach((child) => collectHoverAnimations(child, result));
    }
    return result;
  };

  // Generate hover animation CSS rules for the document
  const hoverAnimCSS = (() => {
    if (!builder.document) return '';
    const animations = collectHoverAnimations(builder.document);
    if (!animations.length) return '';

    const hoverEffects: Record<string, { transform?: string; boxShadow?: string; animation?: string; filter?: string }> = {
      grow: { transform: 'scale(1.05)' },
      shrink: { transform: 'scale(0.95)' },
      lift: { transform: 'translateY(-8px)', boxShadow: '0 15px 35px rgba(0,0,0,0.2)' },
      float: { transform: 'translateY(-5px)' },
      pulse: { animation: 'cms-pulse 0.4s ease' },
      bob: { animation: 'cms-bob 0.5s ease-in-out infinite' },
      shake: { animation: 'cms-shake 0.5s ease-in-out' },
      glow: { boxShadow: '0 0 20px rgba(255,255,255,0.5), 0 0 40px rgba(100,100,255,0.3)', filter: 'brightness(1.1)' },
      shadow: { boxShadow: '0 10px 30px rgba(0,0,0,0.15)' },
      shadowGrow: { transform: 'scale(1.05)', boxShadow: '0 15px 40px rgba(0,0,0,0.25)' },
    };

    return animations.map(({ id, animation }) => {
      const effect = hoverEffects[animation];
      if (!effect) return '';
      const hoverProps = [
        effect.transform && `transform: ${effect.transform}`,
        effect.boxShadow && `box-shadow: ${effect.boxShadow}`,
        effect.animation && `animation: ${effect.animation}`,
        effect.filter && `filter: ${effect.filter}`,
      ].filter(Boolean).join('; ');
      return `[data-node-id="${id}"]:hover { ${hoverProps}; }`;
    }).join('\n');
  })();

  // Animation keyframes and rich text styles CSS
  const builderStyles = `
    /* Animation Keyframes - Entrance */
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeInDown { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeInLeft { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes fadeInRight { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes zoomIn { from { opacity: 0; transform: scale(0.85); } to { opacity: 1; transform: scale(1); } }
    @keyframes slideInUp { from { opacity: 0; transform: translateY(60px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideInDown { from { opacity: 0; transform: translateY(-60px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideInLeft { from { opacity: 0; transform: translateX(-60px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes slideInRight { from { opacity: 0; transform: translateX(60px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes bounceIn { 0% { opacity: 0; transform: scale(0.7); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
    @keyframes flipInX { from { opacity: 0; transform: perspective(400px) rotateX(90deg); } to { opacity: 1; transform: perspective(400px) rotateX(0); } }
    @keyframes flipInY { from { opacity: 0; transform: perspective(400px) rotateY(90deg); } to { opacity: 1; transform: perspective(400px) rotateY(0); } }
    @keyframes rotateIn { from { opacity: 0; transform: rotate(-120deg); } to { opacity: 1; transform: rotate(0); } }
    
    /* Animation Keyframes - Hover */
    @keyframes cms-pulse { 0%{ transform: scale(1); } 25%{ transform: scale(1.08); } 50%{ transform: scale(0.95); } 75%{ transform: scale(1.02); } 100%{ transform: scale(1); } }
    @keyframes cms-bob { 0%, 100%{ transform: translateY(0); } 50%{ transform: translateY(-6px); } }
    @keyframes cms-shake { 0%, 100%{ transform: translateX(0); } 10%, 30%, 50%, 70%, 90%{ transform: translateX(-4px); } 20%, 40%, 60%, 80%{ transform: translateX(4px); } }
    
    /* Hover animation rules - dynamically generated */
    ${hoverAnimCSS}
    
    /* Layout Component Visual Cues */
    .builder-canvas .pb-section {
      outline: 1px dashed rgba(156, 163, 175, 0.3);
      outline-offset: -1px;
      min-height: 50px;
    }
    
    .builder-canvas .pb-container {
      outline: 1px dashed rgba(156, 163, 175, 0.25);
      outline-offset: -1px;
      min-height: 40px;
    }
    
    .builder-canvas .pb-row {
      outline: 1px dashed rgba(156, 163, 175, 0.2);
      outline-offset: -1px;
      min-height: 30px;
    }
    
    .builder-canvas .pb-column {
      outline: 1px dotted rgba(156, 163, 175, 0.2);
      outline-offset: -1px;
      min-height: 30px;
    }
    
    /* Rich Text Content Styles - Preserve TinyMCE formatting */
    .builder-canvas h1, .builder-canvas h2, .builder-canvas h3, 
    .builder-canvas h4, .builder-canvas h5, .builder-canvas h6 {
      display: block;
      font-weight: bold;
      margin: 0.5em 0;
    }
    .builder-canvas h1 { font-size: 2em; }
    .builder-canvas h2 { font-size: 1.5em; }
    .builder-canvas h3 { font-size: 1.17em; }
    .builder-canvas h4 { font-size: 1em; }
    .builder-canvas h5 { font-size: 0.83em; }
    .builder-canvas h6 { font-size: 0.67em; }
    .builder-canvas p { display: block; margin: 0 0 0.75em 0; }
    .builder-canvas strong, .builder-canvas b { font-weight: bold; }
    .builder-canvas em, .builder-canvas i { font-style: italic; }
    .builder-canvas u { text-decoration: underline; }
    .builder-canvas ul, .builder-canvas ol { margin: 0.5em 0; padding-left: 1.5em; }
    .builder-canvas ul { list-style-type: disc; }
    .builder-canvas ol { list-style-type: decimal; }
    .builder-canvas li { display: list-item; margin: 0.25em 0; }
    .builder-canvas a { color: #0078d4; text-decoration: underline; }
    .builder-canvas blockquote { margin: 1em 0; padding-left: 1em; border-left: 3px solid #ccc; }
    .builder-canvas pre, .builder-canvas code { font-family: monospace; background: rgba(0,0,0,0.05); }
    .builder-canvas pre { padding: 1em; overflow-x: auto; }
    .builder-canvas code { padding: 0.2em 0.4em; }
    
    /* Ensure inline styles from TinyMCE are respected */
    .builder-canvas [style*="text-align: center"] { text-align: center !important; }
    .builder-canvas [style*="text-align: right"] { text-align: right !important; }
    .builder-canvas [style*="text-align: left"] { text-align: left !important; }
    .builder-canvas [style*="text-align: justify"] { text-align: justify !important; }
  `;

  return (
    <div className="h-screen flex flex-col bg-[#1e1e1e]">
      {/* Builder Styles (animations + rich text) */}
      <style dangerouslySetInnerHTML={{ __html: builderStyles }} />

      {/* Top Bar - Dark professional header */}
      <header className="h-12 bg-[#252526] border-b border-[#3c3c3c] flex items-center justify-between px-3 flex-shrink-0">
        {/* Left: Back + Title */}
        <div className="flex items-center gap-2">
          <button
            onClick={() => window.history.back()}
            className="p-1.5 hover:bg-white/10 transition-colors"
            title="Back to pages"
          >
            <ArrowLeft className="w-4 h-4 text-white/70" />
          </button>
          <div className="h-4 w-px bg-white/20" />
          <div className="flex items-center gap-2">
            {isEditingTitle ? (
              <input
                ref={titleInputRef}
                type="text"
                value={editingTitleValue}
                onChange={(e) => setEditingTitleValue(e.target.value)}
                onBlur={handleSaveTitle}
                onKeyDown={handleTitleKeyDown}
                className="text-sm font-medium text-white bg-[#1e1e1e] border border-[#0078d4] px-2 py-0.5 outline-none w-[200px]"
                placeholder="Page title..."
              />
            ) : (
              <button
                onClick={handleStartEditTitle}
                className="flex items-center gap-1.5 text-sm font-medium text-white/90 max-w-[200px] truncate hover:text-white group"
                title="Click to edit title"
              >
                {pageData.title || 'Untitled Page'}
                <Pencil className="w-3 h-3 text-white/30 group-hover:text-white/70" />
              </button>
            )}
            <span className={`text-[10px] px-1.5 py-0.5 uppercase tracking-wide ${pageData.status === 'published'
              ? 'bg-emerald-500/20 text-emerald-400'
              : 'bg-amber-500/20 text-amber-400'
              }`}>
              {pageData.status}
            </span>
          </div>
        </div>

        {/* Center: Tools */}
        <div className="flex items-center gap-1">
          {/* Undo/Redo */}
          <div className="flex items-center border-r border-white/10 pr-2 mr-2">
            <button
              onClick={builder.undo}
              disabled={!builder.canUndo}
              className="p-1.5 hover:bg-white/10 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              title="Undo (Ctrl+Z)"
            >
              <Undo2 className="w-4 h-4 text-white/70" />
            </button>
            <button
              onClick={builder.redo}
              disabled={!builder.canRedo}
              className="p-1.5 hover:bg-white/10 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              title="Redo (Ctrl+Shift+Z)"
            >
              <Redo2 className="w-4 h-4 text-white/70" />
            </button>
          </div>

          {/* Viewport Switcher */}
          <div className="flex items-center bg-[#1e1e1e] p-0.5">
            <button
              onClick={() => builder.setViewport('desktop')}
              className={`p-1.5 transition-colors ${builder.viewport === 'desktop' ? 'bg-[#0078d4] text-white' : 'text-white/50 hover:text-white/80'
                }`}
              title="Desktop"
            >
              <Monitor className="w-4 h-4" />
            </button>
            <button
              onClick={() => builder.setViewport('tablet')}
              className={`p-1.5 transition-colors ${builder.viewport === 'tablet' ? 'bg-[#0078d4] text-white' : 'text-white/50 hover:text-white/80'
                }`}
              title="Tablet"
            >
              <Tablet className="w-4 h-4" />
            </button>
            <button
              onClick={() => builder.setViewport('mobile')}
              className={`p-1.5 transition-colors ${builder.viewport === 'mobile' ? 'bg-[#0078d4] text-white' : 'text-white/50 hover:text-white/80'
                }`}
              title="Mobile"
            >
              <Smartphone className="w-4 h-4" />
            </button>
          </div>

          {/* Zoom */}
          <div className="flex items-center border-l border-white/10 pl-2 ml-2">
            <button
              onClick={() => setZoom(Math.max(50, zoom - 10))}
              className="p-1.5 hover:bg-white/10 transition-colors"
              title="Zoom out"
            >
              <ZoomOut className="w-4 h-4 text-white/70" />
            </button>
            <span className="text-xs text-white/50 w-10 text-center">{zoom}%</span>
            <button
              onClick={() => setZoom(Math.min(150, zoom + 10))}
              className="p-1.5 hover:bg-white/10 transition-colors"
              title="Zoom in"
            >
              <ZoomIn className="w-4 h-4 text-white/70" />
            </button>
          </div>
        </div>

        {/* Right: Actions */}
        <div className="flex items-center gap-2">
          {/* Save indicator */}
          {saveMessage && (
            <span className={`text-xs flex items-center gap-1 ${saveMessage.type === 'success' ? 'text-emerald-400' : 'text-red-400'
              }`}>
              {saveMessage.type === 'success' ? <Check className="w-3 h-3" /> : <AlertCircle className="w-3 h-3" />}
              {saveMessage.text}
            </span>
          )}

          {(builder.isDirty || hasUnsavedSettings) && (
            <span className="text-[10px] text-amber-400 bg-amber-500/20 px-1.5 py-0.5">
              Unsaved
            </span>
          )}

          {!builder.isDirty && !hasUnsavedSettings && lastAutoSave && (
            <span className="text-[10px] text-white/40">
              Auto-saved {lastAutoSave.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
            </span>
          )}

          {/* Structure Mode Toggle */}
          <button
            onClick={() => setStructureMode(!structureMode)}
            className={`p-1.5 transition-colors ${structureMode
              ? 'text-[#0078d4] bg-[#0078d4]/20'
              : 'text-white/50 hover:text-white hover:bg-white/10'
              }`}
            title={structureMode ? 'Normal View' : 'Structure Mode'}
          >
            <Layers className="w-4 h-4" />
          </button>

          {/* Version History */}
          <button
            onClick={() => setVersionHistoryOpen(true)}
            className="p-1.5 text-white/50 hover:text-white hover:bg-white/10 transition-colors"
            title="Version History"
          >
            <Clock className="w-4 h-4" />
          </button>

          {/* Save as Template */}
          <button
            onClick={() => setSaveTemplateModalOpen(true)}
            className="p-1.5 text-white/50 hover:text-white hover:bg-white/10 transition-colors"
            title="Save as Template"
            disabled={!builder.document}
          >
            <FileText className="w-4 h-4" />
          </button>

          {/* Preview */}
          <button
            onClick={() => pageData.id > 0 && window.open(`/api/v1/cms/content/${pageData.id}/builder/preview`, '_blank')}
            disabled={pageData.id <= 0}
            className="flex items-center gap-1.5 px-3 py-1.5 text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Eye className="w-4 h-4" />
            Preview
          </button>

          {/* Save */}
          <button
            onClick={handleSave}
            disabled={saving || (!builder.isDirty && !hasUnsavedSettings)}
            className="flex items-center gap-1.5 px-4 py-1.5 bg-[#0078d4] text-white hover:bg-[#006cbd] transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
          >
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
            Save
          </button>
        </div>
      </header>

      {/* Main Content - NEW LAYOUT: Left=Properties, Center=Canvas, Right=Navigator+Global, Bottom=Components */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Top row: Left Panel + Canvas + Right Panel */}
        <div className="flex-1 flex overflow-hidden">
          {/* Left Sidebar - Properties Panel (Context-Sensitive) */}
          <aside className={`bg-[#252526] border-r border-[#3c3c3c] flex flex-col flex-shrink-0 transition-all ${leftPanelCollapsed ? 'w-10' : 'w-80'
            }`}>
            {/* Panel Header */}
            <div className="h-9 flex items-center justify-between px-3 border-b border-[#3c3c3c]">
              {!leftPanelCollapsed && (
                <span className="text-xs text-white/70 font-medium">
                  {builder.selectedNode ? (
                    <>Properties: <span className="text-white">{builder.selectedNode.type}</span></>
                  ) : (
                    'No Selection'
                  )}
                </span>
              )}
              <button
                onClick={() => setLeftPanelCollapsed(!leftPanelCollapsed)}
                className="p-1 hover:bg-white/10 transition-colors ml-auto"
              >
                {leftPanelCollapsed ? (
                  <Maximize2 className="w-3.5 h-3.5 text-white/50" />
                ) : (
                  <Minimize2 className="w-3.5 h-3.5 text-white/50" />
                )}
              </button>
            </div>

            {/* Panel Content - Properties */}
            {!leftPanelCollapsed && (
              <div className="flex-1 overflow-hidden">
                {builder.selectedNode ? (
                  <PropertiesPanel
                    node={builder.selectedNode}
                    onUpdateProps={builder.updateProps}
                    onUpdateStyle={builder.updateStyle}
                    viewport={builder.viewport}
                    onViewportChange={builder.setViewport}
                  />
                ) : (
                  <div className="flex flex-col items-center justify-center h-full text-center p-6">
                    <div className="w-12 h-12 rounded-full bg-white/5 flex items-center justify-center mb-4">
                      <Layers className="w-6 h-6 text-white/30" />
                    </div>
                    <p className="text-sm text-white/50 mb-2">No element selected</p>
                    <p className="text-xs text-white/30">Click an element on the canvas to edit its properties</p>
                  </div>
                )}
              </div>
            )}
          </aside>

          {/* Canvas Area */}
          <main
            className="flex-1 overflow-auto bg-[#1e1e1e] relative flex flex-col"
            onDrop={handleDrop}
            onDragOver={handleDragOver}
            onClick={() => { builder.deselectAll(); setContextMenu(null); }}
          >
            {/* Breadcrumb Navigation */}
            {builder.selectedNodePath.length > 0 && (
              <div
                className="h-8 bg-[#252526] border-b border-[#3c3c3c] flex items-center px-3 gap-1 text-xs flex-shrink-0"
                onClick={(e) => e.stopPropagation()}
              >
                {builder.selectedNodePath.map((node, index) => (
                  <span key={node.id} className="flex items-center gap-1">
                    {index > 0 && <span className="text-white/30">›</span>}
                    <button
                      onClick={() => builder.selectNode(node.id, false)}
                      className={`px-1.5 py-0.5 rounded transition-colors ${index === builder.selectedNodePath.length - 1
                        ? 'text-[#0078d4] bg-[#0078d4]/10'
                        : 'text-white/60 hover:text-white hover:bg-white/10'
                        }`}
                    >
                      {node.meta?.name || node.type.charAt(0).toUpperCase() + node.type.slice(1).replace(/_/g, ' ')}
                    </button>
                  </span>
                ))}
              </div>
            )}

            {/* Canvas Content */}
            <div
              className="flex-1 overflow-auto"
              onContextMenu={handleContextMenu}
            >
              {/* Canvas Container */}
              <div className="min-h-full p-8 flex items-start justify-center">
                <div
                  ref={canvasRef}
                  className={`builder-canvas ${BUILDER_PREVIEW_SCOPE_CLASS} bg-white shadow-2xl transition-all origin-top ${builder.viewport === 'desktop' ? 'w-full max-w-[1400px]' :
                    builder.viewport === 'tablet' ? 'w-[768px]' :
                      'w-[375px]'
                    }`}
                  style={{
                    minHeight: 'auto',
                    transform: `scale(${zoom / 100})`,
                    paddingBottom: '200px', // Extra space at bottom for adding more content
                  }}
                >
                  {(previewThemeCss !== '' || hasGlobalStyleOverrides(pageStyleOverrides)) && (
                    <style>{previewThemeCss + pageOverrideCss}</style>
                  )}
                  <div style={previewShellStyle}>
                    {/* Render document */}
                    <NodeRenderer
                      node={builder.document}
                      viewport={builder.viewport}
                      isSelected={builder.selectedIds.includes(builder.document.id)}
                      isHovered={builder.hoveredId === builder.document.id}
                      structureMode={structureMode}
                      onSelect={builder.selectNode}
                      onHover={builder.hoverNode}
                      onContentChange={(nodeId, content) => builder.updateProps(nodeId, { content })}
                      onPropsChange={(nodeId, props) => builder.updateProps(nodeId, props)}
                      onMoveNode={handleSmartMoveNode}
                      onStyleChange={builder.updateStyle}
                      onAddColumnToRow={handleAddColumnToRow}
                      selectedIds={builder.selectedIds}
                    />

                    {/* Empty state - show when document has no sections or all sections are empty */}
                    {(builder.document.children.length === 0 ||
                      (builder.document.children.length === 1 && builder.document.children[0].children.length === 0)) && (
                        <div className="flex flex-col items-center justify-center py-24 text-center px-8">
                          {/* Animated icon */}
                          <div className="relative mb-8">
                            <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/20 to-purple-500/20 flex items-center justify-center">
                              <Plus className="w-10 h-10 text-blue-500" />
                            </div>
                            <div className="absolute -top-1 -right-1 w-4 h-4 bg-blue-500 rounded-full animate-pulse" />
                          </div>

                          <h3 className="text-xl font-semibold text-gray-700 mb-2">
                            Start Building Your Page
                          </h3>
                          <p className="text-sm text-gray-500 max-w-sm mb-8">
                            Choose a template to get started quickly, or add sections manually from the component drawer below.
                          </p>

                          {/* Quick action buttons */}
                          <div className="flex flex-col sm:flex-row gap-3 mb-8">
                            <button
                              onClick={() => setRightPanelTab('templates')}
                              className="flex items-center justify-center gap-2 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium shadow-lg shadow-blue-500/25"
                            >
                              <FileText className="w-4 h-4" />
                              Browse Templates
                            </button>
                            <button
                              onClick={() => {
                                // Add a blank section
                                const newSection = createNode('section', {}, {
                                  padding: '64px 24px',
                                  minHeight: '300px',
                                  backgroundColor: '#ffffff',
                                });
                                insertNodeWithGovernedFeedback(newSection, builder.document.id, builder.document.children.length, 'Cannot add');
                              }}
                              className="flex items-center justify-center gap-2 px-6 py-3 bg-white text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium border border-gray-200 shadow-sm"
                            >
                              <Plus className="w-4 h-4" />
                              Add Blank Section
                            </button>
                          </div>

                          {/* Keyboard shortcut hint */}
                          <div className="flex items-center gap-2 text-xs text-gray-400">
                            <span>Pro tip: Press</span>
                            <kbd className="px-2 py-0.5 bg-gray-100 rounded text-gray-500 font-mono">⌘</kbd>
                            <span>+</span>
                            <kbd className="px-2 py-0.5 bg-gray-100 rounded text-gray-500 font-mono">K</kbd>
                            <span>to open the command finder</span>
                          </div>
                        </div>
                      )}
                  </div>
                </div>
              </div>
            </div>

            {/* Context Menu */}
            {contextMenu && (
              (() => {
                const pasteReason = governedPasteBlockReason(contextMenu.nodeId);
                return (
                  <ContextMenu
                    x={contextMenu.x}
                    y={contextMenu.y}
                    nodeId={contextMenu.nodeId}
                    nodeType={contextMenu.nodeType}
                    onClose={handleContextMenuClose}
                    onCopy={() => builder.copyNodes([contextMenu.nodeId])}
                    onPaste={() => {
                      const parent = builder.findNode(contextMenu.nodeId);
                      if (parent) {
                        handlePasteNodes(contextMenu.nodeId, parent.children.length);
                      }
                    }}
                    onDuplicate={() => builder.duplicateNode(contextMenu.nodeId)}
                    onDelete={() => builder.deleteNode(contextMenu.nodeId)}
                    onMoveUp={() => handleMoveNode('up')}
                    onMoveDown={() => handleMoveNode('down')}
                    onSaveAsBlock={() => {
                      const node = builder.findNode(contextMenu.nodeId);
                      if (node && ['section', 'container', 'layout_container'].includes(node.type)) {
                        setBlockToSave(node);
                        setSaveBlockModalOpen(true);
                      }
                    }}
                    canPaste={pasteReason === null}
                    canPasteReason={pasteReason}
                    canMoveUp={(() => {
                      const parent = builder.findParent(contextMenu.nodeId);
                      if (!parent) return false;
                      const idx = parent.children.findIndex(c => c.id === contextMenu.nodeId);
                      return idx > 0;
                    })()}
                    canMoveDown={(() => {
                      const parent = builder.findParent(contextMenu.nodeId);
                      if (!parent) return false;
                      const idx = parent.children.findIndex(c => c.id === contextMenu.nodeId);
                      return idx < parent.children.length - 1;
                    })()}
                  />
                );
              })()
            )}
          </main>

          {/* Right Sidebar - Navigator + Global Settings */}
          <aside className={`bg-[#252526] border-l border-[#3c3c3c] flex flex-col flex-shrink-0 transition-all ${rightPanelCollapsed ? 'w-10' : 'w-72'
            }`}>
            {/* Panel Header */}
            <div className="h-9 flex items-center justify-between px-3 border-b border-[#3c3c3c]">
              <button
                onClick={() => setRightPanelCollapsed(!rightPanelCollapsed)}
                className="p-1 hover:bg-white/10 transition-colors"
              >
                {rightPanelCollapsed ? (
                  <Maximize2 className="w-3.5 h-3.5 text-white/50" />
                ) : (
                  <Minimize2 className="w-3.5 h-3.5 text-white/50" />
                )}
              </button>
              {!rightPanelCollapsed && (
                <div className="flex items-center gap-1">
                  <button
                    onClick={() => setRightPanelTab('navigator')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'navigator'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    Navigator
                  </button>
                  <button
                    onClick={() => setRightPanelTab('templates')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'templates'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    Templates
                  </button>
                  <button
                    onClick={() => setRightPanelTab('blocks')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'blocks'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    Blocks
                  </button>
                  <button
                    onClick={() => setRightPanelTab('global')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'global'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    Global
                  </button>
                  <button
                    onClick={() => setRightPanelTab('seo')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'seo'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    SEO
                  </button>
                  <button
                    onClick={() => setRightPanelTab('capabilities')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'capabilities'
                      ? 'text-white bg-[#0078d4]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                  >
                    Features
                  </button>
                  <button
                    onClick={() => setRightPanelTab('governed')}
                    className={`px-2 py-1 text-xs transition-colors ${rightPanelTab === 'governed'
                      ? 'text-white bg-[#6366f1]'
                      : 'text-white/60 hover:text-white/90'
                      }`}
                    title="Governed DiSyL components"
                  >
                    Governed
                  </button>
                </div>
              )}
            </div>

            {/* Panel Content */}
            {!rightPanelCollapsed && (
              <>
                {rightPanelTab === 'navigator' && (
                  <LayersPanel
                    document={builder.document}
                    selectedIds={builder.selectedIds}
                    hoveredId={builder.hoveredId}
                    onSelect={builder.selectNode}
                    onHover={builder.hoverNode}
                    onDelete={builder.deleteNode}
                    onDuplicate={builder.duplicateNode}
                    onMoveNode={builder.moveNodeInDirection}
                    onDragMoveNode={handleSmartMoveNode}
                  />
                )}
                {rightPanelTab === 'templates' && (
                  <TemplatesPanel
                    onInsertTemplate={(node) => {
                      const nodesToInsert = node.type === 'document' ? (Array.isArray(node.children) ? node.children : []) : [node];
                      nodesToInsert.forEach((child, index) => {
                        insertNodeWithGovernedFeedback(child, builder.document.id, builder.document.children.length + index, 'Cannot insert template element');
                      });
                    }}
                  />
                )}
                {rightPanelTab === 'blocks' && (
                  <BlocksPanel
                    onInsertBlock={(node) => {
                      // Insert block as a new section in the document
                      const blockNode = node.type === 'section'
                        ? node
                        : createNode('section', {}, { padding: '48px 24px' }, [
                          node.type === 'layout_container' ? createNode('container', {}, {}, [node]) : node,
                        ]);
                      insertNodeWithGovernedFeedback(blockNode, builder.document.id, builder.document.children.length, 'Cannot insert block');
                    }}
                  />
                )}
                {rightPanelTab === 'global' && (
                  <GlobalStylesPanel
                    styles={globalStyles}
                    onUpdateStyles={setGlobalStyles}
                  />
                )}
                {rightPanelTab === 'seo' && (
                  <SEOPanel
                    settings={seoSettings}
                    onUpdateSettings={setSeoSettings}
                    pageTitle={pageData.title}
                    pageUrl={pageData.slug ? `/${pageData.slug}` : ''}
                  />
                )}
                {rightPanelTab === 'capabilities' && pageData && pageData.id > 0 && (
                  <CapabilityPanel contentId={pageData.id} />
                )}
                {rightPanelTab === 'governed' && (
                  <GovernedComponentsPanel onAddComponent={handleAddComponent} />
                )}
              </>
            )}
          </aside>
        </div>

        {/* Bottom Component Drawer */}
        <div className={`bg-[#252526] border-t border-[#3c3c3c] transition-all ${componentDrawerOpen ? 'h-20' : 'h-8'
          }`}>
          {/* Drawer Header */}
          <div
            className="h-8 flex items-center justify-between px-3 cursor-pointer hover:bg-white/5"
            onClick={() => setComponentDrawerOpen(!componentDrawerOpen)}
          >
            <span className="text-xs text-white/70 font-medium">Components</span>
            <div className="flex items-center gap-2">
              <span className="text-[10px] text-white/40">Drag to canvas</span>
              {componentDrawerOpen ? (
                <Minimize2 className="w-3.5 h-3.5 text-white/50" />
              ) : (
                <Maximize2 className="w-3.5 h-3.5 text-white/50" />
              )}
            </div>
          </div>

          {/* Drawer Content */}
          {componentDrawerOpen && (
            <div className="h-12 overflow-x-auto overflow-y-hidden">
              <ComponentPanelEnhanced onAddComponent={handleAddComponent} horizontal />
            </div>
          )}
        </div>
      </div>

      {/* Version History Modal */}
      <VersionHistory
        contentId={pageData.id}
        isOpen={versionHistoryOpen}
        onClose={() => setVersionHistoryOpen(false)}
        onRestore={handleRestoreVersion}
        currentContent={JSON.stringify(builder.document)}
      />

      {/* Save as Template Modal */}
      {builder.document && (
        <SaveTemplateModal
          isOpen={saveTemplateModalOpen}
          onClose={() => setSaveTemplateModalOpen(false)}
          content={builder.document}
          globalStyles={globalStyles}
          onSuccess={(template) => {
            setSaveMessage({ type: 'success', text: `Template "${template.name}" saved!` });
            setTimeout(() => setSaveMessage(null), 3000);
          }}
        />
      )}

      {/* Save as Block Modal */}
      {blockToSave && (
        <SaveBlockModal
          isOpen={saveBlockModalOpen}
          onClose={() => {
            setSaveBlockModalOpen(false);
            setBlockToSave(null);
          }}
          node={blockToSave}
          onSuccess={(block) => {
            setSaveMessage({ type: 'success', text: `Block "${block.name}" saved!` });
            setTimeout(() => setSaveMessage(null), 3000);
            setSaveBlockModalOpen(false);
            setBlockToSave(null);
          }}
        />
      )}

      {/* Onboarding Tooltips */}
      <OnboardingTooltips />

      {/* Keyboard Shortcuts Help */}
      {shortcutsOpen && (
        <KeyboardShortcutsPanel onClose={() => setShortcutsOpen(false)} />
      )}

      {/* Finder Modal (Cmd+E / Cmd+K) */}
      {finderOpen && (
        <FinderModal
          isOpen={finderOpen}
          onClose={() => {
            setFinderOpen(false);
            setFinderQuery('');
          }}
          query={finderQuery}
          onQueryChange={setFinderQuery}
          inputRef={finderInputRef}
          document={builder.document}
          onSelectNode={(nodeId) => {
            builder.selectNode(nodeId);
            setFinderOpen(false);
            setFinderQuery('');
          }}
        />
      )}
    </div>
  );
}

// =============================================================================
// Finder Modal Component
// =============================================================================

interface FinderModalProps {
  isOpen: boolean;
  onClose: () => void;
  query: string;
  onQueryChange: (query: string) => void;
  inputRef: React.RefObject<HTMLInputElement>;
  document: DiSyLNode;
  onSelectNode: (nodeId: string) => void;
}

// =============================================================================
// Keyboard Shortcuts Panel Component
// =============================================================================

interface KeyboardShortcutsPanelProps {
  onClose: () => void;
}

const SHORTCUTS = [
  {
    category: 'General', items: [
      { keys: ['⌘', 'S'], description: 'Save page' },
      { keys: ['⌘', 'Z'], description: 'Undo' },
      { keys: ['⌘', '⇧', 'Z'], description: 'Redo' },
      { keys: ['⌘', 'E'], description: 'Find element' },
      { keys: ['⌘', '/'], description: 'Toggle shortcuts' },
    ]
  },
  {
    category: 'Selection', items: [
      { keys: ['Esc'], description: 'Deselect all' },
      { keys: ['Del'], description: 'Delete selected' },
      { keys: ['⌘', 'D'], description: 'Duplicate selected' },
    ]
  },
  {
    category: 'Clipboard', items: [
      { keys: ['⌘', 'C'], description: 'Copy element' },
      { keys: ['⌘', 'V'], description: 'Paste element' },
    ]
  },
];

function KeyboardShortcutsPanel({ onClose }: KeyboardShortcutsPanelProps) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center"
      onClick={onClose}
    >
      <div className="absolute inset-0 bg-black/60" />
      <div
        className="relative w-full max-w-md bg-[#252526] border border-[#3c3c3c] shadow-2xl rounded-lg overflow-hidden"
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <div className="flex items-center gap-2">
            <Keyboard className="w-4 h-4 text-[#0078d4]" />
            <h3 className="text-sm font-medium text-white">Keyboard Shortcuts</h3>
          </div>
          <button
            onClick={onClose}
            className="p-1 hover:bg-white/10 rounded transition-colors"
          >
            <X className="w-4 h-4 text-white/50" />
          </button>
        </div>

        {/* Content */}
        <div className="p-4 max-h-[400px] overflow-y-auto">
          {SHORTCUTS.map(section => (
            <div key={section.category} className="mb-4 last:mb-0">
              <h4 className="text-[10px] font-medium text-white/40 uppercase tracking-wider mb-2">
                {section.category}
              </h4>
              <div className="space-y-2">
                {section.items.map((shortcut, idx) => (
                  <div key={idx} className="flex items-center justify-between">
                    <span className="text-sm text-white/70">{shortcut.description}</span>
                    <div className="flex items-center gap-1">
                      {shortcut.keys.map((key, keyIdx) => (
                        <kbd
                          key={keyIdx}
                          className="px-1.5 py-0.5 text-[10px] font-medium bg-[#1e1e1e] border border-[#3c3c3c] rounded text-white/60"
                        >
                          {key}
                        </kbd>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        {/* Footer */}
        <div className="px-4 py-2 border-t border-[#3c3c3c] text-center">
          <span className="text-[10px] text-white/30">Press Esc or ⌘/ to close</span>
        </div>
      </div>
    </div>
  );
}

function FinderModal({ isOpen, onClose, query, onQueryChange, inputRef, document, onSelectNode }: FinderModalProps) {
  // Flatten all nodes for searching
  const flattenNodes = (node: DiSyLNode, depth = 0): Array<{ node: DiSyLNode; depth: number; path: string[] }> => {
    const result: Array<{ node: DiSyLNode; depth: number; path: string[] }> = [];

    const traverse = (n: DiSyLNode, d: number, p: string[]) => {
      const label = n.meta?.name || n.props.content?.toString().slice(0, 30) || n.type;
      result.push({ node: n, depth: d, path: [...p, label] });
      n.children.forEach(child => traverse(child, d + 1, [...p, label]));
    };

    traverse(node, depth, []);
    return result;
  };

  const allNodes = flattenNodes(document);

  // Filter nodes based on query
  const filteredNodes = query.trim()
    ? allNodes.filter(({ node, path }) => {
      const searchText = [
        node.type,
        node.meta?.name,
        node.props.content,
        node.props.title,
        node.props.alt,
        path.join(' '),
      ].filter(Boolean).join(' ').toLowerCase();
      return searchText.includes(query.toLowerCase());
    })
    : allNodes.slice(0, 20); // Show first 20 when no query

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      onClose();
    } else if (e.key === 'Enter' && filteredNodes.length > 0) {
      onSelectNode(filteredNodes[0].node.id);
    }
  };

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
      onClick={onClose}
    >
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/60" />

      {/* Modal */}
      <div
        className="relative w-full max-w-lg bg-[#252526] border border-[#3c3c3c] shadow-2xl rounded-lg overflow-hidden"
        onClick={e => e.stopPropagation()}
      >
        {/* Search Input */}
        <div className="flex items-center gap-3 px-4 py-3 border-b border-[#3c3c3c]">
          <Search className="w-5 h-5 text-white/40" />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => onQueryChange(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Search elements..."
            className="flex-1 bg-transparent text-white text-sm outline-none placeholder-white/40"
            autoFocus
          />
          <div className="flex items-center gap-1 text-[10px] text-white/30">
            <Command className="w-3 h-3" />
            <span>E</span>
          </div>
          <button
            onClick={onClose}
            className="p-1 hover:bg-white/10 rounded transition-colors"
          >
            <X className="w-4 h-4 text-white/50" />
          </button>
        </div>

        {/* Results */}
        <div className="max-h-[300px] overflow-y-auto">
          {filteredNodes.length === 0 ? (
            <div className="px-4 py-8 text-center text-white/40 text-sm">
              No elements found
            </div>
          ) : (
            <div className="py-2">
              {filteredNodes.map(({ node, depth, path }) => (
                <button
                  key={node.id}
                  onClick={() => onSelectNode(node.id)}
                  className="w-full px-4 py-2 flex items-center gap-3 hover:bg-white/5 transition-colors text-left"
                  style={{ paddingLeft: `${16 + depth * 12}px` }}
                >
                  <span className="text-[10px] px-1.5 py-0.5 bg-[#0078d4]/20 text-[#0078d4] rounded uppercase">
                    {node.type}
                  </span>
                  <span className="text-sm text-white/80 truncate flex-1">
                    {node.meta?.name || node.props.content?.toString().slice(0, 40) || node.props.title || `${node.type} element`}
                  </span>
                  <span className="text-[10px] text-white/30 truncate max-w-[150px]">
                    {path.slice(0, -1).join(' › ')}
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-4 py-2 border-t border-[#3c3c3c] flex items-center justify-between text-[10px] text-white/30">
          <span>{filteredNodes.length} element{filteredNodes.length !== 1 ? 's' : ''}</span>
          <div className="flex items-center gap-3">
            <span>↵ Select</span>
            <span>Esc Close</span>
          </div>
        </div>
      </div>
    </div>
  );
}
