<?php
/**
 * OKR base layout - top partial.
 *
 * A page includes this after optionally setting:
 *   $page_title  - shown in <title> and as the page heading (default "OKR")
 *   $page_js     - optional page-specific script path, emitted by footer.php
 */
// lock_adv.php echoes HTML before the permission check below can call
// header('Location: ...'), so buffer output here to keep redirects working.
ob_start();
$page_title = $page_title ?? 'OKR';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="/odb/okr/css/logo.svg">
    <base href="/odb/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="okr/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<?php
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');

$okr_permission = (int)($grade ?? 0);

// SuperAdmin recognition is the union of both module flags: staff.atem is
// already available via lock_adv.php ($atem); staff.okr is queried directly
// since lock_adv.php does not set it. Either flag being set to 1 grants full
// SuperAdmin access to OKR (and, symmetrically, to ATEM - see atem/header.php).
$okr_is_admin = (isset($atem) && (int)$atem === 1);
if (isset($id_user)) {
    $admin_check = mysqli_query($conn, 'SELECT okr FROM staff WHERE id = ' . (int)$id_user);
    if ($admin_check && ($admin_row = mysqli_fetch_assoc($admin_check))) {
        $okr_is_admin = $okr_is_admin || ((int)$admin_row['okr'] === 1);
    }
}

// Dev grade override (localhost only), mirrors ATEM's atem_dev_role_override pattern.
if (isset($_SESSION['okr_dev_role_override'])) {
    $okr_permission = (int)$_SESSION['okr_dev_role_override'];
    $okr_is_admin = false;
}

if ($okr_permission === 0 && !$okr_is_admin) {
    if (isset($_SESSION['okr_dev_role_override'])) {
        unset($_SESSION['okr_dev_role_override']);
        header('Location: /odb/okr/index.php');
    } else {
        header('Location: /odb/index.php');
    }
    exit;
}

// list.php's "New OKR" button follows the same create-permission gate as
// create.php itself (grade 3+ or admin). Set here, not in list.php, because
// $okr_permission/$okr_is_admin aren't known until lock_adv.php runs above,
// and the title row below is rendered inline as part of this same include -
// by the time include('header.php') returns to the caller, the row has
// already been echoed.
if (!isset($page_title_actions) && basename($_SERVER['PHP_SELF']) === 'list.php' && ($okr_permission >= 3 || $okr_is_admin)) {
    $page_title_actions = '<a href="okr/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New OKR</a>';
}
?>

<body>
    <?php include(dirname(__FILE__) . '/navbar.php'); ?>
    <div class="header" style="position: relative;">
        <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
        <h1 class="headerH1"><img src='/odb/okr/css/logo.svg' width='20px'>OKR</h1>
        <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
    </div>
    <div class="okr-container mb-3">

        <div class="row mb-4">
            <div class="col-12 d-flex align-items-start justify-content-between flex-wrap gap-2">
                <h1 class="okr-page-title mb-0"><?php echo htmlspecialchars($page_title); ?><?php echo isset($page_title_badge) ? ' ' . $page_title_badge : ''; ?></h1>
                <?php if (!empty($page_title_actions)): ?>
                <div><?php echo $page_title_actions; ?></div>
                <?php endif; ?>
            </div>
        </div>
