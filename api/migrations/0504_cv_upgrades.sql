-- Slice B: CV upgrades. New sections (courses, awards, co-curricular, references, CGPA) live in the validated JSON of
-- cv_document. The PDPA consent to share the CV with QIU partner employers gets its own column, so CPDC can later
-- list consenting students without reading every document. NULL = no consent.

ALTER TABLE cv_document
    ADD COLUMN employer_sharing_consent_at DATETIME(3) NULL AFTER content_json,
    ADD KEY ix_cv_document_sharing (organisation_id, employer_sharing_consent_at);
