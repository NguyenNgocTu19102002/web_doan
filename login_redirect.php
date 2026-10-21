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

function login_is_ban_phu_huynh($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    return login_role_normalized($role) === 'ban_phu_huynh';
}

/** Menu "Báo cáo Đoàn sinh": HT, HDT, admin, Ban Phụ Huynh. */
function login_can_view_student_report($role = null) {
    if ($role === null) {
        $role = login_current_role();
    }
    $r = login_role_normalized($role);
    return in_array($r, array('admin', 'hdt_manager', 'huynh_truong', 'ban_phu_huynh'), true);
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

/**
 * Bật cờ hiện thư bổ nhiệm (chỉ Huynh Trưởng).
 * Đã tắt — không còn hiện thư bổ nhiệm sau đăng nhập.
 */
function login_enable_bo_nhiem_letter($role) {
    if (isset($_SESSION['show_bo_nhiem'])) {
        unset($_SESSION['show_bo_nhiem']);
    }
    return false;
}

/**
 * Trang đích sau đăng nhập — luôn quay lại trang gọi login (?redirect=).
 */
function login_post_auth_path($role, $fallback_redirect = 'index.php') {
    return get_safe_login_redirect($fallback_redirect);
}

/** Popup thư bổ nhiệm HT — đã tắt. Gắn kèm popup sinh nhật tuần. */
function login_include_bo_nhiem_popup() {
    login_include_sinh_nhat_popup();
}

/** Popup chúc mừng sinh nhật (T2–CN) cho tài khoản có ngày sinh trong tuần. */
function login_include_sinh_nhat_popup() {
    $path = __DIR__ . '/includes/sinh_nhat_popup.php';
    if (is_file($path)) {
        include $path;
    }
}

/**
 * Kiểm tra user hiện tại có phải Trưởng Lớp / Phó Lớp của class_id không
 * (qua users.teacher_id → class_teachers).
 */
function login_is_class_leader($conn, $class_id, $user_id = null) {
    $class_id = (int) $class_id;
    if ($class_id <= 0 || !($conn instanceof mysqli)) {
        return false;
    }
    if ($user_id === null) {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $user_id = (int) $_SESSION['user_id'];
    } else {
        $user_id = (int) $user_id;
    }
    if ($user_id <= 0) {
        return false;
    }
    $sql = "SELECT ct.vai_tro
            FROM users u
            INNER JOIN class_teachers ct ON ct.teacher_id = u.teacher_id
            WHERE u.id = $user_id
              AND u.teacher_id IS NOT NULL
              AND ct.class_id = $class_id
              AND (
                    ct.vai_tro = 'Trưởng Lớp'
                 OR ct.vai_tro = 'Phó Lớp'
                 OR ct.vai_tro LIKE '%Trưởng Lớp%'
                 OR ct.vai_tro LIKE '%Phó Lớp%'
              )
            LIMIT 1";
    $res = @$conn->query($sql);
    return ($res && $res->num_rows > 0);
}

/** Thứ tự ngành dùng trong ORDER BY. */
function login_nganh_field_sql($column = 'nganh') {
    return "FIELD($column, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ')";
}

/**
 * Các lớp user đang phụ trách (qua users.teacher_id → class_teachers).
 * @return array<int, array{id:int,ten_lop:string,nganh:string}>
 */
function login_user_assigned_classes($conn, $user_id = null) {
    $out = array();
    if (!($conn instanceof mysqli)) {
        return $out;
    }
    if ($user_id === null) {
        if (empty($_SESSION['user_id'])) {
            return $out;
        }
        $user_id = (int) $_SESSION['user_id'];
    } else {
        $user_id = (int) $user_id;
    }
    if ($user_id <= 0) {
        return $out;
    }
    $sql = "SELECT DISTINCT c.id, c.ten_lop, c.nganh
            FROM users u
            INNER JOIN class_teachers ct ON ct.teacher_id = u.teacher_id
            INNER JOIN classes c ON c.id = ct.class_id
            WHERE u.id = $user_id
              AND u.teacher_id IS NOT NULL
            ORDER BY " . login_nganh_field_sql('c.nganh') . ", c.ten_lop";
    $res = @$conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = array(
                'id' => (int) $row['id'],
                'ten_lop' => (string) $row['ten_lop'],
                'nganh' => (string) $row['nganh'],
            );
        }
    }
    return $out;
}

/** Admin / HDT được quản lý tài khoản đăng nhập. */
function login_can_manage_accounts($role = null) {
    return login_is_hdt_manager($role);
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

/* ===== Lưu đăng nhập (remember me) =====
 * Cookie = selector:validator. DB chỉ lưu sha256(validator) → lộ DB không dùng được cookie.
 * Mỗi lần tự đăng nhập lại sẽ đổi token mới.
 */
define('LOGIN_REMEMBER_COOKIE', 'tntt_remember');
define('LOGIN_REMEMBER_DAYS', 30);

function login_random_hex($bytes) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $raw = openssl_random_pseudo_bytes($bytes, $strong);
        if ($raw !== false && $strong) {
            return bin2hex($raw);
        }
    }
    if (function_exists('mcrypt_create_iv')) {
        return bin2hex(mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM));
    }
    return '';
}

function login_remember_ensure_table($conn) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS user_remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_selector (selector),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function login_remember_set_cookie($value, $expire) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    setcookie(LOGIN_REMEMBER_COOKIE, $value, $expire, app_base_path() . '/', '', $https, true);
    if ($value === '') {
        unset($_COOKIE[LOGIN_REMEMBER_COOKIE]);
    } else {
        $_COOKIE[LOGIN_REMEMBER_COOKIE] = $value;
    }
}

/** Tạo token lưu đăng nhập cho user và gửi cookie. */
function login_remember_issue($conn, $user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !($conn instanceof mysqli)) {
        return false;
    }
    $selector = login_random_hex(12);
    $validator = login_random_hex(32);
    if ($selector === '' || $validator === '') {
        return false;
    }
    login_remember_ensure_table($conn);
    $conn->query("DELETE FROM user_remember_tokens WHERE expires_at < NOW()");
    $expire = time() + LOGIN_REMEMBER_DAYS * 86400;
    $hash = hash('sha256', $validator);
    $ok = $conn->query("INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at)
        VALUES ($user_id, '$selector', '$hash', '" . date('Y-m-d H:i:s', $expire) . "')");
    if (!$ok) {
        return false;
    }
    login_remember_set_cookie($selector . ':' . $validator, $expire);
    return true;
}

/** Tự đăng nhập từ cookie (khi session đã hết). */
function login_remember_try($conn) {
    $raw = isset($_COOKIE[LOGIN_REMEMBER_COOKIE]) ? (string) $_COOKIE[LOGIN_REMEMBER_COOKIE] : '';
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m) || !($conn instanceof mysqli)) {
        login_remember_set_cookie('', time() - 3600);
        return false;
    }
    login_remember_ensure_table($conn);
    $selector = $m[1];
    $res = $conn->query("SELECT t.id, t.user_id, t.token_hash, u.role
        FROM user_remember_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.selector = '$selector' AND t.expires_at > NOW()
        LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $m[2]))) {
        if ($row) {
            $conn->query("DELETE FROM user_remember_tokens WHERE id = " . (int) $row['id']);
        }
        login_remember_set_cookie('', time() - 3600);
        return false;
    }
    $conn->query("DELETE FROM user_remember_tokens WHERE id = " . (int) $row['id']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['user_id'];
    $_SESSION['user_role'] = isset($row['role']) && $row['role'] !== '' ? trim((string) $row['role']) : 'admin';
    login_remember_issue($conn, $row['user_id']);
    return true;
}

/** Hủy lưu đăng nhập trên thiết bị hiện tại (đăng xuất). */
function login_remember_forget($conn) {
    $raw = isset($_COOKIE[LOGIN_REMEMBER_COOKIE]) ? (string) $_COOKIE[LOGIN_REMEMBER_COOKIE] : '';
    if ($conn instanceof mysqli && preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
        login_remember_ensure_table($conn);
        $conn->query("DELETE FROM user_remember_tokens WHERE selector = '" . $m[1] . "'");
    }
    login_remember_set_cookie('', time() - 3600);
}

/** Hủy mọi phiên lưu đăng nhập của user (đổi mật khẩu / xóa tài khoản). */
function login_remember_revoke_user($conn, $user_id) {
    if (!($conn instanceof mysqli)) {
        return;
    }
    login_remember_ensure_table($conn);
    $conn->query("DELETE FROM user_remember_tokens WHERE user_id = " . (int) $user_id);
}

/** Nạp db_connect.php từ trong hàm nhưng đưa biến ra global (trang gọi require_once sau sẽ bỏ qua). */
function login_remember_bootstrap() {
    require_once __DIR__ . '/db_connect.php';
    foreach (get_defined_vars() as $k => $v) {
        $GLOBALS[$k] = $v;
    }
    return isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
}

if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE
    && empty($_SESSION['user_id']) && !empty($_COOKIE[LOGIN_REMEMBER_COOKIE])) {
    $tntt_remember_conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli)
        ? $GLOBALS['conn']
        : login_remember_bootstrap();
    login_remember_try($tntt_remember_conn);
    unset($tntt_remember_conn);
}
