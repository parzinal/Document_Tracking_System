<?php
// =========================================================
// Documents Tracking
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
$sid = (int)$_SESSION['active_system_id'];

$flash     = '';
$flashType = 'success';

if (!empty($_SESSION['documents_tracking_flash_message'])) {
    $flash = (string)$_SESSION['documents_tracking_flash_message'];
    $flashType = (string)($_SESSION['documents_tracking_flash_type'] ?? 'success');
    unset($_SESSION['documents_tracking_flash_message'], $_SESSION['documents_tracking_flash_type']);
}

function setTrackingFlash(string $message, string $type = 'success'): void {
    $_SESSION['documents_tracking_flash_message'] = $message;
    $_SESSION['documents_tracking_flash_type'] = $type;
}

function ensureDocumentQualificationTable(PDO $pdo): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS document_qualifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_id INT UNSIGNED NOT NULL,
            qualification_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_document_qualification (document_id, qualification_id),
            KEY idx_document (document_id),
            KEY idx_qualification (qualification_id),
            CONSTRAINT fk_document_quals_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            CONSTRAINT fk_document_quals_qualification FOREIGN KEY (qualification_id) REFERENCES qualifications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (Throwable $_e) {
        return false;
    }
}

$hasDocumentQualificationTable = ensureDocumentQualificationTable($pdo);

function handleUpload(): ?string {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);

    // Support multiple files (name="doc_image[]") or single file (name="doc_image")
    if (isset($_FILES['doc_image']['name']) && is_array($_FILES['doc_image']['name'])) {
        $names = $_FILES['doc_image']['name'];
        if (!$names) return null;
        if (count($names) > 20) throw new RuntimeException('Only up to 20 files allowed.');

        $paths = [];
        foreach ($names as $i => $original) {
            $error = $_FILES['doc_image']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('One of the files failed to upload.');

            $tmp = $_FILES['doc_image']['tmp_name'][$i] ?? '';
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;

            $size = (int)($_FILES['doc_image']['size'][$i] ?? 0);
            if ($size <= 0) continue;
            if ($size > 5 * 1024 * 1024) throw new RuntimeException('File too large. Max 5 MB.');

            $mime = $finfo->file($tmp);
            if (!in_array($mime, $allowed, true)) throw new RuntimeException('Invalid file type.');

            $ext = pathinfo((string)$original, PATHINFO_EXTENSION);
            if ($ext === '') {
                $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
            }

            $filename = uniqid('doc_', true) . '.' . strtolower($ext);
            $dest = __DIR__ . '/../assets/upload/' . $filename;
            if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Failed to save uploaded file.');

            $paths[] = 'assets/upload/' . $filename;
        }

        if (!$paths) return null;
        if (count($paths) === 1) return $paths[0];
        return json_encode(array_values($paths), JSON_UNESCAPED_SLASHES);
    }

    // Single file fallback
    if (empty($_FILES['doc_image']['name'])) return null;
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
        throw new RuntimeException('Failed to store uploaded files.');
    }
    if (strlen($encoded) > 60000) {
        throw new RuntimeException('Attachment payload is too large. Reduce the number of files.');
    }

    return $encoded;
}

function handleSharedUploads(): array {
    $names = $_FILES['shared_files']['name'] ?? null;
    if (!is_array($names) || !$names) return [];
    if (count($names) > 20) {
        throw new RuntimeException('Only up to 20 shared files are allowed for this batch.');
    }

    $tmpNames = $_FILES['shared_files']['tmp_name'] ?? [];
    $errors = $_FILES['shared_files']['error'] ?? [];
    $sizes = $_FILES['shared_files']['size'] ?? [];

    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $paths = [];

    foreach ($names as $idx => $original) {
        $error = $errors[$idx] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the shared files failed to upload.');
        }

        $tmp = (string)($tmpNames[$idx] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $size = (int)($sizes[$idx] ?? 0);
        if ($size <= 0) continue;

        $mime = $finfo->file($tmp);
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Invalid file type in one of the shared attachments.');
        }
        if ($size > 5 * 1024 * 1024) {
            throw new RuntimeException('One of the shared attachments is too large (max 5 MB).');
        }

        $ext = strtolower(pathinfo((string)$original, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
        }

        $filename = uniqid('doc_', true) . '.' . $ext;
        $dest = __DIR__ . '/../assets/upload/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Failed to save one of the shared attachments.');
        }

        $paths[] = 'assets/upload/' . $filename;
    }

    return $paths;
}

if (isset($_GET['archive_id'])) {
    $pdo->prepare("UPDATE documents SET is_archived=1 WHERE id=? AND system_id=?")->execute([(int)$_GET['archive_id'], $sid]);
    header('Location: documents_tracking.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_edit_password') {
        $accountPassword = (string)($_POST['account_password'] ?? '');
        $ok = $accountPassword !== '' && password_verify($accountPassword, (string)($currentUser['password_hash'] ?? ''));

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $ok ? 'Password confirmed.' : 'Account password is incorrect.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'add_multiple_documents') {
        $date_sub_shared   = $_POST['date_submission_shared'] ?: null;
        $category_ids      = $_POST['row_category_id']        ?? [];
        $doctype_ids       = $_POST['row_document_type_id']   ?? [];
        $qualification_ids = $_POST['row_qualification_id']   ?? [];
        $batch_nos         = $_POST['batch_no']               ?? [];
        $remarks_arr       = $_POST['remarks']                ?? [];
        $received_tesdas   = $_POST['received_tesda']         ?? [];
        $returned_centers  = $_POST['returned_center']        ?? [];
        $staff_receiveds   = $_POST['staff_received']         ?? [];
        $date_assessments  = $_POST['date_assessment']        ?? [];
        $assessor_names    = $_POST['assessor_name']          ?? [];
        $tesda_releaseds   = $_POST['tesda_released']         ?? [];

        $ins = $pdo->prepare('INSERT INTO documents
            (system_id,category_id,document_type_id,qualification_id,
             date_submission,batch_no,remarks,received_tesda,returned_center,
             staff_received,date_assessment,assessor_name,tesda_released)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        for ($i = 0, $n = count($batch_nos); $i < $n; $i++) {
            $batch = trim($batch_nos[$i]) ?: null;
            $rem   = normalizeRemark($remarks_arr[$i] ?? '');
            if ($batch === null && $rem === null) continue;
            $cat_id  = intval($category_ids[$i]      ?? 0) ?: null;
            $dt_id   = intval($doctype_ids[$i]        ?? 0) ?: null;
            $qual_id = intval($qualification_ids[$i]  ?? 0) ?: null;
            $ins->execute([$sid,$cat_id,$dt_id,$qual_id,$date_sub_shared,$batch,$rem,
                $received_tesdas[$i]  ?: null, $returned_centers[$i] ?: null,
                ($staff_receiveds[$i] ?? '') ?: null, $date_assessments[$i] ?: null,
                trim($assessor_names[$i]   ?? '') ?: null, $tesda_releaseds[$i]  ?: null]);
            $count++;
        }
        setTrackingFlash($count . ' document(s) added.');
    }

    if ($action === 'upload_batch_images') {
        $date_sub_raw = trim((string)($_POST['date_submission'] ?? ''));
        $replace = isset($_POST['replace_existing']) && (string)$_POST['replace_existing'] === '1';

        if ($date_sub_raw !== '__none__' && $date_sub_raw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_sub_raw)) {
            setTrackingFlash('Invalid date selected for batch upload.', 'danger');
        } else {
            try {
                $accountPassword = (string)($_POST['account_password'] ?? '');
                if ($accountPassword === '' || !password_verify($accountPassword, (string)($currentUser['password_hash'] ?? ''))) {
                    throw new RuntimeException('Account password is incorrect. Batch upload cancelled.');
                }

                $sharedPaths = handleSharedUploads();
                if (!$sharedPaths) {
                    throw new RuntimeException('No files uploaded.');
                }

                $encoded = encodeStoredImagePaths($sharedPaths);

                if ($date_sub_raw === '__none__' || $date_sub_raw === '') {
                    $sel = $pdo->prepare('SELECT id,image_path FROM documents WHERE system_id=? AND is_archived=0 AND (date_submission IS NULL OR TRIM(date_submission)="")');
                    $sel->execute([$sid]);
                } else {
                    $sel = $pdo->prepare('SELECT id,image_path FROM documents WHERE system_id=? AND is_archived=0 AND date_submission=?');
                    $sel->execute([$sid, $date_sub_raw]);
                }

                $rows = $sel->fetchAll();
                $count = 0;
                foreach ($rows as $r) {
                    if ($r && $r['image_path'] && $replace) {
                        $oldFiles = parseStoredImagePaths($r['image_path']);
                        foreach ($oldFiles as $old) {
                            $oldPath = __DIR__ . '/../' . ltrim((string)$old, '/');
                            if (is_file($oldPath)) { @unlink($oldPath); }
                        }
                    }

                    $pdo->prepare('UPDATE documents SET image_path=?,updated_at=NOW() WHERE id=? AND system_id=?')
                        ->execute([$encoded, $r['id'], $sid]);
                    $count++;
                }

                setTrackingFlash($count . ' document(s) updated with uploaded file(s).');
            } catch (RuntimeException $e) {
                setTrackingFlash($e->getMessage(), 'danger');
            }
        }
    }

    if ($action === 'edit_core') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM documents WHERE id=? AND system_id=?');
        $chk->execute([$docId, $sid]);
        if ($chk->fetch()) {
            $accountPassword = (string)($_POST['account_password'] ?? '');
            if ($accountPassword === '' || !password_verify($accountPassword, (string)($currentUser['password_hash'] ?? ''))) {
                setTrackingFlash('Account password is incorrect. Document was not updated.', 'danger');
            } else {
                $catId = intval($_POST['category_id']) ?: null;
                $qualInput = $_POST['qualification_ids'] ?? [];
                if (!is_array($qualInput)) {
                    $qualInput = [$qualInput];
                }

                $qualIds = [];
                foreach ($qualInput as $qv) {
                    $qid = intval($qv);
                    if ($qid > 0) {
                        $qualIds[] = $qid;
                    }
                }
                if ($qualIds) {
                    $qualIds = array_values(array_unique($qualIds));
                }

                $primaryQualId = $qualIds ? $qualIds[0] : null;

                $pdo->prepare('UPDATE documents SET category_id=?,document_type_id=?,qualification_id=?,
                    date_submission=?,batch_no=?,received_tesda=?,returned_center=?,
                    staff_received=?,date_assessment=?,assessor_name=?,tesda_released=?,
                    remarks=?,updated_at=NOW() WHERE id=? AND system_id=?')
                ->execute([
                    $catId,
                    intval($_POST['document_type_id']) ?: null,
                    $primaryQualId,
                    $_POST['date_submission'] ?: null,
                    trim($_POST['batch_no'] ?? '') ?: null,
                    ($_POST['received_tesda'] ?? '') ?: null,
                    ($_POST['returned_center'] ?? '') ?: null,
                    trim($_POST['staff_received'] ?? '') ?: null,
                    ($_POST['date_assessment'] ?? '') ?: null,
                    trim($_POST['assessor_name'] ?? '') ?: null,
                    ($_POST['tesda_released'] ?? '') ?: null,
                    normalizeRemark($_POST['remarks'] ?? null),
                    $docId,
                    $sid
                ]);

                if ($hasDocumentQualificationTable) {
                    $pdo->prepare('DELETE FROM document_qualifications WHERE document_id=?')->execute([$docId]);
                    if ($qualIds) {
                        $insDocQual = $pdo->prepare('INSERT IGNORE INTO document_qualifications (document_id, qualification_id) VALUES (?, ?)');
                        foreach ($qualIds as $qid) {
                            $insDocQual->execute([$docId, $qid]);
                        }
                    }
                }
                setTrackingFlash('Document updated.');
            }
        } else {
            setTrackingFlash('Document not found.', 'danger');
        }
    }

    if ($action === 'upload_image') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM documents WHERE id=? AND system_id=?');
        $chk->execute([$docId, $sid]);
        if ($chk->fetch()) {
            $ex = $pdo->prepare('SELECT image_path FROM documents WHERE id=? AND system_id=?');
            $ex->execute([$docId, $sid]);
            $er = $ex->fetch();
            $replace = isset($_POST['replace_existing']) && (string)$_POST['replace_existing'] === '1';

            if ($er && $er['image_path'] && !$replace) {
                setTrackingFlash('Already has file. Use Re-upload to replace or remove first.', 'danger');
            } else {
                try {
                    $p = handleUpload();
                    if ($p) {
                        // If replacing, attempt to delete old files (best-effort)
                        if ($er && $er['image_path']) {
                            $oldFiles = parseStoredImagePaths($er['image_path']);
                            foreach ($oldFiles as $old) {
                                $oldPath = __DIR__ . '/../' . ltrim((string)$old, '/');
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                        }

                        $pdo->prepare('UPDATE documents SET image_path=?,updated_at=NOW() WHERE id=? AND system_id=?')
                            ->execute([$p, $docId, $sid]);
                        setTrackingFlash($er && $er['image_path'] && $replace ? 'Image replaced.' : 'Image uploaded.');
                    }
                } catch (\RuntimeException $e) {
                    setTrackingFlash($e->getMessage(), 'danger');
                }
            }
        } else {
            setTrackingFlash('Document not found.', 'danger');
        }
    }

    if ($action === 'bulk_archive') {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        if ($ids) {
            $ph = implode(',', array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE documents SET is_archived=1 WHERE id IN ($ph) AND system_id=?")->execute(array_merge($ids,[$sid]));
            setTrackingFlash(count($ids).' document(s) archived.');
        }
    }

    header('Location: documents_tracking.php?'.http_build_query(array_filter(['cat'=>$_GET['cat']??'','dt'=>$_GET['dt']??'','qual'=>$_GET['qual']??'','batch'=>$_GET['batch']??'','date_sub'=>$_GET['date_sub']??'']))); exit;
}

$filterCat=(int)($_GET['cat']??0); $filterDt=(int)($_GET['dt']??0); $filterQual=(int)($_GET['qual']??0); $focusDocId=(int)($_GET['doc_id']??0);
$filterBatch = trim((string)($_GET['batch'] ?? ''));
$filterDateSubRaw = trim((string)($_GET['date_sub'] ?? ''));
$filterDateSub = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateSubRaw) ? $filterDateSubRaw : '';
$where=['d.system_id=?','d.is_archived=0']; $params=[$sid];
if($filterCat){ $where[]='d.category_id=?'; $params[]=$filterCat; }
if($filterDt){  $where[]='d.document_type_id=?'; $params[]=$filterDt; }
if($filterQual){
    if ($hasDocumentQualificationTable) {
        $where[]='(d.qualification_id=? OR EXISTS(SELECT 1 FROM document_qualifications dqf WHERE dqf.document_id=d.id AND dqf.qualification_id=?))';
        $params[]=$filterQual;
        $params[]=$filterQual;
    } else {
        $where[]='d.qualification_id=?';
        $params[]=$filterQual;
    }
}
if($filterBatch !== ''){ $where[]='d.batch_no=?'; $params[]=$filterBatch; }
if($filterDateSub){ $where[]='d.date_submission=?'; $params[]=$filterDateSub; }
if($focusDocId){ $where[]='d.id=?'; $params[]=$focusDocId; }
$qualSelect = 'q.name AS qual_name, CAST(d.qualification_id AS CHAR) AS qual_ids';
$qualJoin = '';
if ($hasDocumentQualificationTable) {
    $qualSelect = "COALESCE(NULLIF(qa.qual_names, ''), q.name) AS qual_name, COALESCE(NULLIF(qa.qual_ids, ''), CAST(d.qualification_id AS CHAR)) AS qual_ids";
    $qualJoin = " LEFT JOIN (
        SELECT dq.document_id,
               GROUP_CONCAT(DISTINCT q2.name ORDER BY q2.name SEPARATOR ' / ') AS qual_names,
               GROUP_CONCAT(DISTINCT dq.qualification_id ORDER BY dq.qualification_id SEPARATOR ',') AS qual_ids
        FROM document_qualifications dq
        INNER JOIN qualifications q2 ON q2.id = dq.qualification_id
        GROUP BY dq.document_id
    ) qa ON qa.document_id = d.id";
}
$stmt=$pdo->prepare("SELECT d.*,c.name AS cat_name,dt.name AS doc_type_name,$qualSelect FROM documents d LEFT JOIN categories c ON d.category_id=c.id LEFT JOIN document_types dt ON d.document_type_id=dt.id LEFT JOIN qualifications q ON d.qualification_id=q.id$qualJoin WHERE ".implode(' AND ',$where)." ORDER BY d.created_at DESC");
$stmt->execute($params); $documents=$stmt->fetchAll();

$dateLinesMap = [];
foreach ($documents as $doc) {
    $rawDate = trim((string)($doc['date_submission'] ?? ''));
    $key = $rawDate !== '' ? $rawDate : '__none__';

    if (!isset($dateLinesMap[$key])) {
        $dateLinesMap[$key] = [
            'value' => $key,
            'label' => $rawDate !== '' ? date('F d, Y', strtotime($rawDate)) : 'No Date Submitted',
            'sort' => $rawDate !== '' ? $rawDate : '0000-00-00',
            'count' => 0,
            'with_files' => 0,
        ];
    }

    $dateLinesMap[$key]['count']++;
    if (!empty(trim((string)($doc['image_path'] ?? '')))) {
        $dateLinesMap[$key]['with_files']++;
    }
}

$dateLines = array_values($dateLinesMap);
usort($dateLines, static function(array $a, array $b): int {
    return strcmp((string)$b['sort'], (string)$a['sort']);
});

$cats=$pdo->prepare('SELECT * FROM categories WHERE system_id=? ORDER BY name'); $cats->execute([$sid]); $categories=$cats->fetchAll();
$dts=$pdo->prepare('SELECT * FROM document_types WHERE system_id=? ORDER BY name'); $dts->execute([$sid]); $documentTypes=$dts->fetchAll();
$quals=$pdo->prepare('SELECT * FROM qualifications WHERE system_id=? ORDER BY name'); $quals->execute([$sid]); $qualifications=$quals->fetchAll();
$batchFilterStmt = $pdo->prepare('SELECT DISTINCT batch_no FROM documents WHERE system_id=? AND is_archived=0 AND batch_no IS NOT NULL AND TRIM(batch_no)<>"" ORDER BY batch_no');
$batchFilterStmt->execute([$sid]);
$batchFilterOptions = $batchFilterStmt->fetchAll(PDO::FETCH_COLUMN);

$dtByCat=[];
foreach($documentTypes as $dt){ $cid=(int)($dt['category_id']??0); $dtByCat[$cid][]=['id'=>$dt['id'],'name'=>$dt['name']]; }
$dtAll=array_map(fn($dt)=>['id'=>$dt['id'],'name'=>$dt['name']],$documentTypes);
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
        .table-responsive {
            max-height: 72vh;
            overflow: auto;
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(247,250,255,0.98));
        }
        #documentsTable {
            min-width: 1960px;
            border-collapse: separate;
            border-spacing: 0 9px;
            margin: 0;
            padding: 0 8px 10px;
        }
        #documentsTable thead th {
            position: sticky;
            top: 0;
            z-index: 4;
            white-space: nowrap;
            box-shadow: inset 0 -1px 0 rgba(255,255,255,0.08);
        }
        #documentsTable td { white-space: nowrap; }
        #documentsTable tbody td {
            background: #fff;
            color: #253449;
            border-top: 1px solid #e5ebf3;
            border-bottom: 1px solid #e5ebf3;
            padding: 10px 11px;
            font-size: .79rem;
            vertical-align: middle;
        }
        #documentsTable tbody td:first-child {
            border-left: 1px solid #e5ebf3;
            border-radius: 12px 0 0 12px;
        }
        #documentsTable tbody td:last-child {
            border-right: 1px solid #e5ebf3;
            border-radius: 0 12px 12px 0;
        }
        #documentsTable tbody tr {
            transition: transform .16s ease, filter .16s ease;
        }
        #documentsTable tbody tr:hover {
            transform: translateY(-1px);
            filter: drop-shadow(0 6px 12px rgba(15, 35, 60, 0.08));
        }
        #documentsTable tbody tr:hover td {
            background: #f8fbff;
            border-top-color: #dbe5f2;
            border-bottom-color: #dbe5f2;
        }
        .theme-blossom #documentsTable tbody tr:hover td {
            background: #fff8f8;
            border-top-color: #efd9d9;
            border-bottom-color: #efd9d9;
        }

        #documentsTable .row-check {
            width: 16px;
            height: 16px;
            accent-color: var(--accent, #2a4d7a);
            cursor: pointer;
        }
        #documentsTable .td-check {
            text-align: center;
        }

        #documentsTable .doc-pill,
        #documentsTable .date-chip {
            display: inline-flex;
            align-items: center;
            max-width: 210px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-radius: 999px;
            padding: 4px 11px;
            font-weight: 600;
            line-height: 1.2;
        }
        #documentsTable .doc-pill {
            border: 1px solid #d8e2ef;
            background: #f6f9fd;
            color: #2b3f5e;
        }
        #documentsTable .doc-pill-cat {
            background: color-mix(in srgb, var(--accent-light, #e5eef8) 88%, white 12%);
            border-color: color-mix(in srgb, var(--accent, #638ECB) 26%, #d8e2ef 74%);
            color: var(--accent-dark, #0e2240);
        }
        #documentsTable .doc-pill-type {
            background: #eef5ff;
            border-color: #cfdff3;
        }
        #documentsTable .doc-pill-qual,
        #documentsTable .doc-pill-sub,
        #documentsTable .doc-pill-batch {
            background: #f8fafd;
            border-color: #dce4ef;
            color: #3b4d64;
        }

        #documentsTable .date-chip {
            border: 1px solid #d7e3f2;
            background: #f3f8ff;
            color: #2f4b70;
            font-weight: 700;
            font-size: .74rem;
            letter-spacing: .01em;
        }

        #documentsTable .remark-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .02em;
            border: 1px solid transparent;
        }
        #documentsTable .remark-received {
            background: #e9f9f0;
            color: #15603c;
            border-color: #c6ead6;
        }
        #documentsTable .remark-returned {
            background: #fff4e8;
            color: #7a4a08;
            border-color: #efd4b0;
        }
        .theme-blossom #documentsTable .remark-returned {
            background: #fff1f1;
            color: #8a3e3e;
            border-color: #eac8c8;
        }

        #documentsTable .empty-cell {
            color: #9aa8b8;
            font-style: italic;
        }

        #documentsTable .img-cell-wrap {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid #d8e2ef;
            background: #f3f7fd;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            padding: 0;
        }
        #documentsTable .img-cell-wrap:hover {
            border-color: #b8cde6;
            background: #edf4fc;
        }
        #documentsTable .doc-gallery-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        #documentsTable .img-more-badge {
            position: absolute;
            top: 1px;
            right: 1px;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: rgba(33, 37, 41, 0.86);
            color: #fff;
            font-size: .58rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            line-height: 1;
            pointer-events: none;
            border: 1px solid rgba(255, 255, 255, 0.55);
        }

        #docFilesGalleryModal .modal-dialog {
            max-width: 980px;
        }
        .doc-gallery-viewer {
            min-height: 58vh;
            max-height: 70vh;
            border: 1px solid #dbe4f1;
            border-radius: 12px;
            background: #f7faff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .doc-gallery-viewer img {
            max-width: 100%;
            max-height: 68vh;
            object-fit: contain;
            display: block;
        }
        .doc-gallery-viewer iframe {
            width: 100%;
            height: 68vh;
            border: 0;
            background: #fff;
        }
        .doc-gallery-thumbs {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding: 2px 2px 4px;
            overflow-x: auto;
        }
        .doc-gallery-thumb-btn {
            width: 74px;
            height: 74px;
            border-radius: 10px;
            border: 1px solid #d6dfec;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            flex: 0 0 auto;
        }
        .doc-gallery-thumb-btn.is-active {
            border-color: var(--accent, #3576bd);
            box-shadow: 0 0 0 .15rem rgba(53, 118, 189, .16);
        }
        .doc-gallery-thumb-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
            display: block;
        }
        .doc-gallery-pdf-chip {
            min-width: 58px;
            height: 48px;
            border-radius: 8px;
            border: 1px solid #f1b8c0;
            background: #fff1f3;
            color: #b52536;
            font-size: .72rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 0 8px;
        }

        #documentsTable .td-actions .btn-action {
            width: 34px;
            height: 34px;
            border-radius: 10px;
        }

        #documentsTable .td-img {
            width: 74px;
            text-align: center;
        }

        .slide-dt-foot {
            font-weight: 600;
            color: #667a92;
            background: #fff;
        }
        #documentsTable th:nth-child(2), #documentsTable td:nth-child(2) { min-width: 190px; }
        #documentsTable th:nth-child(3), #documentsTable td:nth-child(3) { min-width: 190px; }
        #documentsTable th:nth-child(4), #documentsTable td:nth-child(4) { min-width: 150px; }
        #documentsTable th:nth-child(5), #documentsTable td:nth-child(5) { min-width: 190px; }
        #documentsTable th:nth-child(6), #documentsTable td:nth-child(6) { min-width: 130px; }
        #documentsTable th:nth-child(7), #documentsTable td:nth-child(7),
        #documentsTable th:nth-child(8), #documentsTable td:nth-child(8),
        #documentsTable th:nth-child(9), #documentsTable td:nth-child(9),
        #documentsTable th:nth-child(10), #documentsTable td:nth-child(10),
        #documentsTable th:nth-child(11), #documentsTable td:nth-child(11),
        #documentsTable th:nth-child(12), #documentsTable td:nth-child(12) { min-width: 140px; }
        #documentsTable th:nth-child(13), #documentsTable td:nth-child(13) { min-width: 220px; }
        #documentsTable th:nth-child(14), #documentsTable td:nth-child(14) { min-width: 86px; }
        #documentsTable th:nth-child(15), #documentsTable td:nth-child(15) { min-width: 100px; }

        #editCoreModal .modal-dialog {
            max-width: 840px;
        }
        #editCoreModal .modal-content {
            max-height: 88vh;
            display: flex;
            flex-direction: column;
        }
        #editCoreModal .modal-body {
            max-height: calc(88vh - 186px);
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px 18px;
        }
        #editCoreModal .row.g-3 {
            --bs-gutter-y: .7rem;
            --bs-gutter-x: .8rem;
        }
        #editCoreModal .form-label {
            margin-bottom: .25rem;
            font-size: .95rem;
        }
        #editCoreModal .form-control,
        #editCoreModal .form-select,
        #editCoreModal .edit-qual-btn {
            min-height: 38px;
            padding-top: 7px;
            padding-bottom: 7px;
            font-size: .9rem;
        }
        #editCoreModal .modal-footer {
            background: #fff;
            border-top: 1px solid #e7edf5;
            box-shadow: 0 -8px 16px rgba(14, 30, 45, 0.08);
            padding: 12px 18px;
            position: relative;
            z-index: 2;
        }
        #editCoreModal .modal-footer .btn {
            min-height: 38px;
            padding: 7px 16px;
            border-radius: 10px;
        }

        #editPasswordModal .modal-dialog {
            max-width: 420px;
        }
        #editPasswordModal .modal-header {
            padding: 14px 18px;
        }
        #editPasswordModal .modal-header .modal-title {
            color: #fff;
            line-height: 1.1;
            margin-bottom: 2px;
        }
        #editPasswordModal .modal-hdr-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            align-self: center;
        }
        #editPasswordModal .modal-subtitle {
            display: block;
            color: rgba(255, 255, 255, 0.82);
            font-size: .84rem;
            line-height: 1.25;
        }

        .date-lines-wrap {
            border: 1px solid #dce5f2;
            border-radius: 14px;
            background: #fff;
            overflow: hidden;
        }
        .date-lines-head {
            display: grid;
            grid-template-columns: 1.4fr .8fr .8fr auto;
            gap: 10px;
            padding: 10px 16px;
            background: #f6f9ff;
            border-bottom: 1px solid #e1e9f5;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #607692;
        }
        .date-line-item {
            width: 100%;
            border: 0;
            border-bottom: 1px solid #edf1f7;
            background: #fff;
            display: grid;
            grid-template-columns: 1.4fr .8fr .8fr auto;
            gap: 10px;
            align-items: center;
            padding: 12px 16px;
            text-align: left;
            color: #1f2f46;
            transition: background .15s ease;
        }
        .date-line-item:last-child {
            border-bottom: 0;
        }
        .date-line-item:hover {
            background: #f8fbff;
        }
        .date-line-date {
            font-weight: 700;
            color: #1f3a5d;
        }
        .date-line-count,
        .date-line-files {
            color: #5a6e87;
            font-weight: 600;
        }
        .date-line-action {
            justify-self: end;
            color: #2c66a0;
            font-weight: 700;
            font-size: .8rem;
        }
        #dateDocsModal .modal-dialog {
            max-width: 96vw;
        }
        #dateDocsModal .modal-body {
            padding: 0;
        }
        .modal-doc-toolbar {
            padding: 12px 14px;
            border-bottom: 1px solid #e1e8f3;
            background: #f8fbff;
        }
        .modal-doc-toolbar .dt-toolbar-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .edit-qual-picker {
            position: relative;
        }
        .edit-qual-btn {
            width: 100%;
            text-align: left;
            border-radius: 10px;
            border: 1px solid #ced9e8;
            background: #fff;
            min-height: 40px;
            padding: 8px 34px 8px 12px;
            color: #2b3f5d;
            font-weight: 600;
            font-size: .86rem;
            position: relative;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .edit-qual-btn::after {
            content: '';
            position: absolute;
            right: 12px;
            top: 50%;
            margin-top: -2px;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 6px solid #6b7788;
            pointer-events: none;
        }
        .edit-qual-btn.is-filled {
            border-color: rgba(53, 118, 189, .45);
            background: linear-gradient(180deg, #edf5ff, #f8fbff);
            color: #1f4775;
        }
        .edit-qual-menu {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            max-height: 190px;
            overflow-y: auto;
            border: 1px solid #cfd9e8;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 26px rgba(17, 34, 56, .18);
            z-index: 35;
            padding: 8px;
        }
        .edit-qual-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 6px;
            border-radius: 8px;
            color: #2b3f5d;
            font-size: .84rem;
            cursor: pointer;
        }
        .edit-qual-item:hover {
            background: #f3f8ff;
        }
        .edit-qual-item input {
            margin: 0;
        }

        .theme-blossom .edit-qual-btn {
            border-color: #e5cea2;
            color: #5a1220;
            background: #fff;
        }
        .theme-blossom .edit-qual-btn.is-filled {
            border-color: rgba(155, 28, 51, .45);
            background: linear-gradient(180deg, #fff3d6, #fff9eb);
            color: #6c1525;
        }
        .theme-blossom .edit-qual-menu {
            border-color: #e5cea2;
        }
        .theme-blossom .edit-qual-item {
            color: #5a1220;
        }
        .theme-blossom .edit-qual-item:hover {
            background: #fff4d7;
        }

        .theme-blossom .date-lines-wrap {
            border-color: #eedbb4;
            background: #fffdf5;
        }
        .theme-blossom .date-lines-head {
            background: linear-gradient(180deg, #fff7dc, #ffefc1);
            border-bottom-color: #efd9a8;
            color: #6c1525;
        }
        .theme-blossom .date-line-item {
            background: #fff;
            border-bottom-color: #f3e6c7;
            color: #4f1220;
        }
        .theme-blossom .date-line-item:hover {
            background: #fff7de;
        }
        .theme-blossom .date-line-date {
            color: #6c1525;
        }
        .theme-blossom .date-line-count,
        .theme-blossom .date-line-files {
            color: #7d5515;
        }
        .theme-blossom .date-line-action {
            color: #8f1f31;
        }

        .theme-blossom .modal-doc-toolbar {
            background: linear-gradient(180deg, #fffef7, #fff6dd);
            border-bottom-color: #efd9a8;
        }

        .theme-blossom #documentsTable tbody tr:hover td {
            background: #fff8e3;
            border-top-color: #edd9ac;
            border-bottom-color: #edd9ac;
        }
        .theme-blossom #documentsTable .doc-pill-type {
            background: #fff7df;
            border-color: #ecd7ab;
            color: #633a20;
        }
        .theme-blossom #documentsTable .doc-pill-qual,
        .theme-blossom #documentsTable .doc-pill-sub,
        .theme-blossom #documentsTable .doc-pill-batch {
            background: #fffdf5;
            border-color: #ecddb9;
            color: #5b2231;
        }
        .theme-blossom #documentsTable .date-chip {
            border-color: #ecd7ab;
            background: #fff6dd;
            color: #6c1525;
        }
        .theme-blossom #documentsTable .img-cell-wrap {
            border-color: #e9d5ae;
            background: #fff8e7;
        }
        .theme-blossom #documentsTable .img-cell-wrap:hover {
            border-color: #d6ab57;
            background: #fff2cf;
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
            <input type="date" name="date_sub" class="dt-filter-select" value="<?= htmlspecialchars($filterDateSub) ?>" onchange="this.form.submit()" title="Filter by Date Submitted">
            <?php if ($filterDateSub): ?><a href="documents_tracking.php" class="dt-filter-clear"><i class="bi bi-x-circle me-1"></i>Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="date-lines-wrap mb-3">
        <div class="date-lines-head">
            <span>Date Submitted</span>
            <span>Rows</span>
            <span>With Files</span>
            <span class="text-end">Action</span>
        </div>
        <?php if (!$dateLines): ?>
            <div class="px-3 py-4 text-center text-muted">No records found.</div>
        <?php else: ?>
            <?php foreach ($dateLines as $line): ?>
                <button
                    type="button"
                    class="date-line-item js-open-date-docs"
                    data-date="<?= htmlspecialchars((string)$line['value']) ?>"
                    data-label="<?= htmlspecialchars((string)$line['label']) ?>"
                >
                    <span class="date-line-date"><?= htmlspecialchars((string)$line['label']) ?></span>
                    <span class="date-line-count"><?= (int)$line['count'] ?> row(s)</span>
                    <span class="date-line-files"><?= (int)$line['with_files'] ?> file row(s)</span>
                    <span class="date-line-action">View Details <i class="bi bi-chevron-right ms-1"></i></span>
                </button>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" value="bulk_archive">

        <div class="modal fade" id="dateDocsModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-table me-2"></i>Documents for <span id="dateDocsModalLabel">Selected Date</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="modal-doc-toolbar">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <div class="dt-toolbar-filters">
                                    <select id="modalFilterCat" class="dt-filter-select">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="modalFilterDt" class="dt-filter-select">
                                        <option value="">All Doc Types</option>
                                        <?php foreach ($documentTypes as $dt): ?>
                                            <option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="modalFilterQual" class="dt-filter-select">
                                        <option value="">All Qualifications</option>
                                        <?php foreach ($qualifications as $q): ?>
                                            <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="modalFilterBatch" class="dt-filter-select">
                                        <option value="">All Batches</option>
                                        <?php foreach ($batchFilterOptions as $batch): ?>
                                            <option value="<?= htmlspecialchars(strtolower(trim((string)$batch))) ?>"><?= htmlspecialchars((string)$batch) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="modalClearFilters">
                                        <i class="bi bi-x-circle me-1"></i>Clear
                                    </button>
                                </div>
                                <div class="dt-toolbar-actions no-print">
                                    <button type="button" class="btn btn-modern btn-warning" id="bulkArchiveBtn"><i class="bi bi-archive"></i>Archive</button>
                                    <button type="button" class="btn btn-modern btn-primary" id="uploadBatchFilesBtn"><i class="bi bi-cloud-upload"></i>Upload Batch Files</button>
                                    <button type="button" class="btn btn-modern btn-danger" id="downloadBatchPdfBtn"><i class="bi bi-file-earmark-pdf"></i>Download Batch PDF</button>
                                    <button type="button" class="btn btn-modern btn-secondary" id="printBtn" data-print-signature="1"><i class="bi bi-printer"></i>Print</button>
                                </div>
                            </div>
                        </div>
                        <div class="slide-table-wrap">
                            <div class="slide-dt-bar">
                                <div class="slide-dt-length">Show <select id="dtLengthSelect" class="slide-dt-select"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option><option value="-1">All</option></select> entries</div>
                                <div class="slide-dt-search"><i class="bi bi-search"></i><input type="search" id="dtSearchInput" placeholder="Quick search…"></div>
                            </div>
                            <div class="table-responsive">
                                <table id="documentsTable" class="slide-table">
                                    <thead><tr>
                                        <th><input type="checkbox" id="selectAll" title="Select All"></th>
                                        <th>Documents / Category</th><th>Doc Type</th><th>Qualification</th>
                                        <th>Batch No.</th><th>Date Submitted</th><th>Received by TESDA</th><th>Returned to Center</th>
                                        <th>Staff Received</th><th>Date of Assessment</th><th>Assessor Name</th><th>TESDA Released</th>
                                        <th>Remarks</th><th>Image</th><th class="no-print">Actions</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($documents as $doc):
                                        $dv=fn($v)=>htmlspecialchars($v??'');
                                        $pill = function($v, $cls='') {
                                            $val = trim((string)($v ?? ''));
                                            if ($val === '') return '<span class="empty-cell">—</span>';
                                            return '<span class="doc-pill '.$cls.'">'.htmlspecialchars($val).'</span>';
                                        };
                                        $dateChip = function($d) {
                                            if (empty($d)) return '<span class="empty-cell">—</span>';
                                            return '<span class="date-chip">'.htmlspecialchars(date('M d, Y', strtotime((string)$d))).'</span>';
                                        };
                                        $remarkVal = strtolower(trim((string)($doc['remarks'] ?? '')));
                                        $remarkHtml = '<span class="empty-cell">—</span>';
                                        if ($remarkVal === 'received') {
                                            $remarkHtml = '<span class="remark-chip remark-received">Received</span>';
                                        } elseif ($remarkVal === 'returned') {
                                            $remarkHtml = '<span class="remark-chip remark-returned">Returned</span>';
                                        }
                                        $rowDateValue = trim((string)($doc['date_submission'] ?? '')) ?: '__none__';
                                        $rowBatchKey = strtolower(trim((string)($doc['batch_no'] ?? '')));
                                        $rowQualIdsRaw = trim((string)($doc['qual_ids'] ?? ''));
                                        $fallbackQualId = (int)($doc['qualification_id'] ?? 0);
                                        $editQualIds = $rowQualIdsRaw !== '' ? $rowQualIdsRaw : ($fallbackQualId > 0 ? (string)$fallbackQualId : '');
                                        $rowQualIdsToken = $rowQualIdsRaw !== '' ? ',' . $rowQualIdsRaw . ',' : '';
                                    ?>
                                    <tr
                                        data-date-sub="<?= htmlspecialchars($rowDateValue) ?>"
                                        data-cat-id="<?= (int)($doc['category_id'] ?? 0) ?>"
                                        data-dt-id="<?= (int)($doc['document_type_id'] ?? 0) ?>"
                                        data-qual-ids="<?= htmlspecialchars($rowQualIdsToken) ?>"
                                        data-batch-key="<?= htmlspecialchars($rowBatchKey) ?>"
                                    >
                                        <td class="td-check"><input type="checkbox" name="selected_ids[]" value="<?= $doc['id'] ?>" class="row-check"></td>
                                        <td class="td-fixed"><?= $pill($doc['cat_name'], 'doc-pill-cat') ?></td>
                                        <td class="td-fixed"><?= $pill($doc['doc_type_name'], 'doc-pill-type') ?></td>
                                        <td class="td-fixed"><?= $pill($doc['qual_name'], 'doc-pill-qual') ?></td>
                                        <td class="td-fixed"><?= $pill($doc['batch_no'], 'doc-pill-batch') ?></td>
                                        <td class="td-fixed"><?= $dateChip($doc['date_submission']) ?></td>
                                        <td class="td-fixed"><?= $dateChip($doc['received_tesda']) ?></td>
                                        <td class="td-fixed"><?= $dateChip($doc['returned_center']) ?></td>
                                        <td class="td-fixed"><?= $pill($doc['staff_received'], 'doc-pill-batch') ?></td>
                                        <td class="td-fixed"><?= $dateChip($doc['date_assessment']) ?></td>
                                        <td class="td-fixed"><?= $pill($doc['assessor_name'], 'doc-pill-batch') ?></td>
                                        <td class="td-fixed"><?= $dateChip($doc['tesda_released']) ?></td>
                                        <td class="td-fixed"><?= $remarkHtml ?></td>
                                        <td class="td-img">
                                            <?php $docFiles = parseStoredImagePaths($doc['image_path'] ?? null); ?>
                                            <?php if($docFiles): ?>
                                                <?php
                                                    $previewFiles = array_slice($docFiles, 0, 20);
                                                    $thumbPath = (string)$previewFiles[0];
                                                    $previewUrls = array_map(
                                                        static fn($path) => '../' . ltrim((string)$path, '/'),
                                                        $previewFiles
                                                    );
                                                    $filesJson = htmlspecialchars(
                                                        (string)json_encode($previewUrls, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                ?>
                                                <button
                                                    type="button"
                                                    class="img-cell-wrap js-doc-gallery-trigger"
                                                    data-files="<?= $filesJson ?>"
                                                    title="View <?= count($previewFiles) ?> file(s)"
                                                >
                                                    <img src="../<?= htmlspecialchars($thumbPath) ?>" class="doc-gallery-thumb" alt="doc" onerror="this.style.display='none'">
                                                    <?php if (count($previewFiles) > 1): ?>
                                                        <span class="img-more-badge">+<?= count($previewFiles) - 1 ?></span>
                                                    <?php endif; ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="empty-cell">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-actions no-print">
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-action btn-outline-primary js-request-edit"
                                                    title="Edit row"
                                                    data-doc-id="<?= (int)$doc['id'] ?>"
                                                    data-category-id="<?= (int)($doc['category_id'] ?? 0) ?>"
                                                    data-doc-type-id="<?= (int)($doc['document_type_id'] ?? 0) ?>"
                                                    data-qualification-ids="<?= htmlspecialchars($editQualIds, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-date-submission="<?= htmlspecialchars((string)($doc['date_submission'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-batch-no="<?= htmlspecialchars((string)($doc['batch_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-received-tesda="<?= htmlspecialchars((string)($doc['received_tesda'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-returned-center="<?= htmlspecialchars((string)($doc['returned_center'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-staff-received="<?= htmlspecialchars((string)($doc['staff_received'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-date-assessment="<?= htmlspecialchars((string)($doc['date_assessment'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-assessor-name="<?= htmlspecialchars((string)($doc['assessor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-tesda-released="<?= htmlspecialchars((string)($doc['tesda_released'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-remarks="<?= htmlspecialchars((string)($doc['remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                ><i class="bi bi-pencil-square"></i></button>

                                                <a href="documents_tracking.php?archive_id=<?= $doc['id'] ?>"
                                                   class="btn btn-action btn-outline-warning"
                                                   data-confirm-message="Archive this document?"
                                                   data-confirm-text="Archive"
                                                   data-confirm-class="btn btn-warning"><i class="bi bi-archive"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="slide-dt-foot" id="dtInfoRow">Showing <?= count($documents) ?> record(s)</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
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

<!-- EDIT PASSWORD MODAL -->
<div class="modal fade" id="editPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-hdr-icon"><i class="bi bi-shield-lock"></i></div>
                    <div>
                        <h5 class="modal-title mb-0">Confirm Password</h5>
                        <small class="modal-subtitle">Required before editing this row</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPasswordForm" novalidate>
                <div class="modal-body">
                    <label for="editPasswordInput" class="form-label fw-semibold">Account Password</label>
                    <input type="password" id="editPasswordInput" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary"><i class="bi bi-arrow-right-circle me-1"></i>Continue</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT CORE MODAL -->
<div class="modal fade" id="editCoreModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-hdr-icon"><i class="bi bi-pencil-square"></i></div>
                    <div><h5 class="modal-title mb-0">Edit Document</h5><small style="color:rgba(255,255,255,.55);font-size:.72rem">Update full document details</small></div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCoreForm">
                <input type="hidden" name="action" value="edit_core">
                <input type="hidden" name="doc_id" id="editCore_docId">
                <input type="hidden" name="account_password" id="editCore_password">
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
                        <div class="col-12"><label class="form-label fw-semibold">Qualification(s)</label>
                            <div id="editCoreQualPicker" class="edit-qual-picker">
                                <button type="button" id="editCoreQualBtn" class="edit-qual-btn js-edit-qual-btn">— None —</button>
                                <div id="editCoreQualMenu" class="edit-qual-menu d-none">
                                    <?php foreach($qualifications as $q): ?>
                                        <label class="edit-qual-item">
                                            <input type="checkbox" class="js-edit-qual-option" name="qualification_ids[]" value="<?=$q['id']?>" data-name="<?=htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8')?>">
                                            <span><?=htmlspecialchars($q['name'])?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Date of Submission</label><input type="date" name="date_submission" id="editCore_dateSub" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Batch No.</label><input type="text" name="batch_no" id="editCore_batchNo" class="form-control" placeholder="e.g. Batch 51"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Received (TESDA)</label><input type="date" name="received_tesda" id="editCore_receivedTesda" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Returned (Center)</label><input type="date" name="returned_center" id="editCore_returnedCenter" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Staff Received</label><input type="text" name="staff_received" id="editCore_staffReceived" class="form-control" placeholder="Staff name"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Date of Assessment</label><input type="date" name="date_assessment" id="editCore_dateAssessment" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Assessor Name</label><input type="text" name="assessor_name" id="editCore_assessorName" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">TESDA Released</label><input type="date" name="tesda_released" id="editCore_tesdaReleased" class="form-control"></div>
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
                <input type="hidden" name="action" value="upload_image">
                <input type="hidden" name="doc_id" id="imgUpload_docId">
                <input type="hidden" name="replace_existing" id="imgUpload_replace" value="0">
                <div class="modal-body">
                    <label class="form-label fw-semibold">Select File(s)</label>
                    <input type="file" name="doc_image[]" class="form-control" accept="image/*,.pdf" multiple required>
                    <div id="imgUpload_note" class="form-text mt-2 text-muted" style="display:none"></div>
                    <div class="form-text mt-2">JPG, PNG, GIF, WEBP or PDF · max 5 MB per file · up to 20 files</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-tb5-primary"><i class="bi bi-upload me-1"></i>Upload</button></div>
            </form>
        </div>
    </div>
</div>

<!-- BATCH UPLOAD MODAL -->
<div class="modal fade" id="batchUploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header"><div class="d-flex align-items-center gap-2"><div class="modal-hdr-icon"><i class="bi bi-cloud-upload"></i></div><h5 class="modal-title mb-0">Upload Batch Files</h5></div><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data" id="batchUploadForm">
                <input type="hidden" name="action" value="upload_batch_images">
                <input type="hidden" name="date_submission" id="batchUpload_date">
                <input type="hidden" name="replace_existing" id="batchUpload_replace" value="1">
                <input type="hidden" name="account_password" id="batchUpload_account_password">
                <div class="modal-body">
                    <label class="form-label fw-semibold">Select File(s)</label>
                    <input type="file" name="shared_files[]" class="form-control" accept="image/*,.pdf" multiple required>
                    <div id="batchUpload_note" class="form-text mt-2 text-muted" style="display:none"></div>
                    <div class="form-text mt-2">JPG, PNG, GIF, WEBP or PDF · max 5 MB per file · up to 20 files</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-tb5-primary"><i class="bi bi-upload me-1"></i>Upload</button></div>
            </form>
        </div>
    </div>
</div>

<!-- CONFIRM PASSWORD MODAL (for batch upload) -->
<div class="modal fade" id="confirmPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-hdr-icon"><i class="bi bi-shield-lock"></i></div>
                    <div>
                        <h5 class="modal-title mb-0">Confirm Password</h5>
                        <small class="modal-subtitle">Required to upload files for this batch</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="confirmPasswordForm" novalidate>
                <div class="modal-body">
                    <label for="confirmPasswordInput" class="form-label fw-semibold">Account Password</label>
                    <input type="password" id="confirmPasswordInput" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-tb5-primary"><i class="bi bi-arrow-right-circle me-1"></i>Continue</button>
                </div>
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
            <div class="modal-footer"><button type="button" id="previewPrint" class="btn btn-outline-dark"><i class="bi bi-printer me-1"></i>Print</button><a id="previewOpen" class="btn btn-outline-primary" target="_blank">Open</a><a id="previewDownload" class="btn btn-outline-secondary" download>Download</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- DOCUMENT FILES GALLERY MODAL -->
<div class="modal fade" id="docFilesGalleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-images me-2"></i>Document Attachments</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="docGalleryViewer" class="doc-gallery-viewer">
                    <div id="docGalleryEmpty" class="text-muted">No file selected.</div>
                    <img id="docGalleryImage" class="d-none" alt="Document file preview">
                    <iframe id="docGalleryFrame" class="d-none" title="Document file preview"></iframe>
                </div>
                <div id="docGalleryThumbs" class="doc-gallery-thumbs"></div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <small id="docGalleryCounter" class="text-muted">0 / 0</small>
                <div class="d-flex gap-2">
                    <a id="docGalleryOpen" class="btn btn-outline-primary btn-sm disabled" target="_blank" rel="noopener">Open File</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const DT_BY_CAT = <?= json_encode($dtByCat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const DT_ALL    = <?= json_encode($dtAll,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const QUALS     = <?= json_encode(array_map(fn($q)=>['id'=>$q['id'],'name'=>$q['name']],$qualifications), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
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

        // Remarks select
        const remarksSel = document.createElement('select');
        remarksSel.name = 'remarks[]';
        remarksSel.className = 'form-select form-select-sm';
        remarksSel.innerHTML =
            '<option value="">—</option>' +
            '<option value="received">Received</option>' +
            '<option value="returned">Returned</option>';
        if (def.remarks) remarksSel.value = def.remarks;

        [numTd,
         wrap(catSel),
         wrap(dtSel),
         wrap(qualSel),
         wrap(inp('batch_no','text','e.g. 51401-001')),
         wrap(inp('date_submission','date')),
         wrap(receivedInp),
         wrap(returnedInp),
         wrap(inp('staff_received','text','Staff name')),
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
        document.getElementById('btnClearRows').addEventListener('click', function () {
            showConfirmModal('Clear all rows?', {
                title: 'Clear Rows',
                confirmText: 'Clear',
                confirmClass: 'btn btn-danger'
            }).then(function (ok) {
                if (ok) resetModal();
            });
        });

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
            if (rows.length===0){
                e.preventDefault();
                showToast('Add at least one row before saving.', 'warning');
                return;
            }
            let bad = false;
            rows.forEach(tr=>{
                const cat = tr.querySelector('[name="row_category_id[]"]').value;
                const dt  = tr.querySelector('[name="row_document_type_id[]"]').value;
                if (!cat||!dt){ tr.classList.add('row-invalid'); bad=true; }
                else tr.classList.remove('row-invalid');
            });
            if (bad){
                e.preventDefault();
                showToast('Rows highlighted in red are missing a Category or Document Type.', 'warning');
            }
        });
    });
})();

(function(){
    function normalizeId(value) {
        const raw = String(value || '').trim();
        return raw === '0' ? '' : raw;
    }

    function filterEditDocTypes(catId, selectedDocTypeId) {
        const dtSel = document.getElementById('editCore_dtId');
        if (!dtSel) return;

        const wantedCat = normalizeId(catId);
        const wantedDt = normalizeId(selectedDocTypeId);

        Array.from(dtSel.options).forEach(function(option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            const optionCat = String(option.getAttribute('data-cat') || '').trim();
            const visible = wantedCat === '' || optionCat === '' || optionCat === wantedCat;
            option.hidden = !visible;
            if (!visible && option.selected) {
                option.selected = false;
            }
        });

        dtSel.value = wantedDt;
        if (dtSel.selectedIndex > 0 && dtSel.options[dtSel.selectedIndex].hidden) {
            dtSel.value = '';
        }
    }

    function setEditQualPickerValues(picker, values) {
        if (!picker) return;
        const wanted = Array.isArray(values) ? values.map(function(v) { return String(v); }) : [];
        const checks = picker.querySelectorAll('.js-edit-qual-option');
        const btn = picker.querySelector('.js-edit-qual-btn');

        checks.forEach(function(check) {
            check.checked = wanted.includes(String(check.value));
        });

        const selectedNames = [];
        checks.forEach(function(check) {
            if (check.checked) {
                selectedNames.push(String(check.dataset.name || check.value));
            }
        });

        if (btn) {
            btn.textContent = selectedNames.length ? selectedNames.join('/') : '— None —';
            btn.classList.toggle('is-filled', selectedNames.length > 0);
        }
    }

    function closeEditQualMenu() {
        const menu = document.getElementById('editCoreQualMenu');
        if (menu) {
            menu.classList.add('d-none');
        }
    }

    function notify(message, type) {
        if (typeof showToast === 'function') {
            showToast(message, type || 'info');
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        const editPasswordModalEl = document.getElementById('editPasswordModal');
        const editPasswordForm = document.getElementById('editPasswordForm');
        const editPasswordInput = document.getElementById('editPasswordInput');
        const editModalEl = document.getElementById('editCoreModal');
        const editForm = document.getElementById('editCoreForm');
        const editCatField = document.getElementById('editCore_catId');
        const editDocIdField = document.getElementById('editCore_docId');
        const editDtField = document.getElementById('editCore_dtId');
        const editQualPicker = document.getElementById('editCoreQualPicker');
        const editQualBtn = document.getElementById('editCoreQualBtn');
        const editQualMenu = document.getElementById('editCoreQualMenu');
        const editDateSubField = document.getElementById('editCore_dateSub');
        const editBatchField = document.getElementById('editCore_batchNo');
        const editReceivedTesdaField = document.getElementById('editCore_receivedTesda');
        const editReturnedCenterField = document.getElementById('editCore_returnedCenter');
        const editStaffReceivedField = document.getElementById('editCore_staffReceived');
        const editDateAssessmentField = document.getElementById('editCore_dateAssessment');
        const editAssessorNameField = document.getElementById('editCore_assessorName');
        const editTesdaReleasedField = document.getElementById('editCore_tesdaReleased');
        const editRemarksField = document.getElementById('editCore_remarks');
        const editPasswordHidden = document.getElementById('editCore_password');

        if (!editPasswordModalEl || !editPasswordForm || !editPasswordInput || !editModalEl || !editForm) {
            return;
        }

        const editPasswordModal = bootstrap.Modal.getOrCreateInstance(editPasswordModalEl);
        const editModal = bootstrap.Modal.getOrCreateInstance(editModalEl);
        let pendingEdit = null;
        let openEditAfterPasswordClose = false;

        if (editQualBtn && editQualMenu && editQualPicker) {
            editQualBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const shouldOpen = editQualMenu.classList.contains('d-none');
                closeEditQualMenu();
                if (shouldOpen) {
                    editQualMenu.classList.remove('d-none');
                }
            });

            editQualMenu.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            editQualPicker.querySelectorAll('.js-edit-qual-option').forEach(function(check) {
                check.addEventListener('change', function() {
                    const checkedValues = Array.from(editQualPicker.querySelectorAll('.js-edit-qual-option:checked'))
                        .map(function(ch) { return String(ch.value); });
                    setEditQualPickerValues(editQualPicker, checkedValues);
                });
            });

            setEditQualPickerValues(editQualPicker, []);
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#editCoreQualPicker')) {
                closeEditQualMenu();
            }
        });

        function hydrateEditForm(data, password) {
            if (!data) return;
            editDocIdField.value = normalizeId(data.docId);

            const catId = normalizeId(data.categoryId);
            const dtId = normalizeId(data.docTypeId);
            const qualIds = String(data.qualificationIds || '')
                .split(',')
                .map(function(v) { return normalizeId(v); })
                .filter(Boolean);

            editCatField.value = catId;
            filterEditDocTypes(catId, dtId);
            setEditQualPickerValues(editQualPicker, qualIds);
            editDateSubField.value = String(data.dateSubmission || '').trim();
            editBatchField.value = String(data.batchNo || '').trim();
            if (editReceivedTesdaField) editReceivedTesdaField.value = String(data.receivedTesda || '').trim();
            if (editReturnedCenterField) editReturnedCenterField.value = String(data.returnedCenter || '').trim();
            if (editStaffReceivedField) editStaffReceivedField.value = String(data.staffReceived || '').trim();
            if (editDateAssessmentField) editDateAssessmentField.value = String(data.dateAssessment || '').trim();
            if (editAssessorNameField) editAssessorNameField.value = String(data.assessorName || '').trim();
            if (editTesdaReleasedField) editTesdaReleasedField.value = String(data.tesdaReleased || '').trim();
            editRemarksField.value = String(data.remarks || '').trim();
            editPasswordHidden.value = password;
        }

        document.addEventListener('click', function(e){
            const trigger = e.target.closest('.js-request-edit');
            if (!trigger) return;

            pendingEdit = {
                docId: trigger.dataset.docId || '',
                categoryId: trigger.dataset.categoryId || '',
                docTypeId: trigger.dataset.docTypeId || '',
                qualificationIds: trigger.dataset.qualificationIds || '',
                dateSubmission: trigger.dataset.dateSubmission || '',
                batchNo: trigger.dataset.batchNo || '',
                receivedTesda: trigger.dataset.receivedTesda || '',
                returnedCenter: trigger.dataset.returnedCenter || '',
                staffReceived: trigger.dataset.staffReceived || '',
                dateAssessment: trigger.dataset.dateAssessment || '',
                assessorName: trigger.dataset.assessorName || '',
                tesdaReleased: trigger.dataset.tesdaReleased || '',
                remarks: trigger.dataset.remarks || ''
            };

            openEditAfterPasswordClose = false;
            editPasswordInput.value = '';
            editPasswordHidden.value = '';
            editPasswordModal.show();
        });

        editPasswordModalEl.addEventListener('shown.bs.modal', function(){
            editPasswordInput.focus();
        });

        editPasswordForm.addEventListener('submit', async function(e){
            e.preventDefault();
            if (!pendingEdit) return;

            const password = String(editPasswordInput.value || '').trim();
            if (password === '') {
                notify('Enter your account password to continue.', 'warning');
                editPasswordInput.focus();
                return;
            }

            const submitBtn = editPasswordForm.querySelector('button[type="submit"]');
            const defaultBtnHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Checking...';
            }

            try {
                const body = new URLSearchParams();
                body.set('action', 'verify_edit_password');
                body.set('account_password', password);

                const response = await fetch('documents_tracking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                });

                const result = await response.json();
                if (!response.ok || !result || result.ok !== true) {
                    notify((result && result.message) ? result.message : 'Unable to verify password right now.', 'danger');
                    editPasswordInput.focus();
                    return;
                }

                hydrateEditForm(pendingEdit, password);
                openEditAfterPasswordClose = true;
                editPasswordModal.hide();
            } catch (_err) {
                notify('Unable to verify password right now. Please try again.', 'danger');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = defaultBtnHtml;
                }
            }
        });

        editPasswordModalEl.addEventListener('hidden.bs.modal', function(){
            editPasswordInput.value = '';
            pendingEdit = null;

            if (openEditAfterPasswordClose) {
                openEditAfterPasswordClose = false;
                editModal.show();
            }
        });

        if (editCatField) {
            editCatField.addEventListener('change', function(){
                filterEditDocTypes(this.value, '');
            });
        }

        editModalEl.addEventListener('hidden.bs.modal', function(){
            if (editPasswordHidden) {
                editPasswordHidden.value = '';
            }
            if (editQualPicker) {
                setEditQualPickerValues(editQualPicker, []);
            }
            if (editDateSubField) editDateSubField.value = '';
            if (editBatchField) editBatchField.value = '';
            if (editReceivedTesdaField) editReceivedTesdaField.value = '';
            if (editReturnedCenterField) editReturnedCenterField.value = '';
            if (editStaffReceivedField) editStaffReceivedField.value = '';
            if (editDateAssessmentField) editDateAssessmentField.value = '';
            if (editAssessorNameField) editAssessorNameField.value = '';
            if (editTesdaReleasedField) editTesdaReleasedField.value = '';
            if (editRemarksField) editRemarksField.value = '';
            closeEditQualMenu();
        });

        if (editDtField) {
            filterEditDocTypes(editCatField ? editCatField.value : '', editDtField.value);
        }
    });
})();

(function(){
    function detectFileType(url) {
        return /\.pdf(?:$|[?#])/i.test(String(url || '')) ? 'pdf' : 'image';
    }

    document.addEventListener('DOMContentLoaded', function(){
        const modalEl = document.getElementById('docFilesGalleryModal');
        if (!modalEl) return;

        const galleryModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const viewerEmpty = document.getElementById('docGalleryEmpty');
        const viewerImage = document.getElementById('docGalleryImage');
        const viewerFrame = document.getElementById('docGalleryFrame');
        const thumbsWrap = document.getElementById('docGalleryThumbs');
        const counter = document.getElementById('docGalleryCounter');
        const openLink = document.getElementById('docGalleryOpen');

        let files = [];
        let activeIndex = 0;

        function setViewer(index) {
            if (!files.length || index < 0 || index >= files.length) return;

            activeIndex = index;
            const url = files[activeIndex];
            const type = detectFileType(url);

            viewerEmpty.classList.add('d-none');
            viewerImage.classList.add('d-none');
            viewerFrame.classList.add('d-none');
            viewerImage.removeAttribute('src');
            viewerFrame.removeAttribute('src');

            if (type === 'pdf') {
                viewerFrame.src = url;
                viewerFrame.classList.remove('d-none');
            } else {
                viewerImage.src = url;
                viewerImage.classList.remove('d-none');
            }

            counter.textContent = (activeIndex + 1) + ' / ' + files.length;
            if (openLink) {
                openLink.href = url;
                openLink.classList.remove('disabled');
                openLink.textContent = type === 'pdf' ? 'Open PDF' : 'Open File';
            }

            thumbsWrap.querySelectorAll('.doc-gallery-thumb-btn').forEach(function(btn, idx){
                btn.classList.toggle('is-active', idx === activeIndex);
            });
        }

        function renderThumbs() {
            thumbsWrap.innerHTML = '';
            files.forEach(function(url, idx){
                const type = detectFileType(url);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'doc-gallery-thumb-btn';
                btn.dataset.index = String(idx);

                if (type === 'pdf') {
                    btn.innerHTML = '<span class="doc-gallery-pdf-chip"><i class="bi bi-file-earmark-pdf"></i>PDF</span>';
                } else {
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = 'Attachment ' + (idx + 1);
                    btn.appendChild(img);
                }

                thumbsWrap.appendChild(btn);
            });
        }

        thumbsWrap.addEventListener('click', function(e){
            const btn = e.target.closest('.doc-gallery-thumb-btn');
            if (!btn) return;
            const idx = Number(btn.dataset.index || 0);
            setViewer(Number.isNaN(idx) ? 0 : idx);
        });

        document.addEventListener('click', function(e){
            const trigger = e.target.closest('.js-doc-gallery-trigger');
            if (!trigger) return;

            let nextFiles = [];
            try {
                nextFiles = JSON.parse(trigger.dataset.files || '[]');
            } catch (_err) {
                nextFiles = [];
            }

            files = Array.isArray(nextFiles)
                ? nextFiles.map(function(url){ return String(url || '').trim(); }).filter(Boolean).slice(0, 20)
                : [];

            if (!files.length) {
                showToast('No files available to preview.', 'warning');
                return;
            }

            renderThumbs();
            setViewer(0);
            galleryModal.show();
        });

        modalEl.addEventListener('hidden.bs.modal', function(){
            files = [];
            activeIndex = 0;
            thumbsWrap.innerHTML = '';
            counter.textContent = '0 / 0';

            viewerImage.classList.add('d-none');
            viewerFrame.classList.add('d-none');
            viewerEmpty.classList.remove('d-none');
            viewerImage.removeAttribute('src');
            viewerFrame.removeAttribute('src');

            if (openLink) {
                openLink.removeAttribute('href');
                openLink.classList.add('disabled');
                openLink.textContent = 'Open File';
            }
        });
    });
})();

(function(){
    document.addEventListener('DOMContentLoaded', function(){
        const modalEl = document.getElementById('dateDocsModal');
        const tableEl = document.getElementById('documentsTable');
        const labelEl = document.getElementById('dateDocsModalLabel');
        const triggers = document.querySelectorAll('.js-open-date-docs');
        const infoEl = document.getElementById('dtInfoRow');
        const modalCatSel = document.getElementById('modalFilterCat');
        const modalDtSel = document.getElementById('modalFilterDt');
        const modalQualSel = document.getElementById('modalFilterQual');
        const modalBatchSel = document.getElementById('modalFilterBatch');
        const modalClearBtn = document.getElementById('modalClearFilters');
        const downloadBatchPdfBtn = document.getElementById('downloadBatchPdfBtn');
        const uploadBatchFilesBtn = document.getElementById('uploadBatchFilesBtn');
        if (!modalEl || !tableEl || !triggers.length) return;

        const dateModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        let activeDate = '';
        let dt = null;

        function getModalFilters() {
            return {
                cat: modalCatSel ? String(modalCatSel.value || '') : '',
                dt: modalDtSel ? String(modalDtSel.value || '') : '',
                qual: modalQualSel ? String(modalQualSel.value || '') : '',
                batch: modalBatchSel ? String(modalBatchSel.value || '').trim().toLowerCase() : '',
            };
        }

        function rowMatchesFilters(rowNode, filters) {
            const rowCat = String(rowNode.getAttribute('data-cat-id') || '');
            const rowDt = String(rowNode.getAttribute('data-dt-id') || '');
            const rowQualIds = String(rowNode.getAttribute('data-qual-ids') || '');
            const rowBatch = String(rowNode.getAttribute('data-batch-key') || '').trim().toLowerCase();

            if (filters.cat && rowCat !== filters.cat) return false;
            if (filters.dt && rowDt !== filters.dt) return false;
            if (filters.qual && !rowQualIds.includes(',' + filters.qual + ',')) return false;
            if (filters.batch && rowBatch !== filters.batch) return false;
            return true;
        }

        function notify(message, type) {
            if (typeof showToast === 'function') {
                showToast(message, type || 'info');
            }
        }

        function isPdfUrl(url) {
            return /\.pdf(?:$|[?#])/i.test(String(url || ''));
        }

        function getRowsForActiveBatch() {
            if (dt) {
                return dt.rows({ search: 'applied' }).nodes().toArray();
            }

            const rows = Array.from(tableEl.querySelectorAll('tbody tr'));
            const filters = getModalFilters();
            return rows.filter(function(row) {
                const rowDate = row.getAttribute('data-date-sub') || '__none__';
                return (!activeDate || rowDate === activeDate) && rowMatchesFilters(row, filters);
            });
        }

        function collectAttachmentUrls(rows) {
            const all = [];
            rows.forEach(function(row) {
                const trigger = row.querySelector('.js-doc-gallery-trigger');
                if (!trigger) return;

                let files = [];
                try {
                    files = JSON.parse(trigger.dataset.files || '[]');
                } catch (_err) {
                    files = [];
                }

                if (!Array.isArray(files)) return;
                files.forEach(function(url) {
                    const clean = String(url || '').trim();
                    if (clean) all.push(clean);
                });
            });

            return Array.from(new Set(all));
        }

        function loadImageForPdf(url) {
            return new Promise(function(resolve, reject) {
                const img = new Image();
                img.onload = function() {
                    const srcW = img.naturalWidth || img.width || 0;
                    const srcH = img.naturalHeight || img.height || 0;
                    if (!srcW || !srcH) {
                        reject(new Error('Invalid image dimensions'));
                        return;
                    }

                    const maxDim = 2200;
                    const scale = Math.min(1, maxDim / Math.max(srcW, srcH));
                    const w = Math.max(1, Math.round(srcW * scale));
                    const h = Math.max(1, Math.round(srcH * scale));

                    const canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;

                    const ctx = canvas.getContext('2d');
                    if (!ctx) {
                        reject(new Error('Canvas unavailable'));
                        return;
                    }

                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, w, h);
                    ctx.drawImage(img, 0, 0, w, h);

                    resolve({
                        dataUrl: canvas.toDataURL('image/jpeg', 0.9),
                        width: w,
                        height: h,
                    });
                };
                img.onerror = function() {
                    reject(new Error('Image failed to load'));
                };
                img.src = url;
            });
        }

        async function downloadBatchImagesPdf() {
            if (!activeDate) {
                notify('Open a batch/date first.', 'warning');
                return;
            }

            if (!window.jspdf || typeof window.jspdf.jsPDF !== 'function') {
                notify('PDF generator failed to load. Refresh and try again.', 'danger');
                return;
            }

            const rows = getRowsForActiveBatch();
            const files = collectAttachmentUrls(rows);
            const imageFiles = files.filter(function(url) { return !isPdfUrl(url); });
            if (!imageFiles.length) {
                notify('No image files found for this batch. PDF files are skipped.', 'warning');
                return;
            }

            const jsPDF = window.jspdf.jsPDF;
            const pdf = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4', compress: true });
            const pageW = pdf.internal.pageSize.getWidth();
            const pageH = pdf.internal.pageSize.getHeight();

            let added = 0;
            let skipped = files.length - imageFiles.length;

            for (const url of imageFiles) {
                try {
                    const img = await loadImageForPdf(url);
                    if (added > 0) pdf.addPage();

                    const scale = Math.min(pageW / img.width, pageH / img.height);
                    const drawW = img.width * scale;
                    const drawH = img.height * scale;
                    const x = (pageW - drawW) / 2;
                    const y = (pageH - drawH) / 2;

                    pdf.addImage(img.dataUrl, 'JPEG', x, y, drawW, drawH, undefined, 'MEDIUM');
                    added++;
                } catch (_err) {
                    skipped++;
                }
            }

            if (!added) {
                notify('No readable images were found to export.', 'warning');
                return;
            }

            const rawLabel = (labelEl && labelEl.textContent ? labelEl.textContent : activeDate) || 'batch';
            const safeLabel = String(rawLabel)
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '') || 'batch';

            pdf.save('batch_' + safeLabel + '.pdf');

            if (skipped > 0) {
                notify('Downloaded ' + added + ' image page(s). Skipped ' + skipped + ' non-image or unreadable file(s).', 'warning');
            } else {
                notify('Downloaded ' + added + ' image page(s).', 'success');
            }
        }

        if (typeof $ !== 'undefined' && typeof $.fn.DataTable !== 'undefined' && $.fn.DataTable.isDataTable(tableEl)) {
            dt = $(tableEl).DataTable();

            if ($.fn.dataTable && $.fn.dataTable.ext && Array.isArray($.fn.dataTable.ext.search)) {
                $.fn.dataTable.ext.search.push(function(settings, _data, dataIndex) {
                    if (settings.nTable !== tableEl) return true;
                    const rowNode = dt.row(dataIndex).node();
                    if (!rowNode) return true;

                    if (!activeDate) return true;

                    const rowDate = rowNode ? (rowNode.getAttribute('data-date-sub') || '__none__') : '__none__';
                    if (rowDate !== activeDate) return false;

                    return rowMatchesFilters(rowNode, getModalFilters());
                });
            }
        }

        function updateFallbackInfo(visibleCount) {
            if (!infoEl) return;
            infoEl.textContent = visibleCount > 0
                ? `Showing ${visibleCount} record(s)`
                : 'No records for this date';
        }

        function applyFallbackFilter() {
            const rows = Array.from(tableEl.querySelectorAll('tbody tr'));
            const filters = getModalFilters();
            let shown = 0;
            rows.forEach(function(row) {
                const rowDate = row.getAttribute('data-date-sub') || '__none__';
                const show = (!activeDate || rowDate === activeDate) && rowMatchesFilters(row, filters);
                row.style.display = show ? '' : 'none';
                if (show) shown++;
            });
            updateFallbackInfo(shown);
        }

        [modalCatSel, modalDtSel, modalQualSel, modalBatchSel].forEach(function(el) {
            if (!el) return;
            el.addEventListener('change', function() {
                if (dt) {
                    dt.draw();
                } else {
                    applyFallbackFilter();
                }
            });
        });

        if (modalClearBtn) {
            modalClearBtn.addEventListener('click', function() {
                if (modalCatSel) modalCatSel.value = '';
                if (modalDtSel) modalDtSel.value = '';
                if (modalQualSel) modalQualSel.value = '';
                if (modalBatchSel) modalBatchSel.value = '';

                if (dt) {
                    dt.draw();
                } else {
                    applyFallbackFilter();
                }
            });
        }

        if (downloadBatchPdfBtn) {
            downloadBatchPdfBtn.addEventListener('click', async function() {
                const defaultHtml = downloadBatchPdfBtn.innerHTML;
                downloadBatchPdfBtn.disabled = true;
                downloadBatchPdfBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Building PDF...';

                try {
                    await downloadBatchImagesPdf();
                } finally {
                    downloadBatchPdfBtn.disabled = false;
                    downloadBatchPdfBtn.innerHTML = defaultHtml;
                }
            });
        }
        const batchForm = document.getElementById('batchUploadForm');
        const confirmPasswordModalEl = document.getElementById('confirmPasswordModal');
        const confirmPasswordForm = document.getElementById('confirmPasswordForm');
        const confirmPasswordInput = document.getElementById('confirmPasswordInput');
        const batchPasswordField = document.getElementById('batchUpload_account_password');

        if (batchForm && confirmPasswordModalEl && confirmPasswordForm && confirmPasswordInput) {
            batchForm.addEventListener('submit', function(e){
                e.preventDefault();
                const replace = (batchForm.querySelector('input[name="replace_existing"]') || {}).value === '1';

                const askPassword = function() {
                    confirmPasswordInput.value = '';
                    bootstrap.Modal.getOrCreateInstance(confirmPasswordModalEl).show();
                };

                if (replace) {
                    if (typeof showConfirmModal === 'function') {
                        showConfirmModal('Replace existing files for this batch?', {
                            title: 'Upload Batch Files',
                            confirmText: 'Replace',
                            confirmClass: 'btn btn-danger'
                        }).then(function(ok){ if (ok) askPassword(); });
                    } else {
                        if (confirm('Replace existing files for this batch?')) askPassword();
                    }
                } else {
                    askPassword();
                }
            });

            confirmPasswordForm.addEventListener('submit', async function(ev){
                ev.preventDefault();
                const pwd = String(confirmPasswordInput.value || '').trim();
                if (pwd === '') {
                    if (typeof showToast === 'function') showToast('Enter your account password to continue.', 'warning');
                    confirmPasswordInput.focus();
                    return;
                }

                const submitBtn = confirmPasswordForm.querySelector('button[type="submit"]');
                const defaultHtml = submitBtn ? submitBtn.innerHTML : null;
                if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Checking...'; }

                try {
                    const body = new URLSearchParams();
                    body.set('action','verify_edit_password');
                    body.set('account_password', pwd);

                    const response = await fetch('documents_tracking.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString(),
                        credentials: 'same-origin'
                    });

                    const result = await response.json();
                    if (!response.ok || !result || result.ok !== true) {
                        if (typeof showToast === 'function') showToast((result && result.message) ? result.message : 'Unable to verify password right now.', 'danger');
                        confirmPasswordInput.focus();
                        return;
                    }

                    // verified — set hidden field and submit the batch form
                    if (batchPasswordField) batchPasswordField.value = pwd;
                    bootstrap.Modal.getOrCreateInstance(confirmPasswordModalEl).hide();
                    batchForm.submit();
                } catch (_err) {
                    if (typeof showToast === 'function') showToast('Unable to verify password right now. Please try again.', 'danger');
                } finally {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = defaultHtml; }
                }
            });
        }

        if (uploadBatchFilesBtn) {
            uploadBatchFilesBtn.addEventListener('click', function() {
                if (!activeDate) {
                    notify('Open a batch/date first.', 'warning');
                    return;
                }

                const batchModal = document.getElementById('batchUploadModal');
                if (!batchModal) return;

                const dateField = document.getElementById('batchUpload_date');
                if (dateField) dateField.value = activeDate;

                const filesInput = batchModal.querySelector('input[type=file][name="shared_files[]"]');
                if (filesInput) filesInput.value = '';

                // show note if there are existing files in this batch
                const rows = getRowsForActiveBatch();
                const existing = rows.some(function(row){ return !!row.querySelector('.js-doc-gallery-trigger'); });
                const note = document.getElementById('batchUpload_note');
                if (note) {
                    if (existing) {
                        note.style.display = '';
                        note.textContent = 'Some rows already have files; uploading will replace them.';
                    } else {
                        note.style.display = 'none';
                        note.textContent = '';
                    }
                }

                bootstrap.Modal.getOrCreateInstance(batchModal).show();
            });
        }

        triggers.forEach(function(btn){
            btn.addEventListener('click', function(){
                activeDate = String(btn.dataset.date || '__none__');
                if (labelEl) {
                    labelEl.textContent = String(btn.dataset.label || 'Selected Date');
                }

                if (dt) {
                    dt.search('').draw();
                } else {
                    applyFallbackFilter();
                }

                dateModal.show();
            });
        });

        modalEl.addEventListener('shown.bs.modal', function(){
            if (dt && typeof dt.columns === 'function') {
                dt.columns.adjust().draw(false);
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function(){
            activeDate = '';
            if (dt) {
                dt.draw();
            } else {
                applyFallbackFilter();
            }
        });
    });
})();
</script>
</body>
</html>