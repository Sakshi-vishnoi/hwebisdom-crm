<?php
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../includes/auth.php';require_admin();require_once __DIR__.'/../includes/email_sync.php';
[$ok,$count,$message]=sync_inbox($pdo);flash($ok?'success':'error',$message);redirect('index.php');
