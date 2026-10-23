<?php

declare(strict_types=1);

/**
 * admin/ajax/smtp_send_test.php
 * 
 * Sends a live verification test email to the user's own email address.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/SmtpCrypto.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. POST required.']);
    exit;
}

$input = $_POST;
$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

// CSRF Verification
$csrf = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
    exit;
}

$user = current_user();
$userId = (int)$user['id'];

// Load existing settings
$stmt = $pdo->prepare('SELECT * FROM user_smtp_settings WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

$fromEmail = trim($input['from_email'] ?? ($existing['from_email'] ?? ''));
$fromName = trim($input['from_name'] ?? ($existing['from_name'] ?? $user['username']));
$host = trim($input['host'] ?? ($existing['smtp_host'] ?? ''));
$port = (int)($input['port'] ?? ($existing['smtp_port'] ?? 587));
$encryption = strtolower(trim($input['encryption'] ?? ($existing['encryption'] ?? 'tls')));
$username = trim($input['username'] ?? ($existing['smtp_username'] ?? ''));
$rawPassword = $input['password'] ?? '';

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'A valid From Email address is required to receive the test email.']);
    exit;
}

// If password is masked or empty, use stored password
if (SmtpCrypto::isMasked((string)$rawPassword)) {
    if (!$existing || empty($existing['smtp_password_encrypted'])) {
        echo json_encode(['success' => false, 'error' => 'No saved password found. Please enter your SMTP password.']);
        exit;
    }
    try {
        $rawPassword = SmtpCrypto::decrypt($existing['smtp_password_encrypted']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Could not decrypt stored password. Please re-enter your password.']);
        exit;
    }
}

$cfg = [
    'host' => $host,
    'port' => $port,
    'encryption' => $encryption,
    'username' => $username,
    'password' => $rawPassword,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
];

$subject = 'Webisdom CRM: SMTP Test Email for ' . ($fromName ?: $user['username']);
$sentTime = date('Y-m-d H:i:s T');
$html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff;">'
    . '<h2 style="color: #0b4e4a; margin-top: 0;">Webisdom CRM: SMTP Verification Test</h2>'
    . '<p style="color: #334155; font-size: 14px; line-height: 1.6;">Hello <strong>' . htmlspecialchars($fromName) . '</strong>,</p>'
    . '<p style="color: #334155; font-size: 14px; line-height: 1.6;">This test email confirms that your personal SMTP configuration in Webisdom CRM is active, authenticated, and functioning properly.</p>'
    . '<div style="background: #f8fafc; border-left: 4px solid #00bcb4; padding: 14px; margin: 18px 0; font-size: 13px; color: #475569;">'
    . '<div><strong>Authenticated Mailbox:</strong> ' . htmlspecialchars($username) . '</div>'
    . '<div><strong>Sender Address:</strong> ' . htmlspecialchars($fromName . ' <' . $fromEmail . '>') . '</div>'
    . '<div><strong>SMTP Server:</strong> ' . htmlspecialchars($host . ':' . $port . ' (' . strtoupper($encryption) . ')') . '</div>'
    . '<div><strong>Timestamp:</strong> ' . htmlspecialchars($sentTime) . '</div>'
    . '</div>'
    . '<p style="color: #64748b; font-size: 12px; margin-bottom: 0;">You can now send outreach and follow-up emails directly from your own mailbox in Webisdom CRM.</p>'
    . '</div>';

[$ok, $message] = smtp_send($cfg, $fromEmail, $subject, $html);

// Discard plain password immediately
unset($cfg, $rawPassword);

if ($ok && $existing) {
    $up = $pdo->prepare('UPDATE user_smtp_settings SET is_verified = 1, last_verified_at = NOW(), last_error = NULL WHERE id = ?');
    $up->execute([$existing['id']]);
} elseif (!$ok && $existing) {
    $up = $pdo->prepare('UPDATE user_smtp_settings SET is_verified = 0, last_error = ? WHERE id = ?');
    $up->execute([$message, $existing['id']]);
}

echo json_encode([
    'success' => $ok,
    'message' => $ok ? "Test email sent successfully to $fromEmail! Please check your inbox." : null,
    'error' => $ok ? null : $message,
]);
exit;
