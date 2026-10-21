<?php
/**
 * Hồ sơ Huynh Trưởng: đồng bộ từ danh sách + đọc/cập nhật theo user đăng nhập.
 */
if (defined('TNTT_HUYNH_PROFILE_LOADED')) {
    return;
}
define('TNTT_HUYNH_PROFILE_LOADED', true);

/** PHP 5.6: thay cho ?? */
function ht_get($arr, $key, $default = '') {
    if (!is_array($arr) || !isset($arr[$key]) || $arr[$key] === null) {
        return $default;
    }
    return $arr[$key];
}

// Polyfill mbstring (một số hosting tắt extension → HTTP 500)
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $encoding = null) {
        return strtolower((string)$s);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $encoding = null) {
        return strtoupper((string)$s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string)$s, $start) : substr((string)$s, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) {
        return strlen((string)$s);
    }
}

/**
 * Lấy 1 dòng từ mysqli_stmt — tương thích hosting không có mysqlnd (không dùng get_result).
 */
function ht_profile_stmt_fetch_assoc($stmt) {
    if (!$stmt) {
        return null;
    }
    // Cần store_result trước khi bind_result trên nhiều hosting
    @$stmt->store_result();
    $meta = @$stmt->result_metadata();
    if (!$meta) {
        if (method_exists($stmt, 'get_result')) {
            // Một số bản build có get_result nhưng không dùng được sau store_result
            return null;
        }
        return null;
    }
    $row = [];
    $bind = [];
    $fields = [];
    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    $meta->free();
    if (!$fields) {
        return null;
    }
    if (!@call_user_func_array([$stmt, 'bind_result'], $bind)) {
        return null;
    }
    if (!$stmt->fetch()) {
        return null;
    }
    $out = [];
    foreach ($fields as $name) {
        $out[$name] = $row[$name];
    }
    @$stmt->free_result();
    return $out;
}

function ht_profile_unaccent($str) {
    $str = mb_strtolower(trim((string)$str), 'UTF-8');
    $map = [
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
        'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
        'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
        'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
        'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
        'đ'=>'d',
    ];
    return strtr($str, $map);
}

function ht_profile_parse_date_display($s) {
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    return null;
}

function ht_profile_format_date($sql_date) {
    if (!$sql_date || $sql_date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($sql_date);
    return $ts ? date('d/m/Y', $ts) : '';
}

/** Thời gian sinh hoạt từ ngày gia nhập (giống class_details). */
function ht_profile_tenure_text($ngay_gia_nhap) {
    if (!$ngay_gia_nhap || $ngay_gia_nhap === '0000-00-00') {
        return '';
    }
    try {
        $start = new DateTime($ngay_gia_nhap);
        $now = new DateTime();
        $diff = $start->diff($now);
        $parts = array();
        if ($diff->y > 0) {
            $parts[] = $diff->y . ' năm';
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m . ' tháng';
        }
        if (empty($parts)) {
            $parts[] = $diff->d . ' ngày';
        }
        return implode(' ', $parts);
    } catch (Exception $e) {
        return '';
    }
}

function ht_profile_display_or_dash($value) {
    $v = trim((string) $value);
    return $v !== '' ? $v : '—';
}

function ht_profile_ensure_extra_columns($conn) {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;
    // Không ALTER trên trang thường — hosting hay thiếu quyền → exception/500.
    // Chỉ kiểm tra cột; nếu chưa có thì bỏ qua.
}

function ht_profile_normalize_name_key($str) {
    if (class_exists('Normalizer')) {
        $str = Normalizer::normalize($str, Normalizer::FORM_C);
    }
    $str = ht_profile_unaccent($str);
    $str = preg_replace('/[_]+$/', '', $str);
    $str = preg_replace('/[^a-z0-9]+/', '', $str);
    return $str;
}

function ht_profile_avatar_dir() {
    return __DIR__ . '/../uploads/avatars';
}

function ht_profile_avatar_url($avatar) {
    if (!$avatar) {
        return '';
    }
    if (preg_match('#^https?://#i', $avatar)) {
        return $avatar;
    }
    return ltrim(str_replace('\\', '/', $avatar), '/');
}

/**
 * Đồng bộ ảnh đại diện từ thư mục ảnh/ (tên file = Tên thánh + Họ tên).
 */
function ht_profile_sync_avatars_from_folder($conn, $source_dir = null) {
    ht_profile_ensure_extra_columns($conn);
    if ($source_dir === null) {
        $source_dir = __DIR__ . '/../ảnh';
    }
    if (!is_dir($source_dir)) {
        return ['ok' => false, 'message' => 'Không tìm thấy thư mục ảnh', 'matched' => 0, 'unmatched' => []];
    }

    $dest_dir = ht_profile_avatar_dir();
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0775, true);
    }

    $teachers = [];
    $r = $conn->query('SELECT id, ho_ten, ten_thanh FROM teachers');
    while ($t = $r->fetch_assoc()) {
        $full = trim(($t['ten_thanh'] ? $t['ten_thanh'] . ' ' : '') . $t['ho_ten']);
        $teachers[] = [
            'id' => (int)$t['id'],
            'ho_ten' => $t['ho_ten'],
            'full_key' => ht_profile_normalize_name_key($full),
            'name_key' => ht_profile_normalize_name_key($t['ho_ten']),
        ];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $matched = 0;
    $unmatched = [];
    $files = scandir($source_dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $source_dir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $base = pathinfo($file, PATHINFO_FILENAME);
        if ($ext === '') {
            $base = $file;
        }

        if (!in_array($ext, $allowed, true)) {
            $fh = @fopen($path, 'rb');
            $head = $fh ? strtolower(bin2hex(fread($fh, 12))) : '';
            if ($fh) {
                fclose($fh);
            }
            if (strpos($head, 'ffd8ff') === 0) {
                $ext = 'jpg';
            } elseif (strpos($head, '89504e47') === 0) {
                $ext = 'png';
            } elseif (strpos($head, '47494638') === 0) {
                $ext = 'gif';
            } elseif (strpos($head, '52494646') === 0) {
                $ext = 'webp';
            } else {
                $info = @getimagesize($path);
                if (!$info) {
                    $unmatched[] = $file . ' (không phải ảnh)';
                    continue;
                }
                $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
                $ext = isset($map[$info[2]]) ? $map[$info[2]] : '';
                if (!in_array($ext, $allowed, true)) {
                    $unmatched[] = $file . ' (định dạng không hỗ trợ)';
                    continue;
                }
            }
        }

        $base = trim($base);
        $base = rtrim($base, " _.-");
        $file_key = ht_profile_normalize_name_key($base);

        $found = null;
        foreach ($teachers as $t) {
            if ($file_key === $t['full_key'] || $file_key === $t['name_key']) {
                $found = $t;
                break;
            }
        }
        // fallback: bỏ "thi" ở giữa (vd: Anna Bùi Khánh Ly ↔ Anna Bùi Thị Khánh Ly)
        if (!$found) {
            $file_key_noti = str_replace('thi', '', $file_key);
            foreach ($teachers as $t) {
                $full_noti = str_replace('thi', '', $t['full_key']);
                $name_noti = str_replace('thi', '', $t['name_key']);
                if ($file_key_noti !== '' && ($file_key_noti === $full_noti || $file_key_noti === $name_noti)) {
                    $found = $t;
                    break;
                }
            }
        }
        // fallback: file chứa họ tên hoặc họ tên chứa phần cuối tên file
        if (!$found) {
            foreach ($teachers as $t) {
                if ($t['name_key'] !== '' && (strpos($file_key, $t['name_key']) !== false || strpos($t['full_key'], $file_key) !== false)) {
                    $found = $t;
                    break;
                }
            }
        }

        if (!$found) {
            $unmatched[] = $file;
            continue;
        }

        $dest_name = 'teacher_' . $found['id'] . '.' . $ext;
        $dest_path = $dest_dir . DIRECTORY_SEPARATOR . $dest_name;
        if (!copy($path, $dest_path)) {
            $unmatched[] = $file . ' (copy lỗi)';
            continue;
        }

        $rel = 'uploads/avatars/' . $dest_name;
        $stmt = $conn->prepare('UPDATE teachers SET avatar=? WHERE id=?');
        $stmt->bind_param('si', $rel, $found['id']);
        $stmt->execute();
        $stmt->close();
        $matched++;
    }

    return ['ok' => true, 'matched' => $matched, 'unmatched' => $unmatched];
}

function ht_profile_load_json_list() {
    $path = __DIR__ . '/../data/huynh_truong_list.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** Số huynh trưởng theo danh sách chính thức (data/huynh_truong_list.json). */
function ht_profile_count_list() {
    $list = ht_profile_load_json_list();
    if (!$list) {
        return 0;
    }
    $count = 0;
    foreach ($list as $p) {
        if (trim((string) ht_get($p, 'ho_ten')) !== '') {
            $count++;
        }
    }
    return $count;
}

/**
 * Đồng bộ thông tin từ JSON (xuất từ danh_sach_huynh_truong.xlsx) vào bảng teachers.
 */
function ht_profile_sync_from_list($conn) {
    ht_profile_ensure_extra_columns($conn);
    $list = ht_profile_load_json_list();
    if (!$list) {
        return ['ok' => false, 'message' => 'Không tìm thấy data/huynh_truong_list.json', 'updated' => 0];
    }

    $teachers = [];
    $r = $conn->query('SELECT id, ho_ten FROM teachers');
    while ($t = $r->fetch_assoc()) {
        $teachers[ht_profile_unaccent($t['ho_ten'])] = (int)$t['id'];
    }

    $updated = 0;
    $missing = [];
    foreach ($list as $p) {
        $ho_ten = trim(ht_get($p, 'ho_ten'));
        if ($ho_ten === '') {
            continue;
        }
        $key = ht_profile_unaccent($ho_ten);
        if (!isset($teachers[$key])) {
            $missing[] = $ho_ten;
            continue;
        }
        $id = $teachers[$key];
        $ten_thanh = trim(ht_get($p, 'ten_thanh'));
        $gioi_tinh = trim(ht_get($p, 'gioi_tinh'));
        if ($gioi_tinh !== 'Nam' && $gioi_tinh !== 'Nữ') {
            $gioi_tinh = null;
        }
        $ngay_sinh = ht_profile_parse_date_display(ht_get($p, 'ngay_sinh'));
        $sdt = preg_replace('/\D+/', '', (string) ht_get($p, 'sdt'));
        $email = trim(ht_get($p, 'email'));
        $cap = trim(ht_get($p, 'cap_bac'));
        $loai = null;
        if ($cap === 'Huynh Trưởng' || $cap === 'Huynh Dự Trưởng' || $cap === 'Dự Trưởng') {
            $loai = ($cap === 'Huynh Trưởng') ? 'Huynh Trưởng' : 'Huynh Dự Trưởng';
        }
        $lop = trim(ht_get($p, 'lop'));
        $nganh = trim(ht_get($p, 'nganh'));
        $vai_tro = trim(ht_get($p, 'vai_tro'));

        $sets = [
            'ten_thanh=?',
            'so_dien_thoai=?',
            'email=?',
            'lop_phu_trach=?',
            'nganh_phu_trach=?',
            'vai_tro_lop=?',
        ];
        $types = 'ssssss';
        $params = [$ten_thanh, $sdt, $email, $lop, $nganh, $vai_tro];
        if ($gioi_tinh) {
            $sets[] = 'gioi_tinh=?';
            $types .= 's';
            $params[] = $gioi_tinh;
        }
        if ($ngay_sinh) {
            $sets[] = 'ngay_sinh=?';
            $types .= 's';
            $params[] = $ngay_sinh;
        }
        if ($loai) {
            $sets[] = 'loai_huynh=?';
            $types .= 's';
            $params[] = $loai;
        }
        $types .= 'i';
        $params[] = $id;
        $sql = 'UPDATE teachers SET ' . implode(', ', $sets) . ' WHERE id=?';
        $stmt = @$conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $updated++;
        }
        $stmt->close();
    }

    return ['ok' => true, 'updated' => $updated, 'missing' => $missing];
}

/**
 * Lấy hồ sơ đầy đủ của user đang đăng nhập.
 */
function ht_profile_get_by_user_id($conn, $user_id) {
    $user_id = (int)$user_id;

    // Dùng query() + int đã ép kiểu (không phụ thuộc mysqlnd / get_result)
    $sql_full = "SELECT u.id AS user_id, u.username, u.role, u.full_name, u.teacher_id,
                   t.id AS teacher_pk, t.ho_ten, t.ten_thanh, t.gioi_tinh, t.ngay_sinh,
                   t.so_dien_thoai, t.email, t.loai_huynh, t.dia_chi, t.avatar,
                   t.ngay_gia_nhap, t.ngay_rua_toi, t.ngay_them_suc, t.ghi_chu,
                   t.lop_phu_trach, t.nganh_phu_trach, t.vai_tro_lop,
                   (SELECT GROUP_CONCAT(CONCAT(c.ten_lop, ' (', ct.vai_tro, ')') SEPARATOR ', ')
                      FROM class_teachers ct
                      JOIN classes c ON c.id = ct.class_id
                     WHERE ct.teacher_id = t.id) AS lop_dang_day,
                   (SELECT c.ten_lop FROM class_teachers ct
                      JOIN classes c ON c.id = ct.class_id
                     WHERE ct.teacher_id = t.id LIMIT 1) AS ten_lop_he_thong,
                   (SELECT c.nganh FROM class_teachers ct
                      JOIN classes c ON c.id = ct.class_id
                     WHERE ct.teacher_id = t.id LIMIT 1) AS nganh_he_thong,
                   (SELECT ct.vai_tro FROM class_teachers ct
                     WHERE ct.teacher_id = t.id LIMIT 1) AS vai_tro_he_thong
            FROM users u
            LEFT JOIN teachers t ON t.id = u.teacher_id
            WHERE u.id = {$user_id} LIMIT 1";
    $sql_basic = "SELECT u.id AS user_id, u.username, u.role, u.full_name, u.teacher_id,
                   t.id AS teacher_pk, t.ho_ten, t.ten_thanh, t.gioi_tinh, t.ngay_sinh,
                   t.so_dien_thoai, t.email, t.loai_huynh, t.dia_chi,
                   t.ngay_gia_nhap, t.ngay_rua_toi, t.ngay_them_suc, t.ghi_chu,
                   NULL AS avatar, NULL AS lop_phu_trach, NULL AS nganh_phu_trach, NULL AS vai_tro_lop,
                   NULL AS lop_dang_day, NULL AS ten_lop_he_thong, NULL AS nganh_he_thong, NULL AS vai_tro_he_thong
            FROM users u
            LEFT JOIN teachers t ON t.id = u.teacher_id
            WHERE u.id = {$user_id} LIMIT 1";
    $sql_minimal = "SELECT u.id AS user_id, u.username, u.role, u.full_name, u.teacher_id,
                   t.id AS teacher_pk, t.ho_ten, t.ten_thanh, t.gioi_tinh, t.ngay_sinh,
                   t.so_dien_thoai, t.email, t.loai_huynh,
                   NULL AS dia_chi, NULL AS avatar,
                   NULL AS ngay_gia_nhap, NULL AS ngay_rua_toi, NULL AS ngay_them_suc, NULL AS ghi_chu,
                   NULL AS lop_phu_trach, NULL AS nganh_phu_trach, NULL AS vai_tro_lop,
                   NULL AS lop_dang_day, NULL AS ten_lop_he_thong, NULL AS nganh_he_thong, NULL AS vai_tro_he_thong
            FROM users u
            LEFT JOIN teachers t ON t.id = u.teacher_id
            WHERE u.id = {$user_id} LIMIT 1";
    $sql_user_only = "SELECT u.id AS user_id, u.username, u.role, u.full_name, u.teacher_id,
                   NULL AS teacher_pk, u.full_name AS ho_ten, NULL AS ten_thanh, NULL AS gioi_tinh,
                   NULL AS ngay_sinh, NULL AS so_dien_thoai, NULL AS email, NULL AS loai_huynh,
                   NULL AS dia_chi, NULL AS avatar,
                   NULL AS ngay_gia_nhap, NULL AS ngay_rua_toi, NULL AS ngay_them_suc, NULL AS ghi_chu,
                   NULL AS lop_phu_trach, NULL AS nganh_phu_trach, NULL AS vai_tro_lop,
                   NULL AS lop_dang_day, NULL AS ten_lop_he_thong, NULL AS nganh_he_thong, NULL AS vai_tro_he_thong
            FROM users u
            WHERE u.id = {$user_id} LIMIT 1";

    foreach ([$sql_full, $sql_basic, $sql_minimal, $sql_user_only] as $sql) {
        $res = @$conn->query($sql);
        if (!$res) {
            continue;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if ($row) {
            return $row;
        }
    }
    return null;
}

function ht_profile_update_personal($conn, $user_id, $data, &$error = null) {
    ht_profile_ensure_extra_columns($conn);
    $profile = ht_profile_get_by_user_id($conn, $user_id);
    if (!$profile || empty($profile['teacher_id'])) {
        $error = 'Không tìm thấy hồ sơ Huynh Trưởng gắn với tài khoản.';
        return false;
    }

    $ten_thanh = trim(ht_get($data, 'ten_thanh'));
    $ho_ten = trim(ht_get($data, 'ho_ten'));
    $gioi_tinh = trim(ht_get($data, 'gioi_tinh'));
    $ngay_sinh_display = trim(ht_get($data, 'ngay_sinh'));
    $sdt = preg_replace('/\D+/', '', (string) ht_get($data, 'so_dien_thoai'));
    $email = trim(ht_get($data, 'email'));
    $dia_chi = trim(ht_get($data, 'dia_chi'));

    if ($ho_ten === '') {
        $error = 'Họ tên không được để trống.';
        return false;
    }
    if ($gioi_tinh !== '' && $gioi_tinh !== 'Nam' && $gioi_tinh !== 'Nữ') {
        $error = 'Giới tính không hợp lệ.';
        return false;
    }
    $ngay_sinh = ht_profile_parse_date_display($ngay_sinh_display);
    if ($ngay_sinh_display !== '' && !$ngay_sinh) {
        $error = 'Ngày sinh phải dạng dd/mm/yyyy.';
        return false;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ.';
        return false;
    }

    $tid = (int)$profile['teacher_id'];
    $sets = ['ten_thanh=?', 'ho_ten=?', 'so_dien_thoai=?', 'email=?', 'dia_chi=?'];
    $types = 'sssss';
    $params = [$ten_thanh, $ho_ten, $sdt, $email, $dia_chi];
    if ($gioi_tinh !== '') {
        $sets[] = 'gioi_tinh=?';
        $types .= 's';
        $params[] = $gioi_tinh;
    }
    if ($ngay_sinh) {
        $sets[] = 'ngay_sinh=?';
        $types .= 's';
        $params[] = $ngay_sinh;
    } elseif ($ngay_sinh_display === '') {
        $sets[] = 'ngay_sinh=NULL';
    }
    $types .= 'i';
    $params[] = $tid;
    $stmt = $conn->prepare('UPDATE teachers SET ' . implode(', ', $sets) . ' WHERE id=?');
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $error = 'Không cập nhật được hồ sơ: ' . $stmt->error;
        $stmt->close();
        return false;
    }
    $stmt->close();

    $stmt = $conn->prepare('UPDATE users SET full_name=? WHERE id=?');
    $stmt->bind_param('si', $ho_ten, $user_id);
    $stmt->execute();
    $stmt->close();

    return true;
}

function ht_profile_change_password($conn, $user_id, $current, $new, $confirm, &$error = null) {
    $user_id = (int)$user_id;
    $res = @$conn->query("SELECT password FROM users WHERE id={$user_id} LIMIT 1");
    $row = ($res) ? $res->fetch_assoc() : null;
    if ($res) {
        $res->free();
    }
    if (!$row) {
        $error = 'Không tìm thấy tài khoản.';
        return false;
    }
    if ((string)$row['password'] !== (string)$current) {
        $error = 'Mật khẩu hiện tại không đúng.';
        return false;
    }
    if (strlen($new) < 4) {
        $error = 'Mật khẩu mới phải có ít nhất 4 ký tự.';
        return false;
    }
    if ($new !== $confirm) {
        $error = 'Xác nhận mật khẩu mới không khớp.';
        return false;
    }
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $new, $user_id);
    $ok = $stmt->execute();
    if (!$ok) {
        $error = 'Không đổi được mật khẩu: ' . $stmt->error;
    }
    $stmt->close();
    if ($ok && function_exists('login_remember_revoke_user')) {
        $had_cookie = !empty($_COOKIE[LOGIN_REMEMBER_COOKIE]);
        login_remember_revoke_user($conn, $user_id);
        if ($had_cookie) {
            login_remember_issue($conn, $user_id);
        }
    }
    return $ok;
}

/**
 * Danh xưng ngắn theo vai trò (dùng cho thông báo bổ nhiệm).
 */
function ht_profile_short_title($vai_tro, $loai_huynh = '') {
    $vt = trim((string)$vai_tro);
    $loai = trim((string)$loai_huynh);
    if ($vt !== '') {
        if (preg_match('/phó/iu', $vt)) {
            return 'Phó';
        }
        if (preg_match('/dự\s*trưởng/iu', $vt)) {
            return 'Dự Trưởng';
        }
        if (preg_match('/trưởng/iu', $vt)) {
            return 'Trưởng';
        }
        if (preg_match('/thư\s*ký/iu', $vt)) {
            return 'Thư Ký';
        }
        if (preg_match('/thủ\s*quỹ/iu', $vt)) {
            return 'Thủ Quỹ';
        }
    }
    if (preg_match('/dự/iu', $loai)) {
        return 'Dự Trưởng';
    }
    return 'Trưởng';
}

/**
 * Danh sách bổ nhiệm lớp chỉ để hiển thị (không liên quan class_teachers).
 */
function ht_profile_load_bo_nhiem_list() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = __DIR__ . '/../data/bo_nhiem_lop.json';
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }
    $data = json_decode(file_get_contents($path), true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

/**
 * Ghép tên rút gọn trong danh sách bổ nhiệm với hồ sơ đầy đủ.
 * Trả về điểm khớp (cao hơn = tốt hơn), 0 = không khớp.
 */
function ht_profile_bo_nhiem_match_score($short_name, $ho_ten, $ten_thanh, $full_name) {
    $short = trim(preg_replace('/^(tr\.?|dì|di)\s+/iu', '', trim((string)$short_name)));
    $short = preg_replace('/\s*\(.*?\)\s*/u', '', $short);
    $short = trim($short);
    if ($short === '') {
        return 0;
    }

    $short_key = ht_profile_normalize_name_key($short);
    $full_key = ht_profile_normalize_name_key($full_name);
    $name_key = ht_profile_normalize_name_key($ho_ten);
    $saint_key = ht_profile_normalize_name_key($ten_thanh);
    if ($short_key === '' || $name_key === '') {
        return 0;
    }

    // Tách từ đã bỏ dấu để khớp theo từ (tránh Nguyễn ≈ Nguyên)
    $name_words = [];
    foreach (preg_split('/\s+/u', trim($ho_ten)) as $w) {
        $wk = ht_profile_normalize_name_key($w);
        if ($wk !== '') {
            $name_words[] = $wk;
        }
    }
    $short_words = [];
    foreach (preg_split('/\s+/u', $short) as $w) {
        $wk = ht_profile_normalize_name_key($w);
        if ($wk !== '') {
            $short_words[] = $wk;
        }
    }
    if (!$short_words || !$name_words) {
        return 0;
    }

    // Khớp gần như đầy đủ
    if ($short_key === $name_key || $short_key === $full_key) {
        return 100;
    }

    // Khớp chuỗi từ liên tiếp (>=2 từ) trong họ tên
    $sw_count = count($short_words);
    $nw_count = count($name_words);
    if ($sw_count >= 2) {
        for ($i = 0; $i <= $nw_count - $sw_count; $i++) {
            $ok = true;
            for ($j = 0; $j < $sw_count; $j++) {
                if ($name_words[$i + $j] !== $short_words[$j]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $near_end = ($i + $sw_count >= $nw_count);
                return ($near_end ? 92 : 84) + min(6, $sw_count);
            }
        }

        // Họ + tên (bỏ tên đệm): Bùi Luân ↔ Bùi Thanh Luân, Hoàng Trang ↔ Hoàng Thuỳ Trang
        $first = $short_words[0];
        $last_s = $short_words[1];
        $last_n = $name_words[$nw_count - 1];
        if ($last_s === $last_n && in_array($first, $name_words, true)) {
            return ($first === $name_words[0]) ? 90 : 86;
        }
    }

    // Một từ: chỉ khớp ĐÚNG 1 từ trong họ tên (không dùng substring)
    if ($sw_count === 1) {
        $token = $short_words[0];
        $raw_parts = preg_split('/\s+/u', trim($ho_ten));
        $last_raw = $raw_parts ? end($raw_parts) : '';
        $last = $name_words[$nw_count - 1];

        // Khớp dấu đầy đủ tên cuối (phân biệt Hiệu / Hiếu)
        if ($last_raw !== '' && mb_strtolower($short, 'UTF-8') === mb_strtolower($last_raw, 'UTF-8')) {
            return 78;
        }
        if ($token === $last) {
            return 74;
        }
        // Khớp từ giữa (không phải họ)
        for ($i = 1; $i < $nw_count; $i++) {
            $raw_i = isset($raw_parts[$i]) ? $raw_parts[$i] : '';
            if ($raw_i !== '' && mb_strtolower($short, 'UTF-8') === mb_strtolower($raw_i, 'UTF-8')) {
                return 70;
            }
            if ($name_words[$i] === $token) {
                return 65;
            }
        }
    }

    // Anna Nga / tên thánh + tên cuối
    if ($sw_count === 2 && $saint_key !== '' && $short_words[0] === $saint_key) {
        if ($short_words[1] === $name_words[$nw_count - 1]) {
            return 88;
        }
    }

    return 0;
}

/**
 * Tìm bổ nhiệm hiển thị theo tên huynh trưởng.
 */
function ht_profile_find_bo_nhiem_display($ho_ten, $ten_thanh = '') {
    $ho_ten = trim((string)$ho_ten);
    $ten_thanh = trim((string)$ten_thanh);
    $full = trim(($ten_thanh !== '' ? $ten_thanh . ' ' : '') . $ho_ten);
    $list = ht_profile_load_bo_nhiem_list();
    $best = null;
    $best_score = 0;

    foreach ($list as $row) {
        foreach (['truong' => 'Trưởng Lớp', 'pho' => 'Phó Lớp'] as $field => $role) {
            if (empty($row[$field]) || !is_array($row[$field])) {
                continue;
            }
            foreach ($row[$field] as $person) {
                $ten = is_array($person) ? ht_get($person, 'ten') : (string) $person;
                $note = is_array($person) ? trim((string) ht_get($person, 'ghi_chu')) : '';
                $score = ht_profile_bo_nhiem_match_score($ten, $ho_ten, $ten_thanh, $full);
                if ($score <= 0) {
                    continue;
                }
                // Ưu tiên vai trò Trưởng nếu điểm ngang nhau
                $adj = $score + ($field === 'truong' ? 0.5 : 0);
                if ($adj > $best_score) {
                    $best_score = $adj;
                    $vai = $role;
                    if ($note !== '') {
                        $vai .= ' · ' . $note;
                    }
                    $best = [
                        'vai_tro' => $vai,
                        'ten_lop' => ht_get($row, 'lop'),
                        'nganh' => ht_get($row, 'nganh'),
                        'ghi_chu' => $note,
                        'score' => $score,
                    ];
                }
            }
        }
    }

    return $best;
}

/**
 * Lấy nội dung thông báo bổ nhiệm lớp cho tài khoản Huynh Trưởng.
 * Lớp/vai trò lấy từ danh sách hiển thị (data/bo_nhiem_lop.json), không lấy từ class_teachers.
 */
function ht_profile_get_appointment($conn, $user_id) {
    $profile = ht_profile_get_by_user_id($conn, $user_id);
    if (!$profile) {
        return null;
    }
    $role = trim((string) ht_get($profile, 'role'));
    // Cho phép theo role DB hoặc session (phòng lệch dữ liệu join)
    if ($role !== 'huynh_truong') {
        $sess_role = isset($_SESSION['user_role']) ? trim((string)$_SESSION['user_role']) : '';
        if ($sess_role !== 'huynh_truong') {
            return null;
        }
    }

    $ho_ten = trim((string) (ht_get($profile, 'ho_ten') !== '' ? ht_get($profile, 'ho_ten') : ht_get($profile, 'full_name')));
    $ten_thanh = trim((string) ht_get($profile, 'ten_thanh'));
    $full = trim(($ten_thanh !== '' ? $ten_thanh . ' ' : '') . $ho_ten);
    if ($full === '') {
        $full = trim((string) ht_get($profile, 'username', 'Huynh Truong'));
        $ho_ten = $full;
    }

    $display = ht_profile_find_bo_nhiem_display($ho_ten, $ten_thanh);
    $vai_tro = ht_get($display, 'vai_tro');
    $ten_lop = ht_get($display, 'ten_lop');
    $nganh = ht_get($display, 'nganh');

    // Fallback nhẹ nếu chưa có trong danh sách hiển thị
    if ($ten_lop === '') {
        $ten_lop = trim((string) ht_get($profile, 'lop_phu_trach'));
        $nganh = trim((string) ht_get($profile, 'nganh_phu_trach', $nganh));
        $vai_tro = trim((string) ht_get($profile, 'vai_tro_lop', $vai_tro));
    }

    $nam_hoc = '';
    $ay_path = __DIR__ . '/academic_year.php';
    if (is_file($ay_path)) {
        require_once $ay_path;
        if (function_exists('ay_get_current_year')) {
            try {
                $year = ay_get_current_year($conn);
                if ($year && !empty($year['ten_nam_hoc'])) {
                    $nam_hoc = $year['ten_nam_hoc'];
                }
            } catch (Exception $e) {
                $nam_hoc = '';
            }
        }
    }
    if ($nam_hoc === '') {
        $y = (int)date('Y');
        $m = (int)date('n');
        $nam_hoc = ($m >= 8) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
    }
    $nam_hoc_display = preg_replace('/\s*-\s*/', ' - ', $nam_hoc);

    $title = ht_profile_short_title($vai_tro, ht_get($profile, 'loai_huynh'));
    $avatar = !empty($profile['avatar']) ? ht_profile_avatar_url($profile['avatar']) : '';
    if ($avatar && !is_file(dirname(__DIR__) . '/' . $avatar)) {
        $avatar = '';
    }

    return [
        'title' => $title,
        'full_name' => $full,
        'ten_thanh' => $ten_thanh,
        'ho_ten' => $ho_ten,
        'vai_tro' => $vai_tro !== '' ? $vai_tro : '—',
        'ten_lop' => $ten_lop !== '' ? $ten_lop : 'Chưa phân lớp',
        'nganh' => $nganh,
        'nam_hoc' => $nam_hoc_display,
        'avatar' => $avatar,
        'blessing' => 'Xin Thiên Chúa nâng đỡ và ban ơn giúp Trưởng luôn hăng say phục vụ.',
        'from_display_list' => $display !== null,
    ];
}


