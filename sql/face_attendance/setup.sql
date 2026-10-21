-- Run once against the same DB as students/classes/attendance. Additive only.
CREATE TABLE IF NOT EXISTS student_face_templates (
    student_id INT(11) NOT NULL,
    encrypted_vectors MEDIUMTEXT NOT NULL,
    model_version VARCHAR(96) NOT NULL,
    template_version CHAR(64) NOT NULL,
    registered_by INT(11) NOT NULL,
    registered_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id),
    CONSTRAINT fk_face_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS face_attendance_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT(11) NOT NULL,
    class_id INT(11) NOT NULL,
    actor_user_id INT(11) NOT NULL,
    event VARCHAR(24) NOT NULL,
    attendance_date DATE NOT NULL,
    activity_type VARCHAR(30) NOT NULL,
    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    similarity DECIMAL(7,6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_face_audit_class_date (class_id, attendance_date),
    KEY idx_face_audit_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
