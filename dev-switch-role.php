<?php
/**
 * Development-only role switcher for OKR testing.
 * Sets a session override for the okr grade so pages/backend calls simulate
 * a different staff.grade without touching the database.
 *
 * This file must NOT be deployed to production.
 */
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_id() == '') {
    session_start();
}

// Restrict to localhost only
$serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$httpHost   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isLocal    = in_array($serverName, array('localhost', '127.0.0.1'))
    || strpos($serverName, 'localhost') !== false
    || strpos($httpHost, 'localhost') !== false
    || strpos($httpHost, '127.0.0.1') !== false;

if (!$isLocal) {
    http_response_code(403);
    echo 'This endpoint is not available in production.';
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$allowed_roles = array(0, 1, 2, 3, 4, 5);
$role_input    = isset($_POST['role']) ? $_POST['role'] : null;

if ($role_input === 'clear') {
    unset($_SESSION['okr_dev_role_override']);
} elseif ($role_input !== null) {
    $role = (int)$role_input;
    if (in_array($role, $allowed_roles)) {
        $_SESSION['okr_dev_role_override'] = $role;
    }
}

$redirect = isset($_POST['redirect']) && $_POST['redirect'] !== ''
    ? $_POST['redirect']
    : '/odb/okr/index.php';

// Only allow relative redirects to prevent open redirect
if (strpos($redirect, '://') !== false) {
    $redirect = '/odb/okr/index.php';
}

header('Location: ' . $redirect);
exit;
