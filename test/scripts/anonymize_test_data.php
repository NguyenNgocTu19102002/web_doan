<?php
/**
 * Làm sạch dữ liệu nhạy cảm trên DB TEST (local: test, hosting: o0kgbp36f9sl_test).
 * Chỉ chạy trên database test — không đụng DB production.
 *
 * CLI: php test/scripts/anonymize_test_data.php
 * Web (chỉ localhost): .../test/scripts/anonymize_test_data.php?run=1&token=...
 */

if (PHP_SAPI !== 'cli') {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $is_local = ($host === '' || $host === 'localhost' || strpos($host, '127.0.0.1') !== false || strpos($host, 'localhost:') === 0);
    if (!$is_local || !isset($_GET['run']) || $_GET['run'] !== '1') {
        header('HTTP/1.1 403 Forbidden');
        die('Chỉ chạy từ CLI hoặc localhost với ?run=1');
    }
}

$_SERVER['HTTP_HOST'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
require_once dirname(__DIR__) . '/db_connect.php';

$allowed_dbs = array('test', 'o0kgbp36f9sl_test');
if (!in_array($db_name, $allowed_dbs, true)) {
    fwrite(STDERR, "ABORT: DB hiện tại là '{$db_name}' — chỉ cho phép: " . implode(', ', $allowed_dbs) . "\n");
    exit(1);
}

function anon_col_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function anon_run($conn, $sql, $label) {
    if (!$conn->query($sql)) {
        throw new RuntimeException($label . ': ' . $conn->error);
    }
    return $conn->affected_rows;
}

function anon_log($msg) {
    echo $msg . "\n";
}

$saints = array('Maria', 'Giuse', 'Phaolo', 'Pierre', 'Anna', 'Têrêsa', 'Gioan', 'Luca', 'Anton', 'Toma');

try {
    $conn->begin_transaction();
    anon_log("=== Anonymize DB test: {$db_name} ===");

    // --- Đoàn sinh (thiếu nhi) ---
    $student_sets = array(
        "ho_ten = CONCAT('Đoàn sinh ', LPAD(id, 3, '0'))",
        "ten_thanh = ELT(1 + (id MOD 10), 'Maria', 'Giuse', 'Phaolo', 'Pierre', 'Anna', 'Têrêsa', 'Gioan', 'Luca', 'Anton', 'Toma')",
        "ngay_sinh = DATE_ADD('2008-01-01', INTERVAL (id MOD 3650) DAY)",
        "ho_ten_bo = ''",
        "ho_ten_me = ''",
        "sdt_lien_lac = ''",
        "email_phu_huynh = ''",
        "dia_chi = ''",
        "ghi_chu = NULL",
        "ngay_rua_toi = NULL",
        "ngay_them_suc = NULL",
    );
    if (anon_col_exists($conn, 'students', 'so_dien_thoai')) {
        $student_sets[] = "so_dien_thoai = ''";
    }
    $n = anon_run($conn, 'UPDATE students SET ' . implode(', ', $student_sets), 'students');
    anon_log("students: {$n} dòng — đã ẩn SĐT, email, địa chỉ, phụ huynh, ghi chú; đổi tên mẫu");

    if (anon_col_exists($conn, 'year_student_snapshots', 'ho_ten')) {
        $snap_sets = array(
            "ho_ten = CONCAT('Đoàn sinh ', LPAD(student_id, 3, '0'))",
            "ten_thanh = ELT(1 + (student_id MOD 10), 'Maria', 'Giuse', 'Phaolo', 'Pierre', 'Anna', 'Têrêsa', 'Gioan', 'Luca', 'Anton', 'Toma')",
        );
        if (anon_col_exists($conn, 'year_student_snapshots', 'ngay_sinh')) {
            $snap_sets[] = "ngay_sinh = DATE_ADD('2008-01-01', INTERVAL (student_id MOD 3650) DAY)";
        }
        $n = anon_run($conn, 'UPDATE year_student_snapshots SET ' . implode(', ', $snap_sets), 'year_student_snapshots');
        anon_log("year_student_snapshots: {$n} dòng");
    }

    // --- Huynh trưởng (test env — ẩn liên hệ thật) ---
    $teacher_cols = array();
    if (anon_col_exists($conn, 'teachers', 'so_dien_thoai')) {
        $teacher_cols[] = "so_dien_thoai = CONCAT('0900', LPAD(id, 6, '0'))";
    }
    if (anon_col_exists($conn, 'teachers', 'email')) {
        $teacher_cols[] = "email = CONCAT('ht', id, '@test.local')";
    }
    if (anon_col_exists($conn, 'teachers', 'dia_chi')) {
        $teacher_cols[] = "dia_chi = ''";
    }
    if (anon_col_exists($conn, 'teachers', 'ghi_chu')) {
        $teacher_cols[] = "ghi_chu = NULL";
    }
    if (anon_col_exists($conn, 'teachers', 'ho_ten')) {
        $teacher_cols[] = "ho_ten = CONCAT('Huynh trưởng ', LPAD(id, 2, '0'))";
    }
    if (anon_col_exists($conn, 'teachers', 'ten_thanh')) {
        $teacher_cols[] = "ten_thanh = ELT(1 + (id MOD 10), 'Maria', 'Giuse', 'Phaolo', 'Pierre', 'Anna', 'Têrêsa', 'Gioan', 'Luca', 'Anton', 'Toma')";
    }
    if ($teacher_cols) {
        $n = anon_run($conn, 'UPDATE teachers SET ' . implode(', ', $teacher_cols), 'teachers');
        anon_log("teachers: {$n} dòng — SĐT/email/địa chỉ giả lập");
    }

    // --- Tài khoản đăng nhập test ---
    if (anon_col_exists($conn, 'users', 'password')) {
        $user_sets = array("password = 'test123'");
        if (anon_col_exists($conn, 'users', 'full_name')) {
            $user_sets[] = "full_name = CONCAT('User test ', id)";
        }
        if (anon_col_exists($conn, 'users', 'email')) {
            $user_sets[] = "email = CONCAT('user', id, '@test.local')";
        }
        $n = anon_run($conn, 'UPDATE users SET ' . implode(', ', $user_sets), 'users');
        anon_log("users: {$n} tài khoản — mật khẩu đặt về 'test123'");
    }

    // Mã bảo vệ lớp → mã test cố định
    if (anon_col_exists($conn, 'classes', 'ma_xac_nhan')) {
        $n = anon_run($conn, "UPDATE classes SET ma_xac_nhan = 'TEST'", 'classes.ma_xac_nhan');
        anon_log("classes: {$n} lớp — ma_xac_nhan = TEST");
    }

    // Thu chi — xóa ghi chú có thể chứa tên người
    if (anon_col_exists($conn, 'thuchi_entries', 'ghi_chu')) {
        $n = anon_run($conn, "UPDATE thuchi_entries SET ghi_chu = NULL", 'thuchi_entries');
        anon_log("thuchi_entries: {$n} dòng — xóa ghi chú");
    }
    if (anon_col_exists($conn, 'thuchi_entries', 'noi_dung')) {
        $n = anon_run($conn, "UPDATE thuchi_entries SET noi_dung = CONCAT('Khoản mục test #', id)", 'thuchi_entries.noi_dung');
        anon_log("thuchi_entries nội dung: {$n} dòng");
    }

    $conn->commit();
    anon_log('=== Hoàn tất. DB test an toàn để thử nghiệm công khai. ===');
    anon_log("Đăng nhập test: mật khẩu chung 'test123' (giữ nguyên username).");
} catch (Exception $e) {
    $conn->rollback();
    fwrite(STDERR, 'LỖI: ' . $e->getMessage() . "\n");
    exit(1);
}
