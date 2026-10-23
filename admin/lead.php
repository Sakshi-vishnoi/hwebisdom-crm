<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_access();

$id = (int) ($_GET['id'] ?? 0);

if ($id < 1) {
    redirect('leads.php');
}

/*
|--------------------------------------------------------------------------
| Get lead
|--------------------------------------------------------------------------
*/
$s = $pdo->prepare("
    SELECT
        l.*,
        u.username AS assignee,
        ps.name AS stage,
        ls.name AS source
    FROM leads l
    LEFT JOIN users u
        ON u.id = l.assigned_to
    LEFT JOIN pipeline_stages ps
        ON ps.id = l.stage_id
    LEFT JOIN lead_sources ls
        ON ls.id = l.source_id
    WHERE l.id = ?
");

$s->execute([$id]);

$lead = $s->fetch();

$canEditLead = false;

if ($lead) {
    $canEditLead = can_edit_lead($pdo, $lead);
}

if (!$lead) {
    flash('error', 'Lead not found.');
    redirect('leads.php');
}

if (!can_view_lead($pdo, $lead)) {
    http_response_code(403);
    exit('Access denied. This lead is not assigned to you.');
}


/*
|--------------------------------------------------------------------------
| New Pipeline Stage -> Subcategory Mapping
|--------------------------------------------------------------------------
*/
$stageOptions = crm_stage_options();

$allowedStageNames = array_keys($stageOptions);
$stagePlaceholders = implode(',', array_fill(0, count($allowedStageNames), '?'));

$stageListStmt = $pdo->prepare("
    SELECT id, name
    FROM pipeline_stages
    WHERE name IN ($stagePlaceholders)
      AND is_active = 1
    ORDER BY sequence ASC, id ASC
");
$stageListStmt->execute($allowedStageNames);
$stages = $stageListStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Update lead
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    if (!$canEditLead) {
        http_response_code(403);
        exit('Read-only access. You do not have permission to modify this lead.');
    }

    $stageId = (int) ($_POST['stage_id'] ?? 0);

    $stageSubcategory = trim($_POST['stage_subcategory'] ?? '');
    $stageComments = trim($_POST['stage_comments'] ?? '');

    $priority = $_POST['priority'] ?? 'Medium';

    $assigned = (int) ($_POST['assigned_to'] ?? 0) ?: null;

    if (current_user()['role'] === 'staff') {
        $assigned = current_user()['id'];
    }

    $sourceId = (int) ($_POST['source_id'] ?? 0) ?: null;

    $company = trim($_POST['company'] ?? '');

    $notes = trim($_POST['notes'] ?? '');
    $clientCategory = trim($_POST['client_category'] ?? 'Prospect');
    $clientLabel = trim($_POST['client_label'] ?? 'Cold');
    $allowedClientCategories = ['Current Client', 'Old Client', 'Client Left', 'Prospect'];
    $allowedClientLabels = ['Hot', 'Cold', 'Dead'];

    $st = $pdo->prepare("
        SELECT name
        FROM pipeline_stages
        WHERE id = ?
    ");

    $st->execute([$stageId]);

    $stageName = $st->fetchColumn();

    if (
        !$stageName ||
        !array_key_exists($stageName, $stageOptions) ||
        !in_array($priority, lead_priorities(), true) ||
        !in_array($clientCategory, $allowedClientCategories, true) ||
        !in_array($clientLabel, $allowedClientLabels, true)
    ) {

        flash(
            'error',
            'Invalid stage or priority.'
        );

    } else {

        $oldStage = $lead['stage'];

        $availableSubcategories = $stageOptions[$stageName];

        if (!empty($availableSubcategories)) {

            if (
                $stageSubcategory === '' ||
                !in_array(
                    $stageSubcategory,
                    $availableSubcategories,
                    true
                )
            ) {
                flash(
                    'error',
                    'Please select a valid option for "' .
                    $stageName .
                    '".'
                );
                redirect('lead.php?id=' . $id);
            }

        } else {
            $stageSubcategory = '';
            $stageComments = '';
        }

        if (
            $stageName === 'Meetings Scheduled' &&
            $stageComments === ''
        ) {
            flash(
                'error',
                'Please enter comments for the scheduled meeting.'
            );
            redirect('lead.php?id=' . $id);
        }

        $u = $pdo->prepare("
            UPDATE leads
            SET
                company = ?,
                source_id = ?,
                stage_id = ?,
                stage_subcategory = ?,
                stage_comments = ?,
                status = ?,
                priority = ?,
                assigned_to = ?,
                notes = ?,
                client_category = ?,
                client_label = ?
            WHERE id = ?
        ");

        $legacyStatus = legacy_status_for_stage($stageName);

        $u->execute([
            $company,
            $sourceId,
            $stageId,
            $stageSubcategory !== '' ? $stageSubcategory : null,
            $stageComments !== '' ? $stageComments : null,
            $legacyStatus,
            $priority,
            $assigned,
            $notes,
            $clientCategory,
            $clientLabel,
            $id
        ]);

        // Record precise, field-by-field changes instead of a generic update.
        // Use the already-loaded lead row as the before-state and the submitted
        // values as the after-state. Values are logged as plain text; output is
        // escaped by the Activity Timeline renderer.
        $sourceName = '';
        if ($sourceId) {
            $sourceStmt = $pdo->prepare('SELECT name FROM lead_sources WHERE id = ?');
            $sourceStmt->execute([$sourceId]);
            $sourceName = (string) ($sourceStmt->fetchColumn() ?: '');
        }

        $assigneeName = '';
        if ($assigned) {
            $assigneeStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $assigneeStmt->execute([$assigned]);
            $assigneeName = (string) ($assigneeStmt->fetchColumn() ?: '');
        }

        $changes = [
            'Company' => [$lead['company'] ?? '', $company],
            'Lead Source' => [$lead['source'] ?? '', $sourceName],
            'Stage' => [$oldStage ?? '', $stageName],
            'Stage Option' => [$lead['stage_subcategory'] ?? '', $stageSubcategory],
            'Meeting Comments' => [$lead['stage_comments'] ?? '', $stageComments],
            'Priority' => [$lead['priority'] ?? '', $priority],
            'Assigned To' => [$lead['assignee'] ?? '', $assigneeName],
            'Internal Notes' => [$lead['notes'] ?? '', $notes],
            'Client Category' => [$lead['client_category'] ?? 'Prospect', $clientCategory],
            'Client Label' => [$lead['client_label'] ?? 'Cold', $clientLabel],
        ];

        foreach ($changes as $fieldLabel => [$before, $after]) {
            $before = trim((string) $before);
            $after = trim((string) $after);
            if ($before === $after) {
                continue;
            }

            $beforeDisplay = $before === '' ? '—' : $before;
            $afterDisplay = $after === '' ? '—' : $after;

            log_activity(
                $pdo,
                $id,
                current_user()['id'],
                'Lead Updated · ' . $fieldLabel,
                $fieldLabel . ' changed from "' . $beforeDisplay . '" to "' . $afterDisplay . '".'
            );
        }

        flash(
            'success',
            'Lead updated successfully.'
        );

        redirect('lead.php?id=' . $id);
    }
}


/*
|--------------------------------------------------------------------------
| Users
|--------------------------------------------------------------------------
*/
$users = $pdo->query("
    SELECT
        id,
        username,
        email,
        role
    FROM users
    WHERE role IN ('admin','b2b_manager','staff')
    ORDER BY username
")->fetchAll();


/*
|--------------------------------------------------------------------------
| Pipeline stages
|--------------------------------------------------------------------------
| Loaded above using only the seven approved stages.
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Lead sources
|--------------------------------------------------------------------------
*/
$sources = lead_sources($pdo);


/*
|--------------------------------------------------------------------------
| Follow-ups
|--------------------------------------------------------------------------
*/
$followups = $pdo->prepare("
    SELECT
        f.*,
        u.username AS assignee
    FROM lead_followups f
    LEFT JOIN users u
        ON u.id = f.assigned_to
    WHERE f.lead_id = ?
    ORDER BY
        f.follow_up_date DESC,
        f.follow_up_time DESC,
        f.id DESC
");

$followups->execute([$id]);

$followups = $followups->fetchAll();


/*
|--------------------------------------------------------------------------
| Meetings
|--------------------------------------------------------------------------
*/
$meetings = $pdo->prepare("
    SELECT *
    FROM meetings
    WHERE lead_id = ?
    ORDER BY
        meeting_date DESC,
        start_time DESC,
        id DESC
");

$meetings->execute([$id]);

$meetings = $meetings->fetchAll();


/*
|--------------------------------------------------------------------------
| Documents
|--------------------------------------------------------------------------
*/
$docs = $pdo->prepare("
    SELECT
        d.*,
        u.username AS uploader
    FROM documents d
    LEFT JOIN users u
        ON u.id = d.uploaded_by
    WHERE d.lead_id = ?
    ORDER BY d.created_at DESC
");

$docs->execute([$id]);

$docs = $docs->fetchAll();


/*
|--------------------------------------------------------------------------
| Activities
|--------------------------------------------------------------------------
*/
$acts = $pdo->prepare("
    SELECT
        a.*,
        u.username
    FROM activities a
    LEFT JOIN users u
        ON u.id = a.user_id
    WHERE a.lead_id = ?
    ORDER BY a.created_at DESC
    LIMIT 40
");

$acts->execute([$id]);

$acts = $acts->fetchAll();


/*
|--------------------------------------------------------------------------
| Negotiation
|--------------------------------------------------------------------------
*/
$neg = $pdo->prepare("
    SELECT
        n.*,
        u.username AS handler
    FROM negotiations n
    LEFT JOIN users u
        ON u.id = n.handled_by
    WHERE n.lead_id = ?
    LIMIT 1
");

$neg->execute([$id]);

$neg = $neg->fetch();


/*
|--------------------------------------------------------------------------
| Email history
|--------------------------------------------------------------------------
*/
$emails = $pdo->prepare("
    SELECT *
    FROM email_messages
    WHERE lead_id = ?
    ORDER BY
        COALESCE(received_at, created_at) DESC
    LIMIT 100
");

$emails->execute([$id]);

$emails = $emails->fetchAll();


$page_title = 'Lead #' . $id;
$current_page = 'leads';

require __DIR__ . '/../includes/header.php';

?>

<style>

/*
|--------------------------------------------------------------------------
| Lead document filename
|--------------------------------------------------------------------------
*/

.lead-document-name {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
    word-break: break-word;
}

.lead-document-name:hover {
    text-decoration: underline;
}

.lead-document-actions {
    white-space: nowrap;
}

</style>


<div class="lead-hero">

    <div>

        <div class="eyebrow">
            B2B Lead
        </div>

        <h2>
            <?= e(
                $lead['company']
                ?: ($lead['name'] ?: 'Unnamed Lead')
            ) ?>
        </h2>

        <p class="muted">

            <?= e($lead['name']) ?>

            ·

            <?= e($lead['email']) ?>

            ·

            <?= e($lead['contact']) ?>

        </p>

    </div>


    <div class="actions">

        <a
            class="btn btn-light"
            href="leads.php"
        >
            Back
        </a>

        <?php if ($canEditLead): ?>

            <a
                class="btn btn-primary"
                href="followups.php?lead_id=<?= $id ?>"
            >
                Add Follow-up
            </a>

            <a
                class="btn btn-primary"
                href="meeting.php?lead_id=<?= $id ?>"
            >
                Schedule Meeting
            </a>

            <a
                class="btn btn-light"
                href="email_send.php?lead_id=<?= $id ?>"
            >
                Email Lead
            </a>

        <?php endif; ?>

    </div>

</div>


<div class="lead-meta">

    <div>

        <label>Source</label>

        <strong>
            <?= e(
                $lead['source']
                ?: 'Google / Website'
            ) ?>
        </strong>

    </div>


    <div>

        <label>Stage</label>

        <span
            class="badge <?= e(
                stage_class(
                    $lead['stage']
                    ?: 'New Lead'
                )
            ) ?>"
        >
            <?= e(
                $lead['stage']
                ?: 'New Lead'
            ) ?>
        </span>

    </div>


    <div>

        <label>Stage Option</label>

        <strong>
            <?= e($lead['stage_subcategory'] ?? '') ?: '—' ?>
        </strong>

    </div>


    <?php if (!empty($lead['stage_comments'])): ?>

        <div>

            <label>Stage Comments</label>

            <strong>
                <?= e($lead['stage_comments']) ?>
            </strong>

        </div>

    <?php endif; ?>


    <div>
        <label>Client Category</label>
        <span class="badge badge-blue"><?= e($lead['client_category'] ?? 'Prospect') ?></span>
    </div>

    <div>
        <label>Client Label</label>
        <span class="badge <?= ($lead['client_label'] ?? 'Cold') === 'Hot' ? 'badge-red' : ((($lead['client_label'] ?? 'Cold') === 'Dead') ? 'badge-gray' : 'badge-blue') ?>"><?= e($lead['client_label'] ?? 'Cold') ?></span>
    </div>

    <div>

        <label>Priority</label>

        <span
            class="badge <?= e(
                priority_class(
                    $lead['priority']
                )
            ) ?>"
        >
            <?= e($lead['priority']) ?>
        </span>

    </div>


    <div>

        <label>Assigned</label>

        <strong>
            <?= e(
                $lead['assignee']
                ?: 'Unassigned'
            ) ?>
        </strong>

    </div>

</div>


<div class="detail-grid">


<div>


    <!--
    |--------------------------------------------------------------------------
    | Sales Pipeline
    |--------------------------------------------------------------------------
    -->

    <div class="section-card">

        <div class="section-head">

            <h2>
                Sales Pipeline
            </h2>

        </div>


        <div class="pipeline-steps">

            <?php foreach ($stages as $x): ?>

                <?php

                $active =
                    (int) $x['id']
                    ===
                    (int) $lead['stage_id'];

                ?>

                <div
                    class="pipeline-step <?= $active ? 'current' : '' ?>"
                >

                    <span>
                        <?= $active ? '●' : '○' ?>
                    </span>

                    <strong>
                        <?= e($x['name']) ?>
                    </strong>

                </div>

            <?php endforeach; ?>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Lead Information
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Lead Information
            </h2>

        </div>


        <?php if ($canEditLead): ?>

        <form
            method="post"
            class="form-card"
            style="max-width:none;border:0;box-shadow:none"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >


            <div class="form-grid">

                <div class="field">
                    <label>Client Category</label>
                    <select class="form-control" name="client_category" required>
                        <?php foreach (['Current Client', 'Old Client', 'Client Left', 'Prospect'] as $category): ?>
                            <option value="<?= e($category) ?>" <?= ($lead['client_category'] ?? 'Prospect') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Client Label</label>
                    <select class="form-control" name="client_label" required>
                        <?php foreach (['Hot', 'Cold', 'Dead'] as $label): ?>
                            <option value="<?= e($label) ?>" <?= ($lead['client_label'] ?? 'Cold') === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">

                    <label>
                        Company
                    </label>

                    <input
                        class="form-control"
                        name="company"
                        value="<?= e($lead['company']) ?>"
                    >

                </div>


                <div class="field">

                    <label>
                        Lead Name
                    </label>

                    <input
                        class="form-control"
                        value="<?= e($lead['name']) ?>"
                        disabled
                    >

                </div>


                <div class="field">

                    <label>
                        Lead Source
                    </label>

                    <select
                        class="form-control"
                        name="source_id"
                    >

                        <?php foreach ($sources as $x): ?>

                            <option
                                value="<?= $x['id'] ?>"
                                <?= $lead['source_id'] == $x['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($x['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field">

                    <label>
                        Stage
                    </label>

                    <select
                        class="form-control"
                        name="stage_id"
                        id="stage_id"
                        required
                    >

                        <option value="">
                            Select Stage
                        </option>

                        <?php foreach ($stages as $x): ?>

                            <option
                                value="<?= (int) $x['id'] ?>"
                                data-stage-name="<?= e($x['name']) ?>"
                                <?= (int) $lead['stage_id'] === (int) $x['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($x['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Dynamic Stage Subcategory -->

                <div
                    class="field"
                    id="stage_subcategory_container"
                    style="display:none"
                >

                    <label id="stage_subcategory_label">
                        Subcategory
                    </label>

                    <select
                        class="form-control"
                        name="stage_subcategory"
                        id="stage_subcategory"
                    >
                        <option value="">
                            Select an option
                        </option>
                    </select>

                </div>


                <!-- Meeting Comments -->

                <div
                    class="field full"
                    id="stage_comments_container"
                    style="display:none"
                >

                    <label>
                        Meeting Comments
                    </label>

                    <textarea
                        class="form-control textarea"
                        name="stage_comments"
                        id="stage_comments"
                        placeholder="Enter meeting comments..."
                    ><?= e($lead['stage_comments'] ?? '') ?></textarea>

                </div>


                <div class="field">

                    <label>
                        Priority
                    </label>

                    <select
                        class="form-control"
                        name="priority"
                    >

                        <?php foreach (lead_priorities() as $p): ?>

                            <option
                                <?= $lead['priority'] === $p
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($p) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field">

                    <label>
                        Assigned To
                    </label>

                    <select
                        class="form-control"
                        name="assigned_to"
                        <?= current_user()['role'] === 'staff' ? 'disabled' : '' ?>
                    >

                        <option value="">
                            Unassigned
                        </option>

                        <?php foreach ($users as $u): ?>

                            <option
                                value="<?= $u['id'] ?>"
                                <?= $lead['assigned_to'] == $u['id']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= e($u['username']) ?>

                                —

                                <?= e(
                                    role_label(
                                        $u['role']
                                    )
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field full">

                    <label>
                        Requirement / Message
                    </label>

                    <div class="readonly-box">

                        <?= nl2br(
                            e(
                                $lead['message']
                                ?: '-'
                            )
                        ) ?>

                    </div>

                </div>


                <div class="field full">

                    <label>
                        Internal Notes
                    </label>

                    <textarea
                        class="form-control textarea"
                        name="notes"
                        placeholder="Internal sales notes..."
                    ><?= e($lead['notes']) ?></textarea>

                </div>


            </div>


            <div class="form-actions">

                <button
                    class="btn btn-primary"
                >
                    Save Lead
                </button>


                <?php if (current_user()['role'] === 'admin'): ?>
                    <a
                        class="btn btn-danger"
                        data-confirm="Delete this lead? This cannot be undone."
                        href="delete_lead.php?id=<?= $id ?>&token=<?= e(csrf_token()) ?>"
                    >
                        Delete Lead
                    </a>
                <?php endif; ?>

            </div>

        </form>

        <?php else: ?>

            <div class="alert">
                This lead is read-only for your role. You can review the
                lead, meetings, documents, emails and activity history, but
                you cannot modify sales data.
            </div>

        <?php endif; ?>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Follow-ups
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Follow-ups
            </h2>

            <?php if ($canEditLead): ?>
                <a
                    class="btn btn-primary btn-sm"
                    href="followups.php?lead_id=<?= $id ?>"
                >
                    + Add
                </a>
            <?php endif; ?>

        </div>


        <div class="timeline">

            <?php if (!$followups): ?>

                <div class="empty">
                    No follow-ups recorded.
                </div>

            <?php else: ?>

                <?php foreach ($followups as $f): ?>

                    <div class="timeline-item">

                        <div class="timeline-dot"></div>

                        <div>

                            <strong>

                                <?= e($f['type']) ?>

                                ·

                                <?= e(
                                    format_day(
                                        $f['follow_up_date']
                                    )
                                ) ?>

                            </strong>


                            <span
                                class="badge <?= $f['status'] === 'Completed'
                                    ? 'badge-green'
                                    : 'badge-orange' ?>"
                            >
                                <?= e($f['status']) ?>
                            </span>


                            <p>

                                <?= nl2br(
                                    e(
                                        $f['notes']
                                        ?: 'No notes'
                                    )
                                ) ?>

                            </p>


                            <small class="muted">

                                <?= e(
                                    $f['assignee']
                                    ?: 'Unassigned'
                                ) ?>

                                <?php if ($f['follow_up_time']): ?>

                                    ·

                                    <?= e(
                                        date(
                                            'h:i A',
                                            strtotime(
                                                $f['follow_up_time']
                                            )
                                        )
                                    ) ?>

                                <?php endif; ?>

                            </small>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Meetings
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Meetings
            </h2>

            <?php if ($canEditLead): ?>
                <a
                    class="btn btn-primary btn-sm"
                    href="meeting.php?lead_id=<?= $id ?>"
                >
                    + Schedule
                </a>
            <?php endif; ?>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                <tr>

                    <th>
                        Meeting
                    </th>

                    <th>
                        Date
                    </th>

                    <th>
                        Time
                    </th>

                    <th>
                        Status
                    </th>

                    <th></th>

                </tr>

                </thead>


                <tbody>

                <?php if (!$meetings): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="empty"
                        >
                            No meetings recorded.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($meetings as $m): ?>

                        <tr>

                            <td>
                                <?= e($m['title']) ?>
                            </td>

                            <td>
                                <?= e(
                                    format_day(
                                        $m['meeting_date']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    date(
                                        'h:i A',
                                        strtotime(
                                            $m['start_time']
                                        )
                                    )
                                ) ?>
                            </td>

                            <td>

                                <span class="badge badge-blue">
                                    <?= e($m['status']) ?>
                                </span>

                            </td>

                            <td>

                                <a
                                    class="btn btn-light btn-sm"
                                    href="meeting.php?id=<?= $m['id'] ?>"
                                >
                                    Open
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Email Communication
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <div>

                <h2>
                    Email Communication
                </h2>

                <div class="muted">
                    Complete inbound and outbound email history linked to this lead.
                </div>

            </div>


            <?php if ($canEditLead): ?>
                <a
                    class="btn btn-primary btn-sm"
                    href="email_send.php?lead_id=<?= $id ?>"
                >
                    + Send Email
                </a>
            <?php endif; ?>

        </div>


        <div class="email-thread-list">

            <?php if (!$emails): ?>

                <div class="empty">
                    No email communication captured yet.
                </div>

            <?php else: ?>

                <?php foreach ($emails as $em): ?>

                    <article class="email-message">

                        <div class="email-message-head">

                            <div>

                                <strong>
                                    <?= e(
                                        $em['subject']
                                        ?: '(No subject)'
                                    ) ?>
                                </strong>

                                <div class="muted">

                                    <?= e(
                                        $em['direction'] === 'inbound'
                                            ? 'From: '
                                            : 'To: '
                                    ) ?>

                                    <?= e(
                                        $em['direction'] === 'inbound'
                                            ? $em['from_email']
                                            : $em['to_email']
                                    ) ?>

                                </div>

                            </div>


                            <span
                                class="badge <?= $em['direction'] === 'inbound'
                                    ? 'badge-blue'
                                    : 'badge-green' ?>"
                            >
                                <?= e(
                                    ucfirst(
                                        $em['direction']
                                    )
                                ) ?>
                            </span>

                        </div>


                        <div class="email-message-meta">

                            <?= e(
                                format_date(
                                    $em['received_at']
                                    ?: $em['created_at']
                                )
                            ) ?>

                        </div>


                        <div class="email-message-body">

                            <?= nl2br(
                                e(
                                    $em['body_text']
                                    ?: strip_tags(
                                        $em['body_html'] ?? ''
                                    )
                                )
                            ) ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Proposals & Documents
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Proposals & Documents
            </h2>

            <?php if ($canEditLead): ?>
                <a
                    class="btn btn-primary btn-sm"
                    href="documents.php?lead_id=<?= $id ?>"
                >
                    + Upload
                </a>
            <?php endif; ?>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                <tr>

                    <th>
                        Type
                    </th>

                    <th>
                        File
                    </th>

                    <th>
                        Uploaded
                    </th>

                    <th>
                        Uploaded By
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

                </thead>


                <tbody>

                <?php if (!$docs): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="empty"
                        >
                            No documents uploaded.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($docs as $d): ?>

                        <tr>

                            <!-- Type -->

                            <td>

                                <?= e(
                                    $d['document_type']
                                ) ?>

                            </td>


                            <!--
                            |--------------------------------------------------------------------------
                            | Click filename to preview
                            |--------------------------------------------------------------------------
                            -->

                            <td>

                                <a
                                    class="lead-document-name"
                                    href="preview_document.php?id=<?= (int) $d['id'] ?>"
                                    target="_blank"
                                    rel="noopener"
                                    title="Open document preview"
                                >

                                    <?= e(
                                        $d['file_name']
                                    ) ?>

                                </a>

                            </td>


                            <!-- Uploaded -->

                            <td>

                                <?= e(
                                    format_day(
                                        $d['created_at']
                                    )
                                ) ?>

                            </td>


                            <!-- Uploaded By -->

                            <td>

                                <?= e(
                                    $d['uploader']
                                    ?: '—'
                                ) ?>

                            </td>


                            <!-- Download -->

                            <td class="lead-document-actions">

                                <a
                                    class="btn btn-light btn-sm"
                                    href="download_document.php?id=<?= (int) $d['id'] ?>"
                                >
                                    Download
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<div>


    <!--
    |--------------------------------------------------------------------------
    | Lead Details
    |--------------------------------------------------------------------------
    -->

    <div class="section-card">

        <div class="section-head">

            <h2>
                Lead Details
            </h2>

        </div>


        <div class="info-list">


            <div class="info-item">

                <label>
                    Email
                </label>

                <div>

                    <?= $lead['email']
                        ? '<a href="mailto:' .
                          e($lead['email']) .
                          '">' .
                          e($lead['email']) .
                          '</a>'
                        : '-' ?>

                </div>

            </div>


            <div class="info-item">

                <label>
                    Phone
                </label>

                <div>

                    <?= $lead['contact']
                        ? '<a href="tel:' .
                          e($lead['contact']) .
                          '">' .
                          e($lead['contact']) .
                          '</a>'
                        : '-' ?>

                </div>

            </div>


            <div class="info-item">

                <label>
                    Created
                </label>

                <div>

                    <?= e(
                        format_date(
                            $lead['created_at']
                        )
                    ) ?>

                </div>

            </div>


            <div class="info-item">

                <label>
                    Last Updated
                </label>

                <div>

                    <?= e(
                        format_date(
                            $lead['updated_at']
                        )
                    ) ?>

                </div>

            </div>


        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Final Negotiation
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Final Negotiation
            </h2>

            <a
                class="btn btn-light btn-sm"
                href="negotiation.php?lead_id=<?= $id ?>"
            >
                <?= $canEditLead ? 'Manage' : 'View' ?>
            </a>

        </div>


        <div class="info-list">

            <?php if (!$neg): ?>

                <div class="empty">
                    No negotiation record yet.
                </div>

            <?php else: ?>


                <div class="info-item">

                    <label>
                        Handled By
                    </label>

                    <div>

                        <?= e(
                            $neg['handler']
                            ?: 'Unassigned'
                        ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <label>
                        Status
                    </label>

                    <div>

                        <span
                            class="badge <?= $neg['status'] === 'Won'
                                ? 'badge-green'
                                : (
                                    $neg['status'] === 'Lost'
                                        ? 'badge-red'
                                        : 'badge-orange'
                                ) ?>"
                        >

                            <?= e(
                                $neg['status']
                            ) ?>

                        </span>

                    </div>

                </div>


                <div class="info-item">

                    <label>
                        Expected / Final Amount
                    </label>

                    <div>

                        <?=
                            $neg['final_amount'] !== null
                                ? '₹ ' . number_format(
                                    (float) $neg['final_amount'],
                                    2
                                )
                                : (
                                    $neg['expected_amount'] !== null
                                        ? '₹ ' . number_format(
                                            (float) $neg['expected_amount'],
                                            2
                                        )
                                        : '-'
                                )
                        ?>

                    </div>

                </div>


                <div class="info-item">

                    <label>
                        Notes
                    </label>

                    <div>

                        <?= nl2br(
                            e(
                                $neg['notes']
                                ?: '-'
                            )
                        ) ?>

                    </div>

                </div>


            <?php endif; ?>

        </div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Activity Timeline
    |--------------------------------------------------------------------------
    -->

    <div
        class="section-card"
        style="margin-top:18px"
    >

        <div class="section-head">

            <h2>
                Activity Timeline
            </h2>

        </div>


        <div class="timeline">

            <?php if (!$acts): ?>

                <div class="empty">
                    No activities yet.
                </div>

            <?php else: ?>

                <?php foreach ($acts as $a): ?>

                    <div class="timeline-item">

                        <div class="timeline-dot"></div>

                        <div>

                            <strong>
                                <?= e(
                                    $a['activity_type']
                                ) ?>
                            </strong>

                            <small class="muted">

                                ·

                                <?= e(
                                    format_date(
                                        $a['created_at']
                                    )
                                ) ?>

                            </small>


                            <p>

                                <?= nl2br(
                                    e(
                                        $a['description']
                                    )
                                ) ?>

                            </p>


                            <small class="muted">

                                by

                                <?= e(
                                    $a['username']
                                    ?: 'System'
                                ) ?>

                            </small>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| Stage -> Subcategory configuration
|--------------------------------------------------------------------------
*/

const stageOptions = <?= json_encode(
    $stageOptions,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const currentSubcategory =
    <?= json_encode(
        $lead['stage_subcategory'] ?? '',
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


/*
|--------------------------------------------------------------------------
| Update stage-dependent fields
|--------------------------------------------------------------------------
*/

function updateStageFields() {

    const stageSelect =
        document.getElementById('stage_id');

    if (!stageSelect) {
        return;
    }

    const selectedOption =
        stageSelect.options[
            stageSelect.selectedIndex
        ];

    const stageName =
        selectedOption
            ? selectedOption.dataset.stageName
            : '';

    const subcategoryContainer =
        document.getElementById(
            'stage_subcategory_container'
        );

    const subcategorySelect =
        document.getElementById(
            'stage_subcategory'
        );

    const subcategoryLabel =
        document.getElementById(
            'stage_subcategory_label'
        );

    const commentsContainer =
        document.getElementById(
            'stage_comments_container'
        );

    const comments =
        document.getElementById(
            'stage_comments'
        );

    const options =
        stageOptions[stageName] || [];


    /*
    |--------------------------------------------------------------------------
    | Rebuild subcategory dropdown
    |--------------------------------------------------------------------------
    */

    subcategorySelect.innerHTML =
        '<option value="">Select an option</option>';

    if (options.length > 0) {

        subcategoryContainer.style.display = '';

        if (stageName === 'Meetings Scheduled') {

            subcategoryLabel.textContent =
                'Meeting Status';

        } else if (stageName === 'New Lead') {

            subcategoryLabel.textContent =
                'Lead Type';

        } else {

            subcategoryLabel.textContent =
                'Document Type';
        }

        options.forEach(function(option) {

            const optionElement =
                document.createElement('option');

            optionElement.value = option;
            optionElement.textContent = option;

            subcategorySelect.appendChild(
                optionElement
            );
        });

        /*
        | Restore the saved subcategory
        */
        subcategorySelect.value =
            currentSubcategory || '';

    } else {

        subcategoryContainer.style.display =
            'none';

        subcategorySelect.value = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Meeting comments
    |--------------------------------------------------------------------------
    */

    if (stageName === 'Meetings Scheduled') {

        commentsContainer.style.display = '';

        comments.required = true;

    } else {

        commentsContainer.style.display = 'none';

        comments.required = false;
    }
}


/*
|--------------------------------------------------------------------------
| Stage change
|--------------------------------------------------------------------------
*/

document
    .getElementById('stage_id')
    .addEventListener(
        'change',
        updateStageFields
    );


/*
|--------------------------------------------------------------------------
| Initial page load
|--------------------------------------------------------------------------
*/

updateStageFields();

</script>


<?php

require __DIR__ . '/../includes/footer.php';

?>
