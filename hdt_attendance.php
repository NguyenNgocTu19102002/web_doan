<?php
session_start();
require_once 'login_redirect.php';
$logged_in = isset($_SESSION['user_id']);

// Kiểm tra quyền truy cập - chỉ cho phép hdt_manager
if (!$logged_in) {
    require_login_redirect();
}

// Load database configuration
require_once 'db_connect.php';

// Đảm bảo bảng hdt_attendance tồn tại (an toàn khi chạy nhiều lần)
$conn->query("CREATE TABLE IF NOT EXISTS hdt_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    activity_type ENUM('Đi lễ', 'Sinh hoạt chung', 'Đi Dạy Giáo Lý', 'Làm bác ái') NOT NULL,
    status ENUM('Có mặt', 'Vắng mặt', 'Vắng có phép', 'Đi muộn') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (teacher_id, class_id, attendance_date, activity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Kiểm tra role của user
$user_id = $_SESSION['user_id'];
$user_result = $conn->query("SELECT role FROM users WHERE id = $user_id");
if ($user_result && $user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
    $user_role = $user['role'];
} else {
    $user_role = 'admin'; // fallback
}

// Chỉ cho phép admin và hdt_manager truy cập
if ($user_role !== 'hdt_manager' && $user_role !== 'admin') {
    die("Bạn không có quyền truy cập trang này. Chỉ tài khoản Admin và quản lý Huynh Dự Trưởng mới được phép.");
}

// Xử lý AJAX request để lấy dữ liệu xuất Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_attendance_data'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $from_date = $conn->real_escape_string($_POST['from_date']);
    $to_date = $conn->real_escape_string($_POST['to_date']);
    $activity_type = $conn->real_escape_string($_POST['activity_type']);
    $class_id = (int)$_POST['class_id'];
    
    // Lấy danh sách ngày điểm danh trong khoảng
    $dates = [];
    $sql_dates = "SELECT DISTINCT attendance_date FROM hdt_attendance 
                  WHERE activity_type = '$activity_type' 
                  AND attendance_date BETWEEN '$from_date' AND '$to_date'";
    
    if ($class_id > 0) {
        $sql_dates .= " AND class_id = $class_id";
    }
    
    $sql_dates .= " ORDER BY attendance_date ASC";
    
    $result_dates = $conn->query($sql_dates);
    if ($result_dates && $result_dates->num_rows > 0) {
        while ($row = $result_dates->fetch_assoc()) {
            $dates[] = $row['attendance_date'];
        }
    }
    
    // Lấy danh sách Huynh Trưởng/Dự Trưởng
    $sql_teachers = "SELECT DISTINCT t.id, t.ho_ten, t.ten_thanh, t.loai_huynh, c.ten_lop, c.nganh, ct.class_id
                     FROM class_teachers ct 
                     JOIN teachers t ON ct.teacher_id = t.id 
                     JOIN classes c ON ct.class_id = c.id 
                     WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')";
    
    if ($class_id > 0) {
        $sql_teachers .= " AND ct.class_id = $class_id";
    }
    
    $sql_teachers .= " ORDER BY c.ten_lop, t.ho_ten";
    
    $result_teachers = $conn->query($sql_teachers);
    $teachers = [];
    
    if ($result_teachers && $result_teachers->num_rows > 0) {
        while ($row = $result_teachers->fetch_assoc()) {
            $teachers[] = [
                'id' => (int)$row['id'],
                'ho_ten' => $row['ho_ten'],
                'ten_thanh' => $row['ten_thanh'],
                'loai_huynh' => $row['loai_huynh'],
                'ten_lop' => $row['ten_lop'],
                'nganh' => $row['nganh'],
                'class_id' => (int)$row['class_id'],
                'attendance' => []
            ];
        }
    }
    
    // Lấy dữ liệu điểm danh
    if (!empty($teachers) && !empty($dates)) {
        $teacher_ids = array_map(function($t) { return $t['id']; }, $teachers);
        $ids_list = implode(',', $teacher_ids);
        
        $sql_attendance = "SELECT teacher_id, attendance_date, status 
                           FROM hdt_attendance 
                           WHERE activity_type = '$activity_type' 
                           AND attendance_date BETWEEN '$from_date' AND '$to_date' 
                           AND teacher_id IN ($ids_list)";
        
        if ($class_id > 0) {
            $sql_attendance .= " AND class_id = $class_id";
        }
        
        $result_attendance = $conn->query($sql_attendance);
        
        if ($result_attendance && $result_attendance->num_rows > 0) {
            $attendance_map = [];
            while ($row = $result_attendance->fetch_assoc()) {
                $tid = (int)$row['teacher_id'];
                $date = $row['attendance_date'];
                $status = $row['status'];
                $attendance_map[$tid][$date] = $status;
            }
            
            // Gán dữ liệu điểm danh cho từng giáo lý viên
            for ($i = 0; $i < count($teachers); $i++) {
                $tid = $teachers[$i]['id'];
                foreach ($dates as $date) {
                    $teachers[$i]['attendance'][$date] = isset($attendance_map[$tid][$date]) 
                        ? $attendance_map[$tid][$date] 
                        : '-';
                }
            }
        }
        
        // Tính % nghỉ trong 6 tháng (chỉ cho "Đi Dạy Giáo Lý")
        // Đi muộn không tính %, Vắng mặt và Vắng có phép đều tính là vắng
        $six_months_ago = date('Y-m-d', strtotime('-6 months', strtotime($to_date)));
        $sql_percent = "SELECT teacher_id, 
                               COUNT(*) AS total,
                               SUM(CASE WHEN status IN ('Vắng mặt','Vắng có phép') THEN 1 ELSE 0 END) AS absent
                        FROM hdt_attendance
                        WHERE activity_type = 'Đi Dạy Giáo Lý' 
                        AND attendance_date >= '$six_months_ago' 
                        AND teacher_id IN ($ids_list)";
        
        if ($class_id > 0) {
            $sql_percent .= " AND class_id = $class_id";
        }
        
        $sql_percent .= " GROUP BY teacher_id";
        
        $result_percent = $conn->query($sql_percent);
        if ($result_percent && $result_percent->num_rows > 0) {
            while ($row = $result_percent->fetch_assoc()) {
                $tid = (int)$row['teacher_id'];
                $total = (int)$row['total'];
                $absent = (int)$row['absent'];
                $percent = $total > 0 ? round(($absent / $total) * 100, 2) : 0;
                
                // Tìm teacher trong mảng và thêm percent
                for ($i = 0; $i < count($teachers); $i++) {
                    if ($teachers[$i]['id'] == $tid) {
                        $teachers[$i]['absent_percent'] = $percent;
                        break;
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'dates' => $dates,
        'teachers' => $teachers
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lấy danh sách Huynh Trưởng & Huynh Dự Trưởng
$sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.loai_huynh, c.ten_lop, c.nganh, ct.class_id
            FROM class_teachers ct 
            JOIN teachers t ON ct.teacher_id = t.id 
            JOIN classes c ON ct.class_id = c.id 
            WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')
            ORDER BY c.ten_lop, t.ho_ten";
$result_hdt = $conn->query($sql_hdt);
$hdt_list = [];
if ($result_hdt->num_rows > 0) {
    while ($row = $result_hdt->fetch_assoc()) {
        $hdt_list[] = $row;
    }
}

// Lấy danh sách lớp
$sql_classes = "SELECT id, ten_lop, nganh FROM classes ORDER BY ten_lop";
$result_classes = $conn->query($sql_classes);
$classes = [];
if ($result_classes->num_rows > 0) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Xử lý xóa điểm danh theo ngày
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attendance_date'])) {
    $delete_date = isset($_POST['delete_date']) ? trim($_POST['delete_date']) : '';
    $delete_activity = isset($_POST['delete_activity']) ? trim($_POST['delete_activity']) : '';
    $delete_class = isset($_POST['delete_class']) ? (int)$_POST['delete_class'] : 0;
    
    if ($delete_date === '' || $delete_activity === '') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin ngày hoặc loại hoạt động']);
            exit;
        } else {
            $_SESSION['error'] = 'Thiếu thông tin ngày hoặc loại hoạt động';
            header("Location: hdt_attendance.php");
            exit;
        }
    }
    
    try {
        if ($delete_class > 0) {
            // Xóa theo lớp cụ thể
            $sql_delete = "DELETE FROM hdt_attendance WHERE attendance_date = ? AND activity_type = ? AND class_id = ?";
            $stmt = $conn->prepare($sql_delete);
            $stmt->bind_param('ssi', $delete_date, $delete_activity, $delete_class);
        } else {
            // Xóa tất cả lớp
            $sql_delete = "DELETE FROM hdt_attendance WHERE attendance_date = ? AND activity_type = ?";
            $stmt = $conn->prepare($sql_delete);
            $stmt->bind_param('ss', $delete_date, $delete_activity);
        }
        
        if ($stmt->execute()) {
            $deleted_count = $stmt->affected_rows;
            $stmt->close();
            
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['status' => 'success', 'message' => "Đã xóa điểm danh ngày $delete_date - $delete_activity ($deleted_count bản ghi)"]);
                exit;
            } else {
                $_SESSION['success'] = "Đã xóa điểm danh ngày $delete_date - $delete_activity ($deleted_count bản ghi)";
                header("Location: hdt_attendance.php?date=$delete_date&activity=" . urlencode($delete_activity) . "&class=$delete_class");
                exit;
            }
        } else {
            throw new Exception('Không thể xóa điểm danh: ' . $stmt->error);
        }
    } catch (Exception $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi khi xóa: ' . $e->getMessage()]);
            exit;
        } else {
            $_SESSION['error'] = 'Lỗi khi xóa: ' . $e->getMessage();
            header("Location: hdt_attendance.php");
            exit;
        }
    }
}

// Xử lý lưu điểm danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $attendance_date = isset($_POST['attendance_date']) ? trim($_POST['attendance_date']) : '';
    $activity_type = isset($_POST['activity_type']) ? trim($_POST['activity_type']) : '';
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    $attendance = isset($_POST['attendance']) && is_array($_POST['attendance']) ? $_POST['attendance'] : [];

    // Validate inputs
    if ($attendance_date === '' || $activity_type === '') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin: vui lòng chọn Ngày và Loại hoạt động trước khi lưu.']);
            exit;
        } else {
            $_SESSION['error'] = 'Thiếu thông tin: vui lòng chọn Ngày và Loại hoạt động trước khi lưu.';
            header("Location: hdt_attendance.php");
            exit;
        }
    }
    if (empty($attendance)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => 'Không có dữ liệu điểm danh để lưu.']);
            exit;
        } else {
            $_SESSION['error'] = 'Không có dữ liệu điểm danh để lưu.';
            header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
            exit;
        }
    }

    // Transaction: delete then bulk insert
    $conn->begin_transaction();
    try {
        // Xóa dữ liệu cũ
        if ($class_id > 0) {
            // Nếu chọn 1 lớp: xóa theo lớp
            $delete_sql = "DELETE FROM hdt_attendance WHERE class_id = ? AND attendance_date = ? AND activity_type = ?";
            $stmt_delete = $conn->prepare($delete_sql);
            $stmt_delete->bind_param('iss', $class_id, $attendance_date, $activity_type);
            if (!$stmt_delete->execute()) {
                throw new Exception('Xóa dữ liệu cũ thất bại: ' . $stmt_delete->error);
            }
            $stmt_delete->close();
        } else {
            // Tất cả lớp: xóa theo từng giáo lý viên trong payload
            $delete_sql = "DELETE FROM hdt_attendance WHERE teacher_id = ? AND attendance_date = ? AND activity_type = ?";
            $stmt_delete = $conn->prepare($delete_sql);
            if (!$stmt_delete) { throw new Exception('Không thể chuẩn bị xóa: ' . $conn->error); }
            foreach ($attendance as $teacher_id => $_tmp) {
                $teacher_id_int = (int)$teacher_id;
                $stmt_delete->bind_param('iss', $teacher_id_int, $attendance_date, $activity_type);
                if (!$stmt_delete->execute()) {
                    throw new Exception('Xóa cũ thất bại cho ID ' . $teacher_id_int . ': ' . $stmt_delete->error);
                }
            }
            $stmt_delete->close();
        }

        // Insert new records
        $insert_sql = "INSERT INTO hdt_attendance (teacher_id, class_id, attendance_date, activity_type, status, notes) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($insert_sql);
        if (!$stmt_insert) {
            throw new Exception('Không thể chuẩn bị câu lệnh lưu: ' . $conn->error);
        }

        $success_count = 0;
        foreach ($attendance as $teacher_id => $data) {
            $teacher_id_int = (int)$teacher_id;
            $status = isset($data['status']) ? (string)$data['status'] : 'Có mặt';
            $notes = isset($data['notes']) ? (string)$data['notes'] : '';
            // class id theo từng dòng nếu có; nếu không có thì cố gắng lấy từ mục đã chọn
            $row_class_id = isset($data['class_id']) ? (int)$data['class_id'] : 0;
            if ($row_class_id <= 0 && $class_id > 0) { $row_class_id = $class_id; }
            // Bảo vệ khóa ngoại: nếu vẫn không xác định lớp, bỏ qua dòng này
            if ($row_class_id <= 0) { continue; }
            $stmt_insert->bind_param('iissss', $teacher_id_int, $row_class_id, $attendance_date, $activity_type, $status, $notes);
            if (!$stmt_insert->execute()) {
                throw new Exception('Lưu thất bại cho ID: ' . $teacher_id_int . ' - ' . $stmt_insert->error);
            }
            $success_count++;
        }
        $stmt_insert->close();
    
        $conn->commit();
        
        // Kiểm tra nếu là AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'success', 'message' => "Đã lưu điểm danh cho $success_count Huynh Trưởng/Dự Trưởng"]);
            exit;
        } else {
            $_SESSION['success'] = "Đã lưu điểm danh cho $success_count Huynh Trưởng/Dự Trưởng";
            header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
            exit;
        }
    } catch (Exception $e) {
        $conn->rollback();
        
        // Kiểm tra nếu là AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['status' => 'error', 'message' => 'Không thể lưu điểm danh: ' . $e->getMessage()]);
            exit;
        } else {
            $_SESSION['error'] = 'Không thể lưu điểm danh: ' . $e->getMessage();
            header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
            exit;
        }
    }
}

// Lấy dữ liệu điểm danh hiện có
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_activity = isset($_GET['activity']) ? $_GET['activity'] : 'Đi Dạy Giáo Lý';
$selected_class = isset($_GET['class']) ? (int)$_GET['class'] : 0;

$attendance_data = [];
if ($selected_class > 0) {
    $sql_attendance = "SELECT teacher_id, status, notes 
                       FROM hdt_attendance 
                       WHERE class_id = $selected_class 
                       AND attendance_date = '$selected_date' 
                       AND activity_type = '$selected_activity'";
} else {
    $sql_attendance = "SELECT teacher_id, status, notes 
                       FROM hdt_attendance 
                       WHERE attendance_date = '$selected_date' 
                       AND activity_type = '$selected_activity'";
}
    $result_attendance = $conn->query($sql_attendance);
if ($result_attendance && $result_attendance->num_rows > 0) {
        while ($row = $result_attendance->fetch_assoc()) {
            $attendance_data[$row['teacher_id']] = $row;
    }
}

// Lọc danh sách HT theo lớp được chọn (0 = tất cả lớp)
$filtered_hdt_list = [];
if ($selected_class > 0) {
    foreach ($hdt_list as $hdt) {
        if ($hdt['class_id'] == $selected_class) {
            $filtered_hdt_list[] = $hdt;
        }
    }
} else {
    $filtered_hdt_list = $hdt_list;
}

// Lấy 3 lần điểm danh gần nhất (theo hoạt động đã chọn và lớp nếu có)
$recent_dates = [];
if ($selected_class > 0) {
    $sql_recent = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND class_id = $selected_class ORDER BY attendance_date DESC LIMIT 3";
} else {
    $sql_recent = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' ORDER BY attendance_date DESC LIMIT 3";
}
$res_recent = $conn->query($sql_recent);
if ($res_recent && $res_recent->num_rows > 0) {
    while ($r = $res_recent->fetch_assoc()) { $recent_dates[] = $r['attendance_date']; }
}

// Dữ liệu 6 tháng gần nhất để tính % nghỉ và xuất Excel
$six_month_start = date('Y-m-d', strtotime('-6 months', strtotime($selected_date)));
$six_month_dates = [];
if ($selected_class > 0) {
    $sql_6m_dates = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$six_month_start' AND class_id = $selected_class ORDER BY attendance_date ASC";
} else {
    $sql_6m_dates = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$six_month_start' ORDER BY attendance_date ASC";
}
$res_6m_dates = $conn->query($sql_6m_dates);
if ($res_6m_dates && $res_6m_dates->num_rows > 0) {
    while ($r = $res_6m_dates->fetch_assoc()) { $six_month_dates[] = $r['attendance_date']; }
}

// Map dữ liệu lịch sử trong 6 tháng cho các HT đang hiển thị
$teacher_ids = array_map(function($r){ return (int)$r['id']; }, $filtered_hdt_list);
$history_map = [];
if (!empty($teacher_ids) && !empty($six_month_dates)) {
    $ids_sql = implode(',', $teacher_ids);
    $from = $conn->real_escape_string($six_month_start);
    if ($selected_class > 0) {
        $sql_hist = "SELECT teacher_id, attendance_date, status FROM hdt_attendance WHERE activity_type='" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$from' AND class_id = $selected_class AND teacher_id IN ($ids_sql)";
    } else {
        $sql_hist = "SELECT teacher_id, attendance_date, status FROM hdt_attendance WHERE activity_type='" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$from' AND teacher_id IN ($ids_sql)";
    }
    $res_hist = $conn->query($sql_hist);
    if ($res_hist && $res_hist->num_rows > 0) {
        while ($row = $res_hist->fetch_assoc()) {
            $tid = (int)$row['teacher_id'];
            $d = $row['attendance_date'];
            $history_map[$tid][$d] = $row['status'];
        }
    }
}

// Tính % nghỉ trong 6 tháng gần nhất cho riêng buổi Dạy Giáo Lý (để gửi mail)
$percent_teaching_absent = [];
if (!empty($teacher_ids)) {
    $ids_sql = implode(',', $teacher_ids);
    $sql_teaching = "SELECT teacher_id, 
                            COUNT(*) AS total,
                            SUM(CASE WHEN status IN ('Vắng mặt','Vắng có phép') THEN 1 ELSE 0 END) AS absent
                     FROM hdt_attendance
                     WHERE activity_type = 'Đi Dạy Giáo Lý' AND attendance_date >= '$six_month_start' AND teacher_id IN ($ids_sql)";
    
    if ($selected_class > 0) {
        $sql_teaching .= " AND class_id = $selected_class";
    }
    
    $sql_teaching .= " GROUP BY teacher_id";
    $res_teaching = $conn->query($sql_teaching);
    if ($res_teaching) {
        while ($row = $res_teaching->fetch_assoc()) {
            $total = (int)$row['total'];
            $abs = (int)$row['absent'];
            $percent_teaching_absent[(int)$row['teacher_id']] = $total > 0 ? round(($abs/$total)*100, 2) : 0;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Điểm Danh Huynh Trưởng - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .attendance-card { border-left: 4px solid #dc3545; }
        .attendance-table th { 
            background-color: #f8f9fa; 
            text-align: center;
            vertical-align: middle;
        }
        .attendance-table td {
            text-align: center;
            vertical-align: middle;
        }
        .attendance-table td:nth-child(3) {
            text-align: left; /* Họ tên căn trái */
        }
        .btn-attendance { min-width: 100px; }
        
        /* Màu đỏ nhẹ cho header */
        .hdt-header {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
        }
        .hdt-card {
            border-left: 4px solid #dc3545;
        }
        .hdt-card .card-header {
            background-color: #f8d7da;
            border-bottom: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        /* Màu sắc cho các trạng thái điểm danh */
        .status-absent {
            color: #dc3545;
            font-weight: 700;
        }
        .status-excused {
            color: #fd7e14;
            font-weight: 700;
        }
        .status-late {
            color: #0d6efd;
            font-weight: 600;
        }
        .status-present {
            color: #198754;
            font-weight: 500;
        }
        
        /* Responsive cho mobile */
        @media (max-width: 768px) {
            .attendance-table {
                font-size: 0.85rem;
            }
            .attendance-table th,
            .attendance-table td {
                padding: 0.5rem 0.25rem;
            }
            .btn-attendance {
                min-width: 80px;
                font-size: 0.8rem;
                padding: 0.25rem 0.5rem;
            }
            .attendance-table td:nth-child(3) {
                font-size: 0.8rem;
            }
            .badge {
                font-size: 0.7rem;
            }
            /* Ẩn cột STT và Tên Thánh trên mobile để tiết kiệm không gian */
            .attendance-table th:nth-child(1),
            .attendance-table td:nth-child(1),
            .attendance-table th:nth-child(2),
            .attendance-table td:nth-child(2) {
                display: none;
            }
            
            /* Form controls trên 1 hàng */
            .row.mb-4 {
                display: flex !important;
                flex-wrap: nowrap !important;
                gap: 0.5rem !important;
                margin-bottom: 1rem !important;
            }
            
            .row.mb-4 > div[class*="col-md"] {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                padding: 0 !important;
            }
            
            .row.mb-4 .form-label {
                font-size: 0.7rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .row.mb-4 .form-control,
            .row.mb-4 .form-select {
                font-size: 0.75rem !important;
                padding: 0.35rem 0.4rem !important;
            }
            
            .row.mb-4 .btn {
                font-size: 0.7rem !important;
                padding: 0.35rem 0.4rem !important;
                white-space: nowrap !important;
            }
            
            .row.mb-4 .btn i {
                display: none !important;
            }
            
            .row.mb-4 .d-flex.align-items-end {
                flex-direction: row !important;
                gap: 0.25rem !important;
            }
        }
        
        @media (max-width: 576px) {
            .attendance-table {
                font-size: 0.75rem;
            }
            .attendance-table th,
            .attendance-table td {
                padding: 0.4rem 0.2rem;
            }
            /* Ẩn thêm cột Lớp trên màn hình rất nhỏ */
            .attendance-table th:nth-child(4),
            .attendance-table td:nth-child(4) {
                display: none;
            }
        }
    </style>
</head>
<body class="theme-default">
    <header class="text-white text-center hdt-header">
        <div class="container">
            <img src="img/logo.png" alt="Logo Đoàn" style="max-width: 90px; margin-bottom: 15px;">
            <h1>Điểm Danh Huynh Trưởng & Dự Trưởng</h1>
            <p class="lead mb-0">Theo dõi tham gia hoạt động</p>
        </div>
    </header>

    <main class="container my-5">
        <div class="card hdt-card">
            <div class="card-body p-4">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <!-- Form chọn ngày, loại hoạt động và lớp -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" id="attendance_date" class="form-control" value="<?php echo $selected_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Loại Hoạt Động</label>
                        <select id="activity_type" class="form-control">
                            <option value="Đi Dạy Giáo Lý" <?php echo $selected_activity === 'Đi Dạy Giáo Lý' ? 'selected' : ''; ?>>Đi Dạy Giáo Lý</option>
                            <option value="Đi lễ" <?php echo $selected_activity === 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
                            <option value="Sinh hoạt chung" <?php echo $selected_activity === 'Sinh hoạt chung' ? 'selected' : ''; ?>>Sinh hoạt chung</option>
                            <option value="Làm bác ái" <?php echo $selected_activity === 'Làm bác ái' ? 'selected' : ''; ?>>Làm bác ái</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lớp</label>
                        <select id="class_filter" class="form-control">
                            <option value="0">Tất cả lớp</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="button" class="btn btn-primary" onclick="loadAttendance()">
                            <i class="fa-solid fa-search me-2"></i>Tải Điểm Danh
                        </button>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteAttendance()" title="Xóa điểm danh ngày này">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Tìm theo tên HT</label>
                        <div class="input-group">
                            <input type="text" id="search_name" class="form-control" placeholder="Nhập tên gần đúng...">
                            <button class="btn btn-outline-secondary" type="button" onclick="filterByName()"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </div>
                        <small class="text-muted">Không phân biệt dấu và chữ hoa.</small>
                    </div>
                </div>

                <!-- Form điểm danh -->
                <?php if (!empty($filtered_hdt_list)): ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="save_attendance" value="1">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="activity_type" value="<?php echo $selected_activity; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover attendance-table">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Tên Thánh</th>
                                    <th>Họ Tên</th>
                                    <th>Lớp</th>
                                    <th>Cấp Bậc</th>
                                    <th>Trạng Thái</th>
                                    <?php foreach ($recent_dates as $d): ?>
                                        <th><?php echo date('d/m', strtotime($d)); ?></th>
                                    <?php endforeach; ?>
                                    <th>% Nghỉ (6 tháng)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $stt = 1; foreach ($filtered_hdt_list as $hdt): ?>
                                    <tr>
                                        <td><?php echo $stt++; ?></td>
                                        <td><?php echo htmlspecialchars(isset($hdt['ten_thanh']) ? $hdt['ten_thanh'] : 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($hdt['ho_ten']); ?></td>
                                        <td><?php echo htmlspecialchars($hdt['ten_lop']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $hdt['loai_huynh'] === 'Huynh Dự Trưởng' ? 'bg-warning' : 'bg-danger'; ?>">
                                                <?php 
                                                    $cap_bac = isset($hdt['loai_huynh']) ? $hdt['loai_huynh'] : 'Huynh Trưởng';
                                                    echo htmlspecialchars($cap_bac === 'Huynh Dự Trưởng' ? 'Dự Trưởng' : $cap_bac); 
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select name="attendance[<?php echo $hdt['id']; ?>][status]" class="form-control btn-attendance">
                                                <option value="Có mặt" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Có mặt') ? 'selected' : ''; ?>>Có mặt</option>
                                                <option value="Đi muộn" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Đi muộn') ? 'selected' : ''; ?>>Đi muộn</option>
                                                <option value="Vắng mặt" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Vắng mặt') ? 'selected' : ''; ?>>Vắng mặt</option>
                                                <option value="Vắng có phép" <?php echo (isset($attendance_data[$hdt['id']]) && ($attendance_data[$hdt['id']]['status'] === 'Vắng có phép' || $attendance_data[$hdt['id']]['status'] === 'Có phép')) ? 'selected' : ''; ?>>Vắng có phép</option>
                                            </select>
                                            <input type="hidden" name="attendance[<?php echo $hdt['id']; ?>][class_id]" value="<?php echo (int)$hdt['class_id']; ?>">
                                        </td>
                                        <?php foreach ($recent_dates as $d): ?>
                                            <?php 
                                                $st = isset($history_map[$hdt['id']][$d]) ? $history_map[$hdt['id']][$d] : null;
                                                $statusClass = '';
                                                if ($st === 'Vắng mặt') $statusClass = 'status-absent';
                                                elseif ($st === 'Vắng có phép' || $st === 'Có phép') $statusClass = 'status-excused';
                                                elseif ($st === 'Đi muộn') $statusClass = 'status-late';
                                                elseif ($st === 'Có mặt') $statusClass = 'status-present';
                                            ?>
                                            <td class="text-center">
                                                <span class="<?php echo $statusClass; ?>">
                                                    <?php echo $st ? htmlspecialchars($st) : '-'; ?>
                                                </span>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="attendance-col">
                                            <?php 
                                                $pct = isset($percent_teaching_absent[$hdt['id']]) ? $percent_teaching_absent[$hdt['id']] : 0; 
                                                $cls = $pct > 40 ? 'text-danger fw-bold' : '';
                                                echo '<span class="' . $cls . '">' . $pct . '%</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="hdt_management.php" class="btn btn-secondary">
                            <i class="fa-solid fa-arrow-left me-2"></i>Quay Lại
                        </a>
                        <div>
                            <button type="button" class="btn btn-warning me-2" onclick="sendEmailWarning()">
                                <i class="fa-solid fa-envelope me-2"></i>Gửi mail (>40% dạy)
                            </button>
                            <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#exportExcelModal">
                                <i class="fa-solid fa-file-excel me-2"></i>Xuất Excel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-save me-2"></i>Lưu Điểm Danh
                            </button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-users fa-3x text-muted mb-3"></i>
                        <h5>Chưa có Huynh Trưởng/Dự Trưởng nào</h5>
                        <p class="text-muted">Vui lòng chọn lớp hoặc thêm Huynh Trưởng/Dự Trưởng</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Xác Nhận Xóa Điểm Danh -->
    <div class="modal fade" id="deleteAttendanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fa-solid fa-trash me-2"></i>Xác Nhận Xóa Điểm Danh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-exclamation-triangle me-2"></i>
                        <strong>Cảnh báo:</strong> Hành động này không thể hoàn tác!
                    </div>
                    <p class="mb-3">Bạn có chắc chắn muốn xóa toàn bộ điểm danh cho:</p>
                    <ul>
                        <li><strong>Ngày:</strong> <span id="delete_confirm_date"></span></li>
                        <li><strong>Hoạt động:</strong> <span id="delete_confirm_activity"></span></li>
                        <li><strong>Lớp:</strong> <span id="delete_confirm_class"></span></li>
                    </ul>
                    <p class="text-danger fw-bold mb-0">Tất cả dữ liệu điểm danh của ngày này sẽ bị xóa vĩnh viễn!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa-solid fa-times me-2"></i>Hủy
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteAttendanceDate()">
                        <i class="fa-solid fa-trash me-2"></i>Xóa Điểm Danh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Xuất Excel -->
    <div class="modal fade" id="exportExcelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-file-excel me-2"></i>Xuất Excel Điểm Danh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fa-solid fa-info-circle me-2"></i>
                        <strong>Chú ý:</strong> File Excel sẽ bao gồm tất cả ngày điểm danh trong khoảng thời gian bạn chọn.
                        <ul class="mb-0 mt-2">
                            <li><strong style="font-weight: 700;">Đi muộn</strong>: In đậm màu đen</li>
                            <li><strong style="font-weight: 700; color: #FF8C00;">Vắng có phép</strong>: Chữ cam in đậm</li>
                            <li><strong style="font-weight: 700; color: #FF0000;">Vắng mặt</strong>: Chữ đỏ in đậm</li>
                        </ul>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Từ ngày</label>
                            <input type="date" id="export_from_date" class="form-control" value="<?php echo $six_month_start; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Đến ngày</label>
                            <input type="date" id="export_to_date" class="form-control" value="<?php echo $selected_date; ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa-solid fa-times me-2"></i>Hủy
                    </button>
                    <button type="button" class="btn btn-success" onclick="exportToExcelWithRange()">
                        <i class="fa-solid fa-download me-2"></i>Xuất Excel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
    <script>
        // Dữ liệu PHP cho JavaScript
        const allHdtData = <?php echo json_encode($hdt_list, JSON_UNESCAPED_UNICODE); ?>;
        const currentClassId = <?php echo $selected_class; ?>;
        const currentActivity = '<?php echo addslashes($selected_activity); ?>';
        
        function loadAttendance() {
            const date = document.getElementById('attendance_date').value;
            const activity = document.getElementById('activity_type').value;
            const classId = document.getElementById('class_filter').value;
            
            if (!date) {
                alert('Vui lòng chọn ngày');
                return;
            }
            
            window.location.href = `hdt_attendance.php?date=${date}&activity=${encodeURIComponent(activity)}&class=${classId}`;
        }

        function exportToExcelWithRange() {
            const fromDate = document.getElementById('export_from_date').value;
            const toDate = document.getElementById('export_to_date').value;
            const classFilter = document.getElementById('class_filter');
            const className = classFilter.options[classFilter.selectedIndex].text;
            
            if (!fromDate || !toDate) {
                alert('Vui lòng chọn đầy đủ khoảng ngày');
                return;
            }
            
            if (fromDate > toDate) {
                alert('Ngày bắt đầu phải nhỏ hơn hoặc bằng ngày kết thúc');
                return;
            }
            
            // Đóng modal
            bootstrap.Modal.getInstance(document.getElementById('exportExcelModal')).hide();
            
            // Hiển thị loading
            const loadingMsg = document.createElement('div');
            loadingMsg.className = 'alert alert-info';
            loadingMsg.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang xuất Excel, vui lòng chờ...';
            document.querySelector('.card-body').prepend(loadingMsg);
            
            // Gọi AJAX để lấy dữ liệu từ PHP
            $.ajax({
                url: 'hdt_attendance.php',
                method: 'POST',
                data: {
                    get_attendance_data: 1,
                    from_date: fromDate,
                    to_date: toDate,
                    activity_type: currentActivity,
                    class_id: currentClassId
                },
                dataType: 'json',
                success: function(result) {
                    loadingMsg.remove();
                    
                    if (result.error) {
                        alert('Lỗi: ' + result.error);
                        return;
                    }
                    
                    const { dates, teachers } = result;
                    
                    if (dates.length === 0) {
                        alert('Không có dữ liệu điểm danh trong khoảng ngày này');
                        return;
                    }
                    
                    // Tạo header
                    const header = ['STT', 'Tên Thánh', 'Họ Tên', 'Lớp', 'Cấp Bậc', ...dates.map(d => {
                        const parts = d.split('-');
                        return `${parts[2]}/${parts[1]}`;
                    }), '% Nghỉ (6 tháng)'];
                    
                    const data = [header];
                    
                    // Tạo dữ liệu
                    let stt = 1;
                    teachers.forEach(teacher => {
                        const row = [
                            stt++,
                            teacher.ten_thanh || 'N/A',
                            teacher.ho_ten,
                            teacher.ten_lop,
                            teacher.loai_huynh === 'Huynh Dự Trưởng' ? 'Dự Trưởng' : teacher.loai_huynh
                        ];
                        
                        dates.forEach(date => {
                            row.push(teacher.attendance[date] || '-');
                        });
                        
                        // Thêm % nghỉ
                        row.push((teacher.absent_percent || 0) + '%');
                        
                        data.push(row);
                    });
                    
                    // Tạo workbook
                    const ws = XLSX.utils.aoa_to_sheet(data);
                    
                    // Định dạng cột
                    const cols = [];
                    cols.push({ wpx: 50 });   // STT
                    cols.push({ wpx: 120 });  // Tên Thánh
                    cols.push({ wpx: 180 });  // Họ Tên
                    cols.push({ wpx: 120 });  // Lớp
                    cols.push({ wpx: 110 });  // Cấp Bậc
                    for (let i = 0; i < dates.length; i++) cols.push({ wpx: 100 }); // ngày
                    cols.push({ wpx: 120 });  // % Nghỉ
                    ws['!cols'] = cols;
                    
                    // Định dạng hàng
                    const rows = new Array(data.length).fill(null).map(() => ({ hpx: 28 }));
                    rows[0] = { hpx: 32 };
                    ws['!rows'] = rows;
                    
                    // Định dạng màu cho các ô
                    const range = XLSX.utils.decode_range(ws['!ref']);
                    for (let R = 1; R <= range.e.r; R++) { // Bắt đầu từ row 1 (bỏ qua header)
                        for (let C = 5; C <= range.e.c - 1; C++) { // Bắt đầu từ cột ngày (index 5), trừ cột % nghỉ cuối
                            const cellAddress = XLSX.utils.encode_cell({ r: R, c: C });
                            if (!ws[cellAddress]) continue;
                            
                            const cellValue = ws[cellAddress].v;
                            
                            // Đi muộn: In đậm màu đen
                            if (cellValue === 'Đi muộn') {
                                ws[cellAddress].s = {
                                    font: { bold: true, color: { rgb: "000000" } },
                                    alignment: { horizontal: "center", vertical: "center" }
                                };
                            }
                            // Vắng có phép: Chữ vàng in đậm
                            else if (cellValue === 'Vắng có phép' || cellValue === 'Có phép') {
                                ws[cellAddress].s = {
                                    font: { bold: true, color: { rgb: "FF8C00" } },
                                    alignment: { horizontal: "center", vertical: "center" }
                                };
                            }
                            // Vắng mặt: Chữ đỏ in đậm
                            else if (cellValue === 'Vắng mặt') {
                                ws[cellAddress].s = {
                                    font: { bold: true, color: { rgb: "FF0000" } },
                                    alignment: { horizontal: "center", vertical: "center" }
                                };
                            }
                            // Có mặt: Căn giữa
                            else if (cellValue === 'Có mặt') {
                                ws[cellAddress].s = {
                                    alignment: { horizontal: "center", vertical: "center" }
                                };
                            }
                            // Các giá trị khác (-, v.v.): Căn giữa
                            else {
                                ws[cellAddress].s = {
                                    alignment: { horizontal: "center", vertical: "center" }
                                };
                            }
                        }
                        
                        // Định dạng cột % nghỉ (cột cuối cùng)
                        const percentColIndex = range.e.c;
                        const percentCellAddress = XLSX.utils.encode_cell({ r: R, c: percentColIndex });
                        if (ws[percentCellAddress]) {
                            const percentValue = ws[percentCellAddress].v;
                            const percentNum = parseFloat(percentValue);
                            
                            if (!isNaN(percentNum)) {
                                if (percentNum > 40) {
                                    ws[percentCellAddress].s = {
                                        font: { bold: true, color: { rgb: "DC3545" } },
                                        alignment: { horizontal: "center", vertical: "center" }
                                    };
                                } else {
                                    ws[percentCellAddress].s = {
                                        alignment: { horizontal: "center", vertical: "center" }
                                    };
                                }
                            }
                        }
                    }
                    
                    // Định dạng header
                    for (let C = 0; C <= range.e.c; C++) {
                        const cellAddress = XLSX.utils.encode_cell({ r: 0, c: C });
                        if (ws[cellAddress]) {
                            ws[cellAddress].s = {
                                font: { bold: true },
                                fill: { patternType: "solid", fgColor: { rgb: "F8F9FA" } },
                                alignment: { horizontal: "center", vertical: "center" }
                            };
                        }
                    }
                    
                    const wb = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(wb, ws, 'Điểm Danh HT');
                    
                    // Xuất file
                    const fileName = `DiemDanh_HT_${fromDate}_${toDate}_${currentActivity}_${className.replace(/[^a-zA-Z0-9]/g, '_')}.xlsx`;
                    XLSX.writeFile(wb, fileName);
                    
                    // Hiển thị thông báo thành công
                    showSuccessMessage('Xuất Excel thành công!');
                },
                error: function(xhr, status, error) {
                    loadingMsg.remove();
                    console.error('Lỗi:', error);
                    alert('Có lỗi xảy ra khi xuất Excel: ' + error);
                }
            });
        }

        function sendEmailWarning() {
            alert('Chức năng gửi mail sẽ sử dụng cấu hình PHPMailer sẵn có. Hãy cung cấp SMTP để kích hoạt thực tế.');
        }

        // Xác nhận xóa điểm danh
        function confirmDeleteAttendance() {
            const date = document.getElementById('attendance_date').value;
            const activity = document.getElementById('activity_type').value;
            const classFilter = document.getElementById('class_filter');
            const className = classFilter.options[classFilter.selectedIndex].text;
            
            if (!date || !activity) {
                alert('Vui lòng chọn ngày và loại hoạt động');
                return;
            }
            
            // Định dạng ngày hiển thị
            const dateParts = date.split('-');
            const displayDate = `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}`;
            
            // Hiển thị thông tin trong modal
            document.getElementById('delete_confirm_date').textContent = displayDate;
            document.getElementById('delete_confirm_activity').textContent = activity;
            document.getElementById('delete_confirm_class').textContent = className;
            
            // Hiển thị modal
            const modal = new bootstrap.Modal(document.getElementById('deleteAttendanceModal'));
            modal.show();
        }

        // Xóa điểm danh theo ngày
        function deleteAttendanceDate() {
            const date = document.getElementById('attendance_date').value;
            const activity = document.getElementById('activity_type').value;
            const classId = document.getElementById('class_filter').value;
            
            // Đóng modal
            bootstrap.Modal.getInstance(document.getElementById('deleteAttendanceModal')).hide();
            
            // Hiển thị loading
            const loadingMsg = document.createElement('div');
            loadingMsg.className = 'alert alert-info';
            loadingMsg.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang xóa điểm danh...';
            document.querySelector('.card-body').prepend(loadingMsg);
            
            // Gửi request xóa
            $.ajax({
                url: 'hdt_attendance.php',
                method: 'POST',
                data: {
                    delete_attendance_date: 1,
                    delete_date: date,
                    delete_activity: activity,
                    delete_class: classId
                },
                dataType: 'json',
                success: function(result) {
                    loadingMsg.remove();
                    
                    if (result.status === 'success') {
                        showSuccessMessage(result.message);
                        // Tải lại trang sau 1.5 giây
                        setTimeout(() => {
                            loadAttendance();
                        }, 1500);
                    } else {
                        showErrorMessage(result.message);
                    }
                },
                error: function(xhr, status, error) {
                    loadingMsg.remove();
                    console.error('Lỗi:', error);
                    showErrorMessage('Có lỗi xảy ra khi xóa điểm danh: ' + error);
                }
            });
        }

        // Auto load khi thay đổi
        document.getElementById('attendance_date').addEventListener('change', loadAttendance);
        document.getElementById('activity_type').addEventListener('change', loadAttendance);
        document.getElementById('class_filter').addEventListener('change', loadAttendance);

        // Lọc theo tên HT (bỏ dấu, không phân biệt hoa thường)
        function normalizeVN(s){
            return s
                .normalize('NFD')
                .replace(/\p{Diacritic}/gu,'')
                .replace(/đ/g,'d').replace(/Đ/g,'D')
                .toLowerCase();
        }
        function filterByName(){
            const q = normalizeVN(document.getElementById('search_name').value.trim());
            const rows = document.querySelectorAll('.attendance-table tbody tr');
            rows.forEach(row => {
                const name = row.children[2]?.textContent || '';
                const saint = row.children[1]?.textContent || '';
                const haystack = normalizeVN(`${saint} ${name}`);
                row.style.display = q === '' || haystack.includes(q) ? '' : 'none';
            });
        }
        document.getElementById('search_name').addEventListener('keyup', function(e){
            if (e.key === 'Enter') filterByName();
        });

        // AJAX cho lưu điểm danh HDT
        $('#attendanceForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Hiển thị loading
            const submitBtn = $('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang lưu...');
            
            fetch('hdt_attendance.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Hiển thị thông báo thành công
                    showSuccessMessage(data.message);
                } else {
                    // Hiển thị thông báo lỗi
                    showErrorMessage(data.message);
                }
                
                // Khôi phục nút
                submitBtn.prop('disabled', false).html(originalText);
            })
            .catch(error => {
                console.error('Lỗi:', error);
                showErrorMessage('Lỗi khi lưu điểm danh HDT: ' + error.message);
                
                // Khôi phục nút
                submitBtn.prop('disabled', false).html(originalText);
            });
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
            
            $('main .container').prepend(alertHtml);
            
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
            
            $('main .container').prepend(alertHtml);
        }
    </script>
</body>
</html>
<?php ?>
