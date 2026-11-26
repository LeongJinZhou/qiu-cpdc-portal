-- Slice A: the placement (industry visit) form on top of the slice 2a internship, its change history and the
-- post-internship satisfaction survey (docs/SPEC.md section 13). Runs on MySQL 8.4 and MariaDB 10.11.
-- business_nature is one of the list in Cpdc\Placement\BusinessNature (checked in PHP, not by an ENUM, so the list
-- can grow without a migration). Allowance 0 = unpaid. HR columns are NULL when the company has no HR person.

ALTER TABLE internship
    ADD COLUMN business_nature VARCHAR(80) NULL AFTER company,
    ADD COLUMN company_address_1 VARCHAR(200) NULL AFTER business_nature,
    ADD COLUMN company_address_2 VARCHAR(200) NULL AFTER company_address_1,
    ADD COLUMN company_address_3 VARCHAR(200) NULL AFTER company_address_2,
    ADD COLUMN company_postcode VARCHAR(10) NULL AFTER company_address_3,
    ADD COLUMN company_state VARCHAR(80) NULL AFTER company_postcode,
    ADD COLUMN company_country VARCHAR(80) NULL AFTER company_state,
    ADD COLUMN batch_month TINYINT UNSIGNED NULL AFTER end_date,
    ADD COLUMN batch_year SMALLINT UNSIGNED NULL AFTER batch_month,
    ADD COLUMN roles_responsibilities TEXT NULL AFTER batch_year,
    ADD COLUMN monthly_allowance_rm DECIMAL(10, 2) NOT NULL DEFAULT 0 AFTER roles_responsibilities,
    ADD COLUMN has_hr_contact TINYINT(1) NOT NULL DEFAULT 1 AFTER monthly_allowance_rm,
    ADD COLUMN hr_name VARCHAR(200) NULL AFTER has_hr_contact,
    ADD COLUMN hr_phone VARCHAR(40) NULL AFTER hr_name,
    ADD COLUMN hr_email VARCHAR(254) NULL AFTER hr_phone,
    ADD COLUMN supervisor_phone VARCHAR(40) NULL AFTER supervisor_email,
    ADD COLUMN placement_submitted_at DATETIME(3) NULL AFTER hr_email,
    ADD KEY ix_internship_batch (organisation_id, batch_year, batch_month),
    ADD KEY ix_internship_business_nature (organisation_id, business_nature);

-- One row per change to a submitted placement: why, and the placement before and after (JSON objects).
CREATE TABLE internship_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    internship_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    change_reason ENUM('company_change', 'date_change', 'supervisor_hr_update', 'allowance_role_update', 'correction')
        NOT NULL,
    remarks VARCHAR(1000) NULL,
    old_snapshot JSON NOT NULL,
    new_snapshot JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    KEY ix_internship_change_log_internship (internship_id, created_at),
    KEY ix_internship_change_log_org (organisation_id, created_at),
    CONSTRAINT fk_internship_change_log_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id),
    CONSTRAINT fk_internship_change_log_internship FOREIGN KEY (internship_id) REFERENCES internship (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_internship_change_log_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The intern's answer to the satisfaction survey, one per internship, editable by its owner.
CREATE TABLE internship_satisfaction (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    internship_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    recommend_juniors TINYINT(1) NOT NULL,
    overall_rating TINYINT UNSIGNED NOT NULL,
    offered_job TINYINT(1) NOT NULL,
    feedback TEXT NULL,
    submitted_at DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_internship_satisfaction_internship (internship_id),
    KEY ix_internship_satisfaction_user (user_id),
    KEY ix_internship_satisfaction_org (organisation_id, overall_rating),
    CONSTRAINT fk_internship_satisfaction_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id),
    CONSTRAINT fk_internship_satisfaction_internship FOREIGN KEY (internship_id) REFERENCES internship (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_internship_satisfaction_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
