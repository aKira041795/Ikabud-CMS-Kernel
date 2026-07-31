<?php
/**
 * Academic Thesis Evaluation — route map.
 */

return [
    'GET' => [
        // Admin pages
        '/admin/thesis-evaluation'                              => 'academic_thesis_evaluation:pageDashboard',
        '/admin/thesis-evaluation/cases'                        => 'academic_thesis_evaluation:pageCases',
        '/admin/thesis-evaluation/cases/{id}'                   => 'academic_thesis_evaluation:pageCaseDetail',
        '/admin/thesis-evaluation/cases/{id}/evidence'          => 'academic_thesis_evaluation:pageEvidenceReview',
        '/admin/thesis-evaluation/cases/{id}/rubrics'           => 'academic_thesis_evaluation:pageRubrics',
        '/admin/thesis-evaluation/cases/{id}/revisions'         => 'academic_thesis_evaluation:pageRevisions',
        '/admin/thesis-evaluation/profiles'                     => 'academic_thesis_evaluation:pageProfiles',
        '/admin/thesis-evaluation/profiles/{id}'                => 'academic_thesis_evaluation:pageProfileDetail',
        '/admin/thesis-evaluation/rubrics'                      => 'academic_thesis_evaluation:pageRubricTemplates',
        '/admin/thesis-evaluation/rubrics/{id}'                 => 'academic_thesis_evaluation:pageRubricTemplateDetail',
        '/admin/thesis-evaluation/settings'                     => 'academic_thesis_evaluation:pageSettings',

        // Student pages
        '/thesis'                                               => 'academic_thesis_evaluation:pageStudentDashboard',
        '/thesis/submit'                                        => 'academic_thesis_evaluation:pageStudentSubmit',
        '/thesis/cases/{id}'                                    => 'academic_thesis_evaluation:pageStudentCaseDetail',
        '/thesis/cases/{id}/revisions'                          => 'academic_thesis_evaluation:pageStudentRevisions',

        // API
        '/api/v1/thesis-evaluation/cases/{id}'                  => 'academic_thesis_evaluation:apiGetCase',
        '/api/v1/thesis-evaluation/cases/{id}/manuscripts'      => 'academic_thesis_evaluation:apiGetManuscripts',
        '/api/v1/thesis-evaluation/cases/{id}/evidence'         => 'academic_thesis_evaluation:apiGetEvidence',
        '/api/v1/thesis-evaluation/cases/{id}/suggestions'      => 'academic_thesis_evaluation:apiGetSuggestionReviews',
        '/api/v1/thesis-evaluation/cases/{id}/rubrics/summary'  => 'academic_thesis_evaluation:apiGetRubricSummary',
        '/api/v1/thesis-evaluation/cases/{id}/revisions'        => 'academic_thesis_evaluation:apiGetRevisions',
        '/api/v1/thesis-evaluation/cases/{id}/report'           => 'academic_thesis_evaluation:apiGetReport',
        '/api/v1/thesis-evaluation/profiles'                    => 'academic_thesis_evaluation:apiListProfiles',
        '/api/v1/thesis-evaluation/rubrics/{code}'              => 'academic_thesis_evaluation:apiGetRubric',
    ],
    'POST' => [
        // API
        '/api/v1/thesis-evaluation/cases'                       => 'academic_thesis_evaluation:apiCreateCase',
        '/api/v1/thesis-evaluation/cases/{id}/transition'       => 'academic_thesis_evaluation:apiTransitionCase',
        '/api/v1/thesis-evaluation/cases/{id}/manuscripts'      => 'academic_thesis_evaluation:apiSubmitManuscript',
        '/api/v1/thesis-evaluation/cases/{id}/aiss-analysis'    => 'academic_thesis_evaluation:apiGenerateAissAnalysis',
        '/api/v1/thesis-evaluation/cases/{id}/reviewers'        => 'academic_thesis_evaluation:apiAssignReviewer',
        '/api/v1/thesis-evaluation/cases/{id}/reviewers/{assignment_id}/accept' => 'academic_thesis_evaluation:apiAcceptAssignment',
        '/api/v1/thesis-evaluation/cases/{id}/rubric-responses' => 'academic_thesis_evaluation:apiSubmitRubricResponses',
        '/api/v1/thesis-evaluation/cases/{id}/evidence/review'  => 'academic_thesis_evaluation:apiReviewEvidence',
        '/api/v1/thesis-evaluation/cases/{id}/suggestions/review' => 'academic_thesis_evaluation:apiReviewSuggestion',
        '/api/v1/thesis-evaluation/cases/{id}/revisions'        => 'academic_thesis_evaluation:apiCreateRevisionRequest',
        '/api/v1/thesis-evaluation/cases/{id}/revisions/{revision_id}/resolve' => 'academic_thesis_evaluation:apiResolveRevision',
        '/api/v1/thesis-evaluation/cases/{id}/disposition'      => 'academic_thesis_evaluation:apiIssueDisposition',
        '/api/v1/thesis-evaluation/profiles'                    => 'academic_thesis_evaluation:apiCreateProfile',
        '/api/v1/thesis-evaluation/settings'                    => 'academic_thesis_evaluation:apiSaveSettings',
    ],
];
