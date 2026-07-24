<?php
$page_title = 'OKR Cards';
include('header.php');
require_once(__DIR__ . '/lib.php');

$scope_where = okrScopeWhere($id_user, $okr_permission, okrDeptIdsFromCsv($department ?? ''), $okr_is_admin);
$result = mysqli_query($conn, okrCardSelectSql($scope_where, $okr_is_admin) . ' ORDER BY c.created_at DESC');

$cards = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $cards[] = okrFormatCard($row);
    }
}

$list_dept_options = [];
$list_dept_names = [];
$list_dept_res = mysqli_query($conn, 'SELECT id, depart_name FROM staff_department ORDER BY depart_name');
if ($list_dept_res) {
    while ($drow = mysqli_fetch_assoc($list_dept_res)) {
        $list_dept_options[] = ['id' => (int)$drow['id'], 'name' => $drow['depart_name']];
        $list_dept_names[(int)$drow['id']] = $drow['depart_name'];
    }
}

$list_type_options = okrTypeValues($conn, false);

// Year options driven by what's actually in the data, not a fixed range,
// since this list spans every card the requester can see (unlike
// Performance, which is scoped to payout years).
$list_years = [];
foreach ($cards as $c) {
    if (!empty($c['start_date'])) { $list_years[(int)substr($c['start_date'], 0, 4)] = true; }
}
$list_year_options = array_keys($list_years);
rsort($list_year_options);

// Owner/Issuer options are the full staff directory (same scoping as
// index.php's Staff filter), not just staff who happen to own/issue a
// visible card - senior management filtering ahead of a first OKR for
// someone should still be able to find that staff member.
$list_dept_ids = okrDeptIdsFromCsv($department ?? '');
if ($okr_permission >= 4 || $okr_is_admin) {
    $staff_dir_where = '1=1';
} elseif ($okr_permission === 3 && !empty($list_dept_ids)) {
    $dept_conds = [];
    foreach ($list_dept_ids as $_did) {
        $dept_conds[] = "FIND_IN_SET($_did, department)";
    }
    $staff_dir_where = '(' . implode(' OR ', $dept_conds) . ')';
} else {
    $staff_dir_where = '0=1';
}
$list_owners = [];
$list_issuers = [];
$staff_dir_res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1 AND ($staff_dir_where) ORDER BY nama_staff");
if ($staff_dir_res) {
    while ($srow = mysqli_fetch_assoc($staff_dir_res)) {
        $list_owners[(int)$srow['id']] = $srow['nama_staff'];
        $list_issuers[(int)$srow['id']] = $srow['nama_staff'];
    }
}

$okr_list_config = [
    'cards'           => $cards,
    'requesterId'     => (int)$id_user,
    'requesterIsAdmin' => $okr_is_admin,
    'deptNames'       => $list_dept_names,
];
?>

<!-- Filter card -->
<div class="okr-card okr-filter mb-3">
    <h6 class="okr-card-title"><i class="bi bi-funnel"></i> Filter</h6>

    <!-- Row 1: Year | Month | Start Date | End Date | Status | Type -->
    <div class="row row-cols-md-6 row-cols-2 g-2 mt-1">
        <div class="col">
            <label class="form-label">Year</label>
            <select class="form-select form-select-sm" id="okr-filter-year">
                <option value="">All years</option>
                <?php foreach ($list_year_options as $y): ?>
                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Month</label>
            <select class="form-select form-select-sm" id="okr-filter-month">
                <option value="">All months</option>
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
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control form-control-sm" id="okr-filter-start-date">
        </div>
        <div class="col">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control form-control-sm" id="okr-filter-end-date">
        </div>
        <div class="col">
            <label class="form-label">Status</label>
            <div class="okr-s2-wrap" id="okr-filter-status-wrap">
                <div class="okr-s2-selection" id="okr-filter-status-btn" tabindex="0">All statuses</div>
                <div class="okr-s2-dropdown" id="okr-filter-status-dropdown">
                    <ul class="okr-s2-list" id="okr-filter-status-list" style="padding:4px 0;">
                        <?php
                        // As a filter, list every real status a card can currently
                        // hold - including Suspended and Completed with Extension -
                        // read live from okr_statuses rather than a fixed list.
                        $list_status_options = array_column(okrFetchStatuses($conn, false), 'value');
                        foreach ($list_status_options as $_st): ?>
                        <li style="cursor:default;">
                            <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                <input type="checkbox" value="<?php echo htmlspecialchars($_st); ?>" checked>
                                <?php echo htmlspecialchars($_st); ?>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col">
            <label class="form-label">Type</label>
            <select class="form-select form-select-sm" id="okr-filter-type">
                <option value="">All types</option>
                <?php foreach ($list_type_options as $t): ?>
                <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Row 2: Level | Department | Owner | Issuer | Search | (buttons) -->
    <div class="row row-cols-md-6 row-cols-2 g-2 mt-0 align-items-end">
        <div class="col">
            <label class="form-label">Level</label>
            <select class="form-select form-select-sm" id="okr-filter-level">
                <option value="">All levels</option>
                <option value="1">Level 1</option>
                <option value="2">Level 2</option>
                <option value="3">Level 3</option>
                <option value="4">Level 4</option>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Department</label>
            <select class="form-select form-select-sm" id="okr-filter-dept">
                <option value="">All departments</option>
                <?php foreach ($list_dept_options as $d): ?>
                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <label class="form-label">Owner</label>
            <div class="okr-s2-wrap" id="okr-filter-owner-wrap">
                <div class="okr-s2-selection" id="okr-filter-owner-btn" tabindex="0">All owners</div>
                <div class="okr-s2-dropdown" id="okr-filter-owner-dropdown">
                    <div class="okr-s2-search-wrap">
                        <input class="okr-s2-search" id="okr-filter-owner-search" type="search" placeholder="Search name...">
                    </div>
                    <ul class="okr-s2-list" id="okr-filter-owner-list">
                        <li data-id="">All owners</li>
                        <?php foreach ($list_owners as $sid => $name): ?>
                        <li data-id="<?php echo $sid; ?>"><?php echo htmlspecialchars($name); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" id="okr-filter-owner-value" value="">
            </div>
        </div>
        <div class="col">
            <label class="form-label">Issuer</label>
            <div class="okr-s2-wrap" id="okr-filter-issuer-wrap">
                <div class="okr-s2-selection" id="okr-filter-issuer-btn" tabindex="0">All issuers</div>
                <div class="okr-s2-dropdown" id="okr-filter-issuer-dropdown">
                    <div class="okr-s2-search-wrap">
                        <input class="okr-s2-search" id="okr-filter-issuer-search" type="search" placeholder="Search name...">
                    </div>
                    <ul class="okr-s2-list" id="okr-filter-issuer-list">
                        <li data-id="">All issuers</li>
                        <?php foreach ($list_issuers as $sid => $name): ?>
                        <li data-id="<?php echo $sid; ?>"><?php echo htmlspecialchars($name); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" id="okr-filter-issuer-value" value="">
            </div>
        </div>
        <div class="col">
            <label class="form-label">Search title or ID</label>
            <input type="text" class="form-control form-control-sm" id="okr-filter-search" placeholder="Type title or OKR ID...">
        </div>
        <div class="col d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="okr-filter-reset">Reset</button>
        </div>
    </div>
</div>

<div class="okr-card">

    <div class="table-responsive">
        <table class="table table-hover align-middle okr-view-tbl" id="okr-view-tbl">
            <thead>
                <tr>
                    <th class="okr-sortable" data-col="id">ID</th>
                    <th class="okr-sortable" data-col="objective">Objective</th>
                    <th class="okr-sortable" data-col="issuer_name">Issuer</th>
                    <th>Owner(s)</th>
                    <th class="okr-sortable" data-col="difficulty_level">Level</th>
                    <th>Type</th>
                    <th class="okr-sortable" data-col="start_date">Start Date</th>
                    <th class="okr-sortable" data-col="end_date">End Date</th>
                    <th class="okr-sortable" data-col="result_status">Status</th>
                    <th style="width:110px;">Action</th>
                </tr>
            </thead>
            <tbody id="okr-view-tbody"></tbody>
        </table>
        <div class="okr-empty-state" id="okr-empty-state" style="display:none;">No OKR cards to show.</div>
    </div>
</div>

<div class="modal fade" id="okr-delete-modal" tabindex="-1" aria-labelledby="okr-delete-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-delete-modal-title">Delete OKR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete this OKR card? This action cannot be undone and will delete it permanently.</p>
                <div class="okr-form-error" id="okr-delete-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="okr-delete-confirm-btn">Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="okr-permadelete-modal" tabindex="-1" aria-labelledby="okr-permadelete-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="okr-permadelete-modal-title">Delete Permanently</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This will permanently remove this OKR card from the database, including all its attachments, reference links, and activity log. This cannot be undone. Are you sure?</p>
                <div class="okr-form-error" id="okr-permadelete-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="okr-permadelete-confirm-btn">Delete Permanently</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="okr-delete-toast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">OKR card deleted.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <div id="okr-permadelete-toast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">OKR card permanently deleted.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
var OKR_VIEW = <?php echo json_encode($okr_list_config); ?>;
</script>
<?php
$page_js = 'okr/js/list.js';
include('footer.php');
?>
