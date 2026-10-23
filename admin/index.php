<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';

require_sales_access();

$page_title='B2B Sales Dashboard';
$current_page='dashboard';

$u=current_user();

$baseWhere='';
$baseParams=[];

if ($u['role']==='staff') {
    $baseWhere=' WHERE (l.assigned_to=? OR l.created_by=?)';
    $baseParams=[$u['id'],$u['id']];
}

$stages=pipeline_stages($pdo);
$sources=lead_sources($pdo);

$countLead=function() use ($pdo,$baseWhere,$baseParams): int {
    $sql='SELECT COUNT(*) FROM leads l'.($baseWhere ? $baseWhere : '');
    $q=$pdo->prepare($sql);
    $q->execute($baseParams);
    return (int)$q->fetchColumn();
};

$total=$countLead();

$sc=$pdo->prepare(
    'SELECT l.stage_id,COUNT(*) c
     FROM leads l'.
    ($baseWhere ? $baseWhere : '').
    ' GROUP BY l.stage_id'
);
$sc->execute($baseParams);

$stageCounts=[];
foreach($sc->fetchAll() as $r){
    $stageCounts[(int)$r['stage_id']]=(int)$r['c'];
}

$ss=$pdo->prepare(
    'SELECT l.source_id,COUNT(*) c
     FROM leads l'.
    ($baseWhere ? $baseWhere : '').
    ' GROUP BY l.source_id'
);
$ss->execute($baseParams);

$sourceCounts=[];
foreach($ss->fetchAll() as $r){
    $sourceCounts[(int)$r['source_id']]=(int)$r['c'];
}

$meetParams=[];
$meetWhere=" WHERE m.meeting_date=CURDATE() AND m.status='Scheduled'";

if($u['role']==='staff'){
    $meetWhere.=' AND (l.assigned_to=? OR l.created_by=?)';
    $meetParams=[$u['id'],$u['id']];
}

$ms=$pdo->prepare(
    "SELECT COUNT(*)
     FROM meetings m
     JOIN leads l ON l.id=m.lead_id
     {$meetWhere}"
);
$ms->execute($meetParams);
$meetingsToday=(int)$ms->fetchColumn();

$upParams=[];
$upWhere=' WHERE m.meeting_date>=CURDATE()';

if($u['role']==='staff'){
    $upWhere.=' AND (l.assigned_to=? OR l.created_by=?)';
    $upParams=[$u['id'],$u['id']];
}

$q=$pdo->prepare(
    "SELECT
        m.*,
        l.name AS lead_name,
        l.company
     FROM meetings m
     JOIN leads l ON l.id=m.lead_id
     {$upWhere}
     ORDER BY m.meeting_date,m.start_time
     LIMIT 6"
);
$q->execute($upParams);
$upcoming=$q->fetchAll();

$q=$pdo->prepare(
    "SELECT
        l.id,
        l.name,
        l.company,
        l.created_at,
        s.name stage,
        ls.name source,
        u.username assignee
     FROM leads l
     LEFT JOIN pipeline_stages s ON s.id=l.stage_id
     LEFT JOIN lead_sources ls ON ls.id=l.source_id
     LEFT JOIN users u ON u.id=l.assigned_to".
    ($baseWhere ? $baseWhere : '').
    " ORDER BY l.created_at DESC
      LIMIT 8"
);
$q->execute($baseParams);
$recent=$q->fetchAll();

require __DIR__.'/../includes/header.php';
?>

<div
    class="section-head"
    style="margin-bottom:18px"
>
    <div>
        <div class="eyebrow">Sales workspace</div>
        <h2 style="margin:0">Overview</h2>
    </div>

    <?php if($u['role']==='admin'): ?>
        <div class="actions">
            <a class="btn btn-light" href="sync_email.php">
                Sync Lead Emails
            </a>

            <a class="btn btn-light" href="google_settings.php">
                Google Calendar
            </a>
        </div>
    <?php endif; ?>
</div>


<div class="stats">

    <div class="stat-card">
        <div class="stat-label">Total Leads</div>
        <div class="stat-number"><?= $total ?></div>
        <div class="stat-meta">Visible to your role</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Meetings Today</div>
        <div class="stat-number"><?= $meetingsToday ?></div>
        <div class="stat-meta">Scheduled today</div>
    </div>

    <?php
    $quickStages=[
        'New Lead',
        'Meetings Scheduled',
        'Under Negotiation',
        'Converted/Won'
    ];
    ?>

    <?php foreach($quickStages as $stageName): ?>
        <?php
        $stageId=0;
        foreach($stages as $st){
            if($st['name']===$stageName){
                $stageId=(int)$st['id'];
                break;
            }
        }
        ?>
        <div class="stat-card">
            <div class="stat-label"><?= e($stageName) ?></div>
            <div class="stat-number">
                <?= $stageId ? ($stageCounts[$stageId]??0) : 0 ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>


<div class="dashboard-grid">

    <div class="section-card">

        <div class="section-head">
            <h2>Sales Pipeline</h2>

            <a
                class="btn btn-primary btn-sm"
                href="pipeline.php"
            >
                Open Pipeline
            </a>
        </div>

        <div class="pipeline-list">

            <?php foreach($stages as $s): ?>

                <div class="pipeline-row">

                    <div>
                        <strong>
                            <?= e($s['name']) ?>
                        </strong>

                        <span class="muted">
                            Stage <?= (int)$s['sequence'] ?>
                        </span>
                    </div>

                    <div class="pipeline-count">
                        <?= $stageCounts[(int)$s['id']]??0 ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>


    <div class="section-card">

        <div class="section-head">

            <h2>Lead Sources</h2>

            <?php if($u['role']==='admin'): ?>
                <a
                    class="btn btn-light btn-sm"
                    href="sources.php"
                >
                    Manage
                </a>
            <?php endif; ?>

        </div>

        <div class="pipeline-list">

            <?php foreach($sources as $s): ?>

                <div class="pipeline-row">

                    <strong>
                        <?= e($s['name']) ?>
                    </strong>

                    <div class="pipeline-count">
                        <?= $sourceCounts[(int)$s['id']]??0 ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</div>


<div
    class="section-card"
    style="margin-top:18px"
>

    <div class="section-head">

        <h2>Upcoming Meetings</h2>

        <a
            class="btn btn-light btn-sm"
            href="meetings.php"
        >
            View Meetings
        </a>

    </div>

    <div class="table-wrap">

        <table class="table">

            <thead>
                <tr>
                    <th>Date</th>
                    <th>Meeting</th>
                    <th>Lead</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

            <?php if(!$upcoming): ?>

                <tr>
                    <td colspan="5" class="empty">
                        No upcoming meetings.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach($upcoming as $m): ?>

                    <tr>

                        <td>
                            <?= e(format_day($m['meeting_date'])) ?>
                        </td>

                        <td>
                            <?= e($m['title']) ?>
                        </td>

                        <td>
                            <a href="lead.php?id=<?= (int)$m['lead_id'] ?>">
                                <?= e($m['company']?:$m['lead_name']) ?>
                            </a>
                        </td>

                        <td>
                            <?= e(date(
                                'h:i A',
                                strtotime($m['start_time'])
                            )) ?>
                        </td>

                        <td>
                            <span class="badge badge-blue">
                                <?= e($m['status']) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div
    class="section-card"
    style="margin-top:18px"
>

    <div class="section-head">

        <h2>Recent Leads</h2>

        <a
            class="btn btn-primary btn-sm"
            href="leads.php"
        >
            View All Leads
        </a>

    </div>

    <div class="table-wrap">

        <table class="table">

            <thead>
                <tr>
                    <th>#</th>
                    <th>Lead</th>
                    <th>Source</th>
                    <th>Stage</th>
                    <th>Assigned</th>
                    <th>Created</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($recent as $lead): ?>

                <tr>

                    <td><?= (int)$lead['id'] ?></td>

                    <td>
                        <a href="lead.php?id=<?= (int)$lead['id'] ?>">
                            <strong>
                                <?= e(
                                    $lead['company']
                                    ?: ($lead['name']?:'Unnamed')
                                ) ?>
                            </strong>
                        </a>

                        <div class="muted">
                            <?= e($lead['name']) ?>
                        </div>
                    </td>

                    <td>
                        <?= e(
                            $lead['source']
                            ?: 'Google / Website'
                        ) ?>
                    </td>

                    <td>
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
                    </td>

                    <td>
                        <?= e(
                            $lead['assignee']
                            ?: 'Unassigned'
                        ) ?>
                    </td>

                    <td class="muted">
                        <?= e(
                            format_day(
                                $lead['created_at']
                            )
                        ) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require __DIR__.'/../includes/footer.php'; ?>
