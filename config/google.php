<?php

require_once __DIR__ . '/env.php';

return [
    'enabled' => filter_var(getenv('GOOGLE_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
    'scope' => 'openid email profile https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.created',
    'calendar_id' => getenv('GOOGLE_CALENDAR_ID') ?: 'primary',
    'timezone' => getenv('CRM_TIMEZONE') ?: 'Asia/Kolkata',
];
