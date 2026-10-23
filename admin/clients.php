<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_access();

$page_title = 'Clients';
$current_page = 'clients';
$q = trim($_GET['q'] ?? '');
$category = trim($_GET['client_category'] ?? '');
$label = trim($_GET['client_label'] ?? '');
$categories = ['Current Client', 'Old Client', 'Client Left', 'Prospect'];
$labels = ['Hot', 'Cold', 'Dead'];
$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(l.name LIKE ? OR l.company LIKE ? OR l.email LIKE ? OR l.contact LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($category, $categories, true)) {
    $where[] = 'l.client_category = ?';
    $params[] = $category;
}
if (in_array($label, $labels, true)) {
    $where[] = 'l.client_label = ?';
    $params[] = $label;
}
$visibility = lead_visibility_sql($params, 'l');
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : ' WHERE 1=1';
$whereSql .= $visibility;

$sql = "SELECT l.id, l.name, l.company, l.email, l.contact, l.client_category, l.client_label,
               l.priority, l.follow_up_date, l.created_at,
               ps.name AS stage, u.username AS assignee
        FROM leads l
        LEFT JOIN pipeline_stages ps ON ps.id = l.stage_id
        LEFT JOIN users u ON u.id = l.assigned_to
        $whereSql
        ORDER BY FIELD(l.client_category, 'Current Client', 'Prospect', 'Old Client', 'Client Left'), l.company, l.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statsParams = [];
$statsVisibility = lead_visibility_sql($statsParams, 'l');
$statsStmt = $pdo->prepare("SELECT COALESCE(l.client_category, 'Prospect') category, COUNT(*) total FROM leads l WHERE 1=1 $statsVisibility GROUP BY COALESCE(l.client_category, 'Prospect')");
$statsStmt->execute($statsParams);
$stats = array_fill_keys($categories, 0);
foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $stats[$row['category']] = (int)$row['total'];

require __DIR__ . '/../includes/header.php';
?>
<style>
/* Clients directory: page-scoped responsive layout */
.clients-page{--cl-ink:#17243b;--cl-muted:#718096;--cl-line:#e6ebf2;--cl-teal:#0faaa7;color:var(--cl-ink);max-width:1600px;margin:0 auto}
.clients-page *{box-sizing:border-box}
.clients-page .clients-hero{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:25px 27px;margin-bottom:20px;border:1px solid #dfe9ef;border-radius:18px;background:linear-gradient(115deg,#f0fbfa 0%,#fff 62%,#f6f8ff 100%)}
.clients-page .eyebrow{font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#0b918f;margin:0 0 7px}
.clients-page .clients-hero h2{font-size:25px;line-height:1.2;letter-spacing:-.035em;margin:0 0 7px;color:#14233b}
.clients-page .clients-hero p{margin:0;color:#718096;font-size:13px;line-height:1.6;max-width:650px}
.clients-page .clients-hero-actions{display:flex;gap:10px;align-items:center;flex-shrink:0}
.clients-page .clients-hero .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 15px;border-radius:10px;text-decoration:none;font-weight:700;white-space:nowrap}
.clients-page .client-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
.clients-page .client-stat{position:relative;display:block;min-width:0;padding:18px 19px 17px;border:1px solid var(--cl-line);border-radius:15px;background:#fff;color:inherit;text-decoration:none;box-shadow:0 2px 8px rgba(21,39,66,.025);transition:transform .15s,border-color .15s,box-shadow .15s}
.clients-page .client-stat:hover{transform:translateY(-2px);border-color:#b7dedd;box-shadow:0 8px 22px rgba(21,39,66,.07)}
.clients-page .client-stat.is-active{border-color:#16b8b2;box-shadow:0 0 0 2px rgba(22,184,178,.12)}
.clients-page .stat-top{display:flex;justify-content:space-between;align-items:center;gap:8px;color:#65748b;font-size:12px;font-weight:700}
.clients-page .stat-dot{width:9px;height:9px;border-radius:50%;background:#9ca9bb;flex-shrink:0}
.clients-page .client-stat:nth-child(1) .stat-dot{background:#13aaa4}.clients-page .client-stat:nth-child(2) .stat-dot{background:#7587ed}.clients-page .client-stat:nth-child(3) .stat-dot{background:#f0a33b}.clients-page .client-stat:nth-child(4) .stat-dot{background:#8b98aa}
.clients-page .stat-number{font-size:30px;line-height:1.15;font-weight:800;letter-spacing:-.04em;margin-top:12px;color:#17243b}
.clients-page .stat-foot{font-size:11px;color:#9aa5b5;margin-top:6px}
.clients-page .client-filter{padding:19px 20px 17px;margin-bottom:18px;background:#fff;border:1px solid var(--cl-line);border-radius:15px;box-shadow:0 2px 8px rgba(21,39,66,.025)}
.clients-page .filter-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}
.clients-page .filter-heading h3{font-size:14px;margin:0;font-weight:800;color:#26354d}
.clients-page .filter-heading span{font-size:12px;color:#8793a5}
.clients-page .filter-grid{display:grid;grid-template-columns:minmax(230px,2fr) minmax(170px,1fr) minmax(150px,1fr) auto;gap:12px;align-items:end}
.clients-page .filter-grid label{display:block;margin:0;color:#53627a;font-size:12px;font-weight:700}
.clients-page .filter-grid input,.clients-page .filter-grid select{display:block;width:100%;height:42px;margin-top:7px;padding:0 12px;border:1px solid #dce3ec;border-radius:9px;background:#fff;color:#23324a;font-size:13px;outline:none}
.clients-page .filter-grid input:focus,.clients-page .filter-grid select:focus{border-color:#17b5b0;box-shadow:0 0 0 3px rgba(23,181,176,.12)}
.clients-page .filter-buttons{display:flex;gap:8px}
.clients-page .filter-buttons .btn{height:42px;display:inline-flex;align-items:center;justify-content:center;padding:0 15px;border-radius:9px;font-weight:700;text-decoration:none;white-space:nowrap}
.clients-page .records-panel{overflow:hidden;border:1px solid var(--cl-line);border-radius:16px;background:#fff;box-shadow:0 3px 12px rgba(21,39,66,.035)}
.clients-page .records-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid #edf0f5}
.clients-page .records-title{font-size:17px;font-weight:800;margin:0;color:#1b2940;letter-spacing:-.02em}
.clients-page .records-subtitle{font-size:12px;color:#8491a4;margin:5px 0 0}
.clients-page .result-count{display:inline-flex;align-items:center;justify-content:center;min-width:31px;height:25px;padding:0 8px;border-radius:8px;background:#eef8f8;color:#0b8987;font-size:12px;font-weight:800;vertical-align:2px}
.clients-page .records-list{padding:0 18px}
.clients-page .record-row{display:grid;grid-template-columns:minmax(220px,2.1fr) minmax(145px,1.2fr) minmax(150px,1.2fr) minmax(150px,1.3fr) 105px;gap:16px;align-items:center;padding:17px 7px;border-bottom:1px solid #edf0f5}
.clients-page .record-row:last-child{border-bottom:0}
.clients-page .list-labels{display:grid;grid-template-columns:minmax(220px,2.1fr) minmax(145px,1.2fr) minmax(150px,1.2fr) minmax(150px,1.3fr) 105px;gap:16px;padding:12px 25px;background:#f8fafc;color:#8390a3;font-size:10px;text-transform:uppercase;letter-spacing:.075em;font-weight:800;border-bottom:1px solid #e9eef4}
.clients-page .client-main{display:flex;gap:12px;align-items:flex-start;min-width:0}
.clients-page .client-avatar{width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-radius:12px;background:#e9f8f7;color:#078d89;font-size:13px;font-weight:800;text-transform:uppercase}
.clients-page .client-name{min-width:0}
.clients-page .client-name strong{display:block;overflow-wrap:anywhere;font-size:13px;line-height:1.45;color:#1c2a40}
.clients-page .client-name .contact-name{font-size:12px;color:#64748b;margin-top:2px}
.clients-page .client-name .client-email{display:block;max-width:100%;overflow-wrap:anywhere;color:#8a96a8;font-size:11px;margin-top:4px}
.clients-page .client-id{display:inline-block;margin-top:5px;color:#9aa4b3;font-size:10px}
.clients-page .record-cell{min-width:0;font-size:12px;color:#35445c;line-height:1.55;overflow-wrap:anywhere}
.clients-page .cell-caption{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9aa5b5;font-weight:800;margin-bottom:5px}
.clients-page .pill{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:5px 9px;border-radius:7px;font-size:10px;line-height:1.25;font-weight:800;white-space:normal}
.clients-page .pill-category{background:#edf5ff;color:#3568b8}.clients-page .pill-current{background:#e7f8ef;color:#168253}.clients-page .pill-old{background:#fff4e5;color:#a46614}.clients-page .pill-left{background:#fff0f0;color:#bd4747}.clients-page .pill-hot{background:#fff0ed;color:#c64c39}.clients-page .pill-cold{background:#edf5ff;color:#3568b8}.clients-page .pill-dead{background:#eef0f3;color:#697586}
.clients-page .label-line{margin-top:7px}
.clients-page .stage-name{font-weight:700;color:#3b4a61}
.clients-page .owner-name{font-weight:700;color:#35445c}
.clients-page .follow-date{display:block;color:#7b8799;font-size:11px;margin-top:4px}
.clients-page .view-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 11px;border:1px solid #d7e1e9;border-radius:9px;background:#fff;color:#176f70;font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s}
.clients-page .view-btn:hover{background:#edfbfa;border-color:#9bd9d6}
.clients-page .empty-state{text-align:center;padding:54px 20px;color:#7c899d}
.clients-page .empty-icon{display:flex;align-items:center;justify-content:center;width:48px;height:48px;margin:0 auto 12px;border-radius:14px;background:#f0f6f8;color:#78909c;font-size:22px}
.clients-page .empty-state strong{display:block;color:#35445c;margin-bottom:5px}
.clients-page .empty-state p{font-size:12px;margin:0}
@media(max-width:1200px){.clients-page .record-row,.clients-page .list-labels{grid-template-columns:minmax(200px,1.7fr) minmax(130px,1fr) minmax(130px,1fr) 100px}.clients-page .list-labels span:nth-child(4),.clients-page .record-row .owner-follow{display:none}.clients-page .filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.clients-page .clients-hero{align-items:flex-start;flex-direction:column;padding:21px}.clients-page .clients-hero-actions{width:100%}.clients-page .clients-hero-actions .btn{flex:1}.clients-page .client-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.clients-page .client-stat{padding:14px}.clients-page .stat-number{font-size:25px}.clients-page .filter-grid{grid-template-columns:1fr}.clients-page .filter-buttons .btn{flex:1}.clients-page .records-heading{padding:17px}.clients-page .list-labels{display:none}.clients-page .records-list{padding:0 12px}.clients-page .record-row,.clients-page .record-row{grid-template-columns:minmax(0,1fr) auto;gap:11px;padding:16px 4px}.clients-page .record-row .classification,.clients-page .record-row .pipeline-cell,.clients-page .record-row .owner-follow{grid-column:1/-1;display:block}.clients-page .record-row .pipeline-cell,.clients-page .record-row .owner-follow{display:flex;justify-content:space-between;gap:10px}.clients-page .record-row .pipeline-cell .cell-caption,.clients-page .record-row .owner-follow .cell-caption{margin:0;min-width:80px}.clients-page .record-row .view-cell{grid-column:2;grid-row:1;align-self:start}.clients-page .record-row .client-main{grid-column:1;grid-row:1}.clients-page .record-row .classification{padding-left:52px}.clients-page .record-row .classification .cell-caption{display:none}}
</style>
<div class="clients-page">
    <section class="clients-hero">
        <div>
            <p class="eyebrow">Relationship management</p>
            <h2>Your client directory</h2>
            <p>Keep client relationships organised, track lead temperature, and jump straight into each record’s activity, documents, meetings and notes.</p>
        </div>
        <div class="clients-hero-actions">
            <a class="btn btn-light" href="leads.php">View all leads</a>
            <a class="btn btn-primary" href="create_lead.php">＋ Add lead</a>
        </div>
    </section>

    <section class="client-stats" aria-label="Client category totals">
    <?php foreach ($categories as $cat):
        $isActive = $category === $cat;
        $queryArgs = array_filter(['client_category' => $isActive ? '' : $cat, 'client_label' => $label, 'q' => $q], static fn($v) => $v !== '');
    ?>
        <a class="client-stat <?= $isActive ? 'is-active' : '' ?>" href="clients.php<?= $queryArgs ? '?' . e(http_build_query($queryArgs)) : '' ?>">
            <div class="stat-top"><span><?= e($cat) ?></span><span class="stat-dot"></span></div>
            <div class="stat-number"><?= (int)$stats[$cat] ?></div>
            <div class="stat-foot"><?= $isActive ? 'Click to clear category filter' : 'Records in this category' ?></div>
        </a>
    <?php endforeach; ?>
    </section>

    <section class="client-filter">
        <div class="filter-heading"><h3>Find a client</h3><span>Search and narrow your directory</span></div>
        <form method="get" class="filter-grid">
            <label>Search
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Company, contact name, email or phone">
            </label>
            <label>Client category
                <select name="client_category"><option value="">All categories</option><?php foreach ($categories as $v): ?><option value="<?= e($v) ?>" <?= $category === $v ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            </label>
            <label>Client label
                <select name="client_label"><option value="">All labels</option><?php foreach ($labels as $v): ?><option value="<?= e($v) ?>" <?= $label === $v ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            </label>
            <div class="filter-buttons"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-light" href="clients.php">Reset</a></div>
        </form>
    </section>

    <section class="records-panel">
        <div class="records-heading">
            <div><h3 class="records-title">Clients & leads <span class="result-count"><?= count($clients) ?></span></h3><p class="records-subtitle">Each record opens the full lead profile and activity history.</p></div>
        </div>
        <div class="list-labels"><span>Company / contact</span><span>Relationship</span><span>Pipeline stage</span><span>Owner / follow-up</span><span> </span></div>
        <div class="records-list">
        <?php if (!$clients): ?>
            <div class="empty-state"><div class="empty-icon">⌕</div><strong>No matching records</strong><p>Try a different search or reset the filters to see all accessible clients and leads.</p></div>
        <?php else: foreach ($clients as $c):
            $companyName = $c['company'] ?: ($c['name'] ?: 'Unnamed client');
            $initials = strtoupper(substr(trim($companyName), 0, 1));
            $catValue = $c['client_category'] ?: 'Prospect';
            $labelValue = $c['client_label'] ?: 'Cold';
            $catClass = $catValue === 'Current Client' ? 'pill-current' : ($catValue === 'Old Client' ? 'pill-old' : ($catValue === 'Client Left' ? 'pill-left' : 'pill-category'));
            $labelClass = $labelValue === 'Hot' ? 'pill-hot' : ($labelValue === 'Dead' ? 'pill-dead' : 'pill-cold');
        ?>
            <article class="record-row">
                <div class="client-main">
                    <div class="client-avatar"><?= e($initials ?: 'C') ?></div>
                    <div class="client-name">
                        <strong><?= e($companyName) ?></strong>
                        <div class="contact-name"><?= e($c['name'] ?: 'Contact not added') ?></div>
                        <span class="client-email"><?= e($c['email'] ?: ($c['contact'] ?: 'No email or phone')) ?></span>
                        <span class="client-id">CLIENT ID #<?= (int)$c['id'] ?></span>
                    </div>
                </div>
                <div class="record-cell classification">
                    <span class="cell-caption">Relationship</span>
                    <span class="pill <?= e($catClass) ?>"><?= e($catValue) ?></span>
                    <div class="label-line"><span class="pill <?= e($labelClass) ?>"><?= e($labelValue) ?></span></div>
                </div>
                <div class="record-cell pipeline-cell">
                    <span class="cell-caption">Pipeline stage</span><span class="stage-name"><?= e($c['stage'] ?: 'New Lead') ?></span>
                    <span class="follow-date">Priority: <?= e($c['priority'] ?: 'Medium') ?></span>
                </div>
                <div class="record-cell owner-follow">
                    <span class="cell-caption">Owner / follow-up</span><span class="owner-name"><?= e($c['assignee'] ?: 'Unassigned') ?></span>
                    <span class="follow-date"><?= $c['follow_up_date'] ? 'Next follow-up · ' . e($c['follow_up_date']) : 'No follow-up scheduled' ?></span>
                </div>
                <div class="view-cell"><a class="view-btn" href="lead.php?id=<?= (int)$c['id'] ?>">View profile <span aria-hidden="true">→</span></a></div>
            </article>
        <?php endforeach; endif; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
