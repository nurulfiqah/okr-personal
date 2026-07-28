<?php
$page_title = 'New OKR';
include('header.php');
require_once(__DIR__ . '/lib.php');

if ($okr_permission < 3 && !$okr_is_admin) {
    header('Location: /odb/okr/list.php');
    exit;
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
                <div class="col-md-4">
                    <label for="okr-type" class="form-label">OKR Type <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-type">
                        <option value="">Select type</option>
                        <?php foreach (okrFetchTypes($conn, false) as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['value']); ?>"><?php echo htmlspecialchars($t['value']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="okr-form-error" id="okr-type-error"></div>
                </div>
                <div class="col-md-4">
                    <label for="okr-start" class="form-label">Start Date <span class="okr-req">*</span></label>
                    <input type="date" class="form-control" id="okr-start">
                    <div class="okr-form-error" id="okr-start-error"></div>
                </div>
                <div class="col-md-4">
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
            <div class="okr-kr-header">
                <h6 class="okr-card-title mb-0"><i class="bi bi-list-task"></i> Key Result Progress</h6>
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

<!-- Link ATEM modal - picks an existing card from the real ATEM module -->
<div class="modal fade" id="okr-kr-atem-modal" tabindex="-1" aria-labelledby="okr-kr-atem-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-kr-atem-modal-title">Link ATEM</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="okr-kr-atem-target-token" value="">
                <div class="mb-2">
                    <label for="okr-kr-atem-search" class="form-label">Search ATEM cards</label>
                    <input type="text" class="form-control" id="okr-kr-atem-search" placeholder="Search by title...">
                </div>
                <div id="okr-kr-atem-list" class="okr-kr-atem-list"></div>
                <div class="okr-form-error" id="okr-kr-atem-modal-error"></div>

                <div class="okr-kr-atem-divider">or</div>
                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="okr-kr-atem-create-toggle">+
                    Create New ATEM</button>
                <div id="okr-kr-atem-create-wrap" style="display:none;" class="mt-2">
                    <div class="mb-2">
                        <label for="okr-kr-atem-create-desc" class="form-label">Action Details <span
                                class="okr-req">*</span></label>
                        <textarea class="form-control" id="okr-kr-atem-create-desc" rows="3"></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label for="okr-kr-atem-create-start" class="form-label">Start Date <span
                                    class="okr-req">*</span></label>
                            <input type="date" class="form-control" id="okr-kr-atem-create-start">
                        </div>
                        <div class="col-6">
                            <label for="okr-kr-atem-create-end" class="form-label">End Date <span
                                    class="okr-req">*</span></label>
                            <input type="date" class="form-control" id="okr-kr-atem-create-end">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="okr-kr-atem-create-dept" class="form-label">Department <span
                                class="okr-req">*</span></label>
                        <select class="form-select" id="okr-kr-atem-create-dept">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="okr-kr-atem-create-staff" class="form-label">PIC (Assigned Staff) <span
                                class="okr-req">*</span></label>
                        <select class="form-select" id="okr-kr-atem-create-staff">
                            <option value="">Select department first</option>
                        </select>
                    </div>
                    <div class="okr-form-error" id="okr-kr-atem-create-error"></div>
                    <button type="button" class="btn btn-primary btn-sm w-100 mt-2"
                        id="okr-kr-atem-create-save-btn">Create &amp; Link</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
var OKR_CONFIG = <?php echo json_encode($okr_config); ?>;
</script>
<?php
$page_js = 'okr/js/create.js';
include('footer.php');
?>