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
$auth_result = mysqli_query($conn, "SELECT id, nama_staff, grade, department, okr, atem FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$auth_row          = mysqli_fetch_assoc($auth_result);
$requester_id      = (int)$auth_row['id'];
$requester_name    = $auth_row['nama_staff'];
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
require_once(__DIR__ . '/mailer.php');

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

    $query = "SELECT c.id, c.okr_type, os.value AS result_status, c.force_terminated,
                     c.start_date, c.end_date, c.issuer_staff_id,
                     iss.nama_staff AS issuer_name, iss.department AS issuer_department
              FROM okr_cards c
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

    $by_type = [];
    foreach (okrTypeValues($conn) as $_type) {
        $by_type[$_type] = [
            'okr_type' => $_type, 'complete' => 0, 'excellence' => 0,
            'extend' => 0, 'suspended' => 0, 'fail' => 0,
        ];
    }

    $by_dept = [];
    $by_staff = [];
    $total = 0;
    $active = 0;
    $extended = 0;
    $complete = 0;
    $excellence = 0;
    $failed = 0;
    $overdue = 0;
    $today = date('Y-m-d');

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $total++;
            $status = $row['result_status'];
            // Completed with Extension folds into the "complete" bucket
            // alongside a plain Completed - see updateCard.
            $is_complete = ($status === OKR_STATUS_COMPLETED || $status === OKR_STATUS_COMPLETED_EXTENSION);

            if ($status === OKR_STATUS_ACTIVE) { $active++; }
            if ($status === 'Extended') { $extended++; }
            if ($is_complete) { $complete++; }
            if ($status === 'Completed with Excellence') { $excellence++; }
            if ($status === 'Failed') { $failed++; }
            if (($status === OKR_STATUS_ACTIVE || $status === 'Extended') && $row['end_date'] < $today) { $overdue++; }

            $dept_ids = okrDeptIdsFromCsv($row['issuer_department']);
            $dept_id  = !empty($dept_ids) ? $dept_ids[0] : 0;
            if (!isset($by_dept[$dept_id])) {
                $by_dept[$dept_id] = [
                    'dept_id' => $dept_id,
                    'dept_name' => $dept_id > 0 && isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : 'Unassigned',
                    'cards' => 0, 'complete' => 0, 'excellence' => 0, 'fail' => 0,
                    'suspended' => 0, 'force_terminated' => 0,
                ];
            }
            // Force Terminate isn't a separate status - it sets Failed plus
            // this flag - so a force-terminated card is excluded from the
            // plain "fail" tally below and counted only in its own bucket,
            // otherwise it would double up against ordinary Failed cards.
            $is_force_terminated = !empty($row['force_terminated']);

            $by_dept[$dept_id]['cards']++;
            if ($is_complete) { $by_dept[$dept_id]['complete']++; }
            if ($status === 'Completed with Excellence') { $by_dept[$dept_id]['excellence']++; }
            if ($status === 'Failed' && !$is_force_terminated) { $by_dept[$dept_id]['fail']++; }
            if ($status === OKR_STATUS_SUSPENDED) { $by_dept[$dept_id]['suspended']++; }
            if ($is_force_terminated) { $by_dept[$dept_id]['force_terminated']++; }

            // Top offenders for the Suspended & Force Terminated ranking -
            // keyed by issuer, only tallying the two buckets that matter
            // there so a staff member with no suspensions never shows up.
            $issuer_id = (int)$row['issuer_staff_id'];
            if ($status === OKR_STATUS_SUSPENDED || $is_force_terminated) {
                if (!isset($by_staff[$issuer_id])) {
                    $by_staff[$issuer_id] = [
                        'staff_id' => $issuer_id,
                        'staff_name' => $row['issuer_name'] ?: ('Staff #' . $issuer_id),
                        'suspended' => 0, 'force_terminated' => 0,
                    ];
                }
                if ($status === OKR_STATUS_SUSPENDED) { $by_staff[$issuer_id]['suspended']++; }
                if ($is_force_terminated) { $by_staff[$issuer_id]['force_terminated']++; }
            }

            if (isset($by_type[$row['okr_type']])) {
                if ($is_complete) { $by_type[$row['okr_type']]['complete']++; }
                if ($status === 'Completed with Excellence') { $by_type[$row['okr_type']]['excellence']++; }
                if ($status === 'Extended') { $by_type[$row['okr_type']]['extend']++; }
                if ($status === 'Suspended') { $by_type[$row['okr_type']]['suspended']++; }
                if ($status === 'Failed') { $by_type[$row['okr_type']]['fail']++; }
            }
        }
    }

    // Top 10 offenders by (suspended + force_terminated) count, most first -
    // sorted server-side so index.js just renders the array as-is.
    $by_staff_suspend = array_values($by_staff);
    usort($by_staff_suspend, function ($a, $b) {
        return ($b['suspended'] + $b['force_terminated']) - ($a['suspended'] + $a['force_terminated']);
    });
    $by_staff_suspend = array_slice($by_staff_suspend, 0, 10);

    echo json_encode([
        'success' => true,
        'data' => [
            'total' => $total,
            'by_status' => [
                'active' => $active, 'extended' => $extended, 'complete' => $complete,
                'excellence' => $excellence, 'failed' => $failed,
            ],
            'overdue_count' => $overdue,
            'by_department' => array_values($by_dept),
            'by_type' => array_values($by_type),
            'by_staff_suspend' => $by_staff_suspend,
        ],
    ]);
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
        'can_edit'        => ($requester_is_admin || (int)$row['issuer_staff_id'] === $requester_id),
        'can_suspend'     => ($requester_is_admin || $requester_grade === 5),
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
    $okr_type    = trim($_POST['okr_type'] ?? '');
    $owner_id    = (int)($_POST['owner_staff_id'] ?? 0);
    $owner2_id   = (int)($_POST['owner2_staff_id'] ?? 0);
    $owner2_purpose = trim($_POST['owner2_purpose'] ?? '');
    $dept_scope  = trim($_POST['dept_scope'] ?? '');
    $start_date  = trim($_POST['start_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');

    if ($objective === '') {
        echo json_encode(['success' => false, 'message' => 'Objective is required.']);
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
    if ($owner_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'An owner is required.']);
        exit;
    }
    if ($owner2_id > 0 && $owner2_id === $owner_id) {
        echo json_encode(['success' => false, 'message' => 'Owner and 2nd Owner must be different people.']);
        exit;
    }
    // Issuer must register as one of the Owner(s) (OKR's ARCI-equivalent) -
    // enforced going forward only, existing cards with issuer != owner are
    // untouched.
    if ($requester_id !== $owner_id && $requester_id !== $owner2_id) {
        echo json_encode(['success' => false, 'message' => 'The issuer must be tagged as one of this OKR\'s owner(s).']);
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

    $dept_ids = [];
    foreach (explode(',', $dept_scope) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $dept_ids[] = $_d;
        }
    }
    $dept_scope_safe = implode(',', $dept_ids);

    $objective_e   = mysqli_real_escape_string($conn, $objective);
    $okr_type_e    = mysqli_real_escape_string($conn, $okr_type);
    $owner2_purpose_e = mysqli_real_escape_string($conn, $owner2_purpose);
    $owner2_sql       = $owner2_id > 0 ? $owner2_id : 'NULL';
    $owner2_purpose_sql = $owner2_id > 0 ? "'$owner2_purpose_e'" : 'NULL';

    // Always resolve the status id explicitly rather than relying on
    // okr_cards.result_status's DB column default - okr_statuses' ids are
    // admin-editable (they were reordered once already), so the "default"
    // status must be looked up by name every time, not assumed to be id 1.
    $status_id = okrStatusIdByValue($conn, $mode === 'draft' ? OKR_STATUS_DRAFT : OKR_STATUS_ACTIVE);
    if ($status_id <= 0) {
        $status_id = $mode === 'draft' ? 1 : 2;
    }

    // difficulty_level is a NOT NULL column with a foreign key into okr_levels
    // (no default) - the incentive system that column belonged to is retired,
    // but the schema itself is untouched, so every insert must still supply a
    // valid level. Level 1 always exists (it's the RM0 "no incentive" level
    // from the old system) and is otherwise unused now. key_results is also
    // NOT NULL with no default - the field was removed from the UI (slated
    // for replacement), so every insert supplies an empty string.
    $insert = "INSERT INTO okr_cards
        (objective, key_results, okr_type, difficulty_level,
         owner_staff_id, owner2_staff_id, owner2_purpose,
         issuer_staff_id, dept_scope, start_date, end_date, result_status)
        VALUES ('$objective_e', '', '$okr_type_e', 1,
                $owner_id, $owner2_sql, $owner2_purpose_sql,
                $requester_id, '$dept_scope_safe', '$start_date', '$end_date', $status_id)";

    if (mysqli_query($conn, $insert)) {
        $new_id = mysqli_insert_id($conn);
        okrFinalizeStagedAttachments($conn, $new_id, $requester_id);
        okrFinalizeStagedReferenceLinks($conn, $new_id, $requester_id);
        okrFinalizeStagedKeyResults($conn, $new_id, $requester_id);
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

// Stages a top-level Key Result (for the create form, before the card exists
// yet). Subtasks and ATEM links can also be staged against its token below -
// see stageKeyResultSubtask/stageKeyResultAtemLink.
if ($action === 'stageKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $description = trim($_POST['description'] ?? '');
    if ($description === '') {
        echo json_encode(['success' => false, 'message' => 'Action details are required.']);
        exit;
    }
    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date   = trim($_POST['end_date'] ?? '') ?: null;
    $status_id  = (int)($_POST['status_id'] ?? 0);
    $allowed_statuses = okrKeyResultAssignableStatuses($conn);
    $allowed_ids = array_column($allowed_statuses, 'id');
    if (!in_array($status_id, $allowed_ids, true)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid status.']);
        exit;
    }
    $status_value = array_column($allowed_statuses, 'value', 'id')[$status_id];

    $token = okrStageKeyResult($description, $start_date, $end_date, $status_id);

    echo json_encode([
        'success'      => true,
        'token'        => $token,
        'description'  => $description,
        'creator_name' => $requester_name,
        'start_date'   => $start_date,
        'end_date'     => $end_date,
        'status_id'    => $status_id,
        'status_value' => $status_value,
        'pill_class'   => okrPillClass($status_value),
    ]);
    exit;
}

if ($action === 'removeStagedKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    okrRemoveStagedKeyResult($token);
    echo json_encode(['success' => true]);
    exit;
}

// Stages a Subtask under a still-staged top-level Key Result (create form,
// before the card - and its Key Results - exist yet). Mirrors createKeyResult's
// real-row version, but nests inside the parent's session entry instead of a
// real parent_id.
if ($action === 'stageKeyResultSubtask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 3 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $parent_token = $_POST['parent_token'] ?? '';
    $description = trim($_POST['description'] ?? '');
    if ($parent_token === '' || $description === '') {
        echo json_encode(['success' => false, 'message' => 'Parent Key Result and action details are required.']);
        exit;
    }
    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date   = trim($_POST['end_date'] ?? '') ?: null;
    $status_id  = (int)($_POST['status_id'] ?? 0);
    $allowed_statuses = okrKeyResultAssignableStatuses($conn);
    $allowed_ids = array_column($allowed_statuses, 'id');
    if (!in_array($status_id, $allowed_ids, true)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid status.']);
        exit;
    }
    $status_value = array_column($allowed_statuses, 'value', 'id')[$status_id];

    $sub_token = okrStageKeyResultSubtask($parent_token, $description, $start_date, $end_date, $status_id);
    if ($sub_token === null) {
        echo json_encode(['success' => false, 'message' => 'Parent Key Result not found.']);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'token'        => $sub_token,
        'parent_token' => $parent_token,
        'description'  => $description,
        'creator_name' => $requester_name,
        'start_date'   => $start_date,
        'end_date'     => $end_date,
        'status_id'    => $status_id,
        'status_value' => $status_value,
        'pill_class'   => okrPillClass($status_value),
    ]);
    exit;
}

if ($action === 'removeStagedKeyResultSubtask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent_token = $_POST['parent_token'] ?? '';
    $token = $_POST['token'] ?? '';
    okrRemoveStagedKeyResultSubtask($parent_token, $token);
    echo json_encode(['success' => true]);
    exit;
}

// Links/unlinks an existing real ATEM card against a still-staged top-level
// Key Result. Same "plain int reference, no FK" rule as linkKeyResultAtem -
// the frontend resolves the title by calling atem/api.php directly.
if ($action === 'stageKeyResultAtemLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $atem_id = (int)($_POST['atem_id'] ?? 0);
    if ($token === '' || $atem_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result or ATEM.']);
        exit;
    }
    if (!okrSetStagedKeyResultAtem($token, $atem_id)) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'removeStagedKeyResultAtemLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    okrSetStagedKeyResultAtem($token, null);
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

    $check = mysqli_query($conn, "SELECT issuer_staff_id FROM okr_cards WHERE id = $id AND deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can add reference links.']);
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

    $query = "SELECT rl.card_id, rl.name, c.issuer_staff_id
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
    if (okrCountReferenceLinks($conn, $row['card_id']) <= 1) {
        echo json_encode(['success' => false, 'message' => 'At least one reference link is required — add another before removing this one.']);
        exit;
    }

    mysqli_query($conn, "DELETE FROM okr_reference_links WHERE id = $link_id");
    okrLogAudit($conn, $row['card_id'], $requester_id, 'reference_link_removed', null, 'Removed reference link: ' . $row['name']);
    echo json_encode(['success' => true]);
    exit;
}

// Key Result Progress: read for an already-saved card (used by edit.php and
// view.php). Read access mirrors the card's own visibility scope, not just
// the issuer/admin edit gate - anyone who can see the card sees its
// Key Results.
if ($action === 'listKeyResults' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }
    $scope_where = okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $requester_is_admin);
    $check = mysqli_query($conn, "SELECT id FROM okr_cards c WHERE c.id = $id AND c.deleted_at IS NULL AND ($scope_where)");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found or not accessible.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => okrFetchKeyResults($conn, $id)]);
    exit;
}

// Adds a Key Result (parent_id blank) or a Subtask (parent_id set) directly
// onto an already-saved card. Same edit gate as updateCard: issuer or admin,
// and not Suspended.
if ($action === 'createKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_id = (int)($_POST['card_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    if ($card_id <= 0 || $description === '') {
        echo json_encode(['success' => false, 'message' => 'Card and action details are required.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $card_id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can add Key Results.']);
        exit;
    }
    if ($card['status_value'] === 'Suspended' || $card['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    $parent_id = (int)($_POST['parent_id'] ?? 0) ?: null;
    if ($parent_id !== null) {
        $parent_check = mysqli_query($conn, "SELECT id FROM okr_key_results WHERE id = $parent_id AND card_id = $card_id AND parent_id IS NULL");
        if (!$parent_check || mysqli_num_rows($parent_check) === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parent Key Result.']);
            exit;
        }
    }

    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date   = trim($_POST['end_date'] ?? '') ?: null;
    $status_id  = (int)($_POST['status_id'] ?? 0);
    $allowed_statuses = okrKeyResultAssignableStatuses($conn);
    $allowed_ids = array_column($allowed_statuses, 'id');
    if (!in_array($status_id, $allowed_ids, true)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid status.']);
        exit;
    }
    $status_value = array_column($allowed_statuses, 'value', 'id')[$status_id];

    $description_e = mysqli_real_escape_string($conn, $description);
    $parent_sql = $parent_id !== null ? $parent_id : 'NULL';
    $start_sql = $start_date !== null ? "'" . mysqli_real_escape_string($conn, $start_date) . "'" : 'NULL';
    $end_sql = $end_date !== null ? "'" . mysqli_real_escape_string($conn, $end_date) . "'" : 'NULL';

    $insert = "INSERT INTO okr_key_results
        (card_id, parent_id, description, status_id, start_date, end_date, created_by)
        VALUES ($card_id, $parent_sql, '$description_e', $status_id, $start_sql, $end_sql, $requester_id)";
    if (mysqli_query($conn, $insert)) {
        $new_id = mysqli_insert_id($conn);
        okrLogAudit($conn, $card_id, $requester_id, $parent_id !== null ? 'subtask_added' : 'key_result_added', null,
            ($parent_id !== null ? 'Added subtask: ' : 'Added Key Result: ') . $description);
        echo json_encode([
            'success'      => true,
            'id'           => $new_id,
            'creator_name' => $requester_name,
            'status_id'    => $status_id,
            'status_value' => $status_value,
            'pill_class'   => okrPillClass($status_value),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'updateKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, c.issuer_staff_id, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$kr['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can edit Key Results.']);
        exit;
    }
    if ($kr['status_value'] === 'Suspended' || $kr['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    $description = trim($_POST['description'] ?? '');
    if ($description === '') {
        echo json_encode(['success' => false, 'message' => 'Action details are required.']);
        exit;
    }
    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date   = trim($_POST['end_date'] ?? '') ?: null;
    $status_id  = (int)($_POST['status_id'] ?? 0);
    $allowed_statuses = okrKeyResultAssignableStatuses($conn);
    $allowed_ids = array_column($allowed_statuses, 'id');
    if (!in_array($status_id, $allowed_ids, true)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid status.']);
        exit;
    }
    $status_value = array_column($allowed_statuses, 'value', 'id')[$status_id];

    $description_e = mysqli_real_escape_string($conn, $description);
    $start_sql = $start_date !== null ? "'" . mysqli_real_escape_string($conn, $start_date) . "'" : 'NULL';
    $end_sql = $end_date !== null ? "'" . mysqli_real_escape_string($conn, $end_date) . "'" : 'NULL';

    $update = "UPDATE okr_key_results SET
        description = '$description_e',
        start_date = $start_sql, end_date = $end_sql, status_id = $status_id
        WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $kr['card_id'], $requester_id,
            $kr['parent_id'] !== null ? 'subtask_updated' : 'key_result_updated', null,
            ($kr['parent_id'] !== null ? 'Updated subtask: ' : 'Updated Key Result: ') . $description);
        echo json_encode([
            'success'      => true,
            'status_id'    => $status_id,
            'status_value' => $status_value,
            'pill_class'   => okrPillClass($status_value),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Deleting a Key Result cascades to its Subtasks (ON DELETE CASCADE).
if ($action === 'deleteKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, kr.description, c.issuer_staff_id, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$kr['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can remove Key Results.']);
        exit;
    }
    if ($kr['status_value'] === 'Suspended' || $kr['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    mysqli_query($conn, "DELETE FROM okr_key_results WHERE id = $id");
    okrLogAudit($conn, $kr['card_id'], $requester_id,
        $kr['parent_id'] !== null ? 'subtask_removed' : 'key_result_removed', null,
        ($kr['parent_id'] !== null ? 'Removed subtask: ' : 'Removed Key Result: ') . $kr['description']);
    echo json_encode(['success' => true]);
    exit;
}

// Links a Key Result to an existing card in the real ATEM module. ATEM lives
// in a separate Laravel service (atem-api), not this database, so atem_id is
// a bare int reference only - never validated/joined against here. The
// frontend resolves it for display by calling atem/api.php directly (same
// session, same origin).
if ($action === 'linkKeyResultAtem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $atem_id = (int)($_POST['atem_id'] ?? 0);
    if ($id <= 0 || $atem_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result or ATEM.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, kr.description, c.issuer_staff_id, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$kr['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can link an ATEM.']);
        exit;
    }
    if ($kr['status_value'] === 'Suspended' || $kr['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    mysqli_query($conn, "UPDATE okr_key_results SET atem_id = $atem_id WHERE id = $id");
    okrLogAudit($conn, $kr['card_id'], $requester_id,
        $kr['parent_id'] !== null ? 'subtask_atem_linked' : 'key_result_atem_linked', null,
        'Linked ATEM #' . $atem_id . ' to: ' . $kr['description']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'unlinkKeyResultAtem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, kr.description, c.issuer_staff_id, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$kr['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can unlink an ATEM.']);
        exit;
    }
    if ($kr['status_value'] === 'Suspended' || $kr['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    mysqli_query($conn, "UPDATE okr_key_results SET atem_id = NULL WHERE id = $id");
    okrLogAudit($conn, $kr['card_id'], $requester_id,
        $kr['parent_id'] !== null ? 'subtask_atem_unlinked' : 'key_result_atem_unlinked', null,
        'Unlinked ATEM from: ' . $kr['description']);
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

    $check = mysqli_query($conn, "SELECT issuer_staff_id FROM okr_cards WHERE id = $id AND deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can add attachments.']);
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

    $query = "SELECT a.card_id, a.stored_name, a.original_name, c.issuer_staff_id
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

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.objective, c.okr_type,
                                          c.owner_staff_id, c.owner2_staff_id, c.owner2_purpose, c.dept_scope,
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
    if ($card['status_value'] === 'Suspended' || $card['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    $objective   = trim($_POST['objective'] ?? '');
    $okr_type    = trim($_POST['okr_type'] ?? '');
    $owner_id    = (int)($_POST['owner_staff_id'] ?? 0);
    $owner2_id   = (int)($_POST['owner2_staff_id'] ?? 0);
    $owner2_purpose = trim($_POST['owner2_purpose'] ?? '');
    $dept_scope  = trim($_POST['dept_scope'] ?? '');
    // Start/End Date are locked once an OKR is created - only an admin can
    // still change them; a non-admin issuer's posted values are silently
    // ignored in favour of whatever is already saved (mirrors the Start Date
    // input already being disabled client-side, just extended to End Date
    // too and enforced server-side so it can't be bypassed).
    $start_date  = $requester_is_admin ? trim($_POST['start_date'] ?? '') : $card['start_date'];
    $end_date    = $requester_is_admin ? trim($_POST['end_date'] ?? '') : $card['end_date'];
    $status      = trim($_POST['result_status'] ?? OKR_STATUS_ACTIVE);
    $extended    = ($_POST['extended'] ?? '') === '1';
    $extended_date = trim($_POST['extended_date'] ?? '');
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($objective === '') {
        echo json_encode(['success' => false, 'message' => 'Objective is required.']);
        exit;
    }
    if ($okr_type !== $card['okr_type'] && !in_array($okr_type, okrTypeValues($conn, false), true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid OKR type.']);
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
    if (!in_array($status, okrTimelineAssignableStatuses($conn), true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }
    // Once an OKR has been extended, it can no longer go back to Draft/Active
    // or be marked Completed with Excellence — it can only resolve as
    // Completed, Failed, or stay Extended while still ongoing. This specific
    // 3-way resolution is a business rule, not something read off the table,
    // so these names stay literal (they have no other identity elsewhere).
    // Admins are exempt from this restriction and may set any status.
    if ((bool)$card['extended'] && !in_array($status, okrPostExtensionResolvableStatuses(), true) && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'This OKR has been extended, so it can now only resolve as Completed or Failed.']);
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

    $dept_ids = [];
    foreach (explode(',', $dept_scope) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $dept_ids[] = $_d;
        }
    }
    $dept_scope_safe = implode(',', $dept_ids);

    $objective_e   = mysqli_real_escape_string($conn, $objective);
    $okr_type_e    = mysqli_real_escape_string($conn, $okr_type);
    $owner2_purpose_e = mysqli_real_escape_string($conn, $owner2_purpose);
    $remarks_e     = mysqli_real_escape_string($conn, $remarks);
    $owner2_sql       = $owner2_id > 0 ? $owner2_id : 'NULL';
    $owner2_purpose_sql = $owner2_id > 0 ? "'$owner2_purpose_e'" : 'NULL';
    $extended_sql = $extended ? 1 : 0;
    $extended_date_sql = $extended_date !== '' ? "'$extended_date'" : 'NULL';

    $status_id = okrStatusIdByValue($conn, $status);
    // An OKR resolved as Completed while already extended is stored as the
    // more specific Completed with Extension status instead of plain
    // Completed - $final_status tracks the actually-persisted value for the
    // audit-diff comparison below (the dropdown always submits the plain
    // "Completed" option value, so comparing against raw $status would log a
    // spurious change every time an already-resolved card is re-saved).
    $final_status = $status;
    if ($status === OKR_STATUS_COMPLETED && $extended) {
        $extension_id = okrStatusIdByValue($conn, OKR_STATUS_COMPLETED_EXTENSION);
        if ($extension_id > 0) {
            $status_id = $extension_id;
            $final_status = OKR_STATUS_COMPLETED_EXTENSION;
        }
    }
    // "Open" = still ongoing (not yet resolved). closed_at should reflect
    // the moment the OKR actually resolved, so it must fire on Extended ->
    // Completed/Failed too, not just Active -> *. No DB column encodes this,
    // so it stays a small constant-based list.
    $open_statuses = [OKR_STATUS_DRAFT, OKR_STATUS_ACTIVE, 'Extended'];
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
        objective = '$objective_e',
        okr_type = '$okr_type_e',
        owner_staff_id = $owner_id, owner2_staff_id = $owner2_sql, owner2_purpose = $owner2_purpose_sql,
        dept_scope = '$dept_scope_safe', start_date = '$start_date', end_date = '$end_date',
        extended = $extended_sql, extended_date = $extended_date_sql, remarks = '$remarks_e',
        result_status = $status_id$closed_sql
        WHERE id = $id";

    if (mysqli_query($conn, $update)) {
        $changes = [];
        if ($card['objective'] !== $objective) { $changes['objective'] = [$card['objective'], $objective]; }
        if ($card['okr_type'] !== $okr_type) { $changes['okr_type'] = [$card['okr_type'], $okr_type]; }
        if ((int)$card['owner_staff_id'] !== $owner_id) { $changes['owner_staff_id'] = [(int)$card['owner_staff_id'], $owner_id]; }
        if ((int)$card['owner2_staff_id'] !== $owner2_id) { $changes['owner2_staff_id'] = [(int)$card['owner2_staff_id'], $owner2_id]; }
        if ((string)$card['owner2_purpose'] !== $owner2_purpose) { $changes['owner2_purpose'] = [$card['owner2_purpose'], $owner2_purpose]; }
        if ($card['dept_scope'] !== $dept_scope_safe) { $changes['dept_scope'] = [$card['dept_scope'], $dept_scope_safe]; }
        if ($card['start_date'] !== $start_date) { $changes['start_date'] = [$card['start_date'], $start_date]; }
        if ($card['end_date'] !== $end_date) { $changes['end_date'] = [$card['end_date'], $end_date]; }
        if ($card['status_value'] !== $final_status) { $changes['result_status'] = [$card['status_value'], $final_status]; }
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

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, os.value AS status_value
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

    $check = mysqli_query($conn, "SELECT c.objective, c.issuer_staff_id, os.value AS status_value,
                                          s.nama_staff AS issuer_name, s.email AS issuer_email
                                   FROM okr_cards c
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   LEFT JOIN staff s ON c.issuer_staff_id = s.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ($card['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'A Failed OKR is already resolved and cannot be suspended.']);
        exit;
    }

    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'A reason is required to suspend an OKR.']);
        exit;
    }

    $status_id = okrStatusIdByValue($conn, OKR_STATUS_SUSPENDED);
    $reason_e  = mysqli_real_escape_string($conn, $reason);
    $update = "UPDATE okr_cards SET result_status = $status_id, closed_by = $requester_id, closed_at = NOW(),
               remarks = '$reason_e', appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'suspended',
            ['result_status' => [$card['status_value'], OKR_STATUS_SUSPENDED]], 'Suspended by CEO: ' . $reason);

        // Best-effort - a mail failure must never affect this response, the
        // status change already committed above.
        sendOkrSuspensionEmail($card['issuer_email'], $card['issuer_name'], $id, $card['objective'], $reason, $requester_name);

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

    $check = mysqli_query($conn, "SELECT os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ($card['status_value'] !== OKR_STATUS_SUSPENDED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is not suspended.']);
        exit;
    }

    // Unsuspend always reopens as Active (write off Closure Date) - it no
    // longer restores whatever status the card had before it was suspended.
    $status_id = okrStatusIdByValue($conn, OKR_STATUS_ACTIVE);
    $update = "UPDATE okr_cards SET result_status = $status_id, closed_by = NULL, closed_at = NULL,
               appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'unsuspended',
            ['result_status' => [OKR_STATUS_SUSPENDED, OKR_STATUS_ACTIVE]], 'Unsuspended by CEO. Reopened as Active.');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Issuer appeals a suspension by submitting a justification - one pending
// appeal per suspension cycle (cleared on unsuspend/force-terminate). Emails
// every CEO/admin recipient (okrCeoRecipients) with the justification + OKR
// number and a plain login-gated deep link (see mailer.php's comment on why
// this isn't a magic bypass link).
if ($action === 'appealSuspension' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $justification = trim($_POST['justification'] ?? '');
    if ($id <= 0 || $justification === '') {
        echo json_encode(['success' => false, 'message' => 'A justification is required to appeal.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.objective, c.issuer_staff_id, c.appealed_at, os.value AS status_value,
                                          s.nama_staff AS issuer_name
                                   FROM okr_cards c
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   LEFT JOIN staff s ON c.issuer_staff_id = s.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ((int)$card['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can appeal a suspension.']);
        exit;
    }
    if ($card['status_value'] !== OKR_STATUS_SUSPENDED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is not suspended.']);
        exit;
    }
    if (!empty($card['appealed_at'])) {
        echo json_encode(['success' => false, 'message' => 'An appeal has already been submitted for this suspension.']);
        exit;
    }

    $justification_e = mysqli_real_escape_string($conn, $justification);
    $update = "UPDATE okr_cards SET appeal_justification = '$justification_e', appealed_at = NOW() WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'appealed', null, 'Appeal submitted: ' . $justification);

        foreach (okrCeoRecipients($conn) as $recipient) {
            sendOkrAppealEmail($recipient['email'], $recipient['name'], $id, $card['objective'], $justification, $card['issuer_name']);
        }

        echo json_encode(['success' => true, 'appealed_at' => date('Y-m-d H:i:s')]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// CEO/admin forcibly terminates a suspended OKR - the other branch of the
// Suspend > Appeal > Active/Force Terminate flow. Only reachable from
// Suspended, same remark requirement as Suspend itself.
if ($action === 'forceTerminateCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade !== 5 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only the CEO can force terminate an OKR.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    if ($id <= 0 || $remark === '') {
        echo json_encode(['success' => false, 'message' => 'A remark is required to force terminate an OKR.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ($card['status_value'] !== OKR_STATUS_SUSPENDED) {
        echo json_encode(['success' => false, 'message' => 'Only a suspended OKR can be force terminated.']);
        exit;
    }

    // Force Terminate is not a separate status - a force-terminated OKR is,
    // semantically, a Failed one. The force_terminated flag is what lets the
    // dashboard ranking (and anything else) tell it apart from an ordinary
    // Failed OKR.
    $status_id = okrStatusIdByValue($conn, 'Failed');
    $remark_e  = mysqli_real_escape_string($conn, $remark);
    $update = "UPDATE okr_cards SET result_status = $status_id, force_terminated = 1, closed_by = $requester_id, closed_at = NOW(),
               remarks = '$remark_e', appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'force_terminated',
            ['result_status' => [OKR_STATUS_SUSPENDED, 'Failed']], 'Force terminated by CEO: ' . $remark);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// CEO/admin-only quality rating: 0-5 stars in half-star steps. Not gated on
// incentive_locked - a rating can be given/changed any time up until the OKR
// is Failed (a terminal, already-resolved outcome - see the Failed check
// below).
if ($action === 'rateCard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade !== 5 && !$requester_is_admin) {
        echo json_encode(['success' => false, 'message' => 'Only the CEO or admin can rate an OKR.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }
    $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? (float)$_POST['rating'] : null;
    if ($rating !== null && (($rating * 2) != round($rating * 2) || $rating < 0 || $rating > 5)) {
        echo json_encode(['success' => false, 'message' => 'Rating must be between 0 and 5, in half-star steps.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.rating, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if ($card['status_value'] === 'Failed') {
        echo json_encode(['success' => false, 'message' => 'A Failed OKR is already resolved and cannot be rated.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $rating_sql = $rating !== null ? $rating : 'NULL';
    $rated_by_sql = $rating !== null ? $requester_id : 'NULL';
    $rated_at_sql = $rating !== null ? "'$now'" : 'NULL';
    $update = "UPDATE okr_cards SET rating = $rating_sql, rated_by = $rated_by_sql, rated_at = $rated_at_sql WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        $old_rating = $card['rating'] !== null ? (float)$card['rating'] : null;
        okrLogAudit($conn, $id, $requester_id, 'rated',
            ['rating' => [$old_rating, $rating]],
            $rating !== null ? ('Rated ' . $rating . ' / 5.') : 'Rating cleared.');

        $rater_name = null;
        if ($rating !== null) {
            $rater_res = mysqli_query($conn, "SELECT nama_staff FROM staff WHERE id = $requester_id");
            if ($rater_res && ($rater_row = mysqli_fetch_assoc($rater_res))) {
                $rater_name = $rater_row['nama_staff'];
            }
        }

        echo json_encode([
            'success'   => true,
            'rating'    => $rating,
            'rated_by_name' => $rater_name,
            'rated_at'  => $rating !== null ? $now : null,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Chat Box: a per-card discussion thread, modeled after ATEM's Chat Box.
// Visible to everyone who can view the card (same scope as the card itself);
// posting is narrower - issuer, admin, or one of the card's owner(s).
if ($action === 'listChatMessages' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }
    $scope_where = okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $requester_is_admin);
    $check = mysqli_query($conn, "SELECT id FROM okr_cards c WHERE c.id = $id AND c.deleted_at IS NULL AND ($scope_where)");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found or not accessible.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => okrFetchChatMessages($conn, $id)]);
    exit;
}

if ($action === 'sendChatMessage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($id <= 0 || $message === '') {
        echo json_encode(['success' => false, 'message' => 'A message is required.']);
        exit;
    }

    $check = mysqli_query($conn, okrCardSelectSql("c.id = $id AND c.deleted_at IS NULL"));
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = okrFormatCard(mysqli_fetch_assoc($check));
    if (!okrCanPostChat($card, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner(s), or admin can post here.']);
        exit;
    }

    $message_e = mysqli_real_escape_string($conn, $message);
    $insert = "INSERT INTO okr_chat_messages (card_id, sender_staff_id, message) VALUES ($id, $requester_id, '$message_e')";
    if (mysqli_query($conn, $insert)) {
        $new_id = mysqli_insert_id($conn);

        // "Octopus notification" for the issuer - scoped to chat only for
        // now. Skip when the issuer is the one sending (no point notifying
        // yourself).
        if ($card['issuer_staff_id'] !== $requester_id) {
            okrNotifyChat($conn, $id, $card['issuer_staff_id']);
        }

        echo json_encode([
            'success'         => true,
            'id'              => $new_id,
            'sender_staff_id' => $requester_id,
            'sender_name'     => $requester_name,
            'message'         => $message,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'editChatMessage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($id <= 0 || $message === '') {
        echo json_encode(['success' => false, 'message' => 'A message is required.']);
        exit;
    }

    $editable = okrChatMessageEditable($conn, $id, $requester_id);
    if (!$editable['ok']) {
        echo json_encode(['success' => false, 'message' => $editable['message']]);
        exit;
    }

    $message_e = mysqli_real_escape_string($conn, $message);
    if (mysqli_query($conn, "UPDATE okr_chat_messages SET message = '$message_e' WHERE id = $id")) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

if ($action === 'unsendChatMessage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid message.']);
        exit;
    }

    $editable = okrChatMessageEditable($conn, $id, $requester_id);
    if (!$editable['ok']) {
        echo json_encode(['success' => false, 'message' => $editable['message']]);
        exit;
    }

    if (mysqli_query($conn, "UPDATE okr_chat_messages SET deleted_at = NOW() WHERE id = $id")) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Octopus notification bell - scoped to chat messages only, all actions
// scoped to $requester_id (a staff member only ever sees/marks their own).
if ($action === 'listNotifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success'      => true,
        'data'         => okrFetchNotifications($conn, $requester_id),
        'unread_count' => okrUnreadNotificationCount($conn, $requester_id),
    ]);
    exit;
}

if ($action === 'markNotificationRead' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        okrMarkNotificationRead($conn, $id, $requester_id);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'markAllNotificationsRead' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    okrMarkAllNotificationsRead($conn, $requester_id);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
