<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * PalStatusPresenter — maps PAL domain statuses to semantic visual tones.
 *
 * PAL owns the domain→tone mapping.
 * Workbench only knows tones: neutral, informational, warning, success, danger.
 */
final readonly class PalStatusPresenter
{
    /** @var array<string, array{label: string, tone: string, description: string}> */
    private const STATUS_MAP = [
        'draft'     => ['label' => 'Draft',     'tone' => 'neutral',       'description' => 'Not yet submitted for approval'],
        'pending'   => ['label' => 'Pending',   'tone' => 'warning',       'description' => 'Awaiting review'],
        'approved'  => ['label' => 'Approved',  'tone' => 'success',       'description' => 'Approved and ready to proceed'],
        'started'   => ['label' => 'Started',   'tone' => 'success',       'description' => 'Work has begun'],
        'ongoing'   => ['label' => 'Ongoing',   'tone' => 'success',       'description' => 'Work in progress'],
        'completed' => ['label' => 'Completed', 'tone' => 'success',       'description' => 'Work completed'],
        'paid'      => ['label' => 'Paid',      'tone' => 'success',       'description' => 'Payment received'],
        'rejected'  => ['label' => 'Rejected',  'tone' => 'danger',        'description' => 'Not approved'],
        'overdue'   => ['label' => 'Overdue',   'tone' => 'danger',        'description' => 'Past due date'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'danger',        'description' => 'No longer active'],
        'closed'    => ['label' => 'Closed',    'tone' => 'neutral',       'description' => 'Finalized and archived'],
        'submitted' => ['label' => 'Submitted', 'tone' => 'warning',       'description' => 'Submitted for review'],
        'voided'    => ['label' => 'Voided',    'tone' => 'danger',        'description' => 'Nullified'],
    ];

    /**
     * Resolve a domain status to a StatusValue with semantic tone.
     */
    public function resolve(string $status): StatusValue
    {
        $entry = self::STATUS_MAP[$status] ?? null;

        if ($entry === null) {
            return new StatusValue(
                key: $status,
                label: ucfirst($status),
                tone: 'neutral',
                description: 'Unknown status',
            );
        }

        return new StatusValue(
            key: $status,
            label: $entry['label'],
            tone: $entry['tone'],
            description: $entry['description'],
        );
    }

    /**
     * Resolve a project (Job Order) status.
     */
    public function forProject(string $status): StatusValue
    {
        return $this->resolve($status);
    }

    /**
     * Resolve an invoice status.
     */
    public function forInvoice(string $status): StatusValue
    {
        return $this->resolve($status);
    }

    /**
     * Resolve an expense status.
     */
    public function forExpense(string $status): StatusValue
    {
        return $this->resolve($status);
    }

    /**
     * Resolve an approval status.
     */
    public function forApproval(string $status): StatusValue
    {
        return $this->resolve($status);
    }

    /**
     * Get all known statuses (for filter dropdowns, etc.).
     *
     * @return array<int, StatusValue>
     */
    public function all(): array
    {
        return array_map(
            fn (string $key) => $this->resolve($key),
            array_keys(self::STATUS_MAP)
        );
    }
}
