-- Project Audit Ledger — Core Schema (Phase 1)
-- All business tables except pal_users (see migration 002)

-- 1. Project types
CREATE TABLE IF NOT EXISTS pal_project_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_pt_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Clients
CREATE TABLE IF NOT EXISTS pal_clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_cli_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Suppliers
CREATE TABLE IF NOT EXISTS pal_suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    payment_terms VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_sup_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Material categories
CREATE TABLE IF NOT EXISTS pal_material_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_mcat_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Units of measure
CREATE TABLE IF NOT EXISTS pal_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL COMMENT 'e.g. Piece, Roll, Meter',
    abbreviation VARCHAR(10) DEFAULT NULL,
    INDEX idx_pal_unit_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Inventory locations
CREATE TABLE IF NOT EXISTS pal_inventory_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_iloc_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Expense categories
CREATE TABLE IF NOT EXISTS pal_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_project_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_ec_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Team leads (fabrication)
CREATE TABLE IF NOT EXISTS pal_team_leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_tl_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Materials master
CREATE TABLE IF NOT EXISTS pal_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    description TEXT DEFAULT NULL,
    unit_id INT UNSIGNED DEFAULT NULL,
    current_avg_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(18,2) DEFAULT NULL,
    preferred_supplier_id INT UNSIGNED DEFAULT NULL,
    storage_location VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_trackable TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_mat_tenant (tenant_id),
    INDEX idx_pal_mat_category (category_id),
    INDEX idx_pal_mat_supplier (preferred_supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Inventory movements
CREATE TABLE IF NOT EXISTS pal_inventory_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    movement_type ENUM(
        'stock_in','issuance','return','wastage','damage',
        'transfer_out','transfer_in','adjustment_up','adjustment_down',
        'initial_balance','reversal'
    ) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL COMMENT 'e.g. purchase, issuance, return',
    reference_id INT UNSIGNED DEFAULT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    location_id INT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    batch_number VARCHAR(100) DEFAULT NULL,
    movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status ENUM('pending','approved','reversed') NOT NULL DEFAULT 'approved',
    reversal_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_im_mat (material_id),
    INDEX idx_pal_im_type (movement_type),
    INDEX idx_pal_im_project (project_id),
    INDEX idx_pal_im_date (movement_date),
    INDEX idx_pal_im_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Inventory balances (cache table)
CREATE TABLE IF NOT EXISTS pal_inventory_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    avg_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_ib_mat_loc (material_id, location_id),
    INDEX idx_pal_ib_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Projects
CREATE TABLE IF NOT EXISTS pal_projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id VARCHAR(50) NOT NULL COMMENT 'Display ID',
    job_order_number VARCHAR(50) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    project_type_id INT UNSIGNED DEFAULT NULL,
    description TEXT DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    estimated_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    start_date DATE DEFAULT NULL,
    target_completion_date DATE DEFAULT NULL,
    actual_completion_date DATE DEFAULT NULL,
    project_manager VARCHAR(255) DEFAULT NULL,
    fabrication_team_lead_id INT UNSIGNED DEFAULT NULL,
    fabrication_alloc_pct DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentage override',
    fabrication_alloc_basis ENUM('expenses','labor_materials','contract','fixed','manual') DEFAULT 'expenses',
    fabrication_alloc_fixed DECIMAL(18,2) DEFAULT NULL,
    status ENUM('draft','approved','in_progress','on_hold','completed','cancelled','closed') NOT NULL DEFAULT 'draft',
    budget_warning_pct DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    notes TEXT DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_proj_tenant (tenant_id),
    INDEX idx_pal_proj_status (status),
    INDEX idx_pal_proj_client (client_id),
    INDEX idx_pal_proj_type (project_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Purchases
CREATE TABLE IF NOT EXISTS pal_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    purchase_number VARCHAR(50) NOT NULL,
    supplier_id INT UNSIGNED DEFAULT NULL,
    purchase_date DATE NOT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    receipt_number VARCHAR(100) DEFAULT NULL,
    po_reference VARCHAR(100) DEFAULT NULL,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    freight_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    notes TEXT DEFAULT NULL,
    status ENUM('draft','submitted','approved','rejected','voided') NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_pur_tenant (tenant_id),
    INDEX idx_pal_pur_supplier (supplier_id),
    INDEX idx_pal_pur_status (status),
    INDEX idx_pal_pur_date (purchase_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Purchase items
CREATE TABLE IF NOT EXISTS pal_purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    unit_id INT UNSIGNED DEFAULT NULL,
    unit_cost DECIMAL(18,2) NOT NULL,
    total_cost DECIMAL(18,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    batch_number VARCHAR(100) DEFAULT NULL,
    storage_location_id INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_pi_purchase (purchase_id),
    INDEX idx_pal_pi_material (material_id),
    FOREIGN KEY (purchase_id) REFERENCES pal_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Material issuances
CREATE TABLE IF NOT EXISTS pal_material_issuances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    issuance_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    issuance_date DATE NOT NULL,
    requested_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    released_by INT UNSIGNED DEFAULT NULL,
    received_by VARCHAR(255) DEFAULT NULL,
    purpose TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','requested','approved','partially_issued','fully_issued','rejected','cancelled') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_mi_tenant (tenant_id),
    INDEX idx_pal_mi_project (project_id),
    INDEX idx_pal_mi_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Material issuance items
CREATE TABLE IF NOT EXISTS pal_material_issuance_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issuance_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    requested_qty DECIMAL(18,4) NOT NULL,
    approved_qty DECIMAL(18,4) DEFAULT NULL,
    issued_qty DECIMAL(18,4) DEFAULT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(18,2) GENERATED ALWAYS AS (COALESCE(issued_qty, 0) * unit_cost) STORED,
    INDEX idx_pal_mii_issuance (issuance_id),
    INDEX idx_pal_mii_material (material_id),
    FOREIGN KEY (issuance_id) REFERENCES pal_material_issuances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Material returns
CREATE TABLE IF NOT EXISTS pal_material_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    issuance_id INT UNSIGNED DEFAULT NULL,
    material_id INT UNSIGNED NOT NULL,
    quantity_returned DECIMAL(18,4) NOT NULL,
    `condition` ENUM('reusable','damaged','wasted','scrap') NOT NULL DEFAULT 'reusable',
    reason VARCHAR(255) DEFAULT NULL,
    return_date DATE NOT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_mr_tenant (tenant_id),
    INDEX idx_pal_mr_project (project_id),
    INDEX idx_pal_mr_issuance (issuance_id),
    INDEX idx_pal_mr_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Expenses
CREATE TABLE IF NOT EXISTS pal_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    expense_number VARCHAR(50) NOT NULL,
    expense_date DATE NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = general operating expense',
    category_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    payee VARCHAR(255) DEFAULT NULL,
    supplier_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','submitted','pending_approval','approved','rejected','returned','voided','reversed') NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    reversal_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_exp_tenant (tenant_id),
    INDEX idx_pal_exp_project (project_id),
    INDEX idx_pal_exp_category (category_id),
    INDEX idx_pal_exp_status (status),
    INDEX idx_pal_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Sales
CREATE TABLE IF NOT EXISTS pal_sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    sales_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    sales_date DATE NOT NULL,
    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(18,2) GENERATED ALWAYS AS (gross_amount - discount_amount + tax_amount) STORED,
    due_date DATE DEFAULT NULL,
    payment_terms VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','issued','partially_paid','paid','overdue','cancelled','voided') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_sal_tenant (tenant_id),
    INDEX idx_pal_sal_project (project_id),
    INDEX idx_pal_sal_client (client_id),
    INDEX idx_pal_sal_status (status),
    INDEX idx_pal_sal_date (sales_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Collections
CREATE TABLE IF NOT EXISTS pal_collections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    collection_number VARCHAR(50) NOT NULL,
    sales_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    status ENUM('pending','approved','rejected','voided') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_coll_tenant (tenant_id),
    INDEX idx_pal_coll_sales (sales_id),
    INDEX idx_pal_coll_project (project_id),
    INDEX idx_pal_coll_client (client_id),
    INDEX idx_pal_coll_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Fabrication allocations
CREATE TABLE IF NOT EXISTS pal_fabrication_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    alloc_basis ENUM('expenses','labor_materials','contract','fixed','manual') NOT NULL DEFAULT 'expenses',
    alloc_percentage DECIMAL(5,2) DEFAULT NULL,
    base_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    calculated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    approved_amount DECIMAL(18,2) DEFAULT NULL COMMENT 'May differ from calculated',
    approval_reason VARCHAR(255) DEFAULT NULL COMMENT 'Required if approved differs from calculated',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status ENUM('draft','approved','adjusted') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_fa_project (project_id),
    INDEX idx_pal_fa_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Fabrication weekly dues
CREATE TABLE IF NOT EXISTS pal_fabrication_weekly_dues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    allocation_id INT UNSIGNED NOT NULL,
    week_number INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    due_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(18,2) GENERATED ALWAYS AS (due_amount - paid_amount) STORED,
    due_date DATE DEFAULT NULL,
    status ENUM('not_due','pending','partial','paid','overdue','waived','adjusted') NOT NULL DEFAULT 'not_due',
    notes TEXT DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pal_fwd_tenant (tenant_id),
    INDEX idx_pal_fwd_project (project_id),
    INDEX idx_pal_fwd_alloc (allocation_id),
    INDEX idx_pal_fwd_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Fabrication payments
CREATE TABLE IF NOT EXISTS pal_fabrication_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    payment_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    weekly_due_id INT UNSIGNED DEFAULT NULL,
    team_lead_id INT UNSIGNED DEFAULT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected','voided') NOT NULL DEFAULT 'pending',
    submitted_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_fp_tenant (tenant_id),
    INDEX idx_pal_fp_project (project_id),
    INDEX idx_pal_fp_due (weekly_due_id),
    INDEX idx_pal_fp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Approvals (polymorphic)
CREATE TABLE IF NOT EXISTS pal_approvals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'e.g. expense, purchase, issuance, collection, fabrication_payment',
    entity_id INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewer_id INT UNSIGNED DEFAULT NULL,
    decision ENUM('pending','approved','rejected','returned','withdrawn','escalated') NOT NULL DEFAULT 'pending',
    decision_date DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    previous_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    escalation_level INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_app_tenant (tenant_id),
    INDEX idx_pal_app_entity (entity_type, entity_id),
    INDEX idx_pal_app_decision (decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. Attachments (polymorphic)
CREATE TABLE IF NOT EXISTS pal_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(500) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    uploaded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_att_tenant (tenant_id),
    INDEX idx_pal_att_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Module-level audit logs
CREATE TABLE IF NOT EXISTS pal_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id VARCHAR(50) DEFAULT NULL,
    old_data JSON DEFAULT NULL,
    new_data JSON DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_al_tenant (tenant_id),
    INDEX idx_pal_al_actor (actor_user_id),
    INDEX idx_pal_al_action (action),
    INDEX idx_pal_al_entity (entity_type, entity_id),
    INDEX idx_pal_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. Report exports
CREATE TABLE IF NOT EXISTS pal_report_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    format ENUM('pdf','excel','html') NOT NULL,
    filters_json JSON DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    generated_by INT UNSIGNED DEFAULT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    INDEX idx_pal_re_tenant (tenant_id),
    INDEX idx_pal_re_type (report_type),
    INDEX idx_pal_re_generated (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Module settings
CREATE TABLE IF NOT EXISTS pal_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    UNIQUE KEY uq_pal_sett_tenant_key (tenant_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
