<?php
// =========================================================
// Add Documents (Full Page)
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';

$sid = (int)$_SESSION['active_system_id'];
$flash = '';
$flashType = 'success';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_multiple_documents') {
        $date_sub_shared_raw = trim((string)($_POST['date_submission_shared'] ?? ''));
        $date_sub_shared = $date_sub_shared_raw !== '' ? $date_sub_shared_raw : null;
        $category_ids      = $_POST['row_category_id']      ?? [];
        $doctype_ids       = $_POST['row_document_type_id'] ?? [];
        $qualification_map = $_POST['row_qualification_ids'] ?? [];
        $row_keys          = $_POST['row_key']              ?? [];
        $batch_nos         = $_POST['batch_no']             ?? [];
        $remarks_arr       = $_POST['remarks']              ?? [];
        $received_tesdas   = $_POST['received_tesda']       ?? [];
        $returned_centers  = $_POST['returned_center']      ?? [];
        $staff_receiveds   = $_POST['staff_received']       ?? [];
        $date_assessments  = $_POST['date_assessment']      ?? [];
        $assessor_names    = $_POST['assessor_name']        ?? [];
        $tesda_releaseds   = $_POST['tesda_released']       ?? [];

        $ins = $pdo->prepare('INSERT INTO documents
            (system_id,category_id,document_type_id,qualification_id,
             date_submission,batch_no,remarks,received_tesda,returned_center,
             staff_received,date_assessment,assessor_name,tesda_released,image_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insDocQual = $hasDocumentQualificationTable
            ? $pdo->prepare('INSERT IGNORE INTO document_qualifications (document_id, qualification_id) VALUES (?, ?)')
            : null;

        $count = 0;
        $rowTotal = max(
            count($category_ids),
            count($doctype_ids),
            count($row_keys),
            count($batch_nos),
            count($remarks_arr)
        );

        try {
            if ($date_sub_shared === null) {
                throw new RuntimeException('Date Submitted is required for this batch.');
            }

            $sharedPaths = handleSharedUploads();
            $sharedImagePath = encodeStoredImagePaths($sharedPaths);

            for ($i = 0; $i < $rowTotal; $i++) {
                $cat_id = intval($category_ids[$i] ?? 0) ?: null;
                $dt_id = intval($doctype_ids[$i] ?? 0) ?: null;
                $rowKey = (string)($row_keys[$i] ?? '');
                $rowQuals = [];
                if (is_array($qualification_map)) {
                    if ($rowKey !== '' && isset($qualification_map[$rowKey])) {
                        $rowQuals = $qualification_map[$rowKey];
                    } elseif (isset($qualification_map[$i])) {
                        $rowQuals = $qualification_map[$i];
                    }
                }
                if (!is_array($rowQuals)) {
                    $rowQuals = [$rowQuals];
                }

                $qualIds = [];
                foreach ($rowQuals as $qv) {
                    $qid = intval($qv);
                    if ($qid > 0) $qualIds[] = $qid;
                }
                if ($qualIds) {
                    $qualIds = array_values(array_unique($qualIds));
                }

                $batch = trim($batch_nos[$i] ?? '') ?: null;
                $rem = trim($remarks_arr[$i] ?? '') ?: null;

                $hasOtherData = (
                    $cat_id !== null ||
                    $dt_id !== null ||
                    !empty($qualIds) ||
                    $batch !== null ||
                    $rem !== null ||
                    ($received_tesdas[$i] ?? '') !== '' ||
                    ($returned_centers[$i] ?? '') !== '' ||
                    ($staff_receiveds[$i] ?? '') !== '' ||
                    ($date_assessments[$i] ?? '') !== '' ||
                    trim($assessor_names[$i] ?? '') !== '' ||
                    ($tesda_releaseds[$i] ?? '') !== '' ||
                    $sharedImagePath !== null
                );

                if (!$hasOtherData) {
                    continue;
                }

                if ($cat_id === null || $dt_id === null) {
                    continue;
                }

                if ($hasDocumentQualificationTable) {
                    $primaryQualId = $qualIds ? $qualIds[0] : null;
                    $ins->execute([
                        $sid,
                        $cat_id,
                        $dt_id,
                        $primaryQualId,
                        $date_sub_shared,
                        $batch,
                        $rem,
                        ($received_tesdas[$i] ?? '') ?: null,
                        ($returned_centers[$i] ?? '') ?: null,
                        ($staff_receiveds[$i] ?? '') ?: null,
                        ($date_assessments[$i] ?? '') ?: null,
                        trim($assessor_names[$i] ?? '') ?: null,
                        ($tesda_releaseds[$i] ?? '') ?: null,
                        $sharedImagePath
                    ]);

                    $docId = (int)$pdo->lastInsertId();
                    if ($docId > 0 && $insDocQual && $qualIds) {
                        foreach ($qualIds as $qid) {
                            $insDocQual->execute([$docId, $qid]);
                        }
                    }

                    $count++;
                    continue;
                }

                $qualTargets = $qualIds ? $qualIds : [null];
                foreach ($qualTargets as $qual_id) {
                    $ins->execute([
                        $sid,
                        $cat_id,
                        $dt_id,
                        $qual_id,
                        $date_sub_shared,
                        $batch,
                        $rem,
                        ($received_tesdas[$i] ?? '') ?: null,
                        ($returned_centers[$i] ?? '') ?: null,
                        ($staff_receiveds[$i] ?? '') ?: null,
                        ($date_assessments[$i] ?? '') ?: null,
                        trim($assessor_names[$i] ?? '') ?: null,
                        ($tesda_releaseds[$i] ?? '') ?: null,
                        $sharedImagePath
                    ]);
                    $count++;
                }
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Documents — <?= htmlspecialchars($activeSystem['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap">
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
            --accent: #9b1c33;
            --accent-dark: #350912;
            --accent-mid: #6c1525;
            --accent-light: #fff8e5;
            --accent-glow: rgba(246, 199, 82, 0.34);
            --accent-gradient: linear-gradient(135deg, #350912 0%, #6c1525 54%, #9b1c33 100%);
            --required-warn: #9b1c33;
            --required-warn-soft: #fff6eb;
            --required-warn-border: rgba(155, 28, 51, 0.30);
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
            border-color: rgba(147, 98, 18, .34) !important;
            background-color: #fffdf4 !important;
        }
        .theme-blossom .select-qual {
            border-color: #e4cfa0 !important;
            background-color: #fff !important;
        }
        .theme-blossom .select-cat:focus,
        .theme-blossom .select-dt:focus {
            border-color: #9b1c33 !important;
            box-shadow: 0 0 0 .15rem rgba(155, 28, 51, .22) !important;
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
        .qual-picker {
            position: relative;
            min-width: 180px;
        }
        .qual-picker-btn {
            text-align: left;
            padding-right: 26px !important;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            position: relative;
        }
        .qual-picker-btn::after {
            content: '';
            position: absolute;
            right: 10px;
            top: 50%;
            margin-top: -2px;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 6px solid #6b7788;
            pointer-events: none;
        }
        .qual-picker-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            max-height: 180px;
            overflow-y: auto;
            border: 1px solid #d6dee8;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 16px rgba(20, 40, 90, .14);
            z-index: 25;
            padding: 6px;
        }
        .qual-picker-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .74rem;
            color: #2b3443;
            padding: 3px 2px;
            margin: 0;
            cursor: pointer;
        }
        .qual-picker-item input {
            margin: 0;
        }
        .shared-upload-wrap {
            border: 1px dashed var(--accent-glow, #d0dbf0);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.74);
            padding: 10px;
        }
        .shared-files-section {
            padding: 0 20px 12px;
        }
        .shared-files-title {
            font-size: .74rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #5a6f8b;
            margin-bottom: 6px;
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

        /* Modern visual refresh */
        body {
            background:
                radial-gradient(1200px 420px at 12% -6%, rgba(76, 122, 186, 0.14), rgba(76, 122, 186, 0) 65%),
                radial-gradient(900px 320px at 92% 0%, rgba(41, 86, 148, 0.12), rgba(41, 86, 148, 0) 70%),
                #edf2f8;
        }
        .add-page-wrap {
            max-width: 1720px;
            position: relative;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
        }
        .add-page-wrap .page-title {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: -.02em;
            color: #152944;
        }
        .add-page-wrap .page-subtitle {
            font-size: .85rem;
            color: #667e9b;
            font-weight: 600;
        }

        .add-card {
            border-radius: 22px;
            border: 1px solid rgba(152, 172, 199, 0.35);
            box-shadow: 0 18px 44px rgba(24, 49, 88, 0.12);
            animation: addCardLift .32s ease;
        }
        .add-card-header {
            position: relative;
            padding: 22px 24px;
            background:
                linear-gradient(104deg, rgba(10, 39, 78, 0.98) 0%, rgba(36, 86, 151, 0.94) 52%, rgba(108, 152, 212, 0.92) 100%);
        }
        .add-card-header::after {
            content: '';
            position: absolute;
            inset: auto 0 0;
            height: 1px;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, .38), rgba(255, 255, 255, 0));
        }
        .add-card-title {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -.02em;
        }
        .add-card-subtitle {
            margin-top: 6px;
            color: rgba(242, 247, 255, .9);
            font-weight: 600;
        }

        .prefill-banner {
            padding: 16px 20px 14px;
            background: linear-gradient(180deg, rgba(233, 242, 255, .95) 0%, rgba(242, 247, 255, .9) 100%);
            border-bottom: 1px solid rgba(123, 150, 184, .24);
        }
        .prefill-banner .form-label {
            font-size: .78rem;
            font-weight: 700;
            color: #38557a;
            letter-spacing: .01em;
        }
        .prefill-banner .form-select,
        .prefill-banner .form-control {
            height: 42px;
            border-radius: 12px;
            border-color: rgba(117, 150, 192, .36);
            font-weight: 600;
        }
        .prefill-banner .form-select:focus,
        .prefill-banner .form-control:focus {
            border-color: #5d89c9;
            box-shadow: 0 0 0 .18rem rgba(93, 137, 201, .18);
        }
        #btnApplyPrefill {
            height: 42px;
            border-radius: 12px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .prefill-hint {
            margin-top: 8px;
            font-size: .77rem;
            color: #57739a;
            font-weight: 600;
        }

        .rows-section {
            padding: 14px 20px 10px;
        }
        .rows-section-label {
            font-size: .8rem;
            color: #425c80;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        #rowCountBadge {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .72rem;
            font-weight: 700;
            background: linear-gradient(120deg, #5c7fae, #7494be) !important;
        }
        #btnAdd1,
        #btnAdd5,
        #btnAdd10 {
            border-radius: 11px;
            font-weight: 700;
            padding-left: 12px;
            padding-right: 12px;
        }

        .rows-scroll-wrap {
            border-radius: 14px;
            border: 1px solid rgba(126, 152, 184, .3);
            background: linear-gradient(180deg, rgba(251, 253, 255, .95), #fff);
        }
        #addRowsTable {
            font-size: .82rem;
        }
        #addRowsTable thead th {
            background: linear-gradient(180deg, #f8fbff 0%, #edf3fb 100%) !important;
            color: #355377 !important;
            font-size: .69rem;
            letter-spacing: .08em;
            border-bottom: 1px solid rgba(133, 160, 196, .35);
            padding: 9px 8px;
        }
        #addRowsTable tbody tr:nth-child(even) td {
            background: #fbfdff;
        }
        #addRowsTable tbody tr:hover td {
            background: #f3f8ff;
        }
        #addRowsTable tbody td {
            border-color: #e5edf7;
        }
        #addRowsTable .form-control,
        #addRowsTable .form-select {
            height: 36px;
            padding: 6px 8px;
            border-radius: 10px;
            border: 1px solid #cad8ea;
            background: #fff;
            font-size: .79rem;
            font-weight: 600;
        }
        #addRowsTable .form-control:focus,
        #addRowsTable .form-select:focus {
            border-color: #6c98d4 !important;
            box-shadow: 0 0 0 .14rem rgba(86, 140, 208, .18);
        }

        .qual-picker-btn {
            height: 36px;
            border-radius: 10px;
            border: 1px solid #cad8ea;
            background: linear-gradient(180deg, #fff, #f5f8fc);
            font-weight: 700;
            color: #3d5a7f;
        }
        .qual-picker-btn.is-filled {
            border-color: rgba(92, 137, 201, .6);
            background: linear-gradient(180deg, #e9f2ff, #f3f8ff);
            color: #21446f;
        }
        .qual-picker-menu {
            border-radius: 12px;
            border-color: #cbd9ea;
            box-shadow: 0 14px 28px rgba(24, 49, 88, .18);
            padding: 8px;
        }
        .qual-picker-item {
            border-radius: 8px;
            padding: 5px 6px;
        }
        .qual-picker-item:hover {
            background: #eef5ff;
        }

        .shared-files-section {
            padding: 0 20px 14px;
        }
        .shared-files-title {
            font-size: .76rem;
            font-weight: 800;
            color: #4f6788;
            letter-spacing: .08em;
        }
        .shared-upload-wrap {
            border-width: 2px;
            border-radius: 14px;
            border-color: rgba(117, 150, 192, .34);
            background: linear-gradient(180deg, rgba(248, 252, 255, .95), #fff);
            padding: 12px;
        }
        #shared_files_input {
            height: 42px;
            border-radius: 12px;
            border-color: #c8d7ea;
            font-weight: 600;
        }

        .add-page-footer {
            border-top-color: rgba(120, 146, 178, .22);
            background: linear-gradient(180deg, rgba(246, 250, 255, .92), rgba(235, 243, 252, .96));
            padding: 14px 20px;
        }
        #btnClearRows {
            font-weight: 700;
        }
        #saveCountLabel {
            font-size: .79rem !important;
            color: #4a6485 !important;
            font-weight: 700;
        }

        body.theme-blossom {
            background:
                radial-gradient(1200px 420px at 12% -6%, rgba(155, 28, 51, 0.18), rgba(155, 28, 51, 0) 65%),
                radial-gradient(900px 320px at 92% 0%, rgba(246, 199, 82, 0.20), rgba(246, 199, 82, 0) 70%),
                #fffdf4;
        }

        .theme-blossom .add-page-wrap .page-title {
            color: #4e0f1c;
        }

        .theme-blossom .add-page-wrap .page-subtitle {
            color: #80530a;
        }

        .theme-blossom .add-card {
            border-color: rgba(155, 28, 51, 0.28);
            box-shadow: 0 18px 44px rgba(96, 20, 34, 0.16);
        }

        .theme-blossom .add-card-header {
            background: linear-gradient(108deg, #350912 0%, #6c1525 56%, #9b1c33 100%);
        }

        .theme-blossom .prefill-banner {
            background: linear-gradient(180deg, #fffef7 0%, #fff5d9 100%);
            border-bottom-color: rgba(147, 98, 18, 0.26);
        }

        .theme-blossom .prefill-banner .form-label {
            color: #6c1525;
        }

        .theme-blossom .prefill-banner .form-select,
        .theme-blossom .prefill-banner .form-control {
            border-color: rgba(147, 98, 18, 0.34);
            background: #fff;
        }

        .theme-blossom .prefill-banner .form-select:focus,
        .theme-blossom .prefill-banner .form-control:focus {
            border-color: #9b1c33;
            box-shadow: 0 0 0 .18rem rgba(155, 28, 51, .18);
        }

        .theme-blossom .prefill-hint,
        .theme-blossom .rows-section-label,
        .theme-blossom .shared-files-title {
            color: #6c1525;
        }

        .theme-blossom #rowCountBadge {
            background: linear-gradient(120deg, #6c1525, #9b1c33) !important;
            color: #ffefc4;
        }

        .theme-blossom .rows-scroll-wrap {
            border-color: rgba(155, 28, 51, .3);
            background: linear-gradient(180deg, rgba(255, 254, 250, .98), #fff);
        }

        .theme-blossom #addRowsTable thead th {
            background: linear-gradient(180deg, #fff7db 0%, #ffefbf 100%) !important;
            color: #6c1525 !important;
            border-bottom-color: rgba(155, 28, 51, .24);
        }

        .theme-blossom #addRowsTable tbody tr:nth-child(even) td {
            background: #fffdf4;
        }

        .theme-blossom #addRowsTable tbody tr:hover td {
            background: #fff7de;
        }

        .theme-blossom #addRowsTable tbody td {
            border-color: #f3e3bf;
        }

        .theme-blossom #addRowsTable .form-control,
        .theme-blossom #addRowsTable .form-select {
            border-color: #e4cfa0;
        }

        .theme-blossom #addRowsTable .form-control:focus,
        .theme-blossom #addRowsTable .form-select:focus {
            border-color: #9b1c33 !important;
            box-shadow: 0 0 0 .14rem rgba(155, 28, 51, .18);
        }

        .theme-blossom .qual-picker-btn {
            border-color: #e4cfa0;
            background: linear-gradient(180deg, #fff, #fff5d7);
            color: #6c1525;
        }

        .theme-blossom .qual-picker-btn.is-filled {
            border-color: rgba(155, 28, 51, .55);
            background: linear-gradient(180deg, #fff5d7, #fff);
            color: #5c0f1e;
        }

        .theme-blossom .qual-picker-item:hover {
            background: #fff5d7;
        }

        .theme-blossom .shared-upload-wrap {
            border-color: rgba(147, 98, 18, .36);
            background: linear-gradient(180deg, rgba(255, 253, 244, .98), #fff);
        }

        .theme-blossom #shared_files_input {
            border-color: #e4cfa0;
        }

        .theme-blossom .add-page-footer {
            border-top-color: rgba(147, 98, 18, .24);
            background: linear-gradient(180deg, rgba(255, 252, 241, .94), rgba(255, 245, 217, .97));
        }

        .theme-blossom #saveCountLabel {
            color: #6c1525 !important;
        }

        @keyframes addCardLift {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 991.98px) {
            .add-card-title {
                font-size: 1.5rem;
            }
            .add-page-wrap .page-title {
                font-size: 1.5rem;
            }
            .prefill-banner {
                padding: 12px 14px;
            }
            .rows-section,
            .shared-files-section,
            .add-page-footer {
                padding-left: 14px;
                padding-right: 14px;
            }
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
                    <p class="add-card-subtitle">One batch date and one shared attachment set apply to all rows. Each row can select multiple qualifications.</p>
                </div>

                <div class="prefill-banner">
                    <div class="prefill-banner-title"><i class="bi bi-lightning-fill me-1"></i>Batch setup + quick prefill</div>
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
                            <label class="form-label form-label-sm mb-1">Date Submitted <span class="required-star">*</span></label>
                            <input type="date" name="date_submission_shared" id="prefill_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-tb5-primary w-100" id="btnApplyPrefill">
                                <i class="bi bi-arrow-down-circle me-1"></i>Apply to All Rows
                            </button>
                        </div>
                    </div>
                    <div class="prefill-hint"><i class="bi bi-info-circle me-1"></i>Set Date Submitted once for the whole batch. Add qualifications per row as combined values (example: BSS/CSS).</div>
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
                                <th style="min-width:180px">Qualifications</th>
                                <th style="min-width:130px">Batch No.</th>
                                <th style="min-width:132px">Received (TESDA)</th>
                                <th style="min-width:132px">Returned (Center)</th>
                                <th style="min-width:128px">Staff Received</th>
                                <th style="min-width:132px">Date Assessment</th>
                                <th style="min-width:128px">Assessor Name</th>
                                <th style="min-width:132px">TESDA Released</th>
                                <th style="min-width:140px">Remarks</th>
                                <th style="width:34px"></th>
                            </tr>
                            </thead>
                            <tbody id="addRowsTbody">
                            <tr id="addRowsEmpty"><td colspan="13">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="shared-files-section">
                    <div class="shared-files-title"><i class="bi bi-paperclip me-1"></i>Shared Files For This Batch</div>
                    <div class="shared-upload-wrap">
                        <input type="file" id="shared_files_input" name="shared_files[]" class="form-control form-control-sm" accept="image/*,.pdf" multiple>
                        <div id="shared_files_meta" class="file-select-note">No shared files selected (optional, max 20 files for this batch).</div>
                        <div id="shared_files_preview" class="file-preview-list"></div>
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
const CATS_PHP  = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']], $categories), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

(function(){
    let rowSeq = 0;
    let currentPreviewUrl = '';
    let sharedPreviewUrls = [];

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

    function closeAllQualMenus() {
        document.querySelectorAll('.js-qual-menu').forEach(menu => menu.classList.add('d-none'));
    }

    function setQualPickerValues(picker, values) {
        if (!picker) return;
        const wanted = Array.isArray(values) ? values.map(v => String(v)) : [];
        const btn = picker.querySelector('.js-qual-btn');
        const checks = picker.querySelectorAll('.js-qual-option');

        checks.forEach(ch => {
            ch.checked = wanted.includes(String(ch.value));
        });

        const selectedVals = [];
        const selectedNames = [];
        checks.forEach(ch => {
            if (ch.checked) {
                selectedVals.push(String(ch.value));
                selectedNames.push(String(ch.dataset.name || ch.value));
            }
        });

        if (btn) {
            btn.textContent = selectedNames.length ? selectedNames.join('/') : '— None —';
            btn.classList.toggle('is-filled', selectedNames.length > 0);
        }
    }

    function createQualPicker(rowKey, selectedVals) {
        const picker = document.createElement('div');
        picker.className = 'qual-picker js-qual-picker';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'form-control form-control-sm qual-picker-btn js-qual-btn';
        btn.textContent = '— None —';

        const menu = document.createElement('div');
        menu.className = 'qual-picker-menu js-qual-menu d-none';

        QUALS.forEach(q => {
            const label = document.createElement('label');
            label.className = 'qual-picker-item';

            const check = document.createElement('input');
            check.type = 'checkbox';
            check.value = String(q.id);
            check.name = `row_qualification_ids[${rowKey}][]`;
            check.dataset.name = q.name;
            check.className = 'js-qual-option';

            const span = document.createElement('span');
            span.textContent = q.name;

            check.addEventListener('change', function(){
                setQualPickerValues(picker, Array.from(menu.querySelectorAll('.js-qual-option:checked')).map(ch => ch.value));
            });

            label.appendChild(check);
            label.appendChild(span);
            menu.appendChild(label);
        });

        btn.addEventListener('click', function(e){
            e.stopPropagation();
            const shouldOpen = menu.classList.contains('d-none');
            closeAllQualMenus();
            if (shouldOpen) menu.classList.remove('d-none');
        });

        menu.addEventListener('click', function(e){
            e.stopPropagation();
        });

        picker.appendChild(btn);
        picker.appendChild(menu);

        setQualPickerValues(picker, selectedVals);
        return picker;
    }

    function makeRow(def) {
        def = def || {};
        rowSeq++;
        const rowKey = String(rowSeq);

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

        const rowKeyInp = document.createElement('input');
        rowKeyInp.type = 'hidden';
        rowKeyInp.name = 'row_key[]';
        rowKeyInp.value = rowKey;

        const qualPicker = createQualPicker(rowKey, def.qualIds || []);

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

        const receivedInp = inp('received_tesda', 'date');
        const returnedInp = inp('returned_center', 'date');

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-outline-danger btn-del-row';
        del.title = 'Remove';
        del.innerHTML = '<i class="bi bi-x"></i>';

        catSel.addEventListener('change', function(){
            fillDtSelect(dtSel, this.value, '');
        });

        const numTd = document.createElement('td');
        numTd.className = 'row-num-cell';

        const wrap = (el) => {
            const td = document.createElement('td');
            td.appendChild(el);
            return td;
        };

        const catWrap = document.createElement('div');
        catWrap.appendChild(rowKeyInp);
        catWrap.appendChild(catSel);

        [
            numTd,
            wrap(catWrap),
            wrap(dtSel),
            wrap(qualPicker),
            wrap(inp('batch_no', 'text', 'e.g. 51401-001')),
            wrap(receivedInp),
            wrap(returnedInp),
            wrap(inp('staff_received', 'text', 'Staff name')),
            wrap(inp('date_assessment', 'date')),
            wrap(inp('assessor_name', 'text')),
            wrap(inp('tesda_released', 'date')),
            wrap(remarksSel),
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

    function clearSharedPreviewUrls() {
        sharedPreviewUrls.forEach(url => URL.revokeObjectURL(url));
        sharedPreviewUrls = [];
    }

    function renderSharedFilesPreview(inputEl) {
        const metaEl = document.getElementById('shared_files_meta');
        const previewWrap = document.getElementById('shared_files_preview');
        if (!metaEl || !previewWrap) return;

        clearSharedPreviewUrls();
        previewWrap.innerHTML = '';

        const files = Array.from((inputEl && inputEl.files) ? inputEl.files : []);
        if (!files.length) {
            metaEl.textContent = 'No shared files selected (optional, max 20 files for this batch).';
            return;
        }

        if (files.length > 20) {
            showToast('Only up to 20 shared files are allowed for this batch.', 'warning');
            inputEl.value = '';
            metaEl.textContent = 'No shared files selected (optional, max 20 files for this batch).';
            return;
        }

        files.forEach((file, idx) => {
            const url = URL.createObjectURL(file);
            sharedPreviewUrls.push(url);
            const typeHint = file.type || (/\.pdf$/i.test(file.name || '') ? 'pdf' : 'image');

            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'preview-trigger js-shared-file-item file-preview-item';
            item.dataset.previewUrl = url;
            item.dataset.previewType = typeHint;
            item.title = file.name || ('Shared file ' + (idx + 1));

            if (typeHint === 'pdf' || typeHint.toLowerCase() === 'application/pdf') {
                item.innerHTML = '<span class="pdf-chip"><i class="bi bi-file-earmark-pdf"></i>PDF</span>';
            } else {
                const thumb = document.createElement('img');
                thumb.className = 'img-mini';
                thumb.alt = 'shared attachment';
                thumb.src = url;
                item.appendChild(thumb);
            }

            previewWrap.appendChild(item);
        });

        metaEl.textContent = files.length + ' shared file(s) selected.';
    }

    function showEmpty() {
        document.getElementById('addRowsTbody').innerHTML = '<tr id="addRowsEmpty"><td colspan="13">No rows yet — click <strong>Add Row</strong> or <strong>+ 5 Rows</strong> to begin.</td></tr>';
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
            dtId: document.getElementById('prefill_doctype').value
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

            if (catSel && dtSel) {
                catSel.value = p.catId;
                fillDtSelect(dtSel, p.catId, p.dtId);
            }
        });
    }

    function resetFormRows() {
        rowSeq = 0;
        showEmpty();
        document.getElementById('prefill_category').value = '';
        document.getElementById('prefill_doctype').innerHTML = '<option value="">— Pick category first —</option>';
        document.getElementById('prefill_date').value = '';
        const sharedInput = document.getElementById('shared_files_input');
        if (sharedInput) {
            sharedInput.value = '';
            renderSharedFilesPreview(sharedInput);
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        const previewModalEl = document.getElementById('docPreviewModal');
        const previewImg = document.getElementById('docPreviewImage');
        const previewFrame = document.getElementById('docPreviewFrame');
        const previewPlaceholder = document.getElementById('docPreviewPlaceholder');

        document.addEventListener('click', function(){
            closeAllQualMenus();
        });

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
            if (trigger) {
                openPreview(trigger.dataset.previewUrl || '', trigger.dataset.previewType || '');
                return;
            }

            const sharedFile = e.target.closest('.js-shared-file-item');
            if (sharedFile) {
                openPreview(sharedFile.dataset.previewUrl || '', sharedFile.dataset.previewType || '');
            }
        });

        const sharedInput = document.getElementById('shared_files_input');
        if (sharedInput) {
            sharedInput.addEventListener('change', function(){
                renderSharedFilesPreview(this);
            });
        }

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
            const btn = e.target.closest('.btn-del-row');
            if (!btn) return;
            const row = btn.closest('tr');
            if (!row) return;
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

            if (!document.getElementById('prefill_date').value) {
                e.preventDefault();
                showToast('Set Date Submitted for this batch.', 'warning');
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
            clearSharedPreviewUrls();
        });

        addRows(5);
    });
})();
</script>
</body>
</html>
