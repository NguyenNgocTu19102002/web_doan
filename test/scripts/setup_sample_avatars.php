<?php
/**
 * Xóa ảnh HT thật và gán 5 avatar mẫu — CHỈ môi trường /test.
 *
 * CLI: php test/scripts/setup_sample_avatars.php
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die('Chỉ chạy từ CLI');
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/db_connect.php';

$allowed_dbs = array('test', 'o0kgbp36f9sl_test');
if (!in_array($db_name, $allowed_dbs, true)) {
    fwrite(STDERR, "ABORT: DB '{$db_name}' — chỉ cho phép test.\n");
    exit(1);
}

$avatar_dir = dirname(__DIR__) . '/uploads/avatars';
$sample_files = array(
    'sample_avatar_1.png',
    'sample_avatar_2.png',
    'sample_avatar_3.png',
    'sample_avatar_4.png',
    'sample_avatar_5.png',
);

function setup_log($msg) {
    echo $msg . "\n";
}

// 1. Xóa toàn bộ file ảnh cũ trong test/uploads/avatars
if (is_dir($avatar_dir)) {
    $deleted = 0;
    foreach (scandir($avatar_dir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $avatar_dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path) && @unlink($path)) {
            $deleted++;
        }
    }
    setup_log("Đã xóa {$deleted} file ảnh cũ trong test/uploads/avatars");
} else {
    mkdir($avatar_dir, 0775, true);
    setup_log('Tạo thư mục test/uploads/avatars');
}

// 2. Tạo 5 ảnh mẫu bằng Python (Pillow)
$py = dirname(__FILE__) . '/setup_sample_avatars.py';
$cmd = 'python ' . escapeshellarg($py);
exec($cmd, $out, $code);
foreach ($out as $line) {
    setup_log($line);
}
if ($code !== 0) {
    fwrite(STDERR, "Lỗi tạo ảnh mẫu (exit {$code}). Cần Python + Pillow.\n");
    exit(1);
}

foreach ($sample_files as $f) {
    if (!is_file($avatar_dir . DIRECTORY_SEPARATOR . $f)) {
        fwrite(STDERR, "Thiếu file: {$f}\n");
        exit(1);
    }
}

// 3. Xóa avatar trong DB (tất cả teachers)
$n = 0;
if ($conn->query("UPDATE teachers SET avatar = NULL")) {
    $n = $conn->affected_rows;
}
setup_log("Đã xóa avatar DB: {$n} teachers");

// 4. Gán 5 avatar mẫu cho 5 HT (ưu tiên Huynh Trưởng, rồi HDT)
$teacher_ids = array();
$res = $conn->query("SELECT id FROM teachers WHERE loai_huynh = 'Huynh Trưởng' ORDER BY id ASC LIMIT 5");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $teacher_ids[] = (int) $row['id'];
    }
}
if (count($teacher_ids) < 5) {
    $res = $conn->query("SELECT id FROM teachers ORDER BY id ASC LIMIT 5");
    $teacher_ids = array();
    while ($res && ($row = $res->fetch_assoc())) {
        $teacher_ids[] = (int) $row['id'];
    }
}

$assigned = 0;
for ($i = 0; $i < 5 && $i < count($teacher_ids); $i++) {
    $tid = $teacher_ids[$i];
    $path = 'uploads/avatars/' . $sample_files[$i];
    $path_esc = $conn->real_escape_string($path);
    if ($conn->query("UPDATE teachers SET avatar = '{$path_esc}' WHERE id = {$tid}")) {
        setup_log("Gán {$sample_files[$i]} → teacher id {$tid}");
        $assigned++;
    }
}

setup_log("=== Hoàn tất: {$assigned} HT có avatar mẫu, còn lại không có ảnh. ===");
