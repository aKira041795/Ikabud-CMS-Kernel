SET FOREIGN_KEY_CHECKS = 0;

-- Add 'pending' status to pal_projects (for quotation-approved flow)
ALTER TABLE pal_projects
    MODIFY COLUMN status ENUM('draft','pending','approved','in_progress','on_hold','completed','cancelled','closed') NOT NULL DEFAULT 'draft';

SET FOREIGN_KEY_CHECKS = 1;
