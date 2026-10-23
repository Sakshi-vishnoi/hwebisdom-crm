<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_sales_access();

$page_title = 'Leads';
$current_page = 'leads';

$q = trim($_GET['q'] ?? '');
$stage = (int)($_GET['stage'] ?? 0);
$source = (int)($_GET['source'] ?? 0);
$priority = trim($_GET['priority'] ?? '');
$assigned = (int)($_GET['assigned'] ?? 0);
$clientCategory = trim($_GET['client_category'] ?? '');
$clientLabel = trim($_GET['client_label'] ?? '');
$clientCategories = ['Current Client', 'Old Client', 'Client Left', 'Prospect'];
$clientLabels = ['Hot', 'Cold', 'Dead'];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = [];
$params = [];

/*
|--------------------------------------------------------------------------
| Normal filters
|--------------------------------------------------------------------------
*/

if ($q !== '') {
    $where[] = '(l.name LIKE ? 
        OR l.company LIKE ? 
        OR l.email LIKE ? 
        OR l.contact LIKE ? 
        OR l.message LIKE ?)';

    $like = '%' . $q . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($stage > 0) {
    $where[] = 'l.stage_id = ?';
    $params[] = $stage;
}

if ($source > 0) {
    $where[] = 'l.source_id = ?';
    $params[] = $source;
}

if (in_array($priority, lead_priorities(), true)) {
    $where[] = 'l.priority = ?';
    $params[] = $priority;
}

if ($assigned > 0) {
    $where[] = 'l.assigned_to = ?';
    $params[] = $assigned;
}

if (in_array($clientCategory, $clientCategories, true)) {
    $where[] = 'l.client_category = ?';
    $params[] = $clientCategory;
}

if (in_array($clientLabel, $clientLabels, true)) {
    $where[] = 'l.client_label = ?';
    $params[] = $clientLabel;
}

/*
|--------------------------------------------------------------------------
| Sales visibility
|--------------------------------------------------------------------------
|
| Admin / B2B Manager / QC can see all leads.
| Sales Team can only see leads assigned to them or created by them.
|
*/

$visibilitySql = lead_visibility_sql($params, 'l');

if ($visibilitySql !== '') {
    $visibilitySql = trim($visibilitySql);

    // lead_visibility_sql() returns something like:
    // AND (l.assigned_to = ? OR l.created_by = ?)

    if (stripos($visibilitySql, 'AND ') === 0) {
        $visibilitySql = substr($visibilitySql, 4);
    }

    $where[] = '(' . $visibilitySql . ')';
}

/*
|--------------------------------------------------------------------------
| WHERE clause
|--------------------------------------------------------------------------
*/

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countSql = "SELECT COUNT(*) FROM leads l {$whereSql}";

$c = $pdo->prepare($countSql);
$c->execute($params);

$total = (int)$c->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));

$page = min($page, $pages);

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Get leads
|--------------------------------------------------------------------------
*/

$sql = "
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

    {$whereSql}

    ORDER BY l.created_at DESC

    LIMIT {$perPage}
    OFFSET {$offset}
";

$s = $pdo->prepare($sql);
$s->execute($params);

$leads = $s->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Filter data
|--------------------------------------------------------------------------
*/

$stages = pipeline_stages($pdo);
$sources = lead_sources($pdo);

$currentUser = current_user();

if (($currentUser['role'] ?? '') === 'staff') {

    $users = [[
        'id' => $currentUser['id'],
        'username' => $currentUser['username']
    ]];

} else {

    $users = $pdo
        ->query('SELECT id, username FROM users ORDER BY username')
        ->fetchAll(PDO::FETCH_ASSOC);
}

require __DIR__ . '/../includes/header.php';

?>
<?php if (!empty($_SESSION['lead_import_log'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const successAlert = document.querySelector('.alert.success');
        if (!successAlert) return;
        const separator = document.createTextNode(' ');
        const logLink = document.createElement('a');
        logLink.href = 'upload_logs.php';
        logLink.textContent = 'Check upload logs for details';
        logLink.style.marginLeft = '8px';
        logLink.style.fontWeight = '600';
        logLink.style.textDecoration = 'underline';
        successAlert.appendChild(separator);
        successAlert.appendChild(logLink);
    });
    </script>
<?php endif; ?>

<?php if (can_manage_sales()): ?>
<div class="section-card" style="margin-bottom:22px;padding:20px 22px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:18px">
        <div style="min-width:220px;flex:1">
            <h2 style="margin:0 0 6px">Import Leads</h2>
            <div class="muted">Upload a CSV or Excel (.xlsx) file. Imported leads will appear with your other leads.</div>
        </div>
        <a class="btn btn-light" style="white-space:nowrap" href="download_leads_template.php">Download CSV Template</a>
    </div>

    <form method="post" action="import_leads.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:16px;background:var(--surface-muted, #f8fafc);border:1px solid var(--border, #e2e8f0);border-radius:10px">
            <div style="flex:1;min-width:240px">
                <label for="leads_file" style="display:block;font-weight:600;margin-bottom:7px">Choose file</label>
                <input id="leads_file" type="file" name="leads_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="display:block;width:100%;max-width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn-primary" type="submit" style="align-self:flex-end;white-space:nowrap">Upload and Import</button>
        </div>
    </form>

    <div class="muted" style="margin-top:12px;line-height:1.6">
        <strong>File limits:</strong> 5 MB maximum · Up to 5,000 rows.<br>
        Empty fields can be filled in later. QC members have read-only access and cannot import leads.
    </div>
</div>
<?php endif; ?>

<form class="toolbar" method="get">

    <input
        class="search"
        name="q"
        value="<?= e($q) ?>"
        placeholder="Search name, company, email, phone..."
    >

    <select name="stage">
        <option value="0">All stages</option>

        <?php foreach ($stages as $x): ?>

            <option
                value="<?= (int)$x['id'] ?>"
                <?= $stage === (int)$x['id'] ? 'selected' : '' ?>
            >
                <?= e($x['name']) ?>
            </option>

        <?php endforeach; ?>

    </select>

    <select name="source">

        <option value="0">All sources</option>

        <?php foreach ($sources as $x): ?>

            <option
                value="<?= (int)$x['id'] ?>"
                <?= $source === (int)$x['id'] ? 'selected' : '' ?>
            >
                <?= e($x['name']) ?>
            </option>

        <?php endforeach; ?>

    </select>

    <select name="priority">

        <option value="">All priorities</option>

        <?php foreach (lead_priorities() as $p): ?>

            <option
                value="<?= e($p) ?>"
                <?= $priority === $p ? 'selected' : '' ?>
            >
                <?= e($p) ?>
            </option>

        <?php endforeach; ?>

    </select>

    <select name="assigned">

        <option value="0">All assignees</option>

        <?php foreach ($users as $u): ?>

            <option
                value="<?= (int)$u['id'] ?>"
                <?= $assigned === (int)$u['id'] ? 'selected' : '' ?>
            >
                <?= e($u['username']) ?>
            </option>

        <?php endforeach; ?>

    </select>

    <select name="client_category">
        <option value="">All client categories</option>
        <?php foreach ($clientCategories as $category): ?>
            <option value="<?= e($category) ?>" <?= $clientCategory === $category ? 'selected' : '' ?>><?= e($category) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="client_label">
        <option value="">All labels</option>
        <?php foreach ($clientLabels as $label): ?>
            <option value="<?= e($label) ?>" <?= $clientLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-primary" type="submit">
        Filter
    </button>

    <a class="btn btn-light" href="leads.php">
        Reset
    </a>

    <a
        class="btn btn-light"
        href="export.php?<?= e(http_build_query([
            'q' => $q,
            'stage' => $stage,
            'source' => $source,
            'priority' => $priority,
            'assigned' => $assigned,
            'client_category' => $clientCategory,
            'client_label' => $clientLabel
        ])) ?>"
    >
        Export CSV
    </a>

</form>

<div class="section-card">

    <div class="section-head">

        <h2>
            All Leads
            <span class="muted">(<?= $total ?>)</span>
        </h2>

    </div>

    <form
        method="post"
        action="bulk_delete.php"
        onsubmit="return confirm('Delete the selected leads? This cannot be undone.');"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <div class="table-wrap">

            <table class="table">

                <thead>

                    <tr>

                        <?php if (current_user()['role'] === 'admin'): ?>
                            <th>
                                <input
                                    class="checkbox"
                                    type="checkbox"
                                    id="selectAll"
                                >
                            </th>
                        <?php endif; ?>

                        <th>#</th>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Source</th>
                        <th>Stage</th>
                        <th>Client Category</th>
                        <th>Label</th>
                        <th>Priority</th>
                        <th>Assigned</th>
                        <th>Follow-up</th>
                        <th>Created</th>
                        <th></th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$leads): ?>

                    <tr>

                        <td
                            colspan="<?= current_user()['role'] === 'admin' ? 13 : 12 ?>"
                            class="empty"
                        >
                            No leads match your filters.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($leads as $lead): ?>

                        <tr>

                            <?php if (current_user()['role'] === 'admin'): ?>
                                <td>
                                    <input
                                        class="checkbox lead-checkbox"
                                        type="checkbox"
                                        name="ids[]"
                                        value="<?= (int)$lead['id'] ?>"
                                    >
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= (int)$lead['id'] ?>
                            </td>

                            <td>

                                <strong>
                                    <?= e(
                                        $lead['company']
                                        ?: ($lead['name'] ?: 'Unnamed')
                                    ) ?>
                                </strong>

                                <div class="muted">
                                    <?= e($lead['name'] ?: '-') ?>
                                    ·
                                    <?= e($lead['email'] ?: '-') ?>
                                </div>

                            </td>

                            <td>
                                <?= e($lead['contact'] ?: '-') ?>
                            </td>

                            <td>
                                <?= e($lead['source'] ?: 'Google / Website') ?>
                            </td>

                            <td>

                                <span
                                    class="badge <?= e(
                                        stage_class(
                                            $lead['stage'] ?: 'New Lead'
                                        )
                                    ) ?>"
                                >
                                    <?= e($lead['stage'] ?: 'New Lead') ?>
                                </span>

                            </td>

                            <td><span class="badge badge-blue"><?= e($lead['client_category'] ?? 'Prospect') ?></span></td>

                            <td><span class="badge <?= ($lead['client_label'] ?? 'Cold') === 'Hot' ? 'badge-red' : ((($lead['client_label'] ?? 'Cold') === 'Dead') ? 'badge-gray' : 'badge-blue') ?>"><?= e($lead['client_label'] ?? 'Cold') ?></span></td>

                            <td>

                                <span
                                    class="badge <?= e(
                                        priority_class($lead['priority'])
                                    ) ?>"
                                >
                                    <?= e($lead['priority']) ?>
                                </span>

                            </td>

                            <td>
                                <?= e($lead['assignee'] ?: 'Unassigned') ?>
                            </td>

                            <td>
                                <?= e($lead['follow_up_date'] ?: '-') ?>
                            </td>

                            <td class="muted">
                                <?= e(format_day($lead['created_at'])) ?>
                            </td>

                            <td>

                                <a
                                    class="btn btn-light btn-sm"
                                    href="lead.php?id=<?= (int)$lead['id'] ?>"
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

        <div
            class="section-head"
            style="border-top:1px solid var(--border);border-bottom:0"
        >

            <?php if (current_user()['role'] === 'admin'): ?>
                <button
                    class="btn btn-danger btn-sm"
                    id="deleteBtn"
                    type="submit"
                    disabled
                >
                    Delete Selected
                </button>
            <?php endif; ?>

            <div class="muted">
                Page <?= $page ?> of <?= $pages ?>
            </div>

        </div>

    </form>

    <?php if ($pages > 1): ?>

        <div class="pagination">

            <?php for ($i = 1; $i <= $pages; $i++): ?>

                <?php

                $url = 'leads.php?' . http_build_query([
                    'q' => $q,
                    'stage' => $stage,
                    'source' => $source,
                    'priority' => $priority,
                    'assigned' => $assigned,
                    'page' => $i
                ]);

                ?>

                <a
                    class="<?= $i === $page ? 'current' : '' ?>"
                    href="<?= e($url) ?>"
                >
                    <?= $i ?>
                </a>

            <?php endfor; ?>

        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>