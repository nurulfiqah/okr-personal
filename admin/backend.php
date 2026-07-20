<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json');

if (session_id() == '') {
    session_start();
}

if (!isset($_SESSION['myusername'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$connect = 1;
include(__DIR__ . '/../../common/index_adv.php');

if (!isset($conn)) {
    echo json_encode(['error' => 'Database connection error']);
    exit;
}

$username    = mysqli_real_escape_string($conn, $_SESSION['myusername']);
$auth_result = mysqli_query($conn, "SELECT okr, atem FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$auth_row      = mysqli_fetch_assoc($auth_result);
// SuperAdmin is the union of staff.okr and staff.atem.
$db_is_admin   = ((int)$auth_row['okr'] === 1 || (int)$auth_row['atem'] === 1);

if (!$db_is_admin) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'toggleBackdate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_value = (isset($_POST['value']) && (int)$_POST['value'] === 1) ? '1' : '0';
    mysqli_query($conn,
        "INSERT INTO okr_config (setting_key, setting_value) VALUES ('backdate_enabled', '$new_value')
         ON DUPLICATE KEY UPDATE setting_value = '$new_value'"
    );
    echo json_encode(['success' => true, 'value' => (int)$new_value]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
