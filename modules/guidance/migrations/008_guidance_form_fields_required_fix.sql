-- Migration 008: Fix gm_form_fields is_required flags for 'case' form type
--
-- Align server-side required validation with what the case-form modal actually collects.
-- Fields that were marked required but are absent or optional in the modal are corrected here:
--
--   student_id     → removed from modal; handler uses null-safe fallback → NOT required
--   college_id     → optional in modal (no required attr, no asterisk)   → NOT required
--   student_grade  → optional in modal (no required attr, no asterisk)   → NOT required
--   category       → modal uses categories[] checkboxes, not this field  → NOT required
--   severity       → not present in modal; handler defaults to 'medium'  → NOT required
--
-- Fields that remain required (is_required=1):
--   student_name   → hidden field auto-filled via JS from last+first name; IS submitted
--   presenting_issue → marked required (*) in modal

UPDATE `gm_form_fields`
SET `is_required` = 0, `updated_at` = NOW()
WHERE `form_type` = 'case'
  AND `field_name` IN ('student_id', 'college_id', 'student_grade', 'category', 'severity');
