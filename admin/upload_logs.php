<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_access();

$log = $_SESSION['lead_import_log'] ?? null;
$page_title = 'Lead Upload Logs';
$current_page = 'leads';
require __DIR__ . '/../includes/header.php';

$entries = is_array($log['entries'] ?? null) ? $log['entries'] : [];
$added = (int)($log['added'] ?? 0);
$skipped = (int)($log['skipped'] ?? 0);
$total = $added + $skipped;
?>
<style>
    .upload-log-page { display:grid; gap:24px; }
    .upload-log-page .section-card { padding:24px; }
    .upload-log-head { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; flex-wrap:wrap; }
    .upload-log-title { margin:0 0 8px; line-height:1.25; }
    .upload-log-subtitle { margin:0; color:var(--muted, #64748b); line-height:1.6; }
    .upload-log-meta { display:grid; grid-template-columns:repeat(2,minmax(280px,360px)); gap:18px; margin-top:26px; }
    .upload-log-meta-item { box-sizing:border-box; min-width:0; min-height:94px; padding:18px 22px; border:1px solid var(--border, #e2e8f0); border-radius:14px; background:var(--surface-soft, #f8fafc); display:flex; flex-direction:column; justify-content:center; gap:8px; }
    .upload-log-meta-item strong { display:block; font-size:11px; line-height:1.4; letter-spacing:.06em; text-transform:uppercase; color:var(--muted, #64748b); margin:0; }
    .upload-log-meta-item span { display:block; color:var(--text, #1e293b); line-height:1.5; overflow-wrap:anywhere; }
    .upload-log-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; }
    .upload-log-stat { box-sizing:border-box; min-height:112px; padding:22px 24px; border:1px solid var(--border, #e2e8f0); border-radius:14px; background:var(--surface, #fff); display:flex; flex-direction:column; justify-content:center; gap:10px; }
    .upload-log-stat-label { color:var(--muted, #64748b); font-size:13px; line-height:1.4; }
    .upload-log-stat-value { font-size:29px; line-height:1.1; font-weight:700; color:var(--text, #1e293b); }
    .upload-log-stat.success .upload-log-stat-value { color:#15803d; }
    .upload-log-stat.warning .upload-log-stat-value { color:#b45309; }
    .upload-log-table-card { padding:0 !important; overflow:hidden; }
    .upload-log-table-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:22px 24px; border-bottom:1px solid var(--border, #e2e8f0); }
    .upload-log-table-heading h3 { margin:0; }
    .upload-log-count { color:var(--muted, #64748b); font-size:13px; }
    .upload-log-table-wrap { width:100%; overflow-x:auto; }
    .upload-log-table { width:100%; min-width:720px; border-collapse:collapse; }
    .upload-log-table th { white-space:nowrap; text-align:left; }
    .upload-log-table th, .upload-log-table td { padding:17px 24px; }
    .upload-log-table td { vertical-align:top; border-bottom:1px solid var(--border, #e2e8f0); line-height:1.55; }
    .upload-log-table tbody tr:last-child td { border-bottom:0; }
    .upload-log-rownum { display:inline-flex; min-width:34px; min-height:28px; align-items:center; justify-content:center; padding:4px 9px; border-radius:8px; background:var(--surface-soft, #f1f5f9); font-weight:600; }
    .upload-log-status { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 11px; font-size:12px; font-weight:700; white-space:nowrap; }
    .upload-log-status.imported { color:#166534; background:#dcfce7; }
    .upload-log-status.skipped { color:#991b1b; background:#fee2e2; }
    .upload-log-status.other { color:#92400e; background:#fef3c7; }
    .upload-log-message { min-width:220px; max-width:700px; overflow-wrap:anywhere; line-height:1.6; }
    .upload-log-empty { padding:36px 20px; text-align:center; }
    .upload-log-empty h3 { margin:0 0 10px; }
    .upload-log-empty p { margin:0 0 20px; color:var(--muted, #64748b); line-height:1.6; }
    @media (max-width:760px) {
        .upload-log-page { gap:18px; }
        .upload-log-page .section-card { padding:18px; }
        .upload-log-meta { grid-template-columns:1fr; gap:12px; }
        .upload-log-meta-item { min-height:82px; }
        .upload-log-stats { grid-template-columns:1fr; gap:12px; }
        .upload-log-stat { min-height:88px; padding:17px 19px; }
        .upload-log-table-card { padding:0 !important; }
        .upload-log-table-heading { padding:18px; }
        .upload-log-table th, .upload-log-table td { padding:14px 16px; }
    }
</style>

<div class="upload-log-page">
    <section class="section-card">
        <div class="upload-log-head">
            <div>
                <h2 class="upload-log-title">Lead Upload Logs</h2>
                <p class="upload-log-subtitle">Review imported rows, skipped records, and any corrections made during the latest upload.</p>
            </div>
            <a class="btn btn-light" href="leads.php">← Back to Leads</a>
        </div>

        <?php if (!$log): ?>
            <div class="upload-log-empty">
                <h3>No upload history available</h3>
                <p>Import a CSV or Excel file to see row-by-row results here.</p>
                <a class="btn btn-primary" href="leads.php">Go to Leads</a>
            </div>
        <?php else: ?>
            <div class="upload-log-meta">
                <div class="upload-log-meta-item"><strong>File name</strong><span><?= e($log['filename'] ?? 'Unknown') ?></span></div>
                <div class="upload-log-meta-item"><strong>Import time</strong><span><?= e($log['created_at'] ?? '-') ?></span></div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($log): ?>
        <section class="upload-log-stats" aria-label="Import summary">
            <div class="upload-log-stat">
                <div class="upload-log-stat-label">Total rows processed</div>
                <div class="upload-log-stat-value"><?= $total ?></div>
            </div>
            <div class="upload-log-stat success">
                <div class="upload-log-stat-label">Successfully imported</div>
                <div class="upload-log-stat-value"><?= $added ?></div>
            </div>
            <div class="upload-log-stat warning">
                <div class="upload-log-stat-label">Skipped rows</div>
                <div class="upload-log-stat-value"><?= $skipped ?></div>
            </div>
        </section>

        <section class="section-card upload-log-table-card">
            <div class="upload-log-table-heading">
                <h3>Row-by-row results</h3>
                <span class="upload-log-count"><?= count($entries) ?> log entr<?= count($entries) === 1 ? 'y' : 'ies' ?></span>
            </div>
            <?php if (!$entries): ?>
                <p class="muted" style="padding:20px 24px;">No row details were recorded for this import.</p>
            <?php else: ?>
                <div class="upload-log-table-wrap">
                    <table class="table upload-log-table">
                        <thead>
                            <tr><th>File row</th><th>Lead</th><th>Status</th><th>Details</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry):
                            $status = strtolower(trim((string)($entry['status'] ?? '')));
                            $statusClass = in_array($status, ['imported', 'success', 'successful'], true) ? 'imported' : (in_array($status, ['skipped', 'failed', 'error'], true) ? 'skipped' : 'other');
                            $statusLabel = $entry['status'] ?? 'Unknown';
                        ?>
                            <tr>
                                <td><span class="upload-log-rownum"><?= (int)($entry['row'] ?? 0) ?></span></td>
                                <td><strong><?= e($entry['lead'] ?? '-') ?></strong></td>
                                <td><span class="upload-log-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td><div class="upload-log-message"><?= e($entry['message'] ?? '-') ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>