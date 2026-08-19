<?php
/**
 * Shared OKR helpers: card query builders, formatting, and grade-based scope.
 * Included by backend.php (AJAX) and the server-rendered pages (index.php, view.php).
 * Requires $conn (mysqli) to already be set up by the includer.
 */

// Canonical status value strings. okr_statuses.value is admin-editable (see
// admin lookup tables), so the module must not hardcode status names except
// where the identity of a *specific* status drives business logic that isn't
// expressible as a table scan (see okrFetchStatuses()/okrTimelineAssignableStatuses()
// below for the DB-driven alternative used everywhere else). These six are
// that irreducible set:
// - ACTIVE/DRAFT: the two default statuses createCard resolves to.
// - SUSPENDED: only ever set via the dedicated CEO Suspend/Unsuspend actions,
//   never directly assignable on the Timeline card.
// - COMPLETED / COMPLETED_EXTENSION: an OKR resolved as Completed while
//   already extended is stored as the more specific Completed with Extension
//   status instead (see updateCard in backend.php) - a business rule, not a
//   fact read off the table.
// - FORCE_TERMINATED: only ever set via the dedicated CEO Force Terminate
//   action (backend.php's forceTerminateCard), never directly assignable on
//   the Timeline card - same pattern as Suspended. Distinct from plain
//   Failed (an OKR that ran its course and wasn't delivered) - Force
//   Terminate is the CEO cutting an OKR short. okr_cards.force_terminated
//   (a legacy boolean column) still exists and is still written alongside
//   this status for continuity with cards force-terminated before this
//   status existed (which are stored as plain Failed + the flag) - see
//   forceTerminateCard's comment in backend.php.
define('OKR_STATUS_ACTIVE', 'Active');
define('OKR_STATUS_DRAFT', 'Draft');
define('OKR_STATUS_COMPLETED', 'Completed');
define('OKR_STATUS_SUSPENDED', 'Suspended');
define('OKR_STATUS_COMPLETED_EXTENSION', 'Completed with Extension');
define('OKR_STATUS_FORCE_TERMINATED', 'Force Terminated');

require_once __DIR__ . '/nas_config.php';

// Attachments only ever touch local disk transiently (uploads/tmp/) on their
// way to the NAS (CURLFile needs a real filesystem path); the permanent copy
// lives on the NAS under CORP_NAS_FOLDER, not in uploads/.
$OKR_UPLOAD_TMP_DIR  = __DIR__ . '/uploads/tmp/';
$OKR_ALLOWED_EXT     = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
$OKR_MAX_FILE_SIZE   = 10 * 1024 * 1024;

function okrCardSelectSql($where, $include_deleted = false) {
    $deleted_clause = $include_deleted ? '' : 'c.deleted_at IS NULL AND ';
    return "SELECT c.*, ow.nama_staff AS owner_name, ow.department AS owner_department,
                   ow2.nama_staff AS owner2_name, ow2.department AS owner2_department,
                   iss.nama_staff AS issuer_name, iss.department AS issuer_department,
                   os.value AS status_value, rb.nama_staff AS rated_by_name,
                   cb.nama_staff AS closed_by_name, sb.nama_staff AS suspended_by_name
            FROM okr_cards c
            LEFT JOIN staff ow  ON c.owner_staff_id  = ow.id
            LEFT JOIN staff ow2 ON c.owner2_staff_id = ow2.id
            LEFT JOIN staff iss ON c.issuer_staff_id = iss.id
            LEFT JOIN staff rb  ON c.rated_by = rb.id
            LEFT JOIN staff cb  ON c.closed_by = cb.id
            LEFT JOIN staff sb  ON c.suspended_by = sb.id
            LEFT JOIN okr_statuses os ON c.result_status = os.id
            WHERE $deleted_clause$where";
}

function okrFormatCard($row) {
    return [
        'id'                => (int)$row['id'],
        'objective'         => $row['objective'],
        'okr_type'          => $row['okr_type'],
        'owner_staff_id'    => (int)$row['owner_staff_id'],
        'owner_name'        => $row['owner_name'],
        'owner_department'  => $row['owner_department'],
        'owner2_staff_id'   => $row['owner2_staff_id'] !== null ? (int)$row['owner2_staff_id'] : null,
        'owner2_name'       => $row['owner2_name'],
        'owner2_department' => $row['owner2_department'],
        'owner2_purpose'    => $row['owner2_purpose'],
        'issuer_staff_id'   => (int)$row['issuer_staff_id'],
        'issuer_name'       => $row['issuer_name'],
        'issuer_department' => $row['issuer_department'],
        'dept_scope'        => $row['dept_scope'],
        'start_date'        => $row['start_date'],
        'end_date'          => $row['end_date'],
        'extended'          => (bool)$row['extended'],
        'extended_date'     => $row['extended_date'],
        'remarks'           => $row['remarks'],
        'appeal_justification' => $row['appeal_justification'] ?? null,
        'appealed_at'       => $row['appealed_at'] ?? null,
        'force_terminated'  => !empty($row['force_terminated']),
        // Suspend no longer overwrites result_status (see suspendCard in
        // backend.php) - is_suspended/suspended_by/suspended_at are the
        // source of truth for "is this card currently suspended", layered
        // independently on top of whatever status the card already had.
        'is_suspended'      => !empty($row['is_suspended']),
        'suspended_by_name' => $row['suspended_by_name'] ?? null,
        'suspended_at'      => $row['suspended_at'] ?? null,
        'rating'            => $row['rating'] !== null ? (float)$row['rating'] : null,
        'rated_by_name'     => $row['rated_by_name'] ?? null,
        'rated_at'          => $row['rated_at'] ?? null,
        // Final Due Date never mirrors the Extended Date target — it's
        // End Date until the OKR is actually resolved (closed_at set),
        // at which point it follows that closure date.
        'final_due_date'    => (!empty($row['extended']) && $row['closed_at'])
            ? substr($row['closed_at'], 0, 10)
            : $row['end_date'],
        // Closure Date is now an independently user-settable field (see
        // okrCanEditClosureDate() below and backend.php's updateCard) - a
        // completion marker, not just a status-transition side-effect. Still
        // auto-stamped to "today" the moment a card first closes, but stays
        // editable afterward within its own permission/date-range rules.
        // Suspend clears it ("writes off" the closure date) rather than
        // stamping it.
        'closure_date'      => $row['closed_at'] ? substr($row['closed_at'], 0, 10) : null,
        'result_status_id'  => (int)$row['result_status'],
        'result_status'     => $row['status_value'],
        'pill_class'        => okrPillClass($row['status_value']),
        'closed_at'         => $row['closed_at'],
        'closed_by_name'    => $row['closed_by_name'] ?? null,
        'created_at'        => $row['created_at'],
        'deleted_at'        => $row['deleted_at'] ?? null,
    ];
}

// Every status this OKR must NOT be in for a Closure Date to be manually
// editable - a business rule (see the ticket this implements), not derivable
// from the table. Deliberately does not include Suspended: while suspended,
// Closure Date is cleared and locked (see suspendCard), not user-editable.
function okrClosureDateLockedStatuses() {
    return [OKR_STATUS_DRAFT, OKR_STATUS_ACTIVE, 'Failed', OKR_STATUS_SUSPENDED];
}

// True if $requester may manually set/change this card's Closure Date -
// Issuer, CEO (grade 5), or admin, and only once the card has actually left
// Draft/Active (nothing to close yet) and isn't Failed, Suspended, or
// soft-deleted (see okrClosureDateLockedStatuses()). Takes the raw status
// *value* (not a full card array) since callers hold that in differently-
// shaped rows (okrFormatCard()'s 'result_status' vs a raw query's
// 'status_value') - passing the value directly avoids that ambiguity.
function okrCanEditClosureDate($status_value, $issuer_staff_id, $is_deleted, $requester_id, $requester_grade, $requester_is_admin) {
    if ($is_deleted) {
        return false;
    }
    $is_privileged = $requester_is_admin || (int)$requester_grade === 5
        || (int)$issuer_staff_id === (int)$requester_id;
    return $is_privileged && !in_array($status_value, okrClosureDateLockedStatuses(), true);
}

// The single DB-driven source of truth for okr_statuses' shape - every status
// picker/filter/validation should read from this (or okrTimelineAssignableStatuses
// below), not re-declare its own hardcoded status list.
function okrFetchStatuses($conn, $include_recycled = true) {
    $where = $include_recycled ? '' : 'WHERE recycle = 0';
    $statuses = [];
    $result = mysqli_query($conn, "SELECT id, value, description, sort_order, recycle
                                    FROM okr_statuses $where ORDER BY sort_order ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $statuses[] = [
                'id' => (int)$row['id'], 'value' => $row['value'], 'description' => $row['description'],
                'sort_order' => (int)$row['sort_order'], 'recycle' => (int)$row['recycle'],
            ];
        }
    }
    return $statuses;
}

// Statuses settable via the Timeline card's Status field: every non-recycled
// status except the ones that are only ever reached indirectly (Suspended via
// the dedicated CEO action, Force Terminated via the dedicated CEO action,
// Completed with Extension derived from Completed + extended - see
// updateCard) or represented by a flag column instead of ever being written
// to result_status via the Timeline field (Deleted -> okr_cards.deleted_at;
// exists in okr_statuses only for id/value parity with atem_statuses). Reads
// live from okr_statuses so an admin renaming/adding/soft-deleting a status
// is picked up with no code change.
function okrTimelineAssignableStatuses($conn) {
    $values = array_column(okrFetchStatuses($conn, false), 'value');
    return array_values(array_diff($values, [OKR_STATUS_SUSPENDED, OKR_STATUS_COMPLETED_EXTENSION, OKR_STATUS_FORCE_TERMINATED, 'Deleted']));
}

// The only statuses an already-extended OKR can still resolve to on a
// subsequent edit (i.e. $card['extended'] was already true before this edit
// session started - see edit.php's dropdown filtering and updateCard's
// validation) - a business rule, not something derivable from the table, so
// shared here rather than re-declared independently by backend.php and
// edit.php. Deliberately does NOT include 'Extended' itself: once a card has
// already been extended, resaving it with Status left on Extended is no
// longer allowed - every edit from that point on must actually resolve it as
// Completed (stored as Completed with Extension - see updateCard) or Failed.
// Applies to everyone, admins included - unlike some other Timeline
// restrictions, there is no admin bypass here.
function okrPostExtensionResolvableStatuses() {
    return [OKR_STATUS_COMPLETED, 'Failed'];
}

// True if a card is force-terminated, covering both shapes: cards
// force-terminated since OKR_STATUS_FORCE_TERMINATED was introduced (status
// itself is Force Terminated) and older cards force-terminated before then
// (status is plain Failed, distinguished only by the legacy
// okr_cards.force_terminated flag). $status is the card's result_status
// value; $force_terminated_flag is the raw okr_cards.force_terminated column
// value (may be a "0"/"1" string straight from mysqli, hence the loose check).
function okrIsForceTerminated($status, $force_terminated_flag) {
    return $status === OKR_STATUS_FORCE_TERMINATED || !empty($force_terminated_flag);
}

// Every "done" status value that counts as Completed for gating purposes
// (e.g. Suspend/Rate are only available once an OKR has actually resolved
// as Completed in some form) - a business grouping, not derivable from the
// table's own columns, so shared here rather than re-declared per call site.
function okrCompletedStatusValues() {
    return [OKR_STATUS_COMPLETED, 'Completed with Excellence', OKR_STATUS_COMPLETED_EXTENSION];
}

function okrStatusIdByValue($conn, $value) {
    $value_e = mysqli_real_escape_string($conn, $value);
    $result = mysqli_query($conn, "SELECT id FROM okr_statuses WHERE value = '$value_e'");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return (int)$row['id'];
    }
    return 0;
}

// Creates (or reuses) a real Draft-status okr_cards row the moment create.php
// is opened, rather than waiting for the user's own Save - so the in-progress
// OKR has a stable id immediately (e.g. so the Link ATEM modal's "Create New
// ATEM" pane can add a reference link back to it before the OKR itself has
// been filled in). Every NOT NULL column with no sensible default gets a
// placeholder (issuer doubles as owner, today for both dates, a hardcoded
// okr_type - see "Retired incentive columns"-style handling in CLAUDE.md,
// same pattern as difficulty_level = 1) - backend.php's createCard overwrites
// all of these with the user's real values once they actually save, reusing
// this same row (UPDATE) rather than inserting a second one.
function okrEnsureDraftCard($conn, $requester_id, $requester_dept_csv) {
    if (!empty($_SESSION['okr_draft_card_id'])) {
        $existing_id = (int)$_SESSION['okr_draft_card_id'];
        $check = mysqli_query($conn, "SELECT c.id FROM okr_cards c
                                       JOIN okr_statuses s ON c.result_status = s.id
                                       WHERE c.id = $existing_id AND c.issuer_staff_id = " . (int)$requester_id . "
                                       AND s.value = '" . OKR_STATUS_DRAFT . "' AND c.deleted_at IS NULL");
        if ($check && mysqli_num_rows($check) > 0) {
            return $existing_id;
        }
        unset($_SESSION['okr_draft_card_id']);
    }

    $status_id = okrStatusIdByValue($conn, OKR_STATUS_DRAFT);
    if ($status_id <= 0) {
        return 0;
    }
    $type_e = 'Committed';
    $today = date('Y-m-d');
    $dept_scope_safe = implode(',', okrDeptIdsFromCsv($requester_dept_csv));

    $insert = "INSERT INTO okr_cards
        (objective, key_results, okr_type, difficulty_level,
         owner_staff_id, issuer_staff_id, dept_scope, start_date, end_date, result_status)
        VALUES ('', '', '$type_e', 1,
                " . (int)$requester_id . ", " . (int)$requester_id . ", '$dept_scope_safe', '$today', '$today', $status_id)";
    if (!mysqli_query($conn, $insert)) {
        return 0;
    }
    $new_id = mysqli_insert_id($conn);
    $_SESSION['okr_draft_card_id'] = $new_id;
    return $new_id;
}

function okrFetchReferenceLinks($conn, $card_id) {
    $links = [];
    $result = mysqli_query($conn, "SELECT id, name, url FROM okr_reference_links
                                    WHERE card_id = " . (int)$card_id . " ORDER BY created_at ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $links[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'url' => $row['url']];
        }
    }
    return $links;
}

function okrCountReferenceLinks($conn, $card_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS n FROM okr_reference_links WHERE card_id = " . (int)$card_id);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? (int)$row['n'] : 0;
}

// ---------------------------------------------------------------
// Key Result Progress: a 2-level task list per card (Key Result rows FK to
// okr_cards, Subtask rows FK to their parent Key Result via a
// self-referential parent_id), mirroring iidas's project_detail.php
// Progression Task / Subtask pattern. Only Key Results (parent_id NULL) can
// be staged before the card exists (create.php); Subtasks require a real
// parent id, so they're only addable once the card - and its Key Results -
// are already saved (edit.php).
// ---------------------------------------------------------------

// The fixed subset of okr_statuses a Key Result/Subtask can be set to -
// deliberately smaller than okrTimelineAssignableStatuses() (the OKR card's
// own Timeline field): no Draft/Extended/Completed with Extension here, just
// the four terminal-ish states that make sense for a single task row.
function okrKeyResultAssignableStatuses($conn) {
    $allowed = ['Active', OKR_STATUS_COMPLETED, 'Completed with Excellence', 'Failed'];
    return array_values(array_filter(okrFetchStatuses($conn, false), function ($s) use ($allowed) {
        return in_array($s['value'], $allowed, true);
    }));
}

// Flat list ordered by id (creation order), each row carrying its own stored
// status plus a computed 'display_status_value' and 'has_children' - a row
// with subtasks shows a derived status instead of its own stored value
// (mirrors iidas's parent-task auto-calc, previously done over percentages):
// Completed only once every subtask is itself Completed or Completed with
// Excellence, otherwise Active - so subtask statuses are the only ones a
// user directly sets once a Key Result has any.
function okrFetchKeyResults($conn, $card_id) {
    $rows = [];
    // `sort_order` (added by sql/add_okr_key_results_sort_order.sql, backing
    // the Subtask drag-to-reorder feature) may not exist yet on a database
    // that hasn't had that migration applied - fall back to the pre-drag
    // query (ordered by id only, sort_order defaulted to 0 in the returned
    // shape) instead of fataling the whole page.
    $has_sort_order = false;
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM okr_key_results LIKE 'sort_order'");
    if ($col_check && mysqli_num_rows($col_check) > 0) {
        $has_sort_order = true;
    }

    if ($has_sort_order) {
        $result = mysqli_query($conn, "SELECT kr.id, kr.card_id, kr.parent_id, kr.description,
                                               kr.atem_id, kr.status_id, kr.start_date, kr.end_date, kr.created_by, kr.created_at,
                                               kr.sort_order,
                                               s.nama_staff AS creator_name, os.value AS status_value
                                        FROM okr_key_results kr
                                        LEFT JOIN staff s ON kr.created_by = s.id
                                        LEFT JOIN okr_statuses os ON kr.status_id = os.id
                                        WHERE kr.card_id = " . (int)$card_id . "
                                        ORDER BY kr.sort_order ASC, kr.id ASC");
    } else {
        $result = mysqli_query($conn, "SELECT kr.id, kr.card_id, kr.parent_id, kr.description,
                                               kr.atem_id, kr.status_id, kr.start_date, kr.end_date, kr.created_by, kr.created_at,
                                               s.nama_staff AS creator_name, os.value AS status_value
                                        FROM okr_key_results kr
                                        LEFT JOIN staff s ON kr.created_by = s.id
                                        LEFT JOIN okr_statuses os ON kr.status_id = os.id
                                        WHERE kr.card_id = " . (int)$card_id . "
                                        ORDER BY kr.id ASC");
    }
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'id'                => (int)$row['id'],
                'card_id'           => (int)$row['card_id'],
                'parent_id'         => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
                'description'       => $row['description'],
                'created_by'        => (int)$row['created_by'],
                'creator_name'      => $row['creator_name'],
                'atem_id'           => $row['atem_id'] !== null ? (int)$row['atem_id'] : null,
                'status_id'         => (int)$row['status_id'],
                'status_value'      => $row['status_value'],
                'pill_class'        => okrPillClass($row['status_value']),
                'start_date'        => $row['start_date'],
                'end_date'          => $row['end_date'],
                'created_at'        => $row['created_at'],
                'sort_order'        => $has_sort_order ? (int)$row['sort_order'] : 0,
            ];
        }
    }

    // has_children is kept as metadata only (still used for numbering/render
    // decisions) - the status itself is no longer auto-derived from
    // subtasks. A Key Result with mixed subtask outcomes (e.g. one Completed,
    // one Failed) has no single obviously-correct computed status, so the
    // user just sets the main Key Result's status directly instead.
    $children_by_parent = [];
    foreach ($rows as $r) {
        if ($r['parent_id'] !== null) {
            $children_by_parent[$r['parent_id']][] = $r['id'];
        }
    }

    foreach ($rows as &$r) {
        $r['has_children'] = isset($children_by_parent[$r['id']]);
        $r['display_status_value'] = $r['status_value'];
        $r['display_pill_class'] = $r['pill_class'];
    }
    unset($r);

    return $rows;
}

function okrCountKeyResults($conn, $card_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS n FROM okr_key_results WHERE card_id = " . (int)$card_id);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? (int)$row['n'] : 0;
}

// Stages a top-level Key Result in the session so it can be linked once
// createCard succeeds (mirrors the reference-link staging above, for the
// create form). Each staged entry also carries its own 'subtasks' map (keyed
// by sub-token) and an optional 'atem_id' link - both resolved into real
// rows/columns by okrFinalizeStagedKeyResults once the card exists.
function okrStageKeyResult($description, $start_date, $end_date, $status_id) {
    $token = uniqid('kr_', true);
    $_SESSION['okr_draft_keyresults'] ??= [];
    $_SESSION['okr_draft_keyresults'][$token] = [
        'description'       => $description,
        'start_date'        => $start_date ?: null,
        'end_date'          => $end_date ?: null,
        'status_id'         => $status_id,
        'atem_id'           => null,
        'subtasks'          => [],
    ];
    return $token;
}

function okrRemoveStagedKeyResult($token) {
    if (!isset($_SESSION['okr_draft_keyresults'][$token])) {
        return false;
    }
    unset($_SESSION['okr_draft_keyresults'][$token]);
    return true;
}

// Updates a staged top-level Key Result's own fields in place, keeping its
// token (and therefore its nested 'subtasks' map) intact. Editing must never
// go through remove-then-re-stage (okrRemoveStagedKeyResult followed by a
// fresh okrStageKeyResult) - removing drops the whole session entry,
// including every staged Subtask nested inside it, as an unintended side
// effect of what's meant to be a plain text/date/status edit.
function okrUpdateStagedKeyResult($token, $description, $start_date, $end_date, $status_id) {
    if (!isset($_SESSION['okr_draft_keyresults'][$token])) {
        return false;
    }
    $_SESSION['okr_draft_keyresults'][$token]['description'] = $description;
    $_SESSION['okr_draft_keyresults'][$token]['start_date']  = $start_date ?: null;
    $_SESSION['okr_draft_keyresults'][$token]['end_date']    = $end_date ?: null;
    $_SESSION['okr_draft_keyresults'][$token]['status_id']   = $status_id;
    return true;
}

// Subtasks of a staged Key Result can't reference a real parent_id yet, so
// they nest inside their parent's session entry instead, keyed by their own
// token - flattened into real rows (with the parent's real id) once
// okrFinalizeStagedKeyResults runs.
function okrStageKeyResultSubtask($parent_token, $description, $start_date, $end_date, $status_id) {
    if (!isset($_SESSION['okr_draft_keyresults'][$parent_token])) {
        return null;
    }
    $sub_token = uniqid('krsub_', true);
    $_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token] = [
        'description'       => $description,
        'start_date'        => $start_date ?: null,
        'end_date'          => $end_date ?: null,
        'status_id'         => $status_id,
    ];
    return $sub_token;
}

function okrRemoveStagedKeyResultSubtask($parent_token, $sub_token) {
    if (!isset($_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token])) {
        return false;
    }
    unset($_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token]);
    return true;
}

// Same in-place-update rationale as okrUpdateStagedKeyResult above, one
// level down for a staged Subtask.
function okrUpdateStagedKeyResultSubtask($parent_token, $sub_token, $description, $start_date, $end_date, $status_id) {
    if (!isset($_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token])) {
        return false;
    }
    $_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token]['description'] = $description;
    $_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token]['start_date']  = $start_date ?: null;
    $_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token]['end_date']    = $end_date ?: null;
    $_SESSION['okr_draft_keyresults'][$parent_token]['subtasks'][$sub_token]['status_id']   = $status_id;
    return true;
}

// Links an existing or newly-created ATEM card against a still-staged
// (top-level only) Key Result - same "plain reference, no FK" rule as the
// real linkKeyResultAtem backend action. Looks in both places a staged token
// can live - a top-level Key Result, or nested inside one as a Subtask.
function okrSetStagedKeyResultAtem($token, $atem_id) {
    if (isset($_SESSION['okr_draft_keyresults'][$token])) {
        $_SESSION['okr_draft_keyresults'][$token]['atem_id'] = $atem_id ?: null;
        return true;
    }
    foreach ($_SESSION['okr_draft_keyresults'] ?? [] as $parent_token => &$kr) {
        if (isset($kr['subtasks'][$token])) {
            $kr['subtasks'][$token]['atem_id'] = $atem_id ?: null;
            return true;
        }
    }
    unset($kr);
    return false;
}

// Links an ATEM back to a just-finalized (real, non-staged) Key Result row
// via atem-api's PATCH /atem/{id}/okr-link, mirroring okr/backend.php's
// linkKeyResultAtem gate but only ever called here with a real KR id already
// known server-side - see okrFinalizeStagedKeyResults below. Loads atem/api.php
// as a library (same pattern documented in atem's CLAUDE.md) purely for its
// JWT bridge helpers; best-effort only, same tolerance as the rest of this
// staged-finalize flow - the KR<->ATEM link (okr_key_results.atem_id) is
// already durable at this point regardless of whether this call succeeds.
function okrBackfillAtemOkrLink($conn, $atem_id, $key_result_id, $actor_id) {
    if (empty($atem_id) || empty($key_result_id)) {
        return;
    }
    if (!function_exists('linkAtemOkrKeyResult')) {
        if (!defined('API_JWT_INCLUDED')) {
            define('API_JWT_INCLUDED', 1);
        }
        require_once __DIR__ . '/../atem/api.php';
    }
    linkAtemOkrKeyResult((int)$atem_id, (int)$key_result_id, (int)$actor_id, (int)$actor_id);
}

function okrFinalizeStagedKeyResults($conn, $card_id, $created_by) {
    if (empty($_SESSION['okr_draft_keyresults'])) {
        return;
    }
    foreach ($_SESSION['okr_draft_keyresults'] as $kr) {
        $desc_e = mysqli_real_escape_string($conn, $kr['description']);
        $start_sql = $kr['start_date'] ? "'" . mysqli_real_escape_string($conn, $kr['start_date']) . "'" : 'NULL';
        $end_sql = $kr['end_date'] ? "'" . mysqli_real_escape_string($conn, $kr['end_date']) . "'" : 'NULL';
        $status_id = (int)($kr['status_id'] ?? 0);
        $atem_sql = !empty($kr['atem_id']) ? (int)$kr['atem_id'] : 'NULL';
        mysqli_query($conn, "INSERT INTO okr_key_results
            (card_id, parent_id, description, atem_id, status_id, start_date, end_date, created_by)
            VALUES ($card_id, NULL, '$desc_e', $atem_sql, $status_id, $start_sql, $end_sql, " . (int)$created_by . ")");
        $parent_id = mysqli_insert_id($conn);
        if (!empty($kr['atem_id'])) {
            okrBackfillAtemOkrLink($conn, $kr['atem_id'], $parent_id, $created_by);
        }

        foreach (($kr['subtasks'] ?? []) as $sub) {
            $sub_desc_e = mysqli_real_escape_string($conn, $sub['description']);
            $sub_start_sql = $sub['start_date'] ? "'" . mysqli_real_escape_string($conn, $sub['start_date']) . "'" : 'NULL';
            $sub_end_sql = $sub['end_date'] ? "'" . mysqli_real_escape_string($conn, $sub['end_date']) . "'" : 'NULL';
            $sub_status_id = (int)($sub['status_id'] ?? 0);
            $sub_atem_sql = !empty($sub['atem_id']) ? (int)$sub['atem_id'] : 'NULL';
            mysqli_query($conn, "INSERT INTO okr_key_results
                (card_id, parent_id, description, atem_id, status_id, start_date, end_date, created_by)
                VALUES ($card_id, $parent_id, '$sub_desc_e', $sub_atem_sql, $sub_status_id, $sub_start_sql, $sub_end_sql, " . (int)$created_by . ")");
            if (!empty($sub['atem_id'])) {
                okrBackfillAtemOkrLink($conn, $sub['atem_id'], mysqli_insert_id($conn), $created_by);
            }
        }
    }
    $_SESSION['okr_draft_keyresults'] = [];
}

// Stages a reference link in the session so it can be linked once createCard
// succeeds (mirrors the attachment staging flow below, for the create form).
function okrStageReferenceLink($name, $url) {
    $token = uniqid('reflink_', true);
    $_SESSION['okr_draft_reflinks'] ??= [];
    $_SESSION['okr_draft_reflinks'][$token] = ['name' => $name, 'url' => $url];
    return $token;
}

function okrRemoveStagedReferenceLink($token) {
    if (!isset($_SESSION['okr_draft_reflinks'][$token])) {
        return false;
    }
    unset($_SESSION['okr_draft_reflinks'][$token]);
    return true;
}

function okrFinalizeStagedReferenceLinks($conn, $card_id, $added_by) {
    if (empty($_SESSION['okr_draft_reflinks'])) {
        return;
    }
    foreach ($_SESSION['okr_draft_reflinks'] as $link) {
        $name_e = mysqli_real_escape_string($conn, $link['name']);
        $url_e  = mysqli_real_escape_string($conn, $link['url']);
        mysqli_query($conn, "INSERT INTO okr_reference_links (card_id, name, url, added_by)
                              VALUES ($card_id, '$name_e', '$url_e', $added_by)");
    }
    $_SESSION['okr_draft_reflinks'] = [];
}

// Writes one immutable audit trail row (mirrors ATEM's atem_audit_logs).
// $changes is an associative array of field => [old, new], or null.
function okrLogAudit($conn, $card_id, $actor_id, $event, $changes, $summary) {
    $event_e = mysqli_real_escape_string($conn, $event);
    // summary is TEXT (up to 65,535 bytes) - several callers splice in
    // free-text user input (Suspend/Appeal reasons) that view.php later
    // reads back verbatim as the sole historical record of that reason (see
    // "Legacy data"/CEO Action note in CLAUDE.md: okr_cards.remarks/
    // appeal_justification only hold the *current* cycle, so full-length
    // history lives only in this column) - it must never be silently cut
    // short. This cap is purely a defensive backstop against a pathological
    // payload, not a normal-use limit.
    $summary = (string)$summary;
    if (mb_strlen($summary) > 20000) {
        $summary = mb_substr($summary, 0, 19997) . '...';
    }
    $summary_e = mysqli_real_escape_string($conn, $summary);
    $changes_sql = 'NULL';
    if ($changes !== null) {
        $changes_sql = "'" . mysqli_real_escape_string($conn, json_encode($changes)) . "'";
    }
    mysqli_query($conn, "INSERT INTO okr_audit_logs (card_id, event, actor_staff_id, changes, summary)
                          VALUES (" . (int)$card_id . ", '$event_e', " . (int)$actor_id . ", $changes_sql, '$summary_e')");
}

function okrFetchAuditLogs($conn, $card_id) {
    $logs = [];
    $result = mysqli_query($conn, "SELECT a.event, a.summary, a.created_at, s.nama_staff AS actor_name
                                    FROM okr_audit_logs a
                                    LEFT JOIN staff s ON a.actor_staff_id = s.id
                                    WHERE a.card_id = " . (int)$card_id . "
                                    ORDER BY a.created_at DESC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $logs[] = [
                'event'      => $row['event'],
                'summary'    => $row['summary'],
                'actor_name' => $row['actor_name'],
                'created_at' => $row['created_at'],
            ];
        }
    }
    return $logs;
}

// ---------------------------------------------------------------
// Chat Box: a per-card discussion thread, modeled after ATEM's Chat Box
// (edit.php + atem/api.php's chat-list/chat-send/chat-edit/chat-unsend,
// which proxy to atem-api). OKR has no Laravel API layer, so this is plain
// local mysqli against okr_chat_messages instead of an HTTP round trip.
// ---------------------------------------------------------------

// 60 seconds - same edit/unsend window ATEM uses, enforced server-side here
// (not just client-side) since backend.php is directly POST-able.
define('OKR_CHAT_EDIT_WINDOW_SECONDS', 60);

// Same "issuer, admin, or an owner" rule used for card-level access, applied
// to who may post - ATEM's equivalent is "issuer, SuperAdmin, or any ARCI
// member"; OKR has no multi-role ARCI, just up to two owners, so both are
// treated the same as ATEM's ARCI members for chat purposes.
function okrCanPostChat($card, $requester_id, $is_admin) {
    if ($is_admin) {
        return true;
    }
    $requester_id = (int)$requester_id;
    return $requester_id === (int)$card['issuer_staff_id']
        || (!empty($card['owner_staff_id']) && $requester_id === (int)$card['owner_staff_id'])
        || (!empty($card['owner2_staff_id']) && $requester_id === (int)$card['owner2_staff_id']);
}

// Same "issuer, owner, or owner2" membership as okrCanPostChat above, but
// governs a wider set of collaborative write actions on view.php that an
// Owner (not just the issuer/admin) may now perform directly from the card's
// read-only page, without needing edit.php access: updating a Key Result/
// Subtask's status (backend.php's updateKeyResult - description/dates there
// stay issuer/admin-only, forced back to their stored values for an
// owner-only requester), and adding/removing Attachments and Reference
// Links (addAttachment/deleteAttachment/addReferenceLink/deleteReferenceLink).
function okrCanCollaborateOnCard($card, $requester_id, $is_admin) {
    if ($is_admin) {
        return true;
    }
    $requester_id = (int)$requester_id;
    return $requester_id === (int)$card['issuer_staff_id']
        || (!empty($card['owner_staff_id']) && $requester_id === (int)$card['owner_staff_id'])
        || (!empty($card['owner2_staff_id']) && $requester_id === (int)$card['owner2_staff_id']);
}

function okrFetchChatMessages($conn, $card_id) {
    $messages = [];
    $result = mysqli_query($conn, "SELECT m.id, m.sender_staff_id, m.message, m.created_at, m.updated_at,
                                           s.nama_staff AS sender_name
                                    FROM okr_chat_messages m
                                    LEFT JOIN staff s ON m.sender_staff_id = s.id
                                    WHERE m.card_id = " . (int)$card_id . " AND m.deleted_at IS NULL
                                    ORDER BY m.created_at ASC, m.id ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $messages[] = [
                'id'               => (int)$row['id'],
                'sender_staff_id'  => (int)$row['sender_staff_id'],
                'sender_name'      => $row['sender_name'] ?: ('Staff #' . $row['sender_staff_id']),
                'message'          => $row['message'],
                'created_at'       => $row['created_at'],
                'updated_at'       => $row['updated_at'],
            ];
        }
    }
    return $messages;
}

// Ownership + the edit window are both re-checked here against the
// message's own sender_staff_id/created_at - never trusted from the client.
function okrChatMessageEditable($conn, $message_id, $requester_id) {
    $result = mysqli_query($conn, "SELECT sender_staff_id, created_at FROM okr_chat_messages
                                    WHERE id = " . (int)$message_id . " AND deleted_at IS NULL");
    if (!$result || mysqli_num_rows($result) === 0) {
        return ['ok' => false, 'message' => 'Message not found.'];
    }
    $row = mysqli_fetch_assoc($result);
    if ((int)$row['sender_staff_id'] !== (int)$requester_id) {
        return ['ok' => false, 'message' => 'You can only edit or unsend your own messages.'];
    }
    if ((time() - strtotime($row['created_at'])) > OKR_CHAT_EDIT_WINDOW_SECONDS) {
        return ['ok' => false, 'message' => 'This message can no longer be edited or unsent.'];
    }
    return ['ok' => true];
}

// ---------------------------------------------------------------
// Suspend / Appeal / Force Terminate notifications - grade-5 (CEO/Board) and
// SuperAdmin, the same population already allowed to suspend/unsuspend/force
// terminate a card, is who receives the Appeal email. Union of staff.okr and
// staff.atem mirrors the SuperAdmin flag used everywhere else in this repo.
function okrCeoRecipients($conn) {
    $recipients = [];
    $result = mysqli_query($conn, "SELECT id, nama_staff, email FROM staff
                                    WHERE recycle != 1 AND (grade = 5 OR okr = 1 OR atem = 1)");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $recipients[] = [
                'staff_id' => (int)$row['id'],
                'name'     => $row['nama_staff'],
                'email'    => $row['email'],
            ];
        }
    }
    return $recipients;
}

// ---------------------------------------------------------------
// In-app notifications ("Octopus notification") - scoped to chat messages
// only for now. Plain local mysqli, unlike ATEM's aspirational Laravel-backed
// notification bell (fully wired frontend, but no real backend endpoint
// exists in that checkout - see okr/CLAUDE.md's Chat Box section) - OKR has
// no API layer to proxy through, so this is genuinely implemented instead.
// ---------------------------------------------------------------
function okrNotifyChat($conn, $card_id, $recipient_staff_id) {
    mysqli_query($conn, "INSERT INTO okr_notifications (staff_id, card_id, type)
                          VALUES (" . (int)$recipient_staff_id . ", " . (int)$card_id . ", 'chat_message')");
}

function okrFetchNotifications($conn, $staff_id, $limit = 20) {
    $notifications = [];
    $result = mysqli_query($conn, "SELECT id, card_id, type, read_at, created_at
                                    FROM okr_notifications
                                    WHERE staff_id = " . (int)$staff_id . "
                                    ORDER BY created_at DESC LIMIT " . (int)$limit);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $notifications[] = [
                'id'         => (int)$row['id'],
                'card_id'    => (int)$row['card_id'],
                'type'       => $row['type'],
                'read_at'    => $row['read_at'],
                'created_at' => $row['created_at'],
            ];
        }
    }
    return $notifications;
}

function okrUnreadNotificationCount($conn, $staff_id) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS n FROM okr_notifications
                                    WHERE staff_id = " . (int)$staff_id . " AND read_at IS NULL");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? (int)$row['n'] : 0;
}

function okrMarkNotificationRead($conn, $notification_id, $staff_id) {
    mysqli_query($conn, "UPDATE okr_notifications SET read_at = NOW()
                          WHERE id = " . (int)$notification_id . " AND staff_id = " . (int)$staff_id . " AND read_at IS NULL");
}

function okrMarkAllNotificationsRead($conn, $staff_id) {
    mysqli_query($conn, "UPDATE okr_notifications SET read_at = NOW()
                          WHERE staff_id = " . (int)$staff_id . " AND read_at IS NULL");
}

function okrFetchAttachments($conn, $card_id) {
    $attachments = [];
    $result = mysqli_query($conn, "SELECT id, original_name, size, mime_type, created_at
                                    FROM okr_card_attachments WHERE card_id = " . (int)$card_id . "
                                    ORDER BY created_at ASC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attachments[] = [
                'id'            => (int)$row['id'],
                'original_name' => $row['original_name'],
                'size'          => (int)$row['size'],
                'mime_type'     => $row['mime_type'],
                'created_at'    => $row['created_at'],
            ];
        }
    }
    return $attachments;
}

// Validates an $_FILES[...] entry against the allowed extensions/size.
// Returns an error message string, or null if the file is acceptable.
function okrValidateUpload($file) {
    global $OKR_ALLOWED_EXT, $OKR_MAX_FILE_SIZE;

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed.';
    }
    if ($file['size'] > $OKR_MAX_FILE_SIZE) {
        return 'File exceeds the 10MB limit.';
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $OKR_ALLOWED_EXT, true)) {
        return 'File type not allowed.';
    }
    return null;
}

// Stages an uploaded file in uploads/tmp and records it in the session so it
// can later be attached to a card once the card itself is saved (mirrors
// ATEM's session-draft attachment flow for the create form).
function okrStageAttachment($file) {
    global $OKR_UPLOAD_TMP_DIR;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $token = uniqid('okr_', true);
    $stored_name = $token . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $OKR_UPLOAD_TMP_DIR . $stored_name)) {
        return null;
    }

    $_SESSION['okr_draft_files'] ??= [];
    $_SESSION['okr_draft_files'][$token] = [
        'stored_name'   => $stored_name,
        'original_name' => $file['name'],
        'size'          => (int)$file['size'],
        'mime_type'     => $file['type'] ?? null,
    ];

    return $token;
}

function okrRemoveStagedAttachment($token) {
    global $OKR_UPLOAD_TMP_DIR;

    if (!isset($_SESSION['okr_draft_files'][$token])) {
        return false;
    }
    $path = $OKR_UPLOAD_TMP_DIR . $_SESSION['okr_draft_files'][$token]['stored_name'];
    if (is_file($path)) {
        unlink($path);
    }
    unset($_SESSION['okr_draft_files'][$token]);
    return true;
}

// Fully discards an in-progress create-form draft: removes any staged
// attachment tmp files, clears staged reference links, and clears the
// autosaved form-field state (okr_draft_state). Used by the clearDraftState
// action and after a successful createCard, mirrors ATEM's draft-clear.
function okrClearDraftSession($conn = null) {
    if (!empty($_SESSION['okr_draft_files'])) {
        foreach (array_keys($_SESSION['okr_draft_files']) as $token) {
            okrRemoveStagedAttachment($token);
        }
    }
    $_SESSION['okr_draft_reflinks'] = [];
    $_SESSION['okr_draft_keyresults'] = [];
    unset($_SESSION['okr_draft_state']);

    // Explicit "Cancel OKR" is an unambiguous signal to drop the placeholder
    // Draft row okrEnsureDraftCard() created on page load too - no child rows
    // exist for it yet (it was never finalized), so a plain delete is enough.
    if ($conn !== null && !empty($_SESSION['okr_draft_card_id'])) {
        $draft_id = (int)$_SESSION['okr_draft_card_id'];
        mysqli_query($conn, "DELETE FROM okr_cards WHERE id = $draft_id AND result_status = (
            SELECT id FROM okr_statuses WHERE value = '" . OKR_STATUS_DRAFT . "'
        ) AND deleted_at IS NULL");
    }
    unset($_SESSION['okr_draft_card_id']);
}

// Uploads every attachment staged in the session to the NAS and links it to
// the newly-created card. Called right after createCard succeeds.
function okrFinalizeStagedAttachments($conn, $card_id, $uploaded_by) {
    global $OKR_UPLOAD_TMP_DIR;

    if (empty($_SESSION['okr_draft_files'])) {
        return;
    }

    $nas = corpNasConnect();
    foreach ($_SESSION['okr_draft_files'] as $file) {
        $tmp_path = $OKR_UPLOAD_TMP_DIR . $file['stored_name'];
        if (!is_file($tmp_path)) {
            continue;
        }
        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $file['stored_name']);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }
        $original_name_e = mysqli_real_escape_string($conn, $file['original_name']);
        $stored_name_e    = mysqli_real_escape_string($conn, $nas_path);
        $mime_type_e      = mysqli_real_escape_string($conn, (string)$file['mime_type']);
        mysqli_query($conn, "INSERT INTO okr_card_attachments
            (card_id, original_name, stored_name, size, mime_type, uploaded_by)
            VALUES ($card_id, '$original_name_e', '$stored_name_e', " . (int)$file['size'] . ", '$mime_type_e', $uploaded_by)");
    }

    $_SESSION['okr_draft_files'] = [];
}

// Admin toggle (okr_config.backdate_enabled) - when false, date pickers
// across the module reject past dates; when true, backdating is allowed.
function okrBackdateEnabled($conn) {
    $result = mysqli_query($conn, "SELECT setting_value FROM okr_config WHERE setting_key = 'backdate_enabled'");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return $row['setting_value'] === '1';
    }
    return false;
}

function okrDeptIdsFromCsv($csv) {
    $ids = [];
    foreach (explode(',', (string)$csv) as $_d) {
        $_d = (int)trim($_d);
        if ($_d > 0) {
            $ids[] = $_d;
        }
    }
    return $ids;
}

// Grade-based visibility scope, mirrors framework doc section 3:
// grade 1-2 see own (owned) cards only, grade 3 sees own department's cards
// (plus anything they issued), grade 4-5 see the whole company.
function okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $is_admin = false) {
    if ($is_admin || $requester_grade >= 4) {
        return '1=1';
    }
    if ($requester_grade === 3) {
        if (empty($requester_dept_ids)) {
            return "c.issuer_staff_id = $requester_id";
        }
        $conds = [];
        foreach ($requester_dept_ids as $_did) {
            $conds[] = "FIND_IN_SET($_did, c.dept_scope)";
        }
        return '(' . implode(' OR ', $conds) . ") OR c.issuer_staff_id = $requester_id";
    }
    return "(c.owner_staff_id = $requester_id OR c.owner2_staff_id = $requester_id)";
}

// Only meaningful before a card is saved with the resolved status - once
// saved, result_status already IS "Completed with Extension" (see updateCard),
// so this is only needed for the edit form's live preview of the Completed
// option's label while the user is still choosing.
function okrStatusDisplayLabel($status, $extended) {
    return ($extended && $status === OKR_STATUS_COMPLETED) ? OKR_STATUS_COMPLETED_EXTENSION : $status;
}

// The one canonical presentational map - pages/JS should consume the
// pre-computed 'pill_class' field on cards/rows (see okrFormatCard) rather
// than re-declaring their own copy of this map.
function okrPillClass($status) {
    $map = [
        'Draft'                      => 'okr-pill-draft',
        'Active'                     => 'okr-pill-active',
        'Completed'                  => 'okr-pill-complete',
        'Completed with Excellence'  => 'okr-pill-complete-excellence',
        'Completed with Extension'   => 'okr-pill-complete-extension',
        'Extended'                   => 'okr-pill-extend',
        'Suspended'                  => 'okr-pill-suspended',
        'Failed'                     => 'okr-pill-fail',
        'Force Terminated'           => 'okr-pill-fail',
    ];
    return isset($map[$status]) ? $map[$status] : 'okr-pill-active';
}
