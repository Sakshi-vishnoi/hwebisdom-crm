<?php
/**
 * admin/ajax/ai_email_draft_start.php
 * 
 * Initiates an AI Research & Draft Job for a lead
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_sales_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. POST required.']);
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
    echo json_encode(['error' => 'Invalid or expired CSRF token. Please refresh the page and try again.']);
    exit;
}

$user = current_user();
$userId = (int)$user['id'];
$leadId = (int)($input['lead_id'] ?? 0);

if ($leadId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid lead_id is required.']);
    exit;
}

// Check Lead Access
$stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lead) {
    http_response_code(404);
    echo json_encode(['error' => 'Lead not found.']);
    exit;
}

if (!can_view_lead($pdo, $lead)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied: You do not have permission to view or draft emails for this lead.']);
    exit;
}

// Rate Limiting: ~10 drafts per user per hour
$rateStmt = $pdo->prepare("
    SELECT COUNT(*) FROM ai_draft_jobs 
    WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$rateStmt->execute([$userId]);
$recentCount = (int)$rateStmt->fetchColumn();

if ($recentCount >= 15) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded: Maximum 10 AI research drafts per hour. Please wait a few minutes before drafting again.']);
    exit;
}

$tone = trim($input['tone'] ?? 'professional');
$extra = trim($input['extra_instructions'] ?? '');
$forceFresh = !empty($input['force_fresh']) ? 1 : 0;

// Create Job Record
$stmtJob = $pdo->prepare("
    INSERT INTO ai_draft_jobs (lead_id, user_id, status, stage, progress, tone, extra_instructions)
    VALUES (?, ?, 'pending', 'Reading CRM history', 10, ?, ?)
");
$stmtJob->execute([$leadId, $userId, $tone, $extra]);
$jobId = (int)$pdo->lastInsertId();

// Detect PHP Binary Path
$phpBinary = 'php';
$wingetPhp = getenv('LOCALAPPDATA') . '\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe';
if (file_exists($wingetPhp)) {
    $phpBinary = $wingetPhp;
}

// Launch Asynchronous Background Worker
$scriptPath = realpath(__DIR__ . '/../../scripts/run_ai_job.php');
$forceParam = $forceFresh ? ' 1' : ' 0';

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $cmd = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . $jobId . $forceParam;
    @pclose(@popen($cmd, 'r'));
} else {
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . $jobId . $forceParam . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

echo json_encode([
    'success' => true,
    'job_id' => $jobId,
    'status' => 'pending',
    'stage' => 'Reading CRM history',
    'progress' => 10
]);
