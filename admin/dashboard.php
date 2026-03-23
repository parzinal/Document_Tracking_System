<?php
// =========================================================
// Dashboard
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
$sid = (int)$_SESSION['active_system_id'];

// Stats
$stmtAll  = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE system_id=? AND is_archived=0');
$stmtAll->execute([$sid]); $totalDocs = (int)$stmtAll->fetchColumn();

$stmtArch = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE system_id=? AND is_archived=1');
$stmtArch->execute([$sid]); $archivedDocs = (int)$stmtArch->fetchColumn();

$stmtPend = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE system_id=? AND is_archived=0 AND date_assessment IS NULL');
$stmtPend->execute([$sid]); $pendingDocs = (int)$stmtPend->fetchColumn();

$stmtRel  = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE system_id=? AND is_archived=0 AND tesda_released IS NOT NULL');
$stmtRel->execute([$sid]); $releasedDocs = (int)$stmtRel->fetchColumn();

// Recent documents
$stmtRecent = $pdo->prepare(
    'SELECT d.*, c.name AS cat_name, dt.name AS doc_type_name, q.name AS qual_name
     FROM documents d
     LEFT JOIN categories    c  ON d.category_id      = c.id
     LEFT JOIN document_types dt ON d.document_type_id = dt.id
     LEFT JOIN qualifications q  ON d.qualification_id  = q.id
     WHERE d.system_id=? AND d.is_archived=0
     ORDER BY d.created_at DESC
     LIMIT 10'
);
$stmtRecent->execute([$sid]);
$recentDocs = $stmtRecent->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= htmlspecialchars($activeSystem['name']) ?></title>
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
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="bi bi-speedometer2"></i></div>
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle"><?= htmlspecialchars($activeSystem['name']) ?></p>
            </div>
        </div>
        <span class="badge" style="background:var(--accent-mid,#1a3a5c);font-size:0.78rem;padding:8px 14px;"><?= date('F d, Y') ?></span>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card stat-1">
                <div class="stat-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div>
                    <div class="stat-value"><?= $totalDocs ?></div>
                    <div class="stat-label">Total Documents</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card stat-2">
                <div class="stat-icon"><i class="bi bi-patch-check"></i></div>
                <div>
                    <div class="stat-value"><?= $releasedDocs ?></div>
                    <div class="stat-label">TESDA Released</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card stat-3">
                <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-value"><?= $pendingDocs ?></div>
                    <div class="stat-label">Pending Assessment</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card stat-4">
                <div class="stat-icon"><i class="bi bi-archive"></i></div>
                <div>
                    <div class="stat-value"><?= $archivedDocs ?></div>
                    <div class="stat-label">Archived</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Documents Table -->
    <div class="table-card">
        <div style="padding:16px 22px;border-bottom:1px solid #eef1f5;display:flex;align-items:center;justify-content:space-between;">
            <span class="fw-semibold" style="color:#1a2332;"><i class="bi bi-clock-history me-2" style="color:var(--accent, #1a6fc4)"></i>Recent Documents</span>
            <a href="documents_tracking.php" class="btn btn-tb5-primary" style="font-size:0.8rem;padding:7px 16px;">
                View All <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Document Type</th>
                            <th>Qualification</th>
                            <th>Date Submitted</th>
                            <th>Received by TESDA</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentDocs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No documents yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentDocs as $doc): ?>
                        <tr>
                            <td><?= htmlspecialchars($doc['cat_name']      ?? '—') ?></td>
                            <td><?= htmlspecialchars($doc['doc_type_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($doc['qual_name']     ?? '—') ?></td>
                            <td><?= $doc['date_submission'] ? date('m/d/Y', strtotime($doc['date_submission'])) : '—' ?></td>
                            <td><?= $doc['received_tesda']  ? date('m/d/Y', strtotime($doc['received_tesda']))  : '—' ?></td>
                            <td>
                                <?php if (!empty($doc['remarks']) && strtolower($doc['remarks']) === 'returned'): ?>
                                    <span class="status-pill" style="background:#fff0f0;color:#a51d2d;">Returned</span>
                                <?php elseif ($doc['tesda_released']): ?>
                                    <span class="status-pill" style="background:#e6f9ef;color:#1a7a4a;">Released</span>
                                <?php elseif ($doc['date_assessment']): ?>
                                    <span class="status-pill" style="background:#e6f2ff;color:#1a5fb4;">Assessed</span>
                                <?php else: ?>
                                    <span class="status-pill" style="background:#fff8e6;color:#b56200;">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
