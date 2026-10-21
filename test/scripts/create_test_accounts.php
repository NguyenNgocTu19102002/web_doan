<?php
/**
 * Tạo/cập nhật tài khoản demo trên DB test.
 * - test  → quản lý HDT (hdt_manager)
 * - test1 → Huynh Trưởng (huynh_truong)
 *
 * CLI: php test/scripts/create_test_accounts.php
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die('Chỉ chạy từ CLI');
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/db_connect.php';

$allowed_dbs = array('test', 'o0kgbp36f9sl_test');
if (!in_array($db_name, $allowed_dbs, true)) {
    fwrite(STDERR, "ABORT: DB '{$db_name}' — chỉ cho phép: " . implode(', ', $allowed_dbs) . "\n");
    exit(1);
}

$password = 'test123';

function upsert_user($conn, $username, $role, $full_name, $teacher_id, $password) {
    $username = trim($username);
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existing) {
        if ($teacher_id !== null) {
            $stmt = $conn->prepare('UPDATE users SET password = ?, role = ?, full_name = ?, teacher_id = ? WHERE id = ?');
            $tid = (int) $teacher_id;
            $stmt->bind_param('sssii', $password, $role, $full_name, $tid, $existing['id']);
        } else {
            $stmt = $conn->prepare('UPDATE users SET password = ?, role = ?, full_name = ?, teacher_id = NULL WHERE id = ?');
            $stmt->bind_param('sssi', $password, $role, $full_name, $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        return array('action' => 'updated', 'id' => (int) $existing['id']);
    }

    if ($teacher_id !== null) {
        $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name, teacher_id) VALUES (?, ?, ?, ?, ?)');
        $tid = (int) $teacher_id;
        $stmt->bind_param('ssssi', $username, $password, $role, $full_name, $tid);
    } else {
        $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $username, $password, $role, $full_name);
    }
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return array('action' => 'created', 'id' => $id);
}

function ensure_ht_teacher($conn) {
    $res = $conn->query("SELECT id FROM teachers WHERE ho_ten = 'HT Test' AND loai_huynh = 'Huynh Trưởng' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int) $row['id'];
    }

    $sql = "INSERT INTO teachers (ho_ten, ten_thanh, gioi_tinh, ngay_sinh, so_dien_thoai, email, loai_huynh, status)
            VALUES ('HT Test', 'Giuse', 'Nam', '2000-01-01', '0900000001', 'test1@test.local', 'Huynh Trưởng', 'active')";
    if (!$conn->query($sql)) {
        throw new RuntimeException('Không tạo được teacher HT Test: ' . $conn->error);
    }
    return (int) $conn->insert_id;
}

function ensure_class_assignment($conn, $teacher_id) {
    $teacher_id = (int) $teacher_id;
    $res = $conn->query("SELECT id, class_id FROM class_teachers WHERE teacher_id = {$teacher_id} LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return;
    }

    $class_res = $conn->query('SELECT id FROM classes ORDER BY id ASC LIMIT 1');
    if (!$class_res || !($class = $class_res->fetch_assoc())) {
        throw new RuntimeException('Không có lớp nào để gán HT test');
    }
    $class_id = (int) $class['id'];
    $vai_tro = 'Huynh Trưởng';
    $sql = "INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ({$class_id}, {$teacher_id}, '{$vai_tro}')";
    if (!$conn->query($sql)) {
        throw new RuntimeException('Không gán lớp cho HT test: ' . $conn->error);
    }
}

try {
    echo "=== Tạo tài khoản test trên DB: {$db_name} ===\n";

    $r1 = upsert_user($conn, 'test', 'hdt_manager', 'QL HDT Test', null, $password);
    echo "test (hdt_manager): {$r1['action']} id={$r1['id']}\n";

    $teacher_id = ensure_ht_teacher($conn);
    ensure_class_assignment($conn, $teacher_id);
    $r2 = upsert_user($conn, 'test1', 'huynh_truong', 'HT Test', $teacher_id, $password);
    echo "test1 (huynh_truong): {$r2['action']} id={$r2['id']}, teacher_id={$teacher_id}\n";

    echo "\nĐăng nhập:\n";
    echo "  test  / {$password}  → Quản lý HDT\n";
    echo "  test1 / {$password}  → Huynh Trưởng\n";
} catch (Exception $e) {
    fwrite(STDERR, 'LỖI: ' . $e->getMessage() . "\n");
    exit(1);
}
