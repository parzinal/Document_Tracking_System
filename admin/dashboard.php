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

// Category totals for dashboard chart
$stmtCatStats = $pdo->prepare(
    'SELECT COALESCE(c.name, "Uncategorized") AS cat_name, COUNT(*) AS total
     FROM documents d
     LEFT JOIN categories c ON d.category_id = c.id
     WHERE d.system_id=? AND d.is_archived=0
     GROUP BY COALESCE(c.name, "Uncategorized")
     ORDER BY total DESC, cat_name ASC'
);
$stmtCatStats->execute([$sid]);
$categoryStats = $stmtCatStats->fetchAll();
$categoryLabels = array_map(fn($r) => $r['cat_name'], $categoryStats);
$categoryCounts = array_map(fn($r) => (int)$r['total'], $categoryStats);

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

    <!-- Category Overview Chart -->
    <div class="table-card mb-4">
        <div style="padding:16px 22px;border-bottom:1px solid #eef1f5;display:flex;align-items:center;justify-content:space-between;">
            <span class="fw-semibold" style="color:#1a2332;"><i class="bi bi-bar-chart-line me-2" style="color:var(--accent, #1a6fc4)"></i>Documents Per Category</span>
            <span class="text-muted" style="font-size:.82rem;">Active records only</span>
        </div>
        <div style="padding:18px 22px;">
            <?php if (empty($categoryStats)): ?>
                <div class="text-center text-muted py-4">No category data yet.</div>
            <?php else: ?>
                <div style="height:320px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Documents Table -->
    <div class="table-card">
        <div style="padding:16px 22px;border-bottom:1px solid #eef1f5;display:flex;align-items:center;justify-content:space-between;">
            <span class="fw-semibold" style="color:#1a2332;"><i class="bi bi-clock-history me-2" style="color:var(--accent, #1a6fc4)"></i>Recent Document Files</span>
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
                            <th>Image</th>
                            <th>File</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentDocs)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No documents yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentDocs as $doc): ?>
                        <tr>
                            <td><?= htmlspecialchars($doc['cat_name']      ?? '—') ?></td>
                            <td><?= htmlspecialchars($doc['doc_type_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($doc['qual_name']     ?? '—') ?></td>
                            <td><?= $doc['date_submission'] ? date('m/d/Y', strtotime($doc['date_submission'])) : '—' ?></td>
                            <td><?= $doc['received_tesda']  ? date('m/d/Y', strtotime($doc['received_tesda']))  : '—' ?></td>
                            <td>
                                <?php if (!empty($doc['image_path'])): ?>
                                    <a href="../<?= htmlspecialchars($doc['image_path']) ?>" target="_blank" rel="noopener">
                                        <img src="../<?= htmlspecialchars($doc['image_path']) ?>" alt="doc" style="width:36px;height:36px;object-fit:cover;border:1px solid #dbe3ee;border-radius:6px;" onerror="this.style.display='none'">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="documents_tracking.php?doc_id=<?= (int)$doc['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-search me-1"></i>Locate
                                </a>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
(function() {
    const chartEl = document.getElementById('categoryChart');
    if (!chartEl) return;

    const labels = <?= json_encode($categoryLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
    const data = <?= json_encode($categoryCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
    const isBlossom = <?= json_encode($themeClass === 'theme-blossom') ?>;

    const base = isBlossom ? '180, 8, 8' : '26, 111, 196';
    const border = isBlossom ? '#7c0b0b' : '#1a5fb4';

    new Chart(chartEl, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Documents',
                data: data,
                backgroundColor: `rgba(${base}, 0.22)`,
                borderColor: border,
                borderWidth: 1.5,
                borderRadius: 8,
                maxBarThickness: 48
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        stepSize: 1
                    },
                    grid: { color: 'rgba(120,130,145,0.2)' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
</body>
</html>
