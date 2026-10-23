<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_access();

$page_title = 'Schedule Meeting';
$current_page = 'meetings';
$error = '';

// Only show leads the current user is allowed to access.
$params = [];
$visibilitySql = lead_visibility_sql($params, 'l');
$stmt = $pdo->prepare(
    "SELECT l.id, l.name, l.company, l.email
     FROM leads l
     WHERE 1=1 $visibilitySql
     ORDER BY COALESCE(NULLIF(l.company, ''), l.name), l.name"
);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $leadId = (int)($_POST['lead_id'] ?? 0);

    if ($leadId < 1) {
        $error = 'Please select a lead.';
    } else {
        // Confirm the selected lead is visible to this user.
        $leadParams = [$leadId];
        $leadVisibility = lead_visibility_sql($leadParams, 'l');
        $check = $pdo->prepare(
            "SELECT l.id FROM leads l WHERE l.id = ? $leadVisibility LIMIT 1"
        );
        $check->execute($leadParams);
        if (!$check->fetchColumn()) {
            http_response_code(403);
            exit('You do not have access to that lead.');
        }

        redirect('meeting.php?lead_id=' . $leadId);
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="section-card form-card" style="max-width:800px">
    <div class="section-head">
        <div>
            <h2>Schedule Meeting</h2>
            <p class="muted">Choose the lead this meeting belongs to. You can enter the meeting details on the next screen.</p>
        </div>
        <a class="btn btn-light" href="meetings.php">Cancel</a>
    </div>

    <?php if ($error): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$leads): ?>
        <div class="alert">No leads are available for your account. Create a lead or ask an administrator to assign one to you.</div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label for="lead_id">Select Lead</label>
                <select class="form-control" id="lead_id" name="lead_id" required>
                    <option value="">Choose a lead...</option>
                    <?php foreach ($leads as $lead): ?>
                        <option value="<?= (int)$lead['id'] ?>" <?= (int)($_POST['lead_id'] ?? 0) === (int)$lead['id'] ? 'selected' : '' ?>>
                            <?= e(($lead['company'] ?: $lead['name']) . ' — ' . $lead['name'] . (!empty($lead['email']) ? ' (' . $lead['email'] . ')' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions" style="margin-top:20px">
                <button class="btn btn-primary" type="submit">Continue to Meeting Details</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
