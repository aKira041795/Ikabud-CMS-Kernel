<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services/ProjectService.php';
require_once __DIR__ . '/services/ProjectCostService.php';
require_once __DIR__ . '/services/ExpenseService.php';
require_once __DIR__ . '/services/PurchaseService.php';
require_once __DIR__ . '/services/InventoryService.php';
require_once __DIR__ . '/services/MaterialIssuanceService.php';
require_once __DIR__ . '/services/MaterialReturnService.php';
require_once __DIR__ . '/services/QuotationService.php';
require_once __DIR__ . '/services/CashAdvanceService.php';
require_once __DIR__ . '/services/FabricationService.php';
require_once __DIR__ . '/services/SalesService.php';
require_once __DIR__ . '/services/ApprovalService.php';
require_once __DIR__ . '/services/AttachmentService.php';
require_once __DIR__ . '/services/JobOrderWorkflow.php';
require_once __DIR__ . '/services/ReceivableService.php';
require_once __DIR__ . '/services/InvoiceTotalCalculator.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/ProjectCompletionCoordinator.php';
require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/06-team-lead-auth.php';
require_once __DIR__ . '/handlers/05-auth.php';
require_once __DIR__ . '/handlers/10-dashboard.php';
require_once __DIR__ . '/handlers/15-projects.php';
require_once __DIR__ . '/handlers/20-clients.php';
require_once __DIR__ . '/handlers/25-expenses.php';
require_once __DIR__ . '/handlers/30-purchases.php';
require_once __DIR__ . '/handlers/35-inventory.php';
require_once __DIR__ . '/handlers/40-issuance.php';
require_once __DIR__ . '/handlers/45-fabrication.php';
require_once __DIR__ . '/handlers/50-sales.php';
require_once __DIR__ . '/handlers/52-quotations.php';
require_once __DIR__ . '/handlers/53-team-lead.php';
require_once __DIR__ . '/handlers/55-approvals.php';
require_once __DIR__ . '/handlers/57-cash-advances.php';
require_once __DIR__ . '/handlers/59-bom.php';
require_once __DIR__ . '/handlers/60-reports.php';
require_once __DIR__ . '/handlers/65-audit.php';
require_once __DIR__ . '/handlers/70-settings.php';
require_once __DIR__ . '/handlers/72-attachments.php';
require_once __DIR__ . '/handlers/75-users.php';

// Load entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}
