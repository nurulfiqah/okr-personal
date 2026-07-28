<?php
$page_title = 'OKR Dashboard';

$page_title_actions = '<a href="atem/index.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Switch to ATEM Dashboard</a>';
include('header.php');
require_once(__DIR__ . '/lib.php');

$dash_cur_year = max(2026, (int)date('Y'));
$dash_year_options = [];
for ($y = 2026; $y <= $dash_cur_year; $y++) {
    $dash_year_options[] = $y;
}

$dash_user_dept_ids = okrDeptIdsFromCsv($department ?? '');

$dash_dept_options = [];
$dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department ORDER BY depart_name');
if ($dept_res) {
    while ($drow = mysqli_fetch_assoc($dept_res)) {
        if ($okr_permission >= 4 || $okr_is_admin) {
            $dash_dept_options[] = ['id' => (int)$drow['id'], 'name' => $drow['depart_name']];
        } elseif ($okr_permission === 3 && in_array((int)$drow['id'], $dash_user_dept_ids, true)) {
            $dash_dept_options[] = ['id' => (int)$drow['id'], 'name' => $drow['depart_name']];
        }
    }
}

// Staff filter options - scoped the same way as Department above (grade 1-2
// only ever see their own cards anyway via okrScopeWhere, so a staff picker
// only makes sense from senior management up).
$dash_staff_options = [];
if ($okr_permission >= 3 || $okr_is_admin) {
    if ($okr_permission >= 4 || $okr_is_admin) {
        $staff_where = '1=1';
    } elseif (!empty($dash_user_dept_ids)) {
        $dept_conds = [];
        foreach ($dash_user_dept_ids as $_did) {
            $dept_conds[] = "FIND_IN_SET($_did, department)";
        }
        $staff_where = '(' . implode(' OR ', $dept_conds) . ')';
    } else {
        $staff_where = '0=1';
    }
    $staff_res = mysqli_query($conn, "SELECT id, nama_staff, department FROM staff WHERE recycle != 1 AND ($staff_where) ORDER BY nama_staff");
    if ($staff_res) {
        while ($srow = mysqli_fetch_assoc($staff_res)) {
            $dash_staff_options[] = [
                'id'      => (int)$srow['id'],
                'name'    => $srow['nama_staff'],
                'deptIds' => okrDeptIdsFromCsv($srow['department']),
            ];
        }
    }
}
?>

<script>
window.OKR_DASH = <?php echo json_encode([
    'apiUrl'      => 'okr/backend.php',
    'departments' => $dash_dept_options,
    'staff'       => $dash_staff_options,
]); ?>;
</script>

<!-- Filter card -->
<div class="okr-card okr-filter mb-3">
    <h6 class="okr-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="okr-filter-row mt-1">
        <div class="okr-filter-item">
            <label class="form-label">Year</label>
            <select id="dash-filter-year" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($dash_year_options as $y): ?>
                <option value="<?php echo $y; ?>"<?php echo ($y === 2026) ? ' selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="okr-filter-item">
            <label class="form-label">Month</label>
            <select id="dash-filter-month" class="form-select form-select-sm">
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
        <div class="okr-filter-item">
            <label class="form-label">Quarter</label>
            <select id="dash-filter-quarter" class="form-select form-select-sm">
                <option value="">All Quarters</option>
                <option value="1">Q1 (Jan &ndash; Mar)</option>
                <option value="2">Q2 (Apr &ndash; Jun)</option>
                <option value="3">Q3 (Jul &ndash; Sep)</option>
                <option value="4">Q4 (Oct &ndash; Dec)</option>
            </select>
        </div>
        <div class="okr-filter-item okr-filter-item--dept" id="dash-dept-col"<?php if (empty($dash_dept_options)) { echo ' style="display:none;"'; } ?>>
            <label class="form-label">Department</label>
            <select id="dash-filter-dept" class="form-select form-select-sm">
                <option value="">All Departments</option>
            </select>
        </div>
        <div class="okr-filter-item" id="dash-staff-col"<?php if (empty($dash_staff_options)) { echo ' style="display:none;"'; } ?>>
            <label class="form-label">Staff</label>
            <div class="okr-s2-wrap" id="dash-staff-wrap">
                <div class="okr-s2-selection" id="dash-staff-btn" tabindex="0">All Staff</div>
                <div class="okr-s2-dropdown" id="dash-staff-dropdown">
                    <div class="okr-s2-search-wrap">
                        <input class="okr-s2-search" id="dash-staff-search" type="search" placeholder="Search name...">
                    </div>
                    <ul class="okr-s2-list" id="dash-staff-list"></ul>
                </div>
                <input type="hidden" id="dash-staff-value" value="0">
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end align-items-center gap-2 mt-2">
        <span class="text-muted" id="dash-filter-label" style="font-size:12px;"></span>
        <button class="btn btn-sm btn-outline-secondary" id="dash-reset-filter">Reset</button>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl">
        <div class="okr-card okr-dash-stat h-100" style="cursor:pointer;">
            <div class="okr-card-title mb-1">Total OKR Cards</div>
            <div class="okr-stat-value okr-stat-value--blue" id="dash-total">---</div>
            <div class="okr-stat-label">created in range</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="okr-card okr-dash-stat h-100" data-status="<?php echo OKR_STATUS_ACTIVE; ?>" style="cursor:pointer;">
            <div class="okr-card-title mb-1">Active / On Hand</div>
            <div class="okr-stat-value okr-stat-value--blue" id="dash-active">---</div>
            <div class="okr-stat-label">not yet closed</div>
            <div class="okr-stat-label" id="dash-extended-label" style="display:none;color:#fd7e14;margin-top:2px;"></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="okr-card okr-dash-stat h-100" data-statuses="Completed,Completed with Excellence,Completed with Extension" style="cursor:pointer;">
            <div class="okr-card-title mb-1">Complete + Excellence</div>
            <div class="okr-stat-value okr-stat-value--green" id="dash-closed">---</div>
            <div class="okr-stat-label">paid outcomes</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="okr-card okr-dash-stat h-100" data-status="Failed" style="cursor:pointer;">
            <div class="okr-card-title mb-1">Failed OKR</div>
            <div class="okr-stat-value okr-stat-value--red" id="dash-failed">---</div>
            <div class="okr-stat-label" id="dash-fail-rate">failure rate</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="okr-card okr-dash-stat h-100" data-overdue="1" data-statuses="Active,Extended" style="cursor:pointer;">
            <div class="okr-card-title mb-1">Overdue Cards</div>
            <div class="okr-stat-value okr-stat-value--red" id="dash-overdue">---</div>
            <div class="okr-stat-label">active/extended past end date</div>
        </div>
    </div>
</div>

<!-- Bar chart -->
<div class="row g-3">
    <div class="col-12">
        <div class="okr-card h-100">
            <h6 class="okr-card-title mb-0">Closure &amp; Failure Analysis</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuer-only closure: Completed, Excellence, Extended, Failed</div>
            <div id="dash-bars">
                <div class="okr-bar-row">
                    <div class="okr-bar-label">Excellence</div>
                    <div class="okr-bar-track">
                        <div class="okr-bar-fill" id="bar-excellence" style="width:0%;background:#198754;"></div>
                    </div>
                    <div class="okr-bar-count" id="bar-excellence-n">-</div>
                </div>
                <div class="okr-bar-row">
                    <div class="okr-bar-label">Completed</div>
                    <div class="okr-bar-track">
                        <div class="okr-bar-fill" id="bar-complete" style="width:0%;background:#0d6efd;"></div>
                    </div>
                    <div class="okr-bar-count" id="bar-complete-n">-</div>
                </div>
                <div class="okr-bar-row">
                    <div class="okr-bar-label">Extended</div>
                    <div class="okr-bar-track">
                        <div class="okr-bar-fill" id="bar-extended" style="width:0%;background:#fd7e14;"></div>
                    </div>
                    <div class="okr-bar-count" id="bar-extended-n">-</div>
                </div>
                <div class="okr-bar-row">
                    <div class="okr-bar-label">Failed</div>
                    <div class="okr-bar-track">
                        <div class="okr-bar-fill" id="bar-failed" style="width:0%;background:#dc3545;"></div>
                    </div>
                    <div class="okr-bar-count" id="bar-failed-n">-</div>
                </div>
            </div>
            <div class="text-muted mt-3" style="font-size:11px;">Critical CEO use: identify failure rate by department, issuer and month.</div>
        </div>
    </div>
</div>

<!-- Department Breakdown -->
<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="okr-card">
            <h6 class="okr-card-title mb-0">Department Breakdown</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards and outcomes by issuer department</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="font-size:12px;text-align:left;">Department</th>
                            <th style="font-size:12px;text-align:left;">Cards</th>
                            <th style="font-size:12px;text-align:left;">Completed</th>
                            <th style="font-size:12px;text-align:left;">Excellence</th>
                            <th style="font-size:12px;text-align:left;">Failed</th>
                            <th style="font-size:12px;text-align:left;">Fail Rate</th>
                            <th style="font-size:12px;text-align:left;">Suspended</th>
                            <th style="font-size:12px;text-align:left;">Force Terminated</th>
                        </tr>
                    </thead>
                    <tbody id="dash-dept-body">
                        <tr><td colspan="8" class="text-muted" style="font-size:12px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Suspended & Force Terminated - Top Staff -->
<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="okr-card">
            <h6 class="okr-card-title mb-0">Suspended &amp; Force Terminated (Top Staff)</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuers with the most suspended/force-terminated OKRs, in range</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="font-size:12px;text-align:left;">Staff (Issuer)</th>
                            <th style="font-size:12px;text-align:left;">Suspended</th>
                            <th style="font-size:12px;text-align:left;">Force Terminated</th>
                        </tr>
                    </thead>
                    <tbody id="dash-staff-suspend-body">
                        <tr><td colspan="3" class="text-muted" style="font-size:12px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$page_js = 'okr/js/index.js';
include('footer.php');
?>