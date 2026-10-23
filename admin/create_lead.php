<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_write();

$page_title = 'Add Lead';
$current_page = 'create_lead';
$error = '';

$sources = lead_sources($pdo);
$users = $pdo->query(
    "SELECT id, username, role FROM users WHERE role IN ('admin','b2b_manager','staff') ORDER BY username"
)->fetchAll();

$current = current_user();

/*
|--------------------------------------------------------------------------
| Allowed Pipeline Stages
|--------------------------------------------------------------------------
| These are the ONLY stages that will appear in Create Lead.
|--------------------------------------------------------------------------
*/
$stageOptions = crm_stage_options();


/*
|--------------------------------------------------------------------------
| Get ONLY the 7 stages from database
|--------------------------------------------------------------------------
*/
$stageNames = array_keys($stageOptions);

$placeholders = implode(
    ',',
    array_fill(0, count($stageNames), '?')
);

$stageQuery = $pdo->prepare(
    "SELECT id, name
     FROM pipeline_stages
     WHERE name IN ($placeholders)
     AND is_active = 1
     ORDER BY sequence ASC, id ASC"
);

$stageQuery->execute($stageNames);

$stages = $stageQuery->fetchAll();


/*
|--------------------------------------------------------------------------
| Check that all 7 stages exist
|--------------------------------------------------------------------------
*/
$existingStageNames = array_column($stages, 'name');

foreach ($stageNames as $requiredStage) {

    if (!in_array($requiredStage, $existingStageNames, true)) {

        $error =
            'Pipeline stage "' .
            $requiredStage .
            '" is missing from the database.';
        
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $message = trim($_POST['message'] ?? '');

    $source = (int) ($_POST['source_id'] ?? 0);
    $stage = (int) ($_POST['stage_id'] ?? 0);

    $stageSubcategory = trim(
        $_POST['stage_subcategory'] ?? ''
    );

    $stageComments = trim(
        $_POST['stage_comments'] ?? ''
    );

    $priority = $_POST['priority'] ?? 'Medium';

    $assigned = (int) (
        $_POST['assigned_to'] ?? 0
    ) ?: null;

    if ($current['role'] === 'staff') {
        $assigned = $current['id'];
    }

    $follow = !empty($_POST['follow_up_date'])
        ? $_POST['follow_up_date']
        : null;

    $notes = trim($_POST['notes'] ?? '');
    $clientCategory = trim($_POST['client_category'] ?? 'Prospect');
    $clientLabel = trim($_POST['client_label'] ?? 'Cold');


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '' && $company === '') {

        $error =
            'Enter at least a lead name or company.';

    } elseif (
        $email !== '' &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $error = 'Invalid email address.';

    } elseif (
        !in_array($priority, lead_priorities(), true)
    ) {

        $error = 'Invalid priority.';

    } elseif (!in_array($clientCategory, ['Current Client', 'Old Client', 'Client Left', 'Prospect'], true) || !in_array($clientLabel, ['Hot', 'Cold', 'Dead'], true)) {

        $error = 'Invalid client category or label.';

    } elseif (!$stage) {

        $error = 'Please select a stage.';

    } elseif (!$source) {

        $error = 'Please select a lead source.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Get Selected Stage Name
        |--------------------------------------------------------------------------
        */

        $stageStmt = $pdo->prepare(
            'SELECT name
             FROM pipeline_stages
             WHERE id = ?
             AND is_active = 1
             LIMIT 1'
        );

        $stageStmt->execute([$stage]);

        $stageName = $stageStmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | Make sure the selected stage is one of our 7 stages
        |--------------------------------------------------------------------------
        */

        if (
            !$stageName ||
            !array_key_exists(
                $stageName,
                $stageOptions
            )
        ) {

            $error = 'Invalid pipeline stage selected.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Validate Subcategory
            |--------------------------------------------------------------------------
            */

            $availableSubcategories =
                $stageOptions[$stageName];


            if (!empty($availableSubcategories)) {

                if (
                    $stageSubcategory === '' ||
                    !in_array(
                        $stageSubcategory,
                        $availableSubcategories,
                        true
                    )
                ) {

                    $error =
                        'Please select a valid option for "' .
                        $stageName .
                        '".';
                }

            } else {

                /*
                | Stages without subcategories
                | should not store one.
                */
                $stageSubcategory = '';
            }


            /*
            |--------------------------------------------------------------------------
            | Meeting Comments
            |--------------------------------------------------------------------------
            */

            if (
                !$error &&
                $stageName === 'Meetings Scheduled' &&
                $stageComments === ''
            ) {

                $error =
                    'Please enter comments for the scheduled meeting.';
            }


            /*
            |--------------------------------------------------------------------------
            | Insert Lead
            |--------------------------------------------------------------------------
            */

            if (!$error) {

                /*
                | Keep the existing legacy status field
                | synchronized with the new stage.
                */
                $legacyStatus = legacy_status_for_stage($stageName);


                $stageSubcategoryValue =
                    $stageSubcategory !== ''
                        ? $stageSubcategory
                        : null;

                $stageCommentsValue =
                    $stageComments !== ''
                        ? $stageComments
                        : null;


                /*
                |--------------------------------------------------------------------------
                | Insert
                |--------------------------------------------------------------------------
                */

                $sql = "
                    INSERT INTO leads
                    (
                        name,
                        company,
                        email,
                        contact,
                        message,
                        status,
                        priority,
                        assigned_to,
                        created_by,
                        follow_up_date,
                        notes,
                        source_id,
                        stage_id,
                        stage_subcategory,
                        stage_comments,
                        client_category,
                        client_label
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    $name ?: null,
                    $company ?: null,
                    $email ?: null,
                    $contact ?: null,
                    $message ?: null,
                    $legacyStatus,
                    $priority,
                    $assigned,
                    $current['id'],
                    $follow,
                    $notes ?: null,
                    $source,
                    $stage,
                    $stageSubcategoryValue,
                    $stageCommentsValue,
                    $clientCategory,
                    $clientLabel
                ]);


                /*
                |--------------------------------------------------------------------------
                | New Lead ID
                |--------------------------------------------------------------------------
                */

                $id = (int) $pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | Activity Log
                |--------------------------------------------------------------------------
                */

                $activityText =
                    'Lead manually created in the CRM.';

                $activityText .=
                    ' Stage: ' . $stageName . '.';


                if ($stageSubcategoryValue) {

                    $activityText .=
                        ' Option: ' .
                        $stageSubcategoryValue . '.';
                }


                if ($stageCommentsValue) {

                    $activityText .=
                        ' Comments: ' .
                        $stageCommentsValue;
                }


                log_activity(
                    $pdo,
                    $id,
                    current_user()['id'],
                    'Lead Created',
                    $activityText
                );


                /*
                |--------------------------------------------------------------------------
                | Success
                |--------------------------------------------------------------------------
                */

                flash(
                    'success',
                    'Lead created successfully.'
                );

                redirect(
                    'lead.php?id=' . $id
                );
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../includes/header.php';

?>


<?php if ($error): ?>

    <div class="alert danger">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<div
    class="section-card form-card"
    style="max-width:1000px"
>

    <div
        class="section-head"
        style="margin:-22px -22px 22px"
    >

        <h2>Create B2B Lead</h2>

        <a
            class="btn btn-light btn-sm"
            href="leads.php"
        >
            Back
        </a>

    </div>


    <form method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >


        <div class="form-grid">


            <!-- Lead Name -->

            <div class="field">

                <label>Lead Name</label>

                <input
                    class="form-control"
                    name="name"
                    value="<?= e($_POST['name'] ?? '') ?>"
                    required
                >

            </div>


            <!-- Company -->

            <div class="field">

                <label>Company</label>

                <input
                    class="form-control"
                    name="company"
                    value="<?= e($_POST['company'] ?? '') ?>"
                >

            </div>


            <!-- Email -->

            <div class="field">

                <label>Email</label>

                <input
                    class="form-control"
                    type="email"
                    name="email"
                    value="<?= e($_POST['email'] ?? '') ?>"
                >

            </div>


            <!-- Contact -->

            <div class="field">

                <label>Contact</label>

                <input
                    class="form-control"
                    name="contact"
                    value="<?= e($_POST['contact'] ?? '') ?>"
                >

            </div>


            <!-- Lead Source -->

            <div class="field">

                <label>Lead Source</label>

                <select
                    class="form-control"
                    name="source_id"
                    required
                >

                    <option value="">
                        Select Source
                    </option>

                    <?php foreach ($sources as $s): ?>

                        <option
                            value="<?= (int) $s['id'] ?>"
                            <?= (
                                (int) (
                                    $_POST['source_id'] ?? 0
                                )
                                === (int) $s['id']
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($s['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Client category and temperature label -->

            <div class="field">
                <label>Client Category</label>
                <select class="form-control" name="client_category" required>
                    <?php foreach (['Current Client', 'Old Client', 'Client Left', 'Prospect'] as $category): ?>
                        <option value="<?= e($category) ?>" <?= (($_POST['client_category'] ?? 'Prospect') === $category) ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Client Label</label>
                <select class="form-control" name="client_label" required>
                    <?php foreach (['Hot', 'Cold', 'Dead'] as $label): ?>
                        <option value="<?= e($label) ?>" <?= (($_POST['client_label'] ?? 'Cold') === $label) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Stage -->

            <div class="field">

                <label>Stage</label>

                <select
                    class="form-control"
                    name="stage_id"
                    id="stage_id"
                    required
                >

                    <option value="">
                        Select Stage
                    </option>

                    <?php foreach ($stages as $s): ?>

                        <option
                            value="<?= (int) $s['id'] ?>"
                            data-stage-name="<?= e($s['name']) ?>"
                            <?= (
                                (int) (
                                    $_POST['stage_id'] ?? 0
                                )
                                === (int) $s['id']
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($s['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Dynamic Subcategory -->

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


            <!-- Dynamic Comments -->

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
                ><?= e(
                    $_POST['stage_comments'] ?? ''
                ) ?></textarea>

            </div>


            <!-- Priority -->

            <div class="field">

                <label>Priority</label>

                <select
                    class="form-control"
                    name="priority"
                >

                    <?php foreach (
                        lead_priorities()
                        as $p
                    ): ?>

                        <option
                            value="<?= e($p) ?>"
                            <?= (
                                (
                                    $_POST['priority']
                                    ?? 'Medium'
                                ) === $p
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($p) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Assigned To -->

            <div class="field">

                <label>Assigned To</label>

                <select
                    class="form-control"
                    name="assigned_to"
                    <?= $current['role'] === 'staff'
                        ? 'disabled'
                        : '' ?>
                >

                    <option value="">
                        Unassigned
                    </option>

                    <?php foreach ($users as $u): ?>

                        <option
                            value="<?= (int) $u['id'] ?>"
                            <?= (
                                (int) (
                                    $_POST['assigned_to']
                                    ?? 0
                                )
                                === (int) $u['id']
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($u['username']) ?>
                            —
                            <?= e(
                                role_label($u['role'])
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Follow-up Date -->

            <div class="field">

                <label>
                    Initial Follow-up Date
                </label>

                <input
                    class="form-control"
                    type="date"
                    name="follow_up_date"
                    value="<?= e(
                        $_POST['follow_up_date'] ?? ''
                    ) ?>"
                >

            </div>


            <!-- Requirement -->

            <div class="field full">

                <label>
                    Requirement / Message
                </label>

                <textarea
                    class="form-control textarea"
                    name="message"
                ><?= e(
                    $_POST['message'] ?? ''
                ) ?></textarea>

            </div>


            <!-- Internal Notes -->

            <div class="field full">

                <label>
                    Internal Notes
                </label>

                <textarea
                    class="form-control textarea"
                    name="notes"
                ><?= e(
                    $_POST['notes'] ?? ''
                ) ?></textarea>

            </div>


        </div>


        <div class="form-actions">

            <button
                type="submit"
                class="btn btn-primary"
            >
                Create Lead
            </button>

        </div>

    </form>

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


/*
|--------------------------------------------------------------------------
| Update dynamic fields
|--------------------------------------------------------------------------
*/

function updateStageFields() {

    const stageSelect =
        document.getElementById('stage_id');

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


    /*
    |--------------------------------------------------------------------------
    | Clear previous options
    |--------------------------------------------------------------------------
    */

    subcategorySelect.innerHTML =
        '<option value="">Select an option</option>';


    /*
    |--------------------------------------------------------------------------
    | Get subcategories
    |--------------------------------------------------------------------------
    */

    const options =
        stageOptions[stageName] || [];


    /*
    |--------------------------------------------------------------------------
    | Display subcategory dropdown
    |--------------------------------------------------------------------------
    */

    if (options.length > 0) {

        subcategoryContainer.style.display =
            '';

        if (
            stageName ===
            'Meetings Scheduled'
        ) {

            subcategoryLabel.textContent =
                'Meeting Status';

        } else if (
            stageName ===
            'New Lead'
        ) {

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

    } else {

        subcategoryContainer.style.display =
            'none';

        subcategorySelect.value = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Meeting Comments
    |--------------------------------------------------------------------------
    */

    if (
        stageName ===
        'Meetings Scheduled'
    ) {

        commentsContainer.style.display =
            '';

    } else {

        commentsContainer.style.display =
            'none';

    }

}


/*
|--------------------------------------------------------------------------
| Stage changed
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


/*
|--------------------------------------------------------------------------
| Restore subcategory after validation error
|--------------------------------------------------------------------------
*/

const previousSubcategory =
    <?= json_encode(
        $_POST['stage_subcategory'] ?? '',
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


if (previousSubcategory) {

    document
        .getElementById('stage_subcategory')
        .value = previousSubcategory;

}

</script>


<?php

require __DIR__ . '/../includes/footer.php';

?>
