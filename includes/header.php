<?php
// Expects $activeSystem, $otherSystem, $currentUser, $themeClass set by auth_check.php
$switchUrl = '?' . http_build_query(array_merge($_GET, ['switch_system' => $otherSystem['id']]));
?>
<!-- ===== TOP NAVBAR ===== -->
<nav class="navbar navbar-expand-lg tb5-navbar fixed-top">
    <div class="container-fluid px-3">

        <!-- LOGO — click to switch system -->
        <a href="<?= htmlspecialchars($switchUrl) ?>"
           class="logo-switch-btn me-3"
           title="Switch to <?= htmlspecialchars($otherSystem['name']) ?>">
            <div class="logo-wrapper">
                <img src="<?= htmlspecialchars('../assets/images/' . $activeSystem['logo_filename']) ?>"
                     alt="<?= htmlspecialchars($activeSystem['name']) ?>"
                     class="system-logo">
            </div>
        </a>

        <!-- SYSTEM NAME -->
        <span class="navbar-brand system-name mb-0">
            <?= htmlspecialchars($activeSystem['name']) ?>
        </span>

        <!-- Right side -->
        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="user-badge">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars($currentUser['username']) ?></span>
            </div>
        </div>

    </div>
</nav>
<!-- END NAVBAR -->
