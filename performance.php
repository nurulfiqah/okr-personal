<?php
$page_title = 'Performance';
include('header.php');
require_once(__DIR__ . '/lib.php');

if ($okr_permission < 4 && !$okr_is_admin) {
    header('Location: /odb/okr/index.php');
    exit;
}

// People Management (staff_department id 17) or admin can lock payouts.
$can_lock_payout = $okr_is_admin || in_array(17, okrDeptIdsFromCsv($department ?? ''), true);

$perf_cur_year = max(2026, (int)date('Y'));
$perf_year_options = [];
for ($y = 2026; $y <= $perf_cur_year; $y++) {
    $perf_year_options[] = $y;
}

$perf_dept_options = [];
$dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department ORDER BY depart_name');
if ($dept_res) {
    while ($drow = mysqli_fetch_assoc($dept_res)) {
        $perf_dept_options[] = ['id' => (int)$drow['id'], 'name' => $drow['depart_name']];
    }
}

$perf_grade_options = [];
$grade_res = mysqli_query($conn, 'SELECT id, grade_name FROM staff_grade ORDER BY id');
if ($grade_res) {
    while ($grow = mysqli_fetch_assoc($grade_res)) {
        $perf_grade_options[] = ['id' => (int)$grow['id'], 'name' => $grow['grade_name']];
    }
}

$perf_struct_options = [];
$struct_res = mysqli_query($conn, 'SELECT id, struct_name FROM staff_struct ORDER BY id');
if ($struct_res) {
    while ($srow = mysqli_fetch_assoc($struct_res)) {
        $perf_struct_options[] = ['id' => (int)$srow['id'], 'name' => $srow['struct_name']];
    }
}
?>

<style>
@keyframes okr-export-slide {
    0%   { transform: translateX(-150%); }
    100% { transform: translateX(400%); }
}
.okr-export-bar-anim {
    background: #198754;
    height: 100%;
    width: 35%;
    animation: okr-export-slide 1.2s ease-in-out infinite;
}
#perf-staff-table td, #perf-staff-table th {
    padding: 4px 8px;
}
.okr-tbl-btn {
    padding: 0px 6px !important;
    font-size: 11px !important;
    line-height: 1.4 !important;
}
</style>

<script>
window.OKR_PERFORMANCE = <?php echo json_encode([
    'apiUrl'        => 'okr/backend.php',
    'exportUrl'     => 'okr/export_performance.php',
    'departments'   => $perf_dept_options,
    'canLockPayout' => $can_lock_payout,
]); ?>;
</script>

<!-- Filter card -->
<div class="okr-card okr-filter mb-3">
    <h6 class="okr-card-title"><i class="bi bi-funnel"></i> Filter</h6>

    <!-- Row 1: Year | Month | Quarter | Department -->
    <div class="row row-cols-md-4 row-cols-2 g-2 mt-1">
        <div class="col">
            <label class="form-label">Year</label>
            <select id="perf-filter-year" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($perf_year_options as $y): ?>
                <option value="<?php echo $y; ?>"<?php echo ($y === 2026) ? ' selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Month</label>
            <select id="perf-filter-month" class="form-select form-select-sm">
                <option value="">All Months</option>
                <option value="1">January</option>
                <option value="2">February</option>
                <option value="3">March</option>
                <option value="4">April</option>
                <option value="5">May</option>
                <option value="6">June</option>
                <option value="7">July</option>
                <option value="8">August</option>
                <option value="9">September</option>
                <option value="10">October</option>
                <option value="11">November</option>
                <option value="12">December</option>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Quarter</label>
            <select id="perf-filter-quarter" class="form-select form-select-sm">
                <option value="">All Quarters</option>
                <option value="1">Q1 (Jan &ndash; Mar)</option>
                <option value="2">Q2 (Apr &ndash; Jun)</option>
                <option value="3">Q3 (Jul &ndash; Sep)</option>
                <option value="4">Q4 (Oct &ndash; Dec)</option>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Department</label>
            <select id="perf-filter-dept" class="form-select form-select-sm">
                <option value="">All Departments</option>
                <?php foreach ($perf_dept_options as $d): ?>
                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Row 2: Grade | Evaluation Structure | Closure Date From | Closure Date To -->
    <div class="row row-cols-md-4 row-cols-2 g-2 mt-0">
        <div class="col">
            <label class="form-label">Grade</label>
            <select id="perf-filter-grade" class="form-select form-select-sm">
                <option value="">All Grades</option>
                <?php foreach ($perf_grade_options as $g): ?>
                <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Evaluation Structure</label>
            <select id="perf-filter-struct" class="form-select form-select-sm">
                <option value="">All Structures</option>
                <?php foreach ($perf_struct_options as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Closure Date From</label>
            <input type="date" id="perf-filter-closure-from" class="form-control form-control-sm">
        </div>
        <div class="col">
            <label class="form-label">Closure Date To</label>
            <input type="date" id="perf-filter-closure-to" class="form-control form-control-sm">
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center mt-2">
        <button class="btn btn-sm btn-outline-secondary" id="perf-reset-filter">Reset</button>
        <a class="btn btn-sm btn-outline-success" id="perf-export-btn" href="#">Export</a>
        <?php if ($can_lock_payout): ?>
        <span class="okr-filter-btns-divider"></span>
        <a class="btn btn-sm btn-outline-danger" id="perf-export-lock-btn" href="#">Export &amp; Lock</a>
        <button class="btn btn-sm btn-danger" id="perf-lock-btn">Lock Payout</button>
        <button class="btn btn-sm btn-outline-warning" id="perf-unlock-btn">Undo Lock Payout</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_lock_payout): ?>
<!-- Lock payout confirmation modal -->
<div class="modal fade" id="okr-lock-modal" tabindex="-1" aria-labelledby="okr-lock-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-lock-modal-title">Lock Payout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>This locks every <strong>Complete</strong> / <strong>Complete with Excellence</strong> OKR matching the current filter. Locked OKRs can no longer be edited, deleted, or suspended by anyone.</p>
                <p class="mb-0 text-muted" style="font-size:12px;">This does not affect cards that are still Active, Extend, Draft, Suspended, or Fail.</p>
                <div class="mt-2">
                    <label for="okr-lock-remark" class="form-label">Remark (optional)</label>
                    <textarea class="form-control" id="okr-lock-remark" rows="2" placeholder="e.g. July 2026 payroll run"></textarea>
                </div>
                <div class="okr-form-error" id="okr-lock-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="okr-lock-confirm-btn">Lock Payout</button>
            </div>
        </div>
    </div>
</div>

<!-- Undo lock payout confirmation modal -->
<div class="modal fade" id="okr-unlock-modal" tabindex="-1" aria-labelledby="okr-unlock-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-unlock-modal-title">Undo Lock Payout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>This unlocks every currently locked OKR matching the current filter, allowing them to be edited, deleted, or suspended again.</p>
                <div class="okr-form-error" id="okr-unlock-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="okr-unlock-confirm-btn">Undo Lock Payout</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="perf-export-progress-wrap" style="display:none;margin-bottom:12px;max-width:400px;">
    <div style="margin-bottom:4px;">
        <span id="perf-export-progress-msg" class="text-muted" style="font-size:12px;">Preparing export...</span>
    </div>
    <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
        <div class="okr-export-bar-anim"></div>
    </div>
</div>

<div class="okr-card">
    <div class="okr-card-title-row">
        <h6 class="okr-card-title mb-0">Staff Performance</h6>
        <?php if ($can_lock_payout): ?>
        <div>
            <a class="btn btn-sm btn-outline-success" id="perf-export-selected-btn" href="#" style="pointer-events:none;opacity:0.5;">Export Selected</a>
            <a class="btn btn-sm btn-outline-danger" id="perf-export-lock-selected-btn" href="#" style="pointer-events:none;opacity:0.5;">Export &amp; Lock Selected</a>
            <button class="btn btn-sm btn-outline-danger" id="perf-lock-selected-btn" disabled>Lock Selected</button>
            <button class="btn btn-sm btn-outline-warning" id="perf-unlock-selected-btn" disabled>Unlock Selected</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">View a staff's OKR cards for the current filter, or export just their history</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 okr-view-tbl" id="perf-staff-table">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="perf-select-all" title="Select all on this page"></th>
                    <th style="text-align:left;">Name</th>
                    <th style="text-align:left;">Department</th>
                    <th style="text-align:left;">Grade</th>
                    <th style="text-align:left;">Evaluation Structure</th>
                    <th style="text-align:center;">OKR</th>
                    <th style="text-align:center;">Complete</th>
                    <th style="text-align:center;">Active</th>
                    <th style="text-align:center;">Extend</th>
                    <th style="text-align:center;">Fail</th>
                    <th style="text-align:center;">Est. Reward</th>
                    <th style="text-align:left;">Action</th>
                </tr>
            </thead>
            <tbody id="perf-table-body">
                <tr><td colspan="12" class="text-muted" style="font-size:12px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-2" id="perf-pager" style="font-size:12px;"></div>
</div>

<!-- Staff drill-down modal -->
<div class="modal fade" id="okr-staff-modal" tabindex="-1" aria-labelledby="okr-staff-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-staff-modal-title">Staff OKR Cards</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 okr-view-tbl">
                        <thead>
                            <tr>
                                <th style="text-align:left;">ID</th>
                                <th style="text-align:left;">Objective</th>
                                <th style="text-align:left;">Type</th>
                                <th style="text-align:left;">Level</th>
                                <th style="text-align:left;">Dates</th>
                                <th style="text-align:left;">Status</th>
                                <th style="text-align:left;">Role</th>
                                <th style="text-align:left;">Est. Reward</th>
                            </tr>
                        </thead>
                        <tbody id="okr-staff-modal-body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-success btn-sm me-auto" id="okr-staff-modal-export" href="#">Export</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$page_js = 'okr/js/performance.js';
include('footer.php');
?>
