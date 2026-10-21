<?php
session_start();
require_once 'login_redirect.php';
$logged_in = isset($_SESSION['user_id']);
$current_role = login_current_role();
$is_admin = login_is_admin($current_role);
$is_hdt_manager = login_is_hdt_manager($current_role);
$is_huynh_truong = login_is_huynh_truong($current_role);

// Thiết lập múi giờ để hiển thị thời gian chính xác khi gửi mail/thao tác
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

// Load database configuration
require_once 'db_connect.php';

// Thêm cột activity_type vào bảng attendance nếu chưa có
$check_column = $conn->query("SHOW COLUMNS FROM attendance LIKE 'activity_type'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE attendance ADD COLUMN activity_type ENUM('Học Giáo Lý', 'Đi lễ') NOT NULL DEFAULT 'Học Giáo Lý' AFTER ngay_diem_danh");
    // Xóa khóa unique cũ nếu có
    $check_index = $conn->query("SHOW INDEX FROM attendance WHERE Key_name = 'unique_attendance'");
    if ($check_index && $check_index->num_rows > 0) {
        $conn->query("ALTER TABLE attendance DROP INDEX unique_attendance");
    }
    // Thêm khóa unique mới
    $conn->query("ALTER TABLE attendance ADD UNIQUE KEY unique_attendance (student_id, class_id, ngay_diem_danh, activity_type)");
}

$check_note_col = $conn->query("SHOW COLUMNS FROM attendance LIKE 'ghi_chu'");
if ($check_note_col && $check_note_col->num_rows == 0) {
    $conn->query("ALTER TABLE attendance ADD COLUMN ghi_chu VARCHAR(255) NULL DEFAULT NULL AFTER status");
}

// Học Giáo Lý và Đi lễ cùng ngày phải là 2 bản ghi riêng: bỏ các khóa unique thiếu activity_type
$att_unique_idx = array();
$res_idx = $conn->query("SHOW INDEX FROM attendance WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'");
if ($res_idx) {
    while ($ix = $res_idx->fetch_assoc()) {
        $att_unique_idx[$ix['Key_name']][] = $ix['Column_name'];
    }
}
$att_has_activity_unique = false;
foreach ($att_unique_idx as $cols) {
    if (in_array('activity_type', $cols, true)) {
        $att_has_activity_unique = true;
    }
}
if (!$att_has_activity_unique) {
    $new_key = isset($att_unique_idx['unique_attendance']) ? 'unique_att_activity' : 'unique_attendance';
    $conn->query("ALTER TABLE attendance ADD UNIQUE KEY $new_key (student_id, class_id, ngay_diem_danh, activity_type)");
}
foreach ($att_unique_idx as $key_name => $cols) {
    if (!in_array('activity_type', $cols, true) && in_array('ngay_diem_danh', $cols, true)) {
        $conn->query("ALTER TABLE attendance DROP INDEX `" . str_replace('`', '', $key_name) . "`");
    }
}

// Cột / enum tạm nghỉ học
$status_col = $conn->query("SHOW COLUMNS FROM students LIKE 'status'");
if ($status_col && $status_col->num_rows > 0) {
    $status_info = $status_col->fetch_assoc();
    if (strpos($status_info['Type'], 'on_leave') === false) {
        $conn->query("ALTER TABLE students MODIFY COLUMN status ENUM('active','inactive','transferred','on_leave') NOT NULL DEFAULT 'active'");
    }
}
$leave_col = $conn->query("SHOW COLUMNS FROM students LIKE 'leave_from'");
if ($leave_col && $leave_col->num_rows == 0) {
    $conn->query("ALTER TABLE students ADD COLUMN leave_from DATE NULL DEFAULT NULL AFTER status");
}

// ================== SMTP CONFIG (điền thông tin Gmail/SMTP của bạn) ==================
// Bật SMTP khi bạn đã nhập tài khoản và mật khẩu ứng dụng (khuyến nghị dùng App Password)
$SMTP_ENABLED   = true;             // bật SMTP
$SMTP_HOST      = 'smtp.gmail.com'; // Gmail SMTP
$SMTP_PORT      = 587;              // TLS
$SMTP_USERNAME  = 'savio220614@gmail.com';
$SMTP_PASSWORD  = str_replace(' ', '', 'nbdt jyey yywg antd');
$SMTP_FROM_EMAIL= 'savio220614@gmail.com';
$SMTP_FROM_NAME = 'Đoàn TNTT Đaminh Savio';

// Tự động load PHPMailer nếu đã cài bằng Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql_class = "SELECT ten_lop, nganh, ma_xac_nhan FROM classes WHERE id = $class_id";
$result_class = $conn->query($sql_class);
if (!$result_class || $result_class->num_rows == 0) { die("Lớp không tồn tại."); }
$class = $result_class->fetch_assoc();

// ================== XÁC THỰC MÃ LỚP ==================
// Kiểm tra xem lớp có yêu cầu mã xác nhận không
$require_verification = !empty($class['ma_xac_nhan']);

// Xử lý xác thực mã
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_class_code'])) {
    $input_code = trim($_POST['verification_code']);
    $correct_code = trim($class['ma_xac_nhan']);
    
    if ($input_code === $correct_code) {
        // Lưu trạng thái xác thực (tồn tại đến khi đăng xuất)
        $_SESSION['class_verified_' . $class_id] = true;
        $_SESSION['success'] = 'Xác thực thành công! Bạn có quyền chỉnh sửa cho đến khi đăng xuất.';
    } else {
        $_SESSION['error'] = 'Mã xác nhận không đúng!';
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý hủy xác thực
if (isset($_GET['logout_verification'])) {
    unset($_SESSION['class_verified_' . $class_id]);
    $_SESSION['success'] = 'Đã hủy quyền chỉnh sửa.';
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Trưởng Lớp / Phó Lớp của lớp này được sửa không cần mã
$is_class_leader = $logged_in ? login_is_class_leader($conn, $class_id) : false;

// Kiểm tra trạng thái xác thực
$is_verified = false;

// Admin / HDT / Trưởng-Phó lớp: không cần mã xác nhận
if ($is_hdt_manager || $is_class_leader) {
    $is_verified = true;
} elseif ($require_verification) {
    // Kiểm tra đã xác thực chưa (tồn tại đến khi đăng xuất)
    if (isset($_SESSION['class_verified_' . $class_id]) && $_SESSION['class_verified_' . $class_id] === true) {
        $is_verified = true;
    }
} else {
    // Nếu lớp không có mã xác nhận, cho phép tự do
    $is_verified = true;
}

$nganh_theme_map = [
    'Chiên Con' => 'theme-chien-con', 'Ấu Nhi' => 'theme-au-nhi', 'Thiếu Nhi' => 'theme-thieu-nhi',
    'Nghĩa Sĩ' => 'theme-nghia-si', 'Hiệp Sĩ' => 'theme-hiep-si',
];
$theme_class = isset($nganh_theme_map[$class['nganh']]) ? $nganh_theme_map[$class['nganh']] : 'theme-default';

// Danh sách lớp (dùng cho chuyển lớp)
$all_classes = [];
$res_all_classes = $conn->query("SELECT id, ten_lop, nganh FROM classes WHERE id != $class_id ORDER BY nganh, ten_lop");
if ($res_all_classes) {
    while ($row = $res_all_classes->fetch_assoc()) {
        $all_classes[] = $row;
    }
}
$classes_for_resume = [];
$res_resume_classes = $conn->query("SELECT id, ten_lop, nganh FROM classes ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop");
if ($res_resume_classes) {
    while ($row = $res_resume_classes->fetch_assoc()) {
        $classes_for_resume[] = $row;
    }
}

// Lấy dữ liệu Huynh Trưởng
$sql_teachers = "SELECT t.ho_ten, t.ten_thanh, t.ngay_sinh, t.ngay_gia_nhap, t.so_dien_thoai, t.email, ct.vai_tro 
                 FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = $class_id";
$result_teachers = $conn->query($sql_teachers);
if (!$result_teachers) { die("Lỗi truy vấn Huynh Trưởng: " . $conn->connect_error); }

// Lấy danh sách Huynh Trưởng (cho dropdown chọn người gửi)
$teachers_for_select = [];
$default_sender_id = 0;
// Lấy toàn bộ giáo lý viên, đánh dấu có thuộc lớp hiện tại hay không và vai trò nếu có
$sql_teachers_select = "SELECT t.id, t.ho_ten, t.ten_thanh, t.so_dien_thoai, t.email,
                               ct.vai_tro, (ct.class_id IS NOT NULL) AS is_in_class
                        FROM teachers t
                        LEFT JOIN class_teachers ct ON ct.teacher_id = t.id AND ct.class_id = $class_id
                        ORDER BY CASE
                            WHEN (ct.class_id IS NOT NULL AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Truong%')) THEN 0
                            WHEN (ct.class_id IS NOT NULL AND (ct.vai_tro LIKE '%Phó%' OR ct.vai_tro LIKE '%Pho%')) THEN 1
                            ELSE 2
                        END, t.ho_ten";
if ($res_sel_teachers = $conn->query($sql_teachers_select)) {
    while ($row = $res_sel_teachers->fetch_assoc()) {
        if ($default_sender_id === 0 && !empty($row['is_in_class']) && (stripos($row['vai_tro'], 'Trưởng') !== false || stripos($row['vai_tro'], 'Truong') !== false)) {
            $default_sender_id = (int)$row['id'];
        }
        $teachers_for_select[] = $row;
    }
}

// Lấy dữ liệu Đoàn Sinh (chỉ đang học trong lớp; tạm nghỉ chỉ hiện khi tìm kiếm)
$search_query = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$student_cols = $logged_in
    ? "id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ngay_gia_nhap, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu, status, leave_from, class_id"
    : "id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, status, leave_from, class_id";
$sql_students = "SELECT $student_cols FROM students WHERE class_id = $class_id AND status = 'active'";
if ($search_query) {
    $sql_students .= " AND (ho_ten LIKE '%$search_query%' OR ten_thanh LIKE '%$search_query%')";
}
$result_students = $conn->query($sql_students);
if (!$result_students) { die("Lỗi truy vấn Đoàn Sinh: " . $conn->connect_error); }

$students = [];
if ($result_students->num_rows > 0) {
    while ($row = $result_students->fetch_assoc()) {
        $row['is_on_leave'] = false;
        $students[] = $row;
    }
}

// Sĩ số lớp (không phụ thuộc tìm kiếm / tạm nghỉ)
$si_so_class = 0;
$res_si_so = $conn->query("SELECT COUNT(*) AS cnt FROM students WHERE class_id = $class_id AND status = 'active'");
if ($res_si_so && ($row_si = $res_si_so->fetch_assoc())) {
    $si_so_class = (int)$row_si['cnt'];
}

// Khi tìm theo tên: thêm đoàn sinh đang tạm nghỉ (không thuộc lớp nào)
$on_leave_in_search = [];
if ($logged_in && $search_query !== '') {
    $sql_leave = "SELECT $student_cols FROM students WHERE status = 'on_leave' AND (ho_ten LIKE '%$search_query%' OR ten_thanh LIKE '%$search_query%') ORDER BY ho_ten";
    $res_leave = $conn->query($sql_leave);
    if ($res_leave) {
        while ($row = $res_leave->fetch_assoc()) {
            $row['is_on_leave'] = true;
            $leave_days = 0;
            if (!empty($row['leave_from'])) {
                $leave_days = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($row['leave_from'])) / 86400));
            }
            $row['leave_days'] = $leave_days;
            $on_leave_in_search[] = $row;
        }
    }
}
$display_students = array_merge($students, $on_leave_in_search);

// Xuất danh sách Đoàn Sinh CSV
if (isset($_GET['export_students'])) {
    $formatExportDate = function ($value) {
        if (empty($value)) {
            return '';
        }
        return date('d/m/Y', strtotime($value));
    };
    $safe_class = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $class['ten_lop']);
    $filename = 'Danh_sach_doan_sinh_' . str_replace(' ', '_', $safe_class) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($logged_in) {
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', 'Giới Tính', 'Ngày Rửa Tội', 'Ngày Thêm Sức', 'Ngày Gia Nhập', 'Họ Tên Bố', 'Họ Tên Mẹ', 'SĐT Liên Lạc', 'Email Phụ Huynh', 'Địa Chỉ', 'Ghi Chú']);
        foreach ($students as $s) {
            fputcsv($output, [
                isset($s['ten_thanh']) ? $s['ten_thanh'] : '',
                $s['ho_ten'],
                $formatExportDate(isset($s['ngay_sinh']) ? $s['ngay_sinh'] : ''),
                isset($s['gioi_tinh']) ? $s['gioi_tinh'] : '',
                $formatExportDate(isset($s['ngay_rua_toi']) ? $s['ngay_rua_toi'] : ''),
                $formatExportDate(isset($s['ngay_them_suc']) ? $s['ngay_them_suc'] : ''),
                $formatExportDate(isset($s['ngay_gia_nhap']) ? $s['ngay_gia_nhap'] : ''),
                isset($s['ho_ten_bo']) ? $s['ho_ten_bo'] : '',
                isset($s['ho_ten_me']) ? $s['ho_ten_me'] : '',
                isset($s['sdt_lien_lac']) ? $s['sdt_lien_lac'] : '',
                isset($s['email_phu_huynh']) ? $s['email_phu_huynh'] : '',
                isset($s['dia_chi']) ? $s['dia_chi'] : '',
                isset($s['ghi_chu']) ? $s['ghi_chu'] : '',
            ]);
        }
    } else {
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', 'Giới Tính']);
        foreach ($students as $s) {
            fputcsv($output, [
                isset($s['ten_thanh']) ? $s['ten_thanh'] : '',
                $s['ho_ten'],
                $formatExportDate(isset($s['ngay_sinh']) ? $s['ngay_sinh'] : ''),
                isset($s['gioi_tinh']) ? $s['gioi_tinh'] : '',
            ]);
        }
    }
    fclose($output);
    exit;
}

// Lấy loại hoạt động đã chọn (mặc định Học Giáo Lý)
$selected_activity = (isset($_GET['activity']) && $_GET['activity'] === 'Đi lễ') ? 'Đi lễ' : 'Học Giáo Lý';

// Lấy 3 ngày điểm danh gần nhất theo loại hoạt động
$sql_attendance_history = "SELECT DISTINCT ngay_diem_danh FROM attendance WHERE class_id = $class_id AND activity_type = '$selected_activity' ORDER BY ngay_diem_danh DESC LIMIT 3";
$result_attendance_history = $conn->query($sql_attendance_history);
$attendance_dates = [];
if ($result_attendance_history && $result_attendance_history->num_rows > 0) {
    while ($row = $result_attendance_history->fetch_assoc()) {
        $attendance_dates[] = $row['ngay_diem_danh'];
    }
}

// Lấy attendance cho từng ngày
// Dữ liệu điểm danh trong database đã là tiếng Việt: có, đi muộn, vắng có phép, vắng không phép
$attendance_data = [];
$total_sessions = [];
$mass_sessions = [];

function cd_mass_percent_html($mass_sessions, $student_id) {
    $sid = (int)$student_id;
    if (empty($mass_sessions[$sid]['total'])) {
        return '<span class="text-muted">—</span>';
    }
    $percent = round(($mass_sessions[$sid]['present'] / $mass_sessions[$sid]['total']) * 100, 2);
    $cls = $percent < 60 ? 'absent-high' : '';
    return '<span class="' . $cls . '" title="' . (int)$mass_sessions[$sid]['present'] . '/' . (int)$mass_sessions[$sid]['total'] . ' buổi">' . $percent . '%</span>';
}

function cd_status_short($status) {
    if ($status === 'vắng không phép') {
        return 'Vắng KP';
    }
    if ($status === 'vắng có phép') {
        return 'Vắng Phép';
    }
    return $status;
}

function cd_absent_percent_html($total_sessions, $student_id) {
    $sid = (int)$student_id;
    $total = isset($total_sessions[$sid]['total']) ? $total_sessions[$sid]['total'] : 0;
    $absent = isset($total_sessions[$sid]['absent']) ? $total_sessions[$sid]['absent'] : 0;
    $percent = $total > 0 ? round(($absent / $total) * 100, 2) : 0;
    $cls = $percent > 40 ? 'absent-high' : '';
    return '<span class="' . $cls . '">' . $percent . '%</span>';
}

function cd_percent_header_html($activity) {
    if ($activity === 'Đi lễ') {
        return '<th class="attendance-col" title="Số buổi có mặt (có / đi muộn) trên tổng số buổi Đi lễ đã điểm danh">% Đi Lễ</th>';
    }
    return '<th class="attendance-col" title="Tính trên buổi Học Giáo Lý">% Nghỉ Học</th>';
}

function cd_percent_cell_html($activity, $total_sessions, $mass_sessions, $student_id) {
    return $activity === 'Đi lễ'
        ? cd_mass_percent_html($mass_sessions, $student_id)
        : cd_absent_percent_html($total_sessions, $student_id);
}
// Tối ưu: gom truy vấn theo mảng id học sinh và mảng ngày
$student_ids = array_column($students, 'id');
if (!empty($student_ids)) {
    $ids_list = implode(',', array_map('intval', $student_ids));
    // Tổng số buổi và số buổi vắng CHỈ TÍNH TRÊN HỌC GIÁO LÝ (không tính Đi lễ)
    $sql_totals = "SELECT student_id, COUNT(*) AS total, SUM(CASE WHEN status IN ('vắng có phép','vắng không phép') THEN 1 ELSE 0 END) AS absent
                   FROM attendance
                   WHERE class_id = $class_id AND student_id IN ($ids_list) AND activity_type = 'Học Giáo Lý'
                   GROUP BY student_id";
    if ($res_totals = $conn->query($sql_totals)) {
        while ($row = $res_totals->fetch_assoc()) {
            $total_sessions[(int)$row['student_id']] = [
                'total' => (int)$row['total'],
                'absent' => (int)$row['absent']
            ];
        }
    }

    // % Đi lễ: số lần có mặt (có / đi muộn) trên tổng số buổi lễ đã điểm danh
    $sql_mass = "SELECT student_id, COUNT(*) AS total, SUM(CASE WHEN status IN ('có','đi muộn') THEN 1 ELSE 0 END) AS present
                 FROM attendance
                 WHERE class_id = $class_id AND student_id IN ($ids_list) AND activity_type = 'Đi lễ'
                 GROUP BY student_id";
    if ($res_mass = $conn->query($sql_mass)) {
        while ($row = $res_mass->fetch_assoc()) {
            $mass_sessions[(int)$row['student_id']] = [
                'total' => (int)$row['total'],
                'present' => (int)$row['present']
            ];
        }
    }

    // Dữ liệu điểm danh cho 3 ngày gần nhất bằng 1 truy vấn IN (theo loại hoạt động đã chọn)
    if (!empty($attendance_dates)) {
        $date_list = array_map(function($d){ return "'" . $d . "'"; }, $attendance_dates);
        $dates_in = implode(',', $date_list);
        $sql_att_multi = "SELECT ngay_diem_danh, student_id, status
                          FROM attendance
                          WHERE class_id = $class_id AND ngay_diem_danh IN ($dates_in) AND student_id IN ($ids_list) AND activity_type = '$selected_activity'";
        if ($res_att = $conn->query($sql_att_multi)) {
            while ($row = $res_att->fetch_assoc()) {
                $attendance_data[$row['ngay_diem_danh']][(int)$row['student_id']] = $row['status'];
            }
        }
    }
}

// Chọn ngày đang xem điểm danh (để preload trạng thái)
$selected_attendance_date = isset($_GET['ngay_diem_danh']) && $_GET['ngay_diem_danh'] !== ''
    ? $conn->real_escape_string($_GET['ngay_diem_danh'])
    : date('Y-m-d');
$attendance_selected = [];
$attendance_selected_code = [];
$attendance_notes = [];
if (!empty($student_ids)) {
    $sql_att_selected = "SELECT student_id, status, ghi_chu FROM attendance WHERE class_id = $class_id AND ngay_diem_danh = '$selected_attendance_date' AND activity_type = '$selected_activity' AND student_id IN ($ids_list)";
    if ($res_sel = $conn->query($sql_att_selected)) {
        while ($row = $res_sel->fetch_assoc()) {
            $attendance_selected[(int)$row['student_id']] = $row['status'];
            $attendance_selected_code[(int)$row['student_id']] = $row['status'];
            $attendance_notes[(int)$row['student_id']] = isset($row['ghi_chu']) ? (string)$row['ghi_chu'] : '';
        }
    }
}

// Nội dung Nhắc nhở Phụ Huynh: chỉ học sinh vắng (có phép / không phép) trong ngày đang xem
$days_vn_reminder = ['Chúa Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
$reminder_header = $days_vn_reminder[(int)date('w', strtotime($selected_attendance_date))] . ' ngày ' . date('d/m/Y', strtotime($selected_attendance_date));
$is_mass_reminder = ($selected_activity === 'Đi lễ');
$reminder_absent = [];
$reminder_late = [];
$reminder_notes = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $name = trim((isset($s['ten_thanh']) ? $s['ten_thanh'] : '') . ' ' . $s['ho_ten']);
    $st = isset($attendance_selected[$sid]) ? $attendance_selected[$sid] : '';
    if ($st === 'vắng có phép' || $st === 'vắng không phép') {
        $line = '- ' . $name . ': ' . ($st === 'vắng có phép' ? 'Vắng có phép' : 'Vắng không phép');
        if (!$is_mass_reminder) {
            $absent = isset($total_sessions[$sid]['absent']) ? (int)$total_sessions[$sid]['absent'] : 0;
            $line .= ' (tổng số buổi vắng: ' . $absent . ' buổi)';
        }
        $reminder_absent[] = $line . '.';
    } elseif ($st === 'đi muộn') {
        $reminder_late[] = '- ' . $name . '.';
    }
    $note = isset($attendance_notes[$sid]) ? trim($attendance_notes[$sid]) : '';
    if ($note !== '') {
        $reminder_notes[] = '- ' . $name . ': ' . rtrim($note, '.') . '.';
    }
}
$reminder_lines = array_merge($reminder_absent, $reminder_late, $reminder_notes);

$reminder_parts = [];
$reminder_parts[] = 'Con xin gửi Quý Phụ Huynh thông tin điểm danh ' . ($is_mass_reminder ? 'Đi lễ' : 'Học Giáo Lý') . ' ' . $reminder_header . ':';
if (!empty($reminder_absent)) {
    $reminder_parts[] = "\nCác em vắng mặt:\n" . implode("\n", $reminder_absent);
}
if (!empty($reminder_late)) {
    $reminder_parts[] = "\nCác em " . ($is_mass_reminder ? 'đi lễ' : 'đi học') . " muộn:\n" . implode("\n", $reminder_late);
}
if (!empty($reminder_notes)) {
    $reminder_parts[] = "\nGhi chú:\n" . implode("\n", $reminder_notes);
}
if (empty($reminder_lines)) {
    $reminder_parts[] = "\nTất cả các em đều có mặt đầy đủ và đúng giờ.";
}
$reminder_parts[] = "\nCon xin cảm ơn!";
$reminder_full_text = implode("\n", $reminder_parts);

// Lấy điểm, tính trung bình và xếp hạng
$sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
$result_grades = $conn->query($sql_grades);
$grades_data = [];
if ($result_grades) {
    while ($row = $result_grades->fetch_assoc()) {
        $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
    }
}

$grade_min = 0;
$grade_max = 10;
$grade_step = 0.1;

function grade_normalize_score($diem) {
    if (!is_numeric($diem)) {
        return null;
    }
    $v = round((float)$diem, 1);
    if ($v < 0 || $v > 10) {
        return false;
    }
    return $v;
}

function grade_display_value(array $grades_data, $student_id, $loai_diem) {
    if (!isset($grades_data[$student_id][$loai_diem])) {
        return '';
    }
    $v = $grades_data[$student_id][$loai_diem];
    if ($v === null || $v === '') {
        return '';
    }
    if (!is_numeric($v)) {
        return '';
    }
    $s = rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
    if ($s === '') {
        $s = '0';
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function grade_is_low(array $grades_data, $student_id, $loai_diem) {
    if (!isset($grades_data[$student_id][$loai_diem]) || !is_numeric($grades_data[$student_id][$loai_diem])) {
        return false;
    }
    return (float)$grades_data[$student_id][$loai_diem] < 5;
}

$grade_columns = ['15 phút 1', '15 phút 2', '1 tiết 1', '1 tiết 2', 'Cuối kỳ 1', 'Cuối kỳ 2'];

function class_recompute_grade_stats($conn, $class_id) {
    $he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
    $class_id = (int)$class_id;
    $grades_data = [];

    $res = $conn->query("SELECT student_id, loai_diem, diem_so FROM grades WHERE class_id = $class_id");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $grades_data[(int)$row['student_id']][$row['loai_diem']] = $row['diem_so'];
        }
    }

    $compute_tb = function ($sid) use ($grades_data, $he_so_map) {
        $grades = isset($grades_data[$sid]) ? $grades_data[$sid] : [];
        $tb = 0;
        $total_he_so = 0;
        foreach ($grades as $loai => $diem) {
            if (!is_numeric($diem)) {
                continue;
            }
            $he_so = isset($he_so_map[$loai]) ? $he_so_map[$loai] : 1;
            $tb += ((float)$diem) * $he_so;
            $total_he_so += $he_so;
        }
        return $total_he_so ? round($tb / $total_he_so, 2) : 0;
    };

    $all_ids = [];
    $res_all = $conn->query("SELECT id FROM students WHERE class_id = $class_id AND status = 'active'");
    if ($res_all) {
        while ($r = $res_all->fetch_assoc()) {
            $all_ids[] = (int)$r['id'];
        }
    }

    $tb_all = [];
    foreach ($all_ids as $sid) {
        $tb_all[$sid] = $compute_tb($sid);
    }

    arsort($tb_all);
    $rank = 1;
    $previous_tb = null;
    $ranks_all = [];
    $keys = array_keys($tb_all);
    foreach ($keys as $index => $id) {
        $tb = $tb_all[$id];
        if ($tb !== $previous_tb) {
            $ranks_all[$id] = $rank;
            $previous_tb = $tb;
        } else {
            $ranks_all[$id] = isset($ranks_all[$keys[$index - 1]]) ? $ranks_all[$keys[$index - 1]] : $rank;
        }
        $rank++;
    }

    return ['tb' => $tb_all, 'ranks' => $ranks_all];
}

$he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];

// Hàm tính TB cho 1 học sinh dựa trên grades_data
$compute_tb = function($sid) use ($grades_data, $he_so_map) {
    $grades = isset($grades_data[$sid]) ? $grades_data[$sid] : [];
    $tb = 0; $total_he_so = 0;
    foreach ($grades as $loai => $diem) {
        if (!is_numeric($diem)) {
            continue;
        }
        $he_so = isset($he_so_map[$loai]) ? $he_so_map[$loai] : 1;
        $tb += ((float)$diem) * $he_so;
        $total_he_so += $he_so;
    }
    return $total_he_so ? round($tb / $total_he_so, 2) : 0;
};

// Lấy toàn bộ học sinh trong lớp để xếp hạng (không phụ thuộc kết quả tìm kiếm)
$all_ids = [];
if ($res_all_s = $conn->query("SELECT id FROM students WHERE class_id = $class_id AND status = 'active'")) {
    while ($r = $res_all_s->fetch_assoc()) { $all_ids[] = (int)$r['id']; }
}

// Tính TB cho toàn bộ lớp để xếp hạng chuẩn
$tb_all = [];
foreach ($all_ids as $sid) { $tb_all[$sid] = $compute_tb($sid); }
arsort($tb_all);
$rank = 1; $previous_tb = null; $ranks_all = [];
$keys = array_keys($tb_all);
foreach ($keys as $index => $id) {
    $tb = $tb_all[$id];
    if ($tb !== $previous_tb) { $ranks_all[$id] = $rank; $previous_tb = $tb; }
    else { $ranks_all[$id] = isset($ranks_all[$keys[$index - 1]]) ? $ranks_all[$keys[$index - 1]] : $rank; }
    $rank++;
}

// Tính TB cho danh sách đang hiển thị (để render cột Điểm TB)
$tb_display = [];
foreach ($students as $student) { $tb_display[$student['id']] = $compute_tb($student['id']); }

// Xử lý thêm Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['add_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để thêm đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = trim($_POST['ngay_sinh']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_sinh']) . "'" : "NULL";
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $ngay_rua_toi = trim($_POST['ngay_rua_toi']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_rua_toi']) . "'" : "NULL";
    $ngay_them_suc = trim($_POST['ngay_them_suc']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_them_suc']) . "'" : "NULL";
    $ngay_gia_nhap = trim($_POST['ngay_gia_nhap']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_gia_nhap']) . "'" : "NULL";
    $ho_ten_bo = $conn->real_escape_string($_POST['ho_ten_bo']);
    $ho_ten_me = $conn->real_escape_string($_POST['ho_ten_me']);
    $sdt_lien_lac = $conn->real_escape_string($_POST['sdt_lien_lac']);
    $email_phu_huynh = $conn->real_escape_string($_POST['email_phu_huynh']);
    $dia_chi = $conn->real_escape_string($_POST['dia_chi']);
    $ghi_chu = $conn->real_escape_string($_POST['ghi_chu']);
    // Validate SĐT: đúng 10 số nếu có nhập
    if ($sdt_lien_lac !== '' && !preg_match('/^\d{10}$/', $sdt_lien_lac)) {
        $_SESSION['error'] = 'SĐT Liên Lạc phải gồm đúng 10 số.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $sql = "INSERT INTO students (class_id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ngay_gia_nhap, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu) 
            VALUES ($class_id, '$ho_ten', '$ten_thanh', $ngay_sinh, '$gioi_tinh', $ngay_rua_toi, $ngay_them_suc, $ngay_gia_nhap, '$ho_ten_bo', '$ho_ten_me', '$sdt_lien_lac', '$email_phu_huynh', '$dia_chi', '$ghi_chu')";
    $conn->query($sql) ? $_SESSION['success'] = "Thêm Đoàn Sinh thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý sửa Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['edit_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để chỉnh sửa đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_id = (int)$_POST['student_id'];
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = trim($_POST['ngay_sinh']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_sinh']) . "'" : "NULL";
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $ngay_rua_toi = trim($_POST['ngay_rua_toi']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_rua_toi']) . "'" : "NULL";
    $ngay_them_suc = trim($_POST['ngay_them_suc']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_them_suc']) . "'" : "NULL";
    $ngay_gia_nhap = trim($_POST['ngay_gia_nhap']) !== '' ? "'" . $conn->real_escape_string($_POST['ngay_gia_nhap']) . "'" : "NULL";
    $ho_ten_bo = $conn->real_escape_string($_POST['ho_ten_bo']);
    $ho_ten_me = $conn->real_escape_string($_POST['ho_ten_me']);
    $sdt_lien_lac = $conn->real_escape_string($_POST['sdt_lien_lac']);
    $email_phu_huynh = $conn->real_escape_string($_POST['email_phu_huynh']);
    $dia_chi = $conn->real_escape_string($_POST['dia_chi']);
    $ghi_chu = $conn->real_escape_string($_POST['ghi_chu']);
    // Validate SĐT: đúng 10 số nếu có nhập
    if ($sdt_lien_lac !== '' && !preg_match('/^\d{10}$/', $sdt_lien_lac)) {
        $_SESSION['error'] = 'SĐT Liên Lạc phải gồm đúng 10 số.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $sql = "UPDATE students SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = $ngay_sinh, gioi_tinh = '$gioi_tinh', ngay_rua_toi = $ngay_rua_toi, ngay_them_suc = $ngay_them_suc, ngay_gia_nhap = $ngay_gia_nhap, ho_ten_bo = '$ho_ten_bo', ho_ten_me = '$ho_ten_me', sdt_lien_lac = '$sdt_lien_lac', email_phu_huynh = '$email_phu_huynh', dia_chi = '$dia_chi', ghi_chu = '$ghi_chu', updated_at = NOW() WHERE id = $student_id";
    $conn->query($sql) ? $_SESSION['success'] = "Sửa Đoàn Sinh thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý xóa Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để xóa đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_id = (int)$_POST['student_id'];
    $conn->query("DELETE FROM students WHERE id = $student_id");
    $_SESSION['success'] = "Xóa Đoàn Sinh thành công";
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý chuyển lớp Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['transfer_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để chuyển lớp đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_id = (int)$_POST['student_id'];
    $new_class_id = (int)$_POST['new_class_id'];
    if ($new_class_id <= 0 || $new_class_id === $class_id) {
        $_SESSION['error'] = 'Vui lòng chọn lớp khác để chuyển.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $check_student = $conn->query("SELECT ho_ten, ten_thanh FROM students WHERE id = $student_id AND class_id = $class_id");
    if (!$check_student || $check_student->num_rows === 0) {
        $_SESSION['error'] = 'Không tìm thấy đoàn sinh trong lớp này.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_row = $check_student->fetch_assoc();
    $check_class = $conn->query("SELECT ten_lop FROM classes WHERE id = $new_class_id");
    if (!$check_class || $check_class->num_rows === 0) {
        $_SESSION['error'] = 'Lớp đích không tồn tại.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $target_class = $check_class->fetch_assoc();
    $student_name = trim(($student_row['ten_thanh'] ? $student_row['ten_thanh'] . ' ' : '') . $student_row['ho_ten']);
    if ($conn->query("UPDATE students SET class_id = $new_class_id, updated_at = NOW() WHERE id = $student_id")) {
        $_SESSION['success'] = "Đã chuyển $student_name sang lớp " . $target_class['ten_lop'];
    } else {
        $_SESSION['error'] = "Lỗi chuyển lớp: " . $conn->error;
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý tạm nghỉ học
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['leave_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để tạm nghỉ đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_id = (int)$_POST['student_id'];
    $leave_from = isset($_POST['leave_from']) && $_POST['leave_from'] !== ''
        ? $conn->real_escape_string($_POST['leave_from'])
        : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leave_from)) {
        $leave_from = date('Y-m-d');
    }
    $check_student = $conn->query("SELECT ho_ten, ten_thanh FROM students WHERE id = $student_id AND class_id = $class_id AND status = 'active'");
    if (!$check_student || $check_student->num_rows === 0) {
        $_SESSION['error'] = 'Không tìm thấy đoàn sinh đang học trong lớp này.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_row = $check_student->fetch_assoc();
    $student_name = trim(($student_row['ten_thanh'] ? $student_row['ten_thanh'] . ' ' : '') . $student_row['ho_ten']);
    if ($conn->query("UPDATE students SET status = 'on_leave', class_id = NULL, leave_from = '$leave_from', updated_at = NOW() WHERE id = $student_id")) {
        $_SESSION['success'] = "Đã tạm nghỉ học cho $student_name từ ngày " . date('d/m/Y', strtotime($leave_from)) . ". Tìm theo tên để bật lại khi đi học trở lại.";
    } else {
        $_SESSION['error'] = "Lỗi tạm nghỉ: " . $conn->error;
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý đi học lại (bật lại + chọn lớp)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['resume_student'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để bật lại đoàn sinh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_id = (int)$_POST['student_id'];
    $new_class_id = (int)$_POST['new_class_id'];
    if ($new_class_id <= 0) {
        $_SESSION['error'] = 'Vui lòng chọn lớp để đi học lại.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $check_student = $conn->query("SELECT ho_ten, ten_thanh, leave_from FROM students WHERE id = $student_id AND status = 'on_leave'");
    if (!$check_student || $check_student->num_rows === 0) {
        $_SESSION['error'] = 'Không tìm thấy đoàn sinh đang tạm nghỉ.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $student_row = $check_student->fetch_assoc();
    $check_class = $conn->query("SELECT ten_lop FROM classes WHERE id = $new_class_id");
    if (!$check_class || $check_class->num_rows === 0) {
        $_SESSION['error'] = 'Lớp đích không tồn tại.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $target_class = $check_class->fetch_assoc();
    $student_name = trim(($student_row['ten_thanh'] ? $student_row['ten_thanh'] . ' ' : '') . $student_row['ho_ten']);
    $leave_days = 0;
    if (!empty($student_row['leave_from'])) {
        $leave_days = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($student_row['leave_from'])) / 86400));
    }
    if ($conn->query("UPDATE students SET status = 'active', class_id = $new_class_id, leave_from = NULL, updated_at = NOW() WHERE id = $student_id")) {
        $days_msg = $leave_days > 0 ? " (đã tạm nghỉ $leave_days ngày)" : '';
        $_SESSION['success'] = "Đã bật lại $student_name vào lớp " . $target_class['ten_lop'] . $days_msg;
        header("Location: class_details.php?id=$new_class_id");
        exit;
    }
    $_SESSION['error'] = "Lỗi đi học lại: " . $conn->error;
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý xóa toàn bộ danh sách Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_all_students'])) {
    // Xóa tất cả điểm danh
    $conn->query("DELETE FROM attendance WHERE class_id = $class_id");
    // Xóa tất cả điểm
    $conn->query("DELETE FROM grades WHERE class_id = $class_id");
    // Xóa tất cả học sinh
    $result = $conn->query("DELETE FROM students WHERE class_id = $class_id");
    if ($result) {
        $_SESSION['success'] = "Đã xóa toàn bộ danh sách Đoàn Sinh thành công";
    } else {
        $_SESSION['error'] = "Lỗi khi xóa danh sách: " . $conn->error;
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý xóa nhiều Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_multiple_students'])) {
    if (isset($_POST['selected_students']) && is_array($_POST['selected_students']) && !empty($_POST['selected_students'])) {
        $student_ids = array_map('intval', $_POST['selected_students']);
        $ids_list = implode(',', $student_ids);
        
        // Xóa điểm danh của các học sinh được chọn
        $conn->query("DELETE FROM attendance WHERE student_id IN ($ids_list)");
        // Xóa điểm của các học sinh được chọn
        $conn->query("DELETE FROM grades WHERE student_id IN ($ids_list)");
        // Xóa các học sinh được chọn
        $result = $conn->query("DELETE FROM students WHERE id IN ($ids_list) AND class_id = $class_id");
        
        if ($result) {
            $count = count($student_ids);
            $_SESSION['success'] = "Đã xóa thành công $count Đoàn Sinh";
        } else {
            $_SESSION['error'] = "Lỗi khi xóa Đoàn Sinh: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Vui lòng chọn ít nhất một Đoàn Sinh để xóa";
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Nhập danh sách Đoàn Sinh từ CSV (Excel lưu dưới dạng CSV UTF-8)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['import_students_csv']) && $is_verified) {
    $imported = 0; $skipped = 0; $errors = [];
    if (!isset($_FILES['students_file']) || $_FILES['students_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Vui lòng chọn tệp CSV hợp lệ.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $tmp = $_FILES['students_file']['tmp_name'];
    $fh = fopen($tmp, 'r');
    if (!$fh) {
        $_SESSION['error'] = 'Không đọc được tệp tải lên.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    // Bỏ BOM nếu có
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    // Bỏ qua dòng tiêu đề (khuyến nghị có)
    $header = fgetcsv($fh);

    // Hàm chuẩn hóa ngày về dạng Y-m-d; chấp nhận d/m/Y hoặc Y-m-d
    $toDate = function($value) {
        $v = trim((string)$value);
        if ($v === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $v)) {
            $parts = explode('/', $v);
            $d = (int)$parts[0]; $m = (int)$parts[1]; $y = (int)$parts[2];
            if ($y < 100) { $y += 2000; }
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : '';
    };

    // Chuẩn bị statement chèn
    $stmt = $conn->prepare("INSERT INTO students (class_id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ngay_gia_nhap, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        fclose($fh);
        $_SESSION['error'] = 'Không thể chuẩn bị câu lệnh: ' . $conn->error;
        header("Location: class_details.php?id=$class_id");
        exit;
    }

    while (($row = fgetcsv($fh)) !== false) {
        // Kỳ vọng 13-14 cột theo thứ tự: Tên Thánh, Họ Tên, Ngày Sinh, Giới Tính, Ngày Rửa Tội, Ngày Thêm Sức, Ngày Gia Nhập, Họ Tên Bố, Họ Tên Mẹ, SĐT Liên Lạc, Email Phụ Huynh, Địa Chỉ, Ghi Chú
        // Chấp nhận thiếu cột cuối, sẽ fill rỗng
        for ($i = 0; $i < 14; $i++) { if (!isset($row[$i])) $row[$i] = ''; }
        $ten_thanh      = trim($row[0]);
        $ho_ten         = trim($row[1]);
        $ngay_sinh      = $toDate($row[2]);
        $gioi_tinh      = trim($row[3]);
        $ngay_rua_toi   = $toDate($row[4]);
        $ngay_them_suc  = $toDate($row[5]);
        $ngay_gia_nhap  = $toDate($row[6]);
        $ho_ten_bo      = trim($row[7]);
        $ho_ten_me      = trim($row[8]);
        $sdt_lien_lac   = preg_replace('/\D+/', '', trim($row[9]));
        $email_phu_huynh= trim($row[10]);
        $dia_chi        = trim($row[11]);
        $ghi_chu        = trim($row[12]);

        if ($ho_ten === '') { $skipped++; continue; }
        if ($sdt_lien_lac !== '' && !preg_match('/^\d{10}$/', $sdt_lien_lac)) { $sdt_lien_lac = ''; }
        if ($gioi_tinh !== 'Nam' && $gioi_tinh !== 'Nữ') { $gioi_tinh = 'Nam'; }

        // Chuyển đổi chuỗi rỗng thành NULL cho các trường ngày tháng
        $ngay_sinh = ($ngay_sinh === '') ? null : $ngay_sinh;
        $ngay_rua_toi = ($ngay_rua_toi === '') ? null : $ngay_rua_toi;
        $ngay_them_suc = ($ngay_them_suc === '') ? null : $ngay_them_suc;
        $ngay_gia_nhap = ($ngay_gia_nhap === '') ? null : $ngay_gia_nhap;

        $stmt->bind_param(
            'isssssssssssss',
            $class_id,
            $ho_ten,
            $ten_thanh,
            $ngay_sinh,
            $gioi_tinh,
            $ngay_rua_toi,
            $ngay_them_suc,
            $ngay_gia_nhap,
            $ho_ten_bo,
            $ho_ten_me,
            $sdt_lien_lac,
            $email_phu_huynh,
            $dia_chi,
            $ghi_chu
        );
        if ($stmt->execute()) { $imported++; } else { $skipped++; $errors[] = $stmt->error; }
    }
    fclose($fh);
    $_SESSION['success'] = "Nhập CSV thành công: $imported dòng, bỏ qua $skipped dòng.";
    if (!empty($errors)) {
        $_SESSION['error'] = 'Một số dòng lỗi: ' . htmlspecialchars(implode('; ', array_slice($errors, 0, 3)));
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý điểm danh hàng loạt
// Xử lý xóa điểm danh theo ngày
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_attendance_by_date'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để xóa điểm danh.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $ngay_xoa = $conn->real_escape_string($_POST['ngay_xoa']);
    $activity_xoa = isset($_POST['activity_xoa']) ? $conn->real_escape_string($_POST['activity_xoa']) : '';
    
    if ($activity_xoa) {
        $sql_delete = "DELETE FROM attendance WHERE class_id = $class_id AND ngay_diem_danh = '$ngay_xoa' AND activity_type = '$activity_xoa'";
        $msg = "Đã xóa điểm danh $activity_xoa ngày " . date('d/m/Y', strtotime($ngay_xoa)) . " thành công";
    } else {
        $sql_delete = "DELETE FROM attendance WHERE class_id = $class_id AND ngay_diem_danh = '$ngay_xoa'";
        $msg = "Đã xóa toàn bộ điểm danh ngày " . date('d/m/Y', strtotime($ngay_xoa)) . " thành công";
    }
    
    if ($conn->query($sql_delete)) {
        $_SESSION['success'] = $msg;
    } else {
        $_SESSION['error'] = 'Lỗi khi xóa điểm danh: ' . $conn->error;
    }
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Chỉ xử lý lưu điểm danh nếu không bấm nút gửi mail/xuất file
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $logged_in &&
    isset($_POST['submit_attendance']) &&
    !isset($_POST['send_email']) &&
    !isset($_POST['export_attendance']) &&
    !isset($_POST['export_attendance_range'])
) {
    // Bỏ qua kiểm tra mã xác nhận cho điểm danh
    // if (!$is_verified) {
    //     $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để điểm danh.';
    //     header("Location: class_details.php?id=$class_id");
    //     exit;
    // }
    $ngay_diem_danh = $conn->real_escape_string($_POST['ngay_diem_danh']);
    $activity_type = (isset($_POST['activity_type']) && $_POST['activity_type'] === 'Đi lễ') ? 'Đi lễ' : 'Học Giáo Lý';
    $valid_statuses = array('có', 'đi muộn', 'vắng có phép', 'vắng không phép');
    $success = true;
    foreach ($_POST['status'] as $student_id => $status) {
        $student_id = (int)$student_id;
        if ($student_id <= 0 || !in_array($status, $valid_statuses, true)) {
            continue;
        }
        $status = $conn->real_escape_string($status);
        $note = isset($_POST['note'][$student_id]) ? trim((string)$_POST['note'][$student_id]) : '';
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 255, 'UTF-8');
        } else {
            $note = substr($note, 0, 255);
        }
        $note_sql = $note === '' ? 'NULL' : "'" . $conn->real_escape_string($note) . "'";
        $note_param = $note === '' ? null : $note;
        $sql_check = "SELECT id FROM attendance WHERE student_id = $student_id AND class_id = $class_id AND ngay_diem_danh = '$ngay_diem_danh' AND activity_type = '$activity_type'";
        $check = $conn->query($sql_check);
        if ($check->num_rows > 0) {
            if (!$conn->query("UPDATE attendance SET status = '$status', ghi_chu = $note_sql WHERE student_id = $student_id AND class_id = $class_id AND ngay_diem_danh = '$ngay_diem_danh' AND activity_type = '$activity_type'")) {
                $success = false;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO attendance (student_id, class_id, ngay_diem_danh, activity_type, status, ghi_chu) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissss", $student_id, $class_id, $ngay_diem_danh, $activity_type, $status, $note_param);
            if (!$stmt->execute()) {
                $success = false;
            }
        }
    }
    if ($success) {
        // Kiểm tra nếu là AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'success', 'message' => "Đã điểm danh $activity_type ngày " . date('d/m/Y', strtotime($ngay_diem_danh)) . " thành công"]);
            exit;
        } else {
            $_SESSION['success'] = "Đã điểm danh $activity_type ngày " . date('d/m/Y', strtotime($ngay_diem_danh)) . " thành công";
            // Redirect để nạp lại dữ liệu ngày vừa lưu và hiển thị dropdown đã chọn
            header("Location: class_details.php?id=$class_id&ngay_diem_danh=$ngay_diem_danh&activity=" . urlencode($activity_type));
            exit;
        }
    } else {
        // Kiểm tra nếu là AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => "Lỗi khi lưu điểm danh: " . $conn->error]);
            exit;
        } else {
            $_SESSION['error'] = "Lỗi khi lưu điểm danh: " . $conn->error;
        }
    }
}

// Xử lý gửi email hàng loạt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['send_email'])) {
    if (!$is_verified) {
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để gửi email.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $ngay_diem_danh = $conn->real_escape_string($_POST['ngay_diem_danh']);
    $ngay_hien_thi = date('d/m/Y', strtotime($ngay_diem_danh));
    // Lấy thời điểm hiện tại theo múi giờ đã thiết lập
    $gio_thong_bao = date('H:i');

    // Tìm tên Trưởng lớp (nếu có)
    $truong_lop = '';
    $sql_truong = "SELECT t.ho_ten FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = $class_id AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Truong%') LIMIT 1";
    if ($res_tr = $conn->query($sql_truong)) {
        if ($row_tr = $res_tr->fetch_assoc()) { $truong_lop = $row_tr['ho_ten']; }
    }
    if ($truong_lop === '') { $truong_lop = 'Trưởng lớp'; }

    // Chọn người gửi (nếu người dùng chọn)
    $sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;
    $sender = null;
    if ($sender_id > 0) {
        $q_sender = "SELECT t.ho_ten, t.ten_thanh, t.so_dien_thoai, t.email,
                            CASE
                                WHEN ct.class_id IS NOT NULL AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Truong%') THEN 'Trưởng lớp'
                                WHEN ct.class_id IS NOT NULL AND (ct.vai_tro LIKE '%Phó%' OR ct.vai_tro LIKE '%Pho%') THEN 'Phó lớp'
                                WHEN ct.class_id IS NOT NULL THEN 'Giáo lý viên'
                                ELSE 'Dạy thay'
                            END AS sender_role
                     FROM teachers t
                     LEFT JOIN class_teachers ct ON ct.teacher_id = t.id AND ct.class_id = $class_id
                     WHERE t.id = $sender_id
                     LIMIT 1";
        if ($res_sender = $conn->query($q_sender)) { $sender = $res_sender->fetch_assoc(); }
    }

    // Chỉ gửi tới đoàn sinh được chọn trong modal
    $email_recipients = [];
    if (isset($_POST['email_recipients']) && is_array($_POST['email_recipients'])) {
        foreach ($_POST['email_recipients'] as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) {
                $email_recipients[] = $rid;
            }
        }
        $email_recipients = array_values(array_unique($email_recipients));
    }
    if (empty($email_recipients)) {
        $_SESSION['error'] = 'Vui lòng chọn ít nhất một email để gửi.';
        header("Location: class_details.php?id=$class_id&ngay_diem_danh=$ngay_diem_danh");
        exit;
    }

    // TỐI ƯU: Lấy tất cả thông tin học sinh vắng trong 1 query duy nhất
    $absent_student_ids = [];
    foreach ($_POST['status'] as $student_id => $status) {
        $student_id = (int)$student_id;
        if (!in_array($student_id, $email_recipients, true)) {
            continue;
        }
        if ($status == 'vắng có phép' || $status == 'vắng không phép') {
            $absent_student_ids[] = $student_id;
        }
    }
    
    $students_info = [];
    if (!empty($absent_student_ids)) {
        $ids_str = implode(',', $absent_student_ids);
        $sql = "SELECT id, ho_ten, ten_thanh, email_phu_huynh FROM students WHERE id IN ($ids_str)";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $students_info[(int)$row['id']] = $row;
            }
        }
    }

    $sent = 0; $failed = 0; $lastError = '';
    foreach ($_POST['status'] as $student_id => $status) {
        $student_id = (int)$student_id;
        if (!in_array($student_id, $email_recipients, true)) {
            continue;
        }
        if ($status == 'vắng có phép' || $status == 'vắng không phép') {
            $student = isset($students_info[$student_id]) ? $students_info[$student_id] : null;
            if ($student && !empty($student['email_phu_huynh'])) {
                $to = $student['email_phu_huynh'];
                    $subject = "Thông báo vắng học Giáo Lý - Đoàn Sinh: " . ($student['ten_thanh'] ? ($student['ten_thanh'] . ' ') : '') . $student['ho_ten'];
                    
                    // Lấy thông tin tổng số buổi nghỉ của học sinh này
                    $student_total_sessions = isset($total_sessions[$student_id]['total']) ? $total_sessions[$student_id]['total'] : 0;
                    $student_absences = isset($total_sessions[$student_id]['absent']) ? $total_sessions[$student_id]['absent'] : 0;
                    
                    $noidung = "<html><body style='font-family: Arial, sans-serif;'>"
                             . "<p>Kính gửi Quý Phụ huynh!</p>"
                             . "<p>Đoàn Sinh: <strong>" . ($student['ten_thanh'] ? ($student['ten_thanh'] . ' ') : '') . $student['ho_ten'] . "</strong></p>"
                             . "<p>Trạng thái điểm danh: <strong>" . $status . "</strong></p>"
                             . "<p>Ngày điểm danh: <strong>" . $ngay_hien_thi . " (lúc $gio_thong_bao)</strong></p>"
                             . "<p>Tổng số buổi nghỉ học trong năm: <strong>" . $student_absences . " buổi.</strong></p>"
                             . "<p>Đoàn TNTT Đaminh Savio kính gửi thông báo đến quý phụ huynh để tiện theo dõi và đồng hành trong việc quản lý, nhắc nhở con em mình. Quý phụ huynh có thể liên hệ với giáo lý viên phụ trách để trao đổi thêm khi cần.</p>"
                             . "<p>Trân trọng,</p>";

                    // Chữ ký người gửi: Tên Thánh Họ và tên - Vai trò/ Dạy thay - Số điện thoại
                    if ($sender) {
                        $sender_fullname = trim(($sender['ten_thanh'] ? ($sender['ten_thanh'] . ' ') : '') . $sender['ho_ten']);
                        $sender_phone = $sender['so_dien_thoai'] ?: 'N/A';
                        $roleText = $sender['sender_role'] ?: 'Dạy thay';
                        $noidung .= "<p>" . $sender_fullname . " - " . $roleText . " - " . $sender_phone . "</p>";
                    } else {
                        $noidung .= "<p>" . $truong_lop . " - Trưởng lớp " . $class['ten_lop'] . "</p>";
                    }
                    $noidung .= "</body></html>";

                    $ok = false;
                    if ($SMTP_ENABLED && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                        try {
                            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                            $mail->CharSet = 'UTF-8';
                            $mail->isSMTP();
                            $mail->Host = $SMTP_HOST;
                            $mail->SMTPAuth = true;
                            $mail->Username = $SMTP_USERNAME;
                            $mail->Password = $SMTP_PASSWORD;
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = $SMTP_PORT;
                            $mail->SMTPDebug = 0; // set 2 để debug chi tiết nếu cần
                            $mail->isHTML(true); // Thiết lập gửi email dưới dạng HTML
                            // From: dùng email người gửi nếu có, nếu không fallback SMTP
                            $fromEmail = ($sender && !empty($sender['email'])) ? $sender['email'] : ($SMTP_FROM_EMAIL ?: $SMTP_USERNAME);
                            $fromName  = ($sender ? (trim(($sender['ten_thanh'] ? ($sender['ten_thanh'] . ' ') : '') . $sender['ho_ten'])) : ($SMTP_FROM_NAME ?: $truong_lop));
                            $mail->setFrom($fromEmail, $fromName);
                            $mail->addAddress($to);
                            $mail->Subject = $subject;
                            $mail->Body = $noidung;
                            $ok = $mail->send();
                            if (!$ok) { $lastError = $mail->ErrorInfo; }
                        } catch (Exception $e) {
                            $ok = false; $lastError = $e->getMessage();
                        }
                    } else {
                        $fromNameHdr = ($sender ? (trim(($sender['ten_thanh'] ? ($sender['ten_thanh'] . ' ') : '') . $sender['ho_ten'])) : $truong_lop);
                        $headers  = "From: " . ($SMTP_FROM_EMAIL ?: 'no-reply@example.com') . "\r\n";
                        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                        $ok = @mail($to, $subject, $noidung, $headers);
                        if (!$ok) { $lastError = 'mail() không gửi được (cần bật SMTP)'; }
                    }
                    if ($ok) { $sent++; } else { $failed++; }
            } else { $failed++; }
        }
    }
    if ($failed > 0 && $sent === 0) {
        if (!$SMTP_ENABLED || !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $_SESSION['error'] = "Không gửi được email. Vui lòng cài PHPMailer (composer require phpmailer/phpmailer) hoặc kiểm tra cấu hình SMTP.";
        } else {
            $_SESSION['error'] = "Không gửi được email: " . ($lastError ?: 'Lỗi không xác định');
        }
    } else {
        $_SESSION['success'] = "Gửi email: $sent thành công, $failed thất bại";
    }
    header("Location: class_details.php?id=$class_id&ngay_diem_danh=$ngay_diem_danh");
    exit;
}

// Xử lý sửa điểm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['submit_grades'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
    }

    if (!$is_verified) {
        if ($is_ajax) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập mã xác nhận để chấm điểm.']);
            exit;
        }
        $_SESSION['error'] = 'Vui lòng nhập mã xác nhận để chấm điểm.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }

    $ngay_cham_diem = date('Y-m-d');
    $saved_count = 0;
    $deleted_count = 0;
    $error_occurred = false;
    $error_msg = '';

    $existing_grades = [];
    $res_existing = $conn->query("SELECT student_id, loai_diem FROM grades WHERE class_id = " . (int)$class_id);
    if ($res_existing) {
        while ($row = $res_existing->fetch_assoc()) {
            $existing_grades[(int)$row['student_id']][$row['loai_diem']] = true;
        }
    }

    if (!empty($_POST['diem']) && is_array($_POST['diem'])) {
        foreach ($_POST['diem'] as $student_id => $grades) {
            if (!is_array($grades)) continue;
            $student_id = (int)$student_id;

            foreach ($grades as $loai_diem => $diem) {
                $diem = trim((string)$diem);
                $loai_diem_safe = $conn->real_escape_string($loai_diem);

                if ($diem === '') {
                    if (empty($existing_grades[$student_id][$loai_diem])) {
                        continue;
                    }
                    $stmt = $conn->prepare("DELETE FROM grades WHERE student_id = ? AND class_id = ? AND loai_diem = ?");
                    if (!$stmt) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi prepare DELETE: ' . $conn->error;
                        break 2;
                    }
                    $stmt->bind_param("iis", $student_id, $class_id, $loai_diem_safe);
                    if (!$stmt->execute()) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi execute DELETE: ' . $stmt->error;
                        $stmt->close();
                        break 2;
                    }
                    if ($stmt->affected_rows > 0) {
                        $deleted_count++;
                        unset($existing_grades[$student_id][$loai_diem]);
                    }
                    $stmt->close();
                    continue;
                }

                if (!is_numeric($diem)) {
                    continue;
                }

                $diem_so = grade_normalize_score($diem);
                if ($diem_so === null) {
                    continue;
                }
                if ($diem_so === false) {
                    $error_occurred = true;
                    $error_msg = 'Điểm phải từ 0 đến 10 (bước 0.1).';
                    break 2;
                }

                if (!empty($existing_grades[$student_id][$loai_diem])) {
                    $stmt = $conn->prepare("UPDATE grades SET diem_so = ?, ngay_cham_diem = ? WHERE student_id = ? AND class_id = ? AND loai_diem = ?");
                    if (!$stmt) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi prepare UPDATE: ' . $conn->error;
                        break 2;
                    }
                    $stmt->bind_param("dsiis", $diem_so, $ngay_cham_diem, $student_id, $class_id, $loai_diem_safe);
                    if (!$stmt->execute()) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi execute UPDATE: ' . $stmt->error;
                        $stmt->close();
                        break 2;
                    }
                    if ($stmt->affected_rows > 0) {
                        $saved_count++;
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO grades (student_id, class_id, loai_diem, diem_so, ngay_cham_diem) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi prepare INSERT: ' . $conn->error;
                        break 2;
                    }
                    $stmt->bind_param("iisds", $student_id, $class_id, $loai_diem_safe, $diem_so, $ngay_cham_diem);
                    if (!$stmt->execute()) {
                        $error_occurred = true;
                        $error_msg = 'Lỗi execute INSERT: ' . $stmt->error;
                        $stmt->close();
                        break 2;
                    }
                    if ($stmt->affected_rows > 0) {
                        $saved_count++;
                        $existing_grades[$student_id][$loai_diem] = true;
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($error_occurred) {
        if ($is_ajax) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $error_msg]);
        } else {
            $_SESSION['error'] = $error_msg;
            header("Location: class_details.php?id=$class_id");
        }
    } else {
        $msg_parts = [];
        if ($saved_count > 0) {
            $msg_parts[] = "Lưu $saved_count điểm";
        }
        if ($deleted_count > 0) {
            $msg_parts[] = "Xóa $deleted_count điểm";
        }
        $message = !empty($msg_parts) ? implode(', ', $msg_parts) . ' thành công!' : 'Không có thay đổi.';
        if ($is_ajax) {
            $stats = class_recompute_grade_stats($conn, $class_id);
            echo json_encode([
                'status' => 'success',
                'message' => $message,
                'saved' => $saved_count,
                'deleted' => $deleted_count,
                'tb' => $stats['tb'],
                'ranks' => $stats['ranks'],
            ]);
        } else {
            $_SESSION['success'] = $message;
            header("Location: class_details.php?id=$class_id");
        }
    }
    exit;
}

// Hàm xuất Excel
function exportExcel($conn, $class, $students, $attendance_data, $total_sessions, $type, $class_id) {
    // Nếu xuất điểm -> xuất HTML bảng (Excel mở được) để bôi đỏ điểm <5 và có tiêu đề
    if ($type === 'diem') {
        $safe_class = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $class['ten_lop']);
        $title = 'Bảng Điểm Lớp ' . htmlspecialchars($class['ten_lop']);
        $filename = 'Bang_diem_' . str_replace(' ', '_', $safe_class) . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "<html><head><meta charset='utf-8'><style>
                table{border-collapse:collapse;width:100%;table-layout:fixed;}
                th,td{border:1px solid #000000;padding:6px;text-align:center;height:30px}
                th{background:#f2f2f2;font-weight:600}
                .low{color:red}
              </style></head><body>";
        echo "<table>";
        // Cố định chiều rộng cột 100px bằng colgroup để Excel nhận đúng
        echo "<colgroup>"
            . str_repeat("<col style='width:100px'>", 11)
            . "</colgroup>";
        echo "<tr style='height:30px'><th colspan='11' style='font-size:18px'>" . $title . "</th></tr>";
        echo "<tr style='height:30px'>
                <th>Tên Thánh</th><th>Họ Tên</th><th>Ngày Sinh</th>
                <th>15 phút 1</th><th>15 phút 2</th><th>1 tiết 1</th><th>1 tiết 2</th>
                <th>Cuối kỳ 1</th><th>Cuối kỳ 2</th><th>Điểm TB</th><th>Xếp Hạng</th>
              </tr>";
        // Lấy điểm và tính TB/XH
        $sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
        $result_grades = $conn->query($sql_grades);
        $grades_data = [];
        if ($result_grades) {
            while ($row = $result_grades->fetch_assoc()) {
                $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
            }
        }
        $he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
        $tb_array = [];
        foreach ($students as $student) {
            $grades = isset($grades_data[$student['id']]) ? $grades_data[$student['id']] : [];
            $tb = 0; $total_he_so = 0;
            foreach ($grades as $loai => $diem) {
                $he_so = isset($he_so_map[$loai]) ? $he_so_map[$loai] : 1;
                $tb += ((float)$diem) * $he_so;
                $total_he_so += $he_so;
            }
            $tb_array[$student['id']] = $total_he_so ? round($tb / $total_he_so, 2) : 0;
        }
        arsort($tb_array);
        $rank = 1; $previous_tb = null; $ranks = []; $keys = array_keys($tb_array);
        foreach ($keys as $index => $id) {
            $tb = $tb_array[$id];
            if ($tb !== $previous_tb) { $ranks[$id] = $rank; $previous_tb = $tb; }
            else { $ranks[$id] = isset($ranks[$keys[$index - 1]]) ? $ranks[$keys[$index - 1]] : $rank; }
            $rank++;
        }
        foreach ($students as $student) {
            $sid = $student['id'];
            $g = isset($grades_data[$sid]) ? $grades_data[$sid] : [];
            $cols = ['15 phút 1','15 phút 2','1 tiết 1','1 tiết 2','Cuối kỳ 1','Cuối kỳ 2'];
            echo "<tr style='height:30px'>";
            echo "<td>" . htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($student['ho_ten']) . "</td>";
            echo "<td>" . ($student['ngay_sinh'] ? date('d/m/Y', strtotime($student['ngay_sinh'])) : 'N/A') . "</td>";
            foreach ($cols as $c) {
                $val = isset($g[$c]) ? $g[$c] : '';
                $cls = ($val !== '' && (float)$val < 5) ? ' class=\'low\'' : '';
                echo "<td$cls>" . ($val === '' ? '' : $val) . "</td>";
            }
            echo "<td>" . (isset($tb_array[$sid]) ? $tb_array[$sid] : '') . "</td>";
            echo "<td>" . (isset($ranks[$sid]) ? $ranks[$sid] : '') . "</td>";
            echo "</tr>";
        }
        echo "</table></body></html>";
        exit;
    }

    // Mặc định: CSV cho các loại khác
    header('Content-Type: text/csv; charset=utf-8');
    $filename = $type . '_' . $class['ten_lop'] . '_' . date('Ymd') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($type === 'diem_danh') {
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', 'Ngày', 'Trạng Thái', '% Nghỉ Học']);
        // Prefetch toàn bộ điểm danh của lớp một lần để xuất nhanh
        $attendance_by_student = [];
        $sql_all_att = "SELECT student_id, ngay_diem_danh, status FROM attendance WHERE class_id = $class_id ORDER BY ngay_diem_danh DESC";
        if ($res_all = $conn->query($sql_all_att)) {
            while ($row = $res_all->fetch_assoc()) {
                $attendance_by_student[(int)$row['student_id']][] = $row;
            }
        }
        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $absent_percent = !empty($total_sessions[$sid]['total']) ? round(($total_sessions[$sid]['absent'] / $total_sessions[$sid]['total']) * 100, 2) : 0;
            $records = isset($attendance_by_student[$sid]) ? $attendance_by_student[$sid] : [];
            if (!empty($records)) {
                foreach ($records as $row) {
                    fputcsv($output, [
                        isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A',
                        $student['ho_ten'],
                        $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A',
                        date("d/m/Y", strtotime($row['ngay_diem_danh'])),
                        $row['status'],
                        $absent_percent . '%'
                    ]);
                }
            } else {
                fputcsv($output, [
                    isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A',
                    $student['ho_ten'],
                    $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A',
                    'N/A',
                    'N/A',
                    $absent_percent . '%'
                ]);
            }
        }
    } elseif ($type === 'diem') {
        // Lấy điểm
        $sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
        $result_grades = $conn->query($sql_grades);
        $grades_data = [];
        if ($result_grades) {
            while ($row = $result_grades->fetch_assoc()) {
                $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
            }
        }
        // Tính TB + XH
        $he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
        $tb_array = [];
        foreach ($students as $student) {
            $grades = isset($grades_data[$student['id']]) ? $grades_data[$student['id']] : [];
            $tb = 0; $total_he_so = 0;
            foreach ($grades as $loai => $diem) {
                $he_so = isset($he_so_map[$loai]) ? $he_so_map[$loai] : 1;
                $tb += ((float)$diem) * $he_so;
                $total_he_so += $he_so;
            }
            $tb_array[$student['id']] = $total_he_so ? round($tb / $total_he_so, 2) : 0;
        }
        arsort($tb_array);
        $rank = 1; $previous_tb = null; $ranks = [];
        $keys = array_keys($tb_array);
        foreach ($keys as $index => $id) {
            $tb = $tb_array[$id];
            if ($tb !== $previous_tb) { $ranks[$id] = $rank; $previous_tb = $tb; }
            else { $ranks[$id] = isset($ranks[$keys[$index - 1]]) ? $ranks[$keys[$index - 1]] : $rank; }
            $rank++;
        }
        // Header giống giao diện web
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', '15 phút 1', '15 phút 2', '1 tiết 1', '1 tiết 2', 'Cuối kỳ 1', 'Cuối kỳ 2', 'Điểm TB', 'Xếp Hạng']);
        foreach ($students as $student) {
            $sid = $student['id'];
            $g = isset($grades_data[$sid]) ? $grades_data[$sid] : [];
            $row = [
                        isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A',
                        $student['ho_ten'],
                $student['ngay_sinh'] ? date('d/m/Y', strtotime($student['ngay_sinh'])) : 'N/A',
                isset($g['15 phút 1']) ? $g['15 phút 1'] : '',
                isset($g['15 phút 2']) ? $g['15 phút 2'] : '',
                isset($g['1 tiết 1']) ? $g['1 tiết 1'] : '',
                isset($g['1 tiết 2']) ? $g['1 tiết 2'] : '',
                isset($g['Cuối kỳ 1']) ? $g['Cuối kỳ 1'] : '',
                isset($g['Cuối kỳ 2']) ? $g['Cuối kỳ 2'] : '',
                isset($tb_array[$sid]) ? $tb_array[$sid] : '',
                isset($ranks[$sid]) ? $ranks[$sid] : ''
            ];
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

// Xuất Excel điểm danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_attendance'])) {
    exportExcel($conn, $class, $students, $attendance_data, $total_sessions, 'diem_danh', $class_id);
}

// Xuất Excel điểm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_grades'])) {
    exportExcel($conn, $class, $students, $attendance_data, $total_sessions, 'diem', $class_id);
}

// Xuất điểm danh theo khoảng (6 tháng / 1 năm) dạng ma trận mỗi học sinh 1 hàng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_attendance_range'])) {
    $anchor = isset($_POST['ngay_diem_danh']) && $_POST['ngay_diem_danh'] ? $_POST['ngay_diem_danh'] : date('Y-m-d');
    $range = $_POST['export_attendance_range'];
    $start = $anchor;
    if ($range === '6m') {
        $from = date('Y-m-d', strtotime('-6 months', strtotime($anchor)));
    } else {
        $from = date('Y-m-d', strtotime('-12 months', strtotime($anchor)));
    }

    // Lấy danh sách ngày điểm danh trong khoảng
    $dates = [];
    $sql_dates = "SELECT DISTINCT ngay_diem_danh FROM attendance WHERE class_id = $class_id AND ngay_diem_danh BETWEEN '$from' AND '$anchor' ORDER BY ngay_diem_danh DESC";
    if ($resd = $conn->query($sql_dates)) {
        while ($r = $resd->fetch_assoc()) { $dates[] = $r['ngay_diem_danh']; }
    }

    // Xuất HTML bảng giống Excel, mỗi học sinh 1 hàng, cột là các ngày trong khoảng
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    $safe_class = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $class['ten_lop']);
    $filename = 'Diem_danh_' . str_replace(' ','_',$safe_class) . '_' . date('Ymd', strtotime($from)) . '_' . date('Ymd', strtotime($anchor)) . '.xls';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<html><head><meta charset='utf-8'><style>
            table{border-collapse:collapse;width:100%;table-layout:fixed;}
            th,td{border:1px solid #bbb;padding:6px;text-align:center;height:28px}
            th{background:#f2f2f2;font-weight:600}
          </style></head><body>";
    echo "<table>";
    // colgroup: Tên Thánh, Họ Tên (tăng 30%), Ngày Sinh + N ngày + % Nghỉ Học (tăng 10%)
    $cols = 3 + count($dates) + 1;
    $base = 110; // cơ sở rộng cột
    echo "<colgroup>";
    echo "<col style='width:" . $base . "px'>"; // Tên Thánh
    echo "<col style='width:" . intval($base*1.3) . "px'>"; // Họ Tên +30%
    echo "<col style='width:" . $base . "px'>"; // Ngày Sinh
    foreach ($dates as $_) echo "<col style='width:" . intval($base*1.1) . "px'>"; // ngày +10%
    echo "<col style='width:" . intval($base*1.1) . "px'>"; // % Nghỉ Học +10%
    echo "</colgroup>";
    echo "<tr><th colspan='$cols' style='font-size:18px'>Bảng Điểm Danh Lớp " . htmlspecialchars($class['ten_lop']) . " (" . date('d/m/Y', strtotime($from)) . " - " . date('d/m/Y', strtotime($anchor)) . ")</th></tr>";
    echo "<tr><th>Tên Thánh</th><th>Họ Tên</th><th>Ngày Sinh</th>";
    foreach ($dates as $d) echo "<th>" . date('d/m', strtotime($d)) . "</th>";
    echo "<th>% Nghỉ Học</th></tr>";

    // Build map trạng thái trong khoảng để fill nhanh
    $student_ids = array_column($students, 'id');
    if (!empty($student_ids)) {
        $ids_list = implode(',', array_map('intval', $student_ids));
        $in_dates = implode(',', array_map(function($x){return "'".$x."'";}, $dates));
        $map = [];
        if (!empty($dates)) {
            $q = "SELECT student_id, ngay_diem_danh, status FROM attendance WHERE class_id = $class_id AND student_id IN ($ids_list) AND ngay_diem_danh IN ($in_dates)";
            if ($rs = $conn->query($q)) {
                while ($row = $rs->fetch_assoc()) {
                    $map[(int)$row['student_id']][$row['ngay_diem_danh']] = $row['status'];
                }
            }
        }
        foreach ($students as $st) {
            $sid = (int)$st['id'];
            echo "<tr>";
            echo "<td>" . htmlspecialchars(isset($st['ten_thanh']) ? $st['ten_thanh'] : 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($st['ho_ten']) . "</td>";
            echo "<td>" . ($st['ngay_sinh'] ? date('d/m/Y', strtotime($st['ngay_sinh'])) : 'N/A') . "</td>";
            $absent = 0; $total = 0;
            foreach ($dates as $d) {
                $val = isset($map[$sid][$d]) ? $map[$sid][$d] : '';
                if ($val !== '') { $total++; if ($val !== 'có') $absent++; }
                echo "<td>" . ($val === '' ? '' : htmlspecialchars($val)) . "</td>";
            }
            $percent = $total>0 ? round(($absent/$total)*100, 2) : 0;
            $pcCls = ($percent>40) ? " style='color:red;font-weight:600'" : "";
            echo "<td$pcCls>".$percent."%</td>";
            echo "</tr>";
        }
    }
    echo "</table></body></html>";
    exit;
}

// Hàm tính thời gian sinh hoạt
function tinh_thoi_gian_sinh_hoat($ngay_gia_nhap) {
    if (!$ngay_gia_nhap) return 'N/A';
    $start = new DateTime($ngay_gia_nhap);
    $now = new DateTime();
    $interval = $start->diff($now);
    return $interval->y . ' năm, ' . $interval->m . ' tháng';
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Chi Tiết Lớp <?php echo htmlspecialchars($class['ten_lop']); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* phần lớn style đã chuyển sang css/style.css; giữ lại vài kích thước cột cần thiết */
        .table td, .table th { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 120px; }
        .table th:first-child, .table td:first-child { width: 100px; }
        .table th:nth-child(2), .table td:nth-child(2) { width: 150px; }
        .table th:nth-child(3), .table td:nth-child(3) { width: 120px; }
        .table .attendance-col { width: 80px; }
        /* Bảng điểm danh: không cắt tên bằng ellipsis */
        .attendance-table td,
        .attendance-table th {
            overflow: visible;
            text-overflow: clip;
        }
        .attendance-table .sticky-col,
        .attendance-table .sticky-col-2 {
            white-space: normal;
            word-break: break-word;
            overflow: visible;
            text-overflow: clip;
        }
        #ngay_diem_danh { max-width: 180px; flex: 0 0 180px; }
        #activity_type { max-width: 200px; flex: 0 0 200px; }
        .attendance-table .attendance-note-col {
            min-width: 190px;
            width: 190px;
        }
        .attendance-note-input {
            font-size: .85rem;
            min-width: 170px;
        }
        .attendance-note-input:not(:placeholder-shown) {
            background: #fff8e1;
            border-color: #ffca28;
        }
        #sender_id { max-width: 320px; flex: 0 1 320px; }
        #attendanceControlsRow {
            flex-wrap: nowrap !important;
            align-items: center;
        }
        .table .grade-input { font-size: 0.9em; }
        .table .low-grade { color: red; }
        .accordion-button { font-weight: 600; }
        .btn-uniform { width: 200px; }
        .absent-high { color: red; font-weight: 600; }
        
        /* Màu sắc cho trạng thái điểm danh */
        .status-present { color: #198754; font-weight: 500; }
        .status-late { color: #0d6efd; font-weight: 600; }
        .status-excused { color: #fd7e14; font-weight: 600; }
        .status-absent { color: #dc3545; font-weight: 700; }

        /* Xác nhận điểm danh: thứ và ngày in đậm màu xanh dương */
        .attendance-confirm { padding: 0.75rem 1rem; background: #f0f7ff; border-radius: 8px; border-left: 4px solid #0d6efd; }
        .attendance-confirm .confirm-date { color: #0d6efd; font-weight: 700; }

        :root {
            --sticky-col-1: 60px;
            --sticky-col-2: 150px;
        }

        .mobile-scroll-hint {
            padding: 0.45rem 0.65rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #ced4da;
        }

        .attendance-scroll-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.06);
        }

        .attendance-table .attendance-status-select {
            min-width: 88px;
            font-size: 0.82rem;
            padding: 0.25rem 0.35rem;
        }

        /* Ghim Tên Thánh + Họ Tên khi vuốt/cuộn ngang (vị trí left do JS tính) */
        .table.pin-table {
            overflow: visible;
            border-collapse: separate;
            border-spacing: 0;
        }
        .pin-table .pin-col {
            position: sticky;
            left: 0;
            z-index: 2;
        }
        .pin-table thead .pin-col {
            z-index: 4;
        }
        .pin-table .pin-last {
            border-right: 2px solid rgba(0, 0, 0, 0.12) !important;
        }
        .pin-table .pin-check {
            width: 40px !important;
            min-width: 40px;
            text-align: center;
        }

        .toolbar-btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .attendance-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: stretch;
        }

        /* Responsive mobile */
        @media (max-width: 768px) {
            .class-detail-header {
                padding: 12px 0 !important;
            }
            .class-detail-header img {
                max-width: 52px !important;
                margin-bottom: 8px !important;
            }
            .class-detail-header h1 {
                font-size: 1.15rem !important;
                line-height: 1.35;
            }
            .class-detail-header .lead {
                font-size: 0.9rem !important;
            }

            .class-detail-main {
                margin-top: 0.5rem !important;
                margin-bottom: 0.5rem !important;
            }
            .class-detail-main > .card > .card-body {
                padding: 0.4rem !important;
            }
            .container {
                padding-left: 2px !important;
                padding-right: 2px !important;
                max-width: 100% !important;
            }
            .accordion-body {
                padding: 0.45rem !important;
            }
            .attendance-scroll-wrap {
                border-radius: 4px;
            }

            .card-title {
                font-size: 1.05rem !important;
            }

            /* Danh sách đoàn sinh: hiện đủ cột, 1 hàng, vuốt ngang trên mobile */
            .student-roster-table {
                font-size: 0.78rem !important;
                width: max-content;
                min-width: 100%;
            }
            .student-roster-table th,
            .student-roster-table td {
                white-space: nowrap !important;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: 0.78rem !important;
                padding: 0.4rem 0.45rem !important;
                vertical-align: middle;
            }
            .student-roster-table .btn-sm {
                padding: 0.25rem 0.4rem !important;
                white-space: nowrap;
            }
            .student-roster-wrap {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Bảng điểm danh: hiện đủ tên + cột điểm danh ngay trên màn hình */
            .attendance-table {
                font-size: 0.8rem !important;
                margin-bottom: 0;
                table-layout: auto !important;
                width: max-content;
                min-width: 100%;
            }
            .attendance-table th,
            .attendance-table td {
                padding: 0.4rem 0.35rem !important;
                vertical-align: middle;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                width: auto !important;
            }
            /* Ẩn ngày sinh trên dọc để nhường chỗ cho Họ Tên + Điểm danh */
            .attendance-table .col-hide-mobile {
                display: none !important;
            }
            .attendance-table .sticky-col {
                min-width: 58px !important;
                max-width: 64px !important;
                white-space: normal !important;
                word-break: break-word;
                line-height: 1.25;
                text-align: left !important;
                padding-left: 0.25rem !important;
                padding-right: 0.2rem !important;
            }
            .attendance-table .sticky-col-2 {
                min-width: 130px !important;
                max-width: 42vw !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-break: break-word;
                line-height: 1.3;
                font-weight: 600;
                text-align: left !important;
            }
            /* Danh sách đoàn sinh / HT / bảng điểm: cột ghim được xuống dòng, không chiếm quá nửa màn hình */
            .student-roster-table .pin-col,
            .attendance-table .pin-col {
                white-space: normal !important;
                word-break: break-word;
                overflow: visible !important;
                text-overflow: clip !important;
                text-align: left !important;
                line-height: 1.3;
            }
            .student-roster-table th.pin-col:not(.pin-check),
            .student-roster-table td.pin-col:not(.pin-check) {
                min-width: 64px;
                max-width: 34vw;
            }
            .student-roster-table .pin-col .badge {
                white-space: normal;
                text-align: left;
            }
            .grade-input {
                min-width: 62px;
                padding: 0.3rem 0.35rem !important;
            }
            /* Cột điểm danh chính (select) — luôn rộng đủ để chọn */
            .attendance-table th.attendance-col-main,
            .attendance-table td.attendance-col-main {
                min-width: 96px !important;
                width: 96px !important;
                white-space: nowrap !important;
            }
            .attendance-table .attendance-col {
                min-width: 68px !important;
                width: 68px !important;
                white-space: nowrap !important;
            }
            .attendance-table .attendance-status-select {
                min-width: 90px !important;
                width: 90px !important;
                font-size: 0.78rem !important;
                padding: 0.3rem 0.2rem !important;
            }

            /* Form điểm danh: xếp dọc trên mobile */
            #attendanceControlsRow {
                flex-direction: column !important;
                align-items: stretch !important;
                flex-wrap: wrap !important;
            }
            #attendanceControlsRow .form-control,
            #attendanceControlsRow .form-select {
                width: 100% !important;
                max-width: 100% !important;
                flex: none !important;
                font-size: 0.9rem !important;
                padding: 0.5rem 0.65rem !important;
            }

            /* Nút thao tác */
            .toolbar-btn-group .btn-uniform,
            .attendance-actions .btn-uniform {
                width: calc(50% - 0.25rem) !important;
                margin: 0 !important;
                font-size: 0.82rem !important;
                padding: 0.55rem 0.45rem !important;
                white-space: normal;
                line-height: 1.25;
            }
            .attendance-actions .btn-uniform:first-child {
                width: 100% !important;
            }
            .btn {
                font-size: 0.85rem !important;
            }

            .form-control, .form-select {
                font-size: 0.9rem !important;
            }

            .accordion-button {
                font-size: 0.92rem !important;
                padding: 0.55rem 0.5rem !important;
                line-height: 1.35;
            }
            .accordion-body {
                padding: 0.45rem !important;
            }

            .alert {
                font-size: 0.85rem !important;
                padding: 0.65rem !important;
            }
            .alert.d-flex {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.5rem !important;
            }
            .alert.d-flex .btn {
                width: 100%;
            }

            .modal-dialog {
                margin: 0.5rem !important;
                max-width: calc(100% - 1rem) !important;
            }

            footer {
                font-size: 0.85rem !important;
                padding: 1rem 0 !important;
            }
        }

        /* Xoay ngang: tối ưu không gian điểm danh */
        @media (max-width: 896px) and (orientation: landscape) {
            .class-detail-header {
                padding: 4px 0 6px !important;
                border-radius: 0 0 10px 10px !important;
            }
            .class-detail-header .container {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                flex-wrap: wrap;
            }
            .class-detail-header img {
                max-width: 32px !important;
                margin-bottom: 0 !important;
            }
            .class-detail-header h1 {
                font-size: 0.95rem !important;
                margin: 0 !important;
            }
            .class-detail-header .lead {
                display: none;
            }

            .class-detail-main {
                margin-top: 0.35rem !important;
                margin-bottom: 0.35rem !important;
            }
            .class-detail-main > .card > .card-body {
                padding: 0.5rem 0.65rem !important;
            }
            .class-detail-main .card-title {
                font-size: 0.95rem !important;
                margin-bottom: 0.5rem !important;
            }

            /* Thu gọn phần huynh trưởng */
            .teacher-section-wrap {
                display: none;
            }

            .accordion-button {
                font-size: 0.82rem !important;
                padding: 0.45rem 0.65rem !important;
            }
            .accordion-body {
                padding: 0.5rem !important;
            }

            #attendanceControlsRow {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                gap: 0.35rem !important;
                margin-bottom: 0.35rem !important;
            }
            #attendanceControlsRow .form-control,
            #attendanceControlsRow .form-select {
                flex: 1 1 0 !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                font-size: 0.78rem !important;
                padding: 0.3rem 0.4rem !important;
            }

            .mobile-scroll-hint {
                font-size: 0.72rem !important;
                padding: 0.3rem 0.5rem !important;
                margin-bottom: 0.35rem !important;
            }

            .attendance-scroll-wrap {
                max-height: calc(100dvh - 155px);
                overflow: auto;
            }

            .attendance-table {
                font-size: 0.72rem !important;
            }
            .attendance-table th,
            .attendance-table td {
                padding: 0.2rem 0.15rem !important;
            }
            .attendance-table .col-hide-landscape {
                display: none !important;
            }
            .attendance-table .sticky-col {
                min-width: 52px !important;
                max-width: 58px !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-break: break-word;
            }
            .attendance-table .sticky-col-2 {
                min-width: 130px !important;
                max-width: 42vw !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-break: break-word;
            }
            .attendance-table .attendance-col-main {
                min-width: 80px !important;
                width: 80px !important;
            }
            .attendance-table .attendance-col {
                min-width: 54px !important;
                width: 54px !important;
            }
            .attendance-table .attendance-status-select {
                min-width: 76px;
                width: 76px;
                font-size: 0.68rem !important;
                padding: 0.12rem 0.15rem !important;
            }

            .attendance-actions {
                gap: 0.3rem;
            }
            .attendance-actions .btn-uniform {
                width: auto !important;
                flex: 1 1 auto;
                min-width: 0;
                font-size: 0.72rem !important;
                padding: 0.35rem 0.45rem !important;
                margin: 0 !important;
            }
            .attendance-actions .btn-uniform:first-child {
                width: auto !important;
            }

            .toolbar-btn-group .btn-uniform {
                width: auto !important;
                flex: 1 1 calc(33% - 0.35rem);
                font-size: 0.72rem !important;
                padding: 0.35rem 0.4rem !important;
            }
        }

        /* Tablet */
        @media (min-width: 769px) and (max-width: 992px) {
            .btn-uniform {
                width: 180px !important;
            }
            table {
                font-size: 0.85rem !important;
            }
            th, td {
                padding: 0.4rem !important;
            }
        }
    </style>
</head>
<body class="<?php echo $theme_class; ?>">
    <header class="text-white text-center class-detail-header">
        <div class="container">
            <img src="img/logo.png" alt="Logo Đoàn" style="max-width: 90px; margin-bottom: 15px;">
            <h1>
                Chi Tiết Lớp <?php echo htmlspecialchars($class['ten_lop']); ?>
                <?php if ($is_hdt_manager): ?>
                    <span class="badge bg-success ms-2" style="font-size: 0.6em; vertical-align: middle;">
                        <i class="fa-solid fa-user-shield"></i> HDT Manager
                    </span>
                <?php endif; ?>
            </h1>
            <p class="lead mb-0">Ngành <?php echo htmlspecialchars($class['nganh']); ?></p>
        </div>
    </header>

    <main class="container my-5 class-detail-main">
        <div class="card">
            <div class="card-body p-4">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <?php if ($logged_in && $require_verification): ?>
                    <div class="mb-4">
                        <?php if ($is_hdt_manager): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <div>
                                    <i class="fa-solid fa-user-shield me-2"></i>
                                    <strong>Quản lý HDT / Admin:</strong> Bạn có toàn quyền chỉnh sửa, không cần mã xác nhận.
                                </div>
                            </div>
                        <?php elseif ($is_class_leader): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <div>
                                    <i class="fa-solid fa-chalkboard-teacher me-2"></i>
                                    <strong>Trưởng / Phó lớp:</strong> Bạn được chỉnh sửa lớp này mà không cần nhập mã xác nhận.
                                </div>
                            </div>
                        <?php elseif ($is_verified): ?>
                            <div class="alert alert-success d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="fa-solid fa-shield-check me-2"></i>
                                    <strong>Đã xác thực!</strong> Bạn có quyền chỉnh sửa cho đến khi đăng xuất
                                </div>
                                <a href="?id=<?php echo $class_id; ?>&logout_verification=1" class="btn btn-sm btn-outline-danger">
                                    <i class="fa-solid fa-sign-out-alt me-1"></i>Hủy xác thực
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="fa-solid fa-lock me-2"></i>
                                    <strong>Lớp được bảo vệ!</strong> Vui lòng nhập mã để chỉnh sửa, xóa dữ liệu.
                                </div>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#verifyClassModal">
                                    <i class="fa-solid fa-key me-1"></i>Nhập mã xác nhận
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="teacher-section-wrap">
                <h4 class="card-title"><i class="fa-solid fa-chalkboard-user me-2"></i>Huynh Trưởng Phụ Trách</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Tên Thánh</th>
                                <th>Họ Tên</th>
                                <th>Ngày Sinh</th>
                                <th>Thời Gian Sinh Hoạt</th>
                                <th>Số Điện Thoại</th>
                                <th>Email</th>
                                <th>Vai Trò</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_teachers->num_rows > 0): while ($teacher = $result_teachers->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(isset($teacher['ten_thanh']) ? $teacher['ten_thanh'] : 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['ho_ten']); ?></td>
                                    <td><?php echo $teacher['ngay_sinh'] ? date("d/m/Y", strtotime($teacher['ngay_sinh'])) : 'N/A'; ?></td>
                                    <td><?php echo tinh_thoi_gian_sinh_hoat($teacher['ngay_gia_nhap']); ?></td>
                                    <td><?php echo htmlspecialchars(isset($teacher['so_dien_thoai']) ? $teacher['so_dien_thoai'] : 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(isset($teacher['email']) ? $teacher['email'] : 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['vai_tro']); ?></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="7" class="text-center">Chưa có Huynh Trưởng.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>

                <div class="accordion" id="accordionPanelsStayOpenExample">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#panel1" aria-expanded="false">
                                <i class="fa-solid fa-children me-2"></i>Danh sách Đoàn Sinh (Sĩ số: <?php echo $si_so_class; ?>)
                            </button>
                        </h2>
                        <div id="panel1" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="mb-3 toolbar-btn-group">
                                    <?php if ($logged_in && $is_verified): ?>
                                        <button class="btn btn-success btn-uniform" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="fa-solid fa-plus me-2"></i>Thêm Đoàn Sinh</button>
                                        <button class="btn btn-warning btn-uniform" data-bs-toggle="modal" data-bs-target="#importStudentsModal" title="Nhập danh sách từ Excel (CSV)"><i class="fa-solid fa-file-import me-2"></i>Nhập CSV</button>
                                        <button class="btn btn-danger btn-uniform" id="deleteMultipleBtn" data-bs-toggle="modal" data-bs-target="#deleteMultipleStudentsModal" title="Xóa nhiều Đoàn Sinh đã chọn" style="display:none;"><i class="fa-solid fa-trash me-2"></i>Xóa Đã Chọn (<span id="selectedCount">0</span>)</button>
                                        <button class="btn btn-danger btn-uniform" data-bs-toggle="modal" data-bs-target="#deleteAllStudentsModal" title="Xóa toàn bộ danh sách lớp"><i class="fa-solid fa-trash-alt me-2"></i>Xóa Toàn Bộ</button>
                                    <?php endif; ?>
                                    <?php if (!empty($students)): ?>
                                        <a href="class_details.php?id=<?php echo $class_id; ?>&export_students=1<?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?>" class="btn btn-info btn-uniform" title="Tải danh sách CSV (mở được bằng Excel)"><i class="fa-solid fa-file-download me-2"></i>Tải Danh Sách</a>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <form method="GET" action="class_details.php">
                                        <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                                        <div class="input-group">
                                            <input type="text" name="search" class="form-control" placeholder="Tìm theo tên (kể cả đang tạm nghỉ)..." value="<?php echo htmlspecialchars($search_query); ?>">
                                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i></button>
                                        </div>
                                    </form>
                                </div>
                                <p class="mobile-scroll-hint d-lg-none mb-2"><i class="fa-solid fa-arrows-left-right me-1"></i>Vuốt ngang để xem đầy đủ thông tin đoàn sinh.</p>
                                <div class="table-responsive student-roster-wrap">
                                    <table class="table table-striped table-hover student-roster-table pin-table">
                                        <thead>
                                            <tr>
                                                <?php if ($logged_in && $is_verified): ?>
                                                    <th class="pin-col pin-check" style="width: 50px;">
                                                        <input type="checkbox" id="selectAllStudents" title="Chọn tất cả">
                                                    </th>
                                                <?php endif; ?>
                                                <th class="pin-col">Tên Thánh</th>
                                                <th class="pin-col">Họ Tên</th>
                                                <th>Ngày Sinh</th>
                                                <th>Giới Tính</th>
                                                <?php if ($logged_in): ?>
                                                    <th>Ngày Rửa Tội</th>
                                                    <th>Ngày Thêm Sức</th>
                                                    <th>Họ Tên Bố</th>
                                                    <th>Họ Tên Mẹ</th>
                                                    <th>SĐT Liên Lạc</th>
                                                    <th>Email Phụ Huynh</th>
                                                    <th>Địa Chỉ</th>
                                                    <th>Ghi Chú</th>
                                                    <th>Thao tác</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($display_students as $student): ?>
                                                <?php $is_on_leave = !empty($student['is_on_leave']); ?>
                                                <tr class="<?php echo $is_on_leave ? 'table-warning' : str_replace(' ', '-', $class['nganh']); ?>">
                                                    <?php if ($logged_in && $is_verified): ?>
                                                        <td class="pin-col pin-check">
                                                            <?php if (!$is_on_leave): ?>
                                                            <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['ho_ten']); ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td><?php echo htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A'); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($student['ho_ten']); ?>
                                                        <?php if ($is_on_leave): ?>
                                                            <span class="badge bg-warning text-dark ms-1">Tạm nghỉ <?php echo (int)$student['leave_days']; ?> ngày<?php echo !empty($student['leave_from']) ? ' (từ ' . date('d/m/Y', strtotime($student['leave_from'])) . ')' : ''; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                    <td><?php echo htmlspecialchars($student['gioi_tinh']); ?></td>
                                                    <?php if ($logged_in): ?>
                                                        <td><?php echo $student['ngay_rua_toi'] ? date("d/m/Y", strtotime($student['ngay_rua_toi'])) : 'N/A'; ?></td>
                                                        <td><?php echo $student['ngay_them_suc'] ? date("d/m/Y", strtotime($student['ngay_them_suc'])) : 'N/A'; ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['ho_ten_bo']) ? $student['ho_ten_bo'] : 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['ho_ten_me']) ? $student['ho_ten_me'] : 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['sdt_lien_lac']) ? $student['sdt_lien_lac'] : 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['email_phu_huynh']) ? $student['email_phu_huynh'] : 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['dia_chi']) ? $student['dia_chi'] : 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars(isset($student['ghi_chu']) ? $student['ghi_chu'] : 'N/A'); ?></td>
                                                        <?php if ($is_verified): ?>
                                                        <td>
                                                            <?php if ($is_on_leave): ?>
                                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#resumeStudentModal"
                                                                data-id="<?php echo (int)$student['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars(trim(($student['ten_thanh'] ? $student['ten_thanh'] . ' ' : '') . $student['ho_ten']), ENT_QUOTES); ?>"
                                                                data-days="<?php echo (int)$student['leave_days']; ?>"
                                                                data-from="<?php echo !empty($student['leave_from']) ? date('d/m/Y', strtotime($student['leave_from'])) : ''; ?>">
                                                                <i class="fa-solid fa-play"></i> Đi học lại
                                                            </button>
                                                            <?php else: ?>
                                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editStudentModal" onclick="loadStudent(<?php echo $student['id']; ?>, '<?php echo addslashes(htmlspecialchars($student['ho_ten'])); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : '')); ?>', '<?php echo isset($student['ngay_sinh']) ? $student['ngay_sinh'] : ''; ?>', '<?php echo htmlspecialchars($student['gioi_tinh']); ?>', '<?php echo isset($student['ngay_rua_toi']) ? $student['ngay_rua_toi'] : ''; ?>', '<?php echo isset($student['ngay_them_suc']) ? $student['ngay_them_suc'] : ''; ?>', '<?php echo addslashes(htmlspecialchars(isset($student['ho_ten_bo']) ? $student['ho_ten_bo'] : '')); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['ho_ten_me']) ? $student['ho_ten_me'] : '')); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['sdt_lien_lac']) ? $student['sdt_lien_lac'] : '')); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['email_phu_huynh']) ? $student['email_phu_huynh'] : '')); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['dia_chi']) ? $student['dia_chi'] : '')); ?>', '<?php echo addslashes(htmlspecialchars(isset($student['ghi_chu']) ? $student['ghi_chu'] : '')); ?>')"><i class="fa-solid fa-edit"></i></button>
                                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#leaveStudentModal"
                                                                data-id="<?php echo (int)$student['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars(trim(($student['ten_thanh'] ? $student['ten_thanh'] . ' ' : '') . $student['ho_ten']), ENT_QUOTES); ?>">
                                                                <i class="fa-solid fa-pause"></i>
                                                            </button>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                                <button type="submit" name="delete_student" class="btn btn-sm btn-danger" onclick="return confirm('Xác nhận xóa?')"><i class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                            <?php endif; ?>
                                                        </td>
                                                        <?php else: ?>
                                                        <td class="text-muted"><i class="fa-solid fa-lock"></i></td>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($display_students)): ?>
                                                <tr><td colspan="<?php echo $logged_in ? 14 : 4; ?>" class="text-center"><?php echo $search_query !== '' ? 'Không tìm thấy Đoàn Sinh.' : 'Chưa có Đoàn Sinh.'; ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Điểm Danh: cho phép xem khi chưa đăng nhập (chỉ xem, ẩn nút chức năng) -->
                    <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#panel2" aria-expanded="false">
                                    <i class="fa-solid fa-calendar-check me-2"></i>Điểm Danh Đoàn Sinh
                                </button>
                            </h2>
                            <div id="panel2" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <?php if ($logged_in && ($is_hdt_manager || $is_class_leader)): ?>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php if (getenv('FACE_ENABLED') === '1'): ?>
                                        <a href="diem_danh_face.php?class_id=<?php echo (int)$class_id; ?>&ngay=<?php echo urlencode($selected_attendance_date); ?>&activity=<?php echo urlencode($selected_activity); ?>" class="btn btn-primary btn-sm">
                                            Điểm danh khuôn mặt
                                        </a>
                                        <?php endif; ?>
                                        <a href="diem_danh_qr.php?class_id=<?php echo (int)$class_id; ?>&ngay=<?php echo urlencode($selected_attendance_date); ?>&activity=<?php echo urlencode($selected_activity); ?>" class="btn btn-success btn-sm">
                                            <i class="fa-solid fa-qrcode me-1"></i>Quét QR điểm danh
                                        </a>
                                        <a href="the_qr.php?class_id=<?php echo (int)$class_id; ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="fa-solid fa-id-card me-1"></i>Thẻ QR của lớp
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($logged_in): ?>
                                    <form method="POST" id="attendanceForm">
                                        <input type="hidden" name="submit_attendance" value="1">
                                        <div class="mb-3 d-flex align-items-center flex-wrap gap-2" id="attendanceControlsRow">
                                            <input type="date" name="ngay_diem_danh" id="ngay_diem_danh" class="form-control" required value="<?php echo htmlspecialchars($selected_attendance_date); ?>" onchange="loadAttendanceByDate()">
                                            <select name="activity_type" id="activity_type" class="form-select" onchange="loadAttendanceByDate()">
                                                <option value="Học Giáo Lý" <?php echo $selected_activity === 'Học Giáo Lý' ? 'selected' : ''; ?>>Học Giáo Lý</option>
                                                <option value="Đi lễ" <?php echo $selected_activity === 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
                                            </select>
                                            <select name="sender_id" id="sender_id" class="form-select">
                                                <?php if (empty($teachers_for_select)): ?>
                                                    <option value="0" data-name="Chưa có Huynh Trưởng">Chưa có Huynh Trưởng</option>
                                                <?php else: ?>
                                                    <?php foreach ($teachers_for_select as $t): ?>
                                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id'] === $default_sender_id) ? 'selected' : ''; ?> data-name="<?php echo htmlspecialchars($t['ho_ten']); ?>">
                                                            <?php echo htmlspecialchars($t['ho_ten']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <p class="mobile-scroll-hint d-lg-none mb-2"><i class="fa-solid fa-arrows-left-right me-1"></i>Họ tên hiển thị đủ. Vuốt ngang xem lịch sử. <strong>Xoay ngang</strong> để rộng hơn.</p>
                                        <div class="table-responsive attendance-scroll-wrap">
                                            <table class="table table-striped table-hover text-center attendance-table pin-table">
                                                <thead>
                                                    <tr>
                                                        <th class="sticky-col pin-col">Tên Thánh</th>
                                                        <th class="sticky-col-2 pin-col">Họ Tên</th>
                                                        <th class="col-hide-landscape col-hide-mobile">Ngày Sinh</th>
                                                        <th class="attendance-col attendance-col-main">Điểm Danh</th>
                                                        <th class="attendance-note-col">Ghi Chú</th>
                                                        <?php foreach ($attendance_dates as $date): ?>
                                                            <th class="attendance-col"><?php echo date('d/m', strtotime($date)); ?></th>
                                                        <?php endforeach; ?>
                                                        <?php echo cd_percent_header_html($selected_activity); ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $student): ?>
                                                        <?php
                                                            $student_display = trim((!empty($student['ten_thanh']) ? $student['ten_thanh'] . ' ' : '') . $student['ho_ten']);
                                                            $student_email = isset($student['email_phu_huynh']) ? trim($student['email_phu_huynh']) : '';
                                                        ?>
                                                        <tr>
                                                            <td class="sticky-col"><?php echo htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A'); ?></td>
                                                            <td class="sticky-col-2"><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                            <td class="col-hide-landscape col-hide-mobile"><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                            <td class="attendance-col attendance-col-main">
                                                                <?php $cur = isset($attendance_selected[$student['id']]) ? $attendance_selected[$student['id']] : 'có'; ?>
                                                                <?php
                                                                    $absent_base = isset($total_sessions[$student['id']]['absent']) ? (int)$total_sessions[$student['id']]['absent'] : 0;
                                                                    $saved_st = isset($attendance_selected[$student['id']]) ? $attendance_selected[$student['id']] : '';
                                                                    if ($selected_activity === 'Học Giáo Lý' && ($saved_st === 'vắng có phép' || $saved_st === 'vắng không phép')) {
                                                                        $absent_base = max(0, $absent_base - 1);
                                                                    }
                                                                ?>
                                                                <select name="status[<?php echo $student['id']; ?>]" class="form-select attendance-status-select"
                                                                    data-absent-base="<?php echo $absent_base; ?>"
                                                                    data-student-id="<?php echo (int)$student['id']; ?>"
                                                                    data-student-name="<?php echo htmlspecialchars($student_display, ENT_QUOTES); ?>"
                                                                    data-student-email="<?php echo htmlspecialchars($student_email, ENT_QUOTES); ?>">
                                                                    <option value="có" <?php echo $cur=='có'?'selected':''; ?>>Có</option>
                                                                    <option value="đi muộn" <?php echo $cur=='đi muộn'?'selected':''; ?>>Đi muộn</option>
                                                                    <option value="vắng có phép" <?php echo $cur=='vắng có phép'?'selected':''; ?>>Vắng Phép</option>
                                                                    <option value="vắng không phép" <?php echo $cur=='vắng không phép'?'selected':''; ?>>Vắng KP</option>
                                                                </select>
                                                            </td>
                                                            <td class="attendance-note-col">
                                                                <input type="text" name="note[<?php echo (int)$student['id']; ?>]" class="form-control form-control-sm attendance-note-input"
                                                                    maxlength="255" placeholder="VD: quên mang vở..."
                                                                    value="<?php echo htmlspecialchars(isset($attendance_notes[$student['id']]) ? $attendance_notes[$student['id']] : '', ENT_QUOTES); ?>">
                                                            </td>
                                                            <?php foreach ($attendance_dates as $date): ?>
                                                                <?php 
                                                                    $st = isset($attendance_data[$date][$student['id']]) ? $attendance_data[$date][$student['id']] : 'N/A';
                                                                    $statusClass = '';
                                                                    if ($st === 'vắng không phép') $statusClass = 'status-absent';
                                                                    elseif ($st === 'vắng có phép') $statusClass = 'status-excused';
                                                                    elseif ($st === 'đi muộn') $statusClass = 'status-late';
                                                                    elseif ($st === 'có') $statusClass = 'status-present';
                                                                ?>
                                                                <td class="attendance-col">
                                                                    <span class="<?php echo $statusClass; ?>">
                                                                        <?php echo htmlspecialchars(cd_status_short($st)); ?>
                                                                    </span>
                                                                </td>
                                                            <?php endforeach; ?>
                                                            <td class="attendance-col"><?php echo cd_percent_cell_html($selected_activity, $total_sessions, $mass_sessions, $student['id']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($students)): ?>
                                                        <tr><td colspan="<?php echo 8 + count($attendance_dates); ?>" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <!-- Nút Lưu điểm danh không cần mã xác nhận -->
                                        <div class="attendance-actions mt-3">
                                        <button type="submit" name="submit_attendance" class="btn btn-primary btn-uniform"><i class="fa-solid fa-save me-2"></i>Lưu Điểm Danh</button>
                                        <button type="button" class="btn btn-info btn-uniform" data-bs-toggle="modal" data-bs-target="#remindParentsModal" title="Xem nội dung copy hoặc chụp ảnh gửi nhóm phụ huynh" style="white-space: nowrap;"><i class="fa-solid fa-bell me-2"></i>Nhắc nhở Phụ Huynh</button>
                                        
                                        <!-- Các nút khác vẫn yêu cầu mã xác nhận -->
                                        <?php if ($is_verified): ?>
                                            <button type="button" id="openSendEmailModalBtn" class="btn btn-warning btn-uniform"><i class="fa-solid fa-envelope me-2"></i>Gửi Mail</button>
                                            <button type="button" name="export_attendance_6m" class="btn btn-theme btn-uniform"><i class="fa-solid fa-file-export me-2"></i>Xuất 6 tháng</button>
                                            <button type="button" name="export_attendance_1y" class="btn btn-theme btn-uniform"><i class="fa-solid fa-file-export me-2"></i>Xuất 1 năm</button>
                                            <button type="button" class="btn btn-danger btn-uniform" data-bs-toggle="modal" data-bs-target="#deleteAttendanceModal"><i class="fa-solid fa-trash me-2"></i>Xóa Điểm Danh</button>
                                        <?php else: ?>
                                            <div class="alert alert-info w-100 mb-0">
                                                <i class="fa-solid fa-info-circle me-2"></i>Để sử dụng các chức năng Gửi Mail, Xuất file và Xóa điểm danh, vui lòng nhập mã xác nhận
                                            </div>
                                        <?php endif; ?>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                    <!-- Khách không đăng nhập: chỉ xem các ngày đã điểm danh -->
                                    <form method="GET" class="mb-3">
                                        <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                                        <div class="d-flex align-items-center flex-wrap gap-2">
                                            <select name="activity" class="form-select" style="max-width: 180px;" onchange="this.form.submit()">
                                                <option value="Học Giáo Lý" <?php echo $selected_activity === 'Học Giáo Lý' ? 'selected' : ''; ?>>Học Giáo Lý</option>
                                                <option value="Đi lễ" <?php echo $selected_activity === 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
                                            </select>
                                        </div>
                                    </form>
                                    <?php if (empty($attendance_dates)): ?>
                                    <p class="text-center text-muted py-3">Chưa có điểm danh cho loại hoạt động này.</p>
                                    <?php else: ?>
                                    <p class="mobile-scroll-hint d-lg-none mb-2"><i class="fa-solid fa-arrows-left-right me-1"></i>Họ tên hiển thị đủ. Vuốt ngang xem thêm cột điểm danh.</p>
                                    <div class="table-responsive attendance-scroll-wrap">
                                        <table class="table table-striped table-hover text-center attendance-table pin-table">
                                            <thead>
                                                <tr>
                                                    <th class="sticky-col pin-col">Tên Thánh</th>
                                                    <th class="sticky-col-2 pin-col">Họ Tên</th>
                                                    <th class="col-hide-landscape col-hide-mobile">Ngày Sinh</th>
                                                    <?php foreach ($attendance_dates as $date): ?>
                                                        <th class="attendance-col"><?php echo date('d/m', strtotime($date)); ?></th>
                                                    <?php endforeach; ?>
                                                    <?php echo cd_percent_header_html($selected_activity); ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($students as $student): ?>
                                                    <tr>
                                                        <td class="sticky-col"><?php echo htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A'); ?></td>
                                                        <td class="sticky-col-2"><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                        <td class="col-hide-landscape col-hide-mobile"><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                        <?php foreach ($attendance_dates as $date): ?>
                                                            <?php 
                                                                $st = isset($attendance_data[$date][$student['id']]) ? $attendance_data[$date][$student['id']] : 'N/A';
                                                                $statusClass = '';
                                                                if ($st === 'vắng không phép') $statusClass = 'status-absent';
                                                                elseif ($st === 'vắng có phép') $statusClass = 'status-excused';
                                                                elseif ($st === 'đi muộn') $statusClass = 'status-late';
                                                                elseif ($st === 'có') $statusClass = 'status-present';
                                                            ?>
                                                            <td class="attendance-col">
                                                                <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars(cd_status_short($st)); ?></span>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td class="attendance-col"><?php echo cd_percent_cell_html($selected_activity, $total_sessions, $mass_sessions, $student['id']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($students)): ?>
                                                    <tr><td colspan="<?php echo 4 + count($attendance_dates); ?>" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <p class="text-muted small mt-2 mb-0"><i class="fa-solid fa-info-circle me-1"></i>Bạn đang xem ở chế độ công khai. <a href="<?php echo htmlspecialchars(login_page_url()); ?>">Đăng nhập</a> để chỉnh sửa điểm danh.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    <?php if ($logged_in): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#panel3" aria-expanded="false">
                                    <i class="fa-solid fa-star me-2"></i>Điểm Đoàn Sinh
                                </button>
                            </h2>
                            <div id="panel3" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <form method="POST" id="gradesForm">
                                        <input type="hidden" name="submit_grades" value="1">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover text-center attendance-table pin-table">
                                                <thead>
                                                    <tr>
                                                        <th class="pin-col">Tên Thánh</th>
                                                        <th class="pin-col">Họ Tên</th>
                                                        <th>Ngày Sinh</th>
                                                        <th>15 phút 1</th>
                                                        <th>15 phút 2</th>
                                                        <th>1 tiết 1</th>
                                                        <th>1 tiết 2</th>
                                                        <th>Cuối kỳ 1</th>
                                                        <th>Cuối kỳ 2</th>
                                                        <th>Điểm TB</th>
                                                        <th>Xếp Hạng</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $student): ?>
                                                        <tr data-student-id="<?php echo (int)$student['id']; ?>">
                                                            <td><?php echo htmlspecialchars(isset($student['ten_thanh']) ? $student['ten_thanh'] : 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                            <td><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                            <?php foreach ($grade_columns as $col): ?>
                                                            <td><input type="number" step="<?php echo $grade_step; ?>" min="<?php echo $grade_min; ?>" max="<?php echo $grade_max; ?>" name="diem[<?php echo $student['id']; ?>][<?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo grade_display_value($grades_data, $student['id'], $col); ?>" class="form-control grade-input <?php echo grade_is_low($grades_data, $student['id'], $col) ? 'low-grade' : ''; ?>" onfocus="this.select()" <?php echo $is_verified ? '' : 'disabled'; ?>></td>
                                                            <?php endforeach; ?>
                                                            <td class="grade-tb-cell"><?php echo isset($tb_display[$student['id']]) ? $tb_display[$student['id']] : 'N/A'; ?></td>
                                                            <td class="grade-rank-cell"><?php echo isset($ranks_all[$student['id']]) ? $ranks_all[$student['id']] : 'N/A'; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($students)): ?>
                                                        <tr><td colspan="11" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if ($is_verified): ?>
                                            <button type="submit" name="submit_grades" class="btn btn-primary btn-uniform mt-3"><i class="fa-solid fa-save me-2"></i>Lưu Điểm</button>
                                            <button type="submit" name="export_grades" class="btn btn-theme btn-uniform mt-3 ms-2"><i class="fa-solid fa-file-export me-2"></i>Xuất Excel</button>
                                        <?php else: ?>
                                            <div class="alert alert-warning mt-3">
                                                <i class="fa-solid fa-lock me-2"></i>Vui lòng nhập mã xác nhận để chấm điểm
                                            </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <a href="classes.php" class="btn btn-theme mt-3"><i class="fa-solid fa-arrow-left me-2"></i>Quay Lại Danh Sách Lớp</a>
            </div>
        </div>
    </main>

    <footer class="text-white text-center">
        <div class="container">
            <p class="mb-2">ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</p>
            <p>
                <a href="mailto:tntt.laptri@gmail.com" class="mx-2"><i class="fa-solid fa-envelope fa-lg"></i></a>
                <a href="https://facebook.com/tntt.laptri" class="mx-2"><i class="fa-brands fa-facebook fa-lg"></i></a>
            </p>
        </div>
    </footer>

    <?php if ($logged_in): ?>
    <!-- Modal Thêm Đoàn Sinh -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Đoàn Sinh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addStudentForm">
                        <input type="hidden" name="add_student" value="1">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Rửa Tội</label>
                                <input type="date" name="ngay_rua_toi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Thêm Sức</label>
                                <input type="date" name="ngay_them_suc" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Gia Nhập</label>
                                <input type="date" name="ngay_gia_nhap" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Bố</label>
                                <input type="text" name="ho_ten_bo" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Mẹ</label>
                                <input type="text" name="ho_ten_me" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SĐT Liên Lạc</label>
                                <input type="text" name="sdt_lien_lac" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Phụ Huynh</label>
                                <input type="email" name="email_phu_huynh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ghi Chú</label>
                                <input type="text" name="ghi_chu" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Thêm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Xác nhận điểm danh (trước khi lưu) -->
    <div class="modal fade" id="confirmAttendanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-calendar-check me-2"></i>Xác nhận điểm danh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Bạn xác nhận lưu điểm danh với thông tin sau:</p>
                    <p class="mb-1">Người điểm danh: <strong id="confirmModalSenderName"></strong></p>
                    <p class="mb-0">Ngày điểm danh: <span id="confirmModalDateDisplay" class="confirm-date fw-bold"></span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-primary" id="confirmAttendanceSubmitBtn"><i class="fa-solid fa-check me-1"></i>Xác nhận</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Kết quả điểm danh (thành công / không thành công) -->
    <div class="modal fade" id="attendanceResultModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="attendanceResultModalHeader">
                    <h5 class="modal-title" id="attendanceResultModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="attendanceResultModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Kết quả lưu điểm -->
    <div class="modal fade" id="gradesResultModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="gradesResultModalHeader">
                    <h5 class="modal-title" id="gradesResultModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="gradesResultModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="gradesResultModalCloseBtn" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nhắc nhở Phụ Huynh -->
    <div class="modal fade" id="remindParentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-bell me-2"></i>Nhắc nhở Phụ Huynh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">Nội dung dưới đây để sao chép hoặc chụp ảnh gửi lên nhóm phụ huynh (ngày đang xem: <span id="remindParentsHeader"><?php echo htmlspecialchars($reminder_header); ?></span>).</p>
                    <div class="border rounded p-3 bg-light">
                        <textarea id="remindParentsContent" class="form-control border-0 bg-transparent font-monospace" rows="<?php echo max(8, substr_count($reminder_full_text, "\n") + 2); ?>" readonly><?php echo htmlspecialchars($reminder_full_text); ?></textarea>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="copyRemindParentsBtn"><i class="fa-solid fa-copy me-1"></i>Sao chép</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($logged_in && $is_verified): ?>
    <!-- Modal chọn email gửi thông báo -->
    <div class="modal fade" id="sendEmailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fa-solid fa-envelope me-2"></i>Chọn email gửi thông báo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Chỉ hiển thị đoàn sinh đang <strong>vắng có phép</strong> hoặc <strong>vắng không phép</strong> theo trạng thái điểm danh hiện tại.</p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllEmailRecipients"><i class="fa-solid fa-check-double me-1"></i>Chọn tất cả</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllEmailRecipients"><i class="fa-solid fa-xmark me-1"></i>Bỏ chọn tất cả</button>
                    </div>
                    <div id="sendEmailRecipientsList" class="list-group mb-2"></div>
                    <p id="sendEmailEmptyMsg" class="text-muted small mb-0" style="display:none;">Không có đoàn sinh vắng hoặc chưa có email phụ huynh.</p>
                    <div id="sendEmailRecipientsInputs"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-warning" id="confirmSendEmailBtn"><i class="fa-solid fa-paper-plane me-2"></i>Gửi mail đã chọn</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Xóa Điểm Danh Theo Ngày -->
    <div class="modal fade" id="deleteAttendanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-trash me-2"></i>Xóa Điểm Danh Theo Ngày</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="deleteAttendanceForm">
                        <input type="hidden" name="delete_attendance_by_date" value="1">
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-exclamation-triangle me-2"></i>
                            <strong>Cảnh báo:</strong> Thao tác này sẽ xóa toàn bộ điểm danh của lớp trong ngày đã chọn và không thể hoàn tác!
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Chọn ngày cần xóa điểm danh:</strong></label>
                            <input type="date" name="ngay_xoa" id="ngayXoaInput" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Loại hoạt động:</strong></label>
                            <select name="activity_xoa" class="form-control">
                                <option value="">Tất cả (Học Giáo Lý + Đi lễ)</option>
                                <option value="Học Giáo Lý">Chỉ Học Giáo Lý</option>
                                <option value="Đi lễ">Chỉ Đi lễ</option>
                            </select>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa-solid fa-trash me-2"></i>Xóa Điểm Danh
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Xác Thực Mã Lớp -->
    <div class="modal fade" id="verifyClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-key me-2"></i>Xác Thực Mã Lớp</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="verify_class_code" value="1">
                        <div class="mb-3">
                            <label class="form-label">Nhập mã xác nhận của lớp <strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong></label>
                            <input type="password" name="verification_code" class="form-control form-control-lg text-center" 
                                   placeholder="Nhập mã xác nhận" required autofocus maxlength="20"
                                   style="letter-spacing: 0.3em; font-size: 1.5rem;">
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="fa-solid fa-info-circle me-2"></i>
                            Sau khi xác thực thành công, bạn có quyền chỉnh sửa, xóa dữ liệu <strong>cho đến khi đăng xuất</strong>.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check me-2"></i>Xác nhận
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Import CSV -->
    <div class="modal fade" id="importStudentsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nhập Đoàn Sinh từ Excel (CSV)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="import_students_csv" value="1">
                        <div class="mb-3">
                            <label class="form-label">Chọn tệp CSV (UTF-8)</label>
                            <input type="file" name="students_file" accept=".csv,text/csv" class="form-control" required>
                        </div>
                        <div class="alert alert-info">
                            Lưu ý: Hãy lưu file Excel thành CSV (UTF-8). Thứ tự cột:
                            <br>1) Tên Thánh, 2) Họ Tên, 3) Ngày Sinh, 4) Giới Tính, 5) Ngày Rửa Tội, 6) Ngày Thêm Sức, 7) Ngày Gia Nhập, 8) Họ Tên Bố, 9) Họ Tên Mẹ, 10) SĐT Liên Lạc, 11) Email Phụ Huynh, 12) Địa Chỉ, 13) Ghi Chú
                            <br>Định dạng ngày chấp nhận: d/m/Y hoặc Y-m-d.
                        </div>
                        <button type="submit" class="btn btn-primary">Nhập</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Xóa Nhiều Đoàn Sinh -->
    <div class="modal fade" id="deleteMultipleStudentsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fa-solid fa-exclamation-triangle me-2"></i>Xác Nhận Xóa Nhiều Đoàn Sinh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <p class="mb-2"><strong>Bạn đang xóa <span id="deleteCount">0</span> Đoàn Sinh</strong> trong lớp <strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong></p>
                        <p class="mb-0">Hành động này sẽ xóa:</p>
                        <ul>
                            <li>Thông tin của các Đoàn Sinh đã chọn</li>
                            <li>Tất cả bản ghi điểm danh của các em</li>
                            <li>Tất cả điểm số của các em</li>
                        </ul>
                        <p class="text-danger fw-bold mb-0"><i class="fa-solid fa-ban me-1"></i>Không thể khôi phục sau khi xóa!</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Danh sách Đoàn Sinh sẽ xóa:</label>
                        <div id="selectedStudentsList" class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                            <!-- Danh sách sẽ được điền bằng JavaScript -->
                        </div>
                    </div>
                    <form method="POST" id="deleteMultipleStudentsForm">
                        <input type="hidden" name="delete_multiple_students" value="1">
                        <div id="selectedStudentsInputs">
                            <!-- Các input hidden sẽ được thêm bằng JavaScript -->
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fa-solid fa-times me-2"></i>Hủy</button>
                            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-2"></i>Xác Nhận Xóa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Xóa Toàn Bộ Danh Sách -->
    <div class="modal fade" id="deleteAllStudentsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-exclamation-triangle me-2"></i>Xác Nhận Xóa Toàn Bộ Danh Sách</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong><i class="fa-solid fa-warning me-2"></i>CẢNH BÁO:</strong>
                        <p class="mb-2">Bạn đang thực hiện xóa toàn bộ danh sách lớp <strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong>!</p>
                        <p class="mb-0">Hành động này sẽ:</p>
                        <ul>
                            <li>Xóa tất cả <strong><?php echo $result_students->num_rows; ?> Đoàn Sinh</strong> trong lớp</li>
                            <li>Xóa tất cả <strong>bản ghi điểm danh</strong> của lớp</li>
                            <li>Xóa tất cả <strong>điểm số</strong> của lớp</li>
                        </ul>
                        <p class="text-danger fw-bold mb-0"><i class="fa-solid fa-ban me-1"></i>KHÔNG THỂ KHÔI PHỤC sau khi xóa!</p>
                    </div>
                    <form method="POST" id="deleteAllStudentsForm">
                        <input type="hidden" name="delete_all_students" value="1">
                        <div class="mb-3">
                            <label class="form-label">Để xác nhận, vui lòng nhập tên lớp: <strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong></label>
                            <input type="text" class="form-control" id="confirm_class_name" placeholder="Nhập tên lớp để xác nhận" required>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fa-solid fa-times me-2"></i>Hủy</button>
                            <button type="submit" class="btn btn-danger" id="confirmDeleteAllBtn" disabled><i class="fa-solid fa-trash-alt me-2"></i>Xóa Toàn Bộ</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Chi Tiết Đoàn Sinh -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-user me-2"></i>Chi Tiết Đoàn Sinh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editStudentForm">
                        <input type="hidden" name="edit_student" value="1">
                        <input type="hidden" name="student_id" id="edit_student_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" id="edit_ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" id="edit_ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" id="edit_ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" id="edit_gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Rửa Tội</label>
                                <input type="date" name="ngay_rua_toi" id="edit_ngay_rua_toi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Thêm Sức</label>
                                <input type="date" name="ngay_them_suc" id="edit_ngay_them_suc" class="form-control">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Bố</label>
                                <input type="text" name="ho_ten_bo" id="edit_ho_ten_bo" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Mẹ</label>
                                <input type="text" name="ho_ten_me" id="edit_ho_ten_me" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SĐT Liên Lạc</label>
                                <input type="text" name="sdt_lien_lac" id="edit_sdt_lien_lac" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Phụ Huynh</label>
                                <input type="email" name="email_phu_huynh" id="edit_email_phu_huynh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" id="edit_dia_chi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ghi Chú</label>
                                <input type="text" name="ghi_chu" id="edit_ghi_chu" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-2"></i>Lưu thông tin</button>
                    </form>

                    <?php if (!empty($all_classes)): ?>
                    <hr class="my-4">
                    <h6 class="mb-3"><i class="fa-solid fa-right-left me-2"></i>Chuyển lớp</h6>
                    <p class="text-muted small mb-3">Chuyển đoàn sinh <strong id="transfer_student_name"></strong> sang lớp khác. Lịch sử điểm danh và điểm số tại lớp hiện tại vẫn được giữ nguyên.</p>
                    <form method="POST" id="transferStudentForm" onsubmit="return confirm('Xác nhận chuyển đoàn sinh sang lớp đã chọn?');">
                        <input type="hidden" name="transfer_student" value="1">
                        <input type="hidden" name="student_id" id="transfer_student_id">
                        <div class="row align-items-end">
                            <div class="col-md-8 mb-3 mb-md-0">
                                <label class="form-label">Lớp đích</label>
                                <select name="new_class_id" id="transfer_new_class_id" class="form-select" required>
                                    <option value="">-- Chọn lớp --</option>
                                    <?php foreach ($all_classes as $cls): ?>
                                        <option value="<?php echo (int)$cls['id']; ?>"><?php echo htmlspecialchars($cls['ten_lop'] . ' (' . $cls['nganh'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-warning w-100"><i class="fa-solid fa-right-left me-2"></i>Chuyển lớp</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($logged_in && $is_verified): ?>
    <!-- Modal tạm nghỉ học -->
    <div class="modal fade" id="leaveStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" onsubmit="return confirm('Xác nhận tạm nghỉ học? Đoàn sinh sẽ không còn trong danh sách lớp cho đến khi bật lại.');">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title"><i class="fa-solid fa-pause me-2"></i>Tạm nghỉ học</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="leave_student" value="1">
                        <input type="hidden" name="student_id" id="leave_student_id">
                        <p>Đoàn sinh: <strong id="leave_student_name"></strong></p>
                        <p class="text-muted small">Từ ngày tạm nghỉ, đoàn sinh sẽ ẩn khỏi mọi danh sách lớp. Vẫn tìm được bằng tên để bật lại.</p>
                        <div class="mb-3">
                            <label class="form-label">Ngày bắt đầu tạm nghỉ</label>
                            <input type="date" name="leave_from" id="leave_from" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-warning"><i class="fa-solid fa-pause me-2"></i>Xác nhận tạm nghỉ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal đi học lại -->
    <div class="modal fade" id="resumeStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" onsubmit="return confirm('Xác nhận bật lại và xếp vào lớp đã chọn?');">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fa-solid fa-play me-2"></i>Đi học lại</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="resume_student" value="1">
                        <input type="hidden" name="student_id" id="resume_student_id">
                        <p>Đoàn sinh: <strong id="resume_student_name"></strong></p>
                        <p class="mb-3">Đã tạm nghỉ: <strong id="resume_leave_days">0</strong> ngày <span id="resume_leave_from_wrap" class="text-muted small"></span></p>
                        <div class="mb-3">
                            <label class="form-label">Chọn lớp</label>
                            <select name="new_class_id" id="resume_new_class_id" class="form-select" required>
                                <option value="">-- Chọn lớp --</option>
                                <?php foreach ($classes_for_resume as $cls): ?>
                                    <option value="<?php echo (int)$cls['id']; ?>" <?php echo ((int)$cls['id'] === $class_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cls['ten_lop'] . ' (' . $cls['nganh'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-success"><i class="fa-solid fa-play me-2"></i>Bật lại</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        (function () {
            var params = new URLSearchParams(window.location.search);
            if (params.get('panel') === 'diemdanh') {
                var panel = document.getElementById('panel2');
                if (panel) {
                    panel.classList.add('show');
                    var btn = document.querySelector('[data-bs-target="#panel2"]');
                    if (btn) {
                        btn.classList.remove('collapsed');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                    setTimeout(function () {
                        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 150);
                }
            }
        })();
    </script>
    <script>
        // Function để reload trang với ngày và loại hoạt động mới
        function loadAttendanceByDate() {
            const date = document.getElementById('ngay_diem_danh').value;
            const activity = document.getElementById('activity_type').value;
            const classId = <?php echo $class_id; ?>;
            window.location.href = `class_details.php?id=${classId}&ngay_diem_danh=${date}&activity=${encodeURIComponent(activity)}`;
        }
    </script>
    <script>
        function isValidPhone(value) {
            return /^\d{10}$/.test(value);
        }

        function attachPhoneValidation(formSelector, inputSelector) {
            var $form = $(formSelector);
            if ($form.length === 0) return;
            $form.on('submit', function(e) {
                var phone = $(inputSelector).val().trim();
                if (phone !== '' && !isValidPhone(phone)) {
                    e.preventDefault();
                    alert('SĐT Liên Lạc phải gồm đúng 10 số. Không thể lưu.');
                    return false;
                }
                return true;
            });
        }

        function loadStudent(id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu) {
            var displayName = (ten_thanh ? ten_thanh + ' ' : '') + ho_ten;
            document.getElementById('edit_student_id').value = id;
            var transferIdEl = document.getElementById('transfer_student_id');
            var transferNameEl = document.getElementById('transfer_student_name');
            var transferClassEl = document.getElementById('transfer_new_class_id');
            if (transferIdEl) transferIdEl.value = id;
            if (transferNameEl) transferNameEl.textContent = displayName;
            if (transferClassEl) transferClassEl.value = '';
            document.getElementById('edit_ho_ten').value = ho_ten;
            document.getElementById('edit_ten_thanh').value = ten_thanh || '';
            document.getElementById('edit_ngay_sinh').value = ngay_sinh || '';
            document.getElementById('edit_gioi_tinh').value = gioi_tinh || '';
            document.getElementById('edit_ngay_rua_toi').value = ngay_rua_toi || '';
            document.getElementById('edit_ngay_them_suc').value = ngay_them_suc || '';
            document.getElementById('edit_ho_ten_bo').value = ho_ten_bo || '';
            document.getElementById('edit_ho_ten_me').value = ho_ten_me || '';
            document.getElementById('edit_sdt_lien_lac').value = sdt_lien_lac || '';
            document.getElementById('edit_email_phu_huynh').value = email_phu_huynh || '';
            document.getElementById('edit_dia_chi').value = dia_chi || '';
            document.getElementById('edit_ghi_chu').value = ghi_chu || '';
        }

        $(document).ready(function() {
            attachPhoneValidation('#addStudentForm', "input[name='sdt_lien_lac']");
            attachPhoneValidation('#editStudentForm', '#edit_sdt_lien_lac');

            $('#leaveStudentModal').on('show.bs.modal', function(event) {
                var btn = $(event.relatedTarget);
                $('#leave_student_id').val(btn.data('id'));
                $('#leave_student_name').text(btn.data('name'));
                $('#leave_from').val(new Date().toISOString().slice(0, 10));
            });

            $('#resumeStudentModal').on('show.bs.modal', function(event) {
                var btn = $(event.relatedTarget);
                $('#resume_student_id').val(btn.data('id'));
                $('#resume_student_name').text(btn.data('name'));
                $('#resume_leave_days').text(btn.data('days') || 0);
                var from = btn.data('from');
                if (from) {
                    $('#resume_leave_from_wrap').text('(từ ' + from + ')');
                } else {
                    $('#resume_leave_from_wrap').text('');
                }
            });
            
            // Xử lý checkbox chọn nhiều Đoàn Sinh
            function updateSelectedCount() {
                const selectedCheckboxes = $('.student-checkbox:checked');
                const count = selectedCheckboxes.length;
                $('#selectedCount').text(count);
                
                if (count > 0) {
                    $('#deleteMultipleBtn').show();
                } else {
                    $('#deleteMultipleBtn').hide();
                }
            }
            
            // Checkbox chọn tất cả
            $('#selectAllStudents').on('change', function() {
                $('.student-checkbox').prop('checked', this.checked);
                updateSelectedCount();
            });
            
            // Checkbox từng Đoàn Sinh
            $(document).on('change', '.student-checkbox', function() {
                const totalCheckboxes = $('.student-checkbox').length;
                const checkedCheckboxes = $('.student-checkbox:checked').length;
                $('#selectAllStudents').prop('checked', totalCheckboxes === checkedCheckboxes);
                updateSelectedCount();
            });
            
            // Khi mở modal xóa nhiều, hiển thị danh sách
            $('#deleteMultipleStudentsModal').on('show.bs.modal', function() {
                const selectedCheckboxes = $('.student-checkbox:checked');
                const count = selectedCheckboxes.length;
                $('#deleteCount').text(count);
                
                let listHtml = '<ul class="mb-0">';
                let inputsHtml = '';
                
                selectedCheckboxes.each(function() {
                    const id = $(this).val();
                    const name = $(this).data('name');
                    listHtml += '<li>' + name + '</li>';
                    inputsHtml += '<input type="hidden" name="selected_students[]" value="' + id + '">';
                });
                
                listHtml += '</ul>';
                $('#selectedStudentsList').html(listHtml);
                $('#selectedStudentsInputs').html(inputsHtml);
            });
            
            // Reset checkbox khi đóng modal
            $('#deleteMultipleStudentsModal').on('hidden.bs.modal', function() {
                $('.student-checkbox').prop('checked', false);
                $('#selectAllStudents').prop('checked', false);
                updateSelectedCount();
            });
            
            // Xác nhận xóa toàn bộ danh sách
            $('#confirm_class_name').on('input', function() {
                const inputValue = $(this).val();
                const className = '<?php echo addslashes($class['ten_lop']); ?>';
                if (inputValue === className) {
                    $('#confirmDeleteAllBtn').prop('disabled', false);
                } else {
                    $('#confirmDeleteAllBtn').prop('disabled', true);
                }
            });
            
            // Xử lý submit form xóa toàn bộ
            $('#deleteAllStudentsForm').on('submit', function(e) {
                const inputValue = $('#confirm_class_name').val();
                const className = '<?php echo addslashes($class['ten_lop']); ?>';
                if (inputValue !== className) {
                    e.preventDefault();
                    alert('Tên lớp không khớp. Vui lòng nhập chính xác.');
                    return false;
                }
                return confirm('Bạn có chắc chắn muốn xóa toàn bộ danh sách lớp này? Hành động này KHÔNG THỂ KHÔI PHỤC!');
            });
            
            // Reset form khi đóng modal
            $('#deleteAllStudentsModal').on('hidden.bs.modal', function () {
                $('#confirm_class_name').val('');
                $('#confirmDeleteAllBtn').prop('disabled', true);
            });
            
            // Nâng cấp: tải CSV qua fetch để hiện popup thành công/thất bại
            async function postAndDownloadCsv(postData, suggestedName) {
                try {
                    const url = 'class_details.php?id=<?php echo $class_id; ?>';
                    const resp = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams(postData).toString()
                    });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const contentType = (resp.headers.get('Content-Type') || '').toLowerCase();
                    const isCsv = contentType.includes('text/csv');
                    const isXls = contentType.includes('application/vnd.ms-excel') || contentType.includes('text/html');
                    if (!(isCsv || isXls)) {
                        const text = await resp.text();
                        throw new Error('Phản hồi không phải CSV/XLS. ' + text.slice(0, 200));
                    }
                    const cd = resp.headers.get('Content-Disposition') || '';
                    let filename = suggestedName || 'export.csv';
                    const match = cd.match(/filename="?([^";]+)"?/i);
                    if (match && match[1]) filename = match[1];
                    const blob = await resp.blob();
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(()=>URL.revokeObjectURL(link.href), 1000);
                    alert('Xuất file thành công: ' + filename);
                } catch (e) {
                    alert('Xuất file thất bại: ' + (e.message || e));
                }
            }

            $(document).on('click', "button[name='export_grades']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_grades: 1 }, 'diem.xls');
            });
            $(document).on('click', "button[name='export_attendance_6m']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_attendance_range: '6m', ngay_diem_danh: $('#ngay_diem_danh').val() }, 'diem_danh_6_thang.xls');
            });
            $(document).on('click', "button[name='export_attendance_1y']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_attendance_range: '1y', ngay_diem_danh: $('#ngay_diem_danh').val() }, 'diem_danh_1_nam.xls');
            });
            // Popup xác nhận trước khi lưu điểm danh
            const DAYS_VN = ['Chúa Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
            function fillConfirmAttendanceModal() {
                const dateInput = document.getElementById('ngay_diem_danh');
                const senderSelect = document.getElementById('sender_id');
                if (dateInput && dateInput.value && senderSelect) {
                    const opt = senderSelect.options[senderSelect.selectedIndex];
                    const senderName = opt.getAttribute('data-name') || opt.text || 'Chưa chọn';
                    const d = new Date(dateInput.value + 'T12:00:00');
                    const dayName = DAYS_VN[d.getDay()];
                    const dd = String(d.getDate()).padStart(2, '0');
                    const mm = String(d.getMonth() + 1).padStart(2, '0');
                    const yyyy = d.getFullYear();
                    const dateStr = dayName + ' ' + dd + '/' + mm + '/' + yyyy;
                    $('#confirmModalSenderName').text(senderName);
                    $('#confirmModalDateDisplay').text(dateStr).css('color', '#0d6efd');
                }
            }
            $('#attendanceForm').on('submit', function(e) {
                if ($(document.activeElement).attr('name') === 'submit_attendance') {
                    e.preventDefault();
                    fillConfirmAttendanceModal();
                    const confirmModal = new bootstrap.Modal(document.getElementById('confirmAttendanceModal'));
                    confirmModal.show();
                }
            });

            function buildSendEmailRecipientsList() {
                const absentStatuses = ['vắng có phép', 'vắng không phép'];
                const recipients = [];
                $('#attendanceForm .attendance-status-select').each(function() {
                    const status = $(this).val();
                    if (absentStatuses.indexOf(status) === -1) return;
                    recipients.push({
                        id: $(this).data('student-id'),
                        name: $(this).data('student-name') || '',
                        email: ($(this).data('student-email') || '').trim(),
                        status: status
                    });
                });

                let listHtml = '';
                let hasSendable = false;
                recipients.forEach(function(r) {
                    const hasEmail = r.email !== '';
                    if (hasEmail) hasSendable = true;
                    const statusLabel = r.status === 'vắng có phép' ? 'Vắng CP' : 'Vắng KP';
                    listHtml += '<label class="list-group-item d-flex align-items-start gap-2' + (hasEmail ? '' : ' list-group-item-light') + '">'
                        + '<input type="checkbox" class="form-check-input mt-1 email-recipient-checkbox" value="' + r.id + '"'
                        + (hasEmail ? ' checked' : ' disabled')
                        + ' data-has-email="' + (hasEmail ? '1' : '0') + '">'
                        + '<span><strong>' + $('<div>').text(r.name).html() + '</strong>'
                        + ' <span class="badge bg-secondary ms-1">' + statusLabel + '</span><br>'
                        + (hasEmail
                            ? '<small class="text-muted">' + $('<div>').text(r.email).html() + '</small>'
                            : '<small class="text-danger">Chưa có email phụ huynh</small>')
                        + '</span></label>';
                });

                $('#sendEmailRecipientsList').html(listHtml);
                $('#sendEmailEmptyMsg').toggle(recipients.length === 0 || !hasSendable);
                $('#confirmSendEmailBtn').prop('disabled', !hasSendable);
            }

            $('#openSendEmailModalBtn').on('click', function() {
                buildSendEmailRecipientsList();
                const modal = new bootstrap.Modal(document.getElementById('sendEmailModal'));
                modal.show();
            });

            $('#selectAllEmailRecipients').on('click', function() {
                $('.email-recipient-checkbox:not(:disabled)').prop('checked', true);
            });

            $('#deselectAllEmailRecipients').on('click', function() {
                $('.email-recipient-checkbox:not(:disabled)').prop('checked', false);
            });

            $('#confirmSendEmailBtn').on('click', function() {
                const selected = $('.email-recipient-checkbox:checked');
                if (selected.length === 0) {
                    alert('Vui lòng chọn ít nhất một email để gửi.');
                    return;
                }
                if (!confirm('Gửi email thông báo vắng học cho ' + selected.length + ' phụ huynh đã chọn?')) {
                    return;
                }

                $('#sendEmailRecipientsInputs').empty();
                selected.each(function() {
                    $('#sendEmailRecipientsInputs').append(
                        $('<input>', { type: 'hidden', name: 'email_recipients[]', value: $(this).val() })
                    );
                });

                const form = document.getElementById('attendanceForm');
                let sendFlag = form.querySelector('input[name="send_email"]');
                if (!sendFlag) {
                    sendFlag = document.createElement('input');
                    sendFlag.type = 'hidden';
                    sendFlag.name = 'send_email';
                    sendFlag.value = '1';
                    form.appendChild(sendFlag);
                }

                const modal = bootstrap.Modal.getInstance(document.getElementById('sendEmailModal'));
                if (modal) modal.hide();
                form.submit();
            });

            $('#confirmAttendanceSubmitBtn').on('click', function() {
                const confirmModal = bootstrap.Modal.getInstance(document.getElementById('confirmAttendanceModal'));
                if (confirmModal) confirmModal.hide();
                const form = document.getElementById('attendanceForm');
                const formData = new FormData(form);
                const submitBtn = $('button[name="submit_attendance"]');
                const originalText = submitBtn.html();
                submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang lưu...');
                fetch('class_details.php?id=<?php echo $class_id; ?>', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    submitBtn.prop('disabled', false).html(originalText);
                    const resultModal = new bootstrap.Modal(document.getElementById('attendanceResultModal'));
                    const header = document.getElementById('attendanceResultModalHeader');
                    const titleEl = document.getElementById('attendanceResultModalTitle');
                    const bodyEl = document.getElementById('attendanceResultModalBody');
                    if (data.status === 'success') {
                        header.className = 'modal-header bg-success text-white';
                        titleEl.textContent = 'Điểm danh thành công';
                        bodyEl.textContent = data.message || 'Đã lưu điểm danh.';
                    } else {
                        header.className = 'modal-header bg-danger text-white';
                        titleEl.textContent = 'Điểm danh không thành công';
                        bodyEl.textContent = data.message || 'Có lỗi xảy ra.';
                    }
                    resultModal.show();
                })
                .catch(error => {
                    submitBtn.prop('disabled', false).html(originalText);
                    const resultModal = new bootstrap.Modal(document.getElementById('attendanceResultModal'));
                    document.getElementById('attendanceResultModalHeader').className = 'modal-header bg-danger text-white';
                    document.getElementById('attendanceResultModalTitle').textContent = 'Điểm danh không thành công';
                    document.getElementById('attendanceResultModalBody').textContent = 'Lỗi khi lưu điểm danh: ' + (error.message || error);
                    resultModal.show();
                });
            });

            // Sao chép nội dung Nhắc nhở Phụ Huynh
            $('#copyRemindParentsBtn').on('click', function() {
                const ta = document.getElementById('remindParentsContent');
                if (!ta) return;
                ta.select();
                ta.setSelectionRange(0, 99999);
                try {
                    navigator.clipboard.writeText(ta.value);
                    const btn = $(this);
                    const orig = btn.html();
                    btn.html('<i class="fa-solid fa-check me-1"></i>Đã sao chép').addClass('btn-success').removeClass('btn-outline-primary');
                    setTimeout(function() { btn.html(orig).removeClass('btn-success').addClass('btn-outline-primary'); }, 2000);
                } catch (e) {
                    alert('Không thể sao chép. Bạn có thể chọn và copy thủ công (Ctrl+C).');
                }
            });

            function buildRemindParentsText() {
                const dateInput = document.getElementById('ngay_diem_danh');
                const activitySelect = document.getElementById('activity_type');
                const selects = document.querySelectorAll('#attendanceForm .attendance-status-select');
                if (!dateInput || !activitySelect || !selects.length) return null;

                const isMass = activitySelect.value === 'Đi lễ';
                const days = ['Chúa Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
                const parts = (dateInput.value || '').split('-');
                let header = dateInput.value;
                if (parts.length === 3) {
                    const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                    header = days[d.getDay()] + ' ngày ' + parts[2] + '/' + parts[1] + '/' + parts[0];
                }

                const absentLines = [];
                const lateLines = [];
                const noteLines = [];
                selects.forEach(function(sel) {
                    const sid = sel.getAttribute('data-student-id');
                    const name = sel.getAttribute('data-student-name') || '';
                    const st = sel.value;
                    if (st === 'vắng có phép' || st === 'vắng không phép') {
                        let line = '- ' + name + ': ' + (st === 'vắng có phép' ? 'Vắng có phép' : 'Vắng không phép');
                        if (!isMass) {
                            const total = (parseInt(sel.getAttribute('data-absent-base'), 10) || 0) + 1;
                            line += ' (tổng số buổi vắng: ' + total + ' buổi)';
                        }
                        absentLines.push(line + '.');
                    } else if (st === 'đi muộn') {
                        lateLines.push('- ' + name + '.');
                    }
                    const noteInput = document.querySelector('#attendanceForm input[name="note[' + sid + ']"]');
                    const note = noteInput ? noteInput.value.trim().replace(/\.+$/, '') : '';
                    if (note !== '') {
                        noteLines.push('- ' + name + ': ' + note + '.');
                    }
                });

                const out = ['Con xin gửi Quý Phụ Huynh thông tin điểm danh ' + (isMass ? 'Đi lễ' : 'Học Giáo Lý') + ' ' + header + ':'];
                if (absentLines.length) out.push('\nCác em vắng mặt:\n' + absentLines.join('\n'));
                if (lateLines.length) out.push('\nCác em ' + (isMass ? 'đi lễ' : 'đi học') + ' muộn:\n' + lateLines.join('\n'));
                if (noteLines.length) out.push('\nGhi chú:\n' + noteLines.join('\n'));
                if (!absentLines.length && !lateLines.length && !noteLines.length) {
                    out.push('\nTất cả các em đều có mặt đầy đủ và đúng giờ.');
                }
                out.push('\nCon xin cảm ơn!');
                return { header: header, text: out.join('\n') };
            }

            const remindModalEl = document.getElementById('remindParentsModal');
            if (remindModalEl) {
                remindModalEl.addEventListener('show.bs.modal', function() {
                    const result = buildRemindParentsText();
                    if (!result) return;
                    const ta = document.getElementById('remindParentsContent');
                    if (ta) {
                        ta.value = result.text;
                        ta.rows = Math.max(8, result.text.split('\n').length + 1);
                    }
                    const headerEl = document.getElementById('remindParentsHeader');
                    if (headerEl) headerEl.textContent = result.header;
                });
            }
            
            // Xử lý AJAX cho việc lưu điểm
            function updateGradesSummary(stats) {
                if (!stats) {
                    return;
                }
                document.querySelectorAll('#gradesForm tbody tr[data-student-id]').forEach(function(row) {
                    const sid = row.getAttribute('data-student-id');
                    const tbCell = row.querySelector('.grade-tb-cell');
                    const rankCell = row.querySelector('.grade-rank-cell');
                    if (tbCell && stats.tb && Object.prototype.hasOwnProperty.call(stats.tb, sid)) {
                        tbCell.textContent = stats.tb[sid];
                    }
                    if (rankCell && stats.ranks && Object.prototype.hasOwnProperty.call(stats.ranks, sid)) {
                        rankCell.textContent = stats.ranks[sid];
                    }
                    row.querySelectorAll('.grade-input').forEach(function(input) {
                        const v = parseFloat(input.value);
                        input.classList.toggle('low-grade', input.value !== '' && !isNaN(v) && v < 5);
                    });
                });
            }

            function showGradesResultModal(isSuccess, message, stats) {
                const resultModalEl = document.getElementById('gradesResultModal');
                const header = document.getElementById('gradesResultModalHeader');
                const titleEl = document.getElementById('gradesResultModalTitle');
                const bodyEl = document.getElementById('gradesResultModalBody');
                const closeBtn = document.getElementById('gradesResultModalCloseBtn');

                if (isSuccess) {
                    header.className = 'modal-header bg-success text-white';
                    titleEl.textContent = 'Lưu điểm thành công';
                    closeBtn.className = 'btn btn-light';
                } else {
                    header.className = 'modal-header bg-danger text-white';
                    titleEl.textContent = 'Lưu điểm thất bại';
                    closeBtn.className = 'btn btn-primary';
                }
                bodyEl.textContent = message || (isSuccess ? 'Đã lưu điểm.' : 'Có lỗi xảy ra.');

                const resultModal = bootstrap.Modal.getOrCreateInstance(resultModalEl);
                resultModal.show();

                if (isSuccess && stats) {
                    updateGradesSummary(stats);
                }
            }

            $('#gradesForm').on('submit', function(e) {
                if ($(document.activeElement).attr('name') === 'submit_grades') {
                    e.preventDefault();

                    const form = this;
                    const formData = new FormData(form);
                    form.querySelectorAll('.grade-input').forEach(function(input) {
                        if (input.value === '' || input.value === null) {
                            formData.set(input.name, '');
                        }
                    });

                    const submitBtn = $('button[name="submit_grades"]');
                    const originalText = submitBtn.html();
                    submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang lưu...');

                    fetch('class_details.php?id=<?php echo $class_id; ?>', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(async function(response) {
                        const text = await response.text();
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (err) {
                            throw new Error(text ? text.substring(0, 300) : 'Phản hồi máy chủ không hợp lệ');
                        }
                        if (!response.ok || data.status !== 'success') {
                            throw new Error(data.message || ('HTTP ' + response.status));
                        }
                        return data;
                    })
                    .then(function(data) {
                        submitBtn.prop('disabled', false).html(originalText);
                        showGradesResultModal(true, data.message, { tb: data.tb, ranks: data.ranks });
                    })
                    .catch(function(error) {
                        submitBtn.prop('disabled', false).html(originalText);
                        showGradesResultModal(false, error.message || String(error));
                    });
                }
            });
            
            // Hàm hiển thị thông báo thành công
            function showSuccessMessage(message) {
                // Xóa thông báo cũ
                $('.alert-success, .alert-danger').remove();
                
                // Thêm thông báo mới
                const alertHtml = '<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                    message +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>';
                
                $('.card-body').prepend(alertHtml);
                
                // Tự động ẩn sau 5 giây
                setTimeout(() => {
                    $('.alert-success').fadeOut();
                }, 5000);
            }
            
            // Hàm hiển thị thông báo lỗi
            function showErrorMessage(message) {
                // Xóa thông báo cũ
                $('.alert-success, .alert-danger').remove();
                
                // Thêm thông báo mới
                const alertHtml = '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                    message +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>';
                
                $('.card-body').prepend(alertHtml);
            }
        });
    </script>
    <script>
    (function () {
        function pinColumns() {
            var tables = document.querySelectorAll('table.pin-table');
            Array.prototype.forEach.call(tables, function (table) {
                var head = table.tHead && table.tHead.rows[0];
                if (!head || table.offsetParent === null) return;
                var idxs = [];
                for (var i = 0; i < head.cells.length; i++) {
                    if (head.cells[i].classList.contains('pin-col')) idxs.push(i);
                }
                if (!idxs.length) return;
                var lastIdx = idxs[idxs.length - 1];
                var bodyRows = Array.prototype.filter.call(table.rows, function (row) {
                    return !(row.cells.length === 1 && row.cells[0].colSpan > 1);
                });
                // Gán class trước (pin-check đổi độ rộng cột), đo sau
                bodyRows.forEach(function (row) {
                    idxs.forEach(function (idx) {
                        var c = row.cells[idx];
                        if (!c) return;
                        c.classList.add('pin-col');
                        if (head.cells[idx].classList.contains('pin-check')) c.classList.add('pin-check');
                        if (idx === lastIdx) c.classList.add('pin-last');
                        c.style.left = '0px';
                    });
                });
                var lefts = {};
                var x = 0;
                idxs.forEach(function (idx) {
                    lefts[idx] = x;
                    x += head.cells[idx].getBoundingClientRect().width;
                });
                bodyRows.forEach(function (row) {
                    idxs.forEach(function (idx) {
                        if (row.cells[idx]) row.cells[idx].style.left = lefts[idx] + 'px';
                    });
                });
            });
        }
        var timer = null;
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(pinColumns, 80);
        }
        document.addEventListener('DOMContentLoaded', pinColumns);
        window.addEventListener('load', pinColumns);
        window.addEventListener('resize', schedule);
        window.addEventListener('orientationchange', schedule);
        document.addEventListener('shown.bs.collapse', pinColumns);
    })();
    </script>
<?php login_include_bo_nhiem_popup(); ?>
</body>
</html>
<?php ?>