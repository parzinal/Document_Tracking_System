<?php
// =========================================================
// Add Documents (Full Page)
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';

$sid = (int)$_SESSION['active_system_id'];
$flash = '';
$flashType = 'success';

function parseStoredImagePaths(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') return [];

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $list = [];
        foreach ($decoded as $path) {
            $path = trim((string)$path);
            if ($path !== '') $list[] = $path;
        }
        return $list;
    }

    return [$raw];
}

function encodeStoredImagePaths(array $paths): ?string {
    $list = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path !== '') $list[] = $path;
    }

    if (!$list) return null;
    if (count($list) === 1) return $list[0];

    $encoded = json_encode(array_values($list), JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to store uploaded row files.');
    }
    if (strlen($encoded) > 300) {
        throw new RuntimeException('Too many files in one row. Keep up to 5 files per row.');
    }

    return $encoded;
}

function handleRowUploads(string $rowKey): array {
    if ($rowKey === '') return [];

    $names = $_FILES['row_image']['name'][$rowKey] ?? null;
    if (!is_array($names) || !$names) return [];
    if (count($names) > 5) {
        throw new RuntimeException('Only up to 5 files are allowed per row.');
    }

    $tmpNames = $_FILES['row_image']['tmp_name'][$rowKey] ?? [];
    $errors = $_FILES['row_image']['error'][$rowKey] ?? [];
    $sizes = $_FILES['row_image']['size'][$rowKey] ?? [];

    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $paths = [];

    foreach ($names as $idx => $original) {
        $error = $errors[$idx] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the row files failed to upload.');
        }

        $tmp = (string)($tmpNames[$idx] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $size = (int)($sizes[$idx] ?? 0);
        if ($size <= 0) continue;

        $mime = $finfo->file($tmp);
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Invalid file type in one of the row attachments.');
        }
        if ($size > 5 * 1024 * 1024) {
            throw new RuntimeException('One of the row attachments is too large (max 5 MB).');
        }

        $ext = strtolower(pathinfo((string)$original, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
        }

        $filename = uniqid('doc_', true) . '.' . $ext;
        $dest = __DIR__ . '/../assets/upload/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Failed to save one of the row attachments.');
        }

        $paths[] = 'assets/upload/' . $filename;
    }

    return $paths;
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
        $date_submissions  = $_POST['date_submission']      ?? [];
        $received_tesdas   = $_POST['received_tesda']       ?? [];
        $returned_centers  = $_POST['returned_center']      ?? [];
        $staff_receiveds   = $_POST['staff_received']       ?? [];
        $date_assessments  = $_POST['date_assessment']      ?? [];
        $assessor_names    = $_POST['assessor_name']        ?? [];
        $tesda_releaseds   = $_POST['tesda_released']       ?? [];
        $row_file_keys     = $_POST['row_file_key']         ?? [];

        $ins = $pdo->prepare('INSERT INTO documents
            (system_id,category_id,document_type_id,document_sub,qualification_id,
             date_submission,batch_no,remarks,received_tesda,returned_center,
             staff_received,date_assessment,assessor_name,tesda_released,image_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        $rowTotal = max(
            count($category_ids),
            count($doctype_ids),
            count($date_submissions),
            count($batch_nos),
            count($remarks_arr),
            count($row_file_keys)
        );

        try {
            for ($i = 0; $i < $rowTotal; $i++) {
                $cat_id = intval($category_ids[$i] ?? 0) ?: null;
                $dt_id = intval($doctype_ids[$i] ?? 0) ?: null;
                $qual_id = intval($qualification_ids[$i] ?? 0) ?: null;
                $sub_doc = isset($sub_docs[$i]) ? trim($sub_docs[$i]) ?: null : null;
                $batch = trim($batch_nos[$i] ?? '') ?: null;
                $rem = trim($remarks_arr[$i] ?? '') ?: null;
                $imgPaths = handleRowUploads((string)($row_file_keys[$i] ?? ''));
                $imgPath = encodeStoredImagePaths($imgPaths);

                $hasOtherData = (
                    $cat_id !== null ||
                    $dt_id !== null ||
                    $qual_id !== null ||
                    $sub_doc !== null ||
                    $batch !== null ||
                    $rem !== null ||
                    ($date_submissions[$i] ?? '') !== '' ||
                    ($received_tesdas[$i] ?? '') !== '' ||
                    ($returned_centers[$i] ?? '') !== '' ||
                    ($staff_receiveds[$i] ?? '') !== '' ||
                    ($date_assessments[$i] ?? '') !== '' ||
                    trim($assessor_names[$i] ?? '') !== '' ||
                    ($tesda_releaseds[$i] ?? '') !== '' ||
                    !empty($imgPaths)
                );

                if (!$hasOtherData) {
                    continue;
                }

                if ($cat_id === null || $dt_id === null) {
                    continue;
                }

                $rowDateSubmission = ($date_submissions[$i] ?? '') ?: ($date_sub_shared ?: null);

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
        .theme-bigfive .add-page-wrap {
            --required-warn: #6f8db8;
            --required-warn-soft: #f4f8ff;
            --required-warn-border: rgba(111, 141, 184, 0.36);
        }
        .theme-blossom .add-page-wrap {
            --accent: #a45555;
            --accent-dark: #321415;
            --accent-mid: #6f2d2d;
            --accent-light: #f8efef;
            --accent-glow: rgba(164, 85, 85, 0.26);
            --accent-gradient: linear-gradient(135deg, #321415 0%, #6f2d2d 54%, #a45555 100%);
            --required-warn: #bc6d6d;
            --required-warn-soft: #fff7f7;
            --required-warn-border: rgba(188, 109, 109, 0.34);
        }
        .add-page-wrap { max-width: 1680px; margin: 0 auto; }
        .add-card { border: 1px solid var(--accent-light, #d8dfea); border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 8px 28px rgba(20, 40, 90, .08); }
        .add-card-header { background: var(--accent-gradient, linear-gradient(120deg, #112d58, #365f95)); color: #fff; padding: 18px 22px; }
        .add-card-title { font-size: 1.45rem; margin: 0; font-weight: 700; letter-spacing: .01em; }
        .add-card-subtitle { margin: 5px 0 0; color: rgba(255, 255, 255, .75); font-size: .9rem; }
        .prefill-banner { background: var(--accent-light, #f0f5ff); border-bottom: 1px solid var(--accent-glow, #d0dbf0); padding: 14px 20px 12px; }
        .prefill-banner-title { font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--accent-mid, #5566aa); margin-bottom: 8px; }
        .prefill-hint { font-size: .76rem; color: var(--accent-mid, #6073a8); margin-top: 6px; }
        .rows-section { padding: 12px 20px 6px; }
        .rows-section-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; gap: 8px; flex-wrap: wrap; }
        .rows-section-label { font-size: .74rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #555; }
        .rows-scroll-wrap { overflow-x: auto; overflow-y: auto; max-height: 540px; border: 1px solid var(--accent-light, #dee2e6); border-radius: 8px; }
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
        .required-star { color: #b45a5a; font-weight: 700; }
        .theme-bigfive .required-star { color: #3c6cab; }
        .select-cat,
        .select-dt { border-color: rgba(99, 142, 203, .36) !important; }
        .select-qual { border-color: #d9dee7 !important; }
        .theme-blossom .select-cat,
        .theme-blossom .select-dt {
            border-color: rgba(164, 85, 85, .34) !important;
            background-color: #fffafa !important;
        }
        .theme-blossom .select-qual {
            border-color: #d7c8c8 !important;
            background-color: #fff !important;
        }
        .theme-blossom .select-cat:focus,
        .theme-blossom .select-dt:focus {
            border-color: #a45555 !important;
            box-shadow: 0 0 0 .15rem rgba(164, 85, 85, .22) !important;
        }
        .add-page-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #dee2e6; background: #fafafa; }
        tr.row-invalid td {
            background: var(--required-warn-soft, #fff7f7);
            border-top-color: var(--required-warn-border, rgba(188, 109, 109, 0.34)) !important;
            border-bottom-color: var(--required-warn-border, rgba(188, 109, 109, 0.34)) !important;
        }
        tr.row-invalid td:first-child {
            border-left: 3px solid var(--required-warn, #bc6d6d) !important;
        }
        tr.row-invalid td:last-child {
            border-right: 1px solid var(--required-warn-border, rgba(188, 109, 109, 0.34)) !important;
        }
        .recent-table-wrap { margin-top: 18px; border: 1px solid var(--accent-light, #d8dfea); border-radius: 12px; overflow: hidden; background: #fff; }
        .recent-title { padding: 12px 16px; border-bottom: 1px solid var(--accent-light, #e7ecf4); background: var(--accent-light, #f7f9fd); font-weight: 700; color: var(--accent-dark, #1d3359); }
        .recent-table { min-width: 1320px; margin: 0; }
        .recent-table thead th { white-space: nowrap; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
        .img-mini { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid #ced4da; background: #fff; }
        .file-cell-wrap { min-width: 228px; }
        .file-cell-wrap .form-control { height: 28px; }
        .btn-file-preview { width: 100%; margin-top: 4px; font-size: .7rem; padding: 2px 6px; }
        .file-select-note {
            margin-top: 4px;
            font-size: .67rem;
            color: #6c757d;
            line-height: 1.2;
        }
        .file-preview-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }
        .file-preview-item {
            border: 1px solid #d6dee8;
            border-radius: 7px;
            background: #fff;
            padding: 2px;
        }
        .file-preview-item .img-mini { width: 34px; height: 34px; }
        .file-preview-item .pdf-chip { min-width: 46px; height: 34px; font-size: .65rem; }
        .file-count-note {
            display: block;
            margin-top: 3px;
            font-size: .66rem;
            color: #6c757d;
        }
        .preview-trigger { background: transparent; border: 0; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
        .preview-trigger:focus-visible { outline: 2px solid var(--accent, #0d6efd); outline-offset: 2px; border-radius: 8px; }
        .pdf-chip {
            min-width: 56px;
            height: 42px;
            border-radius: 6px;
            border: 1px solid #f5c2c7;
            background: #fff5f5;
            color: #b4232f;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .03em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 0 8px;
        }
        #docPreviewModal .modal-dialog { max-width: 980px; }
        #docPreviewViewport {
            min-height: 62vh;
            max-height: 74vh;
            border: 1px solid var(--accent-light, #d9e2f0);
            border-radius: 10px;
            background: var(--accent-light, #f8fbff);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        #docPreviewViewport img {
            max-width: 100%;
            max-height: 72vh;
            object-fit: contain;
            display: block;
        }
        #docPreviewViewport iframe {
            width: 100%;
            height: 72vh;
            border: 0;
            background: #fff;
        }
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
                            <button type="button" class="btn btn-sm btn-tb5-primary w-100" id="btnApplyPrefill">
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
                            <button type="button" class="btn btn-sm btn-outline-tb5" id="btnAdd1"><i class="bi bi-plus-lg me-1"></i>Add Row</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd5">+ 5 Rows</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd10">+ 10 Rows</button>
                        </div>
                    </div>

                    <div class="rows-scroll-wrap">
                        <table class="table table-sm table-bordered table-hover" id="addRowsTable">
                            <thead>
                            <tr>
                                <th style="width:28px">#</th>
                                <th style="min-width:150px">Category <span class="required-star">*</span></th>
                                <th style="min-width:145px">Doc Type <span class="required-star">*</span></th>
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
                                <th style="min-width:228px">Images / PDFs</th>
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
                            <td><?= $dv($doc['staff_received']) ?: '—' ?></td>
                            <td><?= $df($doc['date_assessment']) ?></td>
                            <td><?= $dv($doc['assessor_name']) ?: '—' ?></td>
                            <td><?= $df($doc['tesda_released']) ?></td>
                            <td><?= $dv($doc['remarks']) ?: '—' ?></td>
                            <td>
                                <?php $docFiles = parseStoredImagePaths($doc['image_path'] ?? null); ?>
                                <?php if ($docFiles): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($docFiles as $idx => $path):
                                            $isPdf = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)) === 'pdf';
                                            $previewType = $isPdf ? 'pdf' : 'image';
                                        ?>
                                            <button
                                                type="button"
                                                class="preview-trigger js-preview-doc file-preview-item"
                                                data-preview-url="../<?= htmlspecialchars($path) ?>"
                                                data-preview-type="<?= $previewType ?>"
                                                title="Preview file <?= $idx + 1 ?>"
                                            >
                                                <?php if ($isPdf): ?>
                                                    <span class="pdf-chip"><i class="bi bi-file-earmark-pdf"></i>PDF</span>
                                                <?php else: ?>
                                                    <img src="../<?= htmlspecialchars($path) ?>" alt="doc" class="img-mini" onerror="this.style.display='none'">
                                                <?php endif; ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($docFiles) > 1): ?>
                                        <small class="file-count-note"><?= count($docFiles) ?> files</small>
                                    <?php endif; ?>
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

<div class="modal fade" id="docPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-richtext me-2"></i>Document Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="docPreviewViewport">
                    <div id="docPreviewPlaceholder" class="text-muted">No preview available.</div>
                    <img id="docPreviewImage" class="d-none" alt="Preview">
                    <iframe id="docPreviewFrame" class="d-none" title="Document preview"></iframe>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const DT_BY_CAT = <?= json_encode($dtByCat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DT_ALL    = <?= json_encode($dtAll, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const QUALS     = <?= json_encode(array_map(fn($q)=>['id'=>$q['id'],'name'=>$q['name']], $qualifications), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DOC_SUBS  = <?= json_encode($docSubs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const CATS_PHP  = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']], $categories), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

(function(){
    let rowSeq = 0;
    let currentPreviewUrl = '';

    function detectPreviewType(url, kindHint) {
        if (kindHint === 'pdf' || kindHint === 'application/pdf') return 'pdf';
        if (kindHint && kindHint.startsWith('image/')) return 'image';
        return /\.pdf(?:$|[?#])/i.test(url || '') ? 'pdf' : 'image';
    }

    function openPreview(url, kindHint) {
        if (!url) return;

        const kind = detectPreviewType(url, kindHint || '');
        const modalEl = document.getElementById('docPreviewModal');
        const placeholder = document.getElementById('docPreviewPlaceholder');
        const img = document.getElementById('docPreviewImage');
        const frame = document.getElementById('docPreviewFrame');

        currentPreviewUrl = url;
        placeholder.classList.add('d-none');
        img.classList.add('d-none');
        frame.classList.add('d-none');
        img.removeAttribute('src');
        frame.removeAttribute('src');

        if (kind === 'pdf') {
            frame.src = url;
            frame.classList.remove('d-none');
        } else {
            img.src = url;
            img.classList.remove('d-none');
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

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
        const rowFileKey = String(rowSeq);

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

        const dateSub = inp('date_submission', 'date');
        if (def.dateSubmission) dateSub.value = def.dateSubmission;

        const receivedInp = inp('received_tesda', 'date');
        const returnedInp = inp('returned_center', 'date');
        const syncSubmittedToReceived = () => { receivedInp.value = dateSub.value || ''; };
        dateSub.addEventListener('change', syncSubmittedToReceived);
        if (dateSub.value) syncSubmittedToReceived();

        const imgInp = document.createElement('input');
        imgInp.type = 'file';
        imgInp.name = `row_image[${rowFileKey}][]`;
        imgInp.className = 'form-control form-control-sm';
        imgInp.accept = 'image/*,.pdf';
        imgInp.multiple = true;

        const rowKeyInp = document.createElement('input');
        rowKeyInp.type = 'hidden';
        rowKeyInp.name = 'row_file_key[]';
        rowKeyInp.value = rowFileKey;

        const fileMeta = document.createElement('div');
        fileMeta.className = 'file-select-note';
        fileMeta.textContent = 'No files selected (max 5 per row).';

        const filePreviewList = document.createElement('div');
        filePreviewList.className = 'file-preview-list';

        const previewBtn = document.createElement('button');
        previewBtn.type = 'button';
        previewBtn.className = 'btn btn-outline-primary btn-file-preview js-row-preview';
        previewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Preview first';
        previewBtn.disabled = true;

        imgInp.addEventListener('change', function(){
            if (Array.isArray(this._previewUrls)) {
                this._previewUrls.forEach(url => URL.revokeObjectURL(url));
            }
            this._previewUrls = [];
            filePreviewList.innerHTML = '';
            previewBtn.disabled = true;
            previewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Preview first';

            const files = Array.from(this.files || []);
            if (!files.length) {
                fileMeta.textContent = 'No files selected (max 5 per row).';
                return;
            }

            if (files.length > 5) {
                showToast('Only up to 5 files are allowed per row.', 'warning');
                this.value = '';
                fileMeta.textContent = 'No files selected (max 5 per row).';
                return;
            }

            files.forEach((file, idx) => {
                const url = URL.createObjectURL(file);
                this._previewUrls.push(url);
                const typeHint = file.type || (/\.pdf$/i.test(file.name || '') ? 'pdf' : 'image');

                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'preview-trigger js-row-file-item file-preview-item';
                item.dataset.previewUrl = url;
                item.dataset.previewType = typeHint;
                item.title = file.name || ('Attachment ' + (idx + 1));

                if (typeHint === 'pdf' || typeHint.toLowerCase() === 'application/pdf') {
                    item.innerHTML = '<span class="pdf-chip"><i class="bi bi-file-earmark-pdf"></i>PDF</span>';
                } else {
                    const thumb = document.createElement('img');
                    thumb.className = 'img-mini';
                    thumb.alt = 'attachment';
                    thumb.src = url;
                    item.appendChild(thumb);
                }

                filePreviewList.appendChild(item);
            });

            fileMeta.textContent = files.length + ' file(s) selected.';
            previewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Preview first (' + files.length + ')';
            previewBtn.disabled = false;
        });

        const fileWrap = document.createElement('div');
        fileWrap.className = 'file-cell-wrap';
        fileWrap.appendChild(rowKeyInp);
        fileWrap.appendChild(imgInp);
        fileWrap.appendChild(previewBtn);
        fileWrap.appendChild(fileMeta);
        fileWrap.appendChild(filePreviewList);

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
            wrap(inp('staff_received', 'text', 'Staff name')),
            wrap(inp('date_assessment', 'date')),
            wrap(inp('assessor_name', 'text')),
            wrap(inp('tesda_released', 'date')),
            wrap(remarksSel),
            wrap(fileWrap),
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

    function cleanupRowPreviewUrls(root) {
        (root || document).querySelectorAll('input[type="file"][name^="row_image["]').forEach(inp => {
            if (Array.isArray(inp._previewUrls)) {
                inp._previewUrls.forEach(url => URL.revokeObjectURL(url));
                inp._previewUrls = [];
            }
        });
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
            const receivedInp = tr.querySelector('[name="received_tesda[]"]');

            if (catSel && dtSel) {
                catSel.value = p.catId;
                fillDtSelect(dtSel, p.catId, p.dtId);
            }
            if (qualSel) qualSel.value = p.qualId;
            if (dateSub) {
                dateSub.value = p.dateSubmission || '';
                if (receivedInp) receivedInp.value = dateSub.value;
            }
        });
    }

    function resetFormRows() {
        cleanupRowPreviewUrls(document.getElementById('addRowsTbody'));
        rowSeq = 0;
        showEmpty();
        document.getElementById('prefill_category').value = '';
        document.getElementById('prefill_doctype').innerHTML = '<option value="">— Pick category first —</option>';
        document.getElementById('prefill_qualification').value = '';
        document.getElementById('prefill_date').value = '';
    }

    document.addEventListener('DOMContentLoaded', function(){
        const previewModalEl = document.getElementById('docPreviewModal');
        const previewImg = document.getElementById('docPreviewImage');
        const previewFrame = document.getElementById('docPreviewFrame');
        const previewPlaceholder = document.getElementById('docPreviewPlaceholder');

        document.getElementById('prefill_category').addEventListener('change', function(){
            fillDtSelect(document.getElementById('prefill_doctype'), this.value, '');
        });

        previewModalEl.addEventListener('hidden.bs.modal', function(){
            currentPreviewUrl = '';
            previewImg.classList.add('d-none');
            previewFrame.classList.add('d-none');
            previewImg.removeAttribute('src');
            previewFrame.removeAttribute('src');
            previewPlaceholder.classList.remove('d-none');
        });

        document.addEventListener('click', function(e){
            const trigger = e.target.closest('.js-preview-doc');
            if (!trigger) return;
            openPreview(trigger.dataset.previewUrl || '', trigger.dataset.previewType || '');
        });

        document.getElementById('btnApplyPrefill').addEventListener('click', applyPrefillToAll);
        document.getElementById('btnAdd1').addEventListener('click', () => addRows(1, getPrefill()));
        document.getElementById('btnAdd5').addEventListener('click', () => addRows(5, getPrefill()));
        document.getElementById('btnAdd10').addEventListener('click', () => addRows(10, getPrefill()));
        document.getElementById('btnClearRows').addEventListener('click', () => {
            showConfirmModal('Clear all rows?', {
                title: 'Clear Rows',
                confirmText: 'Clear',
                confirmClass: 'btn btn-danger'
            }).then(function (ok) {
                if (ok) resetFormRows();
            });
        });

        document.getElementById('addRowsTbody').addEventListener('click', function(e){
            const previewItem = e.target.closest('.js-row-file-item');
            if (previewItem) {
                openPreview(previewItem.dataset.previewUrl || '', previewItem.dataset.previewType || '');
                return;
            }

            const previewBtn = e.target.closest('.js-row-preview');
            if (previewBtn) {
                const row = previewBtn.closest('tr');
                const fileInput = row ? row.querySelector('input[type="file"][name^="row_image["]') : null;
                const firstUrl = fileInput && Array.isArray(fileInput._previewUrls) ? fileInput._previewUrls[0] : '';
                const firstFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                const firstType = firstFile ? (firstFile.type || (/\.pdf$/i.test(firstFile.name || '') ? 'pdf' : 'image')) : '';
                if (!firstUrl) return;

                openPreview(firstUrl, firstType);
                return;
            }

            const btn = e.target.closest('.btn-del-row');
            if (!btn) return;
            const row = btn.closest('tr');
            if (!row) return;
            cleanupRowPreviewUrls(row);
            row.remove();
            reindex();
            syncCount();
            if (!document.querySelector('#addRowsTbody tr:not(#addRowsEmpty)')) showEmpty();
        });

        document.getElementById('addMultipleForm').addEventListener('submit', function(e){
            const rows = document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty)');
            if (rows.length === 0) {
                e.preventDefault();
                showToast('Add at least one row before saving.', 'warning');
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
                showToast('Highlighted rows are missing a Category or Document Type.', 'warning');
            }
        });

        window.addEventListener('beforeunload', function(){
            cleanupRowPreviewUrls(document.getElementById('addRowsTbody'));
        });

        addRows(1);
    });
})();
</script>
</body>
</html>
