SET FOREIGN_KEY_CHECKS = 0;

-- Add unique constraints for business identifier columns to prevent
-- duplicate invoice/collection/Job Order numbers under concurrent requests.
--
-- These constraints enforce the "one active invoice per project" invariant
-- and prevent duplicate generated numbers at the database level, complementing
-- the application-level checks in ProjectService::completeProject().

-- 1. pal_projects: unique job_order_number per tenant
ALTER TABLE pal_projects
    ADD UNIQUE KEY uq_pal_proj_jo_number (tenant_id, job_order_number);

-- 2. pal_sales: unique sales_number per tenant
ALTER TABLE pal_sales
    ADD UNIQUE KEY uq_pal_sales_number (tenant_id, sales_number);

-- 3. pal_sales: unique invoice_number per tenant
ALTER TABLE pal_sales
    ADD UNIQUE KEY uq_pal_invoice_number (tenant_id, invoice_number);

-- 4. pal_collections: unique collection_number per tenant
ALTER TABLE pal_collections
    ADD UNIQUE KEY uq_pal_collection_number (tenant_id, collection_number);

SET FOREIGN_KEY_CHECKS = 1;
