<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$page_title = 'Users';
$current_page = 'users';
$error = '';

$roles = [
    'admin' => 'Admin',
    'b2b_manager' => 'B2B Manager',
    'staff' => 'Sales Team',
    'qc' => 'QC Member',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $email = trim($_POST['email'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $error = 'Invalid username.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!array_key_exists($role, $roles)) {
            $error = 'Invalid role.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email.';
        } else {
            try {
                $s = $pdo->prepare(
                    'INSERT INTO users(username,password,role,email) VALUES(?,?,?,?)'
                );
                $s->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $email ?: null
                ]);

                flash('success', 'User created successfully.');
                redirect('users.php');
            } catch (PDOException $e) {
                $error = 'Username already exists or could not be created.';
            }
        }
    }

    elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? 'staff';
        $email = trim($_POST['email'] ?? '');

        if (!array_key_exists($role, $roles)) {
            $error = 'Invalid role.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email.';
        } elseif ($id === (int)$_SESSION['admin_id'] && $role !== 'admin') {
            $error = 'You cannot remove Admin role from your own account.';
        } else {
            // Never allow the last Admin to be removed.
            if ($role !== 'admin') {
                $check = $pdo->prepare(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> ?"
                );
                $check->execute([$id]);

                if ((int)$check->fetchColumn() < 1) {
                    $error = 'At least one Admin account must remain.';
                }
            }

            if (!$error) {
                $s = $pdo->prepare(
                    'UPDATE users SET role=?, email=? WHERE id=?'
                );
                $s->execute([$role, $email ?: null, $id]);

                flash('success', 'User updated successfully.');
                redirect('users.php');
            }
        }
    }

    elseif ($action === 'reset_smtp') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM user_smtp_settings WHERE user_id = ?')->execute([$id]);
        flash('success', 'User SMTP settings have been reset.');
        redirect('users.php');
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)$_SESSION['admin_id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $check = $pdo->prepare(
                'SELECT role FROM users WHERE id=?'
            );
            $check->execute([$id]);
            $userRole = $check->fetchColumn();

            if ($userRole === 'admin') {
                $adminCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM users WHERE role='admin'"
                )->fetchColumn();

                if ($adminCount <= 1) {
                    $error = 'The last Admin account cannot be deleted.';
                }
            }

            if (!$error) {
                $s = $pdo->prepare('DELETE FROM users WHERE id=?');
                $s->execute([$id]);

                flash('success', 'User deleted successfully.');
                redirect('users.php');
            }
        }
    }
}

$users = $pdo->query(
    'SELECT u.id,u.username,u.role,u.email,u.created_at,gt.google_email,
            uss.from_email AS smtp_from_email, uss.is_verified AS smtp_is_verified, uss.smtp_host
     FROM users u
     LEFT JOIN google_tokens gt ON gt.user_id=u.id
     LEFT JOIN user_smtp_settings uss ON uss.user_id=u.id
     ORDER BY
       CASE u.role
         WHEN "admin" THEN 1
         WHEN "b2b_manager" THEN 2
         WHEN "staff" THEN 3
         WHEN "qc" THEN 4
         ELSE 5
       END,
       u.username'
)->fetchAll();

require __DIR__ . '/../includes/header.php';

function user_role_label($role, $roles) {
    return $roles[$role] ?? $role;
}

function user_role_badge($role) {
    return match ($role) {
        'admin' => 'badge-purple',
        'b2b_manager' => 'badge-orange',
        'staff' => 'badge-blue',
        'qc' => 'badge-green',
        default => 'badge-gray',
    };
}

?>

<?php if ($error): ?>
    <div class="alert danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="detail-grid">

    <div class="section-card">

        <div class="section-head">
            <div>
                <h2>CRM Users</h2>
                <div class="muted">
                    Admin-only user and role management.
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Google Calendar</th>
                        <th>Outbound SMTP</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>

                        <td>
                            <strong><?= e($u['username']) ?></strong>
                        </td>

                        <td><?= e($u['email'] ?: '-') ?></td>

                        <td>
                            <span class="badge <?= e(user_role_badge($u['role'])) ?>">
                                <?= e(user_role_label($u['role'], $roles)) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($u['google_email']): ?>
                                <span class="badge badge-green">Connected</span>
                                <div class="muted">
                                    <?= e($u['google_email']) ?>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-gray">Not connected</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($u['smtp_from_email']): ?>
                                <?php if ((int)$u['smtp_is_verified'] === 1): ?>
                                    <span class="badge badge-green" style="font-size: 11px;">✓ Verified</span>
                                <?php else: ?>
                                    <span class="badge badge-orange" style="font-size: 11px;">⚠️ Unverified</span>
                                <?php endif; ?>
                                <div class="muted" style="font-size: 12px; margin-top: 2px;">
                                    <?= e($u['smtp_from_email']) ?>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-gray" style="font-size: 11px;">Not configured</span>
                            <?php endif; ?>
                        </td>

                        <td><?= e(format_day($u['created_at'])) ?></td>

                        <td>

                            <details>
                                <summary class="btn btn-light btn-sm">
                                    Edit
                                </summary>

                                <form method="post" class="inline-edit">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >

                                    <input type="hidden" name="action" value="update">

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$u['id'] ?>"
                                    >

                                    <input
                                        class="form-control"
                                        type="email"
                                        name="email"
                                        value="<?= e($u['email']) ?>"
                                        placeholder="Email"
                                    >

                                    <select class="form-control" name="role">
                                        <?php foreach ($roles as $role => $label): ?>
                                            <option
                                                value="<?= e($role) ?>"
                                                <?= $u['role'] === $role ? 'selected' : '' ?>
                                            >
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button
                                        class="btn btn-primary btn-sm"
                                        type="submit"
                                    >
                                        Save
                                    </button>

                                </form>
                            </details>

                            <?php if ($u['smtp_from_email']): ?>
                                <form
                                    method="post"
                                    style="display:inline"
                                    onsubmit="return confirm('Reset and delete SMTP credentials for user <?= e($u['username']) ?>? They will need to reconfigure their email settings.');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >
                                    <input type="hidden" name="action" value="reset_smtp">
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$u['id'] ?>"
                                    >
                                    <button
                                        class="btn btn-light btn-sm"
                                        type="submit"
                                        style="color: #b45309; border-color: #fde68a;"
                                        title="Reset user's SMTP mailbox settings"
                                    >
                                        Reset SMTP
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ((int)$u['id'] !== (int)$_SESSION['admin_id']): ?>

                                <form
                                    method="post"
                                    style="display:inline"
                                    onsubmit="return confirm('Delete this user? This cannot be undone.');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >

                                    <input type="hidden" name="action" value="delete">

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$u['id'] ?>"
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
                </tbody>

            </table>
        </div>
    </div>


    <div class="section-card form-card">

        <h2 style="margin-top:0">Add User</h2>

        <div class="muted" style="margin-bottom:18px">
            Only Admins can create, edit, or delete CRM users.
        </div>

        <form method="post">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input type="hidden" name="action" value="add">

            <div class="field">
                <label>Username</label>
                <input
                    class="form-control"
                    name="username"
                    minlength="3"
                    maxlength="50"
                    required
                >
            </div>

            <div class="field" style="margin-top:18px">
                <label>Email</label>
                <input
                    class="form-control"
                    type="email"
                    name="email"
                    placeholder="Required for meeting invitations"
                >
            </div>

            <div class="field" style="margin-top:18px">
                <label>Password</label>
                <input
                    class="form-control"
                    type="password"
                    name="password"
                    minlength="8"
                    required
                >
                <small class="muted">
                    Password is securely hashed before being stored.
                </small>
            </div>

            <div class="field" style="margin-top:18px">
                <label>Role</label>

                <select class="form-control" name="role" required>

                    <option value="staff">
                        Sales Team
                    </option>

                    <option value="b2b_manager">
                        B2B Manager
                    </option>

                    <option value="qc">
                        QC Member
                    </option>

                    <option value="admin">
                        Admin
                    </option>

                </select>
            </div>


            <div class="section-card" style="margin-top:20px;padding:15px">

                <strong>Role Responsibilities</strong>

                <ul class="muted" style="margin:10px 0 0 18px">

                    <li>
                        <strong>Admin:</strong>
                        Full CRM and user administration.
                    </li>

                    <li>
                        <strong>B2B Manager:</strong>
                        Sees and manages all leads, but cannot manage users or CRM administration.
                    </li>

                    <li>
                        <strong>Sales Team:</strong>
                        Works on assigned leads only and cannot see other sales users' leads.
                    </li>

                    <li>
                        <strong>QC Member:</strong>
                        Read-only monitoring access to leads and sales activity.
                    </li>

                </ul>

            </div>


            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    Create User
                </button>
            </div>

        </form>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
