<?php
/**
 * admin/ajax/ai_email_draft_status.php
 * 
 * Polling endpoint to check status and fetch results of an AI Research & Draft Job
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_sales_write();

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid job_id parameter is required.']);
    exit;
}

$user = current_user();
$userId = (int)$user['id'];

$stmt = $pdo->prepare("SELECT * FROM ai_draft_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found.']);
    exit;
}

// User authorization check
if ($job['user_id'] != $userId && !in_array($user['role'], ['admin', 'b2b_manager'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied to this research job.']);
    exit;
}

// Fallback execution check:
// If status is still 'pending' after 3 seconds, the background process may not have started.
// Execute inline so the user is never left hanging.
$elapsed = time() - strtotime($job['created_at']);
if ($job['status'] === 'pending' && $elapsed >= 3) {
    require_once __DIR__ . '/../../includes/AiResearchDraftAgent.php';
    try {
        $agent = new AiResearchDraftAgent($pdo);
        $leadId = (int)$job['lead_id'];
        $crmContext = $agent->collectCrmContext($leadId);
        
        $pdo->prepare("UPDATE ai_draft_jobs SET status = 'running', stage = 'Researching company', progress = 45 WHERE id = ?")->execute([$jobId]);
        
        $dossier = $agent->researchLead($crmContext, function($stage, $progress) use ($pdo, $jobId) {
            $pdo->prepare("UPDATE ai_draft_jobs SET stage = ?, progress = ? WHERE id = ?")->execute([$stage, $progress, $jobId]);
        });

        $stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmtUser->execute([$job['user_id']]);
        $uName = $stmtUser->fetchColumn();
        $senderName = $uName ? ucfirst($uName) : 'Saranya';

        $draft = $agent->draftEmail($dossier, $crmContext, $senderName, $job['tone'] ?: 'professional', $job['extra_instructions'] ?: '');
        $draftId = $agent->saveDraft($leadId, (int)$job['user_id'], $draft, $jobId);
        $draft['draft_id'] = $draftId;

        $pdo->prepare("UPDATE ai_draft_jobs SET status = 'completed', stage = 'Ready', progress = 100, result_json = ? WHERE id = ?")
            ->execute([json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobId]);

        // Reload updated job
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        $pdo->prepare("UPDATE ai_draft_jobs SET status = 'failed', error_message = ? WHERE id = ?")
            ->execute([$e->getMessage(), $jobId]);
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Check 90s overall timeout
if (in_array($job['status'], ['pending', 'running'], true) && $elapsed > 90) {
    $timeoutMsg = 'Research and drafting timed out after 90 seconds. You can retry with a specific website URL or company name.';
    $pdo->prepare("UPDATE ai_draft_jobs SET status = 'failed', error_message = ? WHERE id = ?")->execute([$timeoutMsg, $jobId]);
    $job['status'] = 'failed';
    $job['error_message'] = $timeoutMsg;
}

// Return formatted status
if ($job['status'] === 'completed') {
    $result = json_decode($job['result_json'] ?? '{}', true);
    echo json_encode([
        'status' => 'completed',
        'stage' => 'Ready',
        'progress' => 100,
        'result' => $result
    ]);
} elseif ($job['status'] === 'failed') {
    echo json_encode([
        'status' => 'failed',
        'error' => $job['error_message'] ?: 'An error occurred during AI research and drafting.'
    ]);
} else {
    echo json_encode([
        'status' => $job['status'],
        'stage' => $job['stage'] ?: 'Processing research...',
        'progress' => (int)($job['progress'] ?: 20)
    ]);
}
