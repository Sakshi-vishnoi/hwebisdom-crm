-- Migration: Per-user SMTP credentials for Webisdom B2B CRM
-- Table: user_smtp_settings and enhancements to email_messages

CREATE TABLE IF NOT EXISTS user_smtp_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    from_name VARCHAR(150) NOT NULL,
    from_email VARCHAR(150) NOT NULL,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT UNSIGNED NOT NULL DEFAULT 587,
    encryption ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password_encrypted TEXT NOT NULL,
    signature_text TEXT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    last_verified_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_smtp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_smtp_verified (is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure email_messages has user_id column for per-user rate limiting and logging
SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'email_messages'
      AND COLUMN_NAME = 'user_id'
  ) > 0,
  'SELECT 1',
  'ALTER TABLE email_messages ADD COLUMN user_id INT UNSIGNED NULL AFTER lead_id, ADD INDEX idx_email_user_time (user_id, direction, created_at)'
));
PREPARE add_user_id_stmt FROM @preparedStatement;
EXECUTE add_user_id_stmt;
DEALLOCATE PREPARE add_user_id_stmt;
