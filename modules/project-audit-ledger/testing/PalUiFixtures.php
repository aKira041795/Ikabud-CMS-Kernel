<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Testing;

/**
 * PalUiFixtures — named test scenarios for PAL browser and integration tests.
 *
 * Each scenario provides seed data and expected values for a deterministic
 * test case. Scenarios are designed to be idempotent — running the same
 * scenario twice produces the same expected outcome.
 *
 * Usage (PHP test):
 *   $scenario = PalUiFixtures::pendingPayment();
 *   seedFixtures($scenario['entities']);
 *   assertValues($scenario['expected']);
 *
 * Usage (Playwright — use scenario data as reference):
 *   const fixtures = require('./PalUiFixtures');
 *   const scenario = fixtures.pendingPayment();
 *   // navigate to payment detail for scenario.entities.payment.id
 *
 * @package Ikabud\Modules\ProjectAuditLedger\Testing
 */
final class PalUiFixtures
{
    /**
     * Empty state — no entities exist.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function empty(): array
    {
        return [
            'name' => 'empty',
            'entities' => [],
            'expected' => [
                'project_count' => 0,
                'entity_state' => 'empty',
            ],
        ];
    }

    /**
     * Basic scenario — one project, one client, one expense.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function basic(): array
    {
        return [
            'name' => 'basic',
            'entities' => [
                'client' => [
                    'id' => 1,
                    'name' => 'Test Client',
                    'contact_person' => 'John Doe',
                    'contact_number' => '09170000001',
                    'email' => 'client@test.com',
                    'address' => 'Test Address',
                    'is_active' => 1,
                ],
                'project' => [
                    'id' => 1,
                    'project_id' => 'P-20260713-000001',
                    'title' => 'Test Project',
                    'client_name' => 'Test Client',
                    'client_id' => 1,
                    'contract_amount' => 50000000, // ₱500,000.00 in centavos
                    'status' => 'ongoing',
                    'start_date' => '2026-07-13',
                    'description' => 'A test project for UI testing',
                ],
                'expense' => [
                    'id' => 1,
                    'project_id' => 1,
                    'description' => 'Material purchase',
                    'amount' => 500000, // ₱5,000.00
                    'status' => 'approved',
                    'expense_date' => '2026-07-13',
                    'category' => 'Materials',
                ],
            ],
            'expected' => [
                'project_count' => 1,
                'project_status' => 'ongoing',
                'contract_amount' => '₱500,000.00',
                'expense_amount' => '₱5,000.00',
                'expense_status' => 'approved',
                'dashboard_active' => 1,
            ],
        ];
    }

    /**
     * Pending approval — a project expense awaiting approval.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function pendingApproval(): array
    {
        return [
            'name' => 'pending-approval',
            'entities' => [
                'client' => [
                    'id' => 2,
                    'name' => 'Approval Test Client',
                    'contact_person' => 'Jane Smith',
                    'contact_number' => '09170000002',
                    'email' => 'approval@test.com',
                    'address' => 'Test Address 2',
                    'is_active' => 1,
                ],
                'project' => [
                    'id' => 2,
                    'project_id' => 'P-20260713-000002',
                    'title' => 'Approval Test Project',
                    'client_name' => 'Approval Test Client',
                    'client_id' => 2,
                    'contract_amount' => 100000000, // ₱1,000,000.00
                    'status' => 'started',
                    'start_date' => '2026-07-13',
                ],
                'expense' => [
                    'id' => 2,
                    'project_id' => 2,
                    'description' => 'Pending equipment rental',
                    'amount' => 1500000, // ₱15,000.00
                    'status' => 'pending',
                    'expense_date' => '2026-07-13',
                    'category' => 'Equipment',
                ],
            ],
            'expected' => [
                'project_count' => 1,
                'project_status' => 'started',
                'pending_expense_count' => 1,
                'expense_status' => 'pending',
                'approval_queue_count' => 1,
            ],
        ];
    }

    /**
     * Validation failure scenario — incomplete project form.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function validationFailure(): array
    {
        return [
            'name' => 'validation-failure',
            'entities' => [
                // No entities needed — this tests form validation
            ],
            'expected' => [
                'required_fields' => ['title', 'client_name', 'contract_amount'],
                'validation_error_count' => 3,
            ],
        ];
    }

    /**
     * Permission denied scenario — user without admin role.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function permissionDenied(): array
    {
        return [
            'name' => 'permission-denied',
            'entities' => [
                'client' => [
                    'id' => 3,
                    'name' => 'Restricted Client',
                    'contact_person' => 'Restricted User',
                    'contact_number' => '09170000003',
                    'email' => 'restricted@test.com',
                    'address' => 'Test Address 3',
                    'is_active' => 1,
                ],
                'project' => [
                    'id' => 3,
                    'project_id' => 'P-20260713-000003',
                    'title' => 'Restricted Project',
                    'client_name' => 'Restricted Client',
                    'client_id' => 3,
                    'contract_amount' => 25000000,
                    'status' => 'draft',
                    'start_date' => '2026-07-13',
                ],
            ],
            'expected' => [
                'draft_visible_to_admin' => true,
                'draft_visible_to_encoder' => true,
                'approve_action_visible_to_admin' => true,
                'approve_action_hidden_from_encoder' => true,
            ],
        ];
    }

    /**
     * Workflow conflict — trying to approve an already-approved expense.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function workflowConflict(): array
    {
        return [
            'name' => 'workflow-conflict',
            'entities' => [
                'client' => [
                    'id' => 4,
                    'name' => 'Conflict Test Client',
                    'contact_person' => 'Conflict User',
                    'contact_number' => '09170000004',
                    'email' => 'conflict@test.com',
                    'address' => 'Test Address 4',
                    'is_active' => 1,
                ],
                'project' => [
                    'id' => 4,
                    'project_id' => 'P-20260713-000004',
                    'title' => 'Conflict Test Project',
                    'client_name' => 'Conflict Test Client',
                    'client_id' => 4,
                    'contract_amount' => 75000000,
                    'status' => 'ongoing',
                    'start_date' => '2026-07-13',
                ],
                'expense' => [
                    'id' => 3,
                    'project_id' => 4,
                    'description' => 'Already approved expense',
                    'amount' => 2000000,
                    'status' => 'approved',
                    'expense_date' => '2026-07-13',
                    'category' => 'Materials',
                ],
            ],
            'expected' => [
                'approve_action_hidden' => true,
                'expense_status' => 'approved',
                'error_on_duplicate_approve' => true,
            ],
        ];
    }

    /**
     * Large dataset scenario — 50 projects for pagination testing.
     *
     * @param int $count Number of projects to generate
     * @return array{name: string, entities: array, expected: array}
     */
    public static function largeDataset(int $count = 50): array
    {
        $projects = [];
        for ($i = 1; $i <= $count; $i++) {
            $projects[] = [
                'id' => 100 + $i,
                'project_id' => sprintf('P-20260713-%06d', $i),
                'title' => "Test Project {$i}",
                'client_name' => "Client {$i}",
                'client_id' => 100 + $i,
                'contract_amount' => $i * 100000,
                'status' => $i % 3 === 0 ? 'completed' : ($i % 3 === 1 ? 'ongoing' : 'draft'),
                'start_date' => '2026-07-13',
            ];
        }

        return [
            'name' => 'large-dataset',
            'entities' => [
                'projects' => $projects,
            ],
            'expected' => [
                'total_projects' => $count,
                'pages_expected' => ceil($count / 10),
                'ongoing_count' => count(array_filter($projects, fn($p) => $p['status'] === 'ongoing')),
            ],
        ];
    }

    /**
     * Mobile team lead scenario — minimal data for TL view.
     *
     * @return array{name: string, entities: array, expected: array}
     */
    public static function mobileTeamLead(): array
    {
        return [
            'name' => 'mobile-team-lead',
            'entities' => [
                'project' => [
                    'id' => 5,
                    'project_id' => 'P-20260713-000005',
                    'title' => 'TL Test Project',
                    'client_name' => 'TL Client',
                    'client_id' => 5,
                    'contract_amount' => 30000000,
                    'status' => 'ongoing',
                    'start_date' => '2026-07-13',
                ],
            ],
            'expected' => [
                'tl_dashboard_accessible' => true,
                'project_visible_to_tl' => true,
            ],
        ];
    }
}
