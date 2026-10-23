-- AI Research & Draft Agent Tables Migration for Webisdom CRM

CREATE TABLE IF NOT EXISTS ai_draft_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending, running, completed, failed
    stage VARCHAR(100) NULL,
    progress INT NOT NULL DEFAULT 0,
    tone VARCHAR(50) NOT NULL DEFAULT 'professional',
    extra_instructions TEXT NULL,
    result_json LONGTEXT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_job_lead (lead_id),
    INDEX idx_job_user (user_id),
    INDEX idx_job_status (status),
    INDEX idx_job_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_email_drafts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NULL,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    template_used VARCHAR(50) NOT NULL DEFAULT 'bfsi_intro',
    subject VARCHAR(255) NOT NULL,
    subject_alt VARCHAR(255) NULL,
    body LONGTEXT NOT NULL,
    personalisation_line TEXT NULL,
    dossier_json LONGTEXT NOT NULL,
    sources_json LONGTEXT NULL,
    confidence VARCHAR(20) NOT NULL DEFAULT 'medium',
    model VARCHAR(100) NULL,
    was_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_lead (lead_id),
    INDEX idx_draft_user (user_id),
    INDEX idx_draft_created (created_at),
    INDEX idx_draft_sent (was_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add optional lead enrichment fields if not present
SET @dbname = DATABASE();

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'designation'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE leads ADD COLUMN designation VARCHAR(100) NULL AFTER name;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'website'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE leads ADD COLUMN website VARCHAR(255) NULL AFTER company;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'industry'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE leads ADD COLUMN industry VARCHAR(100) NULL AFTER website;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'city'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE leads ADD COLUMN city VARCHAR(100) NULL AFTER industry;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'country'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE leads ADD COLUMN country VARCHAR(100) NULL AFTER city;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
