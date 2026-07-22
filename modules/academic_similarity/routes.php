<?php
/**
 * Academic Similarity — route map.
 * All admin routes are under /admin/academic-similarity.
 */

return [
    'GET' => [
        '/admin/academic-similarity'                              => 'academic-similarity:pageDashboard',
        '/admin/academic-similarity/submissions'                  => 'academic-similarity:pageSubmissions',
        '/admin/academic-similarity/submissions/{id}'             => 'academic-similarity:pageSubmissionDetail',
        '/admin/academic-similarity/sources'                      => 'academic-similarity:pageSources',
        '/admin/academic-similarity/collections'                  => 'academic-similarity:pageCollections',
        '/admin/academic-similarity/reports'                      => 'academic-similarity:pageReports',
        '/admin/academic-similarity/reports/{id}'                 => 'academic-similarity:pageReportDetail',
        '/admin/academic-similarity/reports/{id}/download'        => 'academic-similarity:downloadReport',
        '/admin/academic-similarity/settings'                     => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/settings/processing'          => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/settings/reports'             => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/settings/sources'             => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/settings/semantic'            => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/settings/cms'                 => 'academic-similarity:pageSettings',
        '/admin/academic-similarity/submissions/new'              => 'academic-similarity:pageSubmissionUpload',
        '/admin/academic-similarity/submissions/{id}/upload'      => 'academic-similarity:pageSubmissionUpload',
    ],
    'POST' => [
        '/admin/academic-similarity/settings'                     => 'academic-similarity:apiSaveSettings',
        '/api/v1/academic-similarity/submissions'                 => 'academic-similarity:apiCreateSubmission',
        '/api/v1/academic-similarity/submissions/{id}/process'    => 'academic-similarity:apiProcessSubmission',
        '/api/v1/academic-similarity/submissions/{id}/delete'     => 'academic-similarity:apiDeleteSubmission',
        '/api/v1/academic-similarity/sources'                     => 'academic-similarity:apiCreateSource',
        '/api/v1/academic-similarity/sources/{id}/reindex'        => 'academic-similarity:apiReindexSource',
        '/api/v1/academic-similarity/sources/{id}/delete'         => 'academic-similarity:apiDeleteSource',
        '/api/v1/academic-similarity/collections'                 => 'academic-similarity:apiCreateCollection',
        '/api/v1/academic-similarity/collections/{id}/delete'     => 'academic-similarity:apiDeleteCollection',
        '/api/v1/academic-similarity/reviews/{match_id}/exclude'  => 'academic-similarity:apiExcludeMatch',
        '/api/v1/academic-similarity/settings'                    => 'academic-similarity:apiSaveSettings',
        '/api/v1/academic-similarity/public/submit'               => 'academic-similarity:apiPublicSubmit',
    ],
];
