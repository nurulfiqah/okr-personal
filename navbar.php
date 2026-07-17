<?php
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

$dashboard_active   = ($current_dir == 'okr' && $current_page == 'index.php') ? 'active' : '';
$list_active        = ($current_dir == 'okr' && in_array($current_page, ['list.php', 'view.php', 'create.php', 'edit.php'])) ? 'active' : '';
$performance_active = ($current_dir == 'okr' && $current_page == 'performance.php') ? 'active' : '';
$admin_settings_active = ($current_dir == 'admin' && $current_page == 'index.php') ? 'active' : '';
$show_performance   = ($okr_permission >= 4 || $okr_is_admin);
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
                    <a class="nav-link <?php echo $list_active; ?>" href="okr/list.php">OKR</a>
                </li>
                <?php if ($show_performance): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $performance_active; ?>" href="okr/performance.php">Performance</a>
                </li>
                <?php endif; ?>
                <?php if ($okr_is_admin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_settings_active; ?>" href="okr/admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
