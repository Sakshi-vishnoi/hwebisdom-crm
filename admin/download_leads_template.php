<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!can_manage_sales()) { http_response_code(403); exit('Read-only access.'); }
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="crm_leads_template.csv"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['name','email','contact','company','message','notes','priority','source','stage','follow_up_date','assigned_username']);
fputcsv($out, ['Aarav Sharma','aarav@example.com','9876543210','Example Company','Interested in our services','Call next week','Medium','Google / Website','New Lead','2026-10-20','']);
fclose($out);
exit;
