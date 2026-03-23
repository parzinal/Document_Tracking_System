<?php
// =========================================================
// Documents Tracking
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
$sid = (int)$_SESSION['active_system_id'];

$flash     = '';
$flashType = 'success';

$billCatsStmt = $pdo->prepare("SELECT id FROM categories WHERE system_id=? AND LOWER(name) LIKE ?");
$billCatsStmt->execute([$sid, '%billing%']);
$billingCatIds = $billCatsStmt->fetchAll(PDO::FETCH_COLUMN);

function handleUpload(): ?string {
    if (empty($_FILES['doc_image']['name'])) return null;
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($_FILES['doc_image']['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new RuntimeException('Invalid file type.');
    if ($_FILES['doc_image']['size'] > 5*1024*1024) throw new RuntimeException('File too large. Max 5 MB.');
    $ext      = pathinfo($_FILES['doc_image']['name'], PATHINFO_EXTENSION);
    $filename = uniqid('doc_', true) . '.' . strtolower($ext);
    move_uploaded_file($_FILES['doc_image']['tmp_name'], __DIR__ . '/../assets/upload/' . $filename);
    return 'assets/upload/' . $filename;
}

function normalizeRemark($value): ?string {
    $v = strtolower(trim((string)$value));
    return in_array($v, ['received', 'returned'], true) ? $v : null;
}

if (isset($_GET['archive_id'])) {
    $pdo->prepare("UPDATE documents SET is_archived=1 WHERE id=? AND system_id=?")->execute([(int)$_GET['archive_id'], $sid]);
    header('Location: documents_tracking.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_multiple_documents') {
        $date_sub_shared   = $_POST['date_submission_shared'] ?: null;
        $category_ids      = $_POST['row_category_id']        ?? [];
        $doctype_ids       = $_POST['row_document_type_id']   ?? [];
        $qualification_ids = $_POST['row_qualification_id']   ?? [];
        $sub_docs          = $_POST['row_document_sub']       ?? [];
        $batch_nos         = $_POST['batch_no']               ?? [];
        $remarks_arr       = $_POST['remarks']                ?? [];
        $received_tesdas   = $_POST['received_tesda']         ?? [];
        $returned_centers  = $_POST['returned_center']        ?? [];
        $staff_receiveds   = $_POST['staff_received']         ?? [];
        $date_assessments  = $_POST['date_assessment']        ?? [];
        $assessor_names    = $_POST['assessor_name']          ?? [];
        $tesda_releaseds   = $_POST['tesda_released']         ?? [];

        $ins = $pdo->prepare('INSERT INTO documents
            (system_id,category_id,document_type_id,document_sub,qualification_id,
             date_submission,batch_no,remarks,received_tesda,returned_center,
             staff_received,date_assessment,assessor_name,tesda_released)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        for ($i = 0, $n = count($batch_nos); $i < $n; $i++) {
            $batch = trim($batch_nos[$i]) ?: null;
            $rem   = normalizeRemark($remarks_arr[$i] ?? '');
            if ($batch === null && $rem === null) continue;
            $cat_id  = intval($category_ids[$i]      ?? 0) ?: null;
            $dt_id   = intval($doctype_ids[$i]        ?? 0) ?: null;
            $qual_id = intval($qualification_ids[$i]  ?? 0) ?: null;
            $sub_doc = isset($sub_docs[$i]) ? trim($sub_docs[$i]) ?: null : null;
            if (!in_array($cat_id, $billingCatIds, true)) $qual_id = null;
            $ins->execute([$sid,$cat_id,$dt_id,$sub_doc,$qual_id,$date_sub_shared,$batch,$rem,
                $received_tesdas[$i]  ?: null, $returned_centers[$i] ?: null,
                ($staff_receiveds[$i] ?? '') ?: null, $date_assessments[$i] ?: null,
                trim($assessor_names[$i]   ?? '') ?: null, $tesda_releaseds[$i]  ?: null]);
            $count++;
        }
        $flash = $count . ' document(s) added.';
    }

    if ($action === 'edit_core') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM documents WHERE id=? AND system_id=?');
        $chk->execute([$docId, $sid]);
        if ($chk->fetch()) {
            $catId = intval($_POST['category_id']) ?: null;
            $pdo->prepare('UPDATE documents SET category_id=?,document_type_id=?,document_sub=?,qualification_id=?,
                date_submission=?,batch_no=?,remarks=?,updated_at=NOW() WHERE id=? AND system_id=?')
            ->execute([$catId, intval($_POST['document_type_id']) ?: null,
                trim($_POST['document_sub'] ?? '') ?: null,
                (in_array($catId,$billingCatIds,true) ? (intval($_POST['qualification_id']) ?: null) : null),
                $_POST['date_submission'] ?: null,
                trim($_POST['batch_no'] ?? '') ?: null,
                normalizeRemark($_POST['remarks'] ?? null),
                $docId, $sid]);
            $flash = 'Document updated.';
        }
    }

    if ($action === 'upload_image') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM documents WHERE id=? AND system_id=?');
        $chk->execute([$docId, $sid]);
        if ($chk->fetch()) {
            $ex = $pdo->prepare('SELECT image_path FROM documents WHERE id=? AND system_id=?');
            $ex->execute([$docId,$sid]); $er = $ex->fetch();
            if ($er && $er['image_path']) { $flash = 'Already has file. Remove first.'; $flashType='danger'; }
            else { try { $p=handleUpload(); if($p){ $pdo->prepare('UPDATE documents SET image_path=?,updated_at=NOW() WHERE id=? AND system_id=?')->execute([$p,$docId,$sid]); $flash='Image uploaded.'; } } catch(\RuntimeException $e){ $flash=$e->getMessage(); $flashType='danger'; } }
        }
    }

    if ($action === 'bulk_archive') {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        if ($ids) {
            $ph = implode(',', array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE documents SET is_archived=1 WHERE id IN ($ph) AND system_id=?")->execute(array_merge($ids,[$sid]));
            $flash = count($ids).' document(s) archived.';
        }
    }

    header('Location: documents_tracking.php?'.http_build_query(array_filter(['cat'=>$_GET['cat']??'','dt'=>$_GET['dt']??'','qual'=>$_GET['qual']??'']))); exit;
}

$filterCat=(int)($_GET['cat']??0); $filterDt=(int)($_GET['dt']??0); $filterQual=(int)($_GET['qual']??0); $focusDocId=(int)($_GET['doc_id']??0);
$where=['d.system_id=?','d.is_archived=0']; $params=[$sid];
if($filterCat){ $where[]='d.category_id=?'; $params[]=$filterCat; }
if($filterDt){  $where[]='d.document_type_id=?'; $params[]=$filterDt; }
if($filterQual){ $where[]='d.qualification_id=?'; $params[]=$filterQual; }
if($focusDocId){ $where[]='d.id=?'; $params[]=$focusDocId; }
$stmt=$pdo->prepare("SELECT d.*,c.name AS cat_name,dt.name AS doc_type_name,q.name AS qual_name FROM documents d LEFT JOIN categories c ON d.category_id=c.id LEFT JOIN document_types dt ON d.document_type_id=dt.id LEFT JOIN qualifications q ON d.qualification_id=q.id WHERE ".implode(' AND ',$where)." ORDER BY d.created_at DESC");
$stmt->execute($params); $documents=$stmt->fetchAll();

$cats=$pdo->prepare('SELECT * FROM categories WHERE system_id=? ORDER BY name'); $cats->execute([$sid]); $categories=$cats->fetchAll();
$dts=$pdo->prepare('SELECT * FROM document_types WHERE system_id=? ORDER BY name'); $dts->execute([$sid]); $documentTypes=$dts->fetchAll();
$quals=$pdo->prepare('SELECT * FROM qualifications WHERE system_id=? ORDER BY name'); $quals->execute([$sid]); $qualifications=$quals->fetchAll();

$dtByCat=[];
foreach($documentTypes as $dt){ $cid=(int)($dt['category_id']??0); $dtByCat[$cid][]=['id'=>$dt['id'],'name'=>$dt['name']]; }
$dtAll=array_map(fn($dt)=>['id'=>$dt['id'],'name'=>$dt['name']],$documentTypes);

$subsStmt=$pdo->prepare('SELECT document_type_id,name FROM document_subs WHERE system_id=? ORDER BY name'); $subsStmt->execute([$sid]);
$docSubs=[];
foreach($subsStmt->fetchAll() as $s){ $docSubs[(int)$s['document_type_id']][]=$s['name']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents Tracking — <?= htmlspecialchars($activeSystem['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/header.css">
    <link rel="stylesheet" href="../assets/sidebar.css">
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        .prefill-banner{background:#f0f5ff;border-bottom:1px solid #d0dbf0;padding:14px 20px 12px}
        .prefill-banner-title{font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#5566aa;margin-bottom:8px}
        .prefill-hint{font-size:.73rem;color:#7788bb;margin-top:6px}
        .rows-section{padding:12px 20px 6px}
        .rows-section-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
        .rows-section-label{font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#555}
        .rows-scroll-wrap{overflow-x:auto;overflow-y:auto;max-height:380px;border:1px solid #dee2e6;border-radius:6px}
        #addRowsTable{min-width:1080px;margin-bottom:0;font-size:.79rem}
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
            padding: 7px 8px;
            border-bottom: 2px solid #dee2e6;
        }
        #addRowsTable tbody td{padding:3px 4px;vertical-align:middle}
        #addRowsTable .form-control,#addRowsTable .form-select{font-size:.77rem;padding:3px 5px;height:27px;border-radius:4px}
        #addRowsTable input[type=date]{font-size:.73rem}
        /* Ensure readable text in row inputs/selects */
        #addRowsTable .form-control, #addRowsTable .form-select {
            color: #222 !important;
            background-color: #fff !important;
        }
        #addRowsTable .form-control::placeholder { color: #777; }
        .row-num-cell{font-size:.71rem;color:#aaa;text-align:center;font-variant-numeric:tabular-nums}
        .btn-del-row{width:23px;height:23px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:3px;font-size:.78rem;line-height:1}
        #addRowsEmpty td{padding:28px;text-align:center;color:#bbb;font-size:.83rem}
        .select-cat{border-color:#b8c8f0!important}
        .select-dt{border-color:#b8e0d4!important}
        .select-qual{border-color:#f0d8a8!important}
        .add-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-top:1px solid #dee2e6;background:#fafafa}
        tr.row-invalid{outline:2px solid #dc3545}

        /* Table usability improvements */
        .table-responsive { max-height: 72vh; overflow: auto; }
        #documentsTable { min-width: 1960px; }
        #documentsTable thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            white-space: nowrap;
        }
        #documentsTable td { white-space: nowrap; }
        #documentsTable th:nth-child(2), #documentsTable td:nth-child(2) { min-width: 190px; }
        #documentsTable th:nth-child(3), #documentsTable td:nth-child(3) { min-width: 150px; }
        #documentsTable th:nth-child(4), #documentsTable td:nth-child(4) { min-width: 190px; }
        #documentsTable th:nth-child(5), #documentsTable td:nth-child(5) { min-width: 190px; }
        #documentsTable th:nth-child(6), #documentsTable td:nth-child(6) { min-width: 130px; }
        #documentsTable th:nth-child(7), #documentsTable td:nth-child(7),
        #documentsTable th:nth-child(8), #documentsTable td:nth-child(8),
        #documentsTable th:nth-child(9), #documentsTable td:nth-child(9),
        #documentsTable th:nth-child(10), #documentsTable td:nth-child(10),
        #documentsTable th:nth-child(11), #documentsTable td:nth-child(11),
        #documentsTable th:nth-child(12), #documentsTable td:nth-child(12),
        #documentsTable th:nth-child(13), #documentsTable td:nth-child(13) { min-width: 140px; }
        #documentsTable th:nth-child(14), #documentsTable td:nth-child(14) { min-width: 130px; }
        #documentsTable th:nth-child(15), #documentsTable td:nth-child(15) { min-width: 90px; }
        #documentsTable th:nth-child(16), #documentsTable td:nth-child(16) { min-width: 110px; }
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

    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="bi bi-file-earmark-text"></i></div>
            <div>
                <h1 class="page-title">Documents Tracking</h1>
                <p class="page-subtitle"><?= htmlspecialchars($activeSystem['name']) ?> &mdash; <?= count($documents) ?> active record(s)</p>
            </div>
        </div>
    </div>

    <div class="dt-toolbar">
        <form method="GET" class="dt-toolbar-filters">
            <select name="cat" class="dt-filter-select" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCat==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="dt" class="dt-filter-select" onchange="this.form.submit()">
                <option value="">All Doc Types</option>
                <?php foreach ($documentTypes as $dt): ?>
                    <option value="<?= $dt['id'] ?>" <?= $filterDt==$dt['id']?'selected':'' ?>><?= htmlspecialchars($dt['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="qual" class="dt-filter-select" onchange="this.form.submit()">
                <option value="">All Qualifications</option>
                <?php foreach ($qualifications as $q): ?>
                    <option value="<?= $q['id'] ?>" <?= $filterQual==$q['id']?'selected':'' ?>><?= htmlspecialchars($q['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterCat||$filterDt||$filterQual): ?><a href="documents_tracking.php" class="dt-filter-clear"><i class="bi bi-x-circle me-1"></i>Clear</a><?php endif; ?>
        </form>
        <div class="dt-toolbar-actions no-print">
            <a href="add_documents.php" class="btn btn-modern btn-success"><i class="bi bi-plus-lg"></i>Add Documents</a>
            <button type="button" class="btn btn-modern btn-warning" id="bulkArchiveBtn"><i class="bi bi-archive"></i>Archive</button>
            <button type="button" class="btn btn-modern btn-secondary" id="printBtn"><i class="bi bi-printer"></i>Print</button>
        </div>
    </div>

    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" value="bulk_archive">
        <div class="slide-table-wrap">
            <div class="slide-dt-bar">
                <div class="slide-dt-length">Show <select id="dtLengthSelect" class="slide-dt-select"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option><option value="-1">All</option></select> entries</div>
                <div class="slide-dt-search"><i class="bi bi-search"></i><input type="search" id="dtSearchInput" placeholder="Quick search…"></div>
            </div>
            <div class="table-responsive">
                <table id="documentsTable" class="slide-table">
                    <thead><tr>
                        <th><input type="checkbox" id="selectAll" title="Select All"></th>
                        <th>Documents / Category</th><th>Qualification</th><th>Doc Type</th><th>Sub-Document</th>
                        <th>Batch No.</th><th>Date Submitted</th><th>Received by TESDA</th><th>Returned to Center</th>
                        <th>Staff Received</th><th>Date of Assessment</th><th>Assessor Name</th><th>TESDA Released</th>
                        <th>Remarks</th><th>Image</th><th class="no-print">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($documents as $doc):
                        $dv=fn($v)=>htmlspecialchars($v??'');
                        $df=fn($d)=>$d?date('m/d/Y',strtotime($d)):'';
                    ?>
                    <tr>
                        <td class="td-check"><input type="checkbox" name="selected_ids[]" value="<?= $doc['id'] ?>" class="row-check"></td>
                        <td class="td-fixed"><?= $dv($doc['cat_name'])      ?:'' ?></td>
                        <td class="td-fixed"><?= $dv($doc['qual_name'])     ?:'' ?></td>
                        <td class="td-fixed"><?= $dv($doc['doc_type_name']) ?:'' ?></td>
                        <td class="td-fixed"><?= $dv($doc['document_sub'])  ?:'' ?></td>
                        <td class="td-fixed"><?= $dv($doc['batch_no'])      ?:'' ?></td>
                        <td class="td-fixed"><?= $df($doc['date_submission']) ?></td>
                        <td class="cell-field" data-field="received_tesda"  data-doc-id="<?= $doc['id'] ?>" data-type="date"  data-value="<?= $dv($doc['received_tesda']) ?>"><span class="cell-val"><?= $df($doc['received_tesda'])  ?: '<span class="empty-cell">—</span>' ?></span></td>
                        <td class="td-fixed"><?= $df($doc['returned_center']) ?: '<span class="empty-cell">—</span>' ?></td>
                        <td class="cell-field" data-field="staff_received"  data-doc-id="<?= $doc['id'] ?>" data-type="date"  data-value="<?= $dv($doc['staff_received']) ?>"><span class="cell-val"><?= $df($doc['staff_received'])  ?: '<span class="empty-cell">—</span>' ?></span></td>
                        <td class="cell-field" data-field="date_assessment" data-doc-id="<?= $doc['id'] ?>" data-type="date"  data-value="<?= $dv($doc['date_assessment']) ?>"><span class="cell-val"><?= $df($doc['date_assessment']) ?: '<span class="empty-cell">—</span>' ?></span></td>
                        <td class="cell-field" data-field="assessor_name"   data-doc-id="<?= $doc['id'] ?>" data-type="text"  data-value="<?= $dv($doc['assessor_name']) ?>"><span class="cell-val"><?= $dv($doc['assessor_name'])   ?: '<span class="empty-cell">—</span>' ?></span></td>
                        <td class="cell-field" data-field="tesda_released"  data-doc-id="<?= $doc['id'] ?>" data-type="date"  data-value="<?= $dv($doc['tesda_released']) ?>"><span class="cell-val"><?= $df($doc['tesda_released'])  ?: '<span class="empty-cell">—</span>' ?></span></td>
                        <td class="td-remarks">
                            <select class="form-select remarks-select <?= $doc['remarks']==='received'?'remarks-received':($doc['remarks']==='returned'?'remarks-returned':'') ?>" data-doc-id="<?= $doc['id'] ?>">
                                <option value="">—</option>
                                <option value="received" <?= $doc['remarks']==='received'?'selected':'' ?>>Received</option>
                                <option value="returned" <?= $doc['remarks']==='returned'?'selected':'' ?>>Returned</option>
                            </select>
                        </td>
                        <td class="td-img">
                            <?php if($doc['image_path']): ?>
                                <div class="img-cell-wrap" data-image="<?= htmlspecialchars($doc['image_path']) ?>">
                                    <img src="../<?= htmlspecialchars($doc['image_path']) ?>" class="doc-thumb" alt="doc" data-image="<?= htmlspecialchars($doc['image_path']) ?>" onerror="this.style.display='none'">
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-action btn-outline-secondary btn-upload-img" data-doc-id="<?= $doc['id'] ?>"><i class="bi bi-camera"></i></button>
                            <?php endif; ?>
                        </td>
                        <td class="td-actions no-print">
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-action btn-outline-primary btn-edit-core" title="Edit"
                                    data-row='<?= htmlspecialchars(json_encode(['doc_id'=>$doc['id'],'category_id'=>$doc['category_id'],'document_type_id'=>$doc['document_type_id'],'qualification_id'=>$doc['qualification_id'],'date_submission'=>$doc['date_submission'],'batch_no'=>$doc['batch_no'],'document_sub'=>$doc['document_sub']??null,'remarks'=>$doc['remarks']]),ENT_QUOTES) ?>'>
                                    <i class="bi bi-pencil"></i></button>
                                <a href="documents_tracking.php?archive_id=<?= $doc['id'] ?>" class="btn btn-action btn-outline-warning" onclick="return confirm('Archive this document?')"><i class="bi bi-archive"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="slide-dt-foot" id="dtInfoRow">Showing <?= count($documents) ?> record(s)</div>
        </div>
    </form>
</main>

<!-- ADD DOCUMENTS MODAL -->
<div class="modal fade" id="addDocModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-hdr-icon"><i class="bi bi-file-earmark-plus"></i></div>
                    <div>
                        <h5 class="modal-title mb-0">Add Documents</h5>
                        <small style="color:rgba(255,255,255,.55);font-size:.72rem;">Each row has its own Category → Doc Type → Qualification</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addMultipleForm" novalidate>
                <input type="hidden" name="action" value="add_multiple_documents">
                <div class="modal-body p-0">

                    <!-- Prefill banner -->
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
                                <label class="form-label form-label-sm mb-1">Date of Submission</label>
                                <input type="date" name="date_submission_shared" id="prefill_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm btn-primary w-100" id="btnApplyPrefill">
                                    <i class="bi bi-arrow-down-circle me-1"></i>Apply to All Rows
                                </button>
                            </div>
                        </div>
                        <div class="prefill-hint"><i class="bi bi-info-circle me-1"></i>Or set each row individually — selecting a Category filters its own Doc Type list.</div>
                    </div>

                    <!-- Rows -->
                    <div class="rows-section">
                        <div class="rows-section-top">
                            <span class="rows-section-label"><i class="bi bi-table me-1"></i>Document rows <span class="badge bg-secondary ms-1" id="rowCountBadge">0</span></span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary"   id="btnAdd1"><i class="bi bi-plus-lg me-1"></i>Add Row</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd5">+ 5 Rows</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAdd10">+ 10 Rows</button>
                            </div>
                        </div>
                        <div class="rows-scroll-wrap">
                            <table class="table table-sm table-bordered table-hover" id="addRowsTable">
                                <thead><tr>
                                    <th style="width:28px">#</th>
                                    <th style="min-width:130px">Category <span class="text-danger">*</span></th>
                                    <th style="min-width:110px">Qualification</th>
                                    <th style="min-width:145px">Doc Type <span class="text-danger">*</span></th>
                                    <th style="min-width:120px">Sub-Document</th>
                                    <th style="min-width:105px">Batch No.</th>
                                    <th style="min-width:112px">Date Submitted</th>
                                    <th style="min-width:112px">Received (TESDA)</th>
                                    <th style="min-width:112px">Returned (Center)</th>
                                    <th style="min-width:108px">Staff Received</th>
                                    <th style="min-width:112px">Date Assessment</th>
                                    <th style="min-width:108px">Assessor Name</th>
                                    <th style="min-width:112px">TESDA Released</th>
                                    <th style="min-width:95px">Remarks</th>
                                    <th style="min-width:80px">Image</th>
                                    <th style="width:30px"></th>
                                </tr></thead>
                                <tbody id="addRowsTbody">
                                    <tr id="addRowsEmpty"><td colspan="13">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="add-modal-footer">
                    <button type="button" class="btn btn-sm btn-link text-danger px-0" id="btnClearRows"><i class="bi bi-trash me-1"></i>Clear all rows</button>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="text-muted" style="font-size:.78rem" id="saveCountLabel"></span>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-tb5-primary"><i class="bi bi-check-lg me-1"></i>Save Documents</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT CORE MODAL -->
<div class="modal fade" id="editCoreModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-hdr-icon"><i class="bi bi-pencil-square"></i></div>
                    <div><h5 class="modal-title mb-0">Edit Document</h5><small style="color:rgba(255,255,255,.55);font-size:.72rem">Update category, type, qualification, date</small></div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCoreForm">
                <input type="hidden" name="action" value="edit_core">
                <input type="hidden" name="doc_id" id="editCore_docId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label fw-semibold">Category</label>
                            <select name="category_id" id="editCore_catId" class="form-select"><option value="">— Select —</option>
                            <?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Document Type</label>
                            <select name="document_type_id" id="editCore_dtId" class="form-select"><option value="">— Select —</option>
                            <?php foreach($documentTypes as $dt): ?><option value="<?=$dt['id']?>" data-cat="<?=$dt['category_id'] ?? ''?>"><?=htmlspecialchars($dt['name'])?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Document Sub</label>
                            <select name="document_sub" id="editCore_documentSub" class="form-select"><option value="">— None —</option></select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Qualification</label>
                            <select name="qualification_id" id="editCore_qualId" class="form-select"><option value="">— None —</option>
                            <?php foreach($qualifications as $q): ?><option value="<?=$q['id']?>"><?=htmlspecialchars($q['name'])?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Date of Submission</label><input type="date" name="date_submission" id="editCore_dateSub" class="form-control"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Batch No.</label><input type="text" name="batch_no" id="editCore_batchNo" class="form-control" placeholder="e.g. Batch 51"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Remarks</label>
                            <select name="remarks" id="editCore_remarks" class="form-select">
                                <option value="">—</option>
                                <option value="received">Received</option>
                                <option value="returned">Returned</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- IMAGE UPLOAD MODAL -->
<div class="modal fade" id="imageUploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header"><div class="d-flex align-items-center gap-2"><div class="modal-hdr-icon"><i class="bi bi-camera"></i></div><h5 class="modal-title mb-0">Upload Image</h5></div><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
                <input type="hidden" name="action" value="upload_image"><input type="hidden" name="doc_id" id="imgUpload_docId">
                <div class="modal-body"><label class="form-label fw-semibold">Select File</label><input type="file" name="doc_image" class="form-control" accept="image/*,.pdf" required><div class="form-text mt-2">JPG, PNG, GIF, WEBP or PDF · max 5 MB</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-tb5-primary"><i class="bi bi-upload me-1"></i>Upload</button></div>
            </form>
        </div>
    </div>
</div>

<!-- IMAGE PREVIEW MODAL -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title">Document Image</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center">
                <img id="previewImg" src="" class="img-fluid rounded" alt="Preview" style="max-height:70vh">
                <iframe id="previewIframe" style="width:100%;height:70vh;border:0;display:none"></iframe>
                <p id="previewMsg" class="mt-2" style="display:none"></p>
            </div>
            <div class="modal-footer"><a id="previewOpen" class="btn btn-outline-primary" target="_blank">Open</a><a id="previewDownload" class="btn btn-outline-secondary" download>Download</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const DT_BY_CAT = <?= json_encode($dtByCat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DT_ALL    = <?= json_encode($dtAll,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const QUALS     = <?= json_encode(array_map(fn($q)=>['id'=>$q['id'],'name'=>$q['name']],$qualifications), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DOC_SUBS  = <?= json_encode($docSubs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const CATS_PHP  = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']],$categories), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

(function(){
    let rowSeq = 0;

    function fillDtSelect(sel, catId, selectedVal) {
        const list = (catId && DT_BY_CAT[catId]) ? DT_BY_CAT[catId] : DT_ALL;
        sel.innerHTML = '<option value="">— Select —</option>';
        list.forEach(dt => {
            const o = new Option(dt.name, dt.id, false, String(dt.id)===String(selectedVal));
            sel.appendChild(o);
        });
    }

    function fillQualSelect(sel, selectedVal) {
        sel.innerHTML = '<option value="">— None —</option>';
        QUALS.forEach(q => {
            const o = new Option(q.name, q.id, false, String(q.id)===String(selectedVal));
            sel.appendChild(o);
        });
    }

    function makeRow(def) {
        def = def || {};
        rowSeq++;
        const tr = document.createElement('tr');
        tr.dataset.seq = rowSeq;

        // category
        const catSel = document.createElement('select');
        catSel.name = 'row_category_id[]';
        catSel.className = 'form-select form-select-sm select-cat';
        catSel.innerHTML = '<option value="">— Select —</option>';
        CATS_PHP.forEach(c => {
            const o = new Option(c.name, c.id, false, String(c.id)===String(def.catId||''));
            catSel.appendChild(o);
        });

        // doctype (filtered by category)
        const dtSel = document.createElement('select');
        dtSel.name = 'row_document_type_id[]';
        dtSel.className = 'form-select form-select-sm select-dt';
        fillDtSelect(dtSel, def.catId||'', def.dtId||'');

        // qual
        const qualSel = document.createElement('select');
        qualSel.name = 'row_qualification_id[]';
        qualSel.className = 'form-select form-select-sm select-qual';
        fillQualSelect(qualSel, def.qualId||'');

        // cascade: category change → refill doctype
        catSel.addEventListener('change', function(){ fillDtSelect(dtSel, this.value, ''); });

        const inp = (name, type, ph) => {
            const el = document.createElement('input');
            el.type = type||'text'; el.name = name+'[]';
            el.className = 'form-control form-control-sm';
            if (ph) el.placeholder = ph;
            return el;
        };

        // Image upload
        const imgInp = document.createElement('input');
        imgInp.type = 'file';
        imgInp.name = 'row_image[]';
        imgInp.className = 'form-control form-control-sm';
        imgInp.accept = 'image/*,.pdf';

        // Edit action button
        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-outline-primary btn-edit-row';
        editBtn.title = 'Edit row';
        editBtn.innerHTML = '<i class="bi bi-pencil"></i>';

        // Sync Received/Returned
        const receivedInp = inp('received_tesda','date');
        const returnedInp = inp('returned_center','date');
        receivedInp.addEventListener('change', function(){ returnedInp.value = this.value; });
        returnedInp.addEventListener('change', function(){ receivedInp.value = this.value; });

        const del = document.createElement('button');
        del.type='button'; del.className='btn btn-outline-danger btn-del-row'; del.title='Remove';
        del.innerHTML='<i class="bi bi-x"></i>';

        const numTd = document.createElement('td'); numTd.className='row-num-cell';
        const wrap  = el => { const td=document.createElement('td'); td.appendChild(el); return td; };

        // Sub-Document select
        const subSel = document.createElement('select');
        subSel.name = 'row_document_sub[]';
        subSel.className = 'form-select form-select-sm';
        subSel.innerHTML = '<option value="">— None —</option>';

        // Remarks select
        const remarksSel = document.createElement('select');
        remarksSel.name = 'remarks[]';
        remarksSel.className = 'form-select form-select-sm';
        remarksSel.innerHTML =
            '<option value="">—</option>' +
            '<option value="received">Received</option>' +
            '<option value="returned">Returned</option>';
        if (def.remarks) remarksSel.value = def.remarks;
        // Fill sub-docs when doc type changes
        function fillSubDocs(docTypeId, selectedVal) {
            subSel.innerHTML = '<option value="">— None —</option>';
            if (docTypeId && DOC_SUBS[docTypeId]) {
                DOC_SUBS[docTypeId].forEach(function(name) {
                    const o = new Option(name, name, false, name === selectedVal);
                    subSel.appendChild(o);
                });
            }
        }

        dtSel.addEventListener('change', function(){ fillSubDocs(this.value, ''); });
        // If default provided
        if (def.dtId) fillSubDocs(def.dtId, def.subDoc||'');

        [numTd,
         wrap(catSel),
         wrap(dtSel),
         wrap(qualSel),
         wrap(subSel),
         wrap(inp('batch_no','text','e.g. 51401-001')),
         wrap(inp('date_submission','date')),
         wrap(receivedInp),
         wrap(returnedInp),
         wrap(inp('staff_received','date')),
         wrap(inp('date_assessment','date')),
         wrap(inp('assessor_name','text')),
         wrap(inp('tesda_released','date')),
         wrap(remarksSel),
         wrap(imgInp),
         wrap(editBtn),
         wrap(del)
        ].forEach(td => tr.appendChild(td));

        return tr;
    }

    function reindex() {
        document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty) .row-num-cell')
            .forEach((td,i) => td.textContent = i+1);
    }

    function syncCount() {
        const n = document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty)').length;
        document.getElementById('rowCountBadge').textContent = n;
        document.getElementById('saveCountLabel').textContent = n>0 ? `${n} row${n!==1?'s':''} queued` : '';
    }

    function showEmpty() {
        document.getElementById('addRowsTbody').innerHTML =
            `<tr id="addRowsEmpty"><td colspan="13">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>`;
        syncCount();
    }

    function addRows(n, def) {
        const tbody = document.getElementById('addRowsTbody');
        const empty = document.getElementById('addRowsEmpty');
        if (empty) empty.remove();
        for (let i=0; i<n; i++) tbody.appendChild(makeRow(def||{}));
        reindex(); syncCount();
    }

    function getDataRows() {
        const tbody = document.getElementById('addRowsTbody');
        if (!tbody) return [];
        return Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.id !== 'addRowsEmpty');
    }

    function getPrefill() {
        return {
            catId:  document.getElementById('prefill_category').value,
            dtId:   document.getElementById('prefill_doctype').value,
            qualId: document.getElementById('prefill_qualification').value,
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
            const catSel  = tr.querySelector('[name="row_category_id[]"]');
            const dtSel   = tr.querySelector('[name="row_document_type_id[]"]');
            const qualSel = tr.querySelector('[name="row_qualification_id[]"]');
            if (catSel && dtSel) { catSel.value = p.catId; fillDtSelect(dtSel, p.catId, p.dtId); }
            if (qualSel) qualSel.value = p.qualId;
        });
    }

    function resetModal() {
        rowSeq = 0; showEmpty();
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
        document.getElementById('btnAdd1').addEventListener('click',  ()=>addRows(1,  getPrefill()));
        document.getElementById('btnAdd5').addEventListener('click',  ()=>addRows(5,  getPrefill()));
        document.getElementById('btnAdd10').addEventListener('click', ()=>addRows(10, getPrefill()));
        document.getElementById('btnClearRows').addEventListener('click', ()=>{ if(confirm('Clear all rows?')) resetModal(); });

        document.getElementById('addRowsTbody').addEventListener('click', function(e){
            const btn = e.target.closest('.btn-del-row');
            if (!btn) return;
            btn.closest('tr').remove(); reindex(); syncCount();
            if (!document.querySelector('#addRowsTbody tr:not(#addRowsEmpty)')) showEmpty();
        });

        document.getElementById('addDocModal').addEventListener('shown.bs.modal', function(){
            if (!document.querySelector('#addRowsTbody tr:not(#addRowsEmpty)')) addRows(1);
        });
        document.getElementById('addDocModal').addEventListener('hidden.bs.modal', resetModal);

        document.getElementById('addMultipleForm').addEventListener('submit', function(e){
            const rows = document.querySelectorAll('#addRowsTbody tr:not(#addRowsEmpty)');
            if (rows.length===0){ e.preventDefault(); alert('Add at least one row before saving.'); return; }
            let bad = false;
            rows.forEach(tr=>{
                const cat = tr.querySelector('[name="row_category_id[]"]').value;
                const dt  = tr.querySelector('[name="row_document_type_id[]"]').value;
                if (!cat||!dt){ tr.classList.add('row-invalid'); bad=true; }
                else tr.classList.remove('row-invalid');
            });
            if (bad){ e.preventDefault(); alert('Rows highlighted in red are missing a Category or Document Type.'); }
        });
    });
})();
</script>
</body>
</html>