<?php
// =========================================================
// Archives
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
$sid = (int)$_SESSION['active_system_id'];

$flash     = '';
$flashType = 'success';

if (!empty($_SESSION['archives_flash_message'])) {
    $flash = (string)$_SESSION['archives_flash_message'];
    $flashType = (string)($_SESSION['archives_flash_type'] ?? 'success');
    unset($_SESSION['archives_flash_message'], $_SESSION['archives_flash_type']);
}

function setArchiveFlash(string $message, string $type = 'success'): void {
    $_SESSION['archives_flash_message'] = $message;
    $_SESSION['archives_flash_type'] = $type;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ids = array_values(array_filter(
        array_map('intval', $_POST['selected_ids'] ?? []),
        static fn($id) => $id > 0
    ));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    if (in_array($action, ['bulk_restore', 'bulk_delete'], true)) {
        if (!$ids) {
            setArchiveFlash('Select at least one row first.', 'warning');
            header('Location: archives.php');
            exit;
        }

        if ($adminPassword === '' || !password_verify($adminPassword, (string)$currentUser['password_hash'])) {
            setArchiveFlash('Admin password is incorrect.', 'danger');
            header('Location: archives.php');
            exit;
        }
    }

    // RESTORE
    if ($action === 'bulk_restore') {
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE documents SET is_archived=0 WHERE id IN ($ph) AND system_id=?")
                ->execute(array_merge($ids, [$sid]));
            setArchiveFlash(count($ids) . ' document(s) restored.');
        }
    }

    // DELETE permanently
    if ($action === 'bulk_delete') {
        if ($ids) {
            // Delete uploaded files first
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sel = $pdo->prepare("SELECT image_path FROM documents WHERE id IN ($ph) AND system_id=?");
            $sel->execute(array_merge($ids, [$sid]));
            foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['image_path'] && file_exists(__DIR__ . '/../' . $r['image_path'])) {
                    unlink(__DIR__ . '/../' . $r['image_path']);
                }
            }
            $pdo->prepare("DELETE FROM documents WHERE id IN ($ph) AND system_id=?")
                ->execute(array_merge($ids, [$sid]));
            setArchiveFlash(count($ids) . ' document(s) permanently deleted.', 'warning');
        }
    }

    header('Location: archives.php'); exit;
}

// Filters
$filterCat  = (int)($_GET['cat']  ?? 0);
$filterDt   = (int)($_GET['dt']   ?? 0);
$filterQual = (int)($_GET['qual'] ?? 0);

$where  = ['d.system_id = ?', 'd.is_archived = 1'];
$params = [$sid];
if ($filterCat)  { $where[] = 'd.category_id = ?';      $params[] = $filterCat; }
if ($filterDt)   { $where[] = 'd.document_type_id = ?'; $params[] = $filterDt; }
if ($filterQual) { $where[] = 'd.qualification_id = ?'; $params[] = $filterQual; }
$whereSQL = implode(' AND ', $where);

$docs = $pdo->prepare(
    "SELECT d.*, c.name AS cat_name, dt.name AS doc_type_name, q.name AS qual_name
     FROM documents d
     LEFT JOIN categories      c  ON d.category_id      = c.id
     LEFT JOIN document_types  dt ON d.document_type_id = dt.id
     LEFT JOIN qualifications   q  ON d.qualification_id  = q.id
     WHERE $whereSQL ORDER BY d.updated_at DESC"
);
$docs->execute($params);
$docs = $docs->fetchAll();

$categories    = $pdo->prepare('SELECT * FROM categories     WHERE system_id=? ORDER BY name'); $categories->execute([$sid]);    $categories    = $categories->fetchAll();
$documentTypes = $pdo->prepare('SELECT * FROM document_types  WHERE system_id=? ORDER BY name'); $documentTypes->execute([$sid]); $documentTypes = $documentTypes->fetchAll();
$qualifications= $pdo->prepare('SELECT * FROM qualifications  WHERE system_id=? ORDER BY name'); $qualifications->execute([$sid]); $qualifications= $qualifications->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archives — <?= htmlspecialchars($activeSystem['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
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
            <?= $flash ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="bi bi-archive"></i></div>
            <div>
                <h1 class="page-title">Archives</h1>
                <p class="page-subtitle"><?= htmlspecialchars($activeSystem['name']) ?> &mdash; <?= count($docs) ?> archived record(s)</p>
            </div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar mb-3">
        <select name="cat"  class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCat == $c['id'] ? 'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="dt"   class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Document Types</option>
            <?php foreach ($documentTypes as $dt): ?>
                <option value="<?= $dt['id'] ?>" <?= $filterDt == $dt['id'] ? 'selected':'' ?>><?= htmlspecialchars($dt['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="qual" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Qualifications</option>
            <?php foreach ($qualifications as $q): ?>
                <option value="<?= $q['id'] ?>" <?= $filterQual == $q['id'] ? 'selected':'' ?>><?= htmlspecialchars($q['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterCat || $filterDt || $filterQual): ?>
            <a href="archives.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" id="bulkActionInput" value="">
        <input type="hidden" name="admin_password" id="bulkAdminPasswordInput" value="">
        <div class="action-bar no-print">
            <button type="button" class="btn btn-modern btn-success"
                    onclick="return requestArchiveBulkAction('bulk_restore', 'restore')">
                <i class="bi bi-arrow-counterclockwise"></i>Restore Selected
            </button>
            <button type="button" class="btn btn-modern btn-danger"
                    id="bulkDeleteBtn"
                    onclick="return requestArchiveBulkAction('bulk_delete', 'delete')">
                <i class="bi bi-trash"></i>Delete Permanently
            </button>
            <button type="button" class="btn btn-modern btn-secondary" id="printBtn">
                <i class="bi bi-printer"></i>Print
            </button>
        </div>

        <div class="slide-table-wrap">

            <!-- Custom controls bar -->
            <div class="slide-dt-bar">
                <div class="slide-dt-length">
                    Show
                    <select id="dtLengthSelect" class="slide-dt-select">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="-1">All</option>
                    </select>
                    entries
                </div>
                <div class="slide-dt-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="dtSearchInput" placeholder="Quick search…">
                </div>
            </div>

            <div class="table-responsive">
                <table id="documentsTable" class="slide-table slide-table-muted">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>Category</th>
                                <th>Qualification</th>
                                <th>Document Type</th>
                                <th>Date Submitted</th>
                                <th>Received by TESDA</th>
                                <th>Returned to Center</th>
                                <th>Staff Received</th>
                                <th>Date of Assessment</th>
                                <th>Assessor Name</th>
                                <th>TESDA Released</th>
                                <th>Remarks</th>
                                <th>Image</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($docs as $doc): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_ids[]" value="<?= $doc['id'] ?>" class="row-check"></td>
                                <td><?= htmlspecialchars($doc['cat_name']      ?? '—') ?></td>
                                <td><?= htmlspecialchars($doc['qual_name']     ?? '—') ?></td>
                                <td><?= htmlspecialchars($doc['doc_type_name'] ?? '—') ?></td>
                                <td><?= $doc['date_submission'] ? date('m/d/Y', strtotime($doc['date_submission'])) : '' ?></td>
                                <td><?= $doc['received_tesda']  ? date('m/d/Y', strtotime($doc['received_tesda']))  : '' ?></td>
                                <td><?= $doc['returned_center'] ? date('m/d/Y', strtotime($doc['returned_center'])) : '' ?></td>
                                <td><?= htmlspecialchars($doc['staff_received'] ?? '') ?></td>
                                <td><?= $doc['date_assessment'] ? date('m/d/Y', strtotime($doc['date_assessment'])) : '' ?></td>
                                <td><?= htmlspecialchars($doc['assessor_name']  ?? '') ?></td>
                                <td><?= $doc['tesda_released']  ? date('m/d/Y', strtotime($doc['tesda_released']))  : '' ?></td>
                                <td><?= htmlspecialchars($doc['remarks'] ?? '') ?></td>
                                <td>
                                    <?php if ($doc['image_path']): ?>
                                        <img src="../<?= htmlspecialchars($doc['image_path']) ?>" class="doc-thumb" alt="img"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
            </div>
            <div class="slide-dt-foot" id="dtInfoRow">Showing <?= count($docs) ?> archived record(s)</div>
        </div>
    </form>
</main>

<div class="modal fade" id="archivePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-white"><i class="bi bi-shield-lock me-2"></i>Admin Confirmation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-4">
                <p class="text-muted mb-3 fs-5">Enter admin password to <span id="archiveActionLabel">continue</span>.</p>
                <label for="archivePasswordInput" class="form-label fw-semibold mb-2 fs-4">Admin Password</label>
                <input type="password" id="archivePasswordInput" class="form-control form-control-lg" autocomplete="current-password" placeholder="Enter password">
            </div>
            <div class="modal-footer px-4 py-3">
                <button type="button" class="btn btn-outline-secondary btn-lg px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-tb5-primary btn-lg px-4" id="archivePasswordSubmit">
                    <i class="bi bi-check2-circle me-1"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
let pendingArchiveAction = '';

function requestArchiveBulkAction(actionValue, actionLabel) {
    var checked = document.querySelectorAll('.row-check:checked').length;
    if (checked === 0) { showToast('Select at least one row.', 'warning'); return false; }

    pendingArchiveAction = actionValue;
    document.getElementById('archiveActionLabel').textContent = actionLabel + ' ' + checked + ' document(s)';
    document.getElementById('archivePasswordInput').value = '';
    document.getElementById('bulkAdminPasswordInput').value = '';

    const modalEl = document.getElementById('archivePasswordModal');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    setTimeout(function () {
        document.getElementById('archivePasswordInput')?.focus();
    }, 120);

    return false;
}

document.getElementById('archivePasswordSubmit')?.addEventListener('click', function () {
    if (!pendingArchiveAction) return;

    const passInput = document.getElementById('archivePasswordInput');
    const adminPassword = (passInput?.value || '').trim();
    if (!adminPassword) {
        showToast('Admin password is required.', 'warning');
        passInput?.focus();
        return;
    }

    document.getElementById('bulkActionInput').value = pendingArchiveAction;
    document.getElementById('bulkAdminPasswordInput').value = adminPassword;
    document.getElementById('bulkForm').submit();
});

document.getElementById('archivePasswordInput')?.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        document.getElementById('archivePasswordSubmit')?.click();
    }
});

document.getElementById('archivePasswordModal')?.addEventListener('hidden.bs.modal', function () {
    pendingArchiveAction = '';
    document.getElementById('bulkActionInput').value = '';
    document.getElementById('bulkAdminPasswordInput').value = '';
    document.getElementById('archivePasswordInput').value = '';
});
</script>
</body>
</html>
