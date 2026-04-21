<?php
// =========================================================
// Auth Check — include at top of every protected page
// =========================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/', 1) - 1) . 'index.php');
    exit;
}

// Handle system switch via GET param (safe: cast to int, validate against DB)
if (!empty($_GET['switch_system'])) {
    $pdo        = getPDO();
    $sid        = (int) $_GET['switch_system'];
    $validSystems = $pdo->query('SELECT id FROM systems')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array($sid, array_map('intval', $validSystems))) {
        $_SESSION['active_system_id'] = $sid;
    }
    // Redirect back to the same page without the query param
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $base);
    exit;
}

// Ensure active system is set
if (empty($_SESSION['active_system_id'])) {
    $_SESSION['active_system_id'] = 1;
}

$pdo = getPDO();

// Fetch current user
$stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch();
if (!$currentUser) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Fetch active system info
$stmtSys = $pdo->prepare('SELECT * FROM systems WHERE id = ?');
$stmtSys->execute([$_SESSION['active_system_id']]);
$activeSystem = $stmtSys->fetch();

// Theme: Big Five (id=1) = blue, Big Blossom (id=2) = red
$themeClass = ((int)$_SESSION['active_system_id'] === 2) ? 'theme-blossom' : 'theme-bigfive';

// Determine the OTHER system for the switch button
$otherSystemId = ((int)$_SESSION['active_system_id'] === 1) ? 2 : 1;
$stmtOther = $pdo->prepare('SELECT * FROM systems WHERE id = ?');
$stmtOther->execute([$otherSystemId]);
$otherSystem = $stmtOther->fetch();
