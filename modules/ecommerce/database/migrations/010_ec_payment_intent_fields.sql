ALTER TABLE `ec_payment_transactions`
    ADD COLUMN `payment_intent_id` VARCHAR(255) NULL DEFAULT NULL AFTER `gateway_txn_id`,
    ADD COLUMN `client_key` VARCHAR(255) NULL DEFAULT NULL AFTER `payment_intent_id`,
    ADD INDEX `idx_ec_payment_intent` (`payment_intent_id`);
