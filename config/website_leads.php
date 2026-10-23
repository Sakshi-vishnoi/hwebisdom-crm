<?php

require_once __DIR__ . '/env.php';

return [
    'enabled' => filter_var(getenv('CRM_LEADS_API_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'api_key' => getenv('CRM_LEADS_API_KEY') ?: '',
    'default_priority' => getenv('CRM_LEADS_DEFAULT_PRIORITY') ?: 'Medium',
    'assignment_mode' => getenv('CRM_LEADS_ASSIGNMENT_MODE') ?: 'round_robin',
    'notify_assignee' => filter_var(getenv('CRM_LEADS_NOTIFY_ASSIGNEE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'allowed_origins' => [],
];
