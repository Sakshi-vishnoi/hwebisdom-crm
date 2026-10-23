<?php
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../includes/auth.php';require_admin();
function column_exists(PDO $pdo,string $table,string $column):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$s->execute([$table,$column]);return(bool)$s->fetchColumn();}
function index_exists(PDO $pdo,string $table,string $index):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");$s->execute([$table,$index]);return(bool)$s->fetchColumn();}
try{
 if(!column_exists($pdo,'leads','created_by')){$pdo->exec('ALTER TABLE leads ADD COLUMN created_by INT UNSIGNED NULL AFTER assigned_to');}
 if(!index_exists($pdo,'leads','idx_leads_created_by')){$pdo->exec('ALTER TABLE leads ADD INDEX idx_leads_created_by (created_by)');}
 if(!column_exists($pdo,'meetings','google_event_id')){$pdo->exec('ALTER TABLE meetings ADD COLUMN google_event_id VARCHAR(255) NULL AFTER meeting_link');}
 if(!column_exists($pdo,'meetings','google_meet_link')){$pdo->exec('ALTER TABLE meetings ADD COLUMN google_meet_link VARCHAR(500) NULL AFTER google_event_id');}
 if(!column_exists($pdo,'meetings','google_calendar_id')){$pdo->exec('ALTER TABLE meetings ADD COLUMN google_calendar_id VARCHAR(255) NULL AFTER google_meet_link');}
 if(!index_exists($pdo,'meetings','idx_meeting_google_event')){$pdo->exec('ALTER TABLE meetings ADD INDEX idx_meeting_google_event (google_event_id)');}
 $pdo->exec("CREATE TABLE IF NOT EXISTS google_tokens(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL UNIQUE,access_token TEXT NULL,refresh_token TEXT NULL,expires_at INT UNSIGNED NULL,google_email VARCHAR(190) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS email_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',message_uid VARCHAR(255) NULL,message_id VARCHAR(500) NULL,thread_key VARCHAR(500) NULL,from_email VARCHAR(255) NULL,from_name VARCHAR(255) NULL,to_email TEXT NULL,cc_email TEXT NULL,subject VARCHAR(500) NULL,body_text LONGTEXT NULL,body_html LONGTEXT NULL,received_at DATETIME NULL,raw_headers LONGTEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_email_uid(message_uid),INDEX idx_email_lead_date(lead_id,received_at),INDEX idx_email_message_id(message_id(191)),INDEX idx_email_thread(thread_key(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("UPDATE leads l SET created_by=COALESCE(created_by,assigned_to) WHERE created_by IS NULL AND assigned_to IS NOT NULL");
 flash('success','CRM integration tables and fields are ready.');
}catch(Throwable $e){flash('error','Integration upgrade failed: '.$e->getMessage());}
redirect('index.php');
