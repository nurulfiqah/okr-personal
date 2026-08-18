<?php
require_once(__DIR__ . '/lib.php');

$card_id = (int)($_GET['id'] ?? 0);

$page_title = 'Edit OKR';

// The Link ATEM modal's "Create New ATEM" pane mirrors atem/create.php's own
// fields exactly, so it needs atem/css/style.css (the .atem-* classes) and
// the Quill rich-text editor atem/create.php uses for its Description field.
$extra_css = '<link href="atem/css/style.css?v=' . time() . '" rel="stylesheet">'
    . '<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">';
include('header.php');

if ($card_id <= 0) {
    echo '<div class="okr-card"><p class="mb-0">Invalid OKR card.</p></div>';
    $page_js = '';
    include('footer.php');
    exit;
}

$scope_where = okrScopeWhere($id_user, $okr_permission, okrDeptIdsFromCsv($department ?? ''), $okr_is_admin);
$result = mysqli_query($conn, okrCardSelectSql("c.id = $card_id AND ($scope_where)"));

if (!$result || mysqli_num_rows($result) === 0) {
    echo '<div class="okr-card"><p class="mb-0">This OKR card was not found or you do not have access to it.</p></div>';
    $page_js = '';
    include('footer.php');
    exit;
}

$row  = mysqli_fetch_assoc($result);
$card = okrFormatCard($row);

$can_edit = ($okr_is_admin || $card['issuer_staff_id'] === (int)$id_user);
// Currently-suspended cards are locked pending Unsuspend/Force Terminate
// (see view.php's CEO Action) - is_suspended is now independent of
// result_status (see backend.php's suspendCard), so this checks the flag,
// not a status value. Failed and Force Terminated (see backend.php's
// forceTerminateCard) are locked for everyone, admins included, same as a
// currently-suspended card.
if (!$can_edit || $card['is_suspended'] || $card['result_status'] === 'Failed'
    || $card['result_status'] === OKR_STATUS_FORCE_TERMINATED) {
    header('Location: /odb/okr/view.php?id=' . $card_id);
    exit;
}

// Same CEO Action / Appeal Suspension gating as view.php, so the CEO/admin
// doesn't have to leave the edit form to suspend an OKR - see view.php for
// the full rationale on each variable. A currently-suspended (or Failed/
// Force Terminated) card can never reach this page (redirected above), so
// the Unsuspend/Force-Terminate-while-Suspended and appeal-submission
// branches below never actually render here; they're kept identical to
// view.php's so both pages stay in sync if the rule ever changes, and so
// past suspend/appeal history still displays.
$is_ceo_or_admin = ($okr_is_admin || $okr_permission === 5);
$can_suspend = $is_ceo_or_admin && $card['result_status'] !== OKR_STATUS_DRAFT && !$card['is_suspended'];
$is_completed_status = in_array($card['result_status'], okrCompletedStatusValues(), true);
$can_initiate_suspend = $can_suspend;
$can_appeal = ($card['issuer_staff_id'] === (int)$id_user
    && $card['is_suspended'] && empty($card['appealed_at']));
$audit_logs_for_ceo_action = okrFetchAuditLogs($conn, $card_id);
$suspend_logs = array_values(array_filter($audit_logs_for_ceo_action, function ($log) {
    return in_array($log['event'], ['suspended', 'force_terminated'], true);
}));
$appeal_logs = array_values(array_filter($audit_logs_for_ceo_action, function ($log) {
    return $log['event'] === 'appealed';
}));
$latest_suspend_log = !empty($suspend_logs) ? $suspend_logs[0] : null;
$already_suspended_once = !empty(array_filter($audit_logs_for_ceo_action, function ($log) {
    return $log['event'] === 'suspended';
}));

$issuer_department = '';
$issuer_res = mysqli_query($conn, 'SELECT department FROM staff WHERE id = ' . (int)$card['issuer_staff_id'] . ' LIMIT 1');
if ($issuer_res && ($issuer_row = mysqli_fetch_assoc($issuer_res))) {
    $issuer_dept_ids = okrDeptIdsFromCsv($issuer_row['department']);
    if (!empty($issuer_dept_ids)) {
        $issuer_dept_res = mysqli_query($conn, 'SELECT depart_name FROM staff_department WHERE id = ' . (int)$issuer_dept_ids[0] . ' LIMIT 1');
        if ($issuer_dept_res && mysqli_num_rows($issuer_dept_res) > 0) {
            $issuer_department = mysqli_fetch_assoc($issuer_dept_res)['depart_name'];
        }
    }
}

$staff_list = [];
$staff_res = mysqli_query($conn, "SELECT id, nama_staff, department FROM staff WHERE recycle != 1 ORDER BY nama_staff");
if ($staff_res) {
    while ($srow = mysqli_fetch_assoc($staff_res)) {
        $staff_list[] = [
            'id'       => (int)$srow['id'],
            'name'     => $srow['nama_staff'],
            'deptIds'  => okrDeptIdsFromCsv($srow['department']),
        ];
    }
}

$departments = [];
$dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department ORDER BY depart_name');
if ($dept_res) {
    while ($drow = mysqli_fetch_assoc($dept_res)) {
        $departments[] = ['id' => (int)$drow['id'], 'name' => $drow['depart_name']];
    }
}

// -----------------------------------------------------------------------
// Link ATEM modal - "Create New ATEM" pane data. Mirrors atem/create.php's
// own PHP data prep verbatim (including its single-department, non-CSV-aware
// staff.department cast - atem/create.php itself doesn't parse the CSV
// convention lib.php's okrDeptIdsFromCsv() does, so replicating it exactly
// means keeping that same simplification here rather than "fixing" it).
// -----------------------------------------------------------------------
$atemcreate_departments = [];
$atemcreate_staff_by_dept = [];
$atemcreate_staff_sql = "SELECT s.id, s.nama_staff, s.department, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.recycle != 1
              ORDER BY d.depart_name, s.nama_staff";
$atemcreate_staff_res = mysqli_query($conn, $atemcreate_staff_sql);
if ($atemcreate_staff_res) {
    while ($srow = mysqli_fetch_assoc($atemcreate_staff_res)) {
        $dept_id = (int)$srow['department'];
        if ($dept_id <= 0 || empty($srow['depart_name'])) {
            continue;
        }
        if (!isset($atemcreate_departments[$dept_id])) {
            $atemcreate_departments[$dept_id] = $srow['depart_name'];
            $atemcreate_staff_by_dept[$dept_id] = [];
        }
        $atemcreate_staff_by_dept[$dept_id][] = [
            'id'   => (int)$srow['id'],
            'name' => $srow['nama_staff'],
        ];
    }
}
$atemcreate_departments_list = [];
foreach ($atemcreate_departments as $d_id => $d_name) {
    $atemcreate_departments_list[] = ['id' => $d_id, 'name' => $d_name];
}

$atemcreate_outlets_list = [];
$atemcreate_outlet_res = mysqli_query($conn, "SELECT id, code FROM outlet ORDER BY code ASC");
if ($atemcreate_outlet_res) {
    while ($orow = mysqli_fetch_assoc($atemcreate_outlet_res)) {
        $atemcreate_outlets_list[] = ['id' => (int)$orow['id'], 'code' => $orow['code']];
    }
}

$atemcreate_area_managers_list = [];
$atemcreate_am_sql = "SELECT s.id, s.nama_staff, s.outlet, p.position_name
           FROM staff s
           LEFT JOIN position_rymnet p ON p.id = s.status_rym
           WHERE FIND_IN_SET('1', s.department) AND s.grade >= 3 AND s.recycle != 1
           ORDER BY s.nama_staff";
$atemcreate_am_res = mysqli_query($conn, $atemcreate_am_sql);
if ($atemcreate_am_res) {
    while ($arow = mysqli_fetch_assoc($atemcreate_am_res)) {
        $atemcreate_am_outlet_ids = [];
        foreach (explode(',', (string)$arow['outlet']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid > 0) {
                $atemcreate_am_outlet_ids[] = $oid;
            }
        }
        $atemcreate_area_managers_list[] = [
            'id'         => (int)$arow['id'],
            'name'       => $arow['nama_staff'],
            'position'   => $arow['position_name'] ?? '',
            'outlet_ids' => $atemcreate_am_outlet_ids,
        ];
    }
}

$atemcreate_staff_by_outlet = [];
$atemcreate_all_staff_sql = "SELECT s.id, s.nama_staff, s.outlet, p.position_name
                  FROM staff s
                  LEFT JOIN position_rymnet p ON p.id = s.status_rym
                  WHERE s.recycle != 1";
$atemcreate_all_staff_res = mysqli_query($conn, $atemcreate_all_staff_sql);
if ($atemcreate_all_staff_res) {
    while ($orow2 = mysqli_fetch_assoc($atemcreate_all_staff_res)) {
        if (empty($orow2['outlet'])) {
            continue;
        }
        foreach (explode(',', (string)$orow2['outlet']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($atemcreate_staff_by_outlet[$oid])) {
                $atemcreate_staff_by_outlet[$oid] = [];
            }
            $atemcreate_staff_by_outlet[$oid][] = [
                'id'       => (int)$orow2['id'],
                'name'     => $orow2['nama_staff'],
                'position' => $orow2['position_name'],
            ];
        }
    }
}

// atem/create.php's own issuer/department resolution (single-value, not
// CSV-aware - see the note above) - kept separate from OKR's own
// $issuer_department computed above (that one describes the OKR CARD's
// issuer, who may be a different person than whoever is editing it, e.g. an
// admin) - this one describes the CURRENT SESSION USER, who becomes the
// ATEM's issuer if they use the "Create New ATEM" pane, same as create.php.
$atemcreate_issuer_department = '';
if (isset($department) && $department !== '') {
    $atemcreate_dept_sql = "SELECT depart_name FROM staff_department WHERE id = '" . mysqli_real_escape_string($conn, $department) . "' LIMIT 1";
    $atemcreate_dept_res = mysqli_query($conn, $atemcreate_dept_sql);
    if ($atemcreate_dept_res && mysqli_num_rows($atemcreate_dept_res) > 0) {
        $atemcreate_issuer_department = mysqli_fetch_assoc($atemcreate_dept_res)['depart_name'];
    }
}

// Reuses atem/api.php's own JWT-bridge functions (getAtemLookups, etc.)
// directly - API_JWT_INCLUDED skips its JSON header and standalone
// action-dispatch block, matching how atem/view.php and atem/create.php
// themselves pull it in. It re-resolves $staff_id/$department/$outlet from
// the session independently, so no extra wiring is needed here.
define('API_JWT_INCLUDED', true);
include(__DIR__ . '/../' . $_navbar_atem_folder . '/api.php');

$atemcreate_lookups = ['levels' => [], 'rules' => [], 'statuses' => [], 'pillars' => [], 'reward_masterlist' => []];
$atemcreate_lookup_result = getAtemLookups($staff_id);
if (!empty($atemcreate_lookup_result['success']) && isset($atemcreate_lookup_result['data'])) {
    $atemcreate_lookups = $atemcreate_lookup_result['data'];
}
$atemcreate_issuer_auth = getStaffAuthData($staff_id);
$atemcreate_api_unavailable = empty($atemcreate_lookup_result['success']);

$atemcreate_bd_enabled = false;
$atemcreate_bd_result = mysqli_query($conn, "SELECT setting_value FROM atem_config WHERE setting_key = 'backdate_enabled'");
if ($atemcreate_bd_result && ($r = mysqli_fetch_assoc($atemcreate_bd_result))) {
    $atemcreate_bd_enabled = ($r['setting_value'] === '1');
}

$atemcreate_config = [
    'apiUrl'        => $_navbar_atem_folder . '/api.php',
    'levels'        => $atemcreate_lookups['levels'] ?? [],
    'rules'         => $atemcreate_lookups['rules'] ?? [],
    'pillars'       => $atemcreate_lookups['pillars'] ?? [],
    'rewardMasterlist' => $atemcreate_lookups['reward_masterlist'] ?? [],
    'issuer'        => [
        'id'              => ($atemcreate_issuer_auth['staff_id'] ?? null) ?: $staff_id,
        'name'            => $nama_staff ?? '',
        'staff_dept_id'   => $atemcreate_issuer_auth['staff_dept_id'] ?? null,
        'department_name' => $atemcreate_issuer_department,
    ],
    'departments'   => $atemcreate_departments_list,
    'staffByDept'   => $atemcreate_staff_by_dept,
    'outlets'       => $atemcreate_outlets_list,
    'areaManagers'  => $atemcreate_area_managers_list,
    'staffByOutlet' => $atemcreate_staff_by_outlet,
    'backdate'      => ['enabled' => $atemcreate_bd_enabled],
    'apiUnavailable' => $atemcreate_api_unavailable,
];

$attachments     = okrFetchAttachments($conn, $card_id);
$reference_links = okrFetchReferenceLinks($conn, $card_id);
$audit_logs      = okrFetchAuditLogs($conn, $card_id);
$chat_messages   = okrFetchChatMessages($conn, $card_id);
$can_post_chat   = okrCanPostChat($card, $id_user, $okr_is_admin);

// Timeline dropdown options: every value okrTimelineAssignableStatuses()
// allows (excludes Suspended/Completed with Extension, and Deleted/Force
// Terminated - the latter two are flag-backed, never actually written to
// result_status, and exist in okr_statuses only for id/value parity with
// atem_statuses - see that function's comment in lib.php).
$all_statuses = okrFetchStatuses($conn, false);
$assignable_status_values = okrTimelineAssignableStatuses($conn);
$timeline_status_options = array_values(array_filter($all_statuses, function ($s) use ($assignable_status_values) {
    return in_array($s['value'], $assignable_status_values, true);
}));

$okr_config = [
    'apiUrl'          => 'okr/backend.php',
    'atemApiUrl'      => $_navbar_atem_folder . '/api.php',
    'atemViewUrl'     => $_navbar_atem_folder . '/edit.php',
    'okrViewUrl'      => 'okr/view.php',
    'card'            => $card,
    'staff'           => $staff_list,
    'departments'     => $departments,
    'attachments'     => $attachments,
    'referenceLinks'  => $reference_links,
    'deptScopeIds'    => empty($card['dept_scope']) ? [] : okrDeptIdsFromCsv($card['dept_scope']),
    'backdateEnabled' => okrBackdateEnabled($conn),
    'currentUserName' => $nama_staff ?? '',
    'keyResultStatuses' => okrKeyResultAssignableStatuses($conn),
    'chatMessages'    => $chat_messages,
    'canPostChat'     => $can_post_chat,
    'currentStaffId'  => (int)$id_user,
    'isAdmin'         => $okr_is_admin,
];
?>

<div class="okr-bento">

    <div class="okr-bento-item okr-span-8">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-file-earmark-text"></i> OKR<?php echo (int)$card['id']; ?>
                    Details</h6>
                <span
                    class="okr-pill <?php echo okrPillClass($card['result_status']); ?>"><?php echo htmlspecialchars($card['result_status']); ?></span>
            </div>
            <p class="okr-card-hint">Fields marked <span class="okr-req">*</span> are required.</p>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label for="okr-objective" class="form-label">Objective <span class="okr-req">*</span></label>
                    <textarea class="form-control" id="okr-objective"
                        rows="2"><?php echo htmlspecialchars($card['objective']); ?></textarea>
                    <div class="okr-form-error" id="okr-objective-error"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Issuer</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars($card['issuer_name']); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($issuer_department); ?>"
                        readonly>
                </div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-4">
        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
            <p class="okr-card-hint">Upload supporting files (max 10MB each).</p>
            <div id="okr-dropzone" class="okr-dropzone">
                <input type="file" id="okr-file-input" multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                <div class="okr-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a href="#"
                        id="okr-file-pick">click to select</a></div>
                <small class="okr-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF, Word, Excel, Text</small>
            </div>
            <div class="okr-form-error" id="okr-file-error"></div>
            <div id="okr-file-list" class="okr-file-list mt-2"></div>
        </div>

        <div class="okr-card mb-3">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-link-45deg"></i> Reference Link <span
                        class="okr-req">*</span></h6>
                <button type="button" class="btn btn-primary btn-sm" id="okr-add-reflink-btn">Add Reference
                    Link</button>
            </div>
            <p class="okr-card-hint">Add named links to related documents or resources (e.g. the OKR's Trello board).
            </p>
            <div id="okr-reflink-list" class="okr-reflink-list"></div>
            <div class="okr-form-error" id="reflink-section-error"></div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-list-task"></i> Key Result Progress</h6>
                <button type="button" class="btn btn-primary btn-sm" id="okr-kr-add-btn">Add Key Result</button>
            </div>
            <p class="okr-card-hint">Break this OKR down into Key Results, and optionally split a Key Result into
                subtasks. A Key Result with subtasks shows its progress as the average of those subtasks.</p>
            <div class="okr-alert-notice mb-2" id="okr-kr-date-warning" style="display:none;">
                <i class="bi bi-exclamation-triangle"></i> Some Key Result dates fall outside this OKR's Start/End
                Date. Please update them to stay within range.
            </div>
            <div id="okr-kr-list" class="okr-kr-list"></div>
            <div class="okr-form-error" id="okr-kr-error"></div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-people"></i> Owner(s)</h6>
            <p class="okr-card-hint">Tag the owner(s). A (Accountable) supports up to 2 members. A 2nd owner is only for
                jointly-run OKRs.</p>

            <div class="okr-arci-add">
                <div class="okr-arci-add-grid okr-arci-add-grid-3">
                    <div>
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control mb-1" id="okr-owner-dept-search"
                            placeholder="Search department...">
                        <select class="form-select" id="okr-owner-dept-select" size="6">
                            <option value="">Select department</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Staff</label>
                        <input type="text" class="form-control mb-1" id="okr-owner-staff-search"
                            placeholder="Search staff...">
                        <div id="okr-owner-staff-list" class="okr-arci-staff-list">
                            <div class="text-muted" style="font-size:13px;">Select a department to load staff</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 w-100" id="okr-owner-add-btn">Add
                            Selected</button>
                    </div>
                    <div>
                        <div class="okr-arci-col">
                            <div class="okr-arci-col-head">
                                <span><strong>A</strong> - Accountable (Owner)</span>
                            </div>
                            <div class="okr-arci-members" id="okr-owner-members">
                                <div class="okr-arci-empty">No owners assigned</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="okr-form-error" id="okr-owner-error"></div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-calendar-range"></i> Timeline</h6>
            <p class="okr-card-hint">Schedule, status, extensions and closure for this OKR.</p>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label for="okr-start" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="okr-start"
                        value="<?php echo htmlspecialchars($card['start_date']); ?>"
                        <?php echo $okr_is_admin ? '' : 'disabled'; ?>>
                    <div class="okr-form-error" id="okr-start-error"></div>
                    <?php if (!$okr_is_admin): ?>
                    <p class="okr-card-hint mb-0">Dates are locked once an OKR is created; only an admin can change
                        them.</p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="okr-end" class="form-label">End Date <span class="okr-req">*</span></label>
                    <input type="date" class="form-control" id="okr-end"
                        value="<?php echo htmlspecialchars($card['end_date']); ?>"
                        <?php echo $okr_is_admin ? '' : 'disabled'; ?>>
                    <div class="okr-form-error" id="okr-end-error"></div>
                </div>
                <div class="col-md-4">
                    <label for="okr-status" class="form-label">Status <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-status">
                        <?php
                        // Reads live from okr_statuses (minus the two system-
                        // managed statuses) instead of a fixed option list, so
                        // an admin renaming/adding/soft-deleting a status is
                        // reflected here with no code change.
                        $post_extension_statuses = okrPostExtensionResolvableStatuses();
                        foreach ($timeline_status_options as $st):
                            $value = $st['value'];
                            $is_selected = ($card['result_status'] === $value)
                                || ($value === OKR_STATUS_COMPLETED && $card['result_status'] === OKR_STATUS_COMPLETED_EXTENSION);
                            // Always keep the card's own current value in the
                            // list even if it's no longer a valid choice going
                            // forward (e.g. a card sitting at "Extended" from
                            // before this restriction existed) - otherwise the
                            // <select> has no matching <option>, the browser
                            // silently falls back to the first option, and
                            // saving without touching Status would submit that
                            // instead of the OKR's real current value.
                            $visible = !$card['extended'] || $okr_is_admin || in_array($value, $post_extension_statuses, true) || $is_selected;
                            if (!$visible) { continue; }
                            $label = okrStatusDisplayLabel($value, $card['extended']);
                        ?>
                        <option value="<?php echo htmlspecialchars($value); ?>"
                            <?php echo $is_selected ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($card['extended'] && !$okr_is_admin): ?>
                    <p class="okr-card-hint">This OKR has been extended, so it can now only resolve as Completed with
                        Extension or Failed.</p>
                    <?php elseif ($card['extended'] && $okr_is_admin): ?>
                    <p class="okr-card-hint text-danger"><strong>ADMIN OVERRIDE:</strong> this OKR has been extended
                        and is normally locked to Completed/Failed for everyone else. As admin you may still change
                        Status, the Extended flag, and the Extended Date directly &mdash; use with care.</p>
                    <?php endif; ?>
                    <div class="okr-form-error" id="okr-status-error"></div>
                </div>

                <div class="col-12">
                    <div class="form-check okr-extended-check mb-2">
                        <input class="form-check-input" type="checkbox" id="okr-extended"
                            <?php echo $card['extended'] ? 'checked' : ''; ?>
                            <?php echo ($card['extended'] && !$okr_is_admin) ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="okr-extended" style="font-size: 12px;">Extended? (once only
                            &mdash; cannot be undone<?php echo $okr_is_admin ? ', admin exempt' : ''; ?>)</label>
                    </div>
                </div>

                <div class="col-md-4" id="okr-extended-date-wrap"
                    style="<?php echo $card['extended'] ? '' : 'display:none;'; ?>">
                    <label for="okr-extended-date" class="form-label">Extended Date <span class="okr-req"
                            id="okr-extended-date-req"
                            style="<?php echo $card['extended'] ? '' : 'display:none;'; ?>">*</span></label>
                    <input type="date" class="form-control" id="okr-extended-date"
                        value="<?php echo htmlspecialchars($card['extended_date'] ?? ''); ?>"
                        <?php echo ($card['extended'] && $okr_is_admin) ? '' : 'disabled'; ?>>
                </div>
                <div class="col-md-4">
                    <label for="okr-final-due" class="form-label">Final Due Date</label>
                    <input type="date" class="form-control" id="okr-final-due"
                        value="<?php echo htmlspecialchars($card['final_due_date']); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label for="okr-closure" class="form-label">Closure Date</label>
                    <?php $can_edit_closure = okrCanEditClosureDate($card['result_status'], $card['issuer_staff_id'], false, $id_user, $okr_permission, $okr_is_admin); ?>
                    <input type="date" class="form-control" id="okr-closure"
                        value="<?php echo htmlspecialchars($card['closure_date'] ?? ''); ?>"
                        min="<?php echo htmlspecialchars($card['start_date']); ?>" max="<?php echo date('Y-m-d'); ?>"
                        <?php echo $can_edit_closure ? '' : 'disabled'; ?>>
                    <?php if ($can_edit_closure): ?>
                    <p class="okr-card-hint mb-0">Editable by the Issuer, CEO, or SuperAdmin - range: Start Date to
                        today.</p>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label for="okr-remarks" class="form-label">Remarks</label>
                    <textarea class="form-control" id="okr-remarks" rows="3"
                        placeholder="Notes, failure reason or excellence remark"><?php echo htmlspecialchars($card['remarks'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="okr-save-error-wrap">
    <div class="okr-form-error" id="okr-save-error"></div>
</div>
<div class="okr-save-bar">
    <a href="okr/view.php?id=<?php echo $card_id; ?>" class="btn btn-outline-secondary">Cancel</a>
    <button type="button" class="btn btn-primary" id="okr-save-btn">Save Changes</button>
</div>

<?php if ($latest_suspend_log || $is_ceo_or_admin || $can_appeal): ?>
<div class="row g-3 mt-0" id="okr-ceo-action-row">
    <div class="col-md-6">
        <div class="okr-card h-100" style="border-left:4px solid #ffc107;">
            <h6 class="okr-card-title" style="color:#856404;"><i class="bi bi-pause-circle"></i> CEO Action</h6>
            <?php foreach ($suspend_logs as $suspend_log):
                $suspend_reason_text = preg_replace('/^.*?:\s*/', '', $suspend_log['summary'], 1);
                $suspend_verb        = $suspend_log['event'] === 'force_terminated' ? 'Force Terminated' : 'Suspended';
            ?>
            <p class="okr-card-hint"><?php echo $suspend_log['event'] === 'force_terminated' ? 'This OKR was force terminated.' : 'This OKR has been suspended.'; ?></p>
            <div class="row g-3 mt-1 mb-2">
                <div class="col-md-6">
                    <label class="form-label"><?php echo htmlspecialchars($suspend_verb); ?> By</label>
                    <div style="font-size:13px;"><?php echo htmlspecialchars($suspend_log['actor_name'] ? $suspend_log['actor_name'] : 'Unknown'); ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo htmlspecialchars($suspend_verb); ?> On</label>
                    <div style="font-size:13px;"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($suspend_log['created_at']))); ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label">Reason</label>
                    <div class="okr-reason-box"><?php echo htmlspecialchars($suspend_reason_text); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($is_ceo_or_admin): ?>
            <div class="okr-form-error" id="okr-suspend-error"></div>
            <?php if ($card['is_suspended']): ?>
            <hr class="my-3">
            <p class="okr-card-hint">Unsuspending will lift the suspension - its Status stays as-is, it was never
                changed by suspending.</p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm" id="okr-unsuspend-btn">Unsuspend
                    OKR</button>
                <button type="button" class="btn btn-danger btn-sm" id="okr-force-terminate-btn">Force
                    Terminate</button>
            </div>
            <div id="okr-force-terminate-wrap" style="display:none;" class="mt-2">
                <label for="okr-force-terminate-remark" class="form-label">Remark <span class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-force-terminate-remark" rows="3"
                    placeholder="Why is this OKR being force terminated?"></textarea>
                <div class="okr-form-error" id="okr-force-terminate-remark-error"></div>
                <button type="button" class="btn btn-danger btn-sm mt-2"
                    id="okr-force-terminate-confirm-btn">Force Terminate OKR</button>
            </div>
            <?php elseif ($already_suspended_once && $is_completed_status): ?>
            <p class="okr-card-hint">This OKR has already been suspended once and cannot be suspended again. Force
                Terminate is the only action left.</p>
            <button type="button" class="btn btn-danger btn-sm" id="okr-force-terminate-btn">Force
                Terminate</button>
            <div id="okr-force-terminate-wrap" style="display:none;" class="mt-2">
                <label for="okr-force-terminate-remark" class="form-label">Remark <span class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-force-terminate-remark" rows="3"
                    placeholder="Why is this OKR being force terminated?"></textarea>
                <div class="okr-form-error" id="okr-force-terminate-remark-error"></div>
                <button type="button" class="btn btn-danger btn-sm mt-2"
                    id="okr-force-terminate-confirm-btn">Force Terminate OKR</button>
            </div>
            <?php elseif ($already_suspended_once): ?>
            <p class="okr-card-hint">This OKR has already been suspended once and cannot be suspended again. Force
                Terminate will be available once it's marked Completed.</p>
            <?php elseif (!$can_initiate_suspend): ?>
            <p class="okr-card-hint">Suspend is not available while this OKR is still a Draft.</p>
            <?php else: ?>
            <p class="okr-card-hint">Only the CEO can suspend an OKR.</p>
            <button type="button" class="btn btn-warning" id="okr-suspend-btn">Suspend
                OKR</button>
            <div id="okr-suspend-reason-wrap" style="display:none;" class="mt-2">
                <label for="okr-suspend-reason" class="form-label">Reason <span class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-suspend-reason" rows="3"
                    placeholder="Why is this OKR being suspended?"></textarea>
                <div class="okr-form-error" id="okr-suspend-reason-error"></div>
                <button type="button" class="btn btn-danger btn-sm mt-2" id="okr-suspend-confirm-btn">Suspend
                    OKR</button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="okr-card h-100" style="border-left:4px solid #0d6efd;">
            <h6 class="okr-card-title" style="color:#0d6efd;"><i class="bi bi-megaphone"></i> Appeal Suspension</h6>
            <?php foreach ($appeal_logs as $appeal_log):
                $appeal_reason_text = preg_replace('/^.*?:\s*/', '', $appeal_log['summary'], 1);
            ?>
            <p class="okr-card-hint">The issuer has appealed this suspension.</p>
            <div class="row g-3 mt-1 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Appealed By</label>
                    <div style="font-size:13px;"><?php echo htmlspecialchars($appeal_log['actor_name'] ? $appeal_log['actor_name'] : 'Unknown'); ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Appealed On</label>
                    <div style="font-size:13px;"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($appeal_log['created_at']))); ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label">Appeal Reason</label>
                    <div class="okr-reason-box"><?php echo htmlspecialchars($appeal_reason_text); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($can_appeal): ?>
            <p class="okr-card-hint">Explain why you believe this suspension should be reconsidered. This will be
                emailed to the person who suspended the card. You can only submit one appeal per suspension.</p>
            <div class="okr-form-error" id="okr-appeal-error"></div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="okr-appeal-btn">Appeal</button>
            <div id="okr-appeal-wrap" style="display:none;" class="mt-2">
                <label for="okr-appeal-justification" class="form-label">Justification <span
                        class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-appeal-justification" rows="3"
                    placeholder="Why should this suspension be reversed?"></textarea>
                <div class="okr-form-error" id="okr-appeal-justification-error"></div>
                <button type="button" class="btn btn-primary mt-2" id="okr-appeal-confirm-btn">Submit
                    Appeal</button>
            </div>
            <?php elseif ($latest_suspend_log && empty($appeal_logs)): ?>
            <p class="okr-card-hint">No appeal has been submitted for this OKR.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_suspend): ?>
<div class="modal fade" id="okr-suspend-modal" tabindex="-1" aria-labelledby="okr-suspend-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-suspend-modal-title">Suspend OKR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to suspend this OKR? This will change its status to Suspended.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="okr-suspend-final-btn">Yes, Suspend</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="okr-card mt-3" id="okr-chat-card">
    <h6 class="okr-card-title"><i class="bi bi-chat-dots"></i> Chat</h6>
    <p class="okr-card-hint">Shared discussion thread for this OKR's issuer, owner(s), and admins.</p>
    <div id="okr-chat-wrap" class="okr-chat-wrap"></div>
    <?php if ($can_post_chat): ?>
    <div class="okr-chat-composer" id="okr-chat-composer">
        <textarea class="form-control" id="okr-chat-input" rows="2" maxlength="4000"
            placeholder="Write a message..."></textarea>
        <div class="okr-form-error" id="okr-chat-error"></div>
        <div class="okr-chat-composer-actions">
            <button type="button" class="btn btn-primary btn-sm" id="okr-chat-send-btn">Send</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="okr-card mt-3">
    <h6 class="okr-card-title"><i class="bi bi-clock-history"></i> Audit Log</h6>
    <?php if (empty($audit_logs)): ?>
    <div class="okr-empty-state">No activity recorded yet.</div>
    <?php else: ?>
    <div class="okr-activity-list mt-2">
        <?php foreach ($audit_logs as $log): ?>
        <div class="okr-activity-row">
            <div class="okr-activity-summary"><?php echo htmlspecialchars($log['summary']); ?></div>
            <div class="okr-activity-meta">
                <?php echo htmlspecialchars($log['actor_name'] ? $log['actor_name'] : 'Unknown'); ?>
                &middot; <?php echo htmlspecialchars($log['created_at']); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add Reference Link modal -->
<div class="modal fade" id="okr-reflink-modal" tabindex="-1" aria-labelledby="okr-reflink-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-reflink-modal-title">Add Reference Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="reflink-name" class="form-label">Name <span class="okr-req">*</span></label>
                    <input type="text" class="form-control" id="reflink-name" placeholder="e.g. Trello Board">
                </div>
                <div class="mb-2">
                    <label for="reflink-url" class="form-label">URL <span class="okr-req">*</span></label>
                    <input type="url" class="form-control" id="reflink-url" placeholder="https://trello.com/...">
                </div>
                <div class="okr-form-error" id="reflink-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="reflink-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Key Result / Subtask modal -->
<div class="modal fade" id="okr-kr-modal" tabindex="-1" aria-labelledby="okr-kr-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-kr-modal-title">Add Key Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="okr-kr-id" value="">
                <input type="hidden" id="okr-kr-parent-id" value="">
                <div class="mb-3">
                    <label for="okr-kr-desc" class="form-label">Action <span class="okr-req">*</span></label>
                    <textarea class="form-control" id="okr-kr-desc" rows="3"></textarea>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="okr-kr-start" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="okr-kr-start">
                    </div>
                    <div class="col-6">
                        <label for="okr-kr-end" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="okr-kr-end">
                    </div>
                </div>
                <div class="mb-2" id="okr-kr-status-wrap">
                    <label for="okr-kr-status" class="form-label">Status</label>
                    <select class="form-select" id="okr-kr-status">
                        <?php foreach ($okr_config['keyResultStatuses'] as $st): ?>
                        <option value="<?php echo (int)$st['id']; ?>"><?php echo htmlspecialchars($st['value']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Created By</label>
                    <input type="text" class="form-control" id="okr-kr-created-by" readonly>
                </div>
                <div class="okr-form-error" id="okr-kr-modal-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="okr-kr-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Key Result / Subtask confirmation - offers to unlink or delete the
     ATEM too when one is linked (only ever shown for that case; a plain
     delete with no ATEM link still uses a simple confirm()). -->
<div class="modal fade" id="okr-kr-delete-modal" tabindex="-1" aria-labelledby="okr-kr-delete-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-kr-delete-modal-title">Delete this Key Result?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="okr-kr-delete-modal-message"></p>
                <div id="okr-kr-delete-atem-wrap" style="display:none;">
                    <label for="okr-kr-delete-remark" class="form-label">Reason for deleting the ATEM <span class="okr-req">*</span></label>
                    <textarea class="form-control" id="okr-kr-delete-remark" rows="2"></textarea>
                </div>
                <div class="okr-form-error" id="okr-kr-delete-modal-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="okr-kr-delete-only-btn">Delete Key Result &amp; Unlink ATEM</button>
                <button type="button" class="btn btn-danger" id="okr-kr-delete-atem-btn" style="display:none;">Delete Key Result &amp; ATEM</button>
            </div>
        </div>
    </div>
</div>

<!-- Link ATEM modal: Search Existing ATEM / Create New ATEM (mirrors atem/create.php's own fields) -->
<div class="modal fade" id="okr-kr-atem-modal" tabindex="-1" aria-labelledby="okr-kr-atem-modal-title"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-kr-atem-modal-title">Link ATEM</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="okr-kr-atem-target-id" value="">

                <!-- Search Existing ATEM -->
                <div class="mb-2">
                    <label for="okr-kr-atem-search" class="form-label">Search ATEM cards</label>
                    <input type="text" class="form-control" id="okr-kr-atem-search" placeholder="Search by title or ATEM ID...">
                </div>
                <div id="okr-kr-atem-list" class="okr-kr-atem-list"></div>
                <div class="okr-form-error" id="okr-kr-atem-modal-error"></div>

                <div class="okr-kr-atem-divider">or</div>
                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="okr-kr-atem-create-toggle">+
                    Create New ATEM</button>

                <!-- Create New ATEM (mirrors atem/create.php exactly) -->
                <div id="okr-kr-atem-create-wrap" style="display:none;" class="mt-3">
                    <?php if ($atemcreate_config['apiUnavailable']): ?>
                    <div class="alert alert-warning" role="alert" style="font-size:13px;">
                        The ATEM service is not reachable, so creating a new ATEM is disabled.
                        Please ensure the atem-api service is running, then reload this page.
                    </div>
                    <?php endif; ?>

                    <div class="atem-bento">

                        <!-- ATEM Type -->
                        <div class="atem-bento-item atem-span-12">
                            <div class="atem-card">
                                <h6 class="atem-card-title"><i class="bi bi-person-badge"></i> ATEM Type</h6>
                                <p class="atem-card-hint">Select who this ATEM is being issued for.</p>
                                <div class="atem-staff-type-options">
                                    <button type="button" class="atem-staff-type-btn" id="staff-type-hq" data-type="hq">
                                        <i class="bi bi-building"></i>
                                        <span class="atem-staff-type-label">HQ ATEM</span>
                                    </button>
                                    <button type="button" class="atem-staff-type-btn" id="staff-type-outlet"
                                        data-type="outlet">
                                        <i class="bi bi-shop"></i>
                                        <span class="atem-staff-type-label">Outlet ATEM</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ATEM Details -->
                        <div class="atem-bento-item atem-span-8" id="atem-details-section">
                            <div class="atem-card h-100">
                                <h6 class="atem-card-title"><i class="bi bi-file-earmark-text"></i> ATEM Details</h6>
                                <p class="atem-card-hint">Fields marked <span class="atem-req">*</span> are required.</p>
                                <div class="row g-3 mt-1">
                                    <div class="col-12">
                                        <label for="atem-title" class="form-label">ATEM Title <span
                                                class="atem-req">*</span></label>
                                        <input type="text" class="form-control" id="atem-title"
                                            placeholder="Short, searchable title">
                                        <div class="atem-form-error" id="atem-title-error"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Issuer</label>
                                        <input type="text" class="form-control" id="atem-issuer"
                                            value="<?php echo htmlspecialchars($nama_staff ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Department</label>
                                        <input type="text" class="form-control" id="atem-department"
                                            value="<?php echo htmlspecialchars($atemcreate_issuer_department); ?>" readonly>
                                    </div>
                                    <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-reward-label-group">
                                        <label for="atem-reward-label" class="form-label">Reward</label>
                                        <select class="form-select" id="atem-reward-label">
                                            <option value="" selected>None</option>
                                            <?php foreach ($atemcreate_lookups['reward_masterlist'] as $_rm): ?>
                                            <option value="<?php echo htmlspecialchars((string)$_rm['reward_value']); ?>">
                                                <?php echo htmlspecialchars((string)$_rm['reward_value']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="atem-form-error" id="atem-reward-label-error"></div>
                                    </div>
                                    <div class="col-md-6 atem-hq-only" id="atem-level-group">
                                        <label for="atem-level" class="form-label">ATEM Complexity Level<span
                                                class="atem-req">*</span></label>
                                        <select class="form-select" id="atem-level">
                                            <option value="">Select level</option>
                                        </select>
                                        <div class="atem-form-error" id="atem-level-error"></div>
                                    </div>
                                    <div class="col-md-6 atem-hq-only" id="atem-rule-group">
                                        <label for="atem-rule" class="form-label">Incentive Rule <span
                                                class="atem-req" id="rule-req-star" style="display:none;">*</span></label>
                                        <select class="form-select" id="atem-rule">
                                            <option value="">Select rule</option>
                                        </select>
                                        <div class="atem-form-error" id="atem-rule-error"></div>
                                    </div>
                                    <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-pillars-group">
                                        <label for="atem-pillars" class="form-label">5 Pillars</label>
                                        <select class="form-select" id="atem-pillars">
                                            <option value="">Select pillar</option>
                                        </select>
                                        <div class="atem-form-error" id="atem-pillars-error"></div>
                                    </div>
                                    <div class="col-12 atem-outlet-only atem-hidden" id="atem-am-tag-group">
                                        <label class="form-label">Outlet Staff(s) <span class="atem-req">*</span></label>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <div class="atem-outlet-picker" id="atem-am-picker-wrap">
                                                    <div class="atem-outlet-picker-btn" id="atem-am-picker-btn"
                                                        tabindex="0">Select outlet staff(s)...</div>
                                                    <div class="atem-outlet-picker-dropdown" id="atem-am-picker-dropdown">
                                                        <div class="atem-outlet-picker-search-wrap">
                                                            <input class="atem-outlet-picker-search"
                                                                id="atem-am-picker-search" type="search"
                                                                placeholder="Search outlet staff...">
                                                        </div>
                                                        <ul class="atem-outlet-picker-list" id="atem-am-picker-list"></ul>
                                                    </div>
                                                </div>
                                                <div class="atem-form-error" id="atem-am-error"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div id="atem-am-tags" class="atem-outlet-tags">
                                                    <span class="atem-empty-state">No outlet staff tagged.</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="tl-start" class="form-label">Start Date <span
                                                class="atem-req">*</span></label>
                                        <input type="date" class="form-control" id="tl-start">
                                        <div class="atem-form-error" id="tl-start-error"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="tl-end" class="form-label">End Date <span
                                                class="atem-req">*</span></label>
                                        <input type="date" class="form-control" id="tl-end">
                                        <div class="atem-form-error" id="tl-end-error"></div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <label class="form-label">ATEM Description</label>
                                        <div id="atem-description-editor"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right column: Incentive (live) + Attachment + Reference Link -->
                        <div class="atem-bento-item atem-span-4">
                            <div class="atem-card mb-3" id="atem-incentive-section">
                                <h6 class="atem-card-title"><i class="bi bi-cash-coin"></i> Estimated Incentive</h6>
                                <p class="atem-card-hint">This shows an estimated incentive based on the selected level
                                    and rule. The company reserves the right to determine the final payout under its
                                    incentive scheme. C and I roles are not incentivised.</p>
                                <div class="atem-incentive">
                                    <div class="atem-incentive-total-block">
                                        <div class="atem-incentive-total-label">Total Incentive</div>
                                        <div class="atem-incentive-total-amount" id="inc-total">RM0.00</div>
                                    </div>
                                    <div class="atem-incentive-breakdown">
                                        <div class="atem-incentive-stat">
                                            <span class="atem-incentive-stat-label">Base</span>
                                            <span class="atem-incentive-stat-value" id="inc-base">RM0.00</span>
                                        </div>
                                        <div class="atem-incentive-stat">
                                            <span class="atem-incentive-stat-label">A &middot; Accountable</span>
                                            <span class="atem-incentive-stat-value" id="inc-a">RM0.00</span>
                                        </div>
                                        <div class="atem-incentive-stat">
                                            <span class="atem-incentive-stat-label" id="inc-r-label">R &middot;
                                                Responsible</span>
                                            <span class="atem-incentive-stat-value" id="inc-r">RM0.00</span>
                                        </div>
                                    </div>
                                    <div class="atem-incentive-note" id="inc-note">
                                        Select an ATEM Complexity Leveland rule to calculate incentive. C and I are not
                                        incentivised.
                                    </div>
                                </div>
                            </div>

                            <!-- Attachment -->
                            <div class="atem-card mb-3">
                                <h6 class="atem-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
                                <p class="atem-card-hint">Upload supporting files (max 10MB each). Stored with this
                                    ATEM.</p>
                                <div id="atem-dropzone" class="atem-dropzone">
                                    <input type="file" id="atem-file-input" multiple
                                        accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                                    <div class="atem-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a
                                            href="#" id="atem-file-pick">click to select</a></div>
                                    <small class="atem-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF,
                                        Word, Excel, Text</small>
                                </div>
                                <div class="atem-form-error" id="atem-file-error"></div>
                                <div id="atem-file-list" class="atem-file-list mt-2">
                                    <div class="atem-empty-state">No files attached.</div>
                                </div>
                            </div>

                            <!-- Reference Link -->
                            <div class="atem-card">
                                <div class="atem-card-title-row">
                                    <h6 class="atem-card-title"><i class="bi bi-link-45deg"></i> Reference Link <span
                                            class="atem-req">*</span></h6>
                                    <button type="button" class="btn btn-primary btn-sm"
                                        id="atem-add-reflink-btn">Add Reference Link</button>
                                </div>
                                <p class="atem-card-hint">Add named links to related documents or resources.</p>
                                <div id="atem-reflink-list" class="atem-reflink-list">
                                    <div class="atem-empty-state">No Reference Link added.</div>
                                </div>
                                <div class="atem-form-error" id="atem-reflink-section-error"></div>
                            </div>
                        </div>

                        <!-- ARCI -->
                        <div class="atem-bento-item atem-span-12">
                            <div class="atem-card">
                                <h6 class="atem-card-title"><i class="bi bi-people"></i> Project Team (ARCI)</h6>
                                <p class="atem-card-hint">Tag the team. A (Accountable) supports up to 2 members. R
                                    (Responsible) supports up to 2 members. C and I are for visibility only and are
                                    not incentivised.</p>

                                <div class="alert alert-warning atem-hidden" id="atem-arci-orphan-warning" role="alert">
                                    <span id="atem-arci-orphan-warning-text"></span>
                                    <button type="button" class="btn-close" id="atem-arci-orphan-warning-close"
                                        aria-label="Close"></button>
                                </div>

                                <div class="atem-arci-add">
                                    <div class="atem-arci-add-grid">
                                        <div>
                                            <label class="form-label">Role</label>
                                            <select class="form-select" id="arci-role">
                                                <option value="">Select role</option>
                                                <option value="A">A - Accountable</option>
                                                <option value="R">R - Responsible</option>
                                                <option value="C">C - Consulted</option>
                                                <option value="I">I - Informed</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label" id="arci-dept-label">Department</label>
                                            <div class="atem-outlet-only atem-hidden mb-1" id="arci-scope-toggle">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="arci-scope"
                                                        id="arci-scope-outlet" checked>
                                                    <label class="form-check-label" for="arci-scope-outlet">Outlet</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="arci-scope"
                                                        id="arci-scope-department">
                                                    <label class="form-check-label"
                                                        for="arci-scope-department">Department</label>
                                                </div>
                                            </div>
                                            <input type="text" class="form-control mb-1" id="arci-dept-search"
                                                placeholder="Search department...">
                                            <select class="form-select" id="arci-dept-select" size="6">
                                                <option value="">Select department</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label">Staff</label>
                                            <input type="text" class="form-control mb-1" id="arci-staff-search"
                                                placeholder="Search staff...">
                                            <div id="arci-staff-list" class="atem-arci-staff-list">
                                                <div class="text-muted" style="font-size:13px;">Select a department to
                                                    load staff</div>
                                            </div>
                                            <button type="button" class="btn btn-primary btn-sm mt-2 w-100"
                                                id="arci-add-btn">Add Selected</button>
                                        </div>
                                    </div>
                                    <div class="atem-form-error" id="arci-error"></div>
                                </div>

                                <div class="atem-arci-grid" id="arci-grid">
                                    <?php
                                    $atemcreate_arci_roles = ['A' => 'Accountable', 'R' => 'Responsible', 'C' => 'Consulted', 'I' => 'Informed'];
                                    foreach ($atemcreate_arci_roles as $rkey => $rlabel):
                                    ?>
                                    <div class="atem-arci-col">
                                        <div class="atem-arci-col-head">
                                            <span><strong><?php echo $rkey; ?></strong> - <?php echo $rlabel; ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-sm atem-arci-clear"
                                                data-role="<?php echo $rkey; ?>">Delete All</button>
                                        </div>
                                        <div class="atem-arci-members" data-role="<?php echo $rkey; ?>">
                                            <div class="atem-arci-empty">No members assigned</div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="okr-form-error mt-2" id="atem-save-error"></div>
                    <button type="button" class="btn btn-primary btn-sm w-100 mt-2" id="okr-kr-atem-create-save-btn"
                        <?php echo $atemcreate_config['apiUnavailable'] ? 'disabled' : ''; ?>>Create &amp; Link</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Reference Link modal (Create New ATEM pane) - top-level sibling, not
     nested inside okr-kr-atem-modal's own DOM, so Bootstrap can stack it on
     top rather than fighting the parent modal's backdrop/scroll-lock. -->
<div class="modal fade" id="atem-reflink-modal" tabindex="-1" aria-labelledby="atem-reflink-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="atem-reflink-modal-title">Add Reference Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="atem-reflink-name" class="form-label">Name <span class="atem-req">*</span></label>
                    <input type="text" class="form-control" id="atem-reflink-name" placeholder="Enter reference name">
                </div>
                <div class="mb-2">
                    <label for="atem-reflink-url" class="form-label">URL <span class="atem-req">*</span></label>
                    <input type="url" class="form-control" id="atem-reflink-url" placeholder="https://example.com">
                </div>
                <div class="atem-form-error" id="atem-reflink-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="atem-reflink-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Removal confirmation modal (Create New ATEM pane) -->
<div class="modal fade" id="atem-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="atem-confirm-message">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="atem-confirm-ok">Remove</button>
            </div>
        </div>
    </div>
</div>

<!-- ATEM Type switch warning modal (Create New ATEM pane) -->
<div class="modal fade" id="atem-type-switch-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This ATEM already has data filled in. Changing the ATEM Type requires resetting the
                    form first - all entered fields, tags, and attachments will be cleared.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="atem-type-switch-reset-btn">Reset Form</button>
            </div>
        </div>
    </div>
</div>

<script>
var OKR_EDIT_CONFIG = <?php echo json_encode($okr_config); ?>;
var ATEM_CREATE_CONFIG = <?php echo json_encode($atemcreate_config); ?>;
</script>
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<?php
$page_js = 'okr/js/edit.js';
include('footer.php');
?>