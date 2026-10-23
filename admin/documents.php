<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_access();

$page_title = 'Proposals & Documents';
$current_page = 'documents';

$leadId = (int)($_GET['lead_id'] ?? 0);
$user = current_user();
$error = '';

/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    if (!can_manage_sales()) {
        http_response_code(403);
        exit('Read-only access.');
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Upload document
    |--------------------------------------------------------------------------
    */

    if ($action === 'upload') {

        $leadId = (int)($_POST['lead_id'] ?? 0);

        if ($leadId < 1) {
            $error = 'Lead is required.';
        } else {

            // Make sure current user is allowed to access this lead.
            $leadCheck = assert_lead_access($pdo, $leadId);

            $type = trim($_POST['document_type'] ?? 'Other');

            $docTypeOptions = [
                'Audit PPT',
                'Case studies',
                'Proposal',
                'SoW'
            ];

            if (!in_array($type, $docTypeOptions, true)) {
                $error = 'Invalid document type.';
            }

            $leadStageStmt = $pdo->prepare("
                SELECT ps.name
                FROM leads l
                LEFT JOIN pipeline_stages ps ON ps.id = l.stage_id
                WHERE l.id = ?
                LIMIT 1
            ");
            $leadStageStmt->execute([$leadId]);
            $leadStageName = $leadStageStmt->fetchColumn();

            if (
                !$error &&
                $leadStageName === 'Collateral Document Shared with client' &&
                $type === 'Proposal'
            ) {
                $error = 'Proposal is not an allowed document type for documents shared with the client.';
            }

            if ($error) {

                // Validation error already set above.

            } elseif (
                empty($_FILES['document']['name']) ||
                !isset($_FILES['document'])
            ) {

                $error = 'Lead and document are required.';

            } else {

                $file = $_FILES['document'];

                /*
                |--------------------------------------------------------------------------
                | Allowed file types
                |--------------------------------------------------------------------------
                */

                $allowed = [

                    'pdf' => 'application/pdf',

                    'txt' => 'text/plain',

                    'doc' => 'application/msword',

                    'docx' =>
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

                    'xls' => 'application/vnd.ms-excel',

                    'xlsx' =>
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

                    'ppt' => 'application/vnd.ms-powerpoint',

                    'pptx' =>
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation',

                    'jpg' => 'image/jpeg',

                    'jpeg' => 'image/jpeg',

                    'png' => 'image/png',

                    'gif' => 'image/gif',

                    'webp' => 'image/webp'
                ];

                $originalName = basename($file['name']);

                $ext = strtolower(
                    pathinfo($originalName, PATHINFO_EXTENSION)
                );

                /*
                |--------------------------------------------------------------------------
                | Validate upload
                |--------------------------------------------------------------------------
                */

                if ($file['error'] !== UPLOAD_ERR_OK) {

                    switch ($file['error']) {

                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $error = 'The uploaded file is too large.';
                            break;

                        case UPLOAD_ERR_PARTIAL:
                            $error = 'The file upload was incomplete.';
                            break;

                        case UPLOAD_ERR_NO_FILE:
                            $error = 'Please select a file.';
                            break;

                        default:
                            $error = 'Upload failed. Please try again.';
                            break;
                    }

                } elseif ($file['size'] > 10 * 1024 * 1024) {

                    $error = 'Maximum file size is 10 MB.';

                } elseif (!isset($allowed[$ext])) {

                    $error =
                        'File type not allowed. Allowed types: PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, JPEG, PNG, GIF and WEBP.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Storage directory
                    |--------------------------------------------------------------------------
                    */

                    $dir = __DIR__ . '/../storage/documents';

                    ensure_dir($dir);

                    if (!is_dir($dir)) {

                        $error =
                            'Document storage directory could not be created.';

                    } elseif (!is_writable($dir)) {

                        $error =
                            'Document storage directory is not writable.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Generate safe filename
                        |--------------------------------------------------------------------------
                        */

                        try {

                            $safe =
                                bin2hex(random_bytes(16))
                                . '.'
                                . $ext;

                        } catch (Throwable $e) {

                            $safe =
                                uniqid('', true)
                                . '.'
                                . $ext;
                        }

                        $path = $dir . '/' . $safe;

                        /*
                        |--------------------------------------------------------------------------
                        | Move uploaded file
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !move_uploaded_file(
                                $file['tmp_name'],
                                $path
                            )
                        ) {

                            $error =
                                'Could not save the uploaded file. Please check folder permissions.';

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | Save document in database
                            |--------------------------------------------------------------------------
                            */

                            try {

                                $s = $pdo->prepare(
                                    'INSERT INTO documents
                                    (
                                        lead_id,
                                        document_type,
                                        file_name,
                                        file_path,
                                        uploaded_by
                                    )
                                    VALUES (?, ?, ?, ?, ?)'
                                );

                                $s->execute([
                                    $leadId,
                                    $type,
                                    $originalName,
                                    $path,
                                    $user['id']
                                ]);

                                /*
                                |--------------------------------------------------------------------------
                                | Activity
                                |--------------------------------------------------------------------------
                                */

                                log_activity(
                                    $pdo,
                                    $leadId,
                                    $user['id'],
                                    'Document',
                                    'Uploaded ' .
                                    $originalName .
                                    ' (' .
                                    $type .
                                    ').'
                                );

                                /*
                                |--------------------------------------------------------------------------
                                | Move lead to Proposal / Documents stage
                                |--------------------------------------------------------------------------
                                */

                                $stage = $pdo
                                    ->query(
                                        "SELECT id
                                         FROM pipeline_stages
                                         WHERE name = 'Collateral Document Preparation DA Team'
                                         LIMIT 1"
                                    )
                                    ->fetchColumn();

                                if ($stage) {

                                    $pdo->prepare(
                                        "UPDATE leads
                                         SET
                                             stage_id = ?,
                                             stage_subcategory = ?,
                                             stage_comments = NULL,
                                             status = 'Collateral Prep'
                                         WHERE id = ?
                                         AND COALESCE(stage_id, 0) NOT IN
                                         (
                                             SELECT id
                                             FROM pipeline_stages
                                             WHERE name IN
                                             (
                                                 'Collateral Document Shared with client',
                                                 'Under Negotiation',
                                                 'Converted/Won'
                                             )
                                         )"
                                    )->execute([
                                        (int)$stage,
                                        $type !== '' ? $type : null,
                                        $leadId
                                    ]);
                                }

                                flash(
                                    'success',
                                    'Document uploaded successfully.'
                                );

                                redirect(
                                    'documents.php?lead_id=' .
                                    $leadId
                                );

                            } catch (Throwable $e) {

                                /*
                                |--------------------------------------------------------------------------
                                | If database insert fails, remove uploaded file
                                |--------------------------------------------------------------------------
                                */

                                if (is_file($path)) {
                                    @unlink($path);
                                }

                                $error =
                                    'The file was uploaded but could not be saved to the CRM database.';
                            }
                        }
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete document
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'delete') {

        $docId = (int)($_POST['id'] ?? 0);

        $s = $pdo->prepare(
            'SELECT *
             FROM documents
             WHERE id = ?
             LIMIT 1'
        );

        $s->execute([$docId]);

        $doc = $s->fetch(PDO::FETCH_ASSOC);

        if ($doc) {

            assert_lead_access(
                $pdo,
                (int)$doc['lead_id']
            );

            if (
                !empty($doc['file_path']) &&
                is_file($doc['file_path'])
            ) {

                @unlink($doc['file_path']);
            }

            $pdo
                ->prepare(
                    'DELETE FROM documents WHERE id = ?'
                )
                ->execute([$docId]);

            log_activity(
                $pdo,
                (int)$doc['lead_id'],
                $user['id'],
                'Document',
                'Deleted ' .
                basename($doc['file_name']) .
                '.'
            );

            flash(
                'success',
                'Document deleted.'
            );
        }

        redirect(
            'documents.php' .
            ($leadId
                ? '?lead_id=' . $leadId
                : '')
        );
    }
}

/*
|--------------------------------------------------------------------------
| Get leads available to current user
|--------------------------------------------------------------------------
*/

$params = [];

$vis = lead_visibility_sql(
    $params,
    'l'
);

$q = $pdo->prepare(
    "SELECT
        l.id,
        l.name,
        l.company
     FROM leads l
     WHERE 1=1
     {$vis}
     ORDER BY l.created_at DESC"
);

$q->execute($params);

$leads = $q->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Get documents
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        d.*,
        l.name AS lead_name,
        l.company,
        u.username AS uploader

    FROM documents d

    JOIN leads l
        ON l.id = d.lead_id

    LEFT JOIN users u
        ON u.id = d.uploaded_by
";

$params = [];

$vis = lead_visibility_sql(
    $params,
    'l'
);

$sql .= ' WHERE 1=1 ' . $vis;

if ($leadId) {

    $sql .= ' AND d.lead_id = ?';

    $params[] = $leadId;
}

$sql .= '
    ORDER BY d.created_at DESC
    LIMIT 150
';

$s = $pdo->prepare($sql);

$s->execute($params);

$docs = $s->fetchAll(
    PDO::FETCH_ASSOC
);

require __DIR__ . '/../includes/header.php';

?>

<?php if ($error): ?>

    <div class="alert danger">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<div class="detail-grid">

    <!-- =========================================================
         DOCUMENT LIST
         ========================================================= -->

    <div class="section-card">

        <div class="section-head">

            <h2>
                Documents
            </h2>

        </div>


        <div class="table-wrap">

            <table class="table">

                <thead>

                    <tr>

                        <th>Lead</th>

                        <th>Type</th>

                        <th>File</th>

                        <th>Uploaded By</th>

                        <th>Date</th>

                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>

                <?php if (!$docs): ?>

                    <tr>

                        <td
                            colspan="6"
                            class="empty"
                        >
                            No documents uploaded.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($docs as $d): ?>

                        <?php

                        $extension = strtolower(
                            pathinfo(
                                $d['file_name'],
                                PATHINFO_EXTENSION
                            )
                        );

                        $previewable = in_array(
                            $extension,
                            [
                                'pdf',
                                'txt',
                                'jpg',
                                'jpeg',
                                'png',
                                'gif',
                                'webp'
                            ],
                            true
                        );

                        ?>

                        <tr>

                            <td>

                                <a
                                    href="lead.php?id=<?= (int)$d['lead_id'] ?>"
                                >
                                    <?= e(
                                        $d['company']
                                        ?: $d['lead_name']
                                    ) ?>
                                </a>

                            </td>


                            <td>

                                <?= e(
                                    $d['document_type']
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    $d['file_name']
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    $d['uploader']
                                    ?: 'System'
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    format_day(
                                        $d['created_at']
                                    )
                                ) ?>

                            </td>


                            <td class="actions">

                                <?php if ($previewable): ?>

                                    <a
                                        class="btn btn-light btn-sm"
                                        href="preview_document.php?id=<?= (int)$d['id'] ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Preview
                                    </a>

                                <?php endif; ?>


                                <a
                                    class="btn btn-light btn-sm"
                                    href="download_document.php?id=<?= (int)$d['id'] ?>"
                                >
                                    Download
                                </a>


<?php if ($user['role'] !== 'qc'): ?>
                                <form
                                    method="post"
                                    style="display:inline"
                                    onsubmit="return confirm('Delete this document?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$d['id'] ?>"
                                    >

                                    <button
                                        class="btn btn-danger btn-sm"
                                        type="submit"
                                    >
                                        Delete
                                    </button>

                                </form>
                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


<?php if ($user['role'] !== 'qc'): ?>
    <!-- =========================================================
         UPLOAD DOCUMENT
         ========================================================= -->

    <div class="section-card form-card">

        <h2 style="margin-top:0">
            Upload Document
        </h2>


        <form
            method="post"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="upload"
            >


            <div class="field">

                <label>
                    Lead
                </label>

                <select
                    class="form-control"
                    name="lead_id"
                    required
                >

                    <option value="">
                        Select Lead
                    </option>

                    <?php foreach ($leads as $l): ?>

                        <option
                            value="<?= (int)$l['id'] ?>"
                            <?= $leadId === (int)$l['id']
                                ? 'selected'
                                : '' ?>
                        >

                            <?= e(
                                $l['company']
                                ?: $l['name']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div
                class="field"
                style="margin-top:18px"
            >

                <label>
                    Document Type
                </label>

                <select
                    class="form-control"
                    name="document_type"
                >

                    <option>
                        Audit PPT
                    </option>

                    <option>
                        Case studies
                    </option>

                    <option>
                        Proposal
                    </option>

                    <option>
                        SoW
                    </option>

                </select>

            </div>


            <div
                class="field"
                style="margin-top:18px"
            >

                <label>
                    File (max 10 MB)
                </label>

                <input
                    class="form-control"
                    type="file"
                    name="document"
                    required
                    accept=".pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp"
                >

                <div
                    class="muted"
                    style="margin-top:6px"
                >
                    PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX,
                    JPG, JPEG, PNG, GIF and WEBP.
                </div>

            </div>


            <div class="form-actions">

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Upload
                </button>

            </div>

        </form>

    </div>

</div>


<?php

require __DIR__ . '/../includes/footer.php';

?><?php endif; ?>


