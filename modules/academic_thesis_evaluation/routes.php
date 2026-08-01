<?php
/**
 * Academic Thesis Evaluation — route map.
 */

return [
    'GET' => [
        // Admin pages
        '/admin/thesis-evaluation'                              => 'academic-thesis-evaluation:pageDashboard',
        '/admin/thesis-evaluation/cases'                        => 'academic-thesis-evaluation:pageCases',
        '/admin/thesis-evaluation/cases/{id}'                   => 'academic-thesis-evaluation:pageCaseDetail',
        '/admin/thesis-evaluation/cases/{id}/evidence'          => 'academic-thesis-evaluation:pageEvidenceReview',
        '/admin/thesis-evaluation/cases/{id}/rubrics'           => 'academic-thesis-evaluation:pageRubrics',
        '/admin/thesis-evaluation/cases/{id}/revisions'         => 'academic-thesis-evaluation:pageRevisions',
        '/admin/thesis-evaluation/profiles'                     => 'academic-thesis-evaluation:pageProfiles',
        '/admin/thesis-evaluation/profiles/{id}'                => 'academic-thesis-evaluation:pageProfileDetail',
        '/admin/thesis-evaluation/rubrics'                      => 'academic-thesis-evaluation:pageRubricTemplates',
        '/admin/thesis-evaluation/rubrics/{id}'                 => 'academic-thesis-evaluation:pageRubricTemplateDetail',
        '/admin/thesis-evaluation/settings'                     => 'academic-thesis-evaluation:pageSettings',

        // Student pages
        '/thesis'                                               => 'academic-thesis-evaluation:pageStudentDashboard',
        '/thesis/submit'                                        => 'academic-thesis-evaluation:pageStudentSubmit',
        '/thesis/cases/{id}'                                    => 'academic-thesis-evaluation:pageStudentCaseDetail',
        '/thesis/cases/{id}/revisions'                          => 'academic-thesis-evaluation:pageStudentRevisions',

        // API
        '/api/v1/thesis-evaluation/cases/{id}'                  => 'academic-thesis-evaluation:apiGetCase',
        '/api/v1/thesis-evaluation/cases/{id}/manuscripts'      => 'academic-thesis-evaluation:apiGetManuscripts',
        '/api/v1/thesis-evaluation/cases/{id}/evidence'         => 'academic-thesis-evaluation:apiGetEvidence',
        '/api/v1/thesis-evaluation/cases/{id}/suggestions'      => 'academic-thesis-evaluation:apiGetSuggestionReviews',
        '/api/v1/thesis-evaluation/cases/{id}/rubrics/summary'  => 'academic-thesis-evaluation:apiGetRubricSummary',
        '/api/v1/thesis-evaluation/cases/{id}/revisions'        => 'academic-thesis-evaluation:apiGetRevisions',
        '/api/v1/thesis-evaluation/cases/{id}/report'           => 'academic-thesis-evaluation:apiGetReport',
        '/api/v1/thesis-evaluation/profiles'                    => 'academic-thesis-evaluation:apiListProfiles',
        '/api/v1/thesis-evaluation/rubrics/{code}'              => 'academic-thesis-evaluation:apiGetRubric',
    ],
    'POST' => [
        // API
        '/api/v1/thesis-evaluation/cases'                       => 'academic-thesis-evaluation:apiCreateCase',
        '/api/v1/thesis-evaluation/cases/{id}/transition'       => 'academic-thesis-evaluation:apiTransitionCase',
        '/api/v1/thesis-evaluation/cases/{id}/manuscripts'      => 'academic-thesis-evaluation:apiSubmitManuscript',
        '/api/v1/thesis-evaluation/cases/{id}/aiss-analysis'    => 'academic-thesis-evaluation:apiGenerateAissAnalysis',
        '/api/v1/thesis-evaluation/cases/{id}/reviewers'        => 'academic-thesis-evaluation:apiAssignReviewer',
        '/api/v1/thesis-evaluation/cases/{id}/reviewers/{assignment_id}/accept' => 'academic-thesis-evaluation:apiAcceptAssignment',
        '/api/v1/thesis-evaluation/cases/{id}/rubric-responses' => 'academic-thesis-evaluation:apiSubmitRubricResponses',
        '/api/v1/thesis-evaluation/cases/{id}/evidence/review'  => 'academic-thesis-evaluation:apiReviewEvidence',
        '/api/v1/thesis-evaluation/cases/{id}/suggestions/review' => 'academic-thesis-evaluation:apiReviewSuggestion',
        '/api/v1/thesis-evaluation/cases/{id}/revisions'        => 'academic-thesis-evaluation:apiCreateRevisionRequest',
        '/api/v1/thesis-evaluation/cases/{id}/revisions/{revision_id}/resolve' => 'academic-thesis-evaluation:apiResolveRevision',
        '/api/v1/thesis-evaluation/cases/{id}/disposition'      => 'academic-thesis-evaluation:apiIssueDisposition',
        '/api/v1/thesis-evaluation/profiles'                    => 'academic-thesis-evaluation:apiCreateProfile',
        '/api/v1/thesis-evaluation/settings'                    => 'academic-thesis-evaluation:apiSaveSettings',
    ],
];
