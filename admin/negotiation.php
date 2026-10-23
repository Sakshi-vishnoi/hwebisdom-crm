<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_access();

$leadId = (int)($_GET['lead_id'] ?? 0);

if ($leadId < 1) {
    redirect('leads.php');
}

$s = $pdo->prepare("
    SELECT
        l.*,
        ps.name AS stage,
        u.username AS assignee
    FROM leads l
    LEFT JOIN pipeline_stages ps ON ps.id = l.stage_id
    LEFT JOIN users u ON u.id = l.assigned_to
    WHERE l.id = ?
");
$s->execute([$leadId]);
$lead = $s->fetch();

if (!$lead) {
    redirect('leads.php');
}

if (!can_view_lead($pdo, $lead)) {
    http_response_code(403);
    exit('Access denied.');
}

$canEdit = can_edit_lead($pdo, $lead);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    if (!$canEdit) {
        http_response_code(403);
        exit('Read-only access.');
    }

    $handler = (int)($_POST['handled_by'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'In Progress';

    $expected = $_POST['expected_amount'] !== ''
        ? $_POST['expected_amount']
        : null;

    $final = $_POST['final_amount'] !== ''
        ? $_POST['final_amount']
        : null;

    $notes = trim($_POST['notes'] ?? '');
    $next = trim($_POST['next_action'] ?? '');

    if (!in_array($status, ['In Progress', 'Won'], true)) {

        flash('error', 'Invalid negotiation status.');

    } else {

        // Sales users can only manage their own negotiation.
        if ($user['role'] === 'staff') {
            $handler = $user['id'];
        }

        $s = $pdo->prepare("
            INSERT INTO negotiations
            (
                lead_id,
                handled_by,
                status,
                expected_amount,
                final_amount,
                notes,
                next_action,
                closed_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                handled_by = VALUES(handled_by),
                status = VALUES(status),
                expected_amount = VALUES(expected_amount),
                final_amount = VALUES(final_amount),
                notes = VALUES(notes),
                next_action = VALUES(next_action),
                closed_at = VALUES(closed_at)
        ");

        $closed = $status === 'Won'
            ? date('Y-m-d H:i:s')
            : null;

        $s->execute([
            $leadId,
            $handler,
            $status,
            $expected,
            $final,
            $notes,
            $next,
            $closed
        ]);

        $stageName = $status === 'Won'
            ? 'Converted/Won'
            : 'Under Negotiation';

        $s = $pdo->prepare(
            'SELECT id FROM pipeline_stages WHERE name = ? LIMIT 1'
        );
        $s->execute([$stageName]);

        $stageId = (int)$s->fetchColumn();

        if ($stageId) {
            $pdo->prepare("
                UPDATE leads
                SET
                    stage_id = ?,
                    stage_subcategory = NULL,
                    stage_comments = NULL,
                    status = ?
                WHERE id = ?
            ")->execute([
                $stageId,
                $status === 'Won' ? 'Converted' : 'Under Negotiation',
                $leadId
            ]);
        }

        log_activity(
            $pdo,
            $leadId,
            $user['id'],
            'Negotiation',
            'Negotiation updated: ' . $status . '.'
        );

        flash(
            'success',
            $status === 'Won'
                ? 'Lead marked as Converted/Won.'
                : 'Negotiation updated.'
        );

        redirect(
            'negotiation.php?lead_id=' . $leadId
        );
    }
}

$s = $pdo->prepare("
    SELECT
        n.*,
        u.username AS handler
    FROM negotiations n
    LEFT JOIN users u ON u.id = n.handled_by
    WHERE n.lead_id = ?
");
$s->execute([$leadId]);
$neg = $s->fetch();

if ($user['role'] === 'staff') {

    $users = [[
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role']
    ]];

} else {

    $users = $pdo->query("
        SELECT id, username, role
        FROM users
        WHERE role IN ('admin','b2b_manager','staff')
        ORDER BY username
    ")->fetchAll();
}

$page_title = 'Negotiation';
$current_page = 'pipeline';

require __DIR__ . '/../includes/header.php';

?>

<div class="lead-hero">

    <div>

        <div class="eyebrow">
            Final Discussion / Negotiation
        </div>

        <h2>
            <?= e($lead['company'] ?: $lead['name']) ?>
        </h2>

        <p class="muted">
            Current stage:
            <?= e($lead['stage'] ?: 'New Lead') ?>
        </p>

    </div>

    <a
        class="btn btn-light"
        href="lead.php?id=<?= $leadId ?>"
    >
        Back to Lead
    </a>

</div>


<div
    class="section-card form-card"
    style="max-width:900px"
>

    <?php if (!$canEdit): ?>

        <div class="alert">
            QC Member access is read-only. You can review this negotiation,
            but you cannot change it.
        </div>

    <?php endif; ?>


    <form method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <div class="form-grid">


            <div class="field">

                <label>
                    Handled By
                </label>

                <select
                    class="form-control"
                    name="handled_by"
                    <?= !$canEdit ? 'disabled' : '' ?>
                >

                    <?php foreach ($users as $u): ?>

                        <option
                            value="<?= (int)$u['id'] ?>"
                            <?= (
                                $neg &&
                                (int)$neg['handled_by']
                                === (int)$u['id']
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($u['username']) ?>
                            —
                            <?= e(role_label($u['role'])) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="field">

                <label>
                    Negotiation Status
                </label>

                <select
                    class="form-control"
                    name="status"
                    <?= !$canEdit ? 'disabled' : '' ?>
                >

                    <option
                        value="In Progress"
                        <?= ($neg['status'] ?? 'In Progress')
                            === 'In Progress'
                            ? 'selected'
                            : '' ?>
                    >
                        Under Negotiation
                    </option>

                    <option
                        value="Won"
                        <?= ($neg['status'] ?? '')
                            === 'Won'
                            ? 'selected'
                            : '' ?>
                    >
                        Converted/Won
                    </option>

                </select>

            </div>


            <div class="field">

                <label>
                    Expected Amount
                </label>

                <input
                    class="form-control"
                    type="number"
                    step="0.01"
                    name="expected_amount"
                    value="<?= e($neg['expected_amount'] ?? '') ?>"
                    <?= !$canEdit ? 'disabled' : '' ?>
                >

            </div>


            <div class="field">

                <label>
                    Final Amount
                </label>

                <input
                    class="form-control"
                    type="number"
                    step="0.01"
                    name="final_amount"
                    value="<?= e($neg['final_amount'] ?? '') ?>"
                    <?= !$canEdit ? 'disabled' : '' ?>
                >

            </div>


            <div class="field full">

                <label>
                    Negotiation Notes
                </label>

                <textarea
                    class="form-control textarea"
                    name="notes"
                    <?= !$canEdit ? 'disabled' : '' ?>
                ><?= e($neg['notes'] ?? '') ?></textarea>

            </div>


            <div class="field full">

                <label>
                    Next Action
                </label>

                <textarea
                    class="form-control textarea"
                    name="next_action"
                    <?= !$canEdit ? 'disabled' : '' ?>
                ><?= e($neg['next_action'] ?? '') ?></textarea>

            </div>


        </div>


        <?php if ($canEdit): ?>

            <div class="form-actions">

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Save Negotiation
                </button>

            </div>

        <?php endif; ?>

    </form>

</div>


<?php require __DIR__ . '/../includes/footer.php'; ?>
