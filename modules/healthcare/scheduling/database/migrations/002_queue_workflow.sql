ALTER TABLE ehr_appointments
    ADD COLUMN queue_ticket_number INT UNSIGNED DEFAULT NULL AFTER status,
    ADD COLUMN queue_destination VARCHAR(40) DEFAULT NULL AFTER queue_ticket_number,
    ADD COLUMN queue_called_at DATETIME DEFAULT NULL AFTER queue_destination,
    ADD COLUMN queue_called_by_user_id BIGINT UNSIGNED DEFAULT NULL AFTER queue_called_at,
    ADD COLUMN room_assignment VARCHAR(80) DEFAULT NULL AFTER queue_called_by_user_id,
    ADD KEY idx_ehr_appointments_queue_lane (queue_destination, status, scheduled_start),
    ADD KEY idx_ehr_appointments_queue_ticket (queue_ticket_number),
    ADD KEY idx_ehr_appointments_queue_called (queue_called_at);