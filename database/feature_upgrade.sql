-- Run this once on the CRM database.
-- It is additive and preserves existing CRM data.

ALTER TABLE leads ADD COLUMN created_by INT UNSIGNED NULL AFTER assigned_to;
ALTER TABLE leads ADD INDEX idx_leads_created_by (created_by);

CREATE TABLE IF NOT EXISTS google_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at INT UNSIGNED NULL,
    google_email VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE meetings ADD COLUMN google_event_id VARCHAR(255) NULL AFTER meeting_link;
ALTER TABLE meetings ADD COLUMN google_meet_link VARCHAR(500) NULL AFTER google_event_id;
ALTER TABLE meetings ADD COLUMN google_calendar_id VARCHAR(255) NULL AFTER google_meet_link;
ALTER TABLE meetings ADD INDEX idx_meeting_google_event (google_event_id);

CREATE TABLE IF NOT EXISTS email_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
    message_uid VARCHAR(255) NULL,
    message_id VARCHAR(500) NULL,
    thread_key VARCHAR(500) NULL,
    from_email VARCHAR(255) NULL,
    from_name VARCHAR(255) NULL,
    to_email TEXT NULL,
    cc_email TEXT NULL,
    subject VARCHAR(500) NULL,
    body_text LONGTEXT NULL,
    body_html LONGTEXT NULL,
    received_at DATETIME NULL,
    raw_headers LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_uid (message_uid),
    INDEX idx_email_lead_date (lead_id, received_at),
    INDEX idx_email_message_id (message_id(191)),
    INDEX idx_email_thread (thread_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New sales workflow and role model.
ALTER TABLE leads ADD COLUMN stage_subcategory VARCHAR(100) NULL AFTER stage_id;
ALTER TABLE leads ADD COLUMN stage_comments TEXT NULL AFTER stage_subcategory;
ALTER TABLE leads ADD INDEX idx_leads_stage_subcategory (stage_subcategory);

UPDATE users SET role='staff' WHERE role='closer';

INSERT IGNORE INTO pipeline_stages (name,sequence,is_active) VALUES
('New Lead',1,1),
('Meetings Scheduled',2,1),
('Collateral Document Preparation DA Team',3,1),
('Collateral Document Shared with client',4,1),
('Under Negotiation',5,1),
('Converted/Won',6,1),
('Exploratory Meeting',7,1);

UPDATE pipeline_stages
SET is_active=0
WHERE name NOT IN (
    'New Lead',
    'Meetings Scheduled',
    'Collateral Document Preparation DA Team',
    'Collateral Document Shared with client',
    'Under Negotiation',
    'Converted/Won',
    'Exploratory Meeting'
);

INSERT IGNORE INTO lead_sources (name,is_active) VALUES ('Website Contact Form',1);

