<?php
/**
 * CSV export for the Performance page (mirrors atem/staff_performance/export.php).
 * One row per (staff, card) pair via okrExportRows() - a card with two owners
 * produces two rows, one per role.
 */
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

if ($okr_permission < 4 && !$okr_is_admin) {
    http_response_code(403);
    exit('Forbidden');
}

$f = okrPerformanceFilterSql($_GET);
$filter_sql = $f['filter_sql'];

// Download completion cookie for the progress-bar UI (mirrors ATEM's
// staff_performance/export.php dl_token pattern).
$dl_token = (isset($_GET['dl_token']) && $_GET['dl_token'] !== '')
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['dl_token'])
    : '';
if ($dl_token !== '') {
    setcookie('export_done_' . $dl_token, '1', time() + 120, '/');
}

// "Export & Lock" is an explicit opt-in (?lock=1) from People Management
// (staff_department id 17) or admin - locks the same Complete/Complete with
// Excellence cards this export covers, same rule as the Lock Payout button.
// Plain Export never locks anything.
$requester_dept_ids = okrDeptIdsFromCsv($department ?? '');
$is_ppm = $okr_is_admin || in_array(17, $requester_dept_ids, true);
$do_lock = $is_ppm && !empty($_GET['lock']);

$type = $_GET['type'] ?? 'performance';
$staff_id = 0;
$staff_id_list = [];

if (!empty($_GET['staff_ids'])) {
    foreach (explode(',', $_GET['staff_ids']) as $_sid) {
        $_sid = (int)trim($_sid);
        if ($_sid > 0) { $staff_id_list[] = $_sid; }
    }
}

if ($type === 'staff-okr') {
    $staff_id = (int)($_GET['staff_id'] ?? 0);
    if ($staff_id <= 0) {
        http_response_code(400);
        exit('Invalid staff.');
    }
    $name_res = mysqli_query($conn, 'SELECT nama_staff FROM staff WHERE id = ' . $staff_id);
    $staff_name = ($name_res && ($nrow = mysqli_fetch_assoc($name_res))) ? $nrow['nama_staff'] : 'staff';
    $filename = 'okr_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $staff_name) . '.csv';
} else {
    $filename = !empty($staff_id_list) ? 'okr_performance_selected.csv' : 'okr_performance.csv';
}

if ($do_lock) {
    okrLockPayoutCards($conn, (int)$id_user, $filter_sql, $staff_id, $staff_id_list, '', $f['filter_grade'], $f['filter_struct']);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

fputcsv($out, [
    'Year', 'Month', 'Name', 'Department', 'Grade', 'Evaluation Structure',
    'OKR ID', 'OKR Title', 'OKR Type', 'Level/Complexity', 'Start Date', 'End Date',
    'Issuer', '2nd Owner', 'Role', 'Incentive Rule', 'OKR Status', 'Est. Reward (RM)',
]);
foreach (okrExportRows($conn, $filter_sql, $staff_id, $staff_id_list, $f['filter_grade'], $f['filter_struct']) as $r) {
    fputcsv($out, [
        $r['year'], $r['month'], $r['name'], $r['department'], $r['grade'], $r['struct'],
        $r['okr_id'], $r['title'], $r['okr_type'], $r['level'], $r['start_date'], $r['end_date'],
        $r['issuer'], $r['owner2_name'], $r['role'], $r['incentive_rule_label'], $r['status'], number_format($r['reward'], 2),
    ]);
}

fclose($out);
exit;
