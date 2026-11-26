-- Slice A: more detail on a job posting (docs/SPEC.md section 13). All nullable: older jobs keep working.
-- specializations: comma-separated fields of study the job suits. attachment_file: the random 32-hex key of an
-- optional PDF brochure under STORAGE_DIR/uploads/job/ (never the client's file name, that is attachment_name).

ALTER TABLE job_posting
    ADD COLUMN specializations VARCHAR(255) NULL AFTER description,
    ADD COLUMN vacancy_count SMALLINT UNSIGNED NULL AFTER specializations,
    ADD COLUMN duration_months SMALLINT UNSIGNED NULL AFTER vacancy_count,
    ADD COLUMN salary_min DECIMAL(10, 2) NULL AFTER duration_months,
    ADD COLUMN salary_max DECIMAL(10, 2) NULL AFTER salary_min,
    ADD COLUMN pay_frequency ENUM('monthly', 'hourly', 'lump_sum', 'unpaid', 'negotiable') NULL AFTER salary_max,
    ADD COLUMN min_qualification VARCHAR(160) NULL AFTER pay_frequency,
    ADD COLUMN work_experience VARCHAR(160) NULL AFTER min_qualification,
    ADD COLUMN other_requirements TEXT NULL AFTER work_experience,
    ADD COLUMN benefits TEXT NULL AFTER other_requirements,
    ADD COLUMN country VARCHAR(80) NULL AFTER location,
    ADD COLUMN state VARCHAR(80) NULL AFTER country,
    ADD COLUMN contact_email VARCHAR(254) NULL AFTER benefits,
    ADD COLUMN attachment_file CHAR(32) NULL AFTER contact_email,
    ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_file,
    ADD UNIQUE KEY uq_job_posting_attachment (attachment_file),
    ADD KEY ix_job_posting_state (organisation_id, state);
