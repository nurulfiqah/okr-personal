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

$levels = [];
$level_res = mysqli_query($conn, 'SELECT level, label, rubric_text, base_rm FROM okr_levels ORDER BY level');
if ($level_res) {
    while ($lrow = mysqli_fetch_assoc($level_res)) {
        $levels[] = [
            'level'       => (int)$lrow['level'],
            'label'       => $lrow['label'],
            'rubric_text' => $lrow['rubric_text'],
            'base_rm'     => (float)$lrow['base_rm'],
        ];
    }
}

$incentive_rules = [];
$rule_res = mysqli_query($conn, 'SELECT id, code, label, payout_logic FROM okr_incentive_rules ORDER BY id');
if ($rule_res) {
    while ($rrow = mysqli_fetch_assoc($rule_res)) {
        $incentive_rules[] = [
            'id'           => (int)$rrow['id'],
            'code'         => $rrow['code'],
            'label'        => $rrow['label'],
            'payout_logic' => $rrow['payout_logic'],
        ];
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
$session_draft['reflinks'] = $session_reflinks;
$session_draft['attachments'] = $session_attachments;

$okr_config = [
    'apiUrl'          => 'okr/backend.php',
    'staff'           => $staff_list,
    'departments'     => $departments,
    'levels'          => $levels,
    'incentiveRules'  => $incentive_rules,
    'backdateEnabled' => okrBackdateEnabled($conn),
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
                <div class="col-md-6">
                    <label for="okr-type" class="form-label">OKR Type <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-type">
                        <option value="">Select type</option>
                        <?php foreach (okrFetchTypes($conn, false) as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['value']); ?>"><?php echo htmlspecialchars($t['value']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="okr-form-error" id="okr-type-error"></div>
                </div>
                <div class="col-md-6">
                    <label for="okr-level" class="form-label">OKR Complexity Level <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-level">
                        <option value="">Select level</option>
                    </select>
                    <div class="okr-form-error" id="okr-level-error"></div>
                </div>
                <div class="col-md-6" id="okr-incentive-rule-wrap">
                    <label for="okr-incentive-rule" class="form-label">Incentive Rule <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-incentive-rule">
                        <option value="">Select rule</option>
                    </select>
                    <div class="okr-form-error" id="okr-incentive-rule-error"></div>
                </div>
                <div class="col-md-6 d-flex flex-column">
                    <label class="form-label invisible">Incentive Rule</label>
                    <div class="d-flex align-items-center okr-incentive-rule-hint-row">
                        <p class="okr-card-hint mb-0" id="okr-incentive-rule-hint">Select an incentive rule to see how the payout will be split.</p>
                    </div>
                    <div class="okr-form-error"></div>
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
                <div class="col-12">
                    <label class="form-label">Key Results <span class="okr-req">*</span></label>
                    <div id="okr-key-results-editor"></div>
                    <div class="okr-form-error" id="okr-key-results-error"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-4">
        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-cash-coin"></i> Estimated Incentive</h6>
            <p class="okr-card-hint" id="okr-level-rubric">Select a difficulty level to see its rubric and RM.</p>
            <div class="okr-incentive-tile okr-incentive-tile--blue mt-2">
                <div class="okr-incentive-tile-label">Estimated Incentive</div>
                <div class="okr-incentive-tile-value" id="okr-level-rm">RM0.00</div>
            </div>
            <div class="okr-incentive-breakdown" id="okr-incentive-breakdown" style="display:none;">
                <div class="okr-incentive-stat" id="okr-incentive-stat1">
                    <span class="okr-incentive-stat-label" id="okr-incentive-stat1-label">1st Owner</span>
                    <span class="okr-incentive-stat-value" id="okr-incentive-stat1-value">RM0.00</span>
                </div>
                <div class="okr-incentive-stat" id="okr-incentive-stat2" style="display:none;">
                    <span class="okr-incentive-stat-label" id="okr-incentive-stat2-label">2nd Owner</span>
                    <span class="okr-incentive-stat-value" id="okr-incentive-stat2-value">RM0.00</span>
                </div>
            </div>
            <p class="okr-card-hint mt-2">This shows an estimated incentive based on the selected level and rule. The company reserves the right to determine the final payout under its incentive scheme.</p>
        </div>

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
            <h6 class="okr-card-title"><i class="bi bi-people"></i> Owner(s)</h6>
            <p class="okr-card-hint">Tag the owner(s). A (Accountable) supports up to 2 members. A 2nd owner is only for jointly-run OKRs.</p>

            <div class="okr-arci-add">
                <div class="okr-arci-add-grid">
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
                </div>
                <div class="okr-form-error" id="okr-owner-error"></div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <div class="okr-arci-col">
                        <div class="okr-arci-col-head">
                            <span><strong>A</strong> - Accountable (Owner)</span>
                        </div>
                        <div class="okr-arci-members" id="okr-owner-members">
                            <div class="okr-arci-empty">No owners assigned</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6" id="okr-owner2-purpose-wrap" style="display:none;">
                    <label for="okr-owner2-purpose" class="form-label">Purpose of joint ownership</label>
                    <input type="text" class="form-control" id="okr-owner2-purpose" placeholder="e.g. jointly run with trainee management team">
                    <div class="okr-form-error" id="okr-owner2-purpose-error"></div>
                </div>
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

<script>
var OKR_CONFIG = <?php echo json_encode($okr_config); ?>;
</script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<?php
$page_js = 'okr/js/create.js';
include('footer.php');
?>