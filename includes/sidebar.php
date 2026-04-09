<?php
// Expects $activeSystem set by auth_check.php
// Determine current page for active state
$currentPage = basename($_SERVER['PHP_SELF']);

$navItems = [
    ['file' => 'dashboard.php',           'icon' => 'bi-speedometer2',  'label' => 'Dashboard'],
    ['file' => 'documents_tracking.php',  'icon' => 'bi-file-earmark-text', 'label' => 'Documents Tracking'],
    ['file' => 'add_documents.php',       'icon' => 'bi-file-earmark-plus', 'label' => 'Add Documents'],
    ['file' => 'archives.php',            'icon' => 'bi-archive',        'label' => 'Archives'],
    ['file' => 'add_data.php',            'icon' => 'bi-plus-circle',    'label' => 'Add Data'],
    ['file' => 'usermanage.php',          'icon' => 'bi-people',         'label' => 'User Management'],
    ['file' => 'profile.php',             'icon' => 'bi-person',         'label' => 'Profile'],
];
?>
<!-- ===== SIDEBAR ===== -->
<div class="tb5-sidebar" id="tb5Sidebar">
    <!-- Sidebar header -->
    <div class="sidebar-brand">
        <span class="sidebar-brand-text">MENU</span>
        <button class="btn btn-sm sidebar-toggle-btn ms-auto" id="sidebarToggle" title="Collapse">
            <i class="bi bi-layout-sidebar-reverse"></i>
        </button>
    </div>

    <nav class="sidebar-nav flex-column">
        <?php foreach ($navItems as $item): ?>
            <?php $isActive = ($currentPage === $item['file']); ?>
            <a href="<?= htmlspecialchars($item['file']) ?>"
               class="sidebar-link <?= $isActive ? 'active' : '' ?>">
                <i class="bi <?= $item['icon'] ?> sidebar-icon"></i>
                <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Divider + Logout -->
        <hr class="sidebar-divider">
        <a href="../loginform/logout.php" class="sidebar-link sidebar-logout">
            <i class="bi bi-box-arrow-left sidebar-icon"></i>
            <span class="sidebar-label">Logout</span>
        </a>
    </nav>
</div>
<!-- END SIDEBAR -->
