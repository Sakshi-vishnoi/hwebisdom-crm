<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>$secure]);
    session_start();
}
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: '.$url); exit; }
function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function verify_csrf(): void { $token=$_POST['csrf_token']??''; if(!$token||!hash_equals($_SESSION['csrf_token']??'', $token)){http_response_code(419);exit('Invalid or expired request. Please go back and try again.');} }
function flash(string $key, ?string $message=null): ?string { if($message!==null){$_SESSION['flash'][$key]=$message;return null;} $msg=$_SESSION['flash'][$key]??null;unset($_SESSION['flash'][$key]);return $msg; }
function format_date(?string $date): string { if(!$date)return '-';$time=strtotime($date);return $time?date('d M Y, h:i A',$time):e($date); }
function format_day(?string $date): string { if(!$date)return '-';$time=strtotime($date);return $time?date('d M Y',$time):e($date); }
function lead_priorities(): array { return ['Low','Medium','High']; }
function pipeline_stages(PDO $pdo, bool $activeOnly=true): array { $sql='SELECT * FROM pipeline_stages'.($activeOnly?' WHERE is_active=1':'').' ORDER BY sequence,id'; return $pdo->query($sql)->fetchAll(); }
function lead_sources(PDO $pdo, bool $activeOnly=true): array { $sql='SELECT * FROM lead_sources'.($activeOnly?' WHERE is_active=1':'').' ORDER BY name'; return $pdo->query($sql)->fetchAll(); }
function stage_class(string $name): string {
    return match($name){
        'New Lead' => 'badge-blue',
        'Meetings Scheduled' => 'badge-orange',
        'Collateral Document Preparation DA Team' => 'badge-blue',
        'Collateral Document Shared with client' => 'badge-purple',
        'Under Negotiation' => 'badge-purple',
        'Converted/Won' => 'badge-green',
        'Exploratory Meeting' => 'badge-orange',
        default => 'badge-gray'
    };
}

function priority_class(string $priority): string {
    return match($priority){
        'High' => 'badge-red',
        'Medium' => 'badge-orange',
        default => 'badge-gray'
    };
}

function log_activity(PDO $pdo,int $leadId,?int $userId,string $type,string $description): void {
    $s=$pdo->prepare('INSERT INTO activities (lead_id,user_id,activity_type,description) VALUES (?,?,?,?)');
    $s->execute([$leadId,$userId,$type,$description]);
}

function current_user(): array {
    return [
        'id'=>(int)($_SESSION['admin_id']??0),
        'username'=>$_SESSION['admin_username']??'Admin',
        'role'=>$_SESSION['admin_role']??'admin'
    ];
}

function can_manage_sales(): bool {
    return in_array(current_user()['role'], ['admin','b2b_manager','staff'], true);
}

function can_view_all_leads(): bool {
    return in_array(current_user()['role'], ['admin','b2b_manager','qc'], true);
}

function can_edit_lead(PDO $pdo, array $lead): bool {
    $u=current_user();

    if (in_array($u['role'], ['admin','b2b_manager'], true)) {
        return true;
    }

    if ($u['role'] !== 'staff') {
        return false;
    }

    return (int)($lead['assigned_to']??0)===$u['id']
        || (int)($lead['created_by']??0)===$u['id'];
}

function can_close_deal(): bool {
    return can_manage_sales();
}

function role_label(string $role): string {
    return match($role){
        'admin'=>'Admin',
        'b2b_manager'=>'B2B Manager',
        'staff'=>'Sales Team',
        'qc'=>'QC Member',
        default=>ucfirst($role)
    };
}

function normalize_stage_from_legacy(string $status): string {
    return match($status){
        'New','New Lead'=>'New Lead',
        'Contacted','In Progress','Follow-up'=>'Exploratory Meeting',
        'Meeting 1','Meeting 2','Meetings Scheduled'=>'Meetings Scheduled',
        'Proposal / Documents'=>'Collateral Document Preparation DA Team',
        'Negotiation','Under Negotiation'=>'Under Negotiation',
        'Won','Converted','Converted/Won'=>'Converted/Won',
        default=>'New Lead'
    };
}

function legacy_status_for_stage(string $stageName): string {
    return match($stageName){
        'Converted/Won' => 'Converted',
        'Under Negotiation' => 'Negotiation',
        'Collateral Document Preparation DA Team' => 'Collateral Prep',
        'Collateral Document Shared with client' => 'Collateral Shared',
        default => $stageName
    };
}

function crm_stage_options(): array {
    return [
        'New Lead'=>['Email'],
        'Meetings Scheduled'=>['Rescheduled','Cancelled','Done'],
        'Collateral Document Preparation DA Team'=>['Audit PPT','Case studies','Proposal','SoW'],
        'Collateral Document Shared with client'=>['Audit PPT','Case studies','SoW'],
        'Under Negotiation'=>[],
        'Converted/Won'=>[],
        'Exploratory Meeting'=>[]
    ];
}

function is_sales_staff(): bool {
    return current_user()['role']==='staff';
}

function can_view_lead(PDO $pdo,array $lead): bool {
    $u=current_user();

    if (can_view_all_leads()) {
        return true;
    }

    if ($u['role']!=='staff') {
        return false;
    }

    return (int)($lead['assigned_to']??0)===$u['id']
        || (int)($lead['created_by']??0)===$u['id'];
}

function lead_visibility_sql(array &$params,string $alias='l'): string {
    $u=current_user();

    if (can_view_all_leads()) {
        return '';
    }

    if ($u['role']!=='staff') {
        $params[]=0;
        return " AND 1=?";
    }

    $params[]=$u['id'];
    $params[]=$u['id'];

    return " AND ($alias.assigned_to=? OR $alias.created_by=?)";
}

function assert_lead_access(PDO $pdo,int $leadId): array {
    $s=$pdo->prepare('SELECT l.* FROM leads l WHERE l.id=?');
    $s->execute([$leadId]);
    $lead=$s->fetch();

    if(!$lead){
        http_response_code(404);
        exit('Lead not found.');
    }

    if(!can_view_lead($pdo,$lead)){
        http_response_code(403);
        exit('Access denied. This lead is not assigned to you.');
    }

    return $lead;
}

function ensure_dir(string $path): bool {
    return is_dir($path) || @mkdir($path,0755,true);
}
