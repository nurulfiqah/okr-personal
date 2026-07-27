<?php
require_once(__DIR__ . '/lib.php');

$card_id = (int)($_GET['id'] ?? 0);

$page_title = 'Edit OKR';
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
// Suspended is locked pending Unsuspend/Force Terminate (see view.php's CEO
// Action). Failed is a terminal outcome (including force-terminated cards,
// which are just Failed + a flag - see backend.php's forceTerminateCard) and
// is locked for everyone, admins included, same as Suspended.
if (!$can_edit || $card['result_status'] === OKR_STATUS_SUSPENDED || $card['result_status'] === 'Failed') {
    header('Location: /odb/okr/view.php?id=' . $card_id);
    exit;
}

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

$attachments     = okrFetchAttachments($conn, $card_id);
$reference_links = okrFetchReferenceLinks($conn, $card_id);
$audit_logs      = okrFetchAuditLogs($conn, $card_id);
$chat_messages   = okrFetchChatMessages($conn, $card_id);
$can_post_chat   = okrCanPostChat($card, $id_user, $okr_is_admin);

// Timeline dropdown options: every assignable status except Suspended /
// Completed with Extension (system-managed, see okrTimelineAssignableStatuses).
$all_statuses = okrFetchStatuses($conn, false);
$timeline_status_options = array_values(array_filter($all_statuses, function ($s) {
    return $s['value'] !== OKR_STATUS_SUSPENDED && $s['value'] !== OKR_STATUS_COMPLETED_EXTENSION;
}));

$okr_config = [
    'apiUrl'          => 'okr/backend.php',
    'atemApiUrl'      => $_navbar_atem_folder . '/api.php',
    'atemViewUrl'     => $_navbar_atem_folder . '/edit.php',
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
                <div class="col-md-6">
                    <label for="okr-type" class="form-label">OKR Type <span class="okr-req">*</span></label>
                    <select class="form-select" id="okr-type">
                        <?php
                        $okr_type_options = okrTypeValues($conn, false);
                        if (!in_array($card['okr_type'], $okr_type_options, true)) {
                            $okr_type_options[] = $card['okr_type'];
                        }
                        foreach ($okr_type_options as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"
                            <?php echo ($card['okr_type'] === $t) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="okr-form-error" id="okr-type-error"></div>
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
                            $visible = !$card['extended'] || $okr_is_admin || in_array($value, $post_extension_statuses, true);
                            if (!$visible) { continue; }
                            $is_selected = ($card['result_status'] === $value)
                                || ($value === OKR_STATUS_COMPLETED && $card['result_status'] === OKR_STATUS_COMPLETED_EXTENSION);
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
                    <?php elseif ($card['extended']): ?>
                    <p class="okr-card-hint">This OKR has been extended. As an admin, you can still set any status.</p>
                    <?php endif; ?>
                    <div class="okr-form-error" id="okr-status-error"></div>
                </div>

                <div class="col-12">
                    <div class="form-check okr-extended-check mb-2">
                        <input class="form-check-input" type="checkbox" id="okr-extended"
                            <?php echo $card['extended'] ? 'checked disabled' : ''; ?>>
                        <label class="form-check-label" for="okr-extended" style="font-size: 12px;">Extended? (once only
                            &mdash; cannot be undone)</label>
                    </div>
                </div>

                <div class="col-md-4" id="okr-extended-date-wrap"
                    style="<?php echo $card['extended'] ? '' : 'display:none;'; ?>">
                    <label for="okr-extended-date" class="form-label">Extended Date <span class="okr-req"
                            id="okr-extended-date-req"
                            style="<?php echo $card['extended'] ? '' : 'display:none;'; ?>">*</span></label>
                    <input type="date" class="form-control" id="okr-extended-date"
                        value="<?php echo htmlspecialchars($card['extended_date'] ?? ''); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label for="okr-final-due" class="form-label">Final Due Date</label>
                    <input type="date" class="form-control" id="okr-final-due"
                        value="<?php echo htmlspecialchars($card['final_due_date']); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label for="okr-closure" class="form-label">Closure Date</label>
                    <input type="date" class="form-control" id="okr-closure"
                        value="<?php echo htmlspecialchars($card['closure_date'] ?? ''); ?>" disabled>
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

<div class="okr-card mt-3">
    <div class="okr-kr-header">
        <h6 class="okr-card-title mb-0"><i class="bi bi-list-task"></i> Key Result Progress</h6>
        <button type="button" class="btn btn-primary btn-sm" id="okr-kr-add-btn">Add Key Result</button>
    </div>
    <p class="okr-card-hint">Break this OKR down into Key Results, and optionally split a Key Result into
        subtasks. A Key Result with subtasks shows its progress as the average of those subtasks.</p>
    <div id="okr-kr-list" class="okr-kr-list"></div>
    <div class="okr-form-error" id="okr-kr-error"></div>
</div>

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
    <h6 class="okr-card-title"><i class="bi bi-clock-history"></i> Activity Log</h6>
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
                <input type="hidden" id="okr-kr-atem-target-id" value="">
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
var OKR_EDIT_CONFIG = <?php echo json_encode($okr_config); ?>;
</script>
<?php
$page_js = 'okr/js/edit.js';
include('footer.php');
?>