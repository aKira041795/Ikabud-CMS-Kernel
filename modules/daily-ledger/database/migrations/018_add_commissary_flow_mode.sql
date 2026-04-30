ALTER TABLE dl_production_movements
    MODIFY COLUMN flow_mode ENUM('legacy','production','commissary') NOT NULL DEFAULT 'production';
