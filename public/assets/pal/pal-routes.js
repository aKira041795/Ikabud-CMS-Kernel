/**
 * PAL Route Registry — semantic action → URL mapping.
 * Templates and JS should use PalRoutes.action(...) instead of hardcoded paths.
 *
 * Usage: PalRoutes.action('project.store')  → '/api/v1/project-audit-ledger/projects'
 *        PalRoutes.page('project.list')     → '/admin/project-audit-ledger/projects'
 */
window.PalRoutes = (function () {
    'use strict';

    var BASE_API = '/api/v1/project-audit-ledger';
    var BASE_ADMIN = '/admin/project-audit-ledger';

    var api = {
        'project.store': BASE_API + '/projects',
        'project.update': BASE_API + '/projects/{id}',
        'project.status': BASE_API + '/projects/{id}/status',
        'project.cost': BASE_API + '/projects/{id}/cost',
        'project.email': BASE_API + '/projects/{id}/email',
        'collection.store': BASE_API + '/collections',
        'expense.store': BASE_API + '/expenses',
        'purchase.store': BASE_API + '/purchases',
        'issuance.store': BASE_API + '/material-issuance',
        'fabrication.pay': BASE_API + '/fabrication/payments',
        'cash_advance.store': BASE_API + '/cash-advances',
        'mobilization.store': BASE_API + '/mobilization',
        'attachment.upload': BASE_API + '/attachments',
        'attachment.delete': BASE_API + '/attachments/{id}/delete',
        'approval.decide': BASE_API + '/approvals/{id}/decide',
        'quick_create': BASE_API + '/quick-create',
        'autocomplete': BASE_API + '/autocomplete',
        'settings.category': BASE_API + '/settings/categories',
        'settings.toggle': BASE_API + '/settings/toggle/{id}',
        'settings.supplier': BASE_API + '/settings/suppliers',
        'user.toggle': BASE_API + '/users/{id}/delete',
        'user.list': BASE_API + '/users',
        'sales.store': BASE_API + '/sales',
        'sales.update': BASE_API + '/sales/{id}',
        'auth.logout': BASE_API + '/auth/logout',
        'tl.logout': BASE_API + '/tl/logout',
        'tl.otp_request': BASE_API + '/tl/otp-request',
        'tl.otp_verify': BASE_API + '/tl/otp-verify',
    };

    var pages = {
        'dashboard': BASE_ADMIN,
        'project.list': BASE_ADMIN + '/projects',
        'project.create': BASE_ADMIN + '/projects/create',
        'project.detail': BASE_ADMIN + '/projects/{id}',
        'project.edit': BASE_ADMIN + '/projects/{id}/edit',
        'client.list': BASE_ADMIN + '/clients',
        'client.create': BASE_ADMIN + '/clients/create',
        'client.detail': BASE_ADMIN + '/clients/{id}',
        'supplier.list': BASE_ADMIN + '/suppliers',
        'sales.list': BASE_ADMIN + '/sales',
        'sales.detail': BASE_ADMIN + '/sales/{id}',
        'collection.list': BASE_ADMIN + '/collections',
        'collection.detail': BASE_ADMIN + '/collections/{id}',
        'expense.list': BASE_ADMIN + '/expenses',
        'expense.detail': BASE_ADMIN + '/expenses/{id}',
        'purchase.list': BASE_ADMIN + '/purchases',
        'purchase.detail': BASE_ADMIN + '/purchases/{id}',
        'inventory.list': BASE_ADMIN + '/inventory',
        'inventory.detail': BASE_ADMIN + '/inventory/{id}',
        'issuance.list': BASE_ADMIN + '/material-issuance',
        'return.list': BASE_ADMIN + '/material-returns',
        'fabrication.list': BASE_ADMIN + '/fabrication',
        'mobilization.list': BASE_ADMIN + '/mobilization',
        'cash_advance.list': BASE_ADMIN + '/cash-advances',
        'approval.queue': BASE_ADMIN + '/approvals',
        'reports': BASE_ADMIN + '/reports',
        'audit': BASE_ADMIN + '/audit',
        'settings': BASE_ADMIN + '/settings',
        'users': BASE_ADMIN + '/users',
        'bom.list': BASE_ADMIN + '/bom',
        'quotation.list': BASE_ADMIN + '/quotations',
        'movement.list': BASE_ADMIN + '/inventory/movements',
    };

    function resolve(template, id) {
        if (id !== undefined && id !== null) {
            return template.replace('{id}', String(id));
        }
        return template;
    }

    return {
        /** Get an API URL by semantic action name. Throws on unknown keys. */
        action: function (name, id) {
            var tmpl = api[name];
            if (!tmpl) { throw new Error('PalRoutes: unknown action "' + name + '"'); }
            return resolve(tmpl, id);
        },
        /** Get an admin page URL by semantic page name. Throws on unknown keys. */
        page: function (name, id) {
            var tmpl = pages[name];
            if (!tmpl) { throw new Error('PalRoutes: unknown page "' + name + '"'); }
            return resolve(tmpl, id);
        }
    };
})();
