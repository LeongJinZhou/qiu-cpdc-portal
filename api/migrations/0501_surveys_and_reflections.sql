-- Slice B: self-assessments gain a 1-5 rating style and written reflections; the yearly University and Programme
-- Survey (docs/SPEC.md section 13). Runs on MySQL 8 and MariaDB 10.11 (JSON is LONGTEXT there: PHP validates it).

-- answer_style 'likert': every question is rated 1-5 (Very Poor .. Excellent); the server writes those five options.
-- reflection_prompts: ["...", ...] or NULL, each one a required written answer of the attempt.
ALTER TABLE assessment
    ADD COLUMN answer_style ENUM('choice', 'likert') NOT NULL DEFAULT 'choice' AFTER description,
    ADD COLUMN reflection_prompts JSON NULL AFTER bands_json;

-- reflection_answers: ["...", ...] in the order of the prompts. faculty is the directory department when the attempt
-- was saved; semester is the one the person typed (1-12), so CPDC can average traits per faculty and semester.
ALTER TABLE assessment_attempt
    ADD COLUMN reflection_answers JSON NULL AFTER band_label,
    ADD COLUMN faculty VARCHAR(200) NULL AFTER reflection_answers,
    ADD COLUMN semester TINYINT UNSIGNED NULL AFTER faculty,
    ADD CONSTRAINT ck_assessment_attempt_semester CHECK (semester IS NULL OR semester BETWEEN 1 AND 12),
    ADD KEY ix_assessment_attempt_group (assessment_id, faculty, semester);

-- One survey round per academic year (or more). CPDC opens and closes it; students answer while it is open.
CREATE TABLE survey_cycle (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    status ENUM('draft', 'open', 'closed') NOT NULL DEFAULT 'draft',
    opens_at DATETIME(3) NULL,
    closes_at DATETIME(3) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    KEY ix_survey_cycle_status (organisation_id, status),
    KEY ix_survey_cycle_created_by (created_by),
    CONSTRAINT ck_survey_cycle_dates CHECK (closes_at IS NULL OR opens_at IS NULL OR closes_at > opens_at),
    CONSTRAINT fk_survey_cycle_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id),
    CONSTRAINT fk_survey_cycle_created_by FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One response per person per cycle: a draft while they answer, final once submitted.
-- answers_json {"item_key": 1-5 | "na"} and comments_json {"section_key": "..."}: keys from Cpdc\Survey\SurveyForm,
-- checked in PHP before they are stored. faculty comes from the directory, never from the browser.
CREATE TABLE survey_response (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    cycle_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    faculty VARCHAR(200) NULL,
    programme VARCHAR(200) NULL,
    semester TINYINT UNSIGNED NULL,
    answers_json JSON NOT NULL,
    comments_json JSON NOT NULL,
    submitted_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_survey_response_person (cycle_id, user_id),
    KEY ix_survey_response_report (organisation_id, cycle_id, status, faculty, programme),
    KEY ix_survey_response_user (user_id),
    CONSTRAINT ck_survey_response_semester CHECK (semester IS NULL OR semester BETWEEN 1 AND 12),
    CONSTRAINT fk_survey_response_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id),
    CONSTRAINT fk_survey_response_cycle FOREIGN KEY (cycle_id) REFERENCES survey_cycle (id) ON DELETE CASCADE,
    CONSTRAINT fk_survey_response_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
