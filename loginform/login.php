<?php
// =========================================================
// Login Page
// =========================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in?
if (!empty($_SESSION['user_id'])) {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = preg_replace('#/(?:index\.php|loginform/login\.php)$#', '', $scriptName);
    $basePath = rtrim((string)($basePath ?? ''), '/');
    $dashboardUrl = ($basePath === '' ? '' : $basePath) . '/admin/dashboard.php';
    header('Location: ' . $dashboardUrl);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function appUrl(string $path = ''): string {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = preg_replace('#/(?:index\.php|loginform/login\.php)$#', '', $scriptName);
    $basePath = rtrim((string)($basePath ?? ''), '/');
    $suffix = ltrim($path, '/');

    if ($basePath === '') {
        return '/' . $suffix;
    }

    return $basePath . '/' . $suffix;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email',    FILTER_SANITIZE_EMAIL) ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']           = $user['id'];
            $_SESSION['active_system_id']  = 1;
            header('Location: ' . appUrl('admin/dashboard.php'));
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — TB5 Monitoring System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            display: flex;
            overflow: hidden;
        }

        /* ---- Left panel — animated gradient bg ---- */
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #0a1628 0%, #112240 40%, #1a5fb4 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            padding: 40px;
        }

        .login-left::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(53,132,228,0.15) 0%, transparent 70%);
            top: -100px;
            right: -100px;
            border-radius: 50%;
            animation: pulse 6s ease-in-out infinite alternate;
        }

        .login-left::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(192,57,43,0.12) 0%, transparent 70%);
            bottom: -80px;
            left: -80px;
            border-radius: 50%;
            animation: pulse 8s ease-in-out infinite alternate-reverse;
        }

        @keyframes pulse {
            from { transform: scale(1); opacity: 0.6; }
            to   { transform: scale(1.2); opacity: 1; }
        }

        .login-left .brand-section {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .logo-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 28px;
        }

        .logo-circle {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(12px);
            border: 2px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .logo-circle:hover {
            transform: scale(1.08);
            box-shadow: 0 0 30px rgba(53,132,228,0.3);
        }
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-divider {
            width: 2px;
            height: 50px;
            background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.25), transparent);
            border-radius: 2px;
        }

        .brand-title {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin-bottom: 8px;
        }

        .brand-sub {
            color: rgba(255,255,255,0.45);
            font-size: 0.85rem;
            font-weight: 400;
            letter-spacing: 0.06em;
        }

        /* ---- Right panel — form ---- */
        .login-right {
            width: 480px;
            min-width: 380px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 44px;
            background: #fff;
            position: relative;
        }

        .login-right::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, #1a5fb4, #c0392b);
        }

        .form-wrapper {
            width: 100%;
            max-width: 360px;
        }

        .welcome-text {
            font-size: 1.55rem;
            font-weight: 800;
            color: #1a2332;
            margin-bottom: 4px;
        }

        .welcome-sub {
            color: #7a8a9c;
            font-size: 0.88rem;
            margin-bottom: 30px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #3a4a5c;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
        }

        .input-wrap {
            position: relative;
            margin-bottom: 20px;
        }

        .input-wrap .form-control {
            padding: 12px 16px 12px 46px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.92rem;
            transition: all 0.25s;
            background: #f7f9fc;
        }

        .input-wrap .form-control:focus {
            border-color: #3584e4;
            box-shadow: 0 0 0 4px rgba(53,132,228,0.10);
            background: #fff;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            color: #8899aa;
            font-size: 1.05rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrap:focus-within .input-icon {
            color: #3584e4;
        }

        .toggle-pwd {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8899aa;
            cursor: pointer;
            font-size: 1.05rem;
            padding: 0;
            transition: color 0.2s;
        }
        .toggle-pwd:hover { color: #3584e4; }

        .btn-login {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            color: #fff;
            background: linear-gradient(135deg, #1a3a5c 0%, #1a5fb4 100%);
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 16px rgba(26,95,180,0.25);
            letter-spacing: 0.02em;
            margin-top: 6px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(26,95,180,0.35);
            background: linear-gradient(135deg, #112240 0%, #2470c6 100%);
            color: #fff;
        }
        .btn-login:active {
            transform: translateY(0);
        }

        .forgot-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #6b7a8c;
            font-size: 0.82rem;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: #1a5fb4; }

        /* Alert polish */
        .alert {
            border-radius: 10px;
            font-size: 0.84rem;
            border: none;
            padding: 12px 16px;
        }
        .alert-danger {
            background: #fff0f0;
            color: #b42a1a;
        }
        .alert-success {
            background: #eefbf0;
            color: #157a3b;
        }

        /* ---- Responsive ---- */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .login-left { min-height: 200px; padding: 28px; flex: 0 0 auto; }
            .logo-circle { width: 56px; height: 56px; border-radius: 14px; }
            .brand-title { font-size: 1.2rem; }
            .login-right { width: 100%; min-width: unset; padding: 32px 28px; flex: 1; }
        }

        @media (max-width: 480px) {
            .login-left { min-height: 160px; padding: 20px; }
            .logo-circle { width: 48px; height: 48px; }
            .login-right { padding: 24px 20px; }
        }
    </style>
</head>
<body>

<!-- ===== LEFT BRANDING PANEL ===== -->
<div class="login-left">
    <div class="brand-section">
        <div class="logo-row">
            <div class="logo-circle">
                <img src="<?= htmlspecialchars(appUrl('assets/images/bigfive_logo.png')) ?>" alt="Big Five" onerror="this.style.display='none'">
            </div>
            <div class="logo-divider"></div>
            <div class="logo-circle">
                <img src="<?= htmlspecialchars(appUrl('assets/images/bigblossom_logo.png')) ?>" alt="Big Blossom" onerror="this.style.display='none'">
            </div>
        </div>
        <h1 class="brand-title">TB5 Monitoring System</h1>
        <p class="brand-sub">Document Tracking &amp; Management Platform</p>
    </div>
</div>

<!-- ===== RIGHT FORM PANEL ===== -->
<div class="login-right">
    <div class="form-wrapper">
        <h2 class="welcome-text">Welcome back</h2>
        <p class="welcome-sub">Enter your credentials to access the dashboard</p>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-check-circle-fill"></i>
                Password reset successful. Please sign in.
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <label class="form-label" for="email">Email Address</label>
            <div class="input-wrap">
                <i class="bi bi-envelope input-icon"></i>
                <input type="email" id="email" name="email" class="form-control"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       required autocomplete="email">
            </div>

            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
                <i class="bi bi-lock input-icon"></i>
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="Enter your password"
                       required autocomplete="current-password">
                <button class="toggle-pwd" type="button" id="togglePwd" tabindex="-1" aria-label="Toggle password">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>

            <button type="submit" class="btn btn-login">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="<?= htmlspecialchars(appUrl('loginform/forgotpass.php')) ?>" class="forgot-link">
                <i class="bi bi-question-circle"></i> Forgot Password?
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('togglePwd').addEventListener('click', function () {
        const pwd     = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const show    = pwd.type === 'password';
        pwd.type      = show ? 'text' : 'password';
        eyeIcon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
</script>
</body>
</html>
