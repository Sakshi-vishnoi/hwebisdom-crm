<?php
/**
 * Test script for AI Research & Two-Pass Writer Agent
 * Usage: php scripts/test_ai_agent.php <lead_id>
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AiResearchDraftAgent.php';

$leadId = (int)($argv[1] ?? 1);

echo "=================================================================\n";
echo "  TESTING AI RESEARCH & OUTREACH WRITING AGENT FOR LEAD #{$leadId}\n";
echo "=================================================================\n\n";

$agent = new AiResearchDraftAgent($pdo);

// Stage 1: CRM Context
echo "--- STAGE 1: CRM Context ---\n";
$crm = $agent->collectCrmContext($leadId);
echo "Contact: {$crm['full_name']} (First: {$crm['first_name']})\n";
echo "Company: {$crm['company']} (Cleaned: {$crm['cleaned_company']})\n";
echo "Website: " . ($crm['website'] ?: 'None') . "\n";
echo "Industry: " . ($crm['industry'] ?: 'Unspecified') . "\n";
echo "Cleaned Company: {$crm['cleaned_company']}\n\n";

// Stage 2: Deep Research
echo "--- STAGE 2: Deep Research Dossier ---\n";
$dossier = $agent->researchLead($crm, function($stage, $progress) {
    echo "  [{$progress}%] {$stage}\n";
}, true); // force fresh

echo "\nDOSSIER OUTPUT:\n";
echo json_encode($dossier, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// Stage 3: Two-Pass Outreach Writing
echo "--- STAGE 3: Two-Pass Writing (Writer + Critic) ---\n";
$senderName = 'Admin'; // Test with Admin to verify resolution to Saranya
$draft = $agent->draftEmail($dossier, $crm, $senderName, 'professional', '');

echo "--- STAGE 4: Anti-Copy & Verification Report ---\n";
$antiCopyScore = $draft['similarity_score'] ?? 0;
echo "ANTI-COPY SIMILARITY SCORE: {$antiCopyScore}%\n";
echo "WORD COUNT: " . str_word_count($draft['body']) . " words\n";
echo "SUBJECT: {$draft['subject']}\n";
echo "SUBJECT ALTERNATIVES:\n";
foreach ((array)($draft['subject_alt'] ?? []) as $alt) {
    echo "  - {$alt}\n";
}
echo "PERSONALISATION HOOK USED: " . ($draft['personalisation_line'] ?? 'None (Simpler honest outreach)') . "\n\n";

echo "--- CRITIC REPORT ---\n";
echo json_encode($draft['critic_report'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if (!empty($draft['warnings'])) {
    echo "WARNINGS / ADVISORIES:\n";
    foreach ($draft['warnings'] as $w) {
        echo "  - {$w}\n";
    }
    echo "\n";
}

echo "==================== FINAL OUTREACH EMAIL ====================\n";
echo $draft['body'] . "\n";
echo "==============================================================\n\n";
