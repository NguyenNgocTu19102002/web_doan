<?php
/**
 * Redirect đăng nhập + thư bổ nhiệm (Huynh Trưởng).
 *
 * Luồng HT:
 *   login thành công → $_SESSION['show_bo_nhiem']=1 → quay lại trang ?redirect= → include bo_nhiem_popup.php
 *   đóng thư → POST dismiss_bo_nhiem.php → unset flag
 *
 * Local:  /tntt/tai_khoan.php
 * Hosting: /tai_khoan.php
 */

// Hosting Linux: require_once 'file.php' và require_once __DIR__.'/file.php' có thể nạp 2 lần → 500
if (defined('TNTT_LOGIN_REDIRECT_LOADED')) {
    return;
}
define('TNTT_LOGIN_REDIRECT_LOADED', true);

/** Trang hiển thị thư bổ nhiệm sau đăng nhập HT */
if (!defined('LOGIN_BO_NHIEM_PAGE')) {
    define('LOGIN_BO_NHIEM_PAGE', 'tai_khoan.php');
}

function app_base_path() {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }
    return $base;
}

function app_url($path, $absolute = false) {
    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    $url = app_base_path() . '/' . $path;
    if (!$absolute) {
        return $url;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host . $url;
}

function login_return_url() {
    $url = basename($_SERVER['PHP_SELF']);
    if (!empty($_SERVER['QUERY_STRING'])) {
        $url .= '?' . $_SERVER['QUERY_STRING'];
    }
    return $url;
}

function login_page_url($return_url = null) {
    if ($return_url === null) {
        $return_url = login_return_url();
    }
    return app_url('login.php?redirect=' . rawurlencode($return_url));
}

function get_safe_login_redirect($raw) {
    if ($raw === null || $raw === '') {
        return 'index.php';
    }
    $url = urldecode((string)$raw);
    if (preg_match('#^(https?://|//)#i', $url) || strpos($url, '..') !== false) {
        return 'index.php';
    }
    if (preg_match('#([a-zA-Z0-9_\-]+\.php)(\?[^\#]*)?$#', $url, $m)) {
        return $m[1] . (isset($m[2]) ? $m[2] : '');
    }
    return 'index.php';
}

function login_role_normalized($role) {
    return strtolower(trim((string)$role));
}

function login_is_huynh_truong($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    return login_role_normalized($role) === 'huynh_truong';
}

function login_current_role() {
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== '') {
        return (string) $_SESSION['user_role'];
    }
    if (isset($_SESSION['role']) && $_SESSION['role'] !== '') {
        return (string) $_SESSION['role'];
    }
    return '';
}

function login_is_admin($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    return login_role_normalized($role) === 'admin';
}

function login_is_hdt_manager($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    $r = login_role_normalized($role);
    return $r === 'hdt_manager' || $r === 'admin';
}

/** Quản lý năm học: lưu tổng kết, kết thúc năm (HDT / admin). */
function login_can_manage_academic_year($role = null) {
    return login_is_hdt_manager($role);
}

/** Xem tổng kết năm học đã lưu (HDT / admin / Huynh Trưởng). */
function login_can_view_year_summary($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    $r = login_role_normalized($role);
    return in_array($r, array('admin', 'hdt_manager', 'huynh_truong'), true);
}

/** Trang danh sách năm học theo vai trò. */
function login_year_summary_list_page($role = null) {
    if (login_is_huynh_truong($role)) {
        return 'nien_hoc_xem.php';
    }
    return 'nien_hoc.php';
}

/** Bật cờ hiện thư bổ nhiệm (chỉ Huynh Trưởng). */
function login_enable_bo_nhiem_letter($role) {
    if (login_is_huynh_truong($role)) {
        $_SESSION['show_bo_nhiem'] = 1;
        return true;
    }
    return false;
}

/**
 * Trang đích sau đăng nhập — luôn quay lại trang gọi login (?redirect=).
 */
function login_post_auth_path($role, $fallback_redirect = 'index.php') {
    return get_safe_login_redirect($fallback_redirect);
}

/** Popup thư bổ nhiệm HT — gọi trước </body> trên trang có thể quay lại sau đăng nhập. */
function login_include_bo_nhiem_popup() {
    $path = __DIR__ . '/includes/bo_nhiem_popup.php';
    if (is_file($path)) {
        include $path;
    }
}

/** Redirect HTTP sau đăng nhập thành công. */
function login_redirect_after_auth($role, $fallback_redirect = 'index.php') {
    login_enable_bo_nhiem_letter($role);
    header('Location: ' . app_url(login_post_auth_path($role, $fallback_redirect), true));
    exit;
}

/** Đã đăng nhập mà mở login.php — quay lại trang redirect (hoặc index). */
function login_redirect_if_already_authenticated($redirect_get = '') {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    header('Location: ' . app_url(get_safe_login_redirect($redirect_get), true));
    exit;
}

function require_login_redirect() {
    header('Location: ' . app_url('login.php?redirect=' . rawurlencode(login_return_url()), true));
    exit;
}
