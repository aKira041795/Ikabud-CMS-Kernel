<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ModuleComprehensionProvider,
    EntityContract,
    WorkflowContract,
    ActionContract,
    ChainLink,
    EffectContract,
    InvariantContract,
    ScenarioContract,
};

/**
 * PAL module comprehension provider.
 * Declares what PAL should do — the engine verifies it.
 */
class PalComprehensionProvider implements ModuleComprehensionProvider
{
    public function entities(): array
    {
        return [
            new EntityContract(
                id: 'pal.project',
                label: 'Project / Job Order',
                table: 'pal_projects',
                fields: ['id', 'project_id', 'title', 'client_id', 'contract_amount', 'status',
                         'start_date', 'target_completion_date', 'job_order_number', 'jo_type'],
                relationships: [
                    'client' => 'pal.client',
                    'expenses' => 'pal.expense',
                    'approvals' => 'pal.approval',
                    'fabrication_allocations' => 'pal.fabrication_allocation',
                ],
                statuses: ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed'],
            ),
            new EntityContract(
                id: 'pal.approval',
                label: 'Approval Request',
                table: 'pal_approvals',
                fields: ['id', 'entity_type', 'entity_id', 'submitted_by', 'decision', 'previous_status', 'new_status'],
                statuses: ['pending_approval', 'approved', 'rejected'],
            ),
            new EntityContract(
                id: 'pal.client',
                label: 'Client',
                table: 'pal_clients',
                fields: ['id', 'name', 'contact_person', 'email', 'phone'],
            ),
        ];
    }

    public function routes(): array
    {
        return []; // Discovered dynamically
    }

    public function workflows(): array
    {
        return [
            new WorkflowContract(
                id: 'job_order_lifecycle',
                entityType: 'pal.project',
                states: ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed'],
                transitions: [
                    ['from' => 'draft', 'to' => 'pending', 'action' => 'pal.job-order.submit', 'capability' => 'pal.projects.write@1'],
                    ['from' => 'pending', 'to' => 'approved', 'action' => 'pal.approval.approve', 'capability' => 'pal.approvals.write@1'],
                    ['from' => 'approved', 'to' => 'started', 'action' => 'pal.job-order.start', 'capability' => 'pal.projects.write@1'],
                    ['from' => 'started', 'to' => 'ongoing', 'action' => 'pal.job-order.mark-ongoing', 'capability' => 'pal.projects.write@1'],
                    ['from' => 'ongoing', 'to' => 'completed', 'action' => 'pal.job-order.complete', 'capability' => 'pal.projects.write@1'],
                ],
            ),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionContract(
                id: 'pal.job-order.submit',
                label: 'Submit Job Order for Approval',
                entityType: 'pal.project',
                route: '/api/v1/project-audit-ledger/projects/{id}/status',
                method: 'POST',
                requires: [
                    'status' => 'draft',
                    'capability' => 'pal.projects.write@1',
                ],
                chain: [
                    new ChainLink('button.visible', 'Submit for Approval button is visible on edit page', 'ui'),
                    new ChainLink('button.clicked', 'User clicks the Submit button', 'ui'),
                    new ChainLink('http.request', 'POST request sent to status API endpoint', 'http'),
                    new ChainLink('http.response_ok', 'API returns HTTP 200 with {ok: true}', 'http'),
                    new ChainLink('workflow.transition', 'JobOrderWorkflow::apply() executes transition', 'service'),
                    new ChainLink('db.status_change', 'Project status changes from draft to pending', 'db',
                        probe: "SELECT status FROM pal_projects WHERE id=:id"),
                    new ChainLink('approval.created', 'Approval request record created in pal_approvals', 'db',
                        probe: "SELECT COUNT(*) FROM pal_approvals WHERE entity_type='project' AND entity_id=:id"),
                    new ChainLink('audit.created', 'Audit log entry created for status change', 'audit'),
                    new ChainLink('ui.status_updated', 'Detail page renders Pending status badge', 'verify'),
                    new ChainLink('approval_queue.updated', 'Approval queue page shows the project', 'verify'),
                ],
            ),
            new ActionContract(
                id: 'pal.job-order.create',
                label: 'Create Job Order',
                entityType: 'pal.project',
                route: '/api/v1/project-audit-ledger/projects',
                method: 'POST',
                requires: ['capability' => 'pal.projects.write@1'],
                chain: [
                    new ChainLink('button.visible', 'Create/Save button is visible', 'ui'),
                    new ChainLink('http.request', 'POST request sent to projects API', 'http'),
                    new ChainLink('http.response_ok', 'API returns {ok:true, id:N, redirect:...}', 'http'),
                    new ChainLink('db.project_created', 'Project record created in pal_projects', 'db',
                        probe: "SELECT id FROM pal_projects ORDER BY id DESC LIMIT 1"),
                    new ChainLink('ui.redirect', 'Browser redirects to project detail page', 'ui'),
                ],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [
            'pal.read@1',
            'pal.manage@1',
            'pal.projects.read@1',
            'pal.projects.write@1',
            'pal.approvals.read@1',
            'pal.approvals.write@1',
        ];
    }

    public function invariants(): array
    {
        return [
            new InvariantContract(
                description: 'A project must have a title',
                type: 'db',
                sql: "SELECT COUNT(*) FROM pal_projects WHERE title IS NULL OR title = ''",
            ),
            new InvariantContract(
                description: 'Every approval must reference an existing entity',
                type: 'db',
            ),
        ];
    }

    public function expectedEffects(): array
    {
        return [
            new EffectContract('pal.job-order.submit', [
                'project.status' => 'pending',
                'approval_request.created' => true,
                'audit_log.event' => 'job_order.submitted',
                'ui.status_badge' => 'Pending',
            ]),
        ];
    }

    public function testScenarios(): array
    {
        return [
            new ScenarioContract('jo-lifecycle', 'Full JO lifecycle: create → submit → approve → start → ongoing', [
                'pal.job-order.create',
                'pal.job-order.submit',
            ]),
        ];
    }
}
