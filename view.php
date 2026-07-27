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

// Failed is a terminal outcome (including force-terminated cards, which are
// just Failed + a flag) - once an OKR is Failed there's nothing left for the
// CEO to suspend (it's already resolved) and nothing left to rate.
$is_terminal_failed = ($card['result_status'] === 'Failed');
$can_suspend = ($okr_is_admin || $okr_permission === 5) && !$is_terminal_failed;
$can_rate    = ($okr_is_admin || $okr_permission === 5) && !$is_terminal_failed;
// Force Terminate uses the same gate as Suspend/Unsuspend (grade 5/admin).
$can_force_terminate = $can_suspend;
// Only the issuer can appeal, only while Suspended, only once per
// suspension cycle (appealed_at is cleared on unsuspend/force-terminate).
// Shown even when the issuer also happens to be admin/grade-5 (they still
// see it alongside the CEO Action card's direct Unsuspend/Force Terminate).
$can_appeal = ($card['issuer_staff_id'] === (int)$id_user
    && $card['result_status'] === OKR_STATUS_SUSPENDED && empty($card['appealed_at']));

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

$attachments     = okrFetchAttachments($conn, $card_id);
$reference_links = okrFetchReferenceLinks($conn, $card_id);
$audit_logs      = okrFetchAuditLogs($conn, $card_id);
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
    'chatMessages'    => $chat_messages,
    'canPostChat'     => $can_post_chat,
    'currentStaffId'  => (int)$id_user,
    'canSuspend'      => $can_suspend,
    'canRate'         => $can_rate,
];
?>

<div class="okr-bento">
    <div class="okr-bento-item okr-span-8">
        <div class="okr-card">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-file-earmark-text"></i> OKR<?php echo (int)$card['id']; ?>
                    Details</h6>
                <span
                    class="okr-pill <?php echo okrPillClass($card['result_status']); ?>"><?php echo htmlspecialchars(okrStatusDisplayLabel($card['result_status'], $card['extended'])); ?></span>
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
                        value="<?php echo htmlspecialchars(empty($dept_names) ? '-' : implode(', ', $dept_names)); ?>"
                        readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">OKR Type</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($card['okr_type']); ?>"
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
            <div id="okr-file-list" class="okr-file-list mt-2"></div>
        </div>

        <div class="okr-card mb-3">
            <div class="okr-card-title-row">
                <h6 class="okr-card-title"><i class="bi bi-link-45deg"></i> Reference Link</h6>
            </div>
            <div id="okr-reflink-list" class="okr-reflink-list"></div>
        </div>

        <?php if ($card['result_status'] === OKR_STATUS_SUSPENDED): ?>
        <div class="okr-card">
            <div class="okr-alert-notice mb-2">
                <div><i class="bi bi-slash-circle"></i> <strong>This OKR card has been suspended.</strong>
                    Unsuspending it will restore it to Active status.
                    <?php if ($card['closed_by_name']): ?>
                    <span class="okr-alert-notice-meta">Suspended by
                        <?php echo htmlspecialchars($card['closed_by_name']); ?>
                        <?php if ($card['closed_at']): ?>
                        on <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($card['closed_at']))); ?>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="mt-1">Any unattended suspended OKR will be terminated after 30 days.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can_suspend): ?>
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-pause-circle"></i> CEO Action</h6>
            <div class="okr-form-error" id="okr-suspend-error"></div>
            <?php if ($card['result_status'] === OKR_STATUS_SUSPENDED): ?>
            <?php if (!empty($card['appealed_at'])): ?>
            <div class="okr-card-hint mb-2">
                <strong>Appeal submitted</strong> on
                <?php echo htmlspecialchars(substr((string)$card['appealed_at'], 0, 10)); ?>:<br>
                <?php echo nl2br(htmlspecialchars($card['appeal_justification'])); ?>
            </div>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mb-2" id="okr-unsuspend-btn">Unsuspend
                OKR</button>
            <button type="button" class="btn btn-outline-danger btn-sm w-100" id="okr-force-terminate-btn">Force
                Terminate</button>
            <div id="okr-force-terminate-wrap" style="display:none;" class="mt-2">
                <label for="okr-force-terminate-remark" class="form-label">Remark <span class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-force-terminate-remark" rows="3"
                    placeholder="Why is this OKR being force terminated?"></textarea>
                <div class="okr-form-error" id="okr-force-terminate-remark-error"></div>
                <button type="button" class="btn btn-danger btn-sm w-100 mt-2"
                    id="okr-force-terminate-confirm-btn">Force Terminate OKR</button>
            </div>
            <?php else: ?>
            <p class="okr-card-hint">Only the CEO can suspend an OKR.</p>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="okr-suspend-btn">Suspend
                OKR</button>
            <div id="okr-suspend-reason-wrap" style="display:none;" class="mt-2">
                <label for="okr-suspend-reason" class="form-label">Reason <span class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-suspend-reason" rows="3"
                    placeholder="Why is this OKR being suspended?"></textarea>
                <div class="okr-form-error" id="okr-suspend-reason-error"></div>
                <button type="button" class="btn btn-danger btn-sm w-100 mt-2" id="okr-suspend-confirm-btn">Suspend
                    OKR</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($can_appeal): ?>
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-megaphone"></i> Appeal Suspension</h6>
            <p class="okr-card-hint">Submit a justification for the CEO to review.</p>
            <div class="okr-form-error" id="okr-appeal-error"></div>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="okr-appeal-btn">Appeal</button>
            <div id="okr-appeal-wrap" style="display:none;" class="mt-2">
                <label for="okr-appeal-justification" class="form-label">Justification <span
                        class="okr-req">*</span></label>
                <textarea class="form-control" id="okr-appeal-justification" rows="3"
                    placeholder="Why should this suspension be reversed?"></textarea>
                <div class="okr-form-error" id="okr-appeal-justification-error"></div>
                <button type="button" class="btn btn-primary btn-sm w-100 mt-2" id="okr-appeal-confirm-btn">Submit
                    Appeal</button>
            </div>
        </div>
        <?php endif; ?>
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
            <label class="form-label">Closure Date</label>
            <input type="date" class="form-control" value="<?php echo htmlspecialchars($card['closure_date'] ?? ''); ?>"
                readonly>
        </div>

        <div class="col-12">
            <label class="form-label">Remarks</label>
            <textarea class="form-control" rows="3"
                readonly><?php echo htmlspecialchars($card['remarks'] ?? ''); ?></textarea>
        </div>
    </div>
</div>

<div class="okr-card mt-3">
    <h6 class="okr-card-title"><i class="bi bi-list-task"></i> Key Result Progress</h6>
    <div id="okr-kr-list" class="okr-kr-list"></div>
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