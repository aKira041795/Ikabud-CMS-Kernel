ALTER TABLE dl_deliveries
    ADD INDEX idx_dl_deliveries_status_date (status, delivery_date);

ALTER TABLE dl_branch_receivings
    ADD INDEX idx_dl_brcv_delivery_status (delivery_id, status);