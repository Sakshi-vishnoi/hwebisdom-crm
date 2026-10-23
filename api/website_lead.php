<?php
// Public endpoint for the website contact form.
// Expected fields: name, company, email, contact/phone, message.
// Authentication: X-CRM-Lead-Key header or api_key POST field.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$config = require __DIR__ . '/../config/website_leads.php';

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = $config['allowed_origins'] ?? [];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CRM-Lead-Key');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'POST requests only.']);
    exit;
}
if (empty($config['enabled'])) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Website lead intake is disabled.']);
    exit;
}

$providedKey = $_SERVER['HTTP_X_CRM_LEAD_KEY'] ?? ($_POST['api_key'] ?? '');
$expectedKey = (string)($config['api_key'] ?? '');
if ($expectedKey === '' || $expectedKey === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET' ||
    !hash_equals($expectedKey, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key.']);
    exit;
}

// Honeypot: real visitors should leave this empty.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    echo json_encode(['success' => true, 'message' => 'Thank you for contacting us.']);
    exit;
}

$input = $_POST;
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (str_contains($contentType, 'application/json')) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) $input = $json;
}

$name = trim((string)($input['name'] ?? ''));
$company = trim((string)($input['company'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$contact = trim((string)($input['contact'] ?? ($input['phone'] ?? '')));
$message = trim((string)($input['message'] ?? ($input['requirement'] ?? '')));
$priority = trim((string)($input['priority'] ?? ($config['default_priority'] ?? 'Medium')));
$sourceName = 'Website Contact Form';

if ($name === '' && $company === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Name or company is required.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}
if (!in_array($priority, lead_priorities(), true)) {
    $priority = $config['default_priority'] ?? 'Medium';
    if (!in_array($priority, lead_priorities(), true)) $priority = 'Medium';
}

$sourceStmt = $pdo->prepare('SELECT id FROM lead_sources WHERE name=? LIMIT 1');
$sourceStmt->execute([$sourceName]);
$sourceId = (int)$sourceStmt->fetchColumn();
if (!$sourceId) {
    $sourceStmt = $pdo->prepare('INSERT INTO lead_sources(name,is_active) VALUES(?,1)');
    $sourceStmt->execute([$sourceName]);
    $sourceId = (int)$pdo->lastInsertId();
}

$newStageId = (int)$pdo->query("SELECT id FROM pipeline_stages WHERE name='New Lead' LIMIT 1")->fetchColumn();
if (!$newStageId) {
    $pdo->prepare('INSERT INTO pipeline_stages(name,sequence,is_active) VALUES(?,1,1)')->execute(['New Lead']);
    $newStageId = (int)$pdo->lastInsertId();
}

$assignedTo = null;
if (($config['assignment_mode'] ?? 'round_robin') === 'round_robin') {
    $staff = $pdo->query("
        SELECT u.id, u.username, u.email, COUNT(l.id) AS lead_count
        FROM users u
        LEFT JOIN leads l ON l.assigned_to=u.id
        WHERE u.role='staff'
        GROUP BY u.id, u.username, u.email
        ORDER BY lead_count ASC, u.id ASC
    ")->fetchAll();
    if ($staff) $assignedTo = (int)$staff[0]['id'];
}

try {
    $pdo->beginTransaction();

    // Prevent accidental duplicate submissions when the same contact form is
    // retried within 10 minutes.
    $duplicate = null;
    if ($email !== '') {
        $dup = $pdo->prepare("
            SELECT id FROM leads
            WHERE email=? AND message=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ORDER BY id DESC LIMIT 1
        ");
        $dup->execute([$email, $message]);
        $duplicate = (int)$dup->fetchColumn();
    }

    if ($duplicate) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'duplicate' => true,
            'lead_id' => $duplicate,
            'message' => 'Lead already received.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO leads
        (name, company, email, contact, message, status, priority, assigned_to, created_by,
         follow_up_date, notes, source_id, stage_id, stage_subcategory, stage_comments)
        VALUES (?, ?, ?, ?, ?, 'New Lead', ?, ?, NULL, NULL, NULL, ?, ?, 'Email', NULL)
    ");
    $stmt->execute([
        $name ?: null,
        $company ?: null,
        $email ?: null,
        $contact ?: null,
        $message ?: null,
        $priority,
        $assignedTo,
        $sourceId,
        $newStageId
    ]);

    $leadId = (int)$pdo->lastInsertId();
    log_activity($pdo, $leadId, null, 'Website Lead', 'Lead received from the website contact form.');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Website lead intake failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lead could not be saved.']);
    exit;
}

// Optional notification to the assigned salesperson.
if (!empty($config['notify_assignee']) && $assignedTo) {
    try {
        $u = $pdo->prepare('SELECT username,email FROM users WHERE id=?');
        $u->execute([$assignedTo]);
        $assignee = $u->fetch();
        $mailCfg = require __DIR__ . '/../config/mail.php';

        if ($assignee && !empty($assignee['email']) && !empty($mailCfg['enabled'])) {
            $subject = 'New Website Lead #' . $leadId;
            $html = '<p>A new lead has been received from the website contact form.</p>'
                . '<p><strong>Name:</strong> ' . e($name ?: '-') . '<br>'
                . '<strong>Company:</strong> ' . e($company ?: '-') . '<br>'
                . '<strong>Email:</strong> ' . e($email ?: '-') . '<br>'
                . '<strong>Phone:</strong> ' . e($contact ?: '-') . '<br>'
                . '<strong>Requirement:</strong><br>' . nl2br(e($message ?: '-')) . '</p>'
                . '<p>Lead ID: ' . $leadId . '</p>';
            smtp_send($mailCfg, $assignee['email'], $subject, $html);
        }
    } catch (Throwable $ignored) {
        // Lead creation succeeded; notification failure must not reject the form.
    }
}

echo json_encode([
    'success' => true,
    'duplicate' => false,
    'lead_id' => $leadId,
    'assigned_to' => $assignedTo,
    'message' => 'Lead received successfully.'
]);
