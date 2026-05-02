DROP TRIGGER IF EXISTS gm_cases_before_insert_unique_student_email;

CREATE TRIGGER gm_cases_before_insert_unique_student_email
BEFORE INSERT ON gm_cases
FOR EACH ROW
BEGIN
    IF NEW.student_email IS NOT NULL THEN
        SET NEW.student_email = NULLIF(TRIM(NEW.student_email), '');
    END IF;

    IF NEW.deleted_at IS NULL AND NEW.student_email IS NOT NULL THEN
        IF EXISTS (
            SELECT 1
            FROM gm_cases
            WHERE deleted_at IS NULL
              AND student_email IS NOT NULL
              AND LOWER(TRIM(student_email)) = LOWER(TRIM(NEW.student_email))
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Active student email must be unique';
        END IF;
    END IF;
END;

DROP TRIGGER IF EXISTS gm_cases_before_update_unique_student_email;

CREATE TRIGGER gm_cases_before_update_unique_student_email
BEFORE UPDATE ON gm_cases
FOR EACH ROW
BEGIN
    IF NEW.student_email IS NOT NULL THEN
        SET NEW.student_email = NULLIF(TRIM(NEW.student_email), '');
    END IF;

    IF NEW.deleted_at IS NULL AND NEW.student_email IS NOT NULL THEN
        IF EXISTS (
            SELECT 1
            FROM gm_cases
            WHERE id <> OLD.id
              AND deleted_at IS NULL
              AND student_email IS NOT NULL
              AND LOWER(TRIM(student_email)) = LOWER(TRIM(NEW.student_email))
            LIMIT 1
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Active student email must be unique';
        END IF;
    END IF;
END;