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

    $query = "SELECT c.id, os.value AS result_status, c.force_terminated, c.is_suspended,
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
            // A force-terminated card (either shape - see okrIsForceTerminated)
            // is excluded from the plain "fail" tally below and counted only
            // in its own bucket, so it doesn't double up against ordinary
            // Failed cards.
            $is_force_terminated = okrIsForceTerminated($status, $row['force_terminated']);
            // is_suspended is independent of result_status now (see
            // suspendCard) - a suspended card keeps showing whatever status
            // it already had, so this is checked separately from $status
            // throughout, not folded into the status-based buckets above.
            $is_suspended = !empty($row['is_suspended']);

            $by_dept[$dept_id]['cards']++;
            if ($is_complete) { $by_dept[$dept_id]['complete']++; }
            if ($status === 'Completed with Excellence') { $by_dept[$dept_id]['excellence']++; }
            if ($status === 'Failed' && !$is_force_terminated) { $by_dept[$dept_id]['fail']++; }
            if ($is_suspended) { $by_dept[$dept_id]['suspended']++; }
            if ($is_force_terminated) { $by_dept[$dept_id]['force_terminated']++; }

            // Top offenders for the Suspended & Force Terminated ranking -
            // keyed by issuer, only tallying the two buckets that matter
            // there so a staff member with no suspensions never shows up.
            $issuer_id = (int)$row['issuer_staff_id'];
            if ($is_suspended || $is_force_terminated) {
                if (!isset($by_staff[$issuer_id])) {
                    $by_staff[$issuer_id] = [
                        'staff_id' => $issuer_id,
                        'staff_name' => $row['issuer_name'] ?: ('Staff #' . $issuer_id),
                        'suspended' => 0, 'force_terminated' => 0,
                    ];
                }
                if ($is_suspended) { $by_staff[$issuer_id]['suspended']++; }
                if ($is_force_terminated) { $by_staff[$issuer_id]['force_terminated']++; }
            }
        }
    }

    // Every offender by (suspended + force_terminated) count, most first -
    // sorted server-side so index.js just renders the array as-is. Not
    // capped (the list can grow large) - index.php scrolls the table body
    // instead of truncating it.
    $by_staff_suspend = array_values($by_staff);
    usort($by_staff_suspend, function ($a, $b) {
        return ($b['suspended'] + $b['force_terminated']) - ($a['suspended'] + $a['force_terminated']);
    });

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
    okrClearDraftSession($conn);
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
    if ($owner_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'An owner is required.']);
        exit;
    }
    if ($owner2_id > 0 && $owner2_id === $owner_id) {
        echo json_encode(['success' => false, 'message' => 'Owner and 2nd Owner must be different people.']);
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

    // create.php eagerly creates a placeholder Draft row the moment it's
    // opened (okrEnsureDraftCard, see lib.php) so the in-progress OKR has a
    // stable id before the user saves - e.g. for the Link ATEM modal's
    // "Create New ATEM" pane to reference back to. If that row still exists,
    // is still Draft, and still belongs to this requester, reuse it (UPDATE)
    // instead of inserting a second row, so links already pointing at its id
    // stay valid. Falls back to a real INSERT if it doesn't (session lost,
    // row deleted, etc.) - same as this action's original, only path before.
    $draft_id = !empty($_SESSION['okr_draft_card_id']) ? (int)$_SESSION['okr_draft_card_id'] : 0;
    if ($draft_id > 0) {
        $draft_check = mysqli_query($conn, "SELECT c.id FROM okr_cards c
                                             JOIN okr_statuses s ON c.result_status = s.id
                                             WHERE c.id = $draft_id AND c.issuer_staff_id = $requester_id
                                             AND s.value = '" . OKR_STATUS_DRAFT . "' AND c.deleted_at IS NULL");
        if (!$draft_check || mysqli_num_rows($draft_check) === 0) {
            $draft_id = 0;
        }
    }

    // difficulty_level is a NOT NULL column with a foreign key into okr_levels
    // (no default) - the incentive system that column belonged to is retired,
    // but the schema itself is untouched, so every insert must still supply a
    // valid level. Level 1 always exists (it's the RM0 "no incentive" level
    // from the old system) and is otherwise unused now. okr_type is likewise
    // NOT NULL with no default - the OKR Type feature was removed from the
    // UI, so every insert hardcodes 'Committed' (same pattern as
    // difficulty_level = 1). key_results is also NOT NULL with no default -
    // that field was removed from the UI (slated for replacement), so every
    // insert supplies an empty string.
    if ($draft_id > 0) {
        $write = "UPDATE okr_cards SET
            objective = '$objective_e',
            owner_staff_id = $owner_id, owner2_staff_id = $owner2_sql, owner2_purpose = $owner2_purpose_sql,
            dept_scope = '$dept_scope_safe', start_date = '$start_date', end_date = '$end_date',
            result_status = $status_id
            WHERE id = $draft_id";
    } else {
        $write = "INSERT INTO okr_cards
            (objective, key_results, okr_type, difficulty_level,
             owner_staff_id, owner2_staff_id, owner2_purpose,
             issuer_staff_id, dept_scope, start_date, end_date, result_status)
            VALUES ('$objective_e', '', 'Committed', 1,
                    $owner_id, $owner2_sql, $owner2_purpose_sql,
                    $requester_id, '$dept_scope_safe', '$start_date', '$end_date', $status_id)";
    }

    if (mysqli_query($conn, $write)) {
        $new_id = $draft_id > 0 ? $draft_id : mysqli_insert_id($conn);
        okrFinalizeStagedAttachments($conn, $new_id, $requester_id);
        okrFinalizeStagedReferenceLinks($conn, $new_id, $requester_id);
        okrFinalizeStagedKeyResults($conn, $new_id, $requester_id);
        unset($_SESSION['okr_draft_state']);
        unset($_SESSION['okr_draft_card_id']);
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
// yet). Subtasks can also be staged against its token below - see
// stageKeyResultSubtask.
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

// Links an existing or newly-created ATEM card against a still-staged
// top-level Key Result. atem_id is a bare int reference only - the frontend
// picks/creates the ATEM by calling atem/api.php directly (same session,
// same origin), then calls this action with the resulting id.
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

// Adds a reference link directly onto an already-saved card (used from view.php).
if ($action === 'addReferenceLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $url  = trim($_POST['url'] ?? '');
    if ($id <= 0 || $name === '' || $url === '') {
        echo json_encode(['success' => false, 'message' => 'Both name and URL are required.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!okrCanCollaborateOnCard($card, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can add reference links.']);
        exit;
    }
    if (!empty($card['is_suspended']) || $card['status_value'] === 'Failed' || $card['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
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

    $query = "SELECT rl.card_id, rl.name, c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id,
                     c.is_suspended, os.value AS status_value
              FROM okr_reference_links rl
              JOIN okr_cards c ON rl.card_id = c.id
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              WHERE rl.id = $link_id AND c.deleted_at IS NULL";
    $result = mysqli_query($conn, $query);
    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Reference link not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($result);
    if (!okrCanCollaborateOnCard($row, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can remove reference links.']);
        exit;
    }
    if (!empty($row['is_suspended']) || $row['status_value'] === 'Failed' || $row['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
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

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $card_id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!okrCanCollaborateOnCard($card, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can add Key Results.']);
        exit;
    }
    if (!empty($card['is_suspended']) || $card['status_value'] === 'Failed' || $card['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
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

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, kr.atem_id, kr.description, kr.start_date, kr.end_date,
                                          c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    // Owner/Owner2 gets the same full Key Result Progress access as
    // issuer/admin (see okrCanCollaborateOnCard) - matches the parity
    // already given for Attachments/Reference Links/create-delete above.
    if (!okrCanCollaborateOnCard($kr, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can update this Key Result.']);
        exit;
    }
    if (!empty($kr['is_suspended']) || $kr['status_value'] === 'Failed' || $kr['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
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

    // A top-level Key Result can't be marked Completed while one of its own
    // Subtasks is still Active - the subtask should be resolved first.
    if ($kr['parent_id'] === null && in_array($status_value, ['Completed', 'Completed with Excellence'], true)) {
        $active_sub = mysqli_query($conn, "SELECT kr2.id FROM okr_key_results kr2
                                            JOIN okr_statuses os2 ON kr2.status_id = os2.id
                                            WHERE kr2.parent_id = $id AND os2.value = 'Active' LIMIT 1");
        if ($active_sub && mysqli_num_rows($active_sub) > 0) {
            echo json_encode(['success' => false, 'message' => 'This Key Result cannot be marked ' . $status_value . ' while a Subtask is still Active.']);
            exit;
        }
    }

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
            'atem_id'      => $kr['atem_id'] !== null ? (int)$kr['atem_id'] : null,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Called from atem's own edit page (browser-side, same PHP session) after it
// saves a status change on an ATEM linked back to a Key Result/Subtask via
// atem_id. Mirrors the reverse direction of the js/edit.js sync in this
// module - kept a strict mirror of updateKeyResult's own gates (issuer-only,
// locked-card guard, allowed-status whitelist, subtask-active-blocks-parent-
// completion) since this ultimately performs the same status write.
if ($action === 'syncKeyResultStatusFromAtem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $atem_id = (int)($_POST['atem_id'] ?? 0);
    $status_value = trim($_POST['status_value'] ?? '');
    // Trusted only as far as it lets us verify the caller IS that issuer -
    // the actual privilege check is still "is $requester_id the OKR card's
    // issuer", independently re-verified below from this module's own DB.
    $atem_issuer_staff_id = (int)($_POST['atem_issuer_staff_id'] ?? 0);

    if ($atem_id <= 0 || $status_value === '' || $atem_issuer_staff_id !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $allowed_statuses = okrKeyResultAssignableStatuses($conn);
    $allowed_by_value = array_column($allowed_statuses, 'id', 'value');
    if (!isset($allowed_by_value[$status_value])) {
        // Not one of the 4 Key Result statuses (e.g. ATEM-only statuses like
        // Extended/Suspended/Draft/Completed with Extension) - nothing to sync.
        echo json_encode(['success' => true, 'updated' => 0]);
        exit;
    }
    $status_id = $allowed_by_value[$status_value];

    $rows_res = mysqli_query($conn, "SELECT kr.id, kr.card_id, kr.parent_id, c.issuer_staff_id, c.is_suspended AS card_is_suspended, os.value AS card_status_value
                                      FROM okr_key_results kr
                                      JOIN okr_cards c ON kr.card_id = c.id
                                      LEFT JOIN okr_statuses os ON c.result_status = os.id
                                      WHERE kr.atem_id = $atem_id AND c.deleted_at IS NULL");
    $updated = 0;
    if ($rows_res) {
        while ($row = mysqli_fetch_assoc($rows_res)) {
            // Literal issuer only - no admin bypass, per this feature's own rule.
            if ((int)$row['issuer_staff_id'] !== $requester_id) { continue; }
            if (!empty($row['card_is_suspended']) || $row['card_status_value'] === 'Failed' || $row['card_status_value'] === OKR_STATUS_FORCE_TERMINATED) { continue; }

            if ($row['parent_id'] === null && in_array($status_value, ['Completed', 'Completed with Excellence'], true)) {
                $active_sub = mysqli_query($conn, "SELECT kr2.id FROM okr_key_results kr2
                                                    JOIN okr_statuses os2 ON kr2.status_id = os2.id
                                                    WHERE kr2.parent_id = " . (int)$row['id'] . " AND os2.value = 'Active' LIMIT 1");
                if ($active_sub && mysqli_num_rows($active_sub) > 0) { continue; }
            }

            if (mysqli_query($conn, "UPDATE okr_key_results SET status_id = $status_id WHERE id = " . (int)$row['id'])) {
                $updated++;
                okrLogAudit($conn, $row['card_id'], $requester_id,
                    $row['parent_id'] !== null ? 'subtask_updated' : 'key_result_updated', null,
                    'Status synced from linked ATEM: ' . $status_value);
            }
        }
    }
    echo json_encode(['success' => true, 'updated' => $updated]);
    exit;
}

// Deleting a Key Result cascades to its Subtasks (ON DELETE CASCADE).
if ($action === 'deleteKeyResult' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, kr.parent_id, kr.description, c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!okrCanCollaborateOnCard($kr, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can remove Key Results.']);
        exit;
    }
    if (!empty($kr['is_suspended']) || $kr['status_value'] === 'Failed' || $kr['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
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

// Links a real (already-saved) Key Result or Subtask to an ATEM card. Same
// "plain reference, no FK" rule as the staged okrSetStagedKeyResultAtem used
// by create.php - atem_id is a bare int, resolved client-side against
// atem/api.php, not joined here. Same edit gate as updateKeyResult: issuer or
// admin, and not Suspended/Failed.
if ($action === 'linkKeyResultAtem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $atem_id = (int)($_POST['atem_id'] ?? 0);
    if ($id <= 0 || $atem_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Key Result or ATEM.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, c.issuer_staff_id, c.is_suspended, os.value AS status_value
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
        echo json_encode(['success' => false, 'message' => 'Only the issuer can link ATEM.']);
        exit;
    }
    if (!empty($kr['is_suspended']) || $kr['status_value'] === 'Failed' || $kr['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    if (mysqli_query($conn, "UPDATE okr_key_results SET atem_id = $atem_id WHERE id = $id")) {
        echo json_encode(['success' => true, 'atem_id' => $atem_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Persists a drag-to-reorder of Subtasks under one Key Result (modeled after
// iidas's project_detail.js drag-and-drop, which POSTs a full {id: order}
// map after every drop rather than a single moved-item delta). Every id in
// the payload must actually be a subtask of $parent_id on this card - a
// mismatched id is silently skipped rather than trusted, since the client
// only sends what's currently rendered but the request is still
// user-suppliable.
if ($action === 'reorderKeyResultSubtasks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    $orders_json = $_POST['orders'] ?? '';
    $orders = json_decode($orders_json, true);
    if ($parent_id <= 0 || !is_array($orders) || empty($orders)) {
        echo json_encode(['success' => false, 'message' => 'Invalid reorder request.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT kr.card_id, c.issuer_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_key_results kr
                                   JOIN okr_cards c ON kr.card_id = c.id
                                   LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE kr.id = $parent_id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Key Result not found.']);
        exit;
    }
    $kr = mysqli_fetch_assoc($check);
    if (!$requester_is_admin && (int)$kr['issuer_staff_id'] !== $requester_id) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer can reorder subtasks.']);
        exit;
    }
    if (!empty($kr['is_suspended']) || $kr['status_value'] === 'Failed' || $kr['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM okr_key_results LIKE 'sort_order'");
    if (!$col_check || mysqli_num_rows($col_check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Reordering is not available yet - run sql/add_okr_key_results_sort_order.sql first.']);
        exit;
    }

    $siblings = mysqli_query($conn, "SELECT id FROM okr_key_results WHERE parent_id = $parent_id");
    $sibling_ids = [];
    if ($siblings) {
        while ($row = mysqli_fetch_assoc($siblings)) { $sibling_ids[] = (int)$row['id']; }
    }

    foreach ($orders as $sub_id => $order) {
        $sub_id = (int)$sub_id;
        $order  = (int)$order;
        if (!in_array($sub_id, $sibling_ids, true)) { continue; }
        mysqli_query($conn, "UPDATE okr_key_results SET sort_order = $order WHERE id = $sub_id");
    }
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

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id, c.is_suspended, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!okrCanCollaborateOnCard($card, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can add attachments.']);
        exit;
    }
    if (!empty($card['is_suspended']) || $card['status_value'] === 'Failed' || $card['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
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

    $query = "SELECT a.card_id, a.stored_name, a.original_name, c.issuer_staff_id, c.owner_staff_id, c.owner2_staff_id,
                     c.is_suspended, os.value AS status_value
              FROM okr_card_attachments a
              JOIN okr_cards c ON a.card_id = c.id
              LEFT JOIN okr_statuses os ON c.result_status = os.id
              WHERE a.id = $attachment_id AND c.deleted_at IS NULL";
    $result = mysqli_query($conn, $query);
    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Attachment not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($result);
    if (!okrCanCollaborateOnCard($row, $requester_id, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'Only the issuer, owner, or admin can remove attachments.']);
        exit;
    }
    if (!empty($row['is_suspended']) || $row['status_value'] === 'Failed' || $row['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
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

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.objective, c.is_suspended,
                                          c.owner_staff_id, c.owner2_staff_id, c.owner2_purpose, c.dept_scope,
                                          c.start_date, c.end_date, c.extended, c.extended_date, c.remarks, c.closed_at,
                                          os.value AS status_value
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
    if (!empty($card['is_suspended']) || $card['status_value'] === 'Failed' || $card['status_value'] === OKR_STATUS_FORCE_TERMINATED) {
        echo json_encode(['success' => false, 'message' => 'This OKR is locked and can no longer be edited.']);
        exit;
    }

    $objective   = trim($_POST['objective'] ?? '');
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
    // Once an OKR has been extended, it can no longer go back to Draft/Active/
    // Extended or be marked Completed with Excellence — every subsequent edit
    // must actually resolve it as Completed (stored as Completed with
    // Extension) or Failed. Admin is exempt and may set any assignable
    // status regardless (part of admin's full override authority).
    if (!$requester_is_admin && (bool)$card['extended'] && !in_array($status, okrPostExtensionResolvableStatuses(), true)) {
        echo json_encode(['success' => false, 'message' => 'This OKR has been extended, so it can now only resolve as Completed with Extension or Failed.']);
        exit;
    }
    // Extension is once-only and cannot be undone for a normal issuer: once
    // set, the flag and its date are locked to whatever was already saved.
    // Admin may still overwrite the flag/date (full override authority) -
    // the posted values are used as-is instead of being forced back to the
    // card's saved values.
    if ((bool)$card['extended'] && !$requester_is_admin) {
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
    // Closure Date is a real, independently-editable completion marker now
    // (not purely a side-effect of the open/closed transition above) - the
    // Issuer, CEO, or admin may set/adjust it afterward, within Start Date..
    // today, as long as the resulting status isn't Draft/Active/Failed/
    // Suspended (see okrCanEditClosureDate()/okrClosureDateLockedStatuses()
    // in lib.php). Overrides the automatic $closed_sql above when supplied.
    $closure_date_posted = trim($_POST['closure_date'] ?? '');
    if ($closure_date_posted !== '' && okrCanEditClosureDate($final_status, $card['issuer_staff_id'], false, $requester_id, $requester_grade, $requester_is_admin)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $closure_date_posted)
            || $closure_date_posted < $start_date || $closure_date_posted > date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'Closure Date must be between Start Date and today.']);
            exit;
        }
        $closure_date_e = mysqli_real_escape_string($conn, $closure_date_posted);
        $closed_sql = ", closed_by = $requester_id, closed_at = '$closure_date_e'";
    }
    // Locking is a separate action from completing (handled elsewhere) — a
    // Complete status alone does not lock the card from further edits.
    $update = "UPDATE okr_cards SET
        objective = '$objective_e',
        owner_staff_id = $owner_id, owner2_staff_id = $owner2_sql, owner2_purpose = $owner2_purpose_sql,
        dept_scope = '$dept_scope_safe', start_date = '$start_date', end_date = '$end_date',
        extended = $extended_sql, extended_date = $extended_date_sql, remarks = '$remarks_e',
        result_status = $status_id$closed_sql
        WHERE id = $id";

    if (mysqli_query($conn, $update)) {
        $changes = [];
        if ($card['objective'] !== $objective) { $changes['objective'] = [$card['objective'], $objective]; }
        if ((int)$card['owner_staff_id'] !== $owner_id) { $changes['owner_staff_id'] = [(int)$card['owner_staff_id'], $owner_id]; }
        if ((int)$card['owner2_staff_id'] !== $owner2_id) { $changes['owner2_staff_id'] = [(int)$card['owner2_staff_id'], $owner2_id]; }
        if ((string)$card['owner2_purpose'] !== $owner2_purpose) { $changes['owner2_purpose'] = [$card['owner2_purpose'], $owner2_purpose]; }
        if ($card['dept_scope'] !== $dept_scope_safe) { $changes['dept_scope'] = [$card['dept_scope'], $dept_scope_safe]; }
        if ($card['start_date'] !== $start_date) { $changes['start_date'] = [$card['start_date'], $start_date]; }
        if ($card['end_date'] !== $end_date) { $changes['end_date'] = [$card['end_date'], $end_date]; }
        if ($closure_date_posted !== '' && substr((string)$card['closed_at'], 0, 10) !== $closure_date_posted) {
            $changes['closure_date'] = [$card['closed_at'] ? substr($card['closed_at'], 0, 10) : null, $closure_date_posted];
        }
        if ($card['status_value'] !== $final_status) { $changes['result_status'] = [$card['status_value'], $final_status]; }
        if ((bool)$card['extended'] !== $extended) { $changes['extended'] = [(bool)$card['extended'], $extended]; }
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

    $check = mysqli_query($conn, "SELECT c.objective, c.issuer_staff_id, c.is_suspended, os.value AS status_value,
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
    // The CEO can suspend an OKR in any status except Draft - a Draft isn't
    // a real, issued OKR yet, so there's nothing to suspend.
    if ($card['status_value'] === OKR_STATUS_DRAFT) {
        echo json_encode(['success' => false, 'message' => 'A Draft OKR cannot be suspended.']);
        exit;
    }
    if (!empty($card['is_suspended'])) {
        echo json_encode(['success' => false, 'message' => 'This OKR is already suspended.']);
        exit;
    }

    // An OKR can only ever be suspended once in its lifetime - once
    // unsuspended it cannot be suspended again. Checked against the audit
    // log (never cleared) rather than is_suspended alone (which
    // unsuspendCard clears), so a previous suspend cycle is still detected
    // after the card is reopened.
    $already_suspended_check = mysqli_query($conn, "SELECT 1 FROM okr_audit_logs
                                                      WHERE card_id = $id AND event = 'suspended' LIMIT 1");
    if ($already_suspended_check && mysqli_num_rows($already_suspended_check) > 0) {
        echo json_encode(['success' => false, 'message' => 'This OKR has already been suspended once and cannot be suspended again.']);
        exit;
    }

    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'A reason is required to suspend an OKR.']);
        exit;
    }

    // Suspend no longer touches result_status - the card keeps showing
    // whatever status it already had (see okrFormatCard's is_suspended
    // comment in lib.php). Closure Date is "written off" (cleared) rather
    // than stamped, since a suspend is a pause, not a completion.
    $reason_e = mysqli_real_escape_string($conn, $reason);
    $update = "UPDATE okr_cards SET is_suspended = 1, suspended_by = $requester_id, suspended_at = NOW(),
               closed_by = NULL, closed_at = NULL,
               remarks = '$reason_e', appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'suspended',
            ['is_suspended' => [false, true]], 'Suspended by CEO: ' . $reason);

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

    $check = mysqli_query($conn, "SELECT c.is_suspended
                                   FROM okr_cards c
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (empty($card['is_suspended'])) {
        echo json_encode(['success' => false, 'message' => 'This OKR is not suspended.']);
        exit;
    }

    // Unsuspend just clears the flag - result_status was never touched by
    // suspendCard, so there's nothing to restore. Closure Date stays cleared
    // (it was written off on suspend, not carried over).
    $update = "UPDATE okr_cards SET is_suspended = 0, suspended_by = NULL, suspended_at = NULL,
               appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'unsuspended',
            ['is_suspended' => [true, false]], 'Unsuspended by CEO.');
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

    $check = mysqli_query($conn, "SELECT c.objective, c.issuer_staff_id, c.appealed_at, c.is_suspended,
                                          s.nama_staff AS issuer_name
                                   FROM okr_cards c
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
    if (empty($card['is_suspended'])) {
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

    $check = mysqli_query($conn, "SELECT c.is_suspended, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    // Reachable while currently suspended (the normal Suspend > Appeal >
    // Force Terminate flow), or once the OKR has already used up its one
    // lifetime Suspend and been reopened - since it can never be suspended
    // again (see suspendCard above), Force Terminate becomes the CEO's only
    // remaining action against it.
    if (!empty($card['is_suspended'])) {
        // ok - normal suspended-card force terminate
    } else {
        // Once reopened after its one lifetime Suspend, Force Terminate
        // follows the same "only against a Completed OKR" rule as
        // Suspend/Rate - not available while still Draft/Active/Extended.
        $already_suspended_check = mysqli_query($conn, "SELECT 1 FROM okr_audit_logs
                                                          WHERE card_id = $id AND event = 'suspended' LIMIT 1");
        $already_suspended = $already_suspended_check && mysqli_num_rows($already_suspended_check) > 0;
        if (!$already_suspended || !in_array($card['status_value'], okrCompletedStatusValues(), true)) {
            echo json_encode(['success' => false, 'message' => 'Only a suspended OKR (or a Completed OKR that has already used its one Suspend) can be force terminated.']);
            exit;
        }
    }

    // Force Terminated is its own status (OKR_STATUS_FORCE_TERMINATED) -
    // still stamps the legacy force_terminated boolean column alongside it
    // for continuity with cards force-terminated before this status existed
    // (those are stored as plain Failed + the flag; dashboardStats and the
    // "is this force-terminated" checks elsewhere treat either shape as
    // force-terminated - see okrIsForceTerminated() usage below and in
    // dashboardStats above). Also resolves the suspension itself (if any) -
    // is_suspended/suspended_by/suspended_at are cleared, since the card is
    // no longer "currently suspended", it's now a resolved, terminal outcome.
    $status_id = okrStatusIdByValue($conn, OKR_STATUS_FORCE_TERMINATED);
    $remark_e  = mysqli_real_escape_string($conn, $remark);
    $update = "UPDATE okr_cards SET result_status = $status_id, force_terminated = 1,
               is_suspended = 0, suspended_by = NULL, suspended_at = NULL,
               closed_by = $requester_id, closed_at = NOW(),
               remarks = '$remark_e', appeal_justification = NULL, appealed_at = NULL WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        okrLogAudit($conn, $id, $requester_id, 'force_terminated',
            ['result_status' => [$card['status_value'], OKR_STATUS_FORCE_TERMINATED]], 'Force terminated by CEO: ' . $remark);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit;
}

// Standalone Closure Date edit, callable directly from view.php (which
// otherwise has no write path for a card's other fields - this avoids
// routing through updateCard's full field set just to touch one date).
// Same permission/range rule as the Closure Date field on the edit form -
// see okrCanEditClosureDate()/okrClosureDateLockedStatuses() in lib.php.
if ($action === 'updateClosureDate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $closure_date = trim($_POST['closure_date'] ?? '');
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid card.']);
        exit;
    }

    $check = mysqli_query($conn, "SELECT c.issuer_staff_id, c.start_date, c.closed_at, os.value AS status_value
                                   FROM okr_cards c LEFT JOIN okr_statuses os ON c.result_status = os.id
                                   WHERE c.id = $id AND c.deleted_at IS NULL");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        exit;
    }
    $card = mysqli_fetch_assoc($check);
    if (!okrCanEditClosureDate($card['status_value'], $card['issuer_staff_id'], false, $requester_id, $requester_grade, $requester_is_admin)) {
        echo json_encode(['success' => false, 'message' => 'You are not allowed to set the Closure Date on this OKR.']);
        exit;
    }
    if ($closure_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closure_date)
        || $closure_date < $card['start_date'] || $closure_date > date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Closure Date must be between Start Date and today.']);
        exit;
    }

    $closure_date_e = mysqli_real_escape_string($conn, $closure_date);
    $update = "UPDATE okr_cards SET closed_by = $requester_id, closed_at = '$closure_date_e' WHERE id = $id";
    if (mysqli_query($conn, $update)) {
        $old_closure = $card['closed_at'] ? substr($card['closed_at'], 0, 10) : null;
        okrLogAudit($conn, $id, $requester_id, 'updated',
            ['closure_date' => [$old_closure, $closure_date]], 'Closure Date set to ' . $closure_date . '.');
        echo json_encode(['success' => true, 'closure_date' => $closure_date]);
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
    if (!in_array($card['status_value'], okrCompletedStatusValues(), true)) {
        echo json_encode(['success' => false, 'message' => 'Only a Completed OKR can be rated.']);
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
