<?php

declare(strict_types=1);

return [
    'GET' => [
        // Admin / authenticated routes
        '/tickets'                      => 'ticketing:handleTicketList',
        '/tickets/create'               => 'ticketing:handleTicketCreate',
        '/tickets/{id}/edit'            => 'ticketing:handleTicketEdit',
        '/tickets/{id}'                 => 'ticketing:handleTicketView',
        '/admin/ticketing/settings'     => 'ticketing:handleSettingsPage',
        // Aliases for admin-role nav (module-manager prefixes non-admin paths with /{moduleId})
        '/ticketing/tickets'            => 'ticketing:handleTicketList',
        '/ticketing/tickets/create'     => 'ticketing:handleTicketCreate',
        '/ticketing/tickets/{id}/edit'  => 'ticketing:handleTicketEdit',
        '/ticketing/tickets/{id}'       => 'ticketing:handleTicketView',
        // Public (unauthenticated) routes
        '/submit-ticket'                => 'ticketing:handlePublicSubmitForm',
        '/submit-ticket/success'        => 'ticketing:handlePublicSubmitSuccess',
        '/api/v1/tickets/captcha'       => 'ticketing:apiGetCaptcha',
    ],
    'POST' => [
        // Admin / authenticated routes
        '/api/v1/tickets'               => 'ticketing:apiCreateTicket',
        '/api/v1/tickets/update'        => 'ticketing:apiUpdateTicket',
        '/api/v1/tickets/comment'       => 'ticketing:apiAddComment',
        '/api/v1/tickets/status'        => 'ticketing:apiUpdateStatus',
        '/api/v1/tickets/assign'        => 'ticketing:apiAssignTicket',
        '/api/v1/ticketing/settings'    => 'ticketing:apiSaveSettings',
        // Public (unauthenticated) routes
        '/api/v1/tickets/public-submit' => 'ticketing:apiPublicSubmitTicket',
    ],
];
