<?php

require_once __DIR__ . '/env.php';

$port = (int)(getenv('CRM_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 465);
$isSecure = filter_var(getenv('SMTP_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$encryption = getenv('CRM_SMTP_ENCRYPTION') ?: ($port === 465 || $isSecure ? 'ssl' : 'tls');

$rawPass = getenv('CRM_SMTP_PASSWORD') ?: getenv('SMTP_PASS') ?: '';
// Strip spaces from Gmail App Password if needed (e.g. "abcd efgh ijkl mnop" -> "abcdefghijklmnop")
$password = (strlen(str_replace(' ', '', $rawPass)) === 16) ? str_replace(' ', '', $rawPass) : $rawPass;

$fromEmail = getenv('CRM_FROM_EMAIL') ?: getenv('SMTP_USER') ?: getenv('CRM_SMTP_USER') ?: 'outreach@webisdom.com';

return [
    'enabled' => filter_var(getenv('CRM_SMTP_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'test_mode' => filter_var(getenv('CRM_SMTP_TEST_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'host' => getenv('CRM_SMTP_HOST') ?: getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port' => $port,
    'encryption' => $encryption,
    'username' => getenv('CRM_SMTP_USER') ?: getenv('SMTP_USER') ?: '',
    'password' => $password,
    'from_email' => $fromEmail,
    'from_name' => getenv('CRM_FROM_NAME') ?: getenv('SMTP_FROM_NAME') ?: 'Webisdom B2B CRM',
];
