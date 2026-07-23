-- AISS AI report narrative support.
-- Adds a column for storing AI-generated plain-language summaries of similarity reports.

ALTER TABLE ac_similarity_reports
  ADD COLUMN report_ai_narrative TEXT NULL AFTER report_data_json;
