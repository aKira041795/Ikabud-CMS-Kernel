-- Migration 023: Rename compound capability IDs to snake_case
-- Renames: progresstracking → progress_tracking
--          lessonsindex     → lessons_index
--          mediagallery     → media_gallery
--
-- Affects: cms_entity_capabilities.capability_id (the stored type key)
-- Safe to run multiple times (WHERE guards prevent double-application).

UPDATE cms_entity_capabilities
SET capability_id = 'progress_tracking'
WHERE capability_id = 'progresstracking';

UPDATE cms_entity_capabilities
SET capability_id = 'lessons_index'
WHERE capability_id = 'lessonsindex';

UPDATE cms_entity_capabilities
SET capability_id = 'media_gallery'
WHERE capability_id = 'mediagallery';
