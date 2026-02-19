-- =============================================
-- Migration: Application Management System
-- Date: 2026-02-19
-- =============================================

-- 1. ENHANCE APPLICATIONS TABLE
ALTER TABLE applications
    MODIFY COLUMN `status` ENUM('draft','ready','submitted','under_review','accepted','rejected','waitlisted','withdrawn') NOT NULL DEFAULT 'draft',
    ADD COLUMN `personal_statement` TEXT NULL AFTER `notes`,
    ADD COLUMN `additional_info` TEXT NULL AFTER `personal_statement`,
    ADD COLUMN `applicant_snapshot` LONGTEXT NULL AFTER `additional_info`,
    ADD COLUMN `submitted_via` ENUM('platform_email','external_link','manual') NOT NULL DEFAULT 'external_link' AFTER `applicant_snapshot`,
    ADD COLUMN `external_url` VARCHAR(512) NULL AFTER `submitted_via`,
    ADD COLUMN `email_sent_to` VARCHAR(255) NULL AFTER `external_url`,
    ADD COLUMN `email_sent_at` DATETIME NULL AFTER `email_sent_to`,
    ADD COLUMN `email_message_id` VARCHAR(255) NULL AFTER `email_sent_at`,
    ADD COLUMN `response_received_at` DATETIME NULL AFTER `email_message_id`,
    ADD COLUMN `response_summary` TEXT NULL AFTER `response_received_at`,
    ADD COLUMN `ai_notes` LONGTEXT NULL AFTER `response_summary`,
    ADD COLUMN `ai_last_analyzed_at` DATETIME NULL AFTER `ai_notes`,
    ADD COLUMN `priority_score` TINYINT UNSIGNED NULL AFTER `ai_last_analyzed_at`;

ALTER TABLE applications ADD INDEX idx_user_status (user_id, status);

-- 2. ENHANCE NOTIFICATIONS TABLE
ALTER TABLE notifications
    MODIFY COLUMN `type` ENUM('new_match','deadline_reminder','application_update','reply_received','system','promotion','ai_suggestion') NOT NULL,
    ADD COLUMN `related_type` ENUM('application','scholarship','system') NULL AFTER `link`,
    ADD COLUMN `related_id` INT UNSIGNED NULL AFTER `related_type`;

-- 3. CREATE APPLICATION TIMELINE TABLE
CREATE TABLE IF NOT EXISTS application_timeline (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id  INT UNSIGNED NOT NULL,
    from_status     ENUM('draft','ready','submitted','under_review','accepted','rejected','waitlisted','withdrawn') NULL,
    to_status       ENUM('draft','ready','submitted','under_review','accepted','rejected','waitlisted','withdrawn') NOT NULL,
    note            TEXT NULL,
    changed_by      ENUM('user','system','email_reply','admin') NOT NULL DEFAULT 'user',
    metadata        LONGTEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_timeline_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    INDEX idx_timeline_application (application_id),
    INDEX idx_timeline_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. CREATE APPLICATION DOCUMENTS TABLE
CREATE TABLE IF NOT EXISTS application_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id  INT UNSIGNED NOT NULL,
    doc_type        ENUM('personal_statement','transcript','recommendation_letter','cv_resume','research_proposal','portfolio','certificate','other') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_path       VARCHAR(512) NOT NULL,
    file_size       INT UNSIGNED NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    INDEX idx_documents_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. CREATE SCHOLARSHIP REQUIREMENTS TABLE
CREATE TABLE IF NOT EXISTS scholarship_requirements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scholarship_id  INT UNSIGNED NOT NULL,
    requirement_type ENUM('personal_statement','transcript','recommendation_letter','cv_resume','research_proposal','portfolio','certificate','language_test','gpa_minimum','work_experience','other') NOT NULL,
    label           VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    is_required     TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_requirements_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE,
    INDEX idx_requirements_scholarship (scholarship_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
