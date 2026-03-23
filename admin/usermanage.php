<?php
// =========================================================
// User Management
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $password = $_POST['password'] ?? '';
        $role     = 'admin';

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $flash = 'All fields required. Password min 8 chars.'; $flashType = 'danger';
        } else {
            // Check duplicate email
            $dup = $pdo->prepare('SELECT id FROM users WHERE email=?');
            $dup->execute([$email]);
            if ($dup->fetch()) {
                $flash = 'Email already registered.'; $flashType = 'danger';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $pdo->prepare('INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,?)')
                    ->execute([$username, $email, $hash, $role]);
                $flash = 'User added.';
            }
        }
    }

    if ($action === 'edit_user') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $newPass  = $_POST['new_password'] ?? '';

        if ($uid && $username !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check duplicate email for another user
            $dup = $pdo->prepare('SELECT id FROM users WHERE email=? AND id != ?');
            $dup->execute([$email, $uid]);
            if ($dup->fetch()) {
                $flash = 'Email already in use.'; $flashType = 'danger';
            } else {
                if ($newPass !== '' && strlen($newPass) >= 8) {
                    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]);
                    $pdo->prepare('UPDATE users SET username=?,email=?,password_hash=? WHERE id=?')
                        ->execute([$username, $email, $hash, $uid]);
                } else {
                    $pdo->prepare('UPDATE users SET username=?,email=? WHERE id=?')
                        ->execute([$username, $email, $uid]);
                }
                $flash = 'User updated.';
            }
        }
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        // Prevent self-delete
        if ($uid && $uid !== (int)$_SESSION['user_id']) {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $flash = 'User deleted.'; $flashType = 'warning';
        } else {
            $flash = 'You cannot delete your own account.'; $flashType = 'danger';
        }
    }

    header('Location: usermanage.php'); exit;
}

$users = $pdo->query('SELECT id,username,email,role,created_at FROM users ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — <?= htmlspecialchars($activeSystem['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/header.css">
    <link rel="stylesheet" href="../assets/sidebar.css">
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body class="<?= $themeClass ?>">
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flashType ?> alert-dismissible alert-float fade show">
            <?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="bi bi-people"></i></div>
            <div>
                <h1 class="page-title">User Management</h1>
                <p class="page-subtitle"><?= count($users) ?> user(s) registered</p>
            </div>
        </div>
        <button class="btn btn-tb5-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-1"></i>Add User
        </button>
    </div>

    <div class="table-card">
        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $i => $u): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($u['role']) ?></span></td>
                            <td><?= date('m/d/Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-action btn-outline-primary"
                                    onclick="openEditUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                                    <input type="hidden" name="action"  value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-action btn-outline-danger">
                                        <i class="bi bi-trash"></i>
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
    </div>
</main>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-white"><i class="bi bi-person-plus me-2"></i>Add User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" minlength="8" required>
                        <div class="form-text">Min. 8 characters</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-white"><i class="bi bi-pencil me-2"></i>Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"  value="edit_user">
                <input type="hidden" name="user_id" id="edit_uid">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" id="edit_uname" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" id="edit_uemail" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <small class="text-muted">(leave blank to keep)</small></label>
                        <input type="password" name="new_password" class="form-control" minlength="8">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function openEditUser(u) {
    document.getElementById('edit_uid').value    = u.id;
    document.getElementById('edit_uname').value  = u.username;
    document.getElementById('edit_uemail').value = u.email;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
</script>
</body>
</html>
