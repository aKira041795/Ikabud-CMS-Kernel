<?php

declare(strict_types=1);

/**
 * Invoice Total Calculator — Single source of truth for invoice amount semantics.
 *
 * Establishes one invariant:
 *
 *   invoice_total = gross_amount
 *                   + installation_charge
 *                   + mobilization_charge
 *                   + other_charges
 *                   - discount_amount
 *                   + tax_amount
 *
 * All paths (receivable creation, outstanding display, status checks,
 * emails, print output, backfills, reports) must use this formula.
 *
 * Migration 008_pal_sale_items.sql already redefines the DB-generated
 * `net_amount` column to match this formula. This class provides the
 * same calculation in PHP for contexts where the DB column isn't
 * available (project completion, backfill, receivable sync).
 *
 * Column semantics (invariant):
 *   - contract_amount (pal_projects): final agreed customer total.
 *     In items mode, this already includes installation, mobilization,
 *     and other charges.
 *   - gross_amount (pal_sales): base subtotal before separate charges.
 *     For auto-generated invoices, gross_amount = contract_amount - charges.
 *   - invoice_total: final amount due (via this calculator or DB net_amount).
 */
class palInvoiceTotalCalculator
{
    /**
     * Calculate the canonical invoice total.
     * Matches the DB net_amount generated column formula from migration 008.
     *
     * @param array $saleRow A row from pal_sales (must contain gross_amount,
     *                       installation_charge, mobilization_charge,
     *                       other_charges, discount_amount, tax_amount)
     * @return float
     */
    public static function total(array $saleRow): float
    {
        return max(0,
            (float)($saleRow['gross_amount'] ?? 0)
            + (float)($saleRow['installation_charge'] ?? 0)
            + (float)($saleRow['mobilization_charge'] ?? 0)
            + (float)($saleRow['other_charges'] ?? 0)
            - (float)($saleRow['discount_amount'] ?? 0)
            + (float)($saleRow['tax_amount'] ?? 0)
        );
    }

    /**
     * Compute gross_amount for an auto-generated invoice from a project.
     *
     * When contract_amount already includes separate charges (items mode),
     * the gross_amount must NOT double-count them:
     *
     *   gross_amount = contract_amount - installation - mobilization - other
     *
     * @param float $contractAmount Project's contract_amount
     * @param array $projectRow Must contain installation_charge,
     *                          mobilization_charge, other_charges
     * @return float
     */
    public static function grossFromContract(float $contractAmount, array $projectRow): float
    {
        $charges = (float)($projectRow['installation_charge'] ?? 0)
                 + (float)($projectRow['mobilization_charge'] ?? 0)
                 + (float)($projectRow['other_charges'] ?? 0);
        return max(0, $contractAmount - $charges);
    }
}
