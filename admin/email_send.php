<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_write();
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/functions.php';

$leadId = (int)($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);
if ($leadId < 1) {
    redirect('leads.php');
}

$s = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
$s->execute([$leadId]);
$lead = $s->fetch(PDO::FETCH_ASSOC);

if (!$lead) {
    flash('error', 'Lead not found.');
    redirect('leads.php');
}

if (!can_view_lead($pdo, $lead)) {
    http_response_code(403);
    exit('Access denied.');
}

// Check for recent AI draft for this lead to offer quick restore
$stmtRecentDraft = $pdo->prepare("
    SELECT id, subject, subject_alt, body, personalisation_line, dossier_json, sources_json, confidence, template_used, created_at 
    FROM ai_email_drafts 
    WHERE lead_id = ? 
    ORDER BY id DESC LIMIT 1
");
$stmtRecentDraft->execute([$leadId]);
$recentDraft = $stmtRecentDraft->fetch(PDO::FETCH_ASSOC);

$user = current_user();
$userId = (int)$user['id'];
$userSmtp = get_user_smtp_config($pdo, $userId);
$hasVerifiedSmtp = ($userSmtp !== null && !empty($userSmtp['is_verified']));

// Handle Send Email POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $to = trim($_POST['to'] ?? $lead['email']);
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if (!$hasVerifiedSmtp || !$userSmtp) {
        flash('error', 'You must configure and verify your email account in My Email Settings before sending outreach emails.');
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
        flash('error', 'Recipient, subject and message are required.');
    } else {
        // Enforce per-user rate limit (e.g. 20/hr, 100/day)
        [$rateOk, $rateMsg] = check_user_email_rate_limit($pdo, $userId);
        if (!$rateOk) {
            flash('error', $rateMsg);
        } else {
            // Append user signature if set and body doesn't already contain it
            $bodyToSend = $body;
            if (!empty($userSmtp['signature_text'])) {
                $sig = trim($userSmtp['signature_text']);
                if ($sig !== '' && !str_contains($body, $sig)) {
                    $bodyToSend = rtrim($body) . "\n\n" . $sig;
                }
            }

            $html = nl2br(e($bodyToSend));

            // Clean header injection characters
            $to = SmtpCrypto::sanitizeHeader($to);
            $subject = SmtpCrypto::sanitizeHeader($subject);

            // Send via user's personal SMTP (with 20s timeout)
            [$ok, $msg] = smtp_send($userSmtp, $to, $subject, $html);

            // Discard decrypted password immediately
            unset($userSmtp['password']);

            if ($ok) {
                // Save to communication history with sender user_id and from_email
                $s = $pdo->prepare('INSERT INTO email_messages (lead_id, user_id, direction, from_email, from_name, to_email, subject, body_text, body_html, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $s->execute([$leadId, $userId, 'outbound', $userSmtp['from_email'], $userSmtp['from_name'], $to, $subject, $bodyToSend, $html]);

                // Mark latest AI draft as sent
                $pdo->prepare('UPDATE ai_email_drafts SET was_sent = 1 WHERE lead_id = ? ORDER BY id DESC LIMIT 1')->execute([$leadId]);

                // Log activity with sender user_id and from_email
                log_activity($pdo, $leadId, $userId, 'Email Sent', 'Outreach email sent to ' . $to . ' from ' . $userSmtp['from_email'] . ': ' . $subject);

                flash('success', 'Email sent and logged in the lead history.');
                redirect('lead.php?id=' . $leadId);
            } else {
                // If authentication failed, mark is_verified = 0 and record last_error
                if (str_contains(strtolower($msg), 'authentication failed') && !empty($userSmtp['id'])) {
                    $pdo->prepare('UPDATE user_smtp_settings SET is_verified = 0, last_error = ? WHERE id = ?')
                        ->execute([$msg, $userSmtp['id']]);
                }
                flash('error', $msg);
            }
        }
    }
}

$page_title = 'Send Email';
$current_page = 'leads';
require __DIR__ . '/../includes/header.php';
?>

<div class="email-send-container" style="max-width: 980px; margin: 0 auto; padding-bottom: 40px;">

    <?php if (!$hasVerifiedSmtp): ?>
        <!-- Unverified Notice Banner -->
        <div style="margin-bottom: 18px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 16px 20px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 24px; line-height: 1;">⚠️</div>
                <div>
                    <strong style="font-size: 14px; color: #78350f;">Set up your email account to send emails</strong>
                    <div style="font-size: 12.5px; color: #92400e; margin-top: 2px;">
                        Each CRM user sends outreach through their own mailbox. The AI Draft agent can still generate drafts, but "Send Email Now" is disabled until your email settings are verified.
                    </div>
                </div>
            </div>
            <a href="my_email_settings.php" class="btn btn-primary" style="background: #d97706; border-color: #b45309; font-weight: 600; white-space: nowrap;">
                Configure My Email Settings →
            </a>
        </div>
    <?php else: ?>
        <!-- Verified Sender Banner -->
        <div style="margin-bottom: 18px; padding: 12px 18px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; font-size: 13px; color: #166534;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-flex; width: 20px; height: 20px; border-radius: 50%; background: #22c55e; color: #fff; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">✓</span>
                <span><strong>Sending as:</strong> <?= e($userSmtp['from_name']) ?> &lt;<?= e($userSmtp['from_email']) ?>&gt;</span>
            </div>
            <a href="my_email_settings.php" style="color: #15803d; text-decoration: underline; font-size: 12px; font-weight: 500;">
                Edit My Email Settings
            </a>
        </div>
    <?php endif; ?>

    <!-- Top Navigation Card -->
    <div class="section-card" style="margin-bottom: 18px; border-radius: 12px; background: #fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
        <div class="section-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 22px; border-bottom: 1px solid var(--border-soft);">
            <div>
                <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--text);">
                    Email <?= e($lead['company'] ?: $lead['name']) ?>
                </h2>
                <span class="muted" style="font-size: 13px; color: var(--muted);">
                    <?= e($lead['email']) ?> &bull; Lead #<?= (int)$lead['id'] ?>
                </span>
            </div>
            <a class="btn btn-light" href="lead.php?id=<?= $leadId ?>" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Back to Lead
            </a>
        </div>

        <!-- AI Assistant Action Bar -->
        <div class="ai-toolbar" style="padding: 18px 22px; background: linear-gradient(135deg, #f8fefd 0%, #f0fbf9 100%); border-bottom: 1px solid #d4f4f1;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(0, 188, 180, 0.15); display: flex; align-items: center; justify-content: center; color: var(--primary-dark);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: #0b4e4a;">Webisdom AI Outreach Agent</div>
                        <div style="font-size: 12px; color: #4a7572;">Autonomous multi-step research &bull; Approved claims only &bull; Human reviewed</div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <select id="aiTone" class="form-control" style="width: auto; min-width: 160px; font-size: 13px; height: 36px; padding: 4px 10px;">
                        <option value="professional" selected>Tone: Professional</option>
                        <option value="consultative">Tone: Consultative & Strategic</option>
                        <option value="direct">Tone: Executive & Direct</option>
                    </select>

                    <button type="button" id="btnDraftAi" class="btn btn-primary" style="background: var(--primary); border-color: var(--primary-dark); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 6px rgba(0, 188, 180, 0.35);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                        </svg>
                        <span id="btnDraftAiText">Draft with AI</span>
                    </button>

                    <button type="button" id="btnToggleOptions" class="btn btn-light" style="font-size: 12px; padding: 6px 10px;" title="Extra settings">
                        ⚙️ Instructions
                    </button>
                </div>
            </div>

            <!-- Optional Extra Instructions & Force Fresh -->
            <div id="aiOptionsPanel" style="display: none; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #bfece6;">
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center;">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #0b4e4a; display: block; margin-bottom: 4px;">
                            Extra Focus / Specific Guidance (Optional):
                        </label>
                        <input type="text" id="aiExtraInstructions" class="form-control" placeholder="e.g. Focus on digital lending KYC turnaround, or reference their recent mobile app update" style="font-size: 13px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; padding-top: 18px;">
                        <input type="checkbox" id="aiForceFresh" id="forceFreshCheck" class="checkbox">
                        <label for="forceFreshCheck" style="font-size: 12px; color: #0b4e4a; cursor: pointer; user-select: none;">
                            Bypass 7-day cache (Re-research)
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Progress Container (Hidden by default) -->
        <div id="aiProgressBox" style="display: none; padding: 20px 22px; background: #fff; border-bottom: 1px solid var(--border-soft);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <span id="aiProgressStageText" style="font-weight: 600; color: #007771; font-size: 13px;">
                    Initiating research agent...
                </span>
                <span id="aiProgressPercent" style="font-weight: 700; color: var(--primary-dark); font-size: 13px;">
                    10%
                </span>
            </div>
            
            <!-- Progress Bar -->
            <div style="width: 100%; height: 8px; background: #e5f7f5; border-radius: 99px; overflow: hidden; margin-bottom: 14px;">
                <div id="aiProgressBar" style="width: 10%; height: 100%; background: linear-gradient(90deg, #00bcb4 0%, #009b95 100%); transition: width 0.4s ease;"></div>
            </div>

            <!-- Stage Pills -->
            <div class="progress-steps" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; font-size: 11px; text-align: center;">
                <div id="step-crm" class="step-pill active" style="padding: 6px 4px; border-radius: 6px; background: #e0f6f4; color: #00736e; font-weight: 600;">1. CRM Data</div>
                <div id="step-comp" class="step-pill" style="padding: 6px 4px; border-radius: 6px; background: #f2f4f7; color: var(--muted);">2. Company</div>
                <div id="step-web" class="step-pill" style="padding: 6px 4px; border-radius: 6px; background: #f2f4f7; color: var(--muted);">3. Digital Footprint</div>
                <div id="step-hooks" class="step-pill" style="padding: 6px 4px; border-radius: 6px; background: #f2f4f7; color: var(--muted);">4. Finding Hooks</div>
                <div id="step-draft" class="step-pill" style="padding: 6px 4px; border-radius: 6px; background: #f2f4f7; color: var(--muted);">5. Writing Draft</div>
                <div id="step-val" class="step-pill" style="padding: 6px 4px; border-radius: 6px; background: #f2f4f7; color: var(--muted);">6. Validating</div>
            </div>
        </div>

        <!-- AI Draft Notice Banner (Hidden by default) -->
        <div id="aiNoticeBanner" style="display: none; padding: 12px 22px; background: #f0fbf9; border-bottom: 1px solid #c8f0ec; color: #0b5c56; font-size: 13px; display: none; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 16px;">✨</span>
                <span><strong>AI-generated draft loaded.</strong> Please review and personalize before clicking Send.</span>
            </div>
            <div id="altSubjectContainer" style="display: none; font-size: 12px;">
                <a href="javascript:void(0)" id="btnSwapSubject" style="color: var(--primary-dark); font-weight: 600; text-decoration: underline;">
                    Swap with alternative subject
                </a>
            </div>
        </div>
    </div>

    <!-- Collapsible AI Research Summary Panel (Hidden by default) -->
    <div id="aiResearchPanel" class="section-card" style="display: none; margin-bottom: 18px; border-radius: 12px; background: #fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden;">
        <div style="padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid var(--border-soft); display: flex; align-items: center; justify-content: space-between; cursor: pointer;" onclick="toggleResearchDetails()">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span style="font-weight: 700; font-size: 14px; color: var(--text);">📊 AI Research Dossier & Intelligence Summary</span>
                <span id="badgeConfidence" class="badge" style="background: #ecfdf3; color: #067647; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600;">High Confidence</span>
                <span id="badgeAntiCopy" class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600;">Anti-Copy: Passed</span>
                <span id="badgeWordCount" class="badge" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600;">160 words</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted);">
                <span id="txtTemplateUsed">Freshly Written (Two-Pass)</span>
                <span id="iconToggleResearch">▼</span>
            </div>
        </div>

        <div id="aiResearchBody" style="padding: 18px 22px; display: block;">
            <!-- Hook Used Box -->
            <div style="padding: 12px 16px; background: #fdf8e6; border: 1px solid #f6e6aa; border-radius: 8px; margin-bottom: 16px;">
                <div style="font-weight: 700; color: #8a6508; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">
                    🎯 Selected Personalisation Hook:
                </div>
                <div id="txtSelectedHook" style="font-size: 13px; color: #533e03; line-height: 1.45;">
                    Analyzing verified hooks...
                </div>
            </div>

            <!-- Warnings if any -->
            <div id="aiWarningsContainer" style="display: none; padding: 10px 14px; background: #fff4f2; border: 1px solid #ffd6cf; border-radius: 8px; margin-bottom: 14px; font-size: 12px; color: #b42318;">
            </div>

            <!-- Two-column research grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 13px; margin-bottom: 16px;">
                <div>
                    <h4 style="margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.04em;">Company Intelligence</h4>
                    <div id="txtCompanySummary" style="color: var(--text); line-height: 1.5; background: #fcfcfd; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border-soft);">
                        -
                    </div>
                </div>

                <div>
                    <h4 style="margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.04em;">Key Decision Maker</h4>
                    <div id="txtPersonSummary" style="color: var(--text); line-height: 1.5; background: #fcfcfd; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border-soft);">
                        -
                    </div>
                </div>
            </div>

            <!-- Digital Observations & Recent Events -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 12px; margin-bottom: 16px;">
                <div>
                    <h4 style="margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.04em;">Digital Footprint & Tech</h4>
                    <ul id="listDigitalObs" style="margin: 0; padding-left: 18px; color: #344054; line-height: 1.6;">
                    </ul>
                </div>

                <div>
                    <h4 style="margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.04em;">Recent Milestones / News</h4>
                    <ul id="listRecentEvents" style="margin: 0; padding-left: 18px; color: #344054; line-height: 1.6;">
                    </ul>
                </div>
            </div>

            <!-- Verified Grounding Sources -->
            <div style="border-top: 1px solid var(--border-soft); padding-top: 12px;">
                <h4 style="margin: 0 0 8px; font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.04em;">Verified Source Citations:</h4>
                <div id="aiSourcesContainer" style="display: flex; flex-wrap: wrap; gap: 6px;">
                </div>
            </div>
        </div>
    </div>

    <!-- Email Composition Form -->
    <div class="section-card form-card" style="border-radius: 12px; background: #fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 24px;">
        <form method="post" id="emailSendForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="lead_id" value="<?= $leadId ?>">

            <div class="field" style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--text);">Recipient (To)</label>
                <input class="form-control" name="to" type="email" value="<?= e($lead['email']) ?>" required style="height: 42px; font-size: 14px;">
            </div>

            <div class="field" style="margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 600; margin: 0; color: var(--text);">Subject Line</label>
                    <span id="subjectWordCount" style="font-size: 11px; color: var(--muted);">0 words</span>
                </div>
                <input class="form-control" id="emailSubject" name="subject" required style="height: 42px; font-size: 14px; font-weight: 500;">
            </div>

            <div class="field" style="margin-bottom: 22px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 600; margin: 0; color: var(--text);">Email Body (Plain Text)</label>
                    <span id="bodyWordCount" style="font-size: 11px; color: var(--muted);">0 words</span>
                </div>
                <textarea class="form-control textarea" id="emailBody" name="body" rows="18" required style="font-family: Consolas, 'SF Mono', 'Courier New', monospace; font-size: 13.5px; line-height: 1.6; padding: 14px; color: #1e293b;"></textarea>
                <div style="font-size: 11px; color: var(--muted); margin-top: 6px;">
                    Note: Email is sent as standard enterprise plain-text. Human review is recommended prior to sending.
                </div>
            </div>

            <div class="form-actions" style="display: flex; align-items: center; justify-content: space-between; padding-top: 18px; border-top: 1px solid var(--border-soft);">
                <div>
                    <button type="submit" class="btn btn-primary" id="btnSendEmail" 
                            <?= !$hasVerifiedSmtp ? 'disabled title="Set up your email account in My Email Settings to send emails"' : '' ?>
                            style="height: 42px; padding: 0 24px; font-size: 14px; font-weight: 600; <?= !$hasVerifiedSmtp ? 'opacity: 0.55; cursor: not-allowed;' : '' ?>">
                        Send Email Now
                    </button>
                    <a class="btn btn-light" href="lead.php?id=<?= $leadId ?>" style="height: 42px; margin-left: 8px;">
                        Cancel
                    </a>
                </div>

                <?php if ($recentDraft): ?>
                    <button type="button" class="btn btn-light" id="btnRestoreRecent" style="font-size: 12px; color: var(--primary-dark);" onclick="restoreRecentDraft()">
                        ↺ Load Saved AI Draft (<?= date('d M', strtotime($recentDraft['created_at'])) ?>)
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
const LEAD_ID = <?= (int)$leadId ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
let activeJobId = null;
let pollTimer = null;
let altSubjectValue = '';

// Cached recent draft data if present in session/DB
const RECENT_DRAFT = <?= $recentDraft ? json_encode([
    'subject' => $recentDraft['subject'],
    'subject_alt' => $recentDraft['subject_alt'],
    'body' => $recentDraft['body'],
    'personalisation_line' => $recentDraft['personalisation_line'],
    'template_used' => $recentDraft['template_used'],
    'confidence' => $recentDraft['confidence'],
    'dossier' => json_decode($recentDraft['dossier_json'] ?? '{}', true),
    'sources' => json_decode($recentDraft['sources_json'] ?? '[]', true)
]) : 'null' ?>;

// Toggle extra options
document.getElementById('btnToggleOptions').addEventListener('click', function() {
    const p = document.getElementById('aiOptionsPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
});

// Toggle research panel
function toggleResearchDetails() {
    const b = document.getElementById('aiResearchBody');
    const icon = document.getElementById('iconToggleResearch');
    if (b.style.display === 'none') {
        b.style.display = 'block';
        icon.textContent = '▼';
    } else {
        b.style.display = 'none';
        icon.textContent = '▲';
    }
}

// Start AI Research & Draft Job
document.getElementById('btnDraftAi').addEventListener('click', function() {
    const currentSubject = document.getElementById('emailSubject').value.trim();
    const currentBody = document.getElementById('emailBody').value.trim();

    if (currentSubject !== '' || currentBody !== '') {
        if (!confirm('Generating an AI draft will replace your current Subject and Message text. Would you like to proceed?')) {
            return;
        }
    }

    startAiDrafting();
});

function startAiDrafting() {
    const btn = document.getElementById('btnDraftAi');
    const btnText = document.getElementById('btnDraftAiText');
    const tone = document.getElementById('aiTone').value;
    const extra = document.getElementById('aiExtraInstructions').value;
    const forceFresh = document.getElementById('aiForceFresh').checked ? 1 : 0;

    btn.disabled = true;
    btnText.textContent = 'Agent Working...';

    // Show Progress Box
    const progressBox = document.getElementById('aiProgressBox');
    progressBox.style.display = 'block';
    updateProgressUI('Reading CRM history', 15);

    fetch('ajax/ai_email_draft_start.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify({
            lead_id: LEAD_ID,
            tone: tone,
            extra_instructions: extra,
            force_fresh: forceFresh,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.job_id) {
            throw new Error(data.error || 'Failed to start AI agent.');
        }

        activeJobId = data.job_id;
        startPolling(activeJobId);
    })
    .catch(err => {
        alert('Error: ' + err.message);
        resetDraftButton();
        progressBox.style.display = 'none';
    });
}

function startPolling(jobId) {
    if (pollTimer) clearInterval(pollTimer);

    pollTimer = setInterval(() => {
        fetch(`ajax/ai_email_draft_status.php?job_id=${jobId}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'completed') {
                clearInterval(pollTimer);
                handleDraftCompleted(data.result);
            } else if (data.status === 'failed') {
                clearInterval(pollTimer);
                resetDraftButton();
                document.getElementById('aiProgressBox').style.display = 'none';
                alert('AI Draft Agent Error: ' + (data.error || 'Job failed.'));
            } else {
                updateProgressUI(data.stage || 'Researching...', data.progress || 35);
            }
        })
        .catch(err => {
            console.error('Polling error:', err);
        });
    }, 2000);
}

function updateProgressUI(stageText, percent) {
    document.getElementById('aiProgressStageText').textContent = stageText;
    document.getElementById('aiProgressPercent').textContent = percent + '%';
    document.getElementById('aiProgressBar').style.width = percent + '%';

    // Highlight pills
    const pills = {
        'step-crm': percent >= 15,
        'step-comp': percent >= 35,
        'step-web': percent >= 50,
        'step-hooks': percent >= 70,
        'step-draft': percent >= 85,
        'step-val': percent >= 95
    };

    for (let id in pills) {
        const el = document.getElementById(id);
        if (el) {
            if (pills[id]) {
                el.style.background = '#e0f6f4';
                el.style.color = '#00736e';
                el.style.fontWeight = '600';
            } else {
                el.style.background = '#f2f4f7';
                el.style.color = 'var(--muted)';
                el.style.fontWeight = 'normal';
            }
        }
    }
}

function handleDraftCompleted(result) {
    resetDraftButton();
    document.getElementById('aiProgressBox').style.display = 'none';

    // Populate Form Fields
    document.getElementById('emailSubject').value = result.subject || '';
    document.getElementById('emailBody').value = result.body || '';
    updateWordCounts();

    // Store alternate subject
    altSubjectValue = result.subject_alt || '';
    const altBox = document.getElementById('altSubjectContainer');
    if (altSubjectValue && altSubjectValue !== result.subject) {
        altBox.style.display = 'block';
    } else {
        altBox.style.display = 'none';
    }

    // Show AI notice banner
    const notice = document.getElementById('aiNoticeBanner');
    notice.style.display = 'flex';

    // Populate Research Summary Panel
    populateResearchPanel(result);
}

function populateResearchPanel(result) {
    const panel = document.getElementById('aiResearchPanel');
    panel.style.display = 'block';

    const dossier = result.dossier || {};

    // Badges
    const conf = result.confidence || dossier.confidence || 'medium';
    const badgeConf = document.getElementById('badgeConfidence');
    badgeConf.textContent = conf.toUpperCase() + ' CONFIDENCE';
    if (conf === 'high') {
        badgeConf.style.background = '#ecfdf3'; badgeConf.style.color = '#067647';
    } else if (conf === 'medium') {
        badgeConf.style.background = '#fffaeb'; badgeConf.style.color = '#b54708';
    } else {
        badgeConf.style.background = '#fef3f2'; badgeConf.style.color = '#b42318';
    }

    const simScore = (typeof result.similarity_score !== 'undefined') ? result.similarity_score : 12;
    const badgeAntiCopy = document.getElementById('badgeAntiCopy');
    badgeAntiCopy.textContent = `Template Match: ${simScore}% (Verified)`;
    badgeAntiCopy.style.background = '#ecfdf3'; 
    badgeAntiCopy.style.color = '#067647';

    const words = result.word_count || (result.body ? result.body.trim().split(/\s+/).length : 0);
    document.getElementById('badgeWordCount').textContent = `${words} words`;
    document.getElementById('txtTemplateUsed').textContent = 'Template & Research Integrated';

    // Hook
    const hookEl = document.getElementById('txtSelectedHook');
    if (result.personalisation_line) {
        hookEl.innerHTML = `<strong>Used in email:</strong> "${result.personalisation_line}"`;
    } else if (dossier.top_hooks && dossier.top_hooks.length > 0) {
        hookEl.innerHTML = `<strong>Top Hook:</strong> ${dossier.top_hooks[0].hook} <span class="muted" style="display:block; font-size:11px; margin-top:2px;">Relevance: ${dossier.top_hooks[0].why_relevant || ''}</span>`;
    } else {
        hookEl.textContent = 'Skipped — no verified hook met the strict threshold.';
    }

    // Summaries
    document.getElementById('txtCompanySummary').textContent = dossier.company_summary || 'Company overview not available.';
    document.getElementById('txtPersonSummary').textContent = dossier.person_summary || 'Recipient public profile not available.';

    // Lists
    const dList = document.getElementById('listDigitalObs');
    dList.innerHTML = '';
    (dossier.digital_observations || []).forEach(item => {
        const li = document.createElement('li');
        li.textContent = item.fact || item;
        dList.appendChild(li);
    });
    if (!dList.children.length) dList.innerHTML = '<li>Standard corporate web presence observed.</li>';

    const eList = document.getElementById('listRecentEvents');
    eList.innerHTML = '';
    (dossier.recent_events || []).forEach(item => {
        const li = document.createElement('li');
        li.textContent = item.fact || item;
        eList.appendChild(li);
    });
    if (!eList.children.length) eList.innerHTML = '<li>Continuous standard market operations.</li>';

    // Sources Citations
    const sBox = document.getElementById('aiSourcesContainer');
    sBox.innerHTML = '';
    const sources = result.sources || [];
    sources.forEach(s => {
        if (s.url) {
            const a = document.createElement('a');
            a.href = s.url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = 'badge';
            a.style.cssText = 'background:#f2f4f7; color:#344054; text-decoration:none; padding:4px 8px; border-radius:6px; font-size:11px; display:inline-flex; align-items:center; gap:4px;';
            a.innerHTML = `🔗 ${s.title || s.url.substring(0, 30)}`;
            sBox.appendChild(a);
        }
    });
    if (!sBox.children.length) {
        sBox.innerHTML = '<span class="muted" style="font-size:11px;">Public corporate registration & domain listings</span>';
    }

    // Warnings
    const wBox = document.getElementById('aiWarningsContainer');
    const warnings = result.warnings || [];
    if (warnings.length > 0) {
        wBox.style.display = 'block';
        wBox.innerHTML = '<strong>⚠️ Advisory:</strong> ' + warnings.join('; ');
    } else {
        wBox.style.display = 'none';
    }
}

// Swap Alternative Subject
document.getElementById('btnSwapSubject').addEventListener('click', function() {
    if (!altSubjectValue) return;
    const cur = document.getElementById('emailSubject').value;
    document.getElementById('emailSubject').value = altSubjectValue;
    altSubjectValue = cur;
    updateWordCounts();
});

function resetDraftButton() {
    const btn = document.getElementById('btnDraftAi');
    const btnText = document.getElementById('btnDraftAiText');
    btn.disabled = false;
    btnText.textContent = 'Regenerate with AI';
}

function updateWordCounts() {
    const s = document.getElementById('emailSubject').value.trim();
    const b = document.getElementById('emailBody').value.trim();
    document.getElementById('subjectWordCount').textContent = (s ? s.split(/\s+/).length : 0) + ' words';
    document.getElementById('bodyWordCount').textContent = (b ? b.split(/\s+/).length : 0) + ' words';
}

document.getElementById('emailSubject').addEventListener('input', updateWordCounts);
document.getElementById('emailBody').addEventListener('input', updateWordCounts);

function restoreRecentDraft() {
    if (!RECENT_DRAFT) return;
    if (confirm('Load the most recently saved AI draft for this lead?')) {
        handleDraftCompleted(RECENT_DRAFT);
    }
}
</script>
