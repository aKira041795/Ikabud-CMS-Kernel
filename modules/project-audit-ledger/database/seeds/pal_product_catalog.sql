-- ZAP-ARTS Product Catalog Seed Data
-- Run after migrations: INSERT IGNORE for idempotency
-- These are the default product categories and materials for signage/printing businesses

-- Material Categories
INSERT IGNORE INTO pal_material_categories (id, tenant_id, name, is_active) VALUES
    (1, 0, 'Tarpaulin', 1),
    (2, 0, 'Sticker', 1),
    (3, 0, 'Panaflex', 1),
    (4, 0, 'Acrylic', 1),
    (5, 0, 'Photo Paper', 1),
    (6, 0, 'Sintra', 1),
    (7, 0, 'Frosted Sticker', 1),
    (8, 0, 'Neon', 1),
    (9, 0, 'Stainless Steel', 1),
    (10, 0, 'ACP', 1);

-- Units
INSERT IGNORE INTO pal_units (id, tenant_id, name, abbreviation) VALUES
    (1, 0, 'Piece', 'pc'),
    (2, 0, 'Square Foot', 'sqft'),
    (3, 0, 'Roll', 'rl'),
    (4, 0, 'Meter', 'm'),
    (5, 0, 'Set', 'set');

-- Materials (ZAP-ARTS product catalog)
-- Note: tenant_id = 0 for global defaults; actual tenants get their own copies via INSERT ... SELECT
-- These serve as reference entries. Real usage should duplicate per-tenant.

INSERT IGNORE INTO pal_materials (id, tenant_id, material_code, name, category_id, unit_id, description, is_trackable, is_active) VALUES
    -- Tarpaulin
    (1, 0, 'TARP-BLK-12', 'Blackout Tarpaulin 12oz', 1, 2, '12oz blackout tarpaulin with eyelet', 1, 1),
    (2, 0, 'TARP-BLK-15', 'Blackout Tarpaulin 15oz', 1, 2, '15oz blackout tarpaulin with eyelet', 1, 1),
    (3, 0, 'TARP-BLK-18', 'Blackout Tarpaulin 18oz', 1, 2, '18oz blackout tarpaulin with eyelet', 1, 1),
    (4, 0, 'TARP-BLK-20', 'Blackout Tarpaulin 20oz', 1, 2, '20oz blackout tarpaulin with eyelet', 1, 1),
    -- Sticker
    (5, 0, 'STK-CLR', 'Sticker Printing Clear', 2, 2, 'Clear sticker printing', 1, 1),
    (6, 0, 'STK-WHT', 'Sticker Printing White', 2, 2, 'White sticker printing', 1, 1),
    (7, 0, 'STK-CUT', 'Sticker Cut Out', 2, 1, 'Sticker cut out per piece', 1, 1),
    (8, 0, 'STK-ONLY', 'Sticker Only', 2, 1, 'Sticker only (no backing)', 1, 1),
    -- Panaflex
    (9, 0, 'PNF-ECO', 'Panaflex Ecolsol', 3, 2, 'Panaflex ecolsol printing', 1, 1),
    (10, 0, 'PNF-STD', 'Panaflex Standard', 3, 2, 'Standard panaflex printing', 1, 1),
    -- Acrylic
    (11, 0, 'ACR-PLAQUE', 'Acrylic Plaque', 4, 1, 'Acrylic plaque', 1, 1),
    (12, 0, 'ACR-MEDAL', 'Acrylic Medals', 4, 1, 'Acrylic medals', 1, 1),
    (13, 0, 'ACR-BLT-LT', 'Acrylic Built-up Signage (Lighted)', 4, 1, 'Acrylic built-up signage with LED lighting', 1, 1),
    (14, 0, 'ACR-BLT-NL', 'Acrylic Built-up Signage (Non-lighted)', 4, 1, 'Acrylic built-up signage without lighting', 1, 1),
    -- Photo Paper
    (15, 0, 'PHOTO-PPR', 'Photo Paper Printing', 5, 2, 'Photo paper printing', 1, 1),
    -- Sintra
    (16, 0, 'STK-SINTRA', 'Sticker on Sintra', 6, 2, 'Sticker applied on sintra board', 1, 1),
    -- Frosted Sticker
    (17, 0, 'FROST-CT', 'Frosted Sticker Cutout', 7, 2, 'Frosted sticker cutout', 1, 1),
    (18, 0, 'FROST-PRT', 'Frosted Sticker Printed', 7, 2, 'Frosted sticker printed', 1, 1),
    -- Neon
    (19, 0, 'NEON-LED', 'Neon LED Signage', 8, 1, 'Neon LED signage', 1, 1),
    -- Stainless Steel
    (20, 0, 'STNLS-LT', 'Stainless Signage (Lighted)', 9, 1, 'Stainless steel signage with lighting', 1, 1),
    (21, 0, 'STNLS-NL', 'Stainless Signage (Non-lighted)', 9, 1, 'Stainless steel signage without lighting', 1, 1),
    -- ACP
    (22, 0, 'ACP-ONLY', 'ACP Only', 10, 2, 'Aluminum Composite Panel only', 1, 1),
    (23, 0, 'ACP-LT-ACR', 'ACP with Lighted Acrylic Built-up', 10, 1, 'ACP with lighted acrylic built-up signage', 1, 1),
    (24, 0, 'ACP-NL-ACR', 'ACP with Non-lighted Acrylic Built-up', 10, 1, 'ACP with non-lighted acrylic built-up signage', 1, 1),
    (25, 0, 'ACP-ACR-CUT', 'ACP with Acrylic Cut Out', 10, 1, 'ACP with acrylic cut out lettering', 1, 1);
