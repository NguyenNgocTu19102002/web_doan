<?php
/**
 * Chúc mừng sinh nhật Huynh Trưởng — schema, danh sách tháng/tuần, lời chúc.
 * PHP 5.6 compatible.
 */
if (defined('TNTT_SINH_NHAT_LOADED')) {
    return;
}
define('TNTT_SINH_NHAT_LOADED', true);

function sn_month_names() {
    return array(
        1 => 'Một', 2 => 'Hai', 3 => 'Ba', 4 => 'Bốn', 5 => 'Năm', 6 => 'Sáu',
        7 => 'Bảy', 8 => 'Tám', 9 => 'Chín', 10 => 'Mười', 11 => 'Mười Một', 12 => 'Mười Hai',
    );
}

function sn_current_year_month() {
    return date('Y-m');
}

function sn_month_label($ym = null) {
    if ($ym === null || $ym === '') {
        $ym = sn_current_year_month();
    }
    $parts = explode('-', $ym);
    $m = isset($parts[1]) ? (int)$parts[1] : (int)date('n');
    return 'Tháng ' . $m;
}

function sn_month_label_vn($ym = null) {
    if ($ym === null || $ym === '') {
        $ym = sn_current_year_month();
    }
    $parts = explode('-', $ym);
    $m = isset($parts[1]) ? (int)$parts[1] : (int)date('n');
    $names = sn_month_names();
    $name = isset($names[$m]) ? $names[$m] : (string)$m;
    return 'Tháng ' . $name;
}

function sn_avatar_url($avatar) {
    if (empty($avatar)) {
        return '';
    }
    if (function_exists('ht_profile_avatar_url')) {
        $url = ht_profile_avatar_url($avatar);
    } else {
        if (preg_match('#^https?://#i', $avatar)) {
            return $avatar;
        }
        $url = ltrim(str_replace('\\', '/', $avatar), '/');
    }
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $root = dirname(__DIR__);
    if (is_file($root . '/' . $url)) {
        return $url;
    }
    return '';
}

function sn_initials($ho_ten, $ten_thanh = '') {
    $name = trim((string) $ho_ten);
    $parts = preg_split('/\s+/u', $name);
    $last = $parts ? end($parts) : '';
    if (function_exists('mb_substr')) {
        $first = mb_substr($last !== '' ? $last : '?', 0, 1, 'UTF-8');
        $saint = $ten_thanh !== '' ? mb_substr(trim($ten_thanh), 0, 1, 'UTF-8') : '';
        return mb_strtoupper($saint . $first, 'UTF-8');
    }
    $first = substr($last !== '' ? $last : '?', 0, 1);
    $saint = $ten_thanh !== '' ? substr(trim($ten_thanh), 0, 1) : '';
    return strtoupper($saint . $first);
}

/**
 * Xác định nữ theo gioi_tinh; nếu trống thì đoán từ tên thánh phổ biến.
 */
function sn_is_female($gioi_tinh, $ten_thanh = '') {
    $g = trim((string) $gioi_tinh);
    if ($g === 'Nữ' || strcasecmp($g, 'Nu') === 0 || strcasecmp($g, 'female') === 0) {
        return true;
    }
    if ($g === 'Nam' || strcasecmp($g, 'male') === 0) {
        return false;
    }
    $tt = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $ten_thanh), 'UTF-8')
        : strtolower(trim((string) $ten_thanh));
    if ($tt === '') {
        return false;
    }
    if (strpos($tt, 'maria') === 0 || strpos($tt, 'anê') === 0 || strpos($tt, 'ane') === 0
        || strpos($tt, 'anna') === 0 || strpos($tt, 'têrê') === 0 || strpos($tt, 'tere') === 0
        || strpos($tt, 'lucia') === 0 || strpos($tt, 'rosa') === 0 || strpos($tt, 'isave') === 0
        || strpos($tt, 'elisa') === 0 || strpos($tt, 'caterina') === 0 || strpos($tt, 'katerina') === 0) {
        return true;
    }
    return false;
}

function sn_week_bounds($time = null) {
    if ($time === null) {
        $time = time();
    }
    $dow = (int) date('N', $time); // 1=Mon .. 7=Sun
    $monday_ts = strtotime('-' . ($dow - 1) . ' days', $time);
    $sunday_ts = strtotime('+' . (7 - $dow) . ' days', $time);
    return array(
        'start' => date('Y-m-d', $monday_ts),
        'end' => date('Y-m-d', $sunday_ts),
        'key' => date('Y-m-d', $monday_ts),
    );
}

function sn_md_in_week($ngay_sinh, $week_start, $week_end) {
    if (empty($ngay_sinh) || $ngay_sinh === '0000-00-00') {
        return false;
    }
    $md = date('m-d', strtotime($ngay_sinh));
    if (!$md) {
        return false;
    }
    $t = strtotime($week_start);
    $tend = strtotime($week_end);
    if ($t === false || $tend === false) {
        return false;
    }
    while ($t <= $tend) {
        if (date('m-d', $t) === $md) {
            return true;
        }
        $t += 86400;
    }
    return false;
}

function sn_ensure_schema($conn) {
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $conn->query("CREATE TABLE IF NOT EXISTS birthday_wishes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        `year_month` CHAR(7) NOT NULL,
        user_id INT NULL,
        author_name VARCHAR(150) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_ym (`year_month`),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return true;
}

/** Xóa lời chúc của các tháng trước tháng hiện tại. */
function sn_purge_old_wishes($conn) {
    sn_ensure_schema($conn);
    $ym = sn_current_year_month();
    $ym_esc = $conn->real_escape_string($ym);
    @$conn->query("DELETE FROM birthday_wishes WHERE `year_month` < '$ym_esc'");
}

function sn_age_this_year($ngay_sinh, $year = null) {
    if ($year === null) {
        $year = (int) date('Y');
    }
    if (empty($ngay_sinh) || $ngay_sinh === '0000-00-00') {
        return null;
    }
    $by = (int) date('Y', strtotime($ngay_sinh));
    if ($by <= 0) {
        return null;
    }
    return $year - $by;
}

/**
 * Danh sách HT có sinh nhật trong tháng (1–12).
 * @return array
 */
function sn_list_month_birthdays($conn, $month = null) {
    $list = array();
    if (!($conn instanceof mysqli)) {
        return $list;
    }
    if ($month === null) {
        $month = (int) date('n');
    }
    $month = (int) $month;
    if ($month < 1 || $month > 12) {
        $month = (int) date('n');
    }

    $sql = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.avatar, t.loai_huynh,
                   (SELECT GROUP_CONCAT(CONCAT(c.ten_lop, ' — ', ct.vai_tro) SEPARATOR ', ')
                    FROM class_teachers ct
                    JOIN classes c ON c.id = ct.class_id
                    WHERE ct.teacher_id = t.id) AS lop_phu_trach
            FROM teachers t
            WHERE t.ngay_sinh IS NOT NULL
              AND t.ngay_sinh != '0000-00-00'
              AND MONTH(t.ngay_sinh) = $month
            ORDER BY DAY(t.ngay_sinh), t.ho_ten";
    $res = @$conn->query($sql);
    if (!$res) {
        return $list;
    }
    $year = (int) date('Y');
    while ($row = $res->fetch_assoc()) {
        $row['ngay_md'] = date('d/m', strtotime($row['ngay_sinh']));
        $row['ngay_full'] = date('d/m/Y', strtotime($row['ngay_sinh']));
        $row['tuoi'] = sn_age_this_year($row['ngay_sinh'], $year);
        $row['day'] = (int) date('j', strtotime($row['ngay_sinh']));
        $list[] = $row;
    }
    return $list;
}

/**
 * HT có sinh nhật trong tuần hiện tại (Thứ 2 → CN).
 */
function sn_list_week_birthdays($conn, $week = null) {
    $list = array();
    if ($week === null) {
        $week = sn_week_bounds();
    }
    $all_monthish = array();
    // Lấy cả tháng của start và end (trường hợp giao tháng)
    $m1 = (int) date('n', strtotime($week['start']));
    $m2 = (int) date('n', strtotime($week['end']));
    $months = array($m1);
    if ($m2 !== $m1) {
        $months[] = $m2;
    }
    foreach ($months as $m) {
        foreach (sn_list_month_birthdays($conn, $m) as $row) {
            $all_monthish[$row['id']] = $row;
        }
    }
    foreach ($all_monthish as $row) {
        if (sn_md_in_week($row['ngay_sinh'], $week['start'], $week['end'])) {
            $list[] = $row;
        }
    }
    usort($list, function ($a, $b) {
        $da = isset($a['day']) ? (int)$a['day'] : 0;
        $db = isset($b['day']) ? (int)$b['day'] : 0;
        if ($da === $db) {
            return strcmp($a['ho_ten'], $b['ho_ten']);
        }
        return $da - $db;
    });
    return $list;
}

/**
 * Tài khoản hiện tại có sinh nhật trong tuần? (qua users.teacher_id)
 * @return array|null teacher row
 */
function sn_current_user_birthday_this_week($conn, $user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !($conn instanceof mysqli)) {
        return null;
    }
    $week = sn_week_bounds();
    $sql = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.avatar, t.gioi_tinh
            FROM users u
            INNER JOIN teachers t ON t.id = u.teacher_id
            WHERE u.id = $user_id
              AND u.teacher_id IS NOT NULL
              AND t.ngay_sinh IS NOT NULL
              AND t.ngay_sinh != '0000-00-00'
            LIMIT 1";
    $res = @$conn->query($sql);
    if (!$res || !($row = $res->fetch_assoc())) {
        return null;
    }
    if (!sn_md_in_week($row['ngay_sinh'], $week['start'], $week['end'])) {
        return null;
    }
    $row['ngay_md'] = date('d/m', strtotime($row['ngay_sinh']));
    $row['tuoi'] = sn_age_this_year($row['ngay_sinh']);
    $row['week'] = $week;
    $row['is_female'] = sn_is_female(
        isset($row['gioi_tinh']) ? $row['gioi_tinh'] : '',
        isset($row['ten_thanh']) ? $row['ten_thanh'] : ''
    );
    $row['avatar_url'] = sn_avatar_url(isset($row['avatar']) ? $row['avatar'] : '');
    $row['initials'] = sn_initials(
        isset($row['ho_ten']) ? $row['ho_ten'] : '',
        isset($row['ten_thanh']) ? $row['ten_thanh'] : ''
    );
    return $row;
}

function sn_should_show_popup($conn, $user_id) {
    $info = sn_current_user_birthday_this_week($conn, $user_id);
    if (!$info) {
        return false;
    }
    $week = isset($info['week']) ? $info['week'] : sn_week_bounds();
    $key = $week['key'];
    if (!empty($_SESSION['sinh_nhat_dismissed_week']) && $_SESSION['sinh_nhat_dismissed_week'] === $key) {
        return false;
    }
    return true;
}

function sn_list_wishes($conn, $ym = null) {
    sn_ensure_schema($conn);
    if ($ym === null) {
        $ym = sn_current_year_month();
    }
    $ym_esc = $conn->real_escape_string($ym);
    $list = array();
    $sql = "SELECT w.*, t.avatar AS teacher_avatar, t.ho_ten AS teacher_name, t.ten_thanh AS teacher_saint
            FROM birthday_wishes w
            LEFT JOIN users u ON u.id = w.user_id
            LEFT JOIN teachers t ON t.id = u.teacher_id
            WHERE w.`year_month` = '$ym_esc'
            ORDER BY w.created_at DESC, w.id DESC";
    $res = @$conn->query($sql);
    if (!$res) {
        $res = @$conn->query("SELECT * FROM birthday_wishes WHERE `year_month` = '$ym_esc' ORDER BY created_at DESC, id DESC");
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $avatar_raw = isset($row['teacher_avatar']) ? $row['teacher_avatar'] : '';
            $row['avatar_url'] = function_exists('sn_avatar_url') ? sn_avatar_url($avatar_raw) : '';
            $row['initials'] = function_exists('sn_initials')
                ? sn_initials(
                    !empty($row['teacher_name']) ? $row['teacher_name'] : $row['author_name'],
                    isset($row['teacher_saint']) ? $row['teacher_saint'] : ''
                )
                : '?';
            $list[] = $row;
        }
    }
    return $list;
}

function sn_add_wish($conn, $user_id, $author_name, $content) {
    sn_ensure_schema($conn);
    sn_purge_old_wishes($conn);
    $content = trim((string) $content);
    $author_name = trim((string) $author_name);
    if ($content === '' || $author_name === '') {
        return array('ok' => false, 'message' => 'Vui lòng nhập nội dung lời chúc.');
    }
    if (mb_strlen($content, 'UTF-8') > 1000) {
        return array('ok' => false, 'message' => 'Lời chúc tối đa 1000 ký tự.');
    }
    $ym = sn_current_year_month();
    $ym_esc = $conn->real_escape_string($ym);
    $an = $conn->real_escape_string($author_name);
    $ct = $conn->real_escape_string($content);
    $uid = (int) $user_id;
    $uid_sql = $uid > 0 ? (string) $uid : 'NULL';
    $ok = $conn->query("INSERT INTO birthday_wishes (`year_month`, user_id, author_name, content) VALUES ('$ym_esc', $uid_sql, '$an', '$ct')");
    if ($ok) {
        return array('ok' => true, 'message' => 'Đã gửi lời chúc.');
    }
    return array('ok' => false, 'message' => 'Không gửi được lời chúc: ' . $conn->error);
}

function sn_update_wish($conn, $wish_id, $content) {
    sn_ensure_schema($conn);
    $wish_id = (int) $wish_id;
    $content = trim((string) $content);
    if ($wish_id <= 0 || $content === '') {
        return array('ok' => false, 'message' => 'Nội dung không hợp lệ.');
    }
    $ct = $conn->real_escape_string($content);
    $ok = $conn->query("UPDATE birthday_wishes SET content = '$ct', updated_at = NOW() WHERE id = $wish_id");
    if ($ok) {
        return array('ok' => true, 'message' => 'Đã cập nhật lời chúc.');
    }
    return array('ok' => false, 'message' => 'Không cập nhật được: ' . $conn->error);
}

function sn_delete_wish($conn, $wish_id) {
    sn_ensure_schema($conn);
    $wish_id = (int) $wish_id;
    if ($wish_id <= 0) {
        return array('ok' => false, 'message' => 'Lời chúc không hợp lệ.');
    }
    $ok = $conn->query("DELETE FROM birthday_wishes WHERE id = $wish_id");
    if ($ok) {
        return array('ok' => true, 'message' => 'Đã xóa lời chúc.');
    }
    return array('ok' => false, 'message' => 'Không xóa được: ' . $conn->error);
}

function sn_author_display_name($conn, $user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 'Ẩn danh';
    }
    $sql = "SELECT u.full_name, u.username, t.ho_ten, t.ten_thanh
            FROM users u
            LEFT JOIN teachers t ON t.id = u.teacher_id
            WHERE u.id = $user_id LIMIT 1";
    $res = @$conn->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        if (!empty($row['ho_ten'])) {
            $name = $row['ho_ten'];
            if (!empty($row['ten_thanh'])) {
                $name = $row['ten_thanh'] . ' ' . $name;
            }
            return $name;
        }
        if (!empty($row['full_name'])) {
            return $row['full_name'];
        }
        if (!empty($row['username'])) {
            return $row['username'];
        }
    }
    return 'Thành viên';
}

/** mb_strlen fallback nếu thiếu mbstring */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) {
        return strlen((string) $s);
    }
}
