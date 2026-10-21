-- Existing installations ONLY: apply once if columns consent_recorded_* exist.
-- Fresh installations use setup.sql and must not run this upgrade.
-- Preserve existing templates; metadata now describes registration, not consent.
ALTER TABLE student_face_templates
    CHANGE COLUMN consent_recorded_by registered_by INT(11) NOT NULL,
    CHANGE COLUMN consent_recorded_at registered_at DATETIME NOT NULL;
