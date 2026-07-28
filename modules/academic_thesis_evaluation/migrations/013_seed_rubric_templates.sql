-- Academic Thesis Evaluation — Seed Rubric Templates
-- Inserts default rubric templates and criteria for master's and doctoral evaluation.

SET FOREIGN_KEY_CHECKS = 0;

-- Master's Thesis Rubric v1
INSERT IGNORE INTO ate_rubric_templates (tenant_id, code, name, version, degree_level, status, total_weight, created_at, updated_at)
SELECT tenant_id, 'masters_thesis_v1', 'Master''s Thesis Evaluation Rubric', '1.0', 'masters', 'active', 100.00, NOW(), NOW()
FROM ate_evaluation_profiles WHERE code = 'masters_thesis_v1' AND status = 'active';

-- Doctoral Dissertation Rubric v1
INSERT IGNORE INTO ate_rubric_templates (tenant_id, code, name, version, degree_level, status, total_weight, created_at, updated_at)
SELECT tenant_id, 'doctoral_dissertation_v1', 'Doctoral Dissertation Evaluation Rubric', '1.0', 'doctoral', 'active', 100.00, NOW(), NOW()
FROM ate_evaluation_profiles WHERE code = 'doctoral_dissertation_v1' AND status = 'active';

-- ── Master's Thesis Criteria ─────────────────────────────────────
-- Insert criteria for each masters rubric template
INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'research_problem', 'Research Problem and Objectives',
       'Clarity, significance, and feasibility of the research problem and objectives.',
       10.00, 0, 100, 60.00, 1
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'literature_review', 'Literature Review and Synthesis',
       'Comprehensiveness, critical analysis, and synthesis of relevant literature.',
       15.00, 0, 100, 60.00, 2
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'framework', 'Theoretical/Conceptual Framework',
       'Appropriateness and application of theoretical or conceptual framework.',
       10.00, 0, 100, 60.00, 3
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'methodology', 'Methodology',
       'Appropriateness, rigor, and justification of research design and methods.',
       20.00, 0, 100, 60.00, 4
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'analysis_findings', 'Analysis and Findings',
       'Depth and quality of data analysis, interpretation, and presentation of findings.',
       20.00, 0, 100, 60.00, 5
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'conclusions', 'Conclusions and Recommendations',
       'Validity of conclusions, quality of recommendations, and alignment with findings.',
       10.00, 0, 100, 60.00, 6
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'writing_citation', 'Scholarly Writing and Citation',
       'Quality of academic writing, organization, and proper citation practices.',
       10.00, 0, 100, 60.00, 7
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'contribution', 'Contribution and Applicability',
       'Originality, significance, and practical applicability of the research.',
       5.00, 0, 100, 60.00, 8
FROM ate_rubric_templates rt WHERE rt.code = 'masters_thesis_v1' AND rt.status = 'active';

-- ── Doctoral Dissertation Criteria ───────────────────────────────
INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'research_significance', 'Significance of Research Problem',
       'Originality, timeliness, and potential impact of the research problem.',
       10.00, 0, 100, 70.00, 1
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'critical_lit_synthesis', 'Critical Literature Synthesis',
       'Depth, breadth, and critical synthesis of theoretical and empirical literature.',
       15.00, 0, 100, 70.00, 2
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'theoretical_contribution', 'Theoretical Contribution',
       'Original contribution to theory development or advancement of knowledge.',
       15.00, 0, 100, 70.00, 3
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'methodological_rigor', 'Methodological Rigor',
       'Sophistication, appropriateness, and rigorous execution of research methodology.',
       20.00, 0, 100, 70.00, 4
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'analysis_validity', 'Analysis and Validity',
       'Sophistication of analysis, validity of interpretations, and integrity of findings.',
       15.00, 0, 100, 70.00, 5
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'original_contribution', 'Original Contribution to Knowledge',
       'Novelty, significance, and potential to advance the discipline.',
       15.00, 0, 100, 70.00, 6
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'scholarly_communication', 'Scholarly Communication',
       'Quality of academic writing, argumentation, and scholarly presentation.',
       5.00, 0, 100, 70.00, 7
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

INSERT IGNORE INTO ate_rubric_criteria (rubric_template_id, code, label, description, weight, score_min, score_max, required_comment_below, sort_order)
SELECT rt.id, 'publication_readiness', 'Publication Readiness',
       'Readiness for publication in peer-reviewed journals or scholarly venues.',
       5.00, 0, 100, 70.00, 8
FROM ate_rubric_templates rt WHERE rt.code = 'doctoral_dissertation_v1' AND rt.status = 'active';

SET FOREIGN_KEY_CHECKS = 1;
