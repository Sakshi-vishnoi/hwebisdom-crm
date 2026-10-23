<?php
/**
 * scripts/run_ai_job.php
 * 
 * CLI Background Worker for processing an AI Research & Draft Job
 * Usage: php scripts/run_ai_job.php <job_id>
 */

set_time_limit(90);
ini_set('max_execution_time', '90');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AiResearchDraftAgent.php';

$jobId = (int)($argv[1] ?? ($_GET['job_id'] ?? 0));
if ($jobId < 1) {
    exit("Invalid job ID.\n");
}

$stmt = $pdo->prepare("SELECT * FROM ai_draft_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    exit("Job not found.\n");
}

if ($job['status'] === 'completed') {
    exit("Job already completed.\n");
}

$updateStmt = $pdo->prepare("UPDATE ai_draft_jobs SET status = ?, stage = ?, progress = ?, updated_at = NOW() WHERE id = ?");
$updateStmt->execute(['running', 'Reading CRM history', 15, $jobId]);

$agent = new AiResearchDraftAgent($pdo);

try {
    // Stage 1: CRM Context
    $leadId = (int)$job['lead_id'];
    $crmContext = $agent->collectCrmContext($leadId);

    // Progress callback to update database job record
    $progressCallback = function(string $stage, int $progress) use ($pdo, $jobId) {
        $st = $pdo->prepare("UPDATE ai_draft_jobs SET stage = ?, progress = ?, updated_at = NOW() WHERE id = ?");
        $st->execute([$stage, $progress, $jobId]);
    };

    // Stage 2: Deep Research
    $forceFresh = (bool)($_GET['force_fresh'] ?? false);
    $dossier = $agent->researchLead($crmContext, $progressCallback, $forceFresh);

    // Stage 3: Draft
    $progressCallback('Writing draft', 85);

    // Determine sender name and custom signature from user's SMTP profile
    $stmtSmtp = $pdo->prepare("SELECT from_name, signature_text FROM user_smtp_settings WHERE user_id = ? LIMIT 1");
    $stmtSmtp->execute([(int)$job['user_id']]);
    $smtpRow = $stmtSmtp->fetch(PDO::FETCH_ASSOC);

    $rawSenderName = $smtpRow['from_name'] ?? null;
    $customSignature = !empty($smtpRow['signature_text']) ? trim($smtpRow['signature_text']) : null;

    if (empty($rawSenderName)) {
        $stmtUser = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmtUser->execute([$job['user_id']]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $rawSenderName = $userRow['username'] ?? '';
    }

    $senderName = $agent->resolveSenderName($rawSenderName);
    $tone = $job['tone'] ?: 'professional';
    $extra = $job['extra_instructions'] ?: '';

    $draft = $agent->draftEmail($dossier, $crmContext, $senderName, $tone, $extra, $customSignature);

    // Stage 4: Ready
    $progressCallback('Ready', 100);

    // Save to ai_email_drafts table
    $draftId = $agent->saveDraft($leadId, (int)$job['user_id'], $draft, $jobId);
    $draft['draft_id'] = $draftId;

    // Mark job completed
    $completeStmt = $pdo->prepare("
        UPDATE ai_draft_jobs 
        SET status = 'completed', stage = 'Ready', progress = 100, result_json = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $completeStmt->execute([json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobId]);

    echo "JOB_SUCCESS\n";

} catch (Throwable $e) {
    $errStmt = $pdo->prepare("
        UPDATE ai_draft_jobs 
        SET status = 'failed', error_message = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $errStmt->execute([$e->getMessage(), $jobId]);
    error_log("AI Job {$jobId} failed: " . $e->getMessage());
    echo "JOB_FAILED: " . $e->getMessage() . "\n";
}
