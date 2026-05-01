-- ============================================================
-- MIGRATION 006: Case form refactor — new student fields,
--   multi-category, 4Ps on case, updated referral/status values,
--   updated session types on notes, updated appointment type names.
-- ============================================================

-- ---- gm_cases: new columns ----
ALTER TABLE `gm_cases`
    ADD COLUMN `student_first_name` VARCHAR(100) DEFAULT NULL AFTER `student_id`,
    ADD COLUMN `student_last_name`  VARCHAR(100) DEFAULT NULL AFTER `student_first_name`,
    ADD COLUMN `categories`          JSON         DEFAULT NULL AFTER `category`,
    ADD COLUMN `case_predisposition` TEXT         DEFAULT NULL AFTER `background_info`,
    ADD COLUMN `case_precipitating`  TEXT         DEFAULT NULL AFTER `case_predisposition`,
    ADD COLUMN `case_perpetuating`   TEXT         DEFAULT NULL AFTER `case_precipitating`,
    ADD COLUMN `case_protective`     TEXT         DEFAULT NULL AFTER `case_perpetuating`,
    ADD COLUMN `session_date`        DATE         DEFAULT NULL AFTER `updated_at`;

-- ---- gm_cases: expand referral_source to new values ----
ALTER TABLE `gm_cases`
    MODIFY COLUMN `referral_source`
        ENUM('walk-in','follow-up','referred','self','teacher','staff','parent','others')
        DEFAULT 'self';

-- ---- gm_counselor_notes: expand session_type to new values ----
ALTER TABLE `gm_counselor_notes`
    MODIFY COLUMN `session_type`
        ENUM('walk-in','follow-up','referred','scheduled','referral')
        DEFAULT 'walk-in';

-- ---- gm_appointment_types: rename to requested labels ----
UPDATE `gm_appointment_types` SET `name` = 'Individual Counseling'
    WHERE `code` IN ('individual','walkin','initial','counseling','individual_session');
UPDATE `gm_appointment_types` SET `name` = 'Group Counseling'
    WHERE `code` = 'group';
UPDATE `gm_appointment_types` SET `name` = 'Parent Consultation'
    WHERE `code` = 'parent';

-- Keep only the three requested types visible to staff appointment form
UPDATE `gm_appointment_types` SET `is_active` = 0
    WHERE `code` NOT IN ('individual','group','parent');

-- ---- gm_form_fields: relax required flags for case form ----
UPDATE `gm_form_fields`
    SET `is_required` = 0
    WHERE `form_type` = 'case'
      AND `field_name` IN ('student_id','college_id','student_grade','severity','category','student_name');

-- ---- gm_form_fields: update student_status options ----
UPDATE `gm_form_fields`
    SET `field_options` = '["Probationary","For Follow-up","Terminated"]'
    WHERE `form_type` = 'case' AND `field_name` = 'student_status';

-- ---- gm_form_fields: update referral_source options ----
UPDATE `gm_form_fields`
    SET `field_options` = 'self,teacher,staff,parent,others'
    WHERE `form_type` = 'case' AND `field_name` = 'referral_source';

-- ---- gm_form_fields: disable background_info (removed from new form) ----
UPDATE `gm_form_fields`
    SET `is_enabled` = 0
    WHERE `form_type` = 'case' AND `field_name` = 'background_info';

-- ---- gm_form_fields: disable is_confidential (removed from case form) ----
UPDATE `gm_form_fields`
    SET `is_enabled` = 0
    WHERE `form_type` = 'case' AND `field_name` = 'is_confidential';
