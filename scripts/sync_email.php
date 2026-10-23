<?php
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../includes/functions.php';require_once __DIR__.'/../includes/email_sync.php';
[$ok,$count,$message]=sync_inbox($pdo);echo $message.PHP_EOL;exit($ok?0:1);
