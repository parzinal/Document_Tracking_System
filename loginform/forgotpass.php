<?php
// =========================================================
// Forgot Password — 2-step OTP flow
// =========================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';

$step    = $_SESSION['fp_step']  ?? 1;   // 1 = enter email, 2 = enter OTP + new pass
$error   = '';
$success = '';

// ---- Step 1: Submit email ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Generic message to avoid user enumeration
            $error = 'If that email exists, an OTP has been sent.';
        } else {
            // Invalidate previous OTPs
            $pdo->prepare('UPDATE otp_codes SET is_used=1 WHERE email=? AND is_used=0')
                ->execute([$email]);

            // Generate 6-digit OTP
            $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $pdo->prepare('INSERT INTO otp_codes (email, otp_code, expires_at) VALUES (?, ?, ?)')
                ->execute([$email, $otp, $expires]);

            if (sendOtpEmail($email, $otp)) {
                $_SESSION['fp_email'] = $email;
                $_SESSION['fp_step']  = 2;
                $step    = 2;
                $success = 'OTP sent to your email. Check your inbox.';
            } else {
                $error = 'Failed to send OTP email. Please try again later.';
                // Clean up OTP we just inserted
                $pdo->prepare('UPDATE otp_codes SET is_used=1 WHERE email=? AND otp_code=?')
                    ->execute([$email, $otp]);
            }
        }
    }
}

// ---- Step 2: Verify OTP + reset password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $email    = $_SESSION['fp_email'] ?? '';
    $otp      = trim($_POST['otp']       ?? '');
    $newPass  = $_POST['new_password']    ?? '';
    $confPass = $_POST['confirm_password'] ?? '';

    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = 'OTP must be 6 digits.';
    } elseif (strlen($newPass) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPass !== $confPass) {
        $error = 'Passwords do not match.';
    } else {
        $pdo  = getPDO();
        $stmt = $pdo->prepare(
            'SELECT * FROM otp_codes
             WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$email, $otp]);
        $otpRow = $stmt->fetch();

        if (!$otpRow) {
            $error = 'Invalid or expired OTP. Please request a new one.';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                ->execute([$hash, $email]);
            $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE id = ?')
                ->execute([$otpRow['id']]);

            // Clear session state
            unset($_SESSION['fp_email'], $_SESSION['fp_step']);

            header('Location: ../index.php?reset=success');
            exit;
        }
    }
}

// Allow going back to step 1
if (isset($_GET['restart'])) {
    unset($_SESSION['fp_email'], $_SESSION['fp_step']);
    $step = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — TB5 Monitoring System</title>
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

        /* ---- Left panel ---- */
        .fp-left {
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

        .fp-left::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(53,132,228,0.12) 0%, transparent 70%);
            top: -80px;
            right: -80px;
            border-radius: 50%;
            animation: pulse 6s ease-in-out infinite alternate;
        }

        @keyframes pulse {
            from { transform: scale(1); opacity: 0.6; }
            to   { transform: scale(1.2); opacity: 1; }
        }

        .fp-left .brand-section {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .shield-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(12px);
            border: 2px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 2.4rem;
            color: rgba(255,255,255,0.7);
        }

        .brand-title {
            color: #fff;
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .brand-sub {
            color: rgba(255,255,255,0.40);
            font-size: 0.82rem;
            letter-spacing: 0.04em;
        }

        /* ---- Right panel — form ---- */
        .fp-right {
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

        .fp-right::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, #1a5fb4, #c0392b);
        }

        .form-wrapper { width: 100%; max-width: 360px; }

        .page-heading {
            font-size: 1.55rem;
            font-weight: 800;
            color: #1a2332;
            margin-bottom: 4px;
        }

        .page-sub {
            color: #7a8a9c;
            font-size: 0.86rem;
            margin-bottom: 24px;
        }

        /* Step indicator */
        .step-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 700;
            border: 2px solid #d6dce4;
            color: #8899aa;
            background: #fff;
            transition: all 0.3s;
        }
        .step-circle.active {
            border-color: #1a5fb4;
            background: #1a5fb4;
            color: #fff;
        }
        .step-circle.done {
            border-color: #26a96a;
            background: #26a96a;
            color: #fff;
        }
        .step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            border-radius: 2px;
        }
        .step-line.active {
            background: #1a5fb4;
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
            margin-bottom: 18px;
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
        }

        .input-wrap:focus-within .input-icon {
            color: #3584e4;
        }

        .otp-input {
            text-align: center;
            letter-spacing: 10px;
            font-size: 1.6rem;
            font-weight: 700;
            padding: 12px 16px !important;
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
        }
        .toggle-pwd:hover { color: #3584e4; }

        .btn-fp {
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
        }
        .btn-fp:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(26,95,180,0.35);
            color: #fff;
        }

        .link-secondary {
            color: #6b7a8c;
            font-size: 0.82rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
        }
        .link-secondary:hover { color: #1a5fb4; }

        .alert {
            border-radius: 10px;
            font-size: 0.84rem;
            border: none;
            padding: 12px 16px;
        }
        .alert-danger  { background: #fff0f0; color: #b42a1a; }
        .alert-success { background: #eefbf0; color: #157a3b; }

        @media (max-width: 900px) {
            body { flex-direction: column; }
            .fp-left { min-height: 160px; padding: 24px; flex: 0 0 auto; }
            .fp-right { width: 100%; min-width: unset; padding: 32px 28px; flex: 1; }
        }
    </style>
</head>
<body>

<!-- ===== LEFT BRANDING PANEL ===== -->
<div class="fp-left">
    <div class="brand-section">
        <div class="shield-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        <h1 class="brand-title">Password Recovery</h1>
        <p class="brand-sub">TB5 Monitoring System</p>
    </div>
</div>

<!-- ===== RIGHT FORM PANEL ===== -->
<div class="fp-right">
    <div class="form-wrapper">
        <h2 class="page-heading">Reset Password</h2>
        <p class="page-sub">We'll send a verification code to your email</p>

        <!-- Step indicator -->
        <div class="step-bar">
            <div class="step-circle <?= $step >= 2 ? 'done' : 'active' ?>"><?= $step >= 2 ? '<i class="bi bi-check"></i>' : '1' ?></div>
            <div class="step-line <?= $step >= 2 ? 'active' : '' ?>"></div>
            <div class="step-circle <?= $step >= 2 ? 'active' : '' ?>">2</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
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

        <?php if ($step === 1): ?>
        <!-- ===== STEP 1: Enter Email ===== -->
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="send_otp">
            <label class="form-label" for="email">Email Address</label>
            <div class="input-wrap">
                <i class="bi bi-envelope input-icon"></i>
                <input type="email" id="email" name="email" class="form-control"
                       placeholder="you@example.com" required autocomplete="email">
            </div>
            <button type="submit" class="btn btn-fp">
                <i class="bi bi-send me-2"></i>Send OTP
            </button>
        </form>

        <?php else: ?>
        <!-- ===== STEP 2: OTP + New Password ===== -->
        <p class="text-muted mb-3" style="font-size:.84rem;">
            Code sent to <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>
        </p>
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="reset_password">

            <label class="form-label" for="otp">Verification Code</label>
            <div class="input-wrap">
                <input type="text" id="otp" name="otp" class="form-control otp-input"
                       placeholder="000000" maxlength="6" inputmode="numeric"
                       pattern="[0-9]{6}" autocomplete="one-time-code" required>
            </div>
            <small class="text-muted d-block mb-3" style="margin-top:-12px;font-size:.76rem;">Code expires in 15 minutes</small>

            <label class="form-label" for="new_password">New Password</label>
            <div class="input-wrap">
                <i class="bi bi-lock input-icon"></i>
                <input type="password" id="new_password" name="new_password"
                       class="form-control" placeholder="Min. 8 characters"
                       minlength="8" required autocomplete="new-password">
                <button class="toggle-pwd" type="button" id="toggleNew" tabindex="-1">
                    <i class="bi bi-eye" id="eyeNew"></i>
                </button>
            </div>

            <label class="form-label" for="confirm_password">Confirm Password</label>
            <div class="input-wrap">
                <i class="bi bi-lock-fill input-icon"></i>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="form-control" placeholder="Re-enter new password"
                       required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-fp">
                <i class="bi bi-check-circle me-2"></i>Reset Password
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="?restart=1" class="link-secondary">
                <i class="bi bi-arrow-left"></i> Use a different email
            </a>
        </div>
        <?php endif; ?>

        <hr class="my-3">
        <div class="text-center">
            <a href="login.php" class="link-secondary">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const toggleNew = document.getElementById('toggleNew');
    if (toggleNew) {
        toggleNew.addEventListener('click', function () {
            const el  = document.getElementById('new_password');
            const ico = document.getElementById('eyeNew');
            const show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            ico.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }

    const otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    }
</script>
</body>
</html>
