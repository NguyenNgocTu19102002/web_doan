<?php
/**
 * Schema + helpers cho module Tổng hợp thu chi
 * Tương thích PHP 5.6 + hosting không có mysqlnd.
 */

function thuchi_default_categories() {
    return array(
        'Khóa học',
        'Công viện chung',
        'Chiến dịch',
        'Làm bác ái',
        'Sinh hoạt đoàn',
        'Trại / sự kiện',
        'Đóng góp / ủng hộ',
        'Văn phòng phẩm',
        'Khác',
    );
}

/** Fetch all rows từ mysqli_stmt — không cần mysqlnd */
function thuchi_stmt_fetch_all($stmt) {
    $stmt->store_result();
    $meta = $stmt->result_metadata();
    if (!$meta) {
        return array();
    }
    $row = array();
    $bind = array();
    while ($field = $meta->fetch_field()) {
        $bind[] = &$row[$field->name];
    }
    call_user_func_array(array($stmt, 'bind_result'), $bind);
    $out = array();
    while ($stmt->fetch()) {
        $copy = array();
        foreach ($row as $k => $v) {
            $copy[$k] = $v;
        }
        $out[] = $copy;
    }
    $meta->free();
    return $out;
}

function thuchi_users_has_column($conn, $column) {
    $col = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    return ($r && $r->num_rows > 0);
}

function thuchi_ensure_schema($conn) {
    // Mở rộng ENUM role để có thuquy
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col && $row = $col->fetch_assoc()) {
        $type = isset($row['Type']) ? $row['Type'] : '';
        if (stripos($type, 'thuquy') === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy') DEFAULT 'admin'");
        }
    }

    // Schema tương thích MySQL 5.5+ (tránh 2 TIMESTAMP auto)
    $ok1 = $conn->query("CREATE TABLE IF NOT EXISTS thuchi_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ten VARCHAR(100) NOT NULL,
        thu_dung TINYINT(1) NOT NULL DEFAULT 1,
        chi_dung TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_ten (ten)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $ok2 = $conn->query("CREATE TABLE IF NOT EXISTS thuchi_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loai ENUM('thu','chi') NOT NULL,
        noi_dung VARCHAR(500) NOT NULL,
        so_tien DECIMAL(15,0) NOT NULL,
        ngay DATE NOT NULL,
        ghi_chu TEXT NULL,
        phan_loai VARCHAR(100) NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_ngay (ngay),
        INDEX idx_loai (loai),
        INDEX idx_phan_loai (phan_loai)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    if (!$ok1 || !$ok2) {
        return false;
    }

    // Seed categories nếu trống
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM thuchi_categories");
    $n = 0;
    if ($cnt) {
        $r = $cnt->fetch_assoc();
        $n = isset($r['c']) ? (int)$r['c'] : 0;
    }
    if ($n === 0) {
        $stmt = $conn->prepare("INSERT INTO thuchi_categories (ten, sort_order, created_at) VALUES (?, ?, NOW())");
        if ($stmt) {
            $cats = thuchi_default_categories();
            foreach ($cats as $i => $name) {
                $ord = $i + 1;
                $stmt->bind_param('si', $name, $ord);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    // Tạo / cập nhật tài khoản thủ quỹ
    $exists = false;
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    if ($check) {
        $u = 'thuquy';
        $check->bind_param('s', $u);
        $check->execute();
        $check->store_result();
        $exists = ($check->num_rows > 0);
        $check->close();
    }

    $has_full_name = thuchi_users_has_column($conn, 'full_name');
    if ($exists) {
        if ($has_full_name) {
            $conn->query("UPDATE users SET password = 'thuquy124', role = 'thuquy', full_name = 'Thủ quỹ Đoàn' WHERE username = 'thuquy'");
        } else {
            $conn->query("UPDATE users SET password = 'thuquy124', role = 'thuquy' WHERE username = 'thuquy'");
        }
    } else {
        if ($has_full_name) {
            $conn->query("INSERT INTO users (username, password, role, full_name) VALUES ('thuquy', 'thuquy124', 'thuquy', 'Thủ quỹ Đoàn')");
        } else {
            $conn->query("INSERT INTO users (username, password, role) VALUES ('thuquy', 'thuquy124', 'thuquy')");
        }
    }
    return true;
}

function thuchi_period_range($period, $ref_date = null) {
    $ts = $ref_date ? strtotime($ref_date) : time();
    if ($ts === false) {
        $ts = time();
    }
    switch ($period) {
        case 'tuan':
            $dow = (int)date('N', $ts);
            $start = strtotime('-' . ($dow - 1) . ' days', $ts);
            $end = strtotime('+' . (7 - $dow) . ' days', $ts);
            break;
        case 'quy':
            $m = (int)date('n', $ts);
            $q = (int)ceil($m / 3);
            $startMonth = ($q - 1) * 3 + 1;
            $y = (int)date('Y', $ts);
            $start = strtotime(sprintf('%04d-%02d-01', $y, $startMonth));
            $endMonth = $startMonth + 2;
            $end = strtotime(date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $endMonth))));
            break;
        case 'nam':
            $y = (int)date('Y', $ts);
            $start = strtotime($y . '-01-01');
            $end = strtotime($y . '-12-31');
            break;
        case 'thang':
        default:
            $start = strtotime(date('Y-m-01', $ts));
            $end = strtotime(date('Y-m-t', $ts));
            break;
    }
    return array(date('Y-m-d', $start), date('Y-m-d', $end));
}

function thuchi_format_money($n) {
    return number_format((float)$n, 0, ',', '.') . ' đ';
}

function thuchi_period_label($period, $from, $to) {
    $map = array('tuan' => 'Tuần', 'thang' => 'Tháng', 'quy' => 'Quý', 'nam' => 'Năm');
    $p = isset($map[$period]) ? $map[$period] : 'Kỳ';
    $f = date('d/m/Y', strtotime($from));
    $t = date('d/m/Y', strtotime($to));
    return $p . ' (' . $f . ' - ' . $t . ')';
}
