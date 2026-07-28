<?php
$page_title = 'New OKR';

// The Link ATEM modal's "Create New ATEM" pane mirrors atem/create.php's own
// fields exactly, so it needs atem/css/style.css (the .atem-* classes) and
// the Quill rich-text editor atem/create.php uses for its Description field.
$extra_css = '<link href="atem/css/style.css?v=' . time() . '" rel="stylesheet">'
    . '<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">';
include('header.php');
require_once(__DIR__ . '/lib.php');

if ($okr_permission < 3 && !$okr_is_admin) {
    header('Location: /odb/okr/list.php');
    exit;
}

// Eagerly creates (or reuses) a real Draft-status okr_cards row the moment
// this page opens, so the in-progress OKR has a stable id immediately - see
// okrEnsureDraftCard() in lib.php for why (the Link ATEM modal's "Create New
// ATEM" pane needs a real id to link back to). 0 means the draft insert
// failed - the page still works, the ATEM pane just won't offer the "add
// this OKR" reference link in that edge case.
$draft_card_id = okrEnsureDraftCard($conn, $id_user, $department ?? '');

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

$issuer_name  = isset($nama_staff) ? $nama_staff : '';
$issuer_department = '';
if (isset($department) && $department !== '') {
    $dept_ids = okrDeptIdsFromCsv($department);
    if (!empty($dept_ids)) {
        $dept_lookup = mysqli_query($conn, 'SELECT depart_name FROM staff_department WHERE id = ' . (int)$dept_ids[0] . ' LIMIT 1');
        if ($dept_lookup && mysqli_num_rows($dept_lookup) > 0) {
            $issuer_department = mysqli_fetch_assoc($dept_lookup)['depart_name'];
        }
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
// $issuer_department computed above, since the two can legitimately differ.
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
        'name'            => $issuer_name,
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

// Hydrate any in-progress draft saved to the session (survives refresh).
// Reference links/attachments are already staged in the session by their own
// stage* actions regardless of okr_draft_state - included here so they don't
// go silently invisible after a refresh either.
$session_draft = is_array($_SESSION['okr_draft_state'] ?? null) ? $_SESSION['okr_draft_state'] : [];
$session_reflinks = [];
foreach (($_SESSION['okr_draft_reflinks'] ?? []) as $_token => $_link) {
    $session_reflinks[] = ['token' => $_token, 'name' => $_link['name'], 'url' => $_link['url']];
}
$session_attachments = [];
foreach (($_SESSION['okr_draft_files'] ?? []) as $_token => $_file) {
    $session_attachments[] = ['token' => $_token, 'name' => $_file['original_name'], 'size' => (int)$_file['size']];
}
$key_result_statuses = okrKeyResultAssignableStatuses($conn);
$key_result_status_values = array_column($key_result_statuses, 'value', 'id');
$session_key_results = [];
foreach (($_SESSION['okr_draft_keyresults'] ?? []) as $_token => $_kr) {
    $_subtasks = [];
    foreach (($_kr['subtasks'] ?? []) as $_sub_token => $_sub) {
        $_sub_status_id = (int)($_sub['status_id'] ?? 0);
        $_sub_status_value = $key_result_status_values[$_sub_status_id] ?? 'Active';
        $_subtasks[] = [
            'token'         => $_sub_token,
            'description'   => $_sub['description'],
            'creator_name'  => $issuer_name,
            'start_date'    => $_sub['start_date'],
            'end_date'      => $_sub['end_date'],
            'status_id'     => $_sub_status_id,
            'status_value'  => $_sub_status_value,
            'pill_class'    => okrPillClass($_sub_status_value),
        ];
    }
    $_kr_status_id = (int)($_kr['status_id'] ?? 0);
    $_kr_status_value = $key_result_status_values[$_kr_status_id] ?? 'Active';
    $session_key_results[] = [
        'token'         => $_token,
        'description'   => $_kr['description'],
        'creator_name'  => $issuer_name,
        'atem_id'       => !empty($_kr['atem_id']) ? (int)$_kr['atem_id'] : null,
        'start_date'    => $_kr['start_date'],
        'end_date'      => $_kr['end_date'],
        'status_id'     => $_kr_status_id,
        'status_value'  => $_kr_status_value,
        'pill_class'    => okrPillClass($_kr_status_value),
        'subtasks'      => $_subtasks,
    ];
}
$session_draft['reflinks'] = $session_reflinks;
$session_draft['attachments'] = $session_attachments;
$session_draft['keyResults'] = $session_key_results;

$okr_config = [
    'apiUrl'          => 'okr/backend.php',
    'atemApiUrl'      => $_navbar_atem_folder . '/api.php',
    'atemViewUrl'     => $_navbar_atem_folder . '/edit.php',
    'staff'           => $staff_list,
    'departments'     => $departments,
    'backdateEnabled' => okrBackdateEnabled($conn),
    'currentUserName' => $issuer_name,
    'currentStaffId'  => (int)$id_user,
    'keyResultStatuses' => $key_result_statuses,
    'draft'           => $session_draft,
    'draftCardId'     => $draft_card_id,
    'okrViewUrl'      => 'okr/view.php',
];
?>

<div class="okr-bento">

    <div class="okr-bento-item okr-span-8">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-file-earmark-text"></i> OKR Details</h6>
            <p class="okr-card-hint">Fields marked <span class="okr-req">*</span> are required.</p>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label for="okr-objective" class="form-label">Objective <span class="okr-req">*</span></label>
                    <textarea class="form-control" id="okr-objective" rows="2" placeholder="The goal, written in full"></textarea>
                    <div class="okr-form-error" id="okr-objective-error"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Issuer</label>
                    <input type="text" class="form-control" id="okr-issuer"
                        value="<?php echo htmlspecialchars($issuer_name); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" id="okr-department"
                        value="<?php echo htmlspecialchars($issuer_department); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label for="okr-start" class="form-label">Start Date <span class="okr-req">*</span></label>
                    <input type="date" class="form-control" id="okr-start">
                    <div class="okr-form-error" id="okr-start-error"></div>
                </div>
                <div class="col-md-6">
                    <label for="okr-end" class="form-label">End Date <span class="okr-req">*</span></label>
                    <input type="date" class="form-control" id="okr-end">
                    <div class="okr-form-error" id="okr-end-error"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-4">
        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
            <p class="okr-card-hint">Upload supporting files (max 10MB each). Stored with this OKR.</p>
            <div id="okr-dropzone" class="okr-dropzone">
                <input type="file" id="okr-file-input" multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                <div class="okr-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a href="#"
                        id="okr-file-pick">click to select</a></div>
                <small class="okr-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF, Word, Excel, Text</small>
            </div>
            <div class="okr-form-error" id="okr-file-error"></div>
            <div id="okr-file-list" class="okr-file-list mt-2">
                <div class="okr-empty-state">No files attached.</div>
            </div>
        </div>

        <div class="okr-card mb-3">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-link-45deg"></i> Reference Link <span class="okr-req">*</span></h6>
                <button type="button" class="btn btn-primary btn-sm" id="okr-add-reflink-btn">Add Reference Link</button>
            </div>
            <p class="okr-card-hint">Add named links to related documents or resources (e.g. the OKR's Trello board).</p>
            <div id="okr-reflink-list" class="okr-reflink-list">
                <div class="okr-empty-state">No Reference Link added.</div>
            </div>
            <div class="okr-form-error" id="reflink-section-error"></div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-list-task"></i> Key Result Progress</h6>
                <button type="button" class="btn btn-primary btn-sm" id="okr-kr-add-btn">Add Key Result</button>
            </div>
            <p class="okr-card-hint">Break this OKR down into Key Results now if you'd like. Subtasks can be added
                once the OKR is saved.</p>
            <div class="okr-alert-notice mb-2" id="okr-kr-date-warning" style="display:none;">
                <i class="bi bi-exclamation-triangle"></i> Some Key Result dates fall outside this OKR's Start/End
                Date. Please update them to stay within range.
            </div>
            <div id="okr-kr-list" class="okr-kr-list">
                <div class="okr-kr-empty">No Key Results added yet.</div>
            </div>
            <div class="okr-form-error" id="okr-kr-error"></div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-people"></i> Owner(s)</h6>
            <p class="okr-card-hint">Tag the owner(s). A (Accountable) supports up to 2 members. A 2nd owner is only for jointly-run OKRs.</p>

            <div class="okr-arci-add">
                <div class="okr-arci-add-grid okr-arci-add-grid-3">
                    <div>
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control mb-1" id="okr-owner-dept-search" placeholder="Search department...">
                        <select class="form-select" id="okr-owner-dept-select" size="6">
                            <option value="">Select department</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Staff</label>
                        <input type="text" class="form-control mb-1" id="okr-owner-staff-search" placeholder="Search staff...">
                        <div id="okr-owner-staff-list" class="okr-arci-staff-list">
                            <div class="text-muted" style="font-size:13px;">Select a department to load staff</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 w-100" id="okr-owner-add-btn">Add Selected</button>
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

</div>

<div class="okr-save-error-wrap">
    <div class="okr-form-error" id="okr-save-error"></div>
</div>
<div class="okr-save-bar">
    <button type="button" class="btn btn-outline-secondary" id="okr-cancel-btn">Cancel</button>
    <button type="button" class="btn btn-primary" id="okr-save-btn">Save OKR</button>
</div>

<!-- Leave / cancel confirmation modal -->
<div class="modal fade" id="okr-leave-modal" tabindex="-1" aria-labelledby="okr-leave-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-leave-modal-title">Leave this OKR?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This OKR has not been saved. Do you want to cancel it, or keep it as a draft?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Continue editing</button>
                <button type="button" class="btn btn-danger" id="okr-leave-cancel">Cancel OKR</button>
                <button type="button" class="btn btn-primary" id="okr-leave-draft">Save as draft</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Reference Link modal -->
<div class="modal fade" id="okr-reflink-modal" tabindex="-1" aria-labelledby="okr-reflink-modal-title" aria-hidden="true">
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

<!-- Add Key Result modal (top-level only - subtasks are added after saving, via edit.php) -->
<div class="modal fade" id="okr-kr-modal" tabindex="-1" aria-labelledby="okr-kr-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-kr-modal-title">Add Key Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="okr-kr-token" value="">
                <input type="hidden" id="okr-kr-parent-token" value="">
                <div class="mb-3">
                    <label for="okr-kr-desc" class="form-label">Action Details <span class="okr-req">*</span></label>
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
                <div class="mb-2">
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
                <input type="hidden" id="okr-kr-atem-target-token" value="">

                <!-- Search Existing ATEM -->
                <div class="mb-2">
                    <label for="okr-kr-atem-search" class="form-label">Search ATEM cards</label>
                    <input type="text" class="form-control" id="okr-kr-atem-search" placeholder="Search by title...">
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
                                            value="<?php echo htmlspecialchars($issuer_name); ?>" readonly>
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
var OKR_CONFIG = <?php echo json_encode($okr_config); ?>;
var ATEM_CREATE_CONFIG = <?php echo json_encode($atemcreate_config); ?>;
</script>
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<?php
$page_js = 'okr/js/create.js';
include('footer.php');
?>