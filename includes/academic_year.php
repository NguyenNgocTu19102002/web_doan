<?php
/**
 * Quản lý năm học: schema, tổng kết, kết thúc năm & reset điểm/điểm danh.
 */

/** Mã xác nhận kết thúc / tổng kết năm học (chỉ HDT biết) */
define('AY_END_CONFIRM_CODE', 'TĐ2026');

function ay_verify_end_confirm_code($input) {
    return trim((string)$input) === AY_END_CONFIRM_CODE;
}

function ay_grade_he_so_map() {
    return ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
}

function ay_compute_tb(array $grades_by_loai) {
    $he_so_map = ay_grade_he_so_map();
    $tb = 0;
    $total_he_so = 0;
    foreach ($grades_by_loai as $loai => $diem) {
        if (!is_numeric($diem)) {
            continue;
        }
        $he_so = isset($he_so_map[$loai]) ? $he_so_map[$loai] : 1;
        $tb += ((float)$diem) * $he_so;
        $total_he_so += $he_so;
    }
    return $total_he_so ? round($tb / $total_he_so, 2) : null;
}

function ay_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function ay_ensure_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS academic_years (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ten_nam_hoc VARCHAR(20) NOT NULL,
        trang_thai ENUM('active','archived') NOT NULL DEFAULT 'active',
        is_current TINYINT(1) NOT NULL DEFAULT 0,
        ghi_chu TEXT NULL,
        archived_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ten_nam_hoc (ten_nam_hoc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS year_class_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year_id INT NOT NULL,
        class_id INT NOT NULL,
        ten_lop VARCHAR(100) NOT NULL,
        nganh VARCHAR(50) NOT NULL,
        huynh_truong TEXT NULL,
        truong_lop TEXT NULL,
        pho_lop TEXT NULL,
        si_so INT NOT NULL DEFAULT 0,
        KEY idx_year (academic_year_id),
        KEY idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS year_student_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year_id INT NOT NULL,
        year_class_snapshot_id INT NOT NULL,
        student_id INT NOT NULL,
        ho_ten VARCHAR(100) NOT NULL,
        ten_thanh VARCHAR(50) NULL,
        ngay_sinh DATE NULL,
        gioi_tinh VARCHAR(10) NULL,
        ten_lop VARCHAR(100) NOT NULL,
        nganh VARCHAR(50) NOT NULL,
        huynh_truong TEXT NULL,
        diem_15p1 DECIMAL(4,2) NULL,
        diem_15p2 DECIMAL(4,2) NULL,
        diem_1tiet1 DECIMAL(4,2) NULL,
        diem_1tiet2 DECIMAL(4,2) NULL,
        diem_ck1 DECIMAL(4,2) NULL,
        diem_ck2 DECIMAL(4,2) NULL,
        diem_tb DECIMAL(4,2) NULL,
        xep_hang INT NULL,
        att_total INT NOT NULL DEFAULT 0,
        att_co_phep INT NOT NULL DEFAULT 0,
        att_khong_phep INT NOT NULL DEFAULT 0,
        att_di_muon INT NOT NULL DEFAULT 0,
        att_absent INT NOT NULL DEFAULT 0,
        att_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        KEY idx_year (academic_year_id),
        KEY idx_class_snap (year_class_snapshot_id),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $class_cols = [
        'truong_lop' => 'TEXT NULL',
        'pho_lop' => 'TEXT NULL',
    ];
    foreach ($class_cols as $col => $def) {
        if (!ay_column_exists($conn, 'year_class_snapshots', $col)) {
            $conn->query("ALTER TABLE year_class_snapshots ADD COLUMN $col $def");
        }
    }

    $student_cols = [
        'diem_15p1' => 'DECIMAL(4,2) NULL',
        'diem_15p2' => 'DECIMAL(4,2) NULL',
        'diem_1tiet1' => 'DECIMAL(4,2) NULL',
        'diem_1tiet2' => 'DECIMAL(4,2) NULL',
        'diem_ck1' => 'DECIMAL(4,2) NULL',
        'diem_ck2' => 'DECIMAL(4,2) NULL',
        'att_co_phep' => 'INT NOT NULL DEFAULT 0',
        'att_khong_phep' => 'INT NOT NULL DEFAULT 0',
        'att_di_muon' => 'INT NOT NULL DEFAULT 0',
    ];
    foreach ($student_cols as $col => $def) {
        if (!ay_column_exists($conn, 'year_student_snapshots', $col)) {
            $conn->query("ALTER TABLE year_student_snapshots ADD COLUMN $col $def");
        }
    }
}

function ay_grade_snapshot_map() {
    return [
        '15 phút 1' => 'diem_15p1',
        '15 phút 2' => 'diem_15p2',
        '1 tiết 1' => 'diem_1tiet1',
        '1 tiết 2' => 'diem_1tiet2',
        'Cuối kỳ 1' => 'diem_ck1',
        'Cuối kỳ 2' => 'diem_ck2',
    ];
}

function ay_get_class_teachers_text($conn, $class_id) {
    $class_id = (int)$class_id;
    $sql = "SELECT t.ho_ten, t.ten_thanh, ct.vai_tro
            FROM class_teachers ct
            JOIN teachers t ON ct.teacher_id = t.id
            WHERE ct.class_id = $class_id
            ORDER BY FIELD(ct.vai_tro, 'Trưởng Lớp', 'Phó Lớp', 'Dự Trưởng'), t.ho_ten";
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        return 'Chưa có';
    }
    $parts = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim(($row['ten_thanh'] ? $row['ten_thanh'] . ' ' : '') . $row['ho_ten']);
        $parts[] = $name . ' (' . $row['vai_tro'] . ')';
    }
    return implode(' - ', $parts);
}

function ay_teacher_names_by_role($conn, $class_id, $role_like) {
    $class_id = (int)$class_id;
    $role_esc = $conn->real_escape_string($role_like);
    $sql = "SELECT t.ho_ten, t.ten_thanh
            FROM class_teachers ct
            JOIN teachers t ON ct.teacher_id = t.id
            WHERE ct.class_id = $class_id AND ct.vai_tro LIKE '%$role_esc%'
            ORDER BY t.ho_ten";
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        return 'Chưa có';
    }
    $parts = [];
    while ($row = $res->fetch_assoc()) {
        $parts[] = trim(($row['ten_thanh'] ? $row['ten_thanh'] . ' ' : '') . $row['ho_ten']);
    }
    return implode(', ', $parts);
}

function ay_get_truong_lop_text($conn, $class_id) {
    return ay_teacher_names_by_role($conn, $class_id, 'Trưởng');
}

function ay_get_pho_lop_text($conn, $class_id) {
    return ay_teacher_names_by_role($conn, $class_id, 'Phó');
}

function ay_detect_nien_khoa_from_classes($conn) {
    $res = $conn->query("SELECT nien_khoa FROM classes ORDER BY id LIMIT 1");
    if ($res && ($row = $res->fetch_assoc()) && !empty($row['nien_khoa'])) {
        return $row['nien_khoa'];
    }
    $y = (int)date('Y');
    $m = (int)date('n');
    if ($m >= 8) {
        return $y . '-' . ($y + 1);
    }
    return ($y - 1) . '-' . $y;
}

function ay_next_nien_khoa_label($label) {
    if (preg_match('/^(\d{4})-(\d{4})$/', trim($label), $m)) {
        $start = (int)$m[1] + 1;
        return $start . '-' . ($start + 1);
    }
    $y = (int)date('Y');
    return ($y + 1) . '-' . ($y + 2);
}

function ay_get_current_year($conn) {
    ay_ensure_schema($conn);
    $res = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 AND trang_thai = 'active' ORDER BY id DESC LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    $label = ay_detect_nien_khoa_from_classes($conn);
    $label_esc = $conn->real_escape_string($label);
    $conn->query("INSERT INTO academic_years (ten_nam_hoc, trang_thai, is_current) VALUES ('$label_esc', 'active', 1)");
    $id = (int)$conn->insert_id;
    $res2 = $conn->query("SELECT * FROM academic_years WHERE id = $id");
    return $res2 ? $res2->fetch_assoc() : null;
}

function ay_list_years($conn) {
    ay_ensure_schema($conn);
    $years = [];
    $res = $conn->query("SELECT * FROM academic_years ORDER BY ten_nam_hoc DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $years[] = $row;
        }
    }
    return $years;
}

function ay_get_year($conn, $year_id) {
    $year_id = (int)$year_id;
    $res = $conn->query("SELECT * FROM academic_years WHERE id = $year_id");
    return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
}

function ay_attendance_stats($conn, $student_id, $class_id) {
    $student_id = (int)$student_id;
    $class_id = (int)$class_id;
    $sql = "SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'vắng có phép' THEN 1 ELSE 0 END) AS co_phep,
                   SUM(CASE WHEN status = 'vắng không phép' THEN 1 ELSE 0 END) AS khong_phep,
                   SUM(CASE WHEN status IN ('đi muộn','Đi muộn') THEN 1 ELSE 0 END) AS di_muon,
                   SUM(CASE WHEN status IN ('vắng có phép','vắng không phép') THEN 1 ELSE 0 END) AS absent
            FROM attendance
            WHERE student_id = $student_id AND class_id = $class_id AND activity_type = 'Học Giáo Lý'";
    $res = $conn->query($sql);
    $total = 0;
    $co_phep = 0;
    $khong_phep = 0;
    $di_muon = 0;
    $absent = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $total = (int)$row['total'];
        $co_phep = (int)$row['co_phep'];
        $khong_phep = (int)$row['khong_phep'];
        $di_muon = (int)$row['di_muon'];
        $absent = (int)$row['absent'];
    }
    $percent = $total > 0 ? round(($absent / $total) * 100, 2) : 0;
    return [
        'total' => $total,
        'co_phep' => $co_phep,
        'khong_phep' => $khong_phep,
        'di_muon' => $di_muon,
        'absent' => $absent,
        'percent' => $percent,
    ];
}

function ay_clear_year_snapshots($conn, $academic_year_id) {
    $academic_year_id = (int)$academic_year_id;
    if (!$conn->query("DELETE FROM year_student_snapshots WHERE academic_year_id = $academic_year_id")) {
        return false;
    }
    if (!$conn->query("DELETE FROM year_class_snapshots WHERE academic_year_id = $academic_year_id")) {
        return false;
    }
    return true;
}

function ay_build_year_snapshot($conn, $academic_year_id, $ten_nam_hoc, $replace_existing = true) {
    $academic_year_id = (int)$academic_year_id;
    $ten_esc = $conn->real_escape_string($ten_nam_hoc);

    if ($replace_existing && !ay_clear_year_snapshots($conn, $academic_year_id)) {
        return ['ok' => false, 'message' => 'Lỗi xóa tổng kết cũ: ' . $conn->error];
    }

    $grade_map = ay_grade_snapshot_map();

    $classes_res = $conn->query("SELECT id, ten_lop, nganh FROM classes WHERE nien_khoa = '$ten_esc' ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop");
    if (!$classes_res) {
        return ['ok' => false, 'message' => 'Lỗi truy vấn lớp: ' . $conn->error];
    }

    $class_count = 0;
    $student_count = 0;

    while ($class = $classes_res->fetch_assoc()) {
        $class_id = (int)$class['id'];
        $huynh_truong = ay_get_class_teachers_text($conn, $class_id);
        $truong_lop = ay_get_truong_lop_text($conn, $class_id);
        $pho_lop = ay_get_pho_lop_text($conn, $class_id);
        $huynh_esc = $conn->real_escape_string($huynh_truong);
        $truong_esc = $conn->real_escape_string($truong_lop);
        $pho_esc = $conn->real_escape_string($pho_lop);
        $ten_lop_esc = $conn->real_escape_string($class['ten_lop']);
        $nganh_esc = $conn->real_escape_string($class['nganh']);

        $si_so_res = $conn->query("SELECT COUNT(*) AS c FROM students WHERE class_id = $class_id AND status = 'active'");
        $si_so = ($si_so_res && ($sr = $si_so_res->fetch_assoc())) ? (int)$sr['c'] : 0;

        if (!$conn->query("INSERT INTO year_class_snapshots (academic_year_id, class_id, ten_lop, nganh, huynh_truong, truong_lop, pho_lop, si_so)
                           VALUES ($academic_year_id, $class_id, '$ten_lop_esc', '$nganh_esc', '$huynh_esc', '$truong_esc', '$pho_esc', $si_so)")) {
            return ['ok' => false, 'message' => 'Lỗi lưu lớp: ' . $conn->error];
        }
        $snap_class_id = (int)$conn->insert_id;
        $class_count++;

        $students_res = $conn->query("SELECT id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh FROM students WHERE class_id = $class_id AND status = 'active' ORDER BY ho_ten");
        if (!$students_res) {
            continue;
        }

        $grades_map = [];
        $gr = $conn->query("SELECT student_id, loai_diem, diem_so FROM grades WHERE class_id = $class_id");
        if ($gr) {
            while ($g = $gr->fetch_assoc()) {
                $grades_map[(int)$g['student_id']][$g['loai_diem']] = $g['diem_so'];
            }
        }

        $tb_list = [];
        $student_rows = [];
        while ($st = $students_res->fetch_assoc()) {
            $sid = (int)$st['id'];
            $student_grades = isset($grades_map[$sid]) ? $grades_map[$sid] : [];
            $tb = ay_compute_tb($student_grades);
            $att = ay_attendance_stats($conn, $sid, $class_id);
            $student_rows[] = [
                'student' => $st,
                'grades' => $student_grades,
                'tb' => $tb,
                'att' => $att,
            ];
            $tb_list[$sid] = $tb !== null ? $tb : -1;
        }

        arsort($tb_list);
        $rank = 1;
        $prev_tb = null;
        $ranks = [];
        $last_rank = 1;
        foreach ($tb_list as $sid => $tb) {
            if ($tb !== $prev_tb) {
                $ranks[$sid] = $rank;
                $prev_tb = $tb;
            } else {
                $ranks[$sid] = $last_rank;
            }
            $last_rank = $ranks[$sid];
            $rank++;
        }

        foreach ($student_rows as $item) {
            $st = $item['student'];
            $sid = (int)$st['id'];
            $ho_ten_esc = $conn->real_escape_string($st['ho_ten']);
            $ten_thanh_esc = $conn->real_escape_string($st['ten_thanh'] ?: '');
            $ngay_sinh_sql = !empty($st['ngay_sinh']) ? "'" . $conn->real_escape_string($st['ngay_sinh']) . "'" : 'NULL';
            $gioi_tinh_esc = $conn->real_escape_string($st['gioi_tinh'] ?: '');
            $tb_sql = $item['tb'] !== null ? (float)$item['tb'] : 'NULL';
            $xep_hang = isset($ranks[$sid]) ? (int)$ranks[$sid] : 'NULL';
            $att = $item['att'];

            $grade_sql = [];
            foreach ($grade_map as $loai => $col) {
                $val = isset($item['grades'][$loai]) && is_numeric($item['grades'][$loai]) ? (float)$item['grades'][$loai] : 'NULL';
                $grade_sql[] = $val === 'NULL' ? 'NULL' : $val;
            }

            $sql_ins = "INSERT INTO year_student_snapshots
                (academic_year_id, year_class_snapshot_id, student_id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh,
                 ten_lop, nganh, huynh_truong,
                 diem_15p1, diem_15p2, diem_1tiet1, diem_1tiet2, diem_ck1, diem_ck2,
                 diem_tb, xep_hang,
                 att_total, att_co_phep, att_khong_phep, att_di_muon, att_absent, att_percent)
                VALUES ($academic_year_id, $snap_class_id, $sid, '$ho_ten_esc', '$ten_thanh_esc', $ngay_sinh_sql, '$gioi_tinh_esc',
                        '$ten_lop_esc', '$nganh_esc', '$truong_esc',
                        {$grade_sql[0]}, {$grade_sql[1]}, {$grade_sql[2]}, {$grade_sql[3]}, {$grade_sql[4]}, {$grade_sql[5]},
                        " . ($tb_sql === 'NULL' ? 'NULL' : $tb_sql) . ", $xep_hang,
                        {$att['total']}, {$att['co_phep']}, {$att['khong_phep']}, {$att['di_muon']}, {$att['absent']}, {$att['percent']})";
            if (!$conn->query($sql_ins)) {
                return ['ok' => false, 'message' => 'Lỗi lưu đoàn sinh: ' . $conn->error];
            }
            $student_count++;
        }
    }

    return ['ok' => true, 'class_count' => $class_count, 'student_count' => $student_count];
}

function ay_save_year_snapshot($conn, $academic_year_id, $ten_nam_hoc, $ghi_chu = '') {
    ay_ensure_schema($conn);
    $snap = ay_build_year_snapshot($conn, $academic_year_id, $ten_nam_hoc, true);
    if (!$snap['ok']) {
        return $snap;
    }
    if ($ghi_chu !== '') {
        $ghi_chu_esc = $conn->real_escape_string($ghi_chu);
        $academic_year_id = (int)$academic_year_id;
        $conn->query("UPDATE academic_years SET ghi_chu = '$ghi_chu_esc' WHERE id = $academic_year_id");
    }
    return $snap;
}

function ay_end_year_and_start_new($conn, $ghi_chu = '') {
    ay_ensure_schema($conn);
    $current = ay_get_current_year($conn);
    if (!$current) {
        return ['ok' => false, 'message' => 'Không xác định được năm học hiện tại.'];
    }

    $year_id = (int)$current['id'];
    $ten_nam = $current['ten_nam_hoc'];
    $next_label = ay_next_nien_khoa_label($ten_nam);

    $dup = $conn->query("SELECT id FROM academic_years WHERE ten_nam_hoc = '" . $conn->real_escape_string($next_label) . "'");
    if ($dup && $dup->num_rows > 0) {
        return ['ok' => false, 'message' => "Năm học $next_label đã tồn tại."];
    }

    $conn->begin_transaction();
    try {
        $snap = ay_build_year_snapshot($conn, $year_id, $ten_nam);
        if (!$snap['ok']) {
            throw new Exception($snap['message']);
        }

        $ghi_chu_esc = $conn->real_escape_string($ghi_chu);
        if (!$conn->query("UPDATE academic_years SET trang_thai = 'archived', is_current = 0, archived_at = NOW(), ghi_chu = '$ghi_chu_esc' WHERE id = $year_id")) {
            throw new Exception('Lỗi cập nhật năm cũ: ' . $conn->error);
        }

        $next_esc = $conn->real_escape_string($next_label);
        if (!$conn->query("INSERT INTO academic_years (ten_nam_hoc, trang_thai, is_current) VALUES ('$next_esc', 'active', 1)")) {
            throw new Exception('Lỗi tạo năm mới: ' . $conn->error);
        }

        if (!$conn->query("UPDATE classes SET nien_khoa = '$next_esc'")) {
            throw new Exception('Lỗi cập nhật niên khóa lớp: ' . $conn->error);
        }

        if (!$conn->query('DELETE FROM attendance')) {
            throw new Exception('Lỗi xóa điểm danh: ' . $conn->error);
        }
        if (!$conn->query('DELETE FROM grades')) {
            throw new Exception('Lỗi xóa điểm: ' . $conn->error);
        }

        $conn->commit();
        return [
            'ok' => true,
            'archived_year' => $ten_nam,
            'new_year' => $next_label,
            'class_count' => $snap['class_count'],
            'student_count' => $snap['student_count'],
        ];
    } catch (Exception $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function ay_year_summary_stats($conn, $year_id, $class_ids = null) {
    $year_id = (int)$year_id;
    $stats = array('classes' => 0, 'students' => 0, 'avg_tb' => null);

    $class_where = "academic_year_id = $year_id";
    $student_where = "academic_year_id = $year_id";
    if (is_array($class_ids)) {
        $class_ids = array_values(array_filter(array_map('intval', $class_ids)));
        if (empty($class_ids)) {
            return $stats;
        }
        $in = implode(',', $class_ids);
        $class_where .= " AND class_id IN ($in)";
        $snap_ids = ay_year_class_snapshot_ids_for_classes($conn, $year_id, $class_ids);
        if (empty($snap_ids)) {
            return $stats;
        }
        $student_where .= ' AND year_class_snapshot_id IN (' . implode(',', $snap_ids) . ')';
    }

    $r1 = $conn->query("SELECT COUNT(*) AS c FROM year_class_snapshots WHERE $class_where");
    if ($r1 && ($row = $r1->fetch_assoc())) {
        $stats['classes'] = (int)$row['c'];
    }
    $r2 = $conn->query("SELECT COUNT(*) AS c FROM year_student_snapshots WHERE $student_where");
    if ($r2 && ($row = $r2->fetch_assoc())) {
        $stats['students'] = (int)$row['c'];
    }
    $r3 = $conn->query("SELECT AVG(diem_tb) AS avg_tb FROM year_student_snapshots WHERE $student_where AND diem_tb IS NOT NULL");
    if ($r3 && ($row = $r3->fetch_assoc())) {
        $stats['avg_tb'] = $row['avg_tb'] !== null ? round((float)$row['avg_tb'], 2) : null;
    }
    return $stats;
}

/** class_id HT phụ trách (users.teacher_id → class_teachers). */
function ay_user_teacher_class_ids($conn, $user_id) {
    $user_id = (int)$user_id;
    $ids = array();
    $res = $conn->query("SELECT teacher_id FROM users WHERE id = $user_id LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc()) || empty($row['teacher_id'])) {
        return $ids;
    }
    $teacher_id = (int)$row['teacher_id'];
    $res2 = $conn->query("SELECT class_id FROM class_teachers WHERE teacher_id = $teacher_id");
    if ($res2) {
        while ($r = $res2->fetch_assoc()) {
            $ids[] = (int)$r['class_id'];
        }
    }
    return $ids;
}

function ay_year_class_snapshot_ids_for_classes($conn, $year_id, $class_ids) {
    $year_id = (int)$year_id;
    $snap_ids = array();
    if (!is_array($class_ids)) {
        return $snap_ids;
    }
    $class_ids = array_values(array_filter(array_map('intval', $class_ids)));
    if (empty($class_ids)) {
        return $snap_ids;
    }
    $in = implode(',', $class_ids);
    $res = $conn->query("SELECT id FROM year_class_snapshots WHERE academic_year_id = $year_id AND class_id IN ($in)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $snap_ids[] = (int)$row['id'];
        }
    }
    return $snap_ids;
}
