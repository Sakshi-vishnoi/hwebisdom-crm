<?php

declare(strict_types=1);

/**
 * admin/ajax/smtp_test_connection.php
 * 
 * Authenticates with the user's SMTP server without sending an email.
 * Redacts any sensitive data and returns friendly status messages.
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

// Support JSON or Form-encoded POST
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

// Load existing settings for user to handle masked password
$stmt = $pdo->prepare('SELECT * FROM user_smtp_settings WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

$host = trim($input['host'] ?? ($existing['smtp_host'] ?? ''));
$port = (int)($input['port'] ?? ($existing['smtp_port'] ?? 587));
$encryption = strtolower(trim($input['encryption'] ?? ($existing['encryption'] ?? 'tls')));
$username = trim($input['username'] ?? ($existing['smtp_username'] ?? ''));
$rawPassword = $input['password'] ?? '';

// If password is masked or empty, use stored password if available
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
    'from_email' => $input['from_email'] ?? ($existing['from_email'] ?? $username),
    'from_name' => $input['from_name'] ?? ($existing['from_name'] ?? $user['username']),
];

[$ok, $message] = smtp_test_connection($cfg);

// Discard plain password immediately
unset($cfg, $rawPassword);

// Update database verification status if setting exists
if ($existing) {
    if ($ok) {
        $up = $pdo->prepare('UPDATE user_smtp_settings SET is_verified = 1, last_verified_at = NOW(), last_error = NULL WHERE id = ?');
        $up->execute([$existing['id']]);
    } else {
        $up = $pdo->prepare('UPDATE user_smtp_settings SET is_verified = 0, last_error = ? WHERE id = ?');
        $up->execute([$message, $existing['id']]);
    }
}

echo json_encode([
    'success' => $ok,
    'message' => $ok ? $message : null,
    'error' => $ok ? null : $message,
]);
exit;
