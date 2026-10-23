<?php
// Generic IMAP inbox used to pull lead emails into the CRM.
// On Windows/XAMPP, enable the PHP IMAP extension first.
return [
    'enabled' => false,
    'host' => getenv('CRM_IMAP_HOST') ?: 'imap.your-domain.com',
    'port' => (int)(getenv('CRM_IMAP_PORT') ?: 993),
    'encryption' => getenv('CRM_IMAP_ENCRYPTION') ?: 'ssl',
    'username' => getenv('CRM_IMAP_USER') ?: 'sales@your-domain.com',
    'password' => getenv('CRM_IMAP_PASSWORD') ?: 'YOUR_IMAP_PASSWORD',
    'folder' => getenv('CRM_IMAP_FOLDER') ?: 'INBOX',
    'since_days' => (int)(getenv('CRM_IMAP_SINCE_DAYS') ?: 30),
];
