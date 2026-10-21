<?php
/**
 * Mã QR định danh Đoàn sinh: mỗi em có 1 qr_token cố định trong bảng students.
 * Nội dung QR = SQR_PREFIX . qr_token
 */
require_once dirname(__DIR__) . '/login_redirect.php';

if (!defined('SQR_PREFIX')) {
    define('SQR_PREFIX', 'TNTTLT:');
}

if (!function_exists('sqr_ensure_schema')) {

function sqr_ensure_schema($conn) {
    $col = $conn->query("SHOW COLUMNS FROM students LIKE 'qr_token'");
    if ($col && $col->num_rows == 0) {
        $conn->query("ALTER TABLE students ADD COLUMN qr_token VARCHAR(32) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE students ADD UNIQUE KEY uniq_students_qr_token (qr_token)");
    }
}

function sqr_new_token() {
    return bin2hex(random_bytes(8));
}

/** Cấp mã cho các em trong lớp chưa có mã. */
function sqr_ensure_class_tokens($conn, $class_id) {
    $class_id = (int)$class_id;
    $res = $conn->query("SELECT id FROM students WHERE class_id = $class_id AND (qr_token IS NULL OR qr_token = '')");
    if (!$res) {
        return;
    }
    $stmt = $conn->prepare("UPDATE students SET qr_token = ? WHERE id = ?");
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['id'];
        for ($try = 0; $try < 5; $try++) {
            $token = sqr_new_token();
            $stmt->bind_param('si', $token, $sid);
            if ($stmt->execute()) {
                break;
            }
        }
    }
    $stmt->close();
}

/** Cấp lại mã (khi thẻ bị mất): mã cũ hết hiệu lực. */
function sqr_regenerate_token($conn, $student_id) {
    $student_id = (int)$student_id;
    $stmt = $conn->prepare("UPDATE students SET qr_token = ? WHERE id = ?");
    for ($try = 0; $try < 5; $try++) {
        $token = sqr_new_token();
        $stmt->bind_param('si', $token, $student_id);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
    }
    $stmt->close();
    return null;
}

/** Tách token từ nội dung quét được (chấp nhận cả khi chỉ nhập token). */
function sqr_parse_payload($payload) {
    $payload = trim((string)$payload);
    if (stripos($payload, SQR_PREFIX) === 0) {
        $payload = substr($payload, strlen(SQR_PREFIX));
    }
    return preg_match('/^[a-f0-9]{16}$/i', $payload) ? strtolower($payload) : '';
}

/** Trưởng / Phó lớp đó, hoặc Admin/HDT. */
function sqr_can_scan($conn, $class_id) {
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (login_is_hdt_manager()) {
        return true;
    }
    return login_is_class_leader($conn, (int)$class_id);
}

}
