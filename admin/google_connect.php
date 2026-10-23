<?php
require_once __DIR__.'/../config/database.php';require_once __DIR__.'/../includes/auth.php';require_login();require_once __DIR__.'/../includes/google_calendar.php';
if(!google_is_configured()){flash('error','Google Calendar is not configured. Add the OAuth client ID/secret in config/google.php first.');redirect('users.php');}
redirect(google_auth_url(current_user()['id']));
