<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/functions.php';
$messages=[];$errors=[];
function has_col(PDO $pdo,string $t,string $c):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->execute([$t,$c]);return(bool)$s->fetchColumn();}
function has_table(PDO $pdo,string $t):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$s->execute([$t]);return(bool)$s->fetchColumn();}
function has_index2(PDO $pdo,string $t,string $i):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");$s->execute([$t,$i]);return(bool)$s->fetchColumn();}
try{
// MySQL DDL statements (CREATE/ALTER TABLE) implicitly commit, so this
// migration must not wrap the schema changes in a single transaction.

$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'admin', email VARCHAR(150) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Convert the old enum safely so new roles can be used.
$roleType=$pdo->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
if($roleType==='enum'){$pdo->exec("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'admin'");$messages[]='Updated users.role for B2B roles.';}
if(!has_col($pdo,'users','email')){$pdo->exec("ALTER TABLE users ADD email VARCHAR(150) NULL AFTER role");$messages[]='Added user email.';}
$pdo->exec("CREATE TABLE IF NOT EXISTS lead_sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS pipeline_stages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,sequence INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS leads (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NULL,email VARCHAR(150) NULL,contact VARCHAR(30) NULL,message TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'New',priority VARCHAR(20) NOT NULL DEFAULT 'Medium',assigned_to INT UNSIGNED NULL,created_by INT UNSIGNED NULL,follow_up_date DATE NULL,notes TEXT NULL,company VARCHAR(150) NULL,source_id INT UNSIGNED NULL,stage_id INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$leadCols=['company'=>"VARCHAR(150) NULL AFTER message",'source_id'=>"INT UNSIGNED NULL AFTER company",'stage_id'=>"INT UNSIGNED NULL AFTER source_id",'created_by'=>"INT UNSIGNED NULL AFTER assigned_to",'stage_subcategory'=>"VARCHAR(100) NULL AFTER stage_id",'stage_comments'=>"TEXT NULL AFTER stage_subcategory",'client_category'=>"VARCHAR(30) NOT NULL DEFAULT 'Prospect' AFTER stage_comments",'client_label'=>"VARCHAR(10) NOT NULL DEFAULT 'Cold' AFTER client_category"];foreach($leadCols as $c=>$d){if(!has_col($pdo,'leads',$c)){$pdo->exec("ALTER TABLE leads ADD COLUMN `$c` $d");$messages[]="Added leads.$c";}}
if(!has_index2($pdo,'leads','idx_leads_stage'))$pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_stage(stage_id)");
if(!has_index2($pdo,'leads','idx_leads_source'))$pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_source(source_id)");
if(!has_index2($pdo,'leads','idx_leads_assigned'))$pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_assigned(assigned_to)");if(!has_index2($pdo,'leads','idx_leads_created_by'))$pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_created_by(created_by)");
$pdo->exec("CREATE TABLE IF NOT EXISTS lead_followups (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,assigned_to INT UNSIGNED NULL,follow_up_date DATE NOT NULL,follow_up_time TIME NULL,type VARCHAR(50) NOT NULL DEFAULT 'Call',notes TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'Pending',completed_at DATETIME NULL,created_by INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,INDEX idx_followup_lead(lead_id),INDEX idx_followup_date(follow_up_date),INDEX idx_followup_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS meetings (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,meeting_number TINYINT UNSIGNED NOT NULL DEFAULT 1,title VARCHAR(150) NOT NULL,meeting_date DATE NOT NULL,start_time TIME NOT NULL,end_time TIME NULL,meeting_link VARCHAR(500) NULL,location VARCHAR(255) NULL,description TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'Scheduled',created_by INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,INDEX idx_meeting_lead(lead_id),INDEX idx_meeting_date(meeting_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_participants (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meeting_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,email VARCHAR(150) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_participant_meeting(meeting_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,document_type VARCHAR(80) NOT NULL DEFAULT 'Other',file_name VARCHAR(255) NOT NULL,file_path VARCHAR(500) NOT NULL,uploaded_by INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_document_lead(lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS activities (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,activity_type VARCHAR(50) NOT NULL,description TEXT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_activity_lead(lead_id),INDEX idx_activity_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS negotiations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,lead_id INT UNSIGNED NOT NULL,handled_by INT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'In Progress',expected_amount DECIMAL(15,2) NULL,final_amount DECIMAL(15,2) NULL,notes TEXT NULL,next_action TEXT NULL,closed_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_negotiation_lead(lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$sourceSeed=['Google / Website','Website Contact Form','LinkedIn','LinkedIn Post / Outreach','Email','Third Party'];$s=$pdo->prepare('INSERT IGNORE INTO lead_sources(name) VALUES(?)');foreach($sourceSeed as $x)$s->execute([$x]);
$stageSeed=[
 ['New Lead',1],
 ['Meetings Scheduled',2],
 ['Collateral Document Preparation DA Team',3],
 ['Collateral Document Shared with client',4],
 ['Under Negotiation',5],
 ['Converted/Won',6],
 ['Exploratory Meeting',7]
];
$s=$pdo->prepare('INSERT IGNORE INTO pipeline_stages(name,sequence,is_active) VALUES(?,?,1)');
foreach($stageSeed as $x)$s->execute($x);

// Keep any legacy stage records for historical references, but remove them
// from the active pipeline.
$activeNames=array_column($stageSeed,0);
$legacyParams=[];
$legacyPlaceholders=implode(',',array_fill(0,count($activeNames),'?'));
$legacyParams=$activeNames;
$pdo->prepare("UPDATE pipeline_stages SET is_active=0 WHERE name NOT IN ($legacyPlaceholders)")->execute($legacyParams);

// Existing legacy user role "closer" is now part of the Sales Team role.
$pdo->exec("UPDATE users SET role='staff' WHERE role='closer'");
// Migrate existing leads without destroying the old status field.
$stageRows=$pdo->query('SELECT id,name FROM pipeline_stages')->fetchAll();$stageByName=[];foreach($stageRows as $r)$stageByName[$r['name']]=(int)$r['id'];
$websiteId=(int)$pdo->query("SELECT id FROM lead_sources WHERE name='Google / Website' LIMIT 1")->fetchColumn();
$newStageId=(int)$stageByName['New Lead'];
$pdo->exec("ALTER TABLE leads MODIFY source_id INT UNSIGNED NULL DEFAULT $websiteId");
$pdo->exec("ALTER TABLE leads MODIFY stage_id INT UNSIGNED NULL DEFAULT $newStageId");
$leads=$pdo->query('SELECT id,status,source_id,stage_id FROM leads')->fetchAll();$up=$pdo->prepare('UPDATE leads SET source_id=COALESCE(source_id,?),stage_id=COALESCE(stage_id,?) WHERE id=?');foreach($leads as $l){$name=normalize_stage_from_legacy((string)$l['status']);$sid=$stageByName[$name]??$stageByName['New Lead'];$up->execute([(int)$websiteId,$sid,(int)$l['id']]);}
$messages[]='B2B database structure is ready.';
$count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}catch(Throwable $e){$errors[]=$e->getMessage();$count=0;}
if(!isset($count))$count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if($count===0 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_admin'])){
$username=trim($_POST['username']??'');$password=$_POST['password']??'';$confirm=$_POST['confirm_password']??'';
if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username))$errors[]='Username must be 3-50 characters and contain only letters, numbers, dot, underscore or hyphen.';
if(strlen($password)<8)$errors[]='Password must be at least 8 characters.';if($password!==$confirm)$errors[]='Passwords do not match.';
if(!$errors){$s=$pdo->prepare("INSERT INTO users(username,password,role) VALUES(?,?, 'admin')");$s->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);$messages[]='Admin account created successfully.';$count=1;}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CRM Setup</title><link rel="stylesheet" href="../assets/css/crm.css"></head><body class="login-page"><div class="login-card"><div class="login-logo"><div class="brand-mark">W</div><h1>B2B CRM Setup</h1><p>Database migration and first admin account</p></div><?php foreach($messages as $m):?><div class="alert success"><?=e($m)?></div><?php endforeach;?><?php foreach($errors as $m):?><div class="alert danger"><?=e($m)?></div><?php endforeach;?><?php if($count===0):?><form method="post"><input type="hidden" name="create_admin" value="1"><div class="field"><label>Admin Username</label><input class="form-control" name="username" required></div><div class="field"><label>Password</label><input class="form-control" type="password" name="password" minlength="8" required></div><div class="field"><label>Confirm Password</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div><button class="btn btn-primary" type="submit">Create Admin Account</button></form><?php else:?><div class="alert success">Setup is complete. Delete or rename <strong>admin/migrate.php</strong> from the server before normal use.</div><a class="btn btn-primary" href="login.php">Go to Login</a><?php endif;?></div></body></html>
