<?php
// =========================================================
// Add Documents (Full Page)
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';

$sid = (int)$_SESSION['active_system_id'];
$flash = '';
$flashType = 'success';

$billCatsStmt = $pdo->prepare("SELECT id FROM categories WHERE system_id=? AND LOWER(name) LIKE ?");
$billCatsStmt->execute([$sid, '%billing%']);
$billingCatIds = array_map('intval', $billCatsStmt->fetchAll(PDO::FETCH_COLUMN));

function handleRowUpload(int $index): ?string {
    if (!isset($_FILES['row_image']['name'][$index])) return null;
    if (!is_uploaded_file($_FILES['row_image']['tmp_name'][$index])) return null;
    if ($_FILES['row_image']['error'][$index] !== UPLOAD_ERR_OK) return null;

    $tmp = $_FILES['row_image']['tmp_name'][$index];
    $size = (int)($_FILES['row_image']['size'][$index] ?? 0);
    if ($size <= 0) return null;

    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Invalid file type in one of the row images.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('One of the row images is too large (max 5 MB).');
    }

    $original = (string)($_FILES['row_image']['name'][$index] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
    }

    $filename = uniqid('doc_', true) . '.' . $ext;
    $dest = __DIR__ . '/../assets/upload/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to save one of the row images.');
    }

    return 'assets/upload/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_multiple_documents') {
        $date_sub_shared   = $_POST['date_submission_shared'] ?: null;
        $category_ids      = $_POST['row_category_id']      ?? [];
        $doctype_ids       = $_POST['row_document_type_id'] ?? [];
        $qualification_ids = $_POST['row_qualification_id'] ?? [];
        $sub_docs          = $_POST['row_document_sub']     ?? [];
        $batch_nos         = $_POST['batch_no']             ?? [];
        $remarks_arr       = $_POST['remarks']              ?? [];
        $received_tesdas   = $_POST['received_tesda']       ?? [];
        $returned_centers  = $_POST['returned_center']      ?? [];
        $staff_receiveds   = $_POST['staff_received']       ?? [];
        $date_assessments  = $_POST['date_assessment']      ?? [];
        $assessor_names    = $_POST['assessor_name']        ?? [];
        $tesda_releaseds   = $_POST['tesda_released']       ?? [];

        $ins = $pdo->prepare('INSERT INTO documents
            (system_id,category_id,document_type_id,document_sub,qualification_id,
             date_submission,batch_no,remarks,received_tesda,returned_center,
             staff_received,date_assessment,assessor_name,tesda_released,image_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        $rowTotal = max(
            count($category_ids),
            count($doctype_ids),
            count($batch_nos),
            count($remarks_arr),
            isset($_FILES['row_image']['name']) && is_array($_FILES['row_image']['name']) ? count($_FILES['row_image']['name']) : 0
        );

        try {
            for ($i = 0; $i < $rowTotal; $i++) {
                $cat_id = intval($category_ids[$i] ?? 0) ?: null;
                $dt_id = intval($doctype_ids[$i] ?? 0) ?: null;
                $qual_id = intval($qualification_ids[$i] ?? 0) ?: null;
                $sub_doc = isset($sub_docs[$i]) ? trim($sub_docs[$i]) ?: null : null;
                $batch = trim($batch_nos[$i] ?? '') ?: null;
                $rem = trim($remarks_arr[$i] ?? '') ?: null;
                $imgPath = handleRowUpload($i);

                $hasOtherData = (
                    $cat_id !== null ||
                    $dt_id !== null ||
                    $qual_id !== null ||
                    $sub_doc !== null ||
                    $batch !== null ||
                    $rem !== null ||
                    ($received_tesdas[$i] ?? '') !== '' ||
                    ($returned_centers[$i] ?? '') !== '' ||
                    ($staff_receiveds[$i] ?? '') !== '' ||
                    ($date_assessments[$i] ?? '') !== '' ||
                    trim($assessor_names[$i] ?? '') !== '' ||
                    ($tesda_releaseds[$i] ?? '') !== '' ||
                    $imgPath !== null
                );

                if (!$hasOtherData) {
                    continue;
                }

                if ($cat_id === null || $dt_id === null) {
                    continue;
                }

                if (!in_array($cat_id, $billingCatIds, true)) {
                    $qual_id = null;
                }

                $rowDateSubmission = $date_sub_shared ?: null;

                $ins->execute([
                    $sid,
                    $cat_id,
                    $dt_id,
                    $sub_doc,
                    $qual_id,
                    $rowDateSubmission,
                    $batch,
                    $rem,
                    ($received_tesdas[$i] ?? '') ?: null,
                    ($returned_centers[$i] ?? '') ?: null,
                    ($staff_receiveds[$i] ?? '') ?: null,
                    ($date_assessments[$i] ?? '') ?: null,
                    trim($assessor_names[$i] ?? '') ?: null,
                    ($tesda_releaseds[$i] ?? '') ?: null,
                    $imgPath
                ]);
                $count++;
            }
            $flash = $count . ' document(s) added.';
        } catch (RuntimeException $e) {
            $flash = $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$cats = $pdo->prepare('SELECT * FROM categories WHERE system_id=? ORDER BY name');
$cats->execute([$sid]);
$categories = $cats->fetchAll();

$dts = $pdo->prepare('SELECT * FROM document_types WHERE system_id=? ORDER BY name');
$dts->execute([$sid]);
$documentTypes = $dts->fetchAll();

$quals = $pdo->prepare('SELECT * FROM qualifications WHERE system_id=? ORDER BY name');
$quals->execute([$sid]);
$qualifications = $quals->fetchAll();

$dtByCat = [];
foreach ($documentTypes as $dt) {
    $cid = (int)($dt['category_id'] ?? 0);
    $dtByCat[$cid][] = ['id' => $dt['id'], 'name' => $dt['name']];
}
$dtAll = array_map(fn($dt) => ['id' => $dt['id'], 'name' => $dt['name']], $documentTypes);

$subsStmt = $pdo->prepare('SELECT document_type_id,name FROM document_subs WHERE system_id=? ORDER BY name');
$subsStmt->execute([$sid]);
$docSubs = [];
foreach ($subsStmt->fetchAll() as $s) {
    $docSubs[(int)$s['document_type_id']][] = $s['name'];
}

$recentStmt = $pdo->prepare(
    'SELECT d.*, c.name AS cat_name, dt.name AS doc_type_name, q.name AS qual_name
     FROM documents d
     LEFT JOIN categories c ON d.category_id=c.id
     LEFT JOIN document_types dt ON d.document_type_id=dt.id
     LEFT JOIN qualifications q ON d.qualification_id=q.id
     WHERE d.system_id=? AND d.is_archived=0
     ORDER BY d.created_at DESC
     LIMIT 50'
);
$recentStmt->execute([$sid]);
$recentDocuments = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Documents — <?= htmlspecialchars($activeSystem['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/header.css">
    <link rel="stylesheet" href="../assets/sidebar.css">
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        .add-page-wrap { max-width: 1680px; margin: 0 auto; }
        .add-card { border: 1px solid #d8dfea; border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 8px 28px rgba(20, 40, 90, .08); }
        .add-card-header { background: linear-gradient(120deg, #112d58, #365f95); color: #fff; padding: 18px 22px; }
        .add-card-title { font-size: 1.45rem; margin: 0; font-weight: 700; letter-spacing: .01em; }
        .add-card-subtitle { margin: 5px 0 0; color: rgba(255, 255, 255, .75); font-size: .9rem; }
        .prefill-banner { background: #f0f5ff; border-bottom: 1px solid #d0dbf0; padding: 14px 20px 12px; }
        .prefill-banner-title { font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #5566aa; margin-bottom: 8px; }
        .prefill-hint { font-size: .76rem; color: #6073a8; margin-top: 6px; }
        .rows-section { padding: 12px 20px 6px; }
        .rows-section-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; gap: 8px; flex-wrap: wrap; }
        .rows-section-label { font-size: .74rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #555; }
        .rows-scroll-wrap { overflow-x: auto; overflow-y: auto; max-height: 540px; border: 1px solid #dee2e6; border-radius: 8px; }
        #addRowsTable { min-width: 1780px; margin-bottom: 0; font-size: .79rem; }
        #addRowsTable thead th {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
            background: #f8f9fa !important;
            color: #333 !important;
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 8px 8px;
            border-bottom: 2px solid #dee2e6;
        }
        #addRowsTable tbody td { padding: 4px; vertical-align: middle; }
        #addRowsTable .form-control,
        #addRowsTable .form-select {
            font-size: .77rem;
            padding: 3px 5px;
            height: 28px;
            border-radius: 4px;
            color: #222 !important;
            background-color: #fff !important;
        }
        #addRowsTable .form-control::placeholder { color: #777; }
        .row-num-cell { font-size: .72rem; color: #a0a0a0; text-align: center; font-variant-numeric: tabular-nums; }
        .btn-del-row { width: 23px; height: 23px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; font-size: .78rem; line-height: 1; }
        #addRowsEmpty td { padding: 28px; text-align: center; color: #bbb; font-size: .83rem; }
        .select-cat { border-color: #b8c8f0 !important; }
        .select-dt { border-color: #b8e0d4 !important; }
        .select-qual { border-color: #f0d8a8 !important; }
        .add-page-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #dee2e6; background: #fafafa; }
        tr.row-invalid { outline: 2px solid #dc3545; }
        .recent-table-wrap { margin-top: 18px; border: 1px solid #d8dfea; border-radius: 12px; overflow: hidden; background: #fff; }
        .recent-title { padding: 12px 16px; border-bottom: 1px solid #e7ecf4; background: #f7f9fd; font-weight: 700; color: #1d3359; }
        .recent-table { min-width: 1320px; margin: 0; }
        .recent-table thead th { white-space: nowrap; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
        .img-mini { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid #ced4da; background: #fff; }
    </style>
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

    <div class="add-page-wrap">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h1 class="page-title mb-1">Add Documents</h1>
                <p class="page-subtitle mb-0"><?= htmlspecialchars($activeSystem['name']) ?> — full-page entry view</p>
            </div>
            <a href="documents_tracking.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Tracking</a>
        </div>

        <form method="POST" id="addMultipleForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="add_multiple_documents">
            <div class="add-card">
                <div class="add-card-header">
                    <h2 class="add-card-title">Document Entry</h2>
                    <p class="add-card-subtitle">Each row has its own Category → Doc Type → Qualification. Scroll horizontally to see every field.</p>
                </div>

                <div class="prefill-banner">
                    <div class="prefill-banner-title"><i class="bi bi-lightning-fill me-1"></i>Quick prefill — auto-fills all new rows when set</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Category</label>
                            <select id="prefill_category" class="form-select form-select-sm select-cat">
                                <option value="">— Pick to prefill —</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-1">Document Type</label>
                            <select id="prefill_doctype" class="form-select form-select-sm select-dt">
                                <option value="">— Pick category first —</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Qualification</label>
                            <select id="prefill_qualification" class="form-select form-select-sm select-qual">
                                <option value="">— None —</option>
                                <?php foreach ($qualifications as $q): ?>
                                    <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-1">Date Submitted</label>
                            <input type="date" name="date_submission_shared" id="prefill_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-primary w-100" id="btnApplyPrefill">
                                <i class="bi bi-arrow-down-circle me-1"></i>Apply to All Rows
                            </button>
                        </div>
                    </div>
                    <div class="prefill-hint"><i class="bi bi-info-circle me-1"></i>Selecting a Category filters each row's Doc Type list.</div>
                </div>

                <div class="rows-section">
                    <div class="rows-section-top">
                        <span class="rows-section-label"><i class="bi bi-table me-1"></i>Document rows <span class="badge bg-secondary ms-1" id="rowCountBadge">0</span></span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAdd1"><i class="bi bi-plus-lg me-1"></i>Add Row</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd5">+ 5 Rows</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd10">+ 10 Rows</button>
                        </div>
                    </div>

                    <div class="rows-scroll-wrap">
                        <table class="table table-sm table-bordered table-hover" id="addRowsTable">
                            <thead>
                            <tr>
                                <th style="width:28px">#</th>
                                <th style="min-width:150px">Category <span class="text-danger">*</span></th>
                                <th style="min-width:145px">Doc Type <span class="text-danger">*</span></th>
                                <th style="min-width:140px">Qualification</th>
                                <th style="min-width:165px">Sub-Document</th>
                                <th style="min-width:130px">Batch No.</th>
                                <th style="min-width:132px">Date Submitted</th>
                                <th style="min-width:132px">Received (TESDA)</th>
                                <th style="min-width:132px">Returned (Center)</th>
                                <th style="min-width:128px">Staff Received</th>
                                <th style="min-width:132px">Date Assessment</th>
                                <th style="min-width:128px">Assessor Name</th>
                                <th style="min-width:132px">TESDA Released</th>
                                <th style="min-width:140px">Remarks</th>
                                <th style="min-width:180px">Image/PDF</th>
                                <th style="width:34px"></th>
                            </tr>
                            </thead>
                            <tbody id="addRowsTbody">
                            <tr id="addRowsEmpty"><td colspan="16">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="add-page-footer">
                    <button type="button" class="btn btn-sm btn-link text-danger px-0" id="btnClearRows"><i class="bi bi-trash me-1"></i>Clear all rows</button>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="text-muted" style="font-size:.78rem" id="saveCountLabel"></span>
                        <button type="submit" class="btn btn-tb5-primary"><i class="bi bi-check-lg me-1"></i>Save Documents</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="recent-table-wrap mt-4">
            <div class="recent-title d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span><i class="bi bi-clock-history me-1"></i>Recently Added Documents</span>
                <small class="text-muted">Latest 50 active records</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle recent-table">
                    <thead class="table-light">
                    <tr>
                        <th>Category</th>
                        <th>Qualification</th>
                        <th>Doc Type</th>
                        <th>Sub-Document</th>
                        <th>Batch</th>
                        <th>Date Submitted</th>
                        <th>Received</th>
                        <th>Returned</th>
                        <th>Staff Received</th>
                        <th>Date Assessment</th>
                        <th>Assessor</th>
                        <th>TESDA Released</th>
                        <th>Remarks</th>
                        <th>Image</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentDocuments): ?>
                        <tr><td colspan="14" class="text-center text-muted py-4">No records yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentDocuments as $doc):
                            $dv = fn($v) => htmlspecialchars($v ?? '');
                            $df = fn($d) => $d ? date('m/d/Y', strtotime($d)) : '—';
                        ?>
                        <tr>
                            <td><?= $dv($doc['cat_name']) ?: '—' ?></td>
                            <td><?= $dv($doc['qual_name']) ?: '—' ?></td>
                            <td><?= $dv($doc['doc_type_name']) ?: '—' ?></td>
                            <td><?= $dv($doc['document_sub']) ?: '—' ?></td>
                            <td><?= $dv($doc['batch_no']) ?: '—' ?></td>
                            <td><?= $df($doc['date_submission']) ?></td>
                            <td><?= $df($doc['received_tesda']) ?></td>
                            <td><?= $df($doc['returned_center']) ?></td>
                            <td><?= $df($doc['staff_received']) ?></td>
                            <td><?= $df($doc['date_assessment']) ?></td>
                            <td><?= $dv($doc['assessor_name']) ?: '—' ?></td>
                            <td><?= $df($doc['tesda_released']) ?></td>
                            <td><?= $dv($doc['remarks']) ?: '—' ?></td>
                            <td>
                                <?php if (!empty($doc['image_path'])): ?>
                                    <a href="../<?= htmlspecialchars($doc['image_path']) ?>" target="_blank" rel="noopener">
                                        <img src="../<?= htmlspecialchars($doc['image_path']) ?>" alt="doc" class="img-mini" onerror="this.style.display='none'">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
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
<script>
const DT_BY_CAT = <?= json_encode($dtByCat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DT_ALL    = <?= json_encode($dtAll, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const QUALS     = <?= json_encode(array_map(fn($q)=>['id'=>$q['id'],'name'=>$q['name']], $qualifications), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DOC_SUBS  = <?= json_encode($docSubs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const CATS_PHP  = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']], $categories), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

(function(){
    let rowSeq = 0;

    function fillDtSelect(sel, catId, selectedVal) {
        const list = (catId && DT_BY_CAT[catId]) ? DT_BY_CAT[catId] : DT_ALL;
        sel.innerHTML = '<option value="">— Select —</option>';
        list.forEach(dt => {
            const o = new Option(dt.name, dt.id, false, String(dt.id) === String(selectedVal));
            sel.appendChild(o);
        });
    }

    function fillQualSelect(sel, selectedVal) {
        sel.innerHTML = '<option value="">— None —</option>';
        QUALS.forEach(q => {
            const o = new Option(q.name, q.id, false, String(q.id) === String(selectedVal));
            sel.appendChild(o);
        });
    }

    function fillSubDocs(sel, docTypeId, selectedVal) {
        sel.innerHTML = '<option value="">— None —</option>';
        if (docTypeId && DOC_SUBS[docTypeId]) {
            DOC_SUBS[docTypeId].forEach(name => {
                const o = new Option(name, name, false, name === selectedVal);
                sel.appendChild(o);
            });
        }
    }

    function makeRow(def) {
        def = def || {};
        rowSeq++;

        const tr = document.createElement('tr');
        tr.dataset.seq = rowSeq;

        const catSel = document.createElement('select');
        catSel.name = 'row_category_id[]';
        catSel.className = 'form-select form-select-sm select-cat';
        catSel.innerHTML = '<option value="">— Select —</option>';
        CATS_PHP.forEach(c => catSel.appendChild(new Option(c.name, c.id, false, String(c.id) === String(def.catId || ''))));

        const dtSel = document.createElement('select');
        dtSel.name = 'row_document_type_id[]';
        dtSel.className = 'form-select form-select-sm select-dt';
        fillDtSelect(dtSel, def.catId || '', def.dtId || '');

        const qualSel = document.createElement('select');
        qualSel.name = 'row_qualification_id[]';
        qualSel.className = 'form-select form-select-sm select-qual';
        fillQualSelect(qualSel, def.qualId || '');

        const subSel = document.createElement('select');
        subSel.name = 'row_document_sub[]';
        subSel.className = 'form-select form-select-sm';
        fillSubDocs(subSel, def.dtId || '', def.subDoc || '');

        const remarksSel = document.createElement('select');
        remarksSel.name = 'remarks[]';
        remarksSel.className = 'form-select form-select-sm';
        remarksSel.innerHTML =
            '<option value="">—</option>' +
            '<option value="received">Received</option>' +
            '<option value="returned">Returned</option>';
        if (def.remarks) remarksSel.value = def.remarks;

        const inp = (name, type, ph) => {
            const el = document.createElement('input');
            el.type = type || 'text';
            el.name = name + '[]';
            el.className = 'form-control form-control-sm';
            if (ph) el.placeholder = ph;
            return el;
        };

        const imgInp = document.createElement('input');
        imgInp.type = 'file';
        imgInp.name = 'row_image[]';
        imgInp.className = 'form-control form-control-sm';
        imgInp.accept = 'image/*,.pdf';

        const receivedInp = inp('received_tesda', 'date');
        const returnedInp = inp('returned_center', 'date');
        receivedInp.addEventListener('change', function(){ returnedInp.value = this.value; });
        returnedInp.addEventListener('change', function(){ receivedInp.value = this.value; });

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-outline-danger btn-del-row';
        del.title = 'Remove';
        del.innerHTML = '<i class="bi bi-x"></i>';

        catSel.addEventListener('change', function(){
            fillDtSelect(dtSel, this.value, '');
            fillSubDocs(subSel, '', '');
        });

        dtSel.addEventListener('change', function(){ fillSubDocs(subSel, this.value, ''); });

        const numTd = document.createElement('td');
        numTd.className = 'row-num-cell';

        const wrap = (el) => {
            const td = document.createElement('td');
            td.appendChild(el);
            return td;
        };

        const dateSub = inp('date_submission', 'date');
        if (def.dateSubmission) dateSub.value = def.dateSubmission;

        [
            numTd,
            wrap(catSel),
            wrap(dtSel),
            wrap(qualSel),
            wrap(subSel),
            wrap(inp('batch_no', 'text', 'e.g. 51401-001')),
            wrap(dateSub),
            wrap(receivedInp),
            wrap(returnedInp),
            wrap(inp('staff_received', 'date')),
            wrap(inp('date_assessment', 'date')),
            wrap(inp('assessor_name', 'text')),
            wrap(inp('tesda_released', 'date')),
            wrap(remarksSel),
            wrap(imgInp),
            wrap(del)
        ].forEach(td => tr.appendChild(td));

        return tr;
    }

    function reindex() {
        document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty) .row-num-cell').forEach((td, i) => {
            td.textContent = i + 1;
        });
    }

    function syncCount() {
        const n = document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty)').length;
        document.getElementById('rowCountBadge').textContent = n;
        document.getElementById('saveCountLabel').textContent = n > 0 ? `${n} row${n !== 1 ? 's' : ''} queued` : '';
    }

    function showEmpty() {
        document.getElementById('addRowsTbody').innerHTML = '<tr id="addRowsEmpty"><td colspan="16">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>';
        syncCount();
    }

    function addRows(n, def) {
        const tbody = document.getElementById('addRowsTbody');
        const empty = document.getElementById('addRowsEmpty');
        if (empty) empty.remove();
        for (let i = 0; i < n; i++) tbody.appendChild(makeRow(def || {}));
        reindex();
        syncCount();
    }

    function getDataRows() {
        const tbody = document.getElementById('addRowsTbody');
        if (!tbody) return [];
        return Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.id !== 'addRowsEmpty');
    }

    function getPrefill() {
        return {
            catId: document.getElementById('prefill_category').value,
            dtId: document.getElementById('prefill_doctype').value,
            qualId: document.getElementById('prefill_qualification').value,
            dateSubmission: document.getElementById('prefill_date').value
        };
    }

    function applyPrefillToAll() {
        const p = getPrefill();
        const rows = getDataRows();
        if (rows.length === 0) {
            addRows(1, p);
            return;
        }

        rows.forEach(tr => {
            const catSel = tr.querySelector('[name="row_category_id[]"]');
            const dtSel = tr.querySelector('[name="row_document_type_id[]"]');
            const qualSel = tr.querySelector('[name="row_qualification_id[]"]');
            const dateSub = tr.querySelector('[name="date_submission[]"]');

            if (catSel && dtSel) {
                catSel.value = p.catId;
                fillDtSelect(dtSel, p.catId, p.dtId);
            }
            if (qualSel) qualSel.value = p.qualId;
            if (dateSub) dateSub.value = p.dateSubmission || '';
        });
    }

    function resetFormRows() {
        rowSeq = 0;
        showEmpty();
        document.getElementById('prefill_category').value = '';
        document.getElementById('prefill_doctype').innerHTML = '<option value="">— Pick category first —</option>';
        document.getElementById('prefill_qualification').value = '';
        document.getElementById('prefill_date').value = '';
    }

    document.addEventListener('DOMContentLoaded', function(){
        document.getElementById('prefill_category').addEventListener('change', function(){
            fillDtSelect(document.getElementById('prefill_doctype'), this.value, '');
        });

        document.getElementById('btnApplyPrefill').addEventListener('click', applyPrefillToAll);
        document.getElementById('btnAdd1').addEventListener('click', () => addRows(1, getPrefill()));
        document.getElementById('btnAdd5').addEventListener('click', () => addRows(5, getPrefill()));
        document.getElementById('btnAdd10').addEventListener('click', () => addRows(10, getPrefill()));
        document.getElementById('btnClearRows').addEventListener('click', () => {
            if (confirm('Clear all rows?')) resetFormRows();
        });

        document.getElementById('addRowsTbody').addEventListener('click', function(e){
            const btn = e.target.closest('.btn-del-row');
            if (!btn) return;
            btn.closest('tr').remove();
            reindex();
            syncCount();
            if (!document.querySelector('#addRowsTbody tr:not(#addRowsEmpty)')) showEmpty();
        });

        document.getElementById('addMultipleForm').addEventListener('submit', function(e){
            const rows = document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty)');
            if (rows.length === 0) {
                e.preventDefault();
                alert('Add at least one row before saving.');
                return;
            }

            let bad = false;
            rows.forEach(tr => {
                const cat = tr.querySelector('[name="row_category_id[]"]').value;
                const dt = tr.querySelector('[name="row_document_type_id[]"]').value;
                if (!cat || !dt) {
                    tr.classList.add('row-invalid');
                    bad = true;
                } else {
                    tr.classList.remove('row-invalid');
                }
            });

            if (bad) {
                e.preventDefault();
                alert('Rows highlighted in red are missing a Category or Document Type.');
            }
        });

        addRows(1);
    });
})();
</script>
</body>
</html>
