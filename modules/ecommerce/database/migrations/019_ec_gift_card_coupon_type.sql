-- ============================================================
-- Ecommerce Module — Gift Card Coupon Type
-- Extends ec_coupons.type to allow balance-backed gift cards.
-- ============================================================

ALTER TABLE ec_coupons
    MODIFY COLUMN type ENUM('percent','fixed','gift_card') NOT NULL DEFAULT 'percent';