<?php
// =========================================================
// Profile
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';

$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Valid username and email required.'; $flashType = 'danger';
        } else {
            // Check email not taken by another user
            $dup = $pdo->prepare('SELECT id FROM users WHERE email=? AND id != ?');
            $dup->execute([$email, $currentUser['id']]);
            if ($dup->fetch()) {
                $flash = 'That email is already used by another account.'; $flashType = 'danger';
            } else {
                $pdo->prepare('UPDATE users SET username=?,email=? WHERE id=?')
                    ->execute([$username, $email, $currentUser['id']]);
                $flash = 'Profile updated.';
                // Refresh user
                $stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmtUser->execute([$currentUser['id']]);
                $currentUser = $stmtUser->fetch();
            }
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password']     ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $currentUser['password_hash'])) {
            $flash = 'Current password is incorrect.'; $flashType = 'danger';
        } elseif (strlen($new) < 8) {
            $flash = 'New password must be at least 8 characters.'; $flashType = 'danger';
        } elseif ($new !== $confirm) {
            $flash = 'New passwords do not match.'; $flashType = 'danger';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([$hash, $currentUser['id']]);
            $flash = 'Password changed successfully.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — <?= htmlspecialchars($activeSystem['name']) ?></title>
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
            <div class="page-icon"><i class="bi bi-person-circle"></i></div>
            <div>
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Manage your account settings</p>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Profile Info -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header card-header-white py-3">
                    <span class="fw-semibold"><i class="bi bi-person me-2" style="color:var(--accent, #1a6fc4)"></i>Account Information</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" name="username" class="form-control"
                                   value="<?= htmlspecialchars($currentUser['username']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($currentUser['email']) ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Role</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($currentUser['role']) ?>" disabled>
                        </div>
                        <button type="submit" class="btn btn-tb5-primary">
                            <i class="bi bi-save me-1"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header card-header-white py-3">
                    <span class="fw-semibold"><i class="bi bi-lock me-2" style="color:var(--accent, #1a6fc4)"></i>Change Password</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" required>
                            <div class="form-text">Min. 8 characters</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-key me-1"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Account details strip -->
    <div class="info-strip mt-4">
        <div class="info-strip-item">
            <span class="info-strip-label">User ID</span>
            <span class="info-strip-value">#<?= $currentUser['id'] ?></span>
        </div>
        <div class="info-strip-item">
            <span class="info-strip-label">Role</span>
            <span class="info-strip-value"><span class="badge" style="background:var(--accent-mid, #1a3a5c);"><?= htmlspecialchars($currentUser['role']) ?></span></span>
        </div>
        <div class="info-strip-item">
            <span class="info-strip-label">Member Since</span>
            <span class="info-strip-value"><?= date('F d, Y', strtotime($currentUser['created_at'])) ?></span>
        </div>
        <div class="info-strip-item">
            <span class="info-strip-label">Active System</span>
            <span class="info-strip-value"><?= htmlspecialchars($activeSystem['name']) ?></span>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
