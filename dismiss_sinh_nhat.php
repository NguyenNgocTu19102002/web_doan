<?php
/**
 * Đóng popup sinh nhật tuần này (không hiện lại đến tuần sau).
 */
session_start();
require_once __DIR__ . '/login_redirect.php';

$week_key = isset($_POST['week_key']) ? trim((string)$_POST['week_key']) : '';
if ($week_key === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_key)) {
    require_once __DIR__ . '/includes/sinh_nhat.php';
    $w = sn_week_bounds();
    $week_key = $w['key'];
}

$_SESSION['sinh_nhat_dismissed_week'] = $week_key;

$return = isset($_POST['return']) ? (string)$_POST['return'] : 'index.php';
// Chỉ cho phép path tương đối an toàn
if (preg_match('#^(https?://|//)#i', $return) || strpos($return, '..') !== false) {
    $return = 'index.php';
}
if ($return === '' || $return[0] !== '/') {
    // relative URL like /tntt/classes.php or classes.php
    if (preg_match('#^/[a-zA-Z0-9_\-./?=&%]*$#', $return)) {
        header('Location: ' . $return);
        exit;
    }
    $safe = get_safe_login_redirect($return);
    header('Location: ' . app_url($safe, true));
    exit;
}

header('Location: ' . $return);
exit;
