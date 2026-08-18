<?php
require_once(__DIR__ . '/lib.php');

$card_id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

$page_title = 'View OKR';
include('header.php');

if ($card_id <= 0) {
    echo '<div class="okr-card"><p class="mb-0">Invalid OKR card.</p></div>';
    $page_js = '';
    include('footer.php');
    exit;
}

$scope_where = okrScopeWhere($id_user, $okr_permission, okrDeptIdsFromCsv(isset($department) ? $department : ''), $okr_is_admin);$result = mysqli_query($conn, okrCardSelectSql("c.id = $card_id AND ($scope_where)"));
if (!$result || mysqli_num_rows($result) === 0) {
    echo '<div class="okr-card"><p class="mb-0">This OKR card was not found or you do not have access to it.</p></div>';
    $page_js = '';
    include('footer.php');
    exit;
}

$row  = mysqli_fetch_assoc($result);
$card = okrFormatCard($row);

// Failed and Force Terminated are both terminal outcomes - there's nothing
// left to rate once an OKR has resolved either way, and nothing left to
// Force Terminate (it's already the same or a more final end state), but the
// CEO can still Suspend either to reconsider it (see below). Force
// Terminated is its own status now (see "Retired incentive columns"-style
// note on OKR_STATUS_FORCE_TERMINATED in lib.php), but older force-
// terminated cards are still stored as plain Failed + the legacy
// force_terminated flag - both shapes count as terminal here.
$is_terminal_failed = in_array($card['result_status'], ['Failed', OKR_STATUS_FORCE_TERMINATED], true);
$is_ceo_or_admin = ($okr_is_admin || $okr_permission === 5);
// Suspend no longer overwrites result_status (see backend.php's
// suspendCard) - $card['is_suspended'] is the source of truth for "is this
// card currently suspended", independent of whatever status it's showing.
// The CEO can suspend an OKR in any status except Draft - a Draft isn't a
// real, issued OKR yet, so there's nothing to suspend. Still subject to the
// one-suspend-per-lifetime rule below ($already_suspended_once), and can't
// suspend a card that's already suspended.
$can_suspend = $is_ceo_or_admin && $card['result_status'] !== OKR_STATUS_DRAFT && !$card['is_suspended'];
$is_completed_status = in_array($card['result_status'], okrCompletedStatusValues(), true);
$can_initiate_suspend = $can_suspend;
// Rate is still a post-completion review action - only available once the
// OKR has actually resolved as Completed in some form (see backend.php's
// rateCard, which enforces the same rule server-side).
$can_rate    = $is_ceo_or_admin && !$is_terminal_failed && $is_completed_status;
// Force Terminate keeps its own narrower gate (grade 5/admin, not Failed) -
// unlike Suspend above, it isn't opened up to every status; it's only ever
// reachable while currently suspended, or after a Completed OKR has already
// used its one lifetime Suspend (see backend.php's forceTerminateCard).
$can_force_terminate = $is_ceo_or_admin && !$is_terminal_failed;
// Only the issuer can appeal, only while currently suspended, only once per
// suspension cycle (appealed_at is cleared on unsuspend/force-terminate).
// Shown even when the issuer also happens to be admin/grade-5 (they still
// see it alongside the CEO Action card's direct Unsuspend/Force Terminate).
$can_appeal = ($card['issuer_staff_id'] === (int)$id_user
    && $card['is_suspended'] && empty($card['appealed_at']));

// Same edit gate as edit.php itself (issuer or admin, and not currently
// suspended/Failed/Force Terminated) - shown here so the user doesn't have
// to go back to list.php just to reach the Edit icon there.
$can_edit_card = ($okr_is_admin || $card['issuer_staff_id'] === (int)$id_user)
    && !$card['is_suspended'] && !$is_terminal_failed;

// Owner/Owner2 get inline Key Result Progress status editing, and
// Attachment/Reference Link add-remove, right here on view.php (they can't
// reach edit.php - that page is issuer/admin-only for every other field) -
// see okrCanCollaborateOnCard in lib.php. Locked the same way as
// $can_edit_card above.
$can_collaborate = okrCanCollaborateOnCard($card, $id_user, $okr_is_admin)
    && !$card['is_suspended'] && !$is_terminal_failed;

// Days left before an unattended suspended OKR is auto-terminated (30 days
// from the suspension timestamp) - shown as a countdown on the suspend
// notice banner.
$suspend_days_left = null;
if ($card['is_suspended'] && !empty($card['suspended_at'])) {
    $suspend_deadline  = strtotime($card['suspended_at']) + (30 * 24 * 60 * 60);
    $suspend_days_left = max(0, (int)floor(($suspend_deadline - time()) / 86400));
}

function okrStaffDeptNames($conn, $staff_id) {
    if (empty($staff_id)) {
        return [];
    }
    $names = [];
    $res = mysqli_query($conn, 'SELECT department FROM staff WHERE id = ' . (int)$staff_id);
    if ($res && ($srow = mysqli_fetch_assoc($res))) {
        $dept_ids = okrDeptIdsFromCsv($srow['department']);
        if (!empty($dept_ids)) {
            $dept_res = mysqli_query($conn, 'SELECT depart_name FROM staff_department WHERE id IN (' . implode(',', $dept_ids) . ')');
            if ($dept_res) {
                while ($drow = mysqli_fetch_assoc($dept_res)) {
                    $names[] = $drow['depart_name'];
                }
            }
        }
    }
    return $names;
}

$dept_names  = okrStaffDeptNames($conn, $card['owner_staff_id']);
$dept_names2 = okrStaffDeptNames($conn, $card['owner2_staff_id']);
// Details box's Department field pairs with Issuer (matches ATEM's edit.php
// convention, where Department next to Issuer is the issuer's own
// department) - kept separate from $dept_names above, which is the Owner's
// department shown in the ARCI-style member widget further down.
$issuer_dept_names = okrStaffDeptNames($conn, $card['issuer_staff_id']);

$attachments     = okrFetchAttachments($conn, $card_id);
$reference_links = okrFetchReferenceLinks($conn, $card_id);
$audit_logs      = okrFetchAuditLogs($conn, $card_id);

// The card row only holds the *current* suspend/appeal state (unsuspend and
// force-terminate both null out closed_by/closed_at/appeal fields), so once
// an OKR is unsuspended or force-terminated its suspend/appeal reason
// disappears from the card itself. The audit log keeps that history forever,
// so pull every relevant entry from there instead to show it regardless of
// the card's current status - every past suspend/force-terminate cycle stays
// visible, not just the latest one.
function okrAuditLogsByEvent($logs, $events) {
    $matches = [];
    foreach ($logs as $log) {
        if (in_array($log['event'], $events, true)) {
            $matches[] = $log;
        }
    }
    return $matches;
}
$suspend_logs = okrAuditLogsByEvent($audit_logs, ['suspended', 'force_terminated']);
$appeal_logs  = okrAuditLogsByEvent($audit_logs, ['appealed']);
$latest_suspend_log = !empty($suspend_logs) ? $suspend_logs[0] : null;

// An OKR can only ever be suspended once in its lifetime (see backend.php's
// suspendCard) - once it's had a 'suspended' event, the Suspend action is
// gone for good even after it returns to Active.
$already_suspended_once = !empty(okrAuditLogsByEvent($audit_logs, ['suspended']));

$key_results     = okrFetchKeyResults($conn, $card_id);
$chat_messages   = okrFetchChatMessages($conn, $card_id);
$can_post_chat   = okrCanPostChat($card, $id_user, $okr_is_admin);

$okr_view_config = [
    'apiUrl'          => 'okr/backend.php',
    'atemApiUrl'      => $_navbar_atem_folder . '/api.php',
    'atemViewUrl'     => $_navbar_atem_folder . '/edit.php',
    'card'            => $card,
    'attachments'     => $attachments,
    'referenceLinks'  => $reference_links,
    'keyResults'      => $key_results,
    'keyResultStatuses' => okrKeyResultAssignableStatuses($conn),
    'canCollaborate'  => $can_collaborate,
    'currentUserName' => $nama_staff ?? '',
    'chatMessages'    => $chat_messages,
    'canPostChat'     => $can_post_chat,
    'currentStaffId'  => (int)$id_user,
    'canSuspend'      => $can_suspend,
    'canRate'         => $can_rate,
    'isAdmin'         => $okr_is_admin,
];
?>

<?php if ($card['is_suspended']): ?>
<div class="okr-alert-notice mb-3 d-flex justify-content-between align-items-start gap-3">
    <div>
        <i class="bi bi-slash-circle"></i> <strong>This OKR card has been suspended.</strong>
        Its Status stays as shown below - unsuspending simply lifts the suspension. Any unattended suspended OKR
        will be terminated after 30 days.
        <?php if ($card['suspended_by_name']): ?>
        <span class="okr-alert-notice-meta">Suspended by <?php echo htmlspecialchars($card['suspended_by_name']); ?>
            <?php if ($card['suspended_at']): ?>
            on <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($card['suspended_at']))); ?>
            <?php endif; ?>.
        </span>
        <?php endif; ?>
    </div>
    <?php if ($suspend_days_left !== null): ?>
    <div class="okr-alert-notice-countdown text-nowrap">
        <?php echo $suspend_days_left; ?> day<?php echo $suspend_days_left === 1 ? '' : 's'; ?> left
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="okr-bento">
    <div class="okr-bento-item okr-span-8">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-file-earmark-text"></i> OKR<?php echo (int)$card['id']; ?>
                    Details</h6>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($card['is_suspended']): ?>
                    <span class="okr-pill okr-pill-suspended"><i class="bi bi-pause-circle"></i> Suspended</span>
                    <?php else: ?>
                    <span
                        class="okr-pill <?php echo okrPillClass($card['result_status']); ?>"><?php echo htmlspecialchars(okrStatusDisplayLabel($card['result_status'], $card['extended'])); ?></span>
                    <?php endif; ?>
                    <?php if ($can_edit_card): ?>
                    <a href="okr/edit.php?id=<?php echo (int)$card['id']; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil"></i> Edit</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label class="form-label">Objective</label>
                    <textarea class="form-control" id="okr-objective" rows="2"
                        readonly><?php echo htmlspecialchars($card['objective']); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Issuer</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars($card['issuer_name']); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars(empty($issuer_dept_names) ? '-' : implode(', ', $issuer_dept_names)); ?>"
                        readonly>
                </div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-4">
        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-star"></i> Rating</h6>
            <div class="okr-star-rating" id="okr-star-rating">
                <div class="okr-star-display" id="okr-star-display"></div>
            </div>
            <p class="okr-card-hint mb-0" id="okr-star-meta">
                <?php if ($card['rating'] !== null):
                    $rating_pct = round(((float)$card['rating'] / 5) * 100);
                ?>
                Rated <?php echo number_format((float)$card['rating'], 1); ?> / 5 (<?php echo $rating_pct; ?>%)
                <?php if ($card['rated_by_name']): ?>
                by <?php echo htmlspecialchars($card['rated_by_name']); ?>
                <?php endif; ?>
                <?php if ($card['rated_at']): ?>
                on <?php echo htmlspecialchars(substr((string)$card['rated_at'], 0, 10)); ?>
                <?php endif; ?>
                <?php else: ?>
                Not yet rated
                <?php endif; ?>
            </p>
        </div>

        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
            <?php if ($can_collaborate): ?>
            <p class="okr-card-hint">Upload supporting files (max 10MB each).</p>
            <div id="okr-dropzone" class="okr-dropzone">
                <input type="file" id="okr-file-input" multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                <div class="okr-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a href="#"
                        id="okr-file-pick">click to select</a></div>
                <small class="okr-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF, Word, Excel, Text</small>
            </div>
            <div class="okr-form-error" id="okr-file-error"></div>
            <?php endif; ?>
            <div id="okr-file-list" class="okr-file-list mt-2"></div>
        </div>

        <div class="okr-card mb-3">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-link-45deg"></i> Reference Link</h6>
                <?php if ($can_collaborate): ?>
                <button type="button" class="btn btn-primary btn-sm" id="okr-add-reflink-btn">Add Reference
                    Link</button>
                <?php endif; ?>
            </div>
            <div id="okr-reflink-list" class="okr-reflink-list"></div>
            <div class="okr-form-error" id="reflink-section-error"></div>
        </div>


    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-list-task"></i> Key Result Progress</h6>
                <?php if ($can_collaborate): ?>
                <button type="button" class="btn btn-primary btn-sm" id="okr-kr-add-btn">Add Key Result</button>
                <?php endif; ?>
            </div>
            <div id="okr-kr-list" class="okr-kr-list"></div>
            <div class="okr-form-error" id="okr-kr-error"></div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-people"></i> Owner(s)</h6>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="okr-arci-col">
                        <div class="okr-arci-col-head">
                            <span><strong>A</strong> - Accountable (Owner)</span>
                        </div>
                        <div class="okr-arci-members">
                            <div class="okr-arci-member">
                                <div class="okr-arci-member-info">
                                    <div class="okr-arci-member-dept">
                                        (<?php echo htmlspecialchars(empty($dept_names) ? '-' : implode(', ', $dept_names)); ?>)
                                    </div>
                                    <div class="okr-arci-member-name">
                                        <?php echo htmlspecialchars($card['owner_name']); ?></div>
                                </div>
                            </div>
                            <?php if ($card['owner2_name']): ?>
                            <div class="okr-arci-member">
                                <div class="okr-arci-member-info">
                                    <div class="okr-arci-member-dept">
                                        (<?php echo htmlspecialchars(empty($dept_names2) ? '-' : implode(', ', $dept_names2)); ?>)
                                    </div>
                                    <div class="okr-arci-member-name">
                                        <?php echo htmlspecialchars($card['owner2_name']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="okr-card mt-3">
    <h6 class="okr-card-title"><i class="bi bi-calendar-range"></i> Timeline</h6>
    <p class="okr-card-hint">Schedule, status, extensions and closure for this OKR.</p>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control" value="<?php echo htmlspecialchars($card['start_date']); ?>"
                readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control" value="<?php echo htmlspecialchars($card['end_date']); ?>" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label">Status</label>
            <input type="text" class="form-control"
                value="<?php echo htmlspecialchars(okrStatusDisplayLabel($card['result_status'], $card['extended'])); ?>"
                readonly>
        </div>

        <div class="col-12">
            <div class="form-check okr-extended-check mb-2">
                <input class="form-check-input" type="checkbox" <?php echo $card['extended'] ? 'checked' : ''; ?>
                    disabled>
                <label class="form-check-label" style="font-size: 12px;">Extended? (once only &mdash; cannot be
                    undone)</label>
            </div>
        </div>

        <?php if ($card['extended']): ?>
        <div class="col-md-4">
            <label class="form-label">Extended Date</label>
            <input type="date" class="form-control"
                value="<?php echo htmlspecialchars($card['extended_date'] ?? ''); ?>" readonly>
        </div>
        <?php endif; ?>

        <div class="col-md-4">
            <label class="form-label">Final Due Date</label>
            <input type="date" class="form-control" value="<?php echo htmlspecialchars($card['final_due_date']); ?>"
                readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="okr-closure-date">Closure Date</label>
            <?php $can_edit_closure = okrCanEditClosureDate($card['result_status'], $card['issuer_staff_id'], !empty($card['deleted_at']), $id_user, $okr_permission, $okr_is_admin); ?>
            <input type="date" class="form-control" id="okr-closure-date"
                value="<?php echo htmlspecialchars($card['closure_date'] ?? ''); ?>"
                min="<?php echo htmlspecialchars($card['start_date']); ?>" max="<?php echo date('Y-m-d'); ?>"
                <?php echo $can_edit_closure ? '' : 'readonly'; ?>>
            <?php if ($can_edit_closure): ?>
            <div class="okr-form-error" id="okr-closure-date-error"></div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="okr-closure-date-save-btn">Save
                Closure Date</button>
            <?php endif; ?>
        </div>

        <div class="col-12">
            <label class="form-label">Remarks</label>
            <textarea class="form-control" rows="3"
                readonly><?php echo htmlspecialchars($card['remarks'] ?? ''); ?></textarea>
        </div>
    </div>
</div>

<?php if ($latest_suspend_log || $is_ceo_or_admin || $can_appeal): ?>
<div class="row g-3 mt-3">
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
                <textarea class="form-control okr-autogrow" id="okr-suspend-reason" rows="5"
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
                <textarea class="form-control okr-autogrow" id="okr-appeal-justification" rows="5"
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

<?php if ($can_collaborate): ?>
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
<?php endif; ?>

<?php if ($can_collaborate): ?>
<!-- Add/Edit Key Result / Subtask modal - simplified mirror of edit.php's own
     okr-kr-modal (no Link ATEM here; that stays issuer/admin-only on edit.php,
     the ATEM badge on an existing linked row still shows read-only). -->
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
                        <input type="date" class="form-control" id="okr-kr-start" min="<?php echo htmlspecialchars($card['start_date']); ?>" max="<?php echo htmlspecialchars($card['end_date']); ?>">
                    </div>
                    <div class="col-6">
                        <label for="okr-kr-end" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="okr-kr-end" min="<?php echo htmlspecialchars($card['start_date']); ?>" max="<?php echo htmlspecialchars($card['end_date']); ?>">
                    </div>
                </div>
                <div class="mb-2">
                    <label for="okr-kr-status" class="form-label">Status</label>
                    <select class="form-select" id="okr-kr-status">
                        <?php foreach (okrKeyResultAssignableStatuses($conn) as $st): ?>
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
<?php endif; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="okr-saved-toast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite"
        aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">Changes saved.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
var OKR_VIEW_CONFIG = <?php echo json_encode($okr_view_config); ?>;
</script>
<?php
$page_js = 'okr/js/view.js';
include('footer.php');
?>