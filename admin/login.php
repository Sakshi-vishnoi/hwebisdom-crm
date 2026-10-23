<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_guest();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = (int)$user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'] ?? 'admin';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            redirect('index.php');
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Login | Webisdom CRM</title><link rel="stylesheet" href="../assets/css/crm.css"></head><body class="login-page"><div class="login-card"><div class="login-logo"><div class="brand-mark">W</div><h1>WEBISDOM CRM</h1><p>Sign in to manage website leads</p></div><?php if($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="field"><label>Username</label><input class="form-control" type="text" name="username" autocomplete="username" required autofocus></div><div class="field"><label>Password</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div><button class="btn btn-primary" type="submit">Sign In</button></form></div></body></html>
