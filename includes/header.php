<?php

require_once __DIR__ . '/functions.php';

$user = current_user();

$page_title = $page_title ?? 'Dashboard';
$current_page = $current_page ?? '';

function nav_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg>',
        'clients' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.25"/><path d="M3.5 20c.5-3.4 2.3-5.2 5.5-5.2s5 1.8 5.5 5.2M15 14.5c2.8-.1 4.7 1.5 5.2 4.5"/></svg>',
        'leads' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.25"/><path d="M5.5 20c.6-3.6 2.7-5.4 6.5-5.4s5.9 1.8 6.5 5.4"/><path d="M19 7.5h2.5M20.25 6.25v2.5"/></svg>',
        'add' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
        'pipeline' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="4" width="4" height="16" rx="1"/><rect x="10" y="4" width="4" height="16" rx="1"/><rect x="16.5" y="4" width="4" height="16" rx="1"/></svg>',
        'followups' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 13.5-5.8L20 8.5"/><path d="M20 4.5v4h-4"/><path d="M20 12a8 8 0 0 1-13.5 5.8L4 15.5"/><path d="M4 19.5v-4h4"/></svg>',
        'meetings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M7 3.5v4M17 3.5v4M3.5 9.5h17"/><path d="M8 13h3M13 13h3M8 16.5h3"/></svg>',
        'documents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3.5h8l4 4V20.5H6z"/><path d="M14 3.5v4h4M9 12h6M9 15.5h6"/></svg>',
        'sources' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/><path d="M9 8.5l6 7M15 8.5l-6 7"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.5-3.3 2.3-5 5.5-5s5 1.7 5.5 5"/><circle cx="17.5" cy="9" r="2.25"/><path d="M15.5 14.5c2.8-.1 4.6 1.4 5 4"/></svg>',
        'email_settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>',
        'password' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10M12 14v2"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10"/><path d="M13 8l4 4-4 4M17 12H9"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function nav_link(string $key, string $href, string $label, string $icon, string $current): string
{
    $active = $current === $key ? ' active' : '';
    $aria = $active ? ' aria-current="page"' : '';

    return '<a class="nav-item' . $active . '" href="' . e($href) . '"' . $aria . '>' .
        '<span class="nav-icon">' . nav_icon($icon) . '</span>' .
        '<span class="nav-label">' . e($label) . '</span>' .
        '</a>';
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($page_title) ?> | Webisdom B2B CRM</title>
    <link rel="stylesheet" href="../assets/css/crm.css">
</head>

<body>

<div class="app-shell">

    <div class="mobile-overlay" id="mobileOverlay" aria-hidden="true"></div>

    <aside class="sidebar" id="sidebar">

        <div class="brand">
            <div class="brand-mark">W</div>
            <div class="brand-copy">
                <strong>WEBISDOM</strong>
                <span>B2B CRM</span>
            </div>
        </div>

        <div class="sidebar-nav">
            <nav class="nav-menu" aria-label="CRM navigation">

                <div class="nav-group">
                    <div class="nav-group-title">Sales</div>

                    <?= nav_link('dashboard', 'index.php', 'Dashboard', 'dashboard', $current_page) ?>
                    <?= nav_link('leads', 'leads.php', 'Leads', 'leads', $current_page) ?>
                    <?= nav_link('clients', 'clients.php', 'Clients', 'clients', $current_page) ?>
                    <?php if ($user['role'] !== 'qc'): ?>
                    <?= nav_link('create_lead', 'create_lead.php', 'Add Lead', 'add', $current_page) ?>
                    <?php endif; ?>
                    <?= nav_link('pipeline', 'pipeline.php', 'Pipeline', 'pipeline', $current_page) ?>
                    <?= nav_link('followups', 'followups.php', 'Follow-ups', 'followups', $current_page) ?>
                    <?= nav_link('meetings', 'meetings.php', 'Meetings', 'meetings', $current_page) ?>
                </div>

                <div class="nav-group">
                    <div class="nav-group-title">Workspace</div>

                    <?= nav_link('documents', 'documents.php', 'Documents', 'documents', $current_page) ?>
                </div>

                <?php if ($user['role'] === 'admin'): ?>
                    <div class="nav-group">
                        <div class="nav-group-title">Management</div>

                        <?= nav_link('sources', 'sources.php', 'Lead Sources', 'sources', $current_page) ?>
                        <?= nav_link('users', 'users.php', 'Users', 'users', $current_page) ?>
                    </div>
                <?php endif; ?>

                <div class="nav-group">
                    <div class="nav-group-title">Account</div>

                    <?= nav_link('email_settings', 'my_email_settings.php', 'My Email Settings', 'email_settings', $current_page) ?>
                    <?= nav_link('password', 'change_password.php', 'Change Password', 'password', $current_page) ?>
                </div>

            </nav>
        </div>

        <div class="sidebar-footer">

            <div class="user-card">
                <div class="user-avatar">
                    <?= e(strtoupper(substr($user['username'] ?? 'U', 0, 1))) ?>
                </div>

                <div class="user-details">
                    <strong><?= e($user['username'] ?? 'User') ?></strong>
                    <span><?= e(role_label($user['role'] ?? 'staff')) ?></span>
                </div>
            </div>

            <a href="logout.php" class="logout-link">
                <span class="logout-icon"><?= nav_icon('logout') ?></span>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <main class="main-content">

        <header class="topbar">

            <div class="topbar-left">
                <button class="menu-btn" id="menuBtn" type="button" aria-label="Open navigation" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <div class="topbar-heading">
                    <div class="breadcrumb">Webisdom B2B CRM</div>
                    <h1><?= e($page_title) ?></h1>
                    <p><?= date('l, d M Y') ?></p>
                </div>
            </div>

            <div class="topbar-user">
                <span>Signed in as</span>
                <strong><?= e($user['username']) ?></strong>
                <span class="topbar-avatar"><?= e(strtoupper(substr($user['username'] ?? 'U', 0, 1))) ?></span>
            </div>

        </header>

        <div class="page-body">

            <?php if ($success = flash('success')): ?>
                <div class="alert success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($error = flash('error')): ?>
                <div class="alert danger"><?= e($error) ?></div>
            <?php endif; ?>