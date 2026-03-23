<?php
// =========================================================
// AJAX: Save single inline-edited cell
// =========================================================
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json');

$sid   = (int)$_SESSION['active_system_id'];
$docId = (int)($_POST['doc_id'] ?? 0);
$field = $_POST['field']  ?? '';
$value = $_POST['value']  ?? null;

// Whitelist — only these fields may be updated inline
$allowed = [
    'received_tesda',
    'staff_received',
    'date_assessment',
    'assessor_name',
    'tesda_released',
    'remarks',
];

if (!$docId || !in_array($field, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

// Ownership check
$chk = $pdo->prepare('SELECT id FROM documents WHERE id = ? AND system_id = ?');
$chk->execute([$docId, $sid]);
if (!$chk->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Document not found']);
    exit;
}

// Sanitise — empty string → NULL for date/name fields
$val = (is_string($value) && trim($value) === '') ? null : $value;

if ($field === 'received_tesda') {
    // When received_tesda is set/updated, also populate returned_center with same value
    $pdo->prepare("UPDATE documents SET received_tesda = ?, returned_center = ?, updated_at = NOW() WHERE id = ? AND system_id = ?")
        ->execute([$val, $val, $docId, $sid]);
} else {
    // Single-field update for other allowed fields
    $pdo->prepare("UPDATE documents SET `{$field}` = ?, updated_at = NOW() WHERE id = ? AND system_id = ?")
        ->execute([$val, $docId, $sid]);
}

echo json_encode(['ok' => true]);
