<?php
/**
 * OKR backend - direct mysqli JSON endpoint (no separate API service).
 * Mirrors the pattern used by atem/access_control/backend.php: re-query the
 * `staff` table for the requester's identity/grade/department, then dispatch
 * on $_POST['action'] / $_GET['action'] with a flat if-chain.
 */
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json');

if (session_id() == '') {
    session_start();
}

if (!isset($_SESSION['myusername'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$connect = 1;
include(__DIR__ . '/../common/index_adv.php');

if (!isset($conn)) {
    echo json_encode(['error' => 'Database connection error']);
    exit;
}

$username    = mysqli_real_escape_string($conn, $_SESSION['myusername']);
$auth_result = mysqli_query($conn, "SELECT id, grade, department, okr, atem FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$auth_row          = mysqli_fetch_assoc($auth_result);
$requester_id      = (int)$auth_row['id'];
$requester_grade   = (int)$auth_row['grade'];
// SuperAdmin is the union of staff.okr and staff.atem.
$requester_is_admin = ((int)$auth_row['okr'] === 1 || (int)$auth_row['atem'] === 1);
$requester_dept_ids = [];
foreach (explode(',', $auth_row['department']) as $_d) {
    $_d = (int)trim($_d);
    if ($_d > 0) {
        $requester_dept_ids[] = $_d;
    }
}

if ($requester_grade < 1 && !$requester_is_admin) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once(__DIR__ . '/lib.php');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'listCards' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $scope_where = okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $requester_is_admin);
    $result = mysqli_query($conn, okrCardSelectSql($scope_where) . ' ORDER BY c.created_at DESC');

    $cards = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $cards[] = okrFormatCard($row);
        }
    }
    echo json_encode(['success' => true, 'data' => $cards]);
    exit;
}

if ($action === 'dashboardStats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $scope_where = okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $requester_is_admin);

    $filter_year     = (int)($_GET['filter_year'] ?? 0);
    $filter_month    = (int)($_GET['filter_month'] ?? 0);
    $filter_quarter  = (int)($_GET['filter_quarter'] ?? 0);
    $filter_dept_id  = (int)($_GET['filter_dept_id'] ?? 0);
    $filter_staff_id = (int)($_GET['filter_staff_id'] ?? 0);

    $filter_sql = '';
    if ($filter_year > 0)  { $filter_sql .= " AND YEAR(c.start_date) = $filter_year"; }
    if ($filter_month > 0) { $filter_sql .= " AND MONTH(c.start_date) = $filter_month"; }
    elseif ($filter_quarter > 0) { $filter_sql .= " AND QUARTER(c.start_date) = $filter_quarter"; }
    if ($filter_dept_id > 0) {
        $filter_sql .= " AND (FIND_IN_SET($filter_dept_id, c.dept_scope) OR FIND_IN_SET($filter_dept_id, iss.department))";
    }
    if ($filter_staff_id > 0) {
        $filter_sql .= " AND (c.owner_staff_id = $filter_staff_id OR c.owner2_staff_id = $filter_staff_id OR c.issuer_staff_id = $filter_staff_id)";
    }

    $query = "SELECT c.id, c.okr_type, c.difficulty_level, os.value AS result_status, c.start_date, c.end_date,
                     lv.base_rm AS level_rm, lv.label AS level_label, iss.department AS issuer_department
              FROM okr_cards c
              LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
              WHERE c.deleted_at IS NULL AND ($scope_where) $filter_sql";
    $result = mysqli_query($conn, $query);

    $dept_names = [];
    $dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department');
    if ($dept_res) {
        while ($drow = mysqli_fetch_assoc($dept_res)) {
            $dept_names[(int)$drow['id']] = $drow['depart_name'];
        }
    }

    $levels = [];
    $level_res = mysqli_query($conn, 'SELECT level, label FROM okr_levels ORDER BY level');
    if ($level_res) {
        while ($lrow = mysqli_fetch_assoc($level_res)) {
            $levels[(int)$lrow['level']] = [
                'level_id' => (int)$lrow['level'], 'label' => $lrow['label'],
                'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0,
            ];
        }
    }

    $by_type = [];
    foreach (okrTypeValues($conn) as $_type) {
        $by_type[$_type] = [
            'okr_type' => $_type, 'complete' => 0, 'excellence' => 0,
            'extend' => 0, 'suspended' => 0, 'fail' => 0,
        ];
    }

    $by_dept = [];
    $total = 0;
    $active = 0;
    $extended = 0;
    $complete = 0;
    $excellence = 0;
    $failed = 0;
    $overdue = 0;
    $incentive_total = 0.0;
    $today = date('Y-m-d');

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $total++;
            $status = $row['result_status'];
            $level  = (int)$row['difficulty_level'];
            $rm     = (float)$row['level_rm'];
            $is_paid = ($status === 'Complete' || $status === 'Complete with Excellence');

            if ($status === 'Active') { $active++; }
            if ($status === 'Extend') { $extended++; }
            if ($status === 'Complete') { $complete++; }
            if ($status === 'Complete with Excellence') { $excellence++; }
            if ($status === 'Fail') { $failed++; }
            if (($status === 'Active' || $status === 'Extend') && $row['end_date'] < $today) { $overdue++; }
            if ($is_paid) { $incentive_total += $rm; }

            if (isset($levels[$level])) {
                $levels[$level]['cards']++;
                if ($status === 'Complete') { $levels[$level]['complete']++; }
                if ($status === 'Complete with Excellence') { $levels[$level]['excellence']++; }
                if ($status === 'Fail') { $levels[$level]['fail']++; }
                if ($is_paid) { $levels[$level]['forecast'] += $rm; }
            }

            $dept_ids = okrDeptIdsFromCsv($row['issuer_department']);
            $dept_id  = !empty($dept_ids) ? $dept_ids[0] : 0;
            if (!isset($by_dept[$dept_id])) {
                $by_dept[$dept_id] = [
                    'dept_id' => $dept_id,
                    'dept_name' => $dept_id > 0 && isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : 'Unassigned',
                    'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0, 'forecast' => 0.0,
                ];
            }
            $by_dept[$dept_id]['cards']++;
            if ($status === 'Complete') { $by_dept[$dept_id]['complete']++; }
            if ($status === 'Complete with Excellence') { $by_dept[$dept_id]['excellence']++; }
            if ($status === 'Fail') { $by_dept[$dept_id]['fail']++; }
            if ($is_paid) { $by_dept[$dept_id]['forecast'] += $rm; }

            if (isset($by_type[$row['okr_type']])) {
                if ($status === 'Complete') { $by_type[$row['okr_type']]['complete']++; }
                if ($status === 'Complete with Excellence') { $by_type[$row['okr_type']]['excellence']++; }
                if ($status === 'Extend') { $by_type[$row['okr_type']]['extend']++; }
                if ($status === 'Suspended') { $by_type[$row['okr_type']]['suspended']++; }
                if ($status === 'Fail') { $by_type[$row['okr_type']]['fail']++; }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total' => $total,
            'by_status' => [
                'active' => $active, 'extended' => $extended, 'complete' => $complete,
                'excellence' => $excellence, 'failed' => $failed,
            ],
            'overdue_count' => $overdue,
            'incentive_total' => $incentive_total,
            'by_level' => array_values($levels),
            'by_department' => array_values($by_dept),
            'by_type' => array_values($by_type),
        ],
    ]);
    exit;
}

// Per-staff performance scorecard (mirrors ATEM's staff_performance list),
// restricted to senior management/admin same as the ATEM equivalent.
if ($action === 'staffPerformanceList' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($requester_grade < 4 && !$requester_is_admin) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $f = okrPerformanceFilterSql($_GET);

    echo json_encode(['success' => true, 'data' => okrStaffPerformanceRows($conn, $f['filter_sql'], $f['filter_grade'], $f['filter_struct'])]);
    exit;
}

// Locks the incentive on every Complete/Complete with Excellence card matching
// the current Performance filter, so it can no longer be edited, deleted, or
// suspended - the OKR equivalent of a payout being finalised. Restricted to
// People Management (staff_department id 17) or admin.
if ($action === 'lockPayoutCards' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ppm = in_array(17, $requester_dept_ids, true);
    if (!$requester_is_admin && !$is_ppm) {
        echo json_encode(['success' => false, 'message' => 'Only People Management or admin can lock payouts.']);
        exit;
    }

    $f = okrPerformanceFilterSql($_POST);

    $lock_staff_id = (int)($_POST['staff_id'] ?? 0);
    $lock_staff_id_list = [];
    if (!empty($_POST['staff_ids'])) {
        foreach (explode(',', $_POST['staff_ids']) as $_sid) {
            $_sid = (int)trim($_sid);
            if ($_sid > 0) { $lock_staff_id_list[] = $_sid; }
        }
    }
    $lock_remark = trim($_POST['remark'] ?? '');
    $locked_count = okrLockPayoutCards($conn, $requester_id, $f['filter_sql'], $lock_staff_id, $lock_staff_id_list, $lock_remark, $f['filter_grade'], $f['filter_struct']);

    echo json_encode(['success' => true, 'locked_count' => $locked_count]);
    exit;
}

// Reverses lockPayoutCards: unlocks every currently-locked card matching the
// filter, in case People Management locked something in error. Same
// restriction as locking itself.
if ($action === 'unlockPayoutCards' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ppm = in_array(17, $requester_dept_ids, true);
    if (!$requester_is_admin && !$is_ppm) {
        echo json_encode(['success' => false, 'message' => 'Only People Management or admin can unlock payouts.']);
        exit;
    }

    $f = okrPerformanceFilterSql($_POST);

    $unlock_staff_id = (int)($_POST['staff_id'] ?? 0);
    $unlock_staff_id_list = [];
    if (!empty($_POST['staff_ids'])) {
        foreach (explode(',', $_POST['staff_ids']) as $_sid) {
            $_sid = (int)trim($_sid);
            if ($_sid > 0) { $unlock_staff_id_list[] = $_sid; }
        }
    }
    $unlocked_count = okrUnlockPayoutCards($conn, $requester_id, $f['filter_sql'], $unlock_staff_id, $unlock_staff_id_list, $f['filter_grade'], $f['filter_struct']);

    echo json_encode(['success' => true, 'unlocked_count' => $unlocked_count]);
    exit;
}

// Drill-down for one staff member's cards under the same filters as above.
if ($action === 'staffOkrList' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($requester_grade < 4 && !$requester_is_admin) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $staff_id = (int)($_GET['staff_id'] ?? 0);
    if ($staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }

    $f = okrPerformanceFilterSql($_GET);
    $filter_sql = $f['filter_sql'];

    $query = "SELECT c.id, c.objective, c.okr_type, lv.label AS level_label, lv.base_rm AS level_rm,
                     c.start_date, c.end_date, os.value AS result_status,
                     c.owner_staff_id, c.owner2_staff_id, c.incentive_rule, c.incentivised_owner_staff_id
              FROM okr_cards c
              LEFT JOIN okr_levels lv ON c.difficulty_level = lv.level
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
              WHERE c.deleted_at IS NULL AND (c.owner_staff_id = $staff_id OR c.owner2_staff_id = $staff_id) $filter_sql
              ORDER BY c.start_date DESC";
    $result = mysqli_query($conn, $query);

    $cards = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $status  = $row['result_status'];
            $rm      = (float)$row['level_rm'];
            $is_paid = ($status === 'Complete' || $status === 'Complete with Excellence');
            $owner_id  = (int)$row['owner_staff_id'];
            $owner2_id = $row['owner2_staff_id'] !== null ? (int)$row['owner2_staff_id'] : 0;

            $share = 0.0;
            if ($owner2_id > 0) {
                if ((int)$row['incentive_rule'] === 1) {
                    $share = ((int)$row['incentivised_owner_staff_id'] === $staff_id) ? $rm : 0.0;
                } else {
                    $share = $rm / 2;
                }
            } else {
                $share = $rm;
            }

            $cards[] = [
                'id'           => (int)$row['id'],
                'objective'    => $row['objective'],
                'okr_type'     => $row['okr_type'],
                'level_label'  => $row['level_label'],
                'start_date'   => $row['start_date'],
                'end_date'     => $row['end_date'],
                'result_status' => $status,
                'role'         => ($owner2_id === $staff_id) ? '2nd Owner' : 'Owner',
                'rm_share'     => $is_paid ? $share : 0.0,
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $cards]);
    exit;
}

if ($action === 'getCard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }
    $scope_where = okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $requester_is_admin);
    $result = mysqli_query($conn, okrCardSelectSql("c.id = $id AND ($scope_where)"));
    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found or not accessible.']);
        exit;
    }
    $row = mysqli_fetch_assoc($result);
    echo json_encode([
        'success'         => true,
        'data'            => okrFormatCard($row),
        'reference_links' => okrFetchReferenceLinks($conn, $id),
        'attachments'     => okrFetchAttachments($conn, $id),
        'audit_logs'      => okrFetchAuditLogs($conn, $id),
        'can_edit'        => (($requester_is_admin || (int)$row['issuer_staff_id'] === $requester_id) && !(bool)$row['incentive_locked']),
        'can_suspend'     => (($requester_is_admin || $requester_grade === 5) && !(bool)$row['incentive_locked']),
    ]);
    exit;
}

// Silently autosaves the in-progress create form to the session (debounced
// client-side) so a refresh/reopen restores it, mirrors ATEM's draft-save.
// Never touches the database - just scratch state for this user's session.
if ($action === 'saveDraftState' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $state = json_decode($_POST['state'] ?? '', true);
    $_SESSION['okr_draft_state'] = is_array($state) ? $state : [];
    echo json_encode(['success' => true]);
    exit;
}

// Discards the in-progress create form entirely: staged attachments,
// reference links, and the autosaved field state. Used both by the Leave
// modal's "Cancel OKR" and after a successful save (draft or final).
if ($action === 'clearDraftState' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    okrClearDraftSession();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'createCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only senior management or above can issue an OKR.']);
        exit;
    }

    $mode        = trim($_POST['mode'] ?? 'final');
    $objective   = trim($_POST['objective'] ?? '');
    $key_results = trim($_POST['key_results'] ?? '');
    $okr_type    = trim($_POST['okr_type'] ?? '');
    $level       = (int)($_POST['difficulty_level'] ?? 0);
    $owner_id    = (int)($_POST['owner_staff_id'] ?? 0);
    $owner2_id   = (int)($_POST['owner2_staff_id'] ?? 0);
    $owner2_purpose = trim($_POST['owner2_purpose'] ?? '');
    $incentive_rule = (int)($_POST['incentive_rule'] ?? 1);
    $incentivised_owner_id = (int)($_POST['incentivised_owner_staff_id'] ?? 0);
    $dept_scope  = trim($_POST['dept_scope'] ?? '');
    $start_date  = trim($_POST['start_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');

    if ($objective === '' || $key_results === '') {
        echo json_encode(['success' => false, 'message' => 'Objective and Key Results are required.']);
        exit;
    }
    // A draft doesn't need its reference link lined up yet (e.g. the Trello
    // board isn't created) - every other field below is still required
    // because okr_cards has them as NOT NULL columns regardless of status.
    if ($mode !== 'draft' && empty($_SESSION['okr_draft_reflinks'])) {
        echo json_encode(['success' => false, 'message' => 'At least one reference link (name + URL) is required.']);
        exit;
    }
    if (!in_array($okr_type, okrTypeValues($conn, false), true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid OKR type.']);
        exit;
    }
    if ($level < 1 || $level > 4) {
        echo json_encode(['success' => false, 'message' => 'Invalid difficulty level.']);
        exit;
    }
    if ($owner_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'An owner is required.']);
        exit;
    }
    if ($owner2_id > 0 && $owner2_id === $owner_id) {
        echo json_encode(['success' => false, 'message' => 'Owner and 2nd Owner must be different people.']);
        exit;
    }
    if (!in_array($incentive_rule, [1, 2], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid incentive rule.']);
        exit;
    }
    if ($owner2_id > 0) {
        if ($incentive_rule === 2) {
            $incentivised_owner_id = 0;
        } elseif ($incentivised_owner_id !== $owner_id && $incentivised_owner_id !== $owner2_id) {
            echo json_encode(['success' => false, 'message' => 'Select which owner receives the incentive.']);
            exit;
        }
    } else {
        $incentive_rule = 1;
        $incentivised_owner_id = $owner_id;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        echo json_encode(['success' => false, 'message' => 'Start and end dates are required.']);
        exit;
    }
    if (strtotime($end_date) < strtotime($start_date)) {
        echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
        exit;
    }

    $dept_ids = [];
    foreach (explode(',', $dept_scope) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $dept_ids[] = $_d;
        }
    }
    $dept_scope_safe = implode(',', $dept_ids);

    $objective_e   = mysqli_real_escape_string($conn, $objective);
    $key_results_e = mysqli_real_escape_string($conn, $key_results);
    $okr_type_e    = mysqli_real_escape_string($conn, $okr_type);
    $owner2_purpose_e = mysqli_real_escape_string($conn, $owner2_purpose);
    $owner2_sql       = $owner2_id > 0 ? $owner2_id : 'NULL';
    $owner2_purpose_sql = $owner2_id > 0 ? "'$owner2_purpose_e'" : 'NULL';
    $incentivised_owner_sql = $incentivised_owner_id > 0 ? $incentivised_owner_id : 'NULL';

    // Final submissions rely on okr_cards.result_status's own DEFAULT (Active,
    // id 1) same as always; a draft explicitly inserts the Draft status instead.
    $status_column = '';
    $status_value  = '';
    if ($mode === 'draft') {
        $draft_status_id = okrStatusIdByValue($conn, 'Draft');
        $status_column = ', result_status';
        $status_value  = ', ' . ($draft_status_id > 0 ? $draft_status_id : 1);
    }

    $insert = "INSERT INTO okr_cards
        (objective, key_results, okr_type, difficulty_level,
         owner_staff_id, owner2_staff_id, owner2_purpose, incentive_rule, incentivised_owner_staff_id,
         issuer_staff_id, dept_scope, start_date, end_date$status_column)
        VALUES ('$objective_e', '$key_results_e', '$okr_type_e', $level,
                $owner_id, $owner2_sql, $owner2_purpose_sql, $incentive_rule, $incentivised_owner_sql,
                $requester_id, '$dept_scope_safe', '$start_date', '$end_date'$status_value)";

    if (mysqli_query($conn, $insert)) {
        $new_id = mysqli_insert_id($conn);
        okrFinalizeStagedAttachments($conn, $new_id, $requester_id);
        okrFinalizeStagedReferenceLinks($conn, $new_id, $requester_id);
        unset($_SESSION['okr_draft_state']);
        okrLogAudit($conn, $new_id, $requester_id, 'created', null, $mode === 'draft' ? 'OKR card saved as draft.' : 'OKR card created.');
        echo json_encode(['success' => true, 'id' => $new_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Stages a file (for the create form, before the card exists yet) in the
// session so it can be linked once createCard succeeds.
if ($action === 'stageAttachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
        exit;
    }
    $err = okrValidateUpload($_FILES['file']);
    if ($err !== null) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }
    $token = okrStageAttachment($_FILES['file']);
    if ($token === null) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'token'   => $token,
        'name'    => $_FILES['file']['name'],
        'size'    => (int)$_FILES['file']['size'],
    ]);
    exit;
}

if ($action === 'removeStagedAttachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    okrRemoveStagedAttachment($token);
    echo json_encode(['success' => true]);
    exit;
}

// Stages a reference link (for the create form, before the card exists yet).
if ($action === 'stageReferenceLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $url  = trim($_POST['url'] ?? '');
    if ($name === '' || $url === '') {
        echo json_encode(['success' => false, 'message' => 'Both name and URL are required.']);
        exit;
    }
    $token = okrStageReferenceLink($name, $url);
    echo json_encode(['success' => true, 'token' => $token, 'name' => $name, 'url' => $url]);
    exit;
}

if ($action === 'removeStagedReferenceLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    okrRemoveStagedReferenceLink($token);
    echo json_encode(['success' => true]);
    exit;
}

// Adds a reference link directly onto an already-saved card (used from view.php).
if ($action === 'addReferenceLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $url  = trim($_POST['url'] ?? '');
    if ($id <= 0 || $name === '' || $url === '') {
        echo json_encode(['success' => false, 'message' => 'Both name and URL are required.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT issuer_staff_id, incentive_locked FROM okr_cards WHERE id = $id AND deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can add reference links.']);
        exit;
    }
    if ((bool)$card['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }

    $name_e = mysqli_real_escape_string($conn, $name);
    $url_e  = mysqli_real_escape_string($conn, $url);
    $insert = "INSERT INTO okr_reference_links (card_id, name, url, added_by) VALUES ($id, '$name_e', '$url_e', $requester_id)";
    if (mysqli_query($conn, $insert)) {
        $new_link_id = mysqli_insert_id($conn);
        okrLogAudit($conn, $id, $requester_id, 'reference_link_added', null, 'Added reference link: ' . $name);
        echo json_encode(['success' => true, 'id' => $new_link_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'deleteReferenceLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $link_id = (int)($_POST['id'] ?? 0);
    if ($link_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $query = "SELECT rl.card_id, rl.name, c.issuer_staff_id, c.incentive_locked
              FROM okr_reference_links rl
              JOIN okr_cards c ON rl.card_id = c.id
              WHERE rl.id = $link_id AND c.deleted_at IS NULL";
    $result = mysqli_query($conn, $query);
    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Reference link not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($result);
    if (!$requester_is_admin && (int)$row['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can remove reference links.']);
        exit;
    }
    if ((bool)$row['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }
    if (okrCountReferenceLinks($conn, $row['card_id']) <= 1) {
        echo json_encode(['success' => false, 'message' => 'At least one reference link is required — add another before removing this one.']);
        exit;
    }

    mysqli_query($conn, "DELETE FROM okr_reference_links WHERE id = $link_id");
    okrLogAudit($conn, $row['card_id'], $requester_id, 'reference_link_removed', null, 'Removed reference link: ' . $row['name']);
    echo json_encode(['success' => true]);
    exit;
}

// Uploads a file directly onto an already-saved card (used from view.php).
if ($action === 'addAttachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT issuer_staff_id, incentive_locked FROM okr_cards WHERE id = $id AND deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can add attachments.']);
        exit;
    }
    if ((bool)$card['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }

    $err = okrValidateUpload($_FILES['file']);
    if ($err !== null) {
        echo json_encode(['success' => false, 'message' => $err]);
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $stored_name = uniqid('okr_', true) . '.' . $ext;
    $tmp_path = $OKR_UPLOAD_TMP_DIR . $stored_name;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file.']);
        exit;
    }

    $nas_path = corpNasConnect()->upload($tmp_path, CORP_NAS_FOLDER, $stored_name);
    unlink($tmp_path);
    if ($nas_path === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload the file to the NAS.']);
        exit;
    }

    $original_name_e = mysqli_real_escape_string($conn, $_FILES['file']['name']);
    $stored_name_e    = mysqli_real_escape_string($conn, $nas_path);
    $mime_type_e      = mysqli_real_escape_string($conn, (string)$_FILES['file']['type']);
    $size             = (int)$_FILES['file']['size'];

    $insert = "INSERT INTO okr_card_attachments (card_id, original_name, stored_name, size, mime_type, uploaded_by)
               VALUES ($id, '$original_name_e', '$stored_name_e', $size, '$mime_type_e', $requester_id)";
    if (mysqli_query($conn, $insert)) {
        okrLogAudit($conn, $id, $requester_id, 'attachment_added', null, 'Added attachment: ' . $_FILES['file']['name']);
        echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'deleteAttachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $attachment_id = (int)($_POST['id'] ?? 0);
    if ($attachment_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $query = "SELECT a.card_id, a.stored_name, a.original_name, c.issuer_staff_id, c.incentive_locked
              FROM okr_card_attachments a
              JOIN okr_cards c ON a.card_id = c.id
              WHERE a.id = $attachment_id AND c.deleted_at IS NULL";
    $result = mysqli_query($conn, $query);
    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Attachment not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($result);
    if (!$requester_is_admin && (int)$row['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can remove attachments.']);
        exit;
    }
    if ((bool)$row['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }

    corpNasConnect()->delete($row['stored_name']);
    mysqli_query($conn, "DELETE FROM okr_card_attachments WHERE id = $attachment_id");
    okrLogAudit($conn, $row['card_id'], $requester_id, 'attachment_removed', null, 'Removed attachment: ' . $row['original_name']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'updateCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.incentive_locked, c.objective, c.key_results, c.okr_type, c.difficulty_level,
                                          c.owner_staff_id, c.owner2_staff_id, c.owner2_purpose, c.incentive_rule, c.incentivised_owner_staff_id, c.dept_scope,
                                          c.start_date, c.end_date, c.extended, c.extended_date, c.remarks, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can edit this OKR.']);
        exit;
    }
    if ((bool)$card['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }
    if ($card['status_value'] === 'Suspended') {
        echo json_encode(['success' => false, 'message' => 'Unsuspend this OKR before editing it.']);
        exit;
    }

    $objective   = trim($_POST['objective'] ?? '');
    $key_results = trim($_POST['key_results'] ?? '');
    $okr_type    = trim($_POST['okr_type'] ?? '');
    $level       = (int)($_POST['difficulty_level'] ?? 0);
    $owner_id    = (int)($_POST['owner_staff_id'] ?? 0);
    $owner2_id   = (int)($_POST['owner2_staff_id'] ?? 0);
    $owner2_purpose = trim($_POST['owner2_purpose'] ?? '');
    $incentive_rule = (int)($_POST['incentive_rule'] ?? 1);
    $incentivised_owner_id = (int)($_POST['incentivised_owner_staff_id'] ?? 0);
    $dept_scope  = trim($_POST['dept_scope'] ?? '');
    $start_date  = trim($_POST['start_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');
    $status      = trim($_POST['result_status'] ?? 'Active');
    $extended    = ($_POST['extended'] ?? '') === '1';
    $extended_date = trim($_POST['extended_date'] ?? '');
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($objective === '' || $key_results === '') {
        echo json_encode(['success' => false, 'message' => 'Objective and Key Results are required.']);
        exit;
    }
    if ($okr_type !== $card['okr_type'] && !in_array($okr_type, okrTypeValues($conn, false), true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid OKR type.']);
        exit;
    }
    if ($level < 1 || $level > 4) {
        echo json_encode(['success' => false, 'message' => 'Invalid difficulty level.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        echo json_encode(['success' => false, 'message' => 'Start and end dates are required.']);
        exit;
    }
    if (strtotime($end_date) < strtotime($start_date)) {
        echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
        exit;
    }
    if (!in_array($status, $OKR_TIMELINE_STATUSES, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    // Once an OKR has been extended, it can no longer go back to Draft/Active
    // or be marked Complete with Excellence — it can only resolve as
    // Complete, Fail, or stay in Extend while still ongoing. Admins are exempt
    // from this restriction: they may set any status until the OKR is paid
    // (incentive_locked), which is already enforced above.
    if ((bool)$card['extended'] && !in_array($status, ['Complete', 'Extend', 'Fail'], true) && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'This OKR has been extended, so it can now only resolve as Complete or Fail.']);
        exit;
    }
    // Extension is once-only and cannot be undone: once set, the flag and
    // its date are locked to whatever was already saved.
    if ((bool)$card['extended']) {
        $extended = true;
        $extended_date = $card['extended_date'];
    } elseif ($extended) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $extended_date)) {
            echo json_encode(['success' => false, 'message' => 'Extended Date is required when Extended is checked.']);
            exit;
        }
    } else {
        $extended_date = '';
    }
    if ($owner_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'An owner is required.']);
        exit;
    }
    if ($owner2_id > 0 && $owner2_id === $owner_id) {
        echo json_encode(['success' => false, 'message' => 'Owner and 2nd Owner must be different people.']);
        exit;
    }
    if (!in_array($incentive_rule, [1, 2], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid incentive rule.']);
        exit;
    }
    if ($owner2_id > 0) {
        if ($incentive_rule === 2) {
            $incentivised_owner_id = 0;
        } elseif ($incentivised_owner_id !== $owner_id && $incentivised_owner_id !== $owner2_id) {
            echo json_encode(['success' => false, 'message' => 'Select which owner receives the incentive.']);
            exit;
        }
    } else {
        $incentive_rule = 1;
        $incentivised_owner_id = $owner_id;
    }

    $dept_ids = [];
    foreach (explode(',', $dept_scope) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $dept_ids[] = $_d;
        }
    }
    $dept_scope_safe = implode(',', $dept_ids);

    $objective_e   = mysqli_real_escape_string($conn, $objective);
    $key_results_e = mysqli_real_escape_string($conn, $key_results);
    $okr_type_e    = mysqli_real_escape_string($conn, $okr_type);
    $owner2_purpose_e = mysqli_real_escape_string($conn, $owner2_purpose);
    $remarks_e     = mysqli_real_escape_string($conn, $remarks);
    $owner2_sql       = $owner2_id > 0 ? $owner2_id : 'NULL';
    $owner2_purpose_sql = $owner2_id > 0 ? "'$owner2_purpose_e'" : 'NULL';
    $incentivised_owner_sql = $incentivised_owner_id > 0 ? $incentivised_owner_id : 'NULL';
    $extended_sql = $extended ? 1 : 0;
    $extended_date_sql = $extended_date !== '' ? "'$extended_date'" : 'NULL';

    $status_id = okrStatusIdByValue($conn, $status);
    // "Open" = still ongoing (not yet resolved). closed_at should reflect
    // the moment the OKR actually resolved, so it must fire on Extend ->
    // Complete/Fail too, not just Active -> *.
    $open_statuses = ['Draft', 'Active', 'Extend'];
    $was_open = in_array($card['status_value'], $open_statuses, true);
    $is_open  = in_array($status, $open_statuses, true);
    if ($is_open) {
        $closed_sql = ', closed_by = NULL, closed_at = NULL';
    } elseif ($was_open) {
        $closed_sql = ", closed_by = $requester_id, closed_at = NOW()";
    } else {
        $closed_sql = '';
    }
    // Locking is a separate action from completing (handled elsewhere) — a
    // Complete status alone does not lock the card from further edits.
    $update = "UPDATE okr_cards SET
        objective = '$objective_e', key_results = '$key_results_e',
        okr_type = '$okr_type_e', difficulty_level = $level,
        owner_staff_id = $owner_id, owner2_staff_id = $owner2_sql, owner2_purpose = $owner2_purpose_sql,
        incentive_rule = $incentive_rule, incentivised_owner_staff_id = $incentivised_owner_sql,
        dept_scope = '$dept_scope_safe', start_date = '$start_date', end_date = '$end_date',
        extended = $extended_sql, extended_date = $extended_date_sql, remarks = '$remarks_e',
        result_status = $status_id$closed_sql
        WHERE id = $id";

    if (mysqli_query($conn, $update)) {
        $changes = [];
        if ($card['objective'] !== $objective) { $changes['objective'] = [$card['objective'], $objective]; }
        if ($card['key_results'] !== $key_results) { $changes['key_results'] = [$card['key_results'], $key_results]; }
        if ($card['okr_type'] !== $okr_type) { $changes['okr_type'] = [$card['okr_type'], $okr_type]; }
        if ((int)$card['difficulty_level'] !== $level) { $changes['difficulty_level'] = [(int)$card['difficulty_level'], $level]; }
        if ((int)$card['owner_staff_id'] !== $owner_id) { $changes['owner_staff_id'] = [(int)$card['owner_staff_id'], $owner_id]; }
        if ((int)$card['owner2_staff_id'] !== $owner2_id) { $changes['owner2_staff_id'] = [(int)$card['owner2_staff_id'], $owner2_id]; }
        if ((string)$card['owner2_purpose'] !== $owner2_purpose) { $changes['owner2_purpose'] = [$card['owner2_purpose'], $owner2_purpose]; }
        if ((int)$card['incentive_rule'] !== $incentive_rule) { $changes['incentive_rule'] = [(int)$card['incentive_rule'], $incentive_rule]; }
        if ((int)$card['incentivised_owner_staff_id'] !== $incentivised_owner_id) { $changes['incentivised_owner_staff_id'] = [(int)$card['incentivised_owner_staff_id'], $incentivised_owner_id]; }
        if ($card['dept_scope'] !== $dept_scope_safe) { $changes['dept_scope'] = [$card['dept_scope'], $dept_scope_safe]; }
        if ($card['start_date'] !== $start_date) { $changes['start_date'] = [$card['start_date'], $start_date]; }
        if ($card['end_date'] !== $end_date) { $changes['end_date'] = [$card['end_date'], $end_date]; }
        if ($card['status_value'] !== $status) { $changes['result_status'] = [$card['status_value'], $status]; }
        if (!(bool)$card['extended'] && $extended) { $changes['extended'] = [false, true]; }
        if ((string)$card['extended_date'] !== $extended_date) { $changes['extended_date'] = [$card['extended_date'], $extended_date]; }
        if ((string)$card['remarks'] !== $remarks) { $changes['remarks'] = [$card['remarks'], $remarks]; }
        if (!empty($changes)) {
            okrLogAudit($conn, $id, $requester_id, 'updated', $changes, 'OKR details updated.');
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'deleteCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.incentive_locked, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin) {
        if ((int)$card['issuer_staff_id'] !== $requester_id) {
            echo json_encode(['success' => false, 'message' => 'Only the issuer can delete this OKR.']);
            exit;
        }
        if ($card['status_value'] !== 'Draft') {
            echo json_encode(['success' => false, 'message' => 'Only an admin can delete an OKR once it is no longer a Draft.']);
            exit;
        }
    }
    if ((bool)$card['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be deleted.']);
        exit;
    }

    if (mysqli_query($conn, "UPDATE okr_cards SET deleted_at = NOW() WHERE id = $id")) {
        okrLogAudit($conn, $id, $requester_id, 'deleted', null, 'OKR card deleted.');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'permanentlyDeleteCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only an admin can permanently delete an OKR.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT id FROM okr_cards WHERE id = $id AND deleted_at IS NOT NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found or not soft-deleted yet.']);
        exit;
    }

    // Remove uploaded files from the NAS first — the DB rows for attachments,
    // reference links, and audit logs all cascade automatically when the
    // card row itself is deleted (ON DELETE CASCADE), but physical files
    // aren't tracked by a foreign key so they'd otherwise be orphaned.
    $file_result = mysqli_query($conn, "SELECT stored_name FROM okr_card_attachments WHERE card_id = $id");
    if ($file_result) {
        $nas = corpNasConnect();
        while ($file_row = mysqli_fetch_assoc($file_result)) {
            $nas->delete($file_row['stored_name']);
        }
    }

    if (mysqli_query($conn, "DELETE FROM okr_cards WHERE id = $id")) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'suspendCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade !== 5 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only the CEO can suspend an OKR.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.incentive_locked, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ((bool)$card['incentive_locked']) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked after payout and can no longer be changed.']);
        exit;
    }

    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'A reason is required to suspend an OKR.']);
        exit;
    }

    $status_id = okrStatusIdByValue($conn, 'Suspended');
    $reason_e  = mysqli_real_escape_string($conn, $reason);
    $update = "UPDATE okr_cards SET result_status = $status_id, closed_by = $requester_id, closed_at = NOW(), remarks = '$reason_e' WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'suspended',
            ['result_status' => [$card['status_value'], 'Suspended']], 'Suspended by CEO: ' . $reason);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'unsuspendCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade !== 5 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only the CEO can unsuspend an OKR.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.incentive_locked, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ($card['status_value'] !== 'Suspended') {
        echo json_encode(['success' => false, 'message' => 'This OKR is not suspended.']);
        exit;
    }

    // Restore whatever status the card had right before it was suspended
    // (e.g. Complete), instead of always reopening it as Active.
    $restore_status = 'Active';
    $log_result = mysqli_query($conn, "SELECT changes FROM okr_audit_logs
                                        WHERE card_id = $id AND event = 'suspended'
                                        ORDER BY created_at DESC LIMIT 1");
    if ($log_result && ($log_row = mysqli_fetch_assoc($log_result)) && $log_row['changes']) {
        $changes = json_decode($log_row['changes'], true);
        if (isset($changes['result_status'][0]) && $changes['result_status'][0] !== '') {
            $restore_status = $changes['result_status'][0];
        }
    }

    $status_id = okrStatusIdByValue($conn, $restore_status);
    $closed_sql = $restore_status === 'Active' ? ', closed_by = NULL, closed_at = NULL' : '';
    $update = "UPDATE okr_cards SET result_status = $status_id$closed_sql WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'unsuspended',
            ['result_status' => ['Suspended', $restore_status]], 'Unsuspended by CEO.');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);
