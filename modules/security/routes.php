<?php
/**
 * Security Module — Routes
 */

return [
    'GET' => [
        '/admin/security'              => 'security:pageSecurityDashboard',
        '/admin/security/audit-log'    => 'security:pageSecurityAuditLog',
        '/admin/security/integrity'    => 'security:pageSecurityIntegrity',
        '/admin/security/ip-allowlist' => 'security:pageSecurityIpAllowlist',
        '/admin/security/settings'     => 'security:pageSecuritySettings',
    ],
    'POST' => [
        '/api/v1/security/settings'              => 'security:apiSaveSettings',
        '/api/v1/security/integrity-baseline'    => 'security:apiRebuildBaseline',
        '/api/v1/security/integrity-check'       => 'security:apiCheckIntegrity',
        '/api/v1/security/ip-allowlist/add'      => 'security:apiAddIpAllowlist',
        '/api/v1/security/ip-allowlist/remove'   => 'security:apiRemoveIpAllowlist',
        '/api/v1/security/audit-log/clear'       => 'security:apiClearAuditLog',
    ],
];
