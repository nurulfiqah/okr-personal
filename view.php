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

$can_suspend = (($okr_is_admin || $okr_permission === 5) && !$card['incentive_locked']);

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

$okr_view_config = [
    'apiUrl'          => 'okr/backend.php',
    'card'            => $card,
    'attachments'     => $attachments,
    'referenceLinks'  => $reference_links,
    'canSuspend'      => $can_suspend,
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
                <div class="col-md-4">
                    <label class="form-label">OKR Type</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($card['okr_type']); ?>"
                        readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">OKR Complexity Level</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars($card['level_label']); ?> (RM<?php echo number_format($card['level_rm'], 2); ?>)"
                        readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Incentive Rule</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars($card['incentive_rule_label']); ?>" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label">Key Results</label>
                    <div id="okr-key-results-editor"><?php echo $card['key_results']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="okr-bento-item okr-span-4">
        <div class="okr-card mb-3">
            <h6 class="okr-card-title"><i class="bi bi-cash-coin"></i> Estimated Incentive</h6>
            <p class="okr-card-hint">This shows an estimated incentive based on the selected level and rule. The company
                reserves the right to determine the final payout under its incentive scheme.</p>
            <?php
            $is_paid      = $card['pays_incentive'];
            $is_forecast  = !$is_paid && !in_array($card['result_status'], ['Failed', OKR_STATUS_SUSPENDED], true);
            $show_amount  = $is_paid || $is_forecast;
            $tile_amount  = $show_amount ? $card['level_rm'] : 0;
            $tile_label   = $is_paid ? 'Total Incentive' : 'Estimated Incentive';
            $tile_class   = okrIncentiveTileClass($card['result_status']);
            ?>
            <div class="okr-incentive-tile <?php echo $tile_class; ?> mt-2">
                <div class="okr-incentive-tile-label"><?php echo $tile_label; ?></div>
                <div class="okr-incentive-tile-value">RM<?php echo number_format($tile_amount, 2); ?></div>
            </div>
            <?php if ($show_amount): ?>
            <div class="okr-incentive-breakdown">
                <?php if ($card['owner2_name']): ?>
                <?php if ($card['incentive_rule_code'] === 'RULE1'): ?>
                <div class="okr-incentive-stat" style="grid-column: 1 / -1;">
                    <span
                        class="okr-incentive-stat-label"><?php echo htmlspecialchars($card['incentivised_owner_name']); ?>
                        (100%)</span>
                    <span class="okr-incentive-stat-value">RM<?php echo number_format($card['level_rm'], 2); ?></span>
                </div>
                <?php else: ?>
                <div class="okr-incentive-stat">
                    <span class="okr-incentive-stat-label">1st Owner &middot;
                        <?php echo htmlspecialchars($card['owner_name']); ?></span>
                    <span
                        class="okr-incentive-stat-value">RM<?php echo number_format($card['level_rm'] / 2, 2); ?></span>
                </div>
                <div class="okr-incentive-stat">
                    <span class="okr-incentive-stat-label">2nd Owner &middot;
                        <?php echo htmlspecialchars($card['owner2_name']); ?></span>
                    <span
                        class="okr-incentive-stat-value">RM<?php echo number_format($card['level_rm'] / 2, 2); ?></span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="okr-incentive-stat" style="grid-column: 1 / -1;">
                    <span class="okr-incentive-stat-label">Owner &middot;
                        <?php echo htmlspecialchars($card['owner_name']); ?></span>
                    <span class="okr-incentive-stat-value">RM<?php echo number_format($card['level_rm'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($card['incentive_locked']): ?>
            <p class="okr-card-hint mb-0">
                <i class="bi bi-lock-fill"></i> Locked after
                payout<?php echo $card['locked_by_name'] ? ' by ' . htmlspecialchars($card['locked_by_name']) : ''; ?><?php echo $card['locked_at'] ? ' on ' . htmlspecialchars(substr($card['locked_at'], 0, 10)) : ''; ?>.
            </p>
            <?php if (!empty($card['payout_remark'])): ?>
            <p class="okr-card-hint mb-0"><em>"<?php echo htmlspecialchars($card['payout_remark']); ?>"</em></p>
            <?php endif; ?>
            <?php elseif ($card['unlocked_by_name']): ?>
            <p class="okr-card-hint mb-0">
                <i class="bi bi-unlock"></i> Unlocked by
                <?php echo htmlspecialchars($card['unlocked_by_name']); ?><?php echo $card['unlocked_at'] ? ' on ' . htmlspecialchars(substr($card['unlocked_at'], 0, 10)) : ''; ?>.
            </p>
            <?php endif; ?>
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

        <?php if ($can_suspend): ?>
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-pause-circle"></i> CEO Action</h6>
            <div class="okr-form-error" id="okr-suspend-error"></div>
            <?php if ($card['result_status'] === OKR_STATUS_SUSPENDED): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="okr-unsuspend-btn">Unsuspend
                OKR</button>
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
    </div>

    <div class="okr-bento-item okr-span-12">
        <div class="okr-card">
            <h6 class="okr-card-title"><i class="bi bi-people"></i> Owner(s)</h6>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
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
                                <?php if ($card['owner2_name'] && $card['incentive_rule_code'] === 'RULE1' && $card['incentivised_owner_staff_id'] === $card['owner_staff_id']): ?>
                                <span class="okr-arci-incentivised-badge">Incentivised</span>
                                <?php endif; ?>
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
                                <?php if ($card['incentive_rule_code'] === 'RULE1' && $card['incentivised_owner_staff_id'] === $card['owner2_staff_id']): ?>
                                <span class="okr-arci-incentivised-badge">Incentivised</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($card['owner2_name']): ?>
                <div class="col-md-6">
                    <label class="form-label">Purpose of joint ownership</label>
                    <input type="text" class="form-control"
                        value="<?php echo htmlspecialchars($card['owner2_purpose']); ?>" readonly>
                </div>
                <?php endif; ?>
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

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
var OKR_VIEW_CONFIG = <?php echo json_encode($okr_view_config); ?>;
</script>
<?php
$page_js = 'okr/js/view.js';
include('footer.php');
?>