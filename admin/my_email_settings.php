<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/SmtpCrypto.php';

require_login();

$user = current_user();
$userId = (int)$user['id'];
$page_title = 'My Email Settings';
$current_page = 'email_settings';
$error = '';

// Load existing user SMTP settings
$stmt = $pdo->prepare('SELECT * FROM user_smtp_settings WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fromName = trim($_POST['from_name'] ?? '');
    $fromEmail = trim($_POST['from_email'] ?? '');
    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPort = (int)($_POST['smtp_port'] ?? 587);
    $encryption = strtolower(trim($_POST['encryption'] ?? 'tls'));
    $smtpUsername = trim($_POST['smtp_username'] ?? '');
    $rawPassword = $_POST['smtp_password'] ?? '';
    $signatureText = trim($_POST['signature_text'] ?? '');

    // Validation
    if ($fromName === '') {
        $error = 'Sender Display Name is required.';
    } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid From Email address is required.';
    } elseif ($smtpHost === '') {
        $error = 'SMTP Host is required.';
    } elseif ($smtpPort < 1 || $smtpPort > 65535) {
        $error = 'Valid SMTP Port is required (typically 587 or 465).';
    } elseif (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $error = 'Encryption must be TLS, SSL, or None.';
    } elseif ($smtpUsername === '') {
        $error = 'SMTP Username is required.';
    } else {
        $encryptedPassword = '';
        $plainPasswordForTest = '';

        if (!SmtpCrypto::isMasked($rawPassword)) {
            // New password entered by user
            $plainPasswordForTest = $rawPassword;
            try {
                $encryptedPassword = SmtpCrypto::encrypt($rawPassword);
            } catch (Throwable $e) {
                $error = 'Encryption failed: ' . $e->getMessage();
            }
        } else {
            // Keep existing password
            if (!$settings || empty($settings['smtp_password_encrypted'])) {
                $error = 'SMTP Password is required.';
            } else {
                $encryptedPassword = $settings['smtp_password_encrypted'];
                try {
                    $plainPasswordForTest = SmtpCrypto::decrypt($encryptedPassword);
                } catch (Throwable $e) {
                    $error = 'Could not decrypt stored password. Please enter a new password.';
                }
            }
        }

        if (!$error) {
            // Test connection before marking as verified
            $testCfg = [
                'host' => $smtpHost,
                'port' => $smtpPort,
                'encryption' => $encryption,
                'username' => $smtpUsername,
                'password' => $plainPasswordForTest,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
            ];

            [$testOk, $testMsg] = smtp_test_connection($testCfg);
            unset($testCfg, $plainPasswordForTest);

            $isVerified = $testOk ? 1 : 0;
            $lastVerifiedAt = $testOk ? date('Y-m-d H:i:s') : null;
            $lastError = $testOk ? null : $testMsg;

            try {
                if ($settings) {
                    $up = $pdo->prepare("
                        UPDATE user_smtp_settings SET
                            from_name = ?,
                            from_email = ?,
                            smtp_host = ?,
                            smtp_port = ?,
                            encryption = ?,
                            smtp_username = ?,
                            smtp_password_encrypted = ?,
                            signature_text = ?,
                            is_verified = ?,
                            last_verified_at = ?,
                            last_error = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $up->execute([
                        $fromName,
                        $fromEmail,
                        $smtpHost,
                        $smtpPort,
                        $encryption,
                        $smtpUsername,
                        $encryptedPassword,
                        $signatureText ?: null,
                        $isVerified,
                        $lastVerifiedAt,
                        $lastError,
                        $settings['id']
                    ]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO user_smtp_settings (
                            user_id, from_name, from_email, smtp_host, smtp_port, encryption,
                            smtp_username, smtp_password_encrypted, signature_text, is_verified,
                            last_verified_at, last_error, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([
                        $userId,
                        $fromName,
                        $fromEmail,
                        $smtpHost,
                        $smtpPort,
                        $encryption,
                        $smtpUsername,
                        $encryptedPassword,
                        $signatureText ?: null,
                        $isVerified,
                        $lastVerifiedAt,
                        $lastError
                    ]);
                }

                if ($testOk) {
                    flash('success', 'Email settings saved and verified successfully! You can now send emails through your own mailbox.');
                } else {
                    flash('warning', 'Settings saved, but SMTP connection test failed: ' . $testMsg . '. Please verify your credentials.');
                }

                redirect('my_email_settings.php');
            } catch (PDOException $e) {
                $error = 'Database error saving SMTP settings: ' . $e->getMessage();
            }
        }
    }
}

// Reload settings in case of changes
$stmt = $pdo->prepare('SELECT * FROM user_smtp_settings WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

$hasPassword = !empty($settings['smtp_password_encrypted']);
$isVerified = (int)($settings['is_verified'] ?? 0) === 1;

require __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto; padding-bottom: 40px;">

    <?php if ($error): ?>
        <div class="alert danger" style="margin-bottom: 20px;"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($warn = flash('warning')): ?>
        <div class="alert warning" style="margin-bottom: 20px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 14px 18px; border-radius: 8px;">
            ⚠️ <?= e($warn) ?>
        </div>
    <?php endif; ?>

    <!-- Status Banner Card -->
    <div class="section-card" style="margin-bottom: 20px; border-radius: 12px; background: #fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 20px 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(0, 188, 180, 0.12); display: flex; align-items: center; justify-content: center; color: var(--primary-dark);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--text);">
                        Personal Mailbox Settings (SMTP)
                    </h2>
                    <div style="font-size: 13px; color: var(--muted); margin-top: 2px;">
                        Configure your personal email account to send CRM outreach directly from your mailbox.
                    </div>
                </div>
            </div>

            <!-- Verification Status Badge -->
            <div id="statusBadgeContainer">
                <?php if (!$settings): ?>
                    <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 12px; padding: 6px 12px; border-radius: 99px; font-weight: 600;">
                        ⚪ Not Configured
                    </span>
                <?php elseif ($isVerified): ?>
                    <span class="badge" style="background: #ecfdf3; color: #027a48; border: 1px solid #a6f4c5; font-size: 12px; padding: 6px 12px; border-radius: 99px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                        <span style="font-size: 14px;">✓</span> Verified & Ready
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 12px; padding: 6px 12px; border-radius: 99px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                        <span>⚠️</span> Configured, Not Verified
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($settings && $settings['last_verified_at'] && $isVerified): ?>
            <div style="font-size: 12px; color: #059669; margin-top: 10px; padding-left: 58px;">
                ✓ Successfully authenticated on <?= date('d M Y, h:i A', strtotime($settings['last_verified_at'])) ?>
            </div>
        <?php endif; ?>

        <?php if ($settings && !empty($settings['last_error'])): ?>
            <div id="boxLastError" style="margin-top: 14px; padding: 12px 16px; background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; font-size: 13px; color: #991b1b;">
                <strong>Last Connection Error:</strong> <?= e($settings['last_error']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Live Test Notification Banner (AJAX Response) -->
    <div id="ajaxAlertBanner" style="display: none; margin-bottom: 20px; padding: 14px 18px; border-radius: 8px; font-size: 13px; line-height: 1.5;"></div>

    <!-- Main Settings Form -->
    <div class="section-card form-card" style="border-radius: 12px; background: #fff; border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 26px;">
        <form method="post" id="smtpSettingsForm">
            <input type="hidden" name="csrf_token" id="csrfToken" value="<?= e(csrf_token()) ?>">

            <!-- Provider Presets -->
            <div style="margin-bottom: 22px; padding: 16px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <label style="display: block; font-weight: 700; font-size: 13px; color: #334155; margin-bottom: 6px;">
                    ⚡ Quick Setup: Select Email Provider Preset
                </label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <select id="providerPreset" class="form-control" style="max-width: 320px; height: 38px; font-size: 13px; font-weight: 500;">
                        <option value="custom">Custom SMTP Server</option>
                        <option value="gmail">Google Workspace / Gmail (smtp.gmail.com:587 TLS)</option>
                        <option value="outlook">Microsoft 365 / Outlook (smtp.office365.com:587 TLS)</option>
                        <option value="zoho_in">Zoho Mail India (smtp.zoho.in:587 TLS)</option>
                        <option value="zoho_com">Zoho Mail Global (smtp.zoho.com:587 TLS)</option>
                    </select>
                    <span style="font-size: 12px; color: var(--muted);">Auto-fills host, port, and recommended TLS encryption.</span>
                </div>
            </div>

            <!-- Sender Identity Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px;">
                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        Sender Name (From Name) <span style="color: #e11d48;">*</span>
                    </label>
                    <input class="form-control" id="from_name" name="from_name" type="text" 
                           value="<?= e($settings['from_name'] ?? $user['username']) ?>" 
                           placeholder="e.g. Saranya / Atharv Kumar" required style="height: 40px;">
                    <small class="muted" style="font-size: 11.5px; display: block; margin-top: 4px;">
                        Name displayed in recipient's email client.
                    </small>
                </div>

                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        Sender Email (From Email) <span style="color: #e11d48;">*</span>
                    </label>
                    <input class="form-control" id="from_email" name="from_email" type="email" 
                           value="<?= e($settings['from_email'] ?? $user['email'] ?? '') ?>" 
                           placeholder="e.g. user@webisdom.com" required style="height: 40px;">
                    <small class="muted" style="font-size: 11.5px; display: block; margin-top: 4px;">
                        Replies will be delivered to this address.
                    </small>
                </div>
            </div>

            <!-- Server Connection Grid -->
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        SMTP Host <span style="color: #e11d48;">*</span>
                    </label>
                    <input class="form-control" id="smtp_host" name="smtp_host" type="text" 
                           value="<?= e($settings['smtp_host'] ?? 'smtp.gmail.com') ?>" 
                           placeholder="e.g. smtp.gmail.com" required style="height: 40px;">
                </div>

                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        SMTP Port <span style="color: #e11d48;">*</span>
                    </label>
                    <input class="form-control" id="smtp_port" name="smtp_port" type="number" 
                           value="<?= (int)($settings['smtp_port'] ?? 587) ?>" 
                           min="1" max="65535" required style="height: 40px;">
                </div>

                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        Encryption <span style="color: #e11d48;">*</span>
                    </label>
                    <select class="form-control" id="encryption" name="encryption" required style="height: 40px;">
                        <option value="tls" <?= ($settings['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recommended 587)</option>
                        <option value="ssl" <?= ($settings['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                        <option value="none" <?= ($settings['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                    </select>
                </div>
            </div>

            <!-- Credentials Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 12px;">
                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        SMTP Username <span style="color: #e11d48;">*</span>
                    </label>
                    <input class="form-control" id="smtp_username" name="smtp_username" type="text" 
                           value="<?= e($settings['smtp_username'] ?? $user['email'] ?? '') ?>" 
                           placeholder="Your full email address" required style="height: 40px;">
                    <small class="muted" style="font-size: 11.5px; display: block; margin-top: 4px;">
                        Typically your full email address (e.g. name@webisdom.com).
                    </small>
                </div>

                <div class="field">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                        SMTP Password / App Password <span style="color: #e11d48;">*</span>
                    </label>
                    <div style="position: relative;">
                        <input class="form-control" id="smtp_password" name="smtp_password" type="password" 
                               value="<?= $hasPassword ? SmtpCrypto::MASKED_PLACEHOLDER : '' ?>" 
                               placeholder="<?= $hasPassword ? '•••••••• (leave unchanged to keep)' : 'Enter your App Password' ?>" 
                               required style="height: 40px; padding-right: 40px;">
                        <button type="button" id="btnTogglePassword" style="position: absolute; right: 10px; top: 10px; background: none; border: none; cursor: pointer; color: #64748b; font-size: 14px;" title="Show / Hide">
                            👁️
                        </button>
                    </div>
                    <small class="muted" style="font-size: 11.5px; display: block; margin-top: 4px;">
                        <?= $hasPassword ? 'Password securely saved. Re-type only if changing.' : 'Encrypted with AES-256-GCM. Never logged or exposed.' ?>
                    </small>
                </div>
            </div>

            <!-- Guidance Note Box -->
            <div style="margin-bottom: 22px; padding: 14px 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">
                <div style="font-weight: 700; color: #1e40af; font-size: 12.5px; margin-bottom: 4px;">
                    💡 Notice: Use an App Password for Gmail and Outlook accounts
                </div>
                <div style="font-size: 12px; color: #1e3a8a; line-height: 1.5;">
                    Personal Gmail and Google Workspace accounts with 2-Step Verification do not allow regular login passwords.
                    <a href="javascript:void(0)" onclick="toggleAppPasswordGuide()" style="color: #2563eb; text-decoration: underline; font-weight: 600; margin-left: 4px;">
                        How to generate a Gmail App Password ▼
                    </a>
                </div>
                <div id="appPasswordGuide" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #93c5fd; font-size: 12px; color: #1e3a8a;">
                    <ol style="margin: 0; padding-left: 20px; line-height: 1.7;">
                        <li>Open your <strong>Google Account</strong> (myaccount.google.com) and navigate to <strong>Security</strong>.</li>
                        <li>Ensure <strong>2-Step Verification</strong> is enabled.</li>
                        <li>Search for or select <strong>App passwords</strong>.</li>
                        <li>Enter an app name (e.g. <em>"Webisdom CRM"</em>) and click <strong>Create</strong>.</li>
                        <li>Copy the generated 16-character code (e.g. <code>abcd efgh ijkl mnop</code>) and paste it into the password field above.</li>
                    </ol>
                </div>
            </div>

            <!-- Email Signature -->
            <div class="field" style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text);">
                    Email Signature (Plain Text, Optional)
                </label>
                <textarea class="form-control" id="signature_text" name="signature_text" rows="4" 
                          placeholder="e.g.&#10;Saranya&#10;Senior Digital Consultant, Webisdom&#10;www.webisdom.com | +91 98765 43210" 
                          style="font-family: inherit; font-size: 13px;"><?= e($settings['signature_text'] ?? '') ?></textarea>
                <small class="muted" style="font-size: 11.5px; display: block; margin-top: 4px;">
                    Automatically appended to your outreach emails if not already present.
                </small>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding-top: 18px; border-top: 1px solid var(--border-soft);">
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" id="btnSaveSettings" style="height: 42px; padding: 0 22px; font-weight: 600;">
                        Save & Verify Settings
                    </button>

                    <button type="button" class="btn btn-light" id="btnTestConn" style="height: 42px; padding: 0 18px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                        <span>🔌</span> Test Connection
                    </button>

                    <button type="button" class="btn btn-light" id="btnSendTestEmail" style="height: 42px; padding: 0 18px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                        <span>✉️</span> Send Test Email to Myself
                    </button>
                </div>

                <?php if ($settings): ?>
                    <span class="muted" style="font-size: 11.5px;">
                        Last updated: <?= date('d M Y, h:i A', strtotime($settings['updated_at'] ?? $settings['created_at'])) ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF_TOKEN = document.getElementById('csrfToken').value;

// Toggle App Password Guide
function toggleAppPasswordGuide() {
    const el = document.getElementById('appPasswordGuide');
    el.style.display = (el.style.display === 'none') ? 'block' : 'none';
}

// Toggle password visibility
document.getElementById('btnTogglePassword').addEventListener('click', function() {
    const input = document.getElementById('smtp_password');
    if (input.type === 'password') {
        input.type = 'text';
        this.textContent = '🔒';
    } else {
        input.type = 'password';
        this.textContent = '👁️';
    }
});

// Provider Presets Handler
document.getElementById('providerPreset').addEventListener('change', function() {
    const preset = this.value;
    const hostEl = document.getElementById('smtp_host');
    const portEl = document.getElementById('smtp_port');
    const encEl = document.getElementById('encryption');

    switch (preset) {
        case 'gmail':
            hostEl.value = 'smtp.gmail.com';
            portEl.value = '587';
            encEl.value = 'tls';
            break;
        case 'outlook':
            hostEl.value = 'smtp.office365.com';
            portEl.value = '587';
            encEl.value = 'tls';
            break;
        case 'zoho_in':
            hostEl.value = 'smtp.zoho.in';
            portEl.value = '587';
            encEl.value = 'tls';
            break;
        case 'zoho_com':
            hostEl.value = 'smtp.zoho.com';
            portEl.value = '587';
            encEl.value = 'tls';
            break;
    }
});

function showAlert(type, message) {
    const banner = document.getElementById('ajaxAlertBanner');
    banner.style.display = 'block';
    if (type === 'success') {
        banner.style.background = '#ecfdf3';
        banner.style.border = '1px solid #a6f4c5';
        banner.style.color = '#027a48';
        banner.innerHTML = '<strong>✓ Success:</strong> ' + message;
    } else {
        banner.style.background = '#fef2f2';
        banner.style.border = '1px solid #fee2e2';
        banner.style.color = '#991b1b';
        banner.innerHTML = '<strong>⚠️ Error:</strong> ' + message;
    }
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function updateStatusBadge(isVerified) {
    const container = document.getElementById('statusBadgeContainer');
    if (isVerified) {
        container.innerHTML = `
            <span class="badge" style="background: #ecfdf3; color: #027a48; border: 1px solid #a6f4c5; font-size: 12px; padding: 6px 12px; border-radius: 99px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                <span style="font-size: 14px;">✓</span> Verified & Ready
            </span>
        `;
        const lastErr = document.getElementById('boxLastError');
        if (lastErr) lastErr.style.display = 'none';
    } else {
        container.innerHTML = `
            <span class="badge" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 12px; padding: 6px 12px; border-radius: 99px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                <span>⚠️</span> Configured, Not Verified
            </span>
        `;
    }
}

// Test Connection Handler
document.getElementById('btnTestConn').addEventListener('click', function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Testing... ⏳';

    const payload = {
        csrf_token: CSRF_TOKEN,
        host: document.getElementById('smtp_host').value.trim(),
        port: document.getElementById('smtp_port').value.trim(),
        encryption: document.getElementById('encryption').value,
        username: document.getElementById('smtp_username').value.trim(),
        password: document.getElementById('smtp_password').value,
        from_email: document.getElementById('from_email').value.trim(),
        from_name: document.getElementById('from_name').value.trim(),
    };

    fetch('ajax/smtp_test_connection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (data.success) {
            showAlert('success', data.message || 'Connection and authentication successful! SMTP server is ready.');
            updateStatusBadge(true);
        } else {
            showAlert('error', data.error || 'Connection failed.');
            updateStatusBadge(false);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showAlert('error', 'Network error testing connection: ' + err.message);
    });
});

// Send Test Email to Myself Handler
document.getElementById('btnSendTestEmail').addEventListener('click', function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Sending... ⏳';

    const payload = {
        csrf_token: CSRF_TOKEN,
        host: document.getElementById('smtp_host').value.trim(),
        port: document.getElementById('smtp_port').value.trim(),
        encryption: document.getElementById('encryption').value,
        username: document.getElementById('smtp_username').value.trim(),
        password: document.getElementById('smtp_password').value,
        from_email: document.getElementById('from_email').value.trim(),
        from_name: document.getElementById('from_name').value.trim(),
    };

    fetch('ajax/smtp_send_test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (data.success) {
            showAlert('success', data.message || 'Test email sent successfully!');
            updateStatusBadge(true);
        } else {
            showAlert('error', data.error || 'Failed to send test email.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showAlert('error', 'Network error sending test email: ' + err.message);
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
