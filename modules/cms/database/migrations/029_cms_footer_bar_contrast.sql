-- ═══════════════════════════════════════════════════════════════
-- CMS customizer — corrective data migration for the footer bottom bar
--
-- The ARK theme publishes an accessibility contract in its own manifest
-- (accessibility.contrast_ratio = "4.5:1"). Its customizer schema default for
-- the bottom bar's non-link text was #64748b, which measures only 3.75:1
-- against the bar background (#0f172a) — the theme was declaring a contrast
-- floor it did not actually meet.
--
-- The default is corrected to #94a3b8 (6.96:1, the theme's text_muted token)
-- in storage/cms-themes/ark/customizer.schema.json, so NEW installs are
-- correct from the start. This migration exists only because the customizer
-- SEEDS its schema defaults into cms_theme_customizer on first use, so an
-- install that was already seeded keeps the superseded value and never sees
-- the corrected schema default.
--
-- Scope is deliberately narrow: only rows whose bar_text_color still equals
-- the superseded default are touched. A site owner who deliberately chose a
-- different colour is left alone, and no other colour is modified.
--
-- JSON_SET / JSON_EXTRACT are both available in MySQL 5.7 (the Bluehost
-- compatibility profile). No window functions, CTEs or JSON_TABLE are used.
-- ═══════════════════════════════════════════════════════════════

UPDATE cms_theme_customizer
SET settings_json = JSON_SET(settings_json, '$.bar_text_color', '#94a3b8')
WHERE JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.bar_text_color')) = '#64748b';
