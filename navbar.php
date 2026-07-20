<?php
$_navbar_serverName = $_SERVER['SERVER_NAME'] ?? '';
$_navbar_httpHost   = $_SERVER['HTTP_HOST'] ?? '';
$_navbar_isLocal    = in_array($_navbar_serverName, ['localhost', '127.0.0.1'])
    || strpos($_navbar_serverName, 'localhost') !== false
    || strpos($_navbar_httpHost,   'localhost') !== false
    || strpos($_navbar_httpHost,   '127.0.0.1') !== false;

$_navbar_realIsAdmin = false;
if ($_navbar_isLocal && isset($id_user)) {
    $_navbar_admin_check = mysqli_query($conn, 'SELECT okr FROM staff WHERE id = ' . (int)$id_user);
    if ($_navbar_admin_check && ($_navbar_admin_row = mysqli_fetch_assoc($_navbar_admin_check))) {
        $_navbar_realIsAdmin = ((int)$_navbar_admin_row['okr'] === 1);
    }
}

if ($_navbar_isLocal && $_navbar_realIsAdmin) {
    if (session_id() == '') { session_start(); }

    $_navbar_gradeLabels = [
        0 => 'No access', 1 => 'Grade 1', 2 => 'Grade 2',
        3 => 'Grade 3 - Create', 4 => 'Grade 4', 5 => 'Grade 5 - CEO',
    ];
    $_navbar_activeRole      = isset($_SESSION['okr_dev_role_override']) ? (int)$_SESSION['okr_dev_role_override'] : null;
    $_navbar_activeRoleLabel = ($_navbar_activeRole !== null && isset($_navbar_gradeLabels[$_navbar_activeRole]))
        ? $_navbar_gradeLabels[$_navbar_activeRole] : 'DB Default';
    $_navbar_currentUri = $_SERVER['REQUEST_URI'] ?? '/odb/okr/index.php';
?>
<div style="background:#12122a;color:#d0d0f0;padding:5px 14px;font-size:11px;font-family:monospace;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #333;">
    <span style="color:#888;letter-spacing:.05em;">DEV GRADE</span>
    <strong style="color:#f0c040;">[<?php echo htmlspecialchars($_navbar_activeRoleLabel); ?>]</strong>
    <?php foreach ($_navbar_gradeLabels as $_r => $_label): ?>
    <form method="POST" action="/odb/okr/dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="<?php echo $_r; ?>">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit" style="background:<?php echo ($_navbar_activeRole === $_r ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeRole === $_r ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeRole === $_r ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_r; ?>: <?php echo $_label; ?></button>
    </form>
    <?php endforeach; ?>
    <?php if ($_navbar_activeRole !== null): ?>
    <form method="POST" action="/odb/okr/dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="clear">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit" style="background:#3a1010;color:#ff8888;border:1px solid #a44;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;">Clear Override</button>
    </form>
    <?php endif; ?>
</div>
<?php } ?>
<?php
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

$dashboard_active   = ($current_dir == 'okr' && $current_page == 'index.php') ? 'active' : '';
$list_active        = ($current_dir == 'okr' && in_array($current_page, ['list.php', 'view.php', 'create.php', 'edit.php'])) ? 'active' : '';

// Performance is ATEM's own page (atem/staff_performance/index.php) - OKR has
// no Performance page of its own, so this links cross-module the same way
// Access Control/Masterlist/Admin do below. Same visibility rule as ATEM's
// own navbar: grade 2+, People Management (dept 17), or SuperAdmin.
$_navbar_dept_ids = [];
if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_navbar_d) {
        $_navbar_d = (int)trim($_navbar_d);
        if ($_navbar_d > 0) { $_navbar_dept_ids[] = $_navbar_d; }
    }
}
$show_performance = ($okr_permission >= 2 || $okr_is_admin || in_array(17, $_navbar_dept_ids, true));

// ATEM has separate production ("atem") and staging ("atem-staging") copies
// on the live server; only "atem" exists locally. Cross-links from OKR must
// follow whichever copy is being tested, so pick the sibling folder the same
// way ATEM's own environment detection does (localhost vs everything else) -
// not by OKR's own folder name, since OKR itself has no staging copy.
$_navbar_atem_folder = $_navbar_isLocal ? 'atem' : 'atem-staging';
?>
<nav class="okr-nav navbar navbar-expand-lg navbar-light mb-3">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link <?php echo $dashboard_active; ?>" href="okr/index.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $_navbar_atem_folder; ?>/index.php">ATEM</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $list_active; ?>" href="okr/list.php">OKR</a>
                </li>
                <?php if ($show_performance): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $_navbar_atem_folder; ?>/staff_performance/index.php">Performance</a>
                </li>
                <?php endif; ?>
                <?php if ($okr_is_admin): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $_navbar_atem_folder; ?>/access_control/index.php">Access Control</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $_navbar_atem_folder; ?>/access_control/masterlist.php">Masterlist</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $_navbar_atem_folder; ?>/admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
