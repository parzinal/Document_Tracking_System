<?php
// =========================================================
// Add Data (manage Categories, Document Types, Qualifications)
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
$sid = (int)$_SESSION['active_system_id'];

$flash     = '';
$flashType = 'success';

// Allowed tables and their display names
$tables = [
    'categories'    => 'Category',
    'document_types'=> 'Document Type',
    'qualifications'=> 'Qualification',
];

// Fetch existing document_subs for display (simple list)
$subsStmt = $pdo->prepare("SELECT ds.*, dt.name AS doc_type_name, c.name AS category_name
    FROM document_subs ds
    LEFT JOIN document_types dt ON ds.document_type_id = dt.id
    LEFT JOIN categories c ON dt.category_id = c.id
    WHERE ds.system_id = ? ORDER BY c.name, dt.name, ds.name");
$subsStmt->execute([$sid]);
$documentSubs = $subsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $tableKey  = $_POST['table_key']  ?? '';
    $name      = trim($_POST['name']  ?? '');
    $itemId    = (int)($_POST['item_id'] ?? 0);

    // Seed default mapping (user-provided mapping)
    if ($action === 'seed_default_mapping') {
        // mapping: category => [ doc_type => [subs...] ]
        $mapping = [
            'BILLING' => [
                'TRAINING COST' => ['INITIAL','REMAINING'],
                'TRAINING SUPPORT FUND' => ['INITIAL','REMAINING']
            ],
            'ASSESSMENT BILLING' => [
                'CHEQUE' => [],
                'OTHERS' => []
            ],
            'REQUEST' => [
                'ASSESSMENT' => [],
                'S.O.' => [],
                'OTHERS' => []
            ],
            'TRANSMITTAL LETTER' => [
                'CCTV' => [],
                'OTHERS' => [],
                'ASSESSMENT TOOLS' => []
            ],
            'REPORT' => [
                'EMPLOYMENT' => [],
                'ENROLLMENT' => [],
                'TERMINAL' => [],
                'GRAD/FILES' => [],
                'OTHERS' => []
            ]
        ];

        try {
            $pdo->beginTransaction();
            $insCat = $pdo->prepare('INSERT INTO categories (system_id, name) VALUES (?, ?)');
            $selCat = $pdo->prepare('SELECT id FROM categories WHERE system_id=? AND name=?');
            $insDt  = $pdo->prepare('INSERT INTO document_types (system_id, category_id, name) VALUES (?, ?, ?)');
            $selDt  = $pdo->prepare('SELECT id FROM document_types WHERE system_id=? AND name=?');
            $insSub = $pdo->prepare('INSERT INTO document_subs (system_id, document_type_id, name) VALUES (?, ?, ?)');
            $selSub = $pdo->prepare('SELECT id FROM document_subs WHERE system_id=? AND document_type_id=? AND name=?');

            foreach ($mapping as $catName => $dts) {
                // ensure category
                $selCat->execute([$sid, $catName]);
                $c = $selCat->fetch();
                if ($c) $catId = $c['id'];
                else {
                    $insCat->execute([$sid, $catName]);
                    $catId = $pdo->lastInsertId();
                }

                foreach ($dts as $dtName => $subs) {
                    // ensure document type (unique by name+system)
                    $selDt->execute([$sid, $dtName]);
                    $d = $selDt->fetch();
                    if ($d) $dtId = $d['id'];
                    else {
                        $insDt->execute([$sid, $catId, $dtName]);
                        $dtId = $pdo->lastInsertId();
                    }

                    foreach ($subs as $subName) {
                        $selSub->execute([$sid, $dtId, $subName]);
                        if (!$selSub->fetch()) {
                            $insSub->execute([$sid, $dtId, $subName]);
                        }
                    }
                }
            }
            $pdo->commit();
            $flash = 'Default mapping seeded.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $flash = 'Seeding failed: ' . $e->getMessage(); $flashType = 'danger';
        }
        header('Location: add_data.php?tab=categories'); exit;
    }

    // Add hierarchical mapping: category -> document type -> sub documents
    if ($action === 'add_mapping') {
        $cat_existing = (int)($_POST['category_existing'] ?? 0);
        $cat_new = trim($_POST['category_new'] ?? '');
        $doc_type_name = trim($_POST['doc_type_name'] ?? '');
        $subs_text = trim($_POST['subs_text'] ?? ''); // comma or newline separated

        if (!$doc_type_name) { $flash = 'Document Type is required.'; $flashType = 'danger'; }
        else {
            // determine category id
            if ($cat_existing) {
                $category_id = $cat_existing;
            } elseif ($cat_new !== '') {
                $pdo->prepare('INSERT INTO categories (system_id, name) VALUES (?, ?)')->execute([$sid, $cat_new]);
                $category_id = $pdo->lastInsertId();
            } else {
                $flash = 'Select or enter a Category.'; $flashType = 'danger';
                header('Location: add_data.php'); exit;
            }

            // insert document_type with category link and optional qualification
            $qualification_id = intval($_POST['doc_qualification_id'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO document_types (system_id, category_id, qualification_id, name) VALUES (?, ?, ?, ?)')
                ->execute([$sid, $category_id, $qualification_id, $doc_type_name]);
            $doc_type_id = $pdo->lastInsertId();

            // parse subs
            $subs = preg_split('/[\r\n,]+/', $subs_text);
            $ins = $pdo->prepare('INSERT INTO document_subs (system_id, document_type_id, name) VALUES (?, ?, ?)');
            $count = 0;
            foreach ($subs as $s) {
                $s = trim($s);
                if ($s === '') continue;
                $ins->execute([$sid, $doc_type_id, $s]);
                $count++;
            }
            $flash = 'Mapping added. Document Type added and ' . $count . ' sub-document(s).';
        }
        header('Location: add_data.php?tab=categories'); exit;
    }

    // Validate table key (whitelist against injection)
    if (!array_key_exists($tableKey, $tables)) {
        $flash = 'Invalid table.'; $flashType = 'danger';
    } elseif (in_array($action, ['add', 'edit'], true) && $name === '') {
        $flash = 'Name cannot be empty.'; $flashType = 'danger';
    } elseif ($action === 'add') {
        $pdo->prepare("INSERT INTO `$tableKey` (system_id, name) VALUES (?, ?)")
            ->execute([$sid, $name]);
        $flash = htmlspecialchars($tables[$tableKey]) . ' added.';
    } elseif ($action === 'edit' && $itemId) {
        $pdo->prepare("UPDATE `$tableKey` SET name=? WHERE id=? AND system_id=?")
            ->execute([$name, $itemId, $sid]);
        $flash = htmlspecialchars($tables[$tableKey]) . ' updated.';
    } elseif ($action === 'delete' && $itemId) {
        $pdo->prepare("DELETE FROM `$tableKey` WHERE id=? AND system_id=?")
            ->execute([$itemId, $sid]);
        $flash = htmlspecialchars($tables[$tableKey]) . ' deleted.'; $flashType = 'warning';
    }

    $activeTab = $tableKey;
    header('Location: add_data.php?tab=' . urlencode($tableKey));
    exit;
}

$activeTab = $_GET['tab'] ?? 'categories';
if (!array_key_exists($activeTab, $tables)) $activeTab = 'categories';

// Fetch all data
$data = [];
foreach ($tables as $key => $label) {
    $stmt = $pdo->prepare("SELECT * FROM `$key` WHERE system_id=? ORDER BY name");
    $stmt->execute([$sid]);
    $data[$key] = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Data — <?= htmlspecialchars($activeSystem['name']) ?></title>
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
            <?= $flash ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="bi bi-plus-circle"></i></div>
            <div>
                <h1 class="page-title">Add Data</h1>
                <p class="page-subtitle">Manage dropdown options for <?= htmlspecialchars($activeSystem['name']) ?></p>
            </div>
        </div>
    </div>
    <!-- Single hierarchical add form -->
    <div class="card mb-3">
        <div class="card-body">
            <h6 class="mb-3">Add Category → Document Type → Sub-Documents (one form)</h6>
            <div class="mb-3">
                <form method="POST" onsubmit="return confirm('Seed default mapping? This will add categories, document types and sub-documents if they do not already exist.');">
                    <input type="hidden" name="action" value="seed_default_mapping">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Seed Default Mapping</button>
                </form>
            </div>
            <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="add_mapping">
                <div class="col-md-4">
                    <label class="form-label">Select existing Category</label>
                    <select name="category_existing" class="form-select">
                        <option value="">— Select Category —</option>
                        <?php foreach ($data['categories'] as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Or enter New Category</label>
                    <input type="text" name="category_new" class="form-control" placeholder="New Category name">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Document Type</label>
                    <input type="text" name="doc_type_name" class="form-control" placeholder="Document Type name" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Qualification (billing only)</label>
                    <select name="doc_qualification_id" id="map_qualification" class="form-select">
                        <option value="">— Select Qualification —</option>
                        <?php foreach ($data['qualifications'] as $q): ?>
                            <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Sub-Documents (comma or newline separated)</label>
                    <textarea name="subs_text" class="form-control" rows="2" placeholder="e.g. INITIAL, REMAINING"></textarea>
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-tb5-primary">Add Mapping</button>
                </div>
            </form>
        </div>
    </div>

        <script>
        (function(){
            const existSel = document.querySelector('select[name="category_existing"]');
            const newInput = document.querySelector('input[name="category_new"]');
            const qualSel  = document.getElementById('map_qualification');
            if (!qualSel) return;
            function updateQual() {
                const ex = existSel ? (existSel.options[existSel.selectedIndex]?.text || '') : '';
                const nw = newInput ? (newInput.value || '') : '';
                const target = (ex || nw).toLowerCase();
                const enabled = /billing/i.test(target);
                qualSel.disabled = !enabled;
                if (!enabled) qualSel.value = '';
            }
            if (existSel) existSel.addEventListener('change', updateQual);
            if (newInput) newInput.addEventListener('input', updateQual);
            // init
            updateQual();
        })();
        </script>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-0" id="addDataTabs" role="tablist">
        <?php foreach ($tables as $key => $label): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeTab === $key ? 'active' : '' ?>"
                        data-bs-toggle="tab"
                        data-bs-target="#tab-<?= $key ?>"
                        type="button" role="tab">
                    <?= htmlspecialchars($label) ?>s
                    <span class="badge bg-secondary ms-1"><?= count($data[$key]) ?></span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content">
    <?php foreach ($tables as $key => $label): ?>
        <div class="tab-pane fade <?= $activeTab === $key ? 'show active' : '' ?>"
             id="tab-<?= $key ?>" role="tabpanel">

            <div class="card border-top-0" style="border-radius:0 0 14px 14px;">
                <div class="card-body">

                    <!-- Add form -->
                    <form method="POST" class="inline-add-form">
                        <input type="hidden" name="action"    value="add">
                        <input type="hidden" name="table_key" value="<?= $key ?>">
                        <input type="text" name="name" class="form-control"
                               placeholder="New <?= htmlspecialchars($label) ?> name" required>
                        <button type="submit" class="btn btn-tb5-primary text-nowrap">
                            <i class="bi bi-plus-lg me-1"></i>Add
                        </button>
                    </form>

                    <!-- Data table -->
                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Created</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($data[$key])): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No <?= strtolower($label) ?>s yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($data[$key] as $i => $row): ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td id="label-<?= $key ?>-<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= date('m/d/Y', strtotime($row['created_at'])) ?></td>
                                    <td class="text-center" style="width:150px">
                                        <!-- Edit -->
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="openEditModal('<?= $key ?>', <?= $row['id'] ?>, <?= htmlspecialchars(json_encode($row['name']), ENT_QUOTES) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Delete -->
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete this <?= strtolower($label) ?>? Documents using it will lose this link.')">
                                            <input type="hidden" name="action"    value="delete">
                                            <input type="hidden" name="table_key" value="<?= $key ?>">
                                            <input type="hidden" name="item_id"   value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div><!-- end table-responsive -->
                    </div><!-- end table-card -->

                    <?php if ($key === 'document_types'): ?>
                        <div class="mt-4">
                            <h6>Existing Sub-Documents</h6>
                            <div class="small text-muted">Sub-documents linked to document types</div>
                            <ul class="list-group mt-2">
                                <?php foreach ($documentSubs as $s): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($s['category_name'] . ' › ' . $s['doc_type_name']) ?></div>
                                        </div>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="table_key" value="document_subs">
                                            <input type="hidden" name="item_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</main>

<!-- Edit Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title text-white"><i class="bi bi-pencil me-2"></i>Edit Item</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action"    value="edit">
                <input type="hidden" name="table_key" id="edit_table_key">
                <input type="hidden" name="item_id"   id="edit_item_id">
                <div class="modal-body">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" name="name" id="edit_item_name" class="form-control" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function openEditModal(tableKey, itemId, name) {
    document.getElementById('edit_table_key').value = tableKey;
    document.getElementById('edit_item_id').value   = itemId;
    document.getElementById('edit_item_name').value = name;
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}
</script>
</body>
</html>
