<?php

declare(strict_types=1);

return [
    'GET' => [
        '/cms/admin/contact-forms' => 'contact-form:contactFormAdminForms',
        '/cms/admin/contact-forms/create' => 'contact-form:contactFormAdminFormCreate',
        '/cms/admin/contact-forms/{id}/edit' => 'contact-form:contactFormAdminFormEdit',
        '/cms/admin/contact-forms/submissions' => 'contact-form:contactFormAdminSubmissions',
        '/cms/admin/contact-forms/submissions/export' => 'contact-form:contactFormAdminSubmissionsExport',
        '/cms/admin/contact-forms/submissions/{id}' => 'contact-form:contactFormAdminSubmissionDetail',
        '/api/v1/contact-form/captcha' => 'contact-form:apiGetContactFormCaptcha',
    ],
    'POST' => [
        '/cms/admin/contact-forms/create' => 'contact-form:contactFormAdminFormCreate',
        '/cms/admin/contact-forms/{id}/edit' => 'contact-form:contactFormAdminFormEdit',
        '/cms/admin/contact-forms/{id}/delete' => 'contact-form:contactFormAdminFormDelete',
        '/cms/admin/contact-forms/{id}/fields/create' => 'contact-form:contactFormAdminFieldCreate',
        '/cms/admin/contact-forms/{id}/fields/reorder' => 'contact-form:contactFormAdminFieldReorder',
        '/cms/admin/contact-forms/{id}/fields/{fieldId}/save' => 'contact-form:contactFormAdminFieldUpdate',
        '/cms/admin/contact-forms/{id}/fields/{fieldId}/delete' => 'contact-form:contactFormAdminFieldDelete',
        '/cms/admin/contact-forms/submissions/{id}/status' => 'contact-form:contactFormAdminSubmissionStatusUpdate',
        '/api/v1/contact-form/submit' => 'contact-form:submitContactForm',
    ],
];
