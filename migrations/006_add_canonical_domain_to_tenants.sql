-- Control plane migration: canonical domain per tenant
-- Adds an optional canonical_domain column to kernel_tenants.
-- When set, any request arriving on a different domain for this tenant
-- is 301-redirected to the canonical domain before any response is rendered.
-- This enforces a single-domain contract for sessions, links, and SEO.

ALTER TABLE `kernel_tenants`
    ADD COLUMN `canonical_domain` VARCHAR(255) DEFAULT NULL AFTER `entry_module_id`;
