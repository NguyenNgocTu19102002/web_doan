<?php
/**
 * Báo cáo Đoàn sinh — HT gửi yêu cầu, HDT duyệt, Ban Phụ Huynh gặp phụ huynh.
 * Tương thích PHP 5.6 (không dùng ??, get_result).
 */

function bc_h($v) {
    return htmlspecialchars((string) (isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

function bc_ensure_schema($conn) {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col && ($info = $col->fetch_assoc())) {
        if (strpos($info['Type'], 'enum(') === 0 && strpos($info['Type'], 'ban_phu_huynh') === false) {
            @$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy','huynh_truong','ban_phu_huynh') DEFAULT 'huynh_truong'");
        }
    }

    $note_col = $conn->query("SHOW COLUMNS FROM attendance LIKE 'ghi_chu'");
    if ($note_col && $note_col->num_rows == 0) {
        @$conn->query("ALTER TABLE attendance ADD COLUMN ghi_chu VARCHAR(255) NULL DEFAULT NULL AFTER status");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS student_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NULL,
        reason TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_by INT NULL,
        created_by_name VARCHAR(150) NULL,
        created_at DATETIME NULL,
        hdt_by INT NULL,
        hdt_by_name VARCHAR(150) NULL,
        hdt_note TEXT NULL,
        hdt_at DATETIME NULL,
        bph_by INT NULL,
        bph_by_name VARCHAR(150) NULL,
        bph_note TEXT NULL,
        bph_at DATETIME NULL,
        KEY idx_status (status),
        KEY idx_student (student_id),
        KEY idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function bc_csrf_token() {
    if (empty($_SESSION['bc_csrf'])) {
        $_SESSION['bc_csrf'] = md5(uniqid(mt_rand(), true));
    }
    return $_SESSION['bc_csrf'];
}

function bc_csrf_ok() {
    return !empty($_POST['csrf']) && !empty($_SESSION['bc_csrf'])
        && (string) $_POST['csrf'] === (string) $_SESSION['bc_csrf'];
}

function bc_user_display_name($conn, $user_id) {
    $user_id = (int) $user_id;
    $res = $conn->query("SELECT u.username, u.full_name, t.ho_ten, t.ten_thanh
                         FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id
                         WHERE u.id = $user_id LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        if (!empty($row['ho_ten'])) {
            return trim((!empty($row['ten_thanh']) ? $row['ten_thanh'] . ' ' : '') . $row['ho_ten']);
        }
        if (!empty($row['full_name'])) {
            return $row['full_name'];
        }
        return (string) $row['username'];
    }
    return 'Không rõ';
}

function bc_class_order_sql($alias = '') {
    $col = $alias !== '' ? $alias . '.nganh' : 'nganh';
    $name = $alias !== '' ? $alias . '.ten_lop' : 'ten_lop';
    return "FIELD($col, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), $name";
}

/** Lớp user đang làm Trưởng / Phó lớp. @return array id => row */
function bc_leader_classes($conn, $user_id) {
    $user_id = (int) $user_id;
    $out = array();
    $res = $conn->query("SELECT DISTINCT c.id, c.ten_lop, c.nganh
                         FROM users u
                         INNER JOIN class_teachers ct ON ct.teacher_id = u.teacher_id
                         INNER JOIN classes c ON c.id = ct.class_id
                         WHERE u.id = $user_id AND u.teacher_id IS NOT NULL
                           AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Phó%')
                         ORDER BY " . bc_class_order_sql('c'));
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['id']] = $row;
        }
    }
    return $out;
}

function bc_all_classes($conn) {
    $out = array();
    $res = $conn->query("SELECT id, ten_lop, nganh FROM classes ORDER BY " . bc_class_order_sql());
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['id']] = $row;
        }
    }
    return $out;
}

function bc_empty_stats() {
    return array(
        'gl' => array('total' => 0, 'co' => 0, 'muon' => 0, 'cp' => 0, 'kp' => 0),
        'le' => array('total' => 0, 'co' => 0, 'muon' => 0, 'cp' => 0, 'kp' => 0),
    );
}

/** Thống kê chuyên cần theo học sinh (toàn bộ các buổi đã điểm danh). */
function bc_student_stats($conn, $student_ids) {
    $ids = array();
    foreach ((array) $student_ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $out = array();
    foreach ($ids as $id) {
        $out[$id] = bc_empty_stats();
    }
    if (!$ids) {
        return $out;
    }
    $map = array('có' => 'co', 'đi muộn' => 'muon', 'vắng có phép' => 'cp', 'vắng không phép' => 'kp');
    $res = $conn->query("SELECT student_id, activity_type, status, COUNT(*) AS c
                         FROM attendance
                         WHERE student_id IN (" . implode(',', $ids) . ")
                         GROUP BY student_id, activity_type, status");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $sid = (int) $row['student_id'];
            $type = ($row['activity_type'] === 'Đi lễ') ? 'le' : 'gl';
            $c = (int) $row['c'];
            $out[$sid][$type]['total'] += $c;
            if (isset($map[$row['status']])) {
                $out[$sid][$type][$map[$row['status']]] += $c;
            }
        }
    }
    return $out;
}

/** % nghỉ học (Học Giáo Lý) — null nếu chưa có buổi nào. */
function bc_absent_percent($stats) {
    $t = $stats['gl']['total'];
    return $t > 0 ? round(($stats['gl']['cp'] + $stats['gl']['kp']) / $t * 100, 1) : null;
}

/** % đi lễ — null nếu chưa có buổi lễ nào. */
function bc_mass_percent($stats) {
    $t = $stats['le']['total'];
    return $t > 0 ? round(($stats['le']['co'] + $stats['le']['muon']) / $t * 100, 1) : null;
}

function bc_percent_badge($value, $bad_when_high) {
    if ($value === null) {
        return '<span class="text-muted">—</span>';
    }
    $bad = $bad_when_high ? ($value > 40) : ($value < 60);
    return '<span class="pct ' . ($bad ? 'pct-bad' : 'pct-ok') . '">' . $value . '%</span>';
}

function bc_status_info($status) {
    $map = array(
        'pending'  => array('Chờ BĐH duyệt', 'st-pending', 'fa-hourglass-half'),
        'approved' => array('Đã chuyển Ban Phụ Huynh', 'st-approved', 'fa-share'),
        'rejected' => array('BĐH không duyệt', 'st-rejected', 'fa-ban'),
        'met'      => array('Đã gặp phụ huynh', 'st-met', 'fa-handshake'),
    );
    return isset($map[$status]) ? $map[$status] : array($status, 'st-pending', 'fa-circle');
}

/** Yêu cầu đang mở (chờ duyệt / chờ gặp) theo học sinh. @return array student_id => status */
function bc_open_reports_by_student($conn, $student_ids) {
    $ids = array_filter(array_map('intval', (array) $student_ids));
    $out = array();
    if (!$ids) {
        return $out;
    }
    $res = $conn->query("SELECT student_id, status FROM student_reports
                         WHERE status IN ('pending','approved') AND student_id IN (" . implode(',', $ids) . ")");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['student_id']] = $row['status'];
        }
    }
    return $out;
}

function bc_fetch_reports($conn, $where, $limit = 100) {
    $out = array();
    $res = $conn->query("SELECT r.*, s.ho_ten, s.ten_thanh, c.ten_lop, c.nganh
                         FROM student_reports r
                         LEFT JOIN students s ON s.id = r.student_id
                         LEFT JOIN classes c ON c.id = r.class_id
                         WHERE $where
                         ORDER BY r.id DESC
                         LIMIT " . (int) $limit);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    }
    return $out;
}

function bc_student_full($conn, $student_id) {
    $student_id = (int) $student_id;
    $res = $conn->query("SELECT s.*, c.ten_lop, c.nganh FROM students s
                         LEFT JOIN classes c ON c.id = s.class_id
                         WHERE s.id = $student_id LIMIT 1");
    return ($res && ($row = $res->fetch_assoc())) ? $row : null;
}

function bc_absence_history($conn, $student_id, $limit = 12) {
    $student_id = (int) $student_id;
    $out = array();
    $res = $conn->query("SELECT ngay_diem_danh, activity_type, status, ghi_chu
                         FROM attendance
                         WHERE student_id = $student_id
                           AND (status IN ('vắng có phép','vắng không phép','đi muộn') OR (ghi_chu IS NOT NULL AND ghi_chu <> ''))
                         ORDER BY ngay_diem_danh DESC
                         LIMIT " . (int) $limit);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    }
    return $out;
}

function bc_date($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function bc_datetime($d) {
    if (empty($d)) {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i', $ts) : '';
}
