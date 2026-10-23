<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_sales_access();

$user = current_user();
if (!can_manage_sales()) {
    http_response_code(403);
    exit('Read-only access. QC members cannot import leads.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
verify_csrf();

function import_fail(string $message): never { flash('error', $message); redirect('leads.php'); }
function normalize_header(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[\s\-]+/', '_', $s);
    return preg_replace('/[^a-z0-9_]/', '', $s);
}
function cell_string($value): string {
    if ($value === null) return '';
    return trim((string)$value);
}
function read_xlsx_rows(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('This server cannot read .xlsx files because the PHP ZipArchive extension is unavailable. Please use the CSV template instead.');
    if (!function_exists('simplexml_load_string')) throw new RuntimeException('This server cannot read .xlsx files because SimpleXML is unavailable. Please use the CSV template instead.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('The Excel file could not be opened. Save it as .xlsx or upload a CSV file.');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($sx) foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) $text = (string)$si->t;
            else foreach ($si->r as $run) $text .= (string)$run->t;
            $shared[] = $text;
        }
    }
    $sheetName = 'xl/worksheets/sheet1.xml';
    $sheetXml = $zip->getFromName($sheetName);
    if ($sheetXml === false) { $zip->close(); throw new RuntimeException('The Excel workbook has no readable first worksheet.'); }
    $sx = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$sx) { $zip->close(); throw new RuntimeException('The Excel worksheet is invalid.'); }
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^[A-Z]+/', $ref, $m);
            $letters = $m[0] ?? 'A'; $col = 0;
            foreach (str_split($letters) as $ch) $col = $col * 26 + ord($ch) - 64;
            $col--;
            $type = (string)$cell['t'];
            $val = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's') $val = $shared[(int)$val] ?? '';
            elseif ($type === 'inlineStr') $val = isset($cell->is->t) ? (string)$cell->is->t : '';
            $line[$col] = $val;
        }
        if ($line) { ksort($line); $max = max(array_keys($line)); $dense = array_fill(0, $max + 1, ''); foreach ($line as $i=>$v) $dense[$i] = $v; $rows[] = $dense; }
    }
    $zip->close();
    return $rows;
}

if (empty($_FILES['leads_file']) || $_FILES['leads_file']['error'] !== UPLOAD_ERR_OK) import_fail('Please choose a valid CSV or Excel (.xlsx) file.');
$file = $_FILES['leads_file'];
if ($file['size'] > 5 * 1024 * 1024) import_fail('The file is too large. Maximum file size is 5 MB.');
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
try {
    if ($ext === 'csv') {
        $fh = fopen($file['tmp_name'], 'rb');
        if (!$fh) throw new RuntimeException('Could not read the uploaded CSV file.');
        $rows = [];
        while (($row = fgetcsv($fh, 0, ',')) !== false) $rows[] = $row;
        fclose($fh);
        if (isset($rows[0][0])) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
    } elseif ($ext === 'xlsx') {
        $rows = read_xlsx_rows($file['tmp_name']);
    } else {
        import_fail('Unsupported file type. Upload a .csv or modern Excel .xlsx file. Legacy .xls files are not supported; save them as .xlsx or CSV first.');
    }
} catch (Throwable $e) { import_fail($e->getMessage()); }
if (count($rows) < 2) import_fail('The file has no lead rows. Keep the header row and add at least one lead.');
if (count($rows) > 5001) import_fail('A maximum of 5,000 leads can be imported at once. Split larger files into smaller files.');

$headers = array_map(fn($v)=>normalize_header(cell_string($v)), array_shift($rows));
$aliases = [
    'name'=>'name','lead_name'=>'name','contact_name'=>'name',
    'email'=>'email','email_address'=>'email',
    'contact'=>'contact','phone'=>'contact','phone_number'=>'contact','mobile'=>'contact',
    'company'=>'company','company_name'=>'company','message'=>'message','description'=>'message',
    'notes'=>'notes','priority'=>'priority','source'=>'source','lead_source'=>'source',
    'stage'=>'stage','pipeline_stage'=>'stage','follow_up_date'=>'follow_up_date','followup_date'=>'follow_up_date',
    'assigned_username'=>'assigned_username','assigned_to'=>'assigned_username','assignee'=>'assigned_username'
];
$map = [];
foreach ($headers as $idx=>$h) if (isset($aliases[$h])) $map[$aliases[$h]] = $idx;
if (!isset($map['name']) && !isset($map['company']) && !isset($map['email']) && !isset($map['contact'])) import_fail('Missing required header row. Download the CSV template and keep its column names.');

$stageRows = pipeline_stages($pdo, true); $sourceRows = lead_sources($pdo, true);
$stageMap=[]; foreach($stageRows as $x) $stageMap[strtolower(trim($x['name']))]=(int)$x['id'];
$sourceMap=[]; foreach($sourceRows as $x) $sourceMap[strtolower(trim($x['name']))]=(int)$x['id'];
$defaultStage = $stageMap['new lead'] ?? (count($stageRows) ? (int)$stageRows[0]['id'] : null);
$defaultSource = $sourceMap['google / website'] ?? (count($sourceRows) ? (int)$sourceRows[0]['id'] : null);
$usersStmt=$pdo->query('SELECT id, username FROM users'); $userMap=[]; foreach($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) $userMap[strtolower($u['username'])]=(int)$u['id'];

$insert = $pdo->prepare('INSERT INTO leads (name,email,contact,company,message,status,priority,assigned_to,created_by,follow_up_date,notes,source_id,stage_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$added=0; $skipped=0; $errors=[]; $logEntries=[];
try {
    $pdo->beginTransaction();
    foreach ($rows as $rowNum=>$row) {
        $lineNo=$rowNum+2; $get=function(string $key) use($map,$row): string { return isset($map[$key]) ? cell_string($row[$map[$key]] ?? '') : ''; };
        $name=$get('name'); $email=$get('email'); $contact=$get('contact'); $company=$get('company');
        if ($name==='' && $email==='' && $contact==='' && $company==='') { $skipped++; $logEntries[]=['row'=>$lineNo,'status'=>'Skipped','message'=>'Row was empty; no lead fields were provided.']; continue; }
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { $message="Invalid email '{$email}'; row skipped."; $errors[]="Row {$lineNo}: {$message}"; $logEntries[]=['row'=>$lineNo,'status'=>'Skipped','lead'=>$name ?: $company ?: '(unnamed lead)','message'=>$message]; $skipped++; continue; }
        if (strlen($name)>100 || strlen($email)>150 || strlen($contact)>30 || strlen($company)>150) { $message='A field exceeds its maximum allowed length; row skipped.'; $errors[]="Row {$lineNo}: {$message}"; $logEntries[]=['row'=>$lineNo,'status'=>'Skipped','lead'=>$name ?: $company ?: '(unnamed lead)','message'=>$message]; $skipped++; continue; }
        $priority=ucfirst(strtolower($get('priority'))); if (!in_array($priority,['Low','Medium','High'],true)) $priority='Medium';
        $rowNotes=[]; $sourceText=$get('source'); $sourceId=$sourceText!=='' ? ($sourceMap[strtolower($sourceText)] ?? null) : $defaultSource;
        if ($sourceText!=='' && $sourceId===null) { $rowNotes[]="Source '{$sourceText}' was not found; default source used."; $sourceId=$defaultSource; }
        $stageText=$get('stage'); $stageId=$stageText!=='' ? ($stageMap[strtolower($stageText)] ?? null) : $defaultStage;
        if ($stageText!=='' && $stageId===null) { $rowNotes[]="Stage '{$stageText}' was not found; default stage used."; $stageId=$defaultStage; }
        $follow=$get('follow_up_date');
        if ($follow!=='') { $dt=DateTime::createFromFormat('!Y-m-d',$follow); if (!$dt || $dt->format('Y-m-d')!==$follow) { $rowNotes[]='Invalid follow-up date (use YYYY-MM-DD); date left blank.'; $follow=null; } }
        else $follow=null;
        $assignedText=$get('assigned_username');
        if ($user['role']==='staff') $assignedId=(int)$user['id'];
        elseif ($assignedText!=='') { $assignedId=$userMap[strtolower($assignedText)] ?? null; if ($assignedId===null) $rowNotes[]="Assigned username '{$assignedText}' not found; lead left unassigned."; }
        else $assignedId=null;
        $insert->execute([
            $name!=='' ? $name : null,
            $email!=='' ? $email : null,
            $contact!=='' ? $contact : null,
            $company!=='' ? $company : null,
            $get('message')!=='' ? $get('message') : null,
            'New', $priority, $assignedId, (int)$user['id'], $follow,
            $get('notes')!=='' ? $get('notes') : null, $sourceId, $stageId
        ]);
        $added++;
        $logEntries[]=['row'=>$lineNo,'status'=>'Imported','lead'=>$name ?: $company ?: '(unnamed lead)','message'=>$rowNotes ? implode(' ', $rowNotes) : 'Lead imported successfully.'];
        if ($rowNotes) foreach ($rowNotes as $note) $errors[]="Row {$lineNo}: {$note}";
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('CRM lead import failed: '.$e->getMessage());
    import_fail('Import failed due to a database error. No rows were imported. Check that your CRM database is fully migrated, then try again.');
}
$_SESSION['lead_import_log'] = [
    'created_at' => date('Y-m-d H:i:s'),
    'filename' => basename((string)$file['name']),
    'added' => $added,
    'skipped' => $skipped,
    'entries' => $logEntries,
];
if ($added > 0) {
    $msg = $skipped > 0
        ? "{$added} lead(s) imported successfully. {$skipped} row(s) need attention."
        : "{$added} lead(s) imported successfully.";
    flash('success', $msg);
} else {
    flash('error', 'No leads were imported. Check the upload log for details.');
}
redirect('leads.php');
