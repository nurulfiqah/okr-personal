<?php
/**
 * Streams an OKR attachment after checking the requester has view access to
 * the owning card. Files live on the corporate NAS (see nas_config.php), so
 * this is the only way to fetch them.
 */
// lock_adv.php echoes HTML before the auth check runs, which would corrupt
// the file stream below, so buffer and discard it (same trick as header.php).
ob_start();
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');
require_once(__DIR__ . '/lib.php');
ob_end_clean();

$okr_permission = (int)($grade ?? 0);

$okr_is_admin = false;
if (isset($id_user)) {
    $admin_check = mysqli_query($conn, 'SELECT okr FROM staff WHERE id = ' . (int)$id_user);
    if ($admin_check && ($admin_row = mysqli_fetch_assoc($admin_check))) {
        $okr_is_admin = ((int)$admin_row['okr'] === 1);
    }
}

if ($okr_permission === 0 && !$okr_is_admin) {
    http_response_code(403);
    exit('Forbidden');
}

$attachment_id = (int)($_GET['id'] ?? 0);
if ($attachment_id <= 0) {
    http_response_code(404);
    exit('Not found');
}

$scope_where = okrScopeWhere($id_user, $okr_permission, okrDeptIdsFromCsv($department ?? ''), $okr_is_admin);
$query = "SELECT a.stored_name, a.original_name, a.mime_type
          FROM okr_card_attachments a
          JOIN okr_cards c ON a.card_id = c.id
          WHERE a.id = $attachment_id AND c.deleted_at IS NULL AND ($scope_where)";
$result = mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(404);
    exit('Not found');
}

$row     = mysqli_fetch_assoc($result);
$content = corpNasConnect()->download($row['stored_name']);

if ($content === false) {
    http_response_code(404);
    exit('File missing');
}

header('Content-Type: ' . ($row['mime_type'] ? $row['mime_type'] : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $row['original_name']) . '"');
echo $content;
exit;
