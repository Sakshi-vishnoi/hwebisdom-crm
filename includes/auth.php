<?php
require_once __DIR__.'/functions.php';

function require_login(): void {
    if(empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
}

function require_guest(): void {
    if(!empty($_SESSION['admin_logged_in'])) {
        redirect('index.php');
    }
}

function require_admin(): void {
    require_login();
    if(current_user()['role']!=='admin'){
        http_response_code(403);
        exit('Access denied. Admin access is required.');
    }
}

function require_sales_access(): void {
    require_login();
    if(!in_array(current_user()['role'],['admin','b2b_manager','staff','qc'],true)){
        http_response_code(403);
        exit('Access denied.');
    }
}

function require_sales_write(): void {
    require_login();
    if(!can_manage_sales()){
        http_response_code(403);
        exit('Read-only access. You do not have permission to modify sales records.');
    }
}
