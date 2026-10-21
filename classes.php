<?php
session_start();
require_once 'login_redirect.php';
require_once 'db_connect.php';
require_once __DIR__ . '/includes/huynh_profile.php';

$logged_in = isset($_SESSION['user_id']);
$current_role = login_current_role();
$is_admin = login_is_admin($current_role);
$is_hdt_manager = login_is_hdt_manager($current_role);
$is_huynh_truong = login_is_huynh_truong($current_role);
$can_edit = $is_admin || $is_hdt_manager;

function cls_h($v) {
    return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

function cls_nganh_slug($nganh) {
    $map = array(
        'Chiên Con' => 'chien-con',
        'Ấu Nhi' => 'au-nhi',
        'Thiếu Nhi' => 'thieu-nhi',
        'Nghĩa Sĩ' => 'nghia-si',
        'Hiệp Sĩ' => 'hiep-si',
    );
    return isset($map[$nganh]) ? $map[$nganh] : 'default';
}

function formatTeacherList($huynh_truong) {
    if (empty($huynh_truong)) {
        return 'Chưa có';
    }
    $teachers = explode(' - ', $huynh_truong);
    $output = array();
    foreach ($teachers as $teacher) {
        $output[] = cls_h($teacher);
    }
    return implode('<br>', $output);
}

// Đảm bảo cột tạm nghỉ học tồn tại
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

// Xử lý chỉnh sửa lớp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['edit_class']) && !empty($_POST['class_id'])) {
    $class_id = (int)$_POST['class_id'];
    $ten_lop = $conn->real_escape_string($_POST['ten_lop']);
    $nganh = $conn->real_escape_string($_POST['nganh']);
    $ma_xac_nhan = isset($_POST['ma_xac_nhan']) ? $conn->real_escape_string($_POST['ma_xac_nhan']) : null;
    $huynh_truong = isset($_POST['huynh_truong']) ? array_filter($_POST['huynh_truong'], function($id) { return !empty($id); }) : array();
    $vai_tro = isset($_POST['vai_tro']) ? $_POST['vai_tro'] : array();

    $sql_update_class = "UPDATE classes SET ten_lop = '$ten_lop', nganh = '$nganh', ma_xac_nhan = " . ($ma_xac_nhan ? "'$ma_xac_nhan'" : "NULL") . " WHERE id = $class_id";
    if ($conn->query($sql_update_class)) {
        $conn->query("DELETE FROM class_teachers WHERE class_id = $class_id");
        foreach ($huynh_truong as $index => $teacher_id) {
            if (!empty($teacher_id) && isset($vai_tro[$index]) && in_array($vai_tro[$index], array('Trưởng Lớp', 'Phó Lớp'))) {
                $teacher_id = (int)$teacher_id;
                $vai_tro_val = $conn->real_escape_string($vai_tro[$index]);
                $conn->query("INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ($class_id, $teacher_id, '$vai_tro_val')");
            }
        }
        $_SESSION['success'] = 'Cập nhật lớp thành công';
    } else {
        $_SESSION['error'] = 'Lỗi khi cập nhật lớp: ' . $conn->error;
    }
    header('Location: classes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['add_class'])) {
    $ma_xac_nhan = !empty($_POST['ma_xac_nhan']) ? $conn->real_escape_string($_POST['ma_xac_nhan']) : null;
    $stmt = $conn->prepare('INSERT INTO classes (ten_lop, nganh, nien_khoa, ma_xac_nhan) VALUES (?, ?, ?, ?)');
    $nien_khoa = date('Y') . '-' . (date('Y') + 1);
    $stmt->bind_param('ssss', $_POST['ten_lop'], $_POST['nganh'], $nien_khoa, $ma_xac_nhan);
    $stmt->execute() ? $_SESSION['success'] = 'Thêm lớp thành công' : $_SESSION['error'] = 'Lỗi: ' . $conn->error;
    header('Location: classes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['delete_class'])) {
    $stmt = $conn->prepare('DELETE FROM classes WHERE id=?');
    $stmt->bind_param('i', $_POST['id']);
    $stmt->execute() ? $_SESSION['success'] = 'Xóa lớp thành công' : $_SESSION['error'] = 'Lỗi: ' . $conn->error;
    header('Location: classes.php');
    exit;
}

// Lớp được phép xếp đoàn sinh đi học lại: Admin/HDT mọi lớp, Trưởng/Phó chỉ lớp mình phụ trách
$resume_class_options = array();
if ($logged_in) {
    if ($can_edit) {
        $sql_resume_opts = "SELECT id, ten_lop, nganh FROM classes
                            ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop";
    } else {
        $uid_resume = (int)$_SESSION['user_id'];
        $sql_resume_opts = "SELECT DISTINCT c.id, c.ten_lop, c.nganh
                            FROM users u
                            INNER JOIN class_teachers ct ON ct.teacher_id = u.teacher_id
                            INNER JOIN classes c ON c.id = ct.class_id
                            WHERE u.id = $uid_resume AND u.teacher_id IS NOT NULL
                              AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Phó%')
                            ORDER BY FIELD(c.nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), c.ten_lop";
    }
    $res_resume_opts = $conn->query($sql_resume_opts);
    if ($res_resume_opts) {
        while ($row = $res_resume_opts->fetch_assoc()) {
            $resume_class_options[(int)$row['id']] = $row;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['resume_student'])) {
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $new_class_id = isset($_POST['new_class_id']) ? (int)$_POST['new_class_id'] : 0;
    $back = 'classes.php' . (!empty($_POST['student_search']) ? '?student_search=' . urlencode($_POST['student_search']) : '');

    if ($new_class_id <= 0 || !isset($resume_class_options[$new_class_id])) {
        $_SESSION['error'] = 'Vui lòng chọn lớp hợp lệ (bạn chỉ được xếp vào lớp mình phụ trách).';
        header('Location: ' . $back);
        exit;
    }
    $check_student = $conn->query("SELECT ho_ten, ten_thanh, leave_from FROM students WHERE id = $student_id AND status = 'on_leave'");
    if (!$check_student || $check_student->num_rows === 0) {
        $_SESSION['error'] = 'Không tìm thấy đoàn sinh đang tạm nghỉ.';
        header('Location: ' . $back);
        exit;
    }
    $student_row = $check_student->fetch_assoc();
    $student_name = trim(($student_row['ten_thanh'] ? $student_row['ten_thanh'] . ' ' : '') . $student_row['ho_ten']);
    $leave_days = 0;
    if (!empty($student_row['leave_from'])) {
        $leave_days = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($student_row['leave_from'])) / 86400));
    }
    if ($conn->query("UPDATE students SET status = 'active', class_id = $new_class_id, leave_from = NULL, updated_at = NOW() WHERE id = $student_id")) {
        $days_msg = $leave_days > 0 ? " (đã tạm nghỉ $leave_days ngày)" : '';
        $_SESSION['success'] = "Đã bật lại $student_name vào lớp " . $resume_class_options[$new_class_id]['ten_lop'] . $days_msg;
        header('Location: class_details.php?id=' . $new_class_id);
        exit;
    }
    $_SESSION['error'] = 'Lỗi đi học lại: ' . $conn->error;
    header('Location: ' . $back);
    exit;
}

$stats = array('Chiên Con' => 0, 'Ấu Nhi' => 0, 'Thiếu Nhi' => 0, 'Nghĩa Sĩ' => 0, 'Hiệp Sĩ' => 0);
$result_stats = $conn->query("SELECT nganh, COUNT(s.id) as si_so FROM classes c LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active' GROUP BY nganh");
if ($result_stats) {
    while ($row = $result_stats->fetch_assoc()) {
        $stats[$row['nganh']] = (int)$row['si_so'];
    }
}

$ordered_stats = array(
    'Chiên Con' => $stats['Chiên Con'],
    'Ấu Nhi' => $stats['Ấu Nhi'],
    'Thiếu Nhi' => $stats['Thiếu Nhi'],
    'Nghĩa Sĩ' => $stats['Nghĩa Sĩ'],
    'Hiệp Sĩ' => $stats['Hiệp Sĩ'],
);

$teachers_result = $conn->query('SELECT COUNT(*) as total FROM teachers');
$teachers_data = $teachers_result ? $teachers_result->fetch_assoc() : array('total' => 0);
$total_teachers = isset($teachers_data['total']) ? (int)$teachers_data['total'] : 0;

$students_result = $conn->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
$students_data = $students_result ? $students_result->fetch_assoc() : array('total' => 0);
$total_students = isset($students_data['total']) ? (int)$students_data['total'] : 0;

$total_classes = 0;
$total_nganh = 0;
$classes_by_nganh = $conn->query("SELECT nganh, COUNT(*) as total FROM classes GROUP BY nganh");
if ($classes_by_nganh) {
    while ($row = $classes_by_nganh->fetch_assoc()) {
        $total_classes += (int)$row['total'];
        if (trim((string)$row['nganh']) !== '') {
            $total_nganh++;
        }
    }
}

$all_teachers = array();
$res_teachers = $conn->query('SELECT id, ho_ten FROM teachers ORDER BY ho_ten');
if ($res_teachers) {
    while ($t = $res_teachers->fetch_assoc()) {
        $all_teachers[] = $t;
    }
}

$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$student_search = isset($_GET['student_search']) ? $conn->real_escape_string(trim($_GET['student_search'])) : '';
$filter_nganh = isset($_GET['nganh']) ? $conn->real_escape_string($_GET['nganh']) : '';
$valid_nganh = array('Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ');
if ($filter_nganh !== '' && !in_array($filter_nganh, $valid_nganh, true)) {
    $filter_nganh = '';
}

$search_query = '';
if ($search !== '') {
    $search_query = "WHERE (LOWER(c.ten_lop) LIKE LOWER('%$search%') OR EXISTS (SELECT 1 FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = c.id AND LOWER(t.ho_ten) LIKE LOWER('%$search%')))";
}
if ($filter_nganh !== '') {
    $search_query .= ($search_query === '' ? 'WHERE ' : ' AND ') . "c.nganh = '$filter_nganh'";
}

$student_results = array();
if ($student_search !== '') {
    $sql_student_search = "SELECT s.id, s.ho_ten, s.ten_thanh, s.status, s.leave_from, s.class_id,
                                  c.ten_lop, c.nganh
                           FROM students s
                           LEFT JOIN classes c ON s.class_id = c.id
                           WHERE (s.ho_ten LIKE '%$student_search%' OR s.ten_thanh LIKE '%$student_search%')
                             AND s.status IN ('active', 'on_leave')
                           ORDER BY s.ho_ten";
    $res_student_search = $conn->query($sql_student_search);
    if ($res_student_search) {
        while ($row = $res_student_search->fetch_assoc()) {
            if ($row['status'] === 'on_leave' && !empty($row['leave_from'])) {
                $row['leave_days'] = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($row['leave_from'])) / 86400));
            } else {
                $row['leave_days'] = 0;
            }
            $student_results[] = $row;
        }
    }
}


$classes_list = array();
$res_classes = $conn->query("SELECT c.id, c.ten_lop, c.nganh, c.ma_xac_nhan,
    (SELECT COUNT(*) FROM students WHERE class_id = c.id AND status = 'active') as si_so,
    (SELECT GROUP_CONCAT(CONCAT(t.ho_ten, ' (', ct.vai_tro, ')') SEPARATOR ' - ')
     FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = c.id) as huynh_truong
    FROM classes c $search_query
    ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $classes_list[] = $row;
    }
}

$search_mode = ($student_search !== '') ? 'student' : 'class';
$year_nav_url = $is_huynh_truong ? 'nien_hoc_xem.php' : 'nien_hoc.php';

// Lối tắt khi đã đăng nhập: ưu tiên lớp tài khoản đang phụ trách
$my_assigned = $logged_in ? login_user_assigned_classes($conn) : array();
$my_class_id = !empty($my_assigned) ? (int)$my_assigned[0]['id'] : 0;
$my_class_name = !empty($my_assigned) ? (string)$my_assigned[0]['ten_lop'] : '';
$today_qr = date('Y-m-d');
// Admin/HDT không gắn lớp → chọn lớp trong popup
$need_class_pick = $logged_in && $my_class_id <= 0 && $can_edit;
$pick_classes = array();
if ($need_class_pick) {
    $res_pick = $conn->query('SELECT id, ten_lop, nganh FROM classes ORDER BY ' . login_nganh_field_sql('nganh') . ', ten_lop');
    if ($res_pick) {
        while ($row = $res_pick->fetch_assoc()) {
            $pick_classes[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đoàn sinh — ĐOÀN TNTT Đaminh Savio Lập Trí</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --cls-primary: #1565c0;
            --cls-primary-2: #42a5f5;
            --cls-text: #1a237e;
            --cls-muted: #607d8b;
            --cls-border: #e3eef6;
            --cls-bg: #f4f8fb;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(160deg, #e8f4fc 0%, var(--cls-bg) 45%, #e3f2fd 100%);
            min-height: 100vh;
            color: #263238;
        }
        .cls-topbar {
            background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5);
            color: #fff;
            padding: .65rem 0 0;
            box-shadow: 0 8px 24px rgba(13,71,161,.22);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .cls-topbar-shell { padding-bottom: .65rem; }
        .cls-topbar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            min-height: 48px;
        }
        .cls-brand {
            display: flex;
            align-items: center;
            gap: .65rem;
            color: #fff;
            text-decoration: none;
            min-width: 0;
            flex: 1;
        }
        .cls-brand:hover { color: #fff; opacity: .95; }
        .cls-brand img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .cls-brand-text {
            font-weight: 800;
            font-size: .95rem;
            line-height: 1.25;
            min-width: 0;
        }
        .cls-brand-text .sub {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            opacity: .85;
            margin-top: .1rem;
        }
        .cls-menu-btn {
            flex-shrink: 0;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 12px;
            background: rgba(255,255,255,.16);
            color: #fff;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }
        .cls-menu-btn:hover { background: rgba(255,255,255,.28); }
        .cls-menu-btn[aria-expanded="true"] { background: rgba(255,255,255,.28); }

        .cls-nav-panel { width: 100%; }
        .cls-nav {
            display: flex;
            align-items: center;
            gap: .2rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .cls-nav-link {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: rgba(255,255,255,.92);
            text-decoration: none !important;
            font-weight: 700;
            font-size: .88rem;
            padding: .45rem .75rem;
            border-radius: 999px;
            border: none !important;
            white-space: nowrap;
            transition: background .15s, color .15s;
        }
        .cls-nav-link:hover { color: #fff; background: rgba(255,255,255,.14); }
        .cls-nav-link.active { background: rgba(255,255,255,.22); color: #fff; }
        .cls-nav-link i { width: 1.1rem; text-align: center; opacity: .9; }

        @media (min-width: 992px) {
            .cls-topbar { padding: .75rem 0; }
            .cls-topbar-shell {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding-bottom: 0;
            }
            .cls-topbar-head { flex: 0 1 auto; min-width: 0; }
            .cls-brand { flex: 0 1 auto; }
            .cls-nav-panel {
                display: block !important;
                height: auto !important;
                visibility: visible !important;
                flex: 0 1 auto;
            }
            .cls-nav { justify-content: flex-end; flex-wrap: wrap; }
        }
        @media (max-width: 991px) {
            .cls-nav-panel {
                border-top: 1px solid rgba(255,255,255,.14);
                margin-top: .5rem;
                padding-top: .35rem;
            }
            .cls-nav-panel:not(.show) { border-top-color: transparent; margin-top: 0; padding-top: 0; }
            .cls-nav {
                flex-direction: column;
                align-items: stretch;
                gap: .15rem;
                padding-bottom: .25rem;
            }
            .cls-nav-link {
                width: 100%;
                border-radius: .75rem;
                padding: .7rem .85rem;
                white-space: normal;
            }
            .cls-brand-text { font-size: .88rem; }
            .cls-brand img { width: 36px; height: 36px; }
        }

        .page-hero {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 14px 40px rgba(21,101,192,.1);
            border: 1px solid rgba(21,101,192,.06);
            padding: 1.35rem 1.5rem;
            margin-bottom: 1.25rem;
        }
        .page-hero h1 {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--cls-text);
            margin: 0 0 .25rem;
        }
        .page-hero p { margin: 0; color: var(--cls-muted); font-weight: 600; font-size: .92rem; }

        .stat-card {
            background: #fff;
            border: 1px solid var(--cls-border);
            border-radius: 1rem;
            padding: 1rem;
            height: 100%;
            box-shadow: 0 4px 16px rgba(21,101,192,.06);
            display: flex;
            align-items: center;
            gap: .85rem;
        }
        .stat-card .ico {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .stat-card .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--cls-muted); font-weight: 700; }
        .stat-card .val { font-size: 1.35rem; font-weight: 800; color: var(--cls-text); line-height: 1.1; }
        a.stat-card {
            text-decoration: none;
            color: inherit;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
            cursor: pointer;
        }
        a.stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(21,101,192,.12);
            border-color: #90caf9;
        }
        a.stat-card .val { font-size: 1.05rem; }

        .nganh-scroll {
            display: flex;
            gap: .35rem;
            flex-wrap: wrap;
            overflow-x: visible;
            padding-bottom: .15rem;
        }
        .nganh-scroll::-webkit-scrollbar { display: none; }
        .nganh-chip {
            flex-shrink: 1;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .32rem .62rem;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--cls-border);
            background: #fff;
            color: #455a64;
            transition: transform .15s, box-shadow .15s;
            white-space: nowrap;
        }
        .nganh-chip:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.08); color: #263238; }
        .nganh-chip.active { background: linear-gradient(135deg, #1565c0, #1e88e5); color: #fff; border-color: transparent; }
        .nganh-chip .cnt {
            background: rgba(0,0,0,.08);
            border-radius: 999px;
            padding: .06rem .32rem;
            font-size: .68rem;
        }
        .nganh-chip.active .cnt { background: rgba(255,255,255,.25); }

        .panel {
            background: #fff;
            border-radius: 1.15rem;
            box-shadow: 0 10px 30px rgba(21,101,192,.07);
            border: 1px solid rgba(21,101,192,.05);
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .panel-head {
            background: linear-gradient(135deg, #1565c0, #42a5f5);
            color: #fff;
            padding: 1rem 1.25rem;
            font-weight: 800;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem;
        }
        .panel-body { padding: 1.25rem; }
        .panel-body-compact { padding: 1rem 1.15rem; overflow: visible; }

        .filter-search-block {
            margin-top: 1.28rem;
            padding-top: 1.28rem;
            border-top: 1px dashed #dce7f2;
        }

        .search-tabs {
            display: inline-flex;
            gap: .25rem;
            margin-bottom: 0;
            flex-wrap: nowrap;
            flex-shrink: 0;
        }
        .search-tab {
            border: none;
            border-radius: 999px;
            padding: .32rem .72rem;
            font-weight: 700;
            font-size: .78rem;
            background: #f0f4f8;
            color: #546e7a;
            white-space: nowrap;
        }
        .search-tab.active {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            color: #fff;
        }
        .search-pane { display: none; }
        .search-pane.active {
            display: block;
            flex: 1;
            min-width: 0;
        }

        .search-inline {
            display: flex;
            align-items: center;
            gap: .45rem;
            flex: 1;
            min-width: 0;
        }
        .search-inline .form-control {
            flex: 9 1 0;
            min-width: 0;
            max-width: 90%;
            padding: .42rem .75rem;
            font-size: .86rem;
            border-radius: 999px;
        }
        .search-inline .btn-cls {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            min-width: 5.25rem;
            padding: .42rem 1.2rem;
            font-size: .82rem;
            border-radius: 999px;
            justify-content: center;
        }
        .search-inline .btn-cls i { margin: 0 !important; }
        .search-inline .btn-text { display: none; }

        .filter-search-row {
            display: flex;
            align-items: center;
            gap: .55rem;
            flex-wrap: wrap;
        }

        @media (min-width: 576px) {
            .search-inline .btn-text { display: inline; }
        }

        @media (max-width: 575px) {
            .filter-search-row {
                flex-direction: column;
                align-items: stretch;
            }
            .search-tabs {
                width: 100%;
                justify-content: center;
            }
            .search-pane.active {
                width: 100%;
            }
        }

        .form-control, .form-select {
            border-radius: .75rem;
            border-color: #d6e4f0;
            font-weight: 600;
        }
        .btn-cls {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            border: none;
            border-radius: 999px;
            padding: .55rem 1.25rem;
            font-weight: 800;
            color: #fff;
        }
        .btn-cls:hover { filter: brightness(1.05); color: #fff; }
        .btn-cls-outline {
            border: 2px solid #1565c0;
            color: #1565c0;
            background: #fff;
            border-radius: 999px;
            padding: .5rem 1.1rem;
            font-weight: 800;
        }
        .btn-cls-outline:hover { background: #e3f2fd; color: #0d47a1; }

        .badge-nganh {
            display: inline-block;
            padding: .32em .72em;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 800;
            border: 1px solid transparent;
        }
        .badge-nganh.chien-con { background: #ffcdd2; color: #b71c1c; border-color: #ef9a9a; }
        .badge-nganh.au-nhi { background: #c8e6c9; color: #1b5e20; border-color: #81c784; }
        .badge-nganh.thieu-nhi { background: #bbdefb; color: #0d47a1; border-color: #64b5f6; }
        .badge-nganh.nghia-si { background: #ffe082; color: #e65100; border-color: #ffca28; }
        .badge-nganh.hiep-si { background: #d7ccc8; color: #4e342e; border-color: #a1887f; }

        /* Desktop table — mỗi hàng như một card riêng */
        .cls-table-wrap { display: none; }
        .cls-table-wrap .table-responsive {
            padding: .15rem .25rem .5rem;
        }
        .cls-table {
            margin: 0;
            font-size: .92rem;
            border-collapse: separate;
            border-spacing: 0 .62rem;
        }
        .cls-table thead th {
            background: #f8fbff;
            color: var(--cls-text);
            font-weight: 800;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            border-bottom: 2px solid var(--cls-border);
            padding: .75rem .85rem .85rem;
            white-space: nowrap;
        }
        .cls-table tbody td {
            padding: .95rem .85rem;
            vertical-align: middle;
            background: inherit;
            border-top: 1px solid rgba(21, 101, 192, .1);
            border-bottom: 1px solid rgba(21, 101, 192, .1);
        }
        .cls-table tbody td:first-child {
            border-left: 1px solid rgba(21, 101, 192, .1);
            border-radius: .85rem 0 0 .85rem;
        }
        .cls-table tbody td:last-child {
            border-right: 1px solid rgba(21, 101, 192, .1);
            border-radius: 0 .85rem .85rem 0;
        }
        .cls-table tbody tr.cls-row-clickable {
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
            box-shadow: 0 3px 10px rgba(21, 101, 192, .07);
        }
        .cls-table tbody tr.cls-row-clickable:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(21, 101, 192, .12);
        }
        .cls-table tbody tr.cls-row-clickable.chien-con {
            background: #fff5f6;
            box-shadow: 0 3px 10px rgba(239, 83, 80, .1);
        }
        .cls-table tbody tr.cls-row-clickable.chien-con td {
            border-color: rgba(239, 83, 80, .22);
        }
        .cls-table tbody tr.cls-row-clickable.au-nhi {
            background: #f4fbf4;
            box-shadow: 0 3px 10px rgba(102, 187, 106, .1);
        }
        .cls-table tbody tr.cls-row-clickable.au-nhi td {
            border-color: rgba(102, 187, 106, .24);
        }
        .cls-table tbody tr.cls-row-clickable.thieu-nhi {
            background: #f3f8ff;
            box-shadow: 0 3px 10px rgba(66, 165, 245, .1);
        }
        .cls-table tbody tr.cls-row-clickable.thieu-nhi td {
            border-color: rgba(66, 165, 245, .24);
        }
        .cls-table tbody tr.cls-row-clickable.nghia-si {
            background: #fffbf0;
            box-shadow: 0 3px 10px rgba(255, 167, 38, .1);
        }
        .cls-table tbody tr.cls-row-clickable.nghia-si td {
            border-color: rgba(255, 167, 38, .26);
        }
        .cls-table tbody tr.cls-row-clickable.hiep-si {
            background: #faf6f4;
            box-shadow: 0 3px 10px rgba(141, 110, 99, .1);
        }
        .cls-table tbody tr.cls-row-clickable.hiep-si td {
            border-color: rgba(141, 110, 99, .24);
        }
        .cls-table tbody tr.cls-row-clickable.chien-con:hover { background: #ffebee; }
        .cls-table tbody tr.cls-row-clickable.au-nhi:hover { background: #e8f5e9; }
        .cls-table tbody tr.cls-row-clickable.thieu-nhi:hover { background: #e3f2fd; }
        .cls-table tbody tr.cls-row-clickable.nghia-si:hover { background: #fff8e1; }
        .cls-table tbody tr.cls-row-clickable.hiep-si:hover { background: #efebe9; }
        .cls-name {
            font-weight: 800;
            color: var(--cls-text);
        }
        .cls-row-clickable:hover .cls-name { color: var(--cls-primary); }
        .cls-link {
            font-weight: 800;
            color: var(--cls-primary);
            text-decoration: none;
        }
        .cls-link:hover { color: #0d47a1; text-decoration: underline; }

        /* Mobile cards */
        .cls-cards {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding-bottom: .25rem;
        }
        .cls-card {
            display: block;
            border: 1.5px solid rgba(21, 101, 192, .12);
            border-radius: 1rem;
            padding: 1rem 1.15rem;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 4px 14px rgba(21, 101, 192, .08);
            transition: transform .15s, box-shadow .15s, border-color .15s;
            cursor: pointer;
        }
        .cls-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(21, 101, 192, .14);
            color: inherit;
        }
        .cls-card.chien-con {
            background: #fff5f6;
            border-color: rgba(239, 83, 80, .28);
            box-shadow: 0 4px 14px rgba(239, 83, 80, .12);
        }
        .cls-card.au-nhi {
            background: #f4fbf4;
            border-color: rgba(102, 187, 106, .3);
            box-shadow: 0 4px 14px rgba(102, 187, 106, .12);
        }
        .cls-card.thieu-nhi {
            background: #f3f8ff;
            border-color: rgba(66, 165, 245, .3);
            box-shadow: 0 4px 14px rgba(66, 165, 245, .12);
        }
        .cls-card.nghia-si {
            background: #fffbf0;
            border-color: rgba(255, 167, 38, .32);
            box-shadow: 0 4px 14px rgba(255, 167, 38, .12);
        }
        .cls-card.hiep-si {
            background: #faf6f4;
            border-color: rgba(141, 110, 99, .3);
            box-shadow: 0 4px 14px rgba(141, 110, 99, .12);
        }
        .cls-card.chien-con:hover { background: #ffebee; box-shadow: 0 10px 24px rgba(239, 83, 80, .16); }
        .cls-card.au-nhi:hover { background: #e8f5e9; box-shadow: 0 10px 24px rgba(102, 187, 106, .16); }
        .cls-card.thieu-nhi:hover { background: #e3f2fd; box-shadow: 0 10px 24px rgba(66, 165, 245, .16); }
        .cls-card.nghia-si:hover { background: #fff8e1; box-shadow: 0 10px 24px rgba(255, 167, 38, .16); }
        .cls-card.hiep-si:hover { background: #efebe9; box-shadow: 0 10px 24px rgba(141, 110, 99, .16); }
        .cls-card-actions {
            display: flex;
            gap: .4rem;
            margin-top: .85rem;
            padding-top: .85rem;
            border-top: 1px dashed rgba(21, 101, 192, .18);
        }
        .cls-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: .5rem;
            margin-bottom: .5rem;
        }
        .cls-card-name { font-weight: 800; font-size: 1.05rem; color: var(--cls-text); }
        .cls-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .65rem;
            font-size: .85rem;
            color: var(--cls-muted);
            font-weight: 600;
        }
        .cls-card-meta span i { color: var(--cls-primary); margin-right: .25rem; width: 1rem; text-align: center; }
        .cls-card-actions .btn { border-radius: 999px; font-weight: 700; font-size: .82rem; }

        .student-card {
            background: #f8fbff;
            border: 1px solid var(--cls-border);
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: .65rem;
        }
        .student-card.on-leave { background: #fffde7; border-color: #fff59d; }
        .student-card-name { font-weight: 800; color: var(--cls-text); font-size: 1rem; }
        .student-card-saint { color: var(--cls-muted); font-size: .88rem; font-weight: 600; }

        .chart-box {
            background: #f8fbff;
            border: 1px solid var(--cls-border);
            border-radius: 1rem;
            padding: 1rem;
            height: 100%;
        }
        .chart-box canvas { max-height: 220px; }

        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--cls-muted);
        }
        .empty-state i { font-size: 2.5rem; opacity: .35; margin-bottom: .75rem; }

        .modal-content { border: none; border-radius: 1.15rem; overflow: hidden; }
        .modal-header {
            background: linear-gradient(135deg, #1565c0, #42a5f5);
            color: #fff;
            border: none;
        }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .teacher-row {
            background: #f8fbff;
            border: 1px solid var(--cls-border);
            border-radius: .75rem;
            padding: .65rem;
            margin-bottom: .5rem;
        }

        @media (min-width: 992px) {
            .cls-table-wrap { display: block; }
            .cls-cards { display: none; }
        }
        @media (max-width: 991px) {
            .page-hero h1 { font-size: 1.2rem; }
            .stat-card .val { font-size: 1.15rem; }
            .panel-head { font-size: .95rem; }
        }
        @media (max-width: 576px) {
            .panel-body { padding: 1rem; }
        }
    </style>
</head>
<body>

<div class="cls-topbar">
    <div class="container cls-topbar-shell">
        <div class="cls-topbar-head">
            <a class="cls-brand" href="index.php">
                <img src="img/logo.png" alt="Logo Đoàn">
                <span class="cls-brand-text">
                    <span class="d-none d-sm-inline">ĐOÀN TNTT Đaminh Savio Lập Trí</span>
                    <span class="d-inline d-sm-none">TNTT Lập Trí</span>
                    <span class="sub d-none d-md-block">Quản lý đoàn sinh</span>
                </span>
            </a>
            <?php if ($is_admin): ?>
                <span class="badge bg-danger d-none d-lg-inline-flex align-self-center"><i class="fas fa-crown me-1"></i>Admin</span>
            <?php elseif ($is_hdt_manager): ?>
                <span class="badge bg-warning text-dark d-none d-lg-inline-flex align-self-center"><i class="fas fa-user-shield me-1"></i>HDT</span>
            <?php endif; ?>
            <button type="button" class="cls-menu-btn d-lg-none" data-bs-toggle="collapse" data-bs-target="#clsNavMenu" aria-expanded="false" aria-controls="clsNavMenu" aria-label="Mở menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="collapse cls-nav-panel d-lg-block" id="clsNavMenu">
            <nav class="cls-nav" aria-label="Menu chính">
                <a href="index.php" class="cls-nav-link"><i class="fas fa-home"></i><span>Trang chủ</span></a>
                <a href="classes.php" class="cls-nav-link active"><i class="fas fa-users"></i><span>Đoàn sinh</span></a>
                <?php if ($logged_in): ?>
                    <a href="phieu_ra_cong.php" class="cls-nav-link"><i class="fas fa-door-open"></i><span>Phiếu ra cổng</span></a>
                <?php endif; ?>
                <?php if ($logged_in && ($is_hdt_manager || $is_admin)): ?>
                    <a href="diemdanh.php" class="cls-nav-link"><i class="fas fa-clipboard-check"></i><span>Điểm danh</span></a>
                    <a href="quan_ly_tai_khoan.php" class="cls-nav-link"><i class="fas fa-user-cog"></i><span>Tài khoản HT</span></a>
                <?php endif; ?>
                <?php if ($logged_in && login_can_view_student_report($current_role)): ?>
                    <a href="bao_cao_doan_sinh.php" class="cls-nav-link"><i class="fas fa-file-lines"></i><span>Báo cáo</span></a>
                <?php endif; ?>
                <a href="loichua.php" class="cls-nav-link"><i class="fas fa-bible"></i><span>Lời Chúa</span></a>
                <?php if ($logged_in): ?>
                    <a href="tai_khoan.php" class="cls-nav-link"><i class="fas fa-id-card"></i><span>Tài khoản</span></a>
                    <a href="logout.php" class="cls-nav-link"><i class="fas fa-sign-out-alt"></i><span>Đăng xuất</span></a>
                <?php else: ?>
                    <a href="<?php echo cls_h(login_page_url()); ?>" class="cls-nav-link"><i class="fas fa-sign-in-alt"></i><span>Đăng nhập</span></a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</div>

<main class="container py-3 py-md-4">

    <div class="page-hero">
        <h1><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Quản lý đoàn sinh</h1>
        <p>Danh sách lớp, sĩ số và tìm kiếm đoàn sinh toàn Đoàn</p>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="fas fa-check-circle me-2"></i><?php echo cls_h($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo cls_h($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($logged_in): ?>
    <!-- Lối tắt nhanh (đã đăng nhập) -->
    <div class="row g-3 mb-3">
        <?php
        $shortcuts = array(
            array('qr', 'fa-qrcode', '#e3f2fd', '#1565c0', 'Điểm danh', 'Quét QR',
                  $my_class_id > 0 ? 'diem_danh_qr.php?class_id=' . $my_class_id . '&ngay=' . urlencode($today_qr) : ''),
            array('so', 'fa-clipboard-list', '#fff3e0', '#ef6c00', 'Điểm danh', 'Điểm danh sổ',
                  $my_class_id > 0 ? 'class_details.php?id=' . $my_class_id . '&panel=diemdanh' : ''),
            array('', 'fa-door-open', '#e8f5e9', '#2e7d32', 'Xin phép', 'Phiếu ra cổng', 'phieu_ra_cong.php'),
            array('lop', 'fa-chalkboard-teacher', '#e8eaf6', '#3949ab',
                  $my_class_id > 0 ? 'Lớp của tôi' : 'Lớp học',
                  $my_class_id > 0 ? $my_class_name : 'Vào lớp',
                  $my_class_id > 0 ? 'class_details.php?id=' . $my_class_id : ''),
        );
        ?>
        <?php foreach ($shortcuts as $sc): ?>
            <?php list($sc_mode, $sc_icon, $sc_bg, $sc_color, $sc_lbl, $sc_val, $sc_url) = $sc; ?>
            <div class="col-6 col-lg-3">
                <?php if ($sc_url !== ''): ?>
                    <a class="stat-card" href="<?php echo cls_h($sc_url); ?>" title="<?php echo cls_h($sc_val); ?>">
                <?php elseif ($need_class_pick): ?>
                    <a class="stat-card" href="#" role="button" data-bs-toggle="modal" data-bs-target="#pickClassModal" data-pick-mode="<?php echo cls_h($sc_mode); ?>">
                <?php else: ?>
                    <a class="stat-card" href="classes.php" title="Tài khoản chưa được gắn lớp phụ trách">
                <?php endif; ?>
                    <div class="ico" style="background:<?php echo $sc_bg; ?>;color:<?php echo $sc_color; ?>"><i class="fas <?php echo $sc_icon; ?>"></i></div>
                    <div><div class="lbl"><?php echo cls_h($sc_lbl); ?></div><div class="val"><?php echo cls_h($sc_val); ?></div></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <!-- Thống kê -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="ico" style="background:#e3f2fd;color:#1565c0"><i class="fas fa-users"></i></div>
                <div><div class="lbl">Đoàn sinh</div><div class="val"><?php echo $total_students; ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="ico" style="background:#fff3e0;color:#ef6c00"><i class="fas fa-user-tie"></i></div>
                <div><div class="lbl">Huynh trưởng</div><div class="val"><?php echo $total_teachers; ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="ico" style="background:#e8f5e9;color:#2e7d32"><i class="fas fa-door-open"></i></div>
                <div><div class="lbl">Lớp học</div><div class="val"><?php echo $total_classes; ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="ico" style="background:#f3e5f5;color:#6a1b9a"><i class="fas fa-layer-group"></i></div>
                <div><div class="lbl">Ngành</div><div class="val"><?php echo $total_nganh; ?></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="<?php echo $logged_in ? 'col-12' : 'col-lg-7'; ?>">
            <div class="panel h-100 mb-0">
                <div class="panel-head">
                    <span><i class="fas fa-filter me-2"></i>Lọc &amp; tìm kiếm</span>
                    <?php if ($can_edit): ?>
                        <button class="btn btn-light btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addClassModal">
                            <i class="fas fa-plus me-1"></i>Thêm lớp
                        </button>
                    <?php endif; ?>
                </div>
                <div class="panel-body panel-body-compact">
                    <div class="nganh-scroll">
                        <?php
                        $chip_qs = array();
                        if ($search !== '') { $chip_qs['search'] = $search; }
                        if ($student_search !== '') { $chip_qs['student_search'] = $student_search; }
                        $chip_all_url = 'classes.php' . (empty($chip_qs) ? '' : '?' . http_build_query($chip_qs));
                        ?>
                        <a href="<?php echo cls_h($chip_all_url); ?>" class="nganh-chip <?php echo $filter_nganh === '' ? 'active' : ''; ?>">
                            Tất cả <span class="cnt"><?php echo $total_students; ?></span>
                        </a>
                        <?php foreach ($ordered_stats as $nganh_name => $nganh_count): ?>
                            <?php
                            $chip_nganh_qs = $chip_qs;
                            $chip_nganh_qs['nganh'] = $nganh_name;
                            $chip_nganh_url = 'classes.php?' . http_build_query($chip_nganh_qs);
                            ?>
                            <a href="<?php echo cls_h($chip_nganh_url); ?>"
                               class="nganh-chip <?php echo $filter_nganh === $nganh_name ? 'active' : ''; ?>">
                                <?php echo cls_h($nganh_name); ?>
                                <span class="cnt"><?php echo (int)$nganh_count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-search-block">
                        <div class="filter-search-row">
                            <div class="search-tabs">
                                <button type="button" class="search-tab <?php echo $search_mode === 'class' ? 'active' : ''; ?>" data-target="pane-class">
                                    <i class="fas fa-chalkboard me-1"></i>Lớp
                                </button>
                                <button type="button" class="search-tab <?php echo $search_mode === 'student' ? 'active' : ''; ?>" data-target="pane-student">
                                    <i class="fas fa-user-graduate me-1"></i>Đoàn sinh
                                </button>
                            </div>

                            <div id="pane-class" class="search-pane <?php echo $search_mode === 'class' ? 'active' : ''; ?>">
                                <form method="GET" class="search-inline">
                                    <?php if ($filter_nganh !== ''): ?>
                                        <input type="hidden" name="nganh" value="<?php echo cls_h($filter_nganh); ?>">
                                    <?php endif; ?>
                                    <input type="text" name="search" class="form-control" placeholder="Tên lớp hoặc huynh trưởng..." value="<?php echo cls_h($search); ?>">
                                    <button class="btn btn-cls" type="submit" aria-label="Tìm lớp">
                                        <i class="fas fa-search"></i><span class="btn-text ms-1">Tìm</span>
                                    </button>
                                </form>
                            </div>

                            <div id="pane-student" class="search-pane <?php echo $search_mode === 'student' ? 'active' : ''; ?>">
                                <form method="GET" class="search-inline">
                                    <?php if ($filter_nganh !== ''): ?>
                                        <input type="hidden" name="nganh" value="<?php echo cls_h($filter_nganh); ?>">
                                    <?php endif; ?>
                                    <?php if ($search !== ''): ?>
                                        <input type="hidden" name="search" value="<?php echo cls_h($search); ?>">
                                    <?php endif; ?>
                                    <input type="text" name="student_search" class="form-control" placeholder="Tên hoặc tên thánh đoàn sinh..." value="<?php echo cls_h($student_search); ?>">
                                    <button class="btn btn-cls" type="submit" aria-label="Tìm đoàn sinh">
                                        <i class="fas fa-search"></i><span class="btn-text ms-1">Tìm</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!$logged_in): ?>
        <div class="col-lg-5">
            <div class="chart-box h-100">
                <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.04em">Sĩ số theo ngành</div>
                <canvas id="statsChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Kết quả tìm đoàn sinh -->
    <?php if ($student_search !== ''): ?>
    <div class="panel">
        <div class="panel-head">
            <span><i class="fas fa-user-graduate me-2"></i>Kết quả tìm đoàn sinh (<?php echo count($student_results); ?>)</span>
        </div>
        <div class="panel-body">
            <?php if (empty($student_results)): ?>
                <div class="empty-state">
                    <div><i class="fas fa-search"></i></div>
                    <p class="mb-0 fw-bold">Không tìm thấy đoàn sinh phù hợp</p>
                </div>
            <?php else: ?>
                <?php foreach ($student_results as $sr): ?>
                    <?php $is_on_leave = ($sr['status'] === 'on_leave'); ?>
                    <div class="student-card <?php echo $is_on_leave ? 'on-leave' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <div class="student-card-name"><?php echo cls_h($sr['ho_ten']); ?></div>
                                <div class="student-card-saint">
                                    <i class="fas fa-cross me-1"></i><?php echo cls_h($sr['ten_thanh'] ? $sr['ten_thanh'] : '—'); ?>
                                </div>
                            </div>
                            <?php if ($is_on_leave): ?>
                                <span class="badge bg-warning text-dark fw-bold">Tạm nghỉ <?php echo (int)$sr['leave_days']; ?> ngày</span>
                            <?php elseif (!empty($sr['class_id'])): ?>
                                <span class="badge-nganh <?php echo cls_nganh_slug($sr['nganh']); ?>"><?php echo cls_h($sr['ten_lop']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="mt-2 small text-muted fw-semibold">
                            <?php if ($is_on_leave && !empty($sr['leave_from'])): ?>
                                Từ ngày <?php echo date('d/m/Y', strtotime($sr['leave_from'])); ?>
                            <?php elseif (!empty($sr['class_id'])): ?>
                                <?php echo cls_h($sr['nganh']); ?>
                            <?php else: ?>
                                Chưa có lớp
                            <?php endif; ?>
                        </div>
                        <div class="mt-2">
                            <?php if ($is_on_leave && $logged_in && !empty($resume_class_options)): ?>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#resumeStudentModal"
                                    data-id="<?php echo (int)$sr['id']; ?>"
                                    data-name="<?php echo cls_h(trim(($sr['ten_thanh'] ? $sr['ten_thanh'] . ' ' : '') . $sr['ho_ten'])); ?>"
                                    data-days="<?php echo (int)$sr['leave_days']; ?>">
                                    <i class="fas fa-play me-1"></i>Đi học lại
                                </button>
                            <?php elseif ($is_on_leave && $logged_in): ?>
                                <span class="small text-muted">Liên hệ Trưởng lớp / HDT để xếp lớp đi học lại.</span>
                            <?php elseif (!empty($sr['class_id'])): ?>
                                <a href="class_details.php?id=<?php echo (int)$sr['class_id']; ?>&search=<?php echo urlencode($sr['ho_ten']); ?>" class="btn btn-sm btn-cls">
                                    <i class="fas fa-eye me-1"></i>Xem lớp
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Danh sách lớp -->
    <div class="panel">
        <div class="panel-head">
            <span><i class="fas fa-list me-2"></i>Danh sách lớp (<?php echo count($classes_list); ?>)</span>
            <?php if ($search !== '' || $filter_nganh !== ''): ?>
                <a href="classes.php" class="btn btn-light btn-sm fw-bold"><i class="fas fa-times me-1"></i>Xóa lọc</a>
            <?php endif; ?>
        </div>
        <div class="panel-body p-0 p-md-3">
            <?php if (empty($classes_list)): ?>
                <div class="empty-state">
                    <div><i class="fas fa-inbox"></i></div>
                    <p class="mb-0 fw-bold">Không có lớp nào phù hợp</p>
                </div>
            <?php else: ?>

            <!-- Mobile cards -->
            <div class="cls-cards px-2 px-md-0 pb-2">
                <?php $stt = 1; foreach ($classes_list as $row): ?>
                    <?php $slug = cls_nganh_slug($row['nganh']); ?>
                    <div class="cls-card <?php echo cls_h($slug); ?>" data-href="class_details.php?id=<?php echo (int)$row['id']; ?>" role="link" tabindex="0">
                        <div class="cls-card-top">
                            <div>
                                <div class="cls-card-name">
                                    <span class="cls-name"><?php echo cls_h($row['ten_lop']); ?></span>
                                </div>
                                <span class="badge-nganh <?php echo cls_h($slug); ?> mt-1"><?php echo cls_h($row['nganh']); ?></span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-primary" style="font-size:1.25rem"><?php echo (int)$row['si_so']; ?></div>
                                <div class="small text-muted fw-bold">sĩ số</div>
                            </div>
                        </div>
                        <div class="cls-card-meta">
                            <span><i class="fas fa-user-tie"></i><?php echo formatTeacherList($row['huynh_truong']); ?></span>
                        </div>
                        <?php if ($can_edit): ?>
                        <div class="cls-card-actions cls-no-nav">
                            <button type="button" class="btn btn-sm btn-cls btn-edit-class flex-fill"
                                data-id="<?php echo (int)$row['id']; ?>"
                                data-ten-lop="<?php echo cls_h($row['ten_lop']); ?>"
                                data-nganh="<?php echo cls_h($row['nganh']); ?>"
                                data-ma-xac-nhan="<?php echo cls_h(isset($row['ma_xac_nhan']) ? $row['ma_xac_nhan'] : ''); ?>"
                                data-bs-toggle="modal" data-bs-target="#editClassModal">
                                <i class="fas fa-edit me-1"></i>Sửa
                            </button>
                            <form method="POST" class="d-inline flex-fill" onsubmit="return confirm('Xác nhận xóa lớp này?');">
                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" name="delete_class" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="fas fa-trash me-1"></i>Xóa
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Desktop table -->
            <div class="cls-table-wrap">
                <div class="table-responsive">
                    <table class="table cls-table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên lớp</th>
                                <th>Ngành</th>
                                <th>Sĩ số</th>
                                <th>Huynh trưởng</th>
                                <?php if ($can_edit): ?><th class="text-end">Thao tác</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $stt = 1; foreach ($classes_list as $row): ?>
                                <?php $slug = cls_nganh_slug($row['nganh']); ?>
                                <tr class="cls-row-clickable <?php echo cls_h($slug); ?>" data-href="class_details.php?id=<?php echo (int)$row['id']; ?>">
                                    <td class="text-muted fw-bold"><?php echo $stt++; ?></td>
                                    <td><span class="cls-name"><?php echo cls_h($row['ten_lop']); ?></span></td>
                                    <td><span class="badge-nganh <?php echo cls_h($slug); ?>"><?php echo cls_h($row['nganh']); ?></span></td>
                                    <td><strong><?php echo (int)$row['si_so']; ?></strong></td>
                                    <td class="small"><?php echo formatTeacherList($row['huynh_truong']); ?></td>
                                    <?php if ($can_edit): ?>
                                    <td class="text-end text-nowrap cls-no-nav">
                                        <button type="button" class="btn btn-sm btn-cls btn-edit-class"
                                            data-id="<?php echo (int)$row['id']; ?>"
                                            data-ten-lop="<?php echo cls_h($row['ten_lop']); ?>"
                                            data-nganh="<?php echo cls_h($row['nganh']); ?>"
                                            data-ma-xac-nhan="<?php echo cls_h(isset($row['ma_xac_nhan']) ? $row['ma_xac_nhan'] : ''); ?>"
                                            data-bs-toggle="modal" data-bs-target="#editClassModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Xác nhận xóa lớp?');">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" name="delete_class" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</main>

<?php if ($can_edit): ?>
<div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Thêm lớp mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="add_class" value="1">
                    <div class="mb-3">
                        <label class="form-label">Tên lớp</label>
                        <input type="text" name="ten_lop" class="form-control" required placeholder="VD: Chiên Con 1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ngành</label>
                        <select name="nganh" class="form-select" required>
                            <option value="Chiên Con">Chiên Con</option>
                            <option value="Ấu Nhi">Ấu Nhi</option>
                            <option value="Thiếu Nhi">Thiếu Nhi</option>
                            <option value="Nghĩa Sĩ">Nghĩa Sĩ</option>
                            <option value="Hiệp Sĩ">Hiệp Sĩ</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mã xác nhận <span class="text-muted fw-normal">(tuỳ chọn)</span></label>
                        <input type="text" name="ma_xac_nhan" class="form-control" placeholder="Bảo vệ lớp khi chỉnh sửa" maxlength="20">
                        <div class="form-text"><i class="fas fa-info-circle me-1"></i>Để trống nếu không cần mã bảo vệ.</div>
                    </div>
                    <button type="submit" class="btn btn-cls w-100"><i class="fas fa-check me-1"></i>Thêm lớp</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Chỉnh sửa lớp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editClassForm">
                    <input type="hidden" name="edit_class" value="1">
                    <input type="hidden" name="class_id" id="class_id">
                    <div class="mb-3">
                        <label class="form-label">Tên lớp</label>
                        <input type="text" name="ten_lop" id="ten_lop" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ngành</label>
                        <select name="nganh" id="nganh" class="form-select" required>
                            <option value="Chiên Con">Chiên Con</option>
                            <option value="Ấu Nhi">Ấu Nhi</option>
                            <option value="Thiếu Nhi">Thiếu Nhi</option>
                            <option value="Nghĩa Sĩ">Nghĩa Sĩ</option>
                            <option value="Hiệp Sĩ">Hiệp Sĩ</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mã xác nhận</label>
                        <input type="text" name="ma_xac_nhan" id="ma_xac_nhan" class="form-control" placeholder="Để trống để tắt bảo vệ" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Huynh trưởng phụ trách</label>
                        <div id="teacher-list"></div>
                    </div>
                    <button type="submit" class="btn btn-cls w-100"><i class="fas fa-save me-1"></i>Lưu thay đổi</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($logged_in && !empty($resume_class_options)): ?>
<div class="modal fade" id="resumeStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" onsubmit="return confirm('Xác nhận bật lại và xếp vào lớp đã chọn?');">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-play me-2"></i>Đi học lại</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="resume_student" value="1">
                    <input type="hidden" name="student_id" id="resume_student_id">
                    <input type="hidden" name="student_search" value="<?php echo cls_h($student_search); ?>">
                    <p class="mb-1">Đoàn sinh: <strong id="resume_student_name"></strong></p>
                    <p class="mb-3 text-muted small">Đã tạm nghỉ: <strong id="resume_leave_days">0</strong> ngày</p>
                    <label class="form-label fw-bold">Chọn lớp đi học lại</label>
                    <select name="new_class_id" class="form-select" required>
                        <option value="">— Chọn lớp —</option>
                        <?php foreach ($resume_class_options as $opt): ?>
                            <option value="<?php echo (int)$opt['id']; ?>"><?php echo cls_h($opt['ten_lop'] . ' (' . $opt['nganh'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-play me-1"></i>Bật lại</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($need_class_pick): ?>
<div class="modal fade" id="pickClassModal" tabindex="-1" aria-labelledby="pickClassModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="pickClassModalLabel"><i class="fas fa-chalkboard me-2"></i>Chọn lớp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($pick_classes)): ?>
                    <div class="alert alert-warning mb-0">Chưa có lớp nào trong hệ thống.</div>
                <?php else: ?>
                    <label class="form-label fw-bold" for="pickClassSelect">Lớp</label>
                    <select id="pickClassSelect" class="form-select">
                        <option value="">— Chọn lớp —</option>
                        <?php foreach ($pick_classes as $pc): ?>
                            <option value="<?php echo (int)$pc['id']; ?>"><?php echo cls_h($pc['ten_lop'] . ' (' . $pc['nganh'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted small mt-2 mb-0" id="pickClassHint"></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-cls" id="pickClassGo" <?php echo empty($pick_classes) ? 'disabled' : ''; ?>>
                    <i class="fas fa-arrow-right me-1"></i>Đi tới
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    // Menu mobile: đổi icon + đóng khi chọn mục
    var navMenu = document.getElementById('clsNavMenu');
    var menuBtn = document.querySelector('.cls-menu-btn');
    if (navMenu && menuBtn) {
        navMenu.addEventListener('show.bs.collapse', function() {
            menuBtn.innerHTML = '<i class="fas fa-times"></i>';
            menuBtn.setAttribute('aria-expanded', 'true');
        });
        navMenu.addEventListener('hide.bs.collapse', function() {
            menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            menuBtn.setAttribute('aria-expanded', 'false');
        });
        navMenu.querySelectorAll('.cls-nav-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 992 && navMenu.classList.contains('show')) {
                    bootstrap.Collapse.getOrCreateInstance(navMenu).hide();
                }
            });
        });
    }

    // Click cả hàng / card để vào lớp
    function clsGoToClass(el) {
        var href = el.getAttribute('data-href');
        if (href) window.location.href = href;
    }
    document.querySelectorAll('.cls-row-clickable, .cls-card[data-href]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target.closest('.cls-no-nav, button, a, form, input, select, textarea, label')) return;
            clsGoToClass(el);
        });
        el.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (e.target.closest('.cls-no-nav, button, a, form, input, select, textarea, label')) return;
            e.preventDefault();
            clsGoToClass(el);
        });
    });

    // Tab tìm kiếm
    document.querySelectorAll('.search-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.search-tab').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.search-pane').forEach(function(p) { p.classList.remove('active'); });
            btn.classList.add('active');
            var target = document.getElementById(btn.getAttribute('data-target'));
            if (target) target.classList.add('active');
        });
    });

    // Biểu đồ
    var stats = <?php echo json_encode($ordered_stats); ?>;
    var ctx = document.getElementById('statsChart');
    if (ctx) {
        new Chart(ctx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(stats),
                datasets: [{
                    data: Object.values(stats),
                    backgroundColor: ['#ef5350', '#66bb6a', '#42a5f5', '#ffa726', '#8d6e63'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 10, font: { size: 11, weight: '600' } }
                    }
                }
            }
        });
    }

    <?php if ($can_edit): ?>
    var allTeachers = <?php echo json_encode($all_teachers); ?>;

    function addTeacherRow(teacher_id, vai_tro, index) {
        teacher_id = teacher_id || '';
        vai_tro = vai_tro || 'Phó Lớp';
        var teacherList = document.getElementById('teacher-list');
        var row = document.createElement('div');
        row.className = 'teacher-row';
        row.setAttribute('data-index', index);
        var options = '<option value="">— Chọn huynh trưởng —</option>';
        allTeachers.forEach(function(teacher) {
            options += '<option value="' + teacher.id + '"' + (String(teacher_id) === String(teacher.id) ? ' selected' : '') + '>' + teacher.ho_ten + '</option>';
        });
        row.innerHTML =
            '<select name="huynh_truong[]" class="form-select teacher-select mb-2" onchange="updateTeacherDropdowns()">' + options + '</select>' +
            '<select name="vai_tro[]" class="form-select">' +
                '<option value="Trưởng Lớp"' + (vai_tro === 'Trưởng Lớp' ? ' selected' : '') + '>Trưởng Lớp</option>' +
                '<option value="Phó Lớp"' + (vai_tro === 'Phó Lớp' ? ' selected' : '') + '>Phó Lớp</option>' +
            '</select>';
        teacherList.appendChild(row);
    }

    window.updateTeacherDropdowns = function() {
        var selects = document.querySelectorAll('#teacher-list .teacher-select');
        var selectedValues = Array.prototype.slice.call(selects).map(function(s) { return s.value; }).filter(function(v) { return v !== ''; });
        selects.forEach(function(select) {
            var currentValue = select.value;
            select.innerHTML = '<option value="">— Chọn huynh trưởng —</option>';
            allTeachers.forEach(function(teacher) {
                if (selectedValues.indexOf(String(teacher.id)) === -1 || String(teacher.id) === currentValue) {
                    var option = document.createElement('option');
                    option.value = teacher.id;
                    option.textContent = teacher.ho_ten;
                    if (String(teacher.id) === currentValue) option.selected = true;
                    select.appendChild(option);
                }
            });
        });
    };

    function loadClassData(id, ten_lop, nganh, ma_xac_nhan) {
        document.getElementById('class_id').value = id;
        document.getElementById('ten_lop').value = ten_lop;
        document.getElementById('nganh').value = nganh;
        document.getElementById('ma_xac_nhan').value = ma_xac_nhan || '';
        fetch('get_class_teachers.php?id=' + id)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var teacherList = document.getElementById('teacher-list');
                teacherList.innerHTML = '';
                for (var i = 0; i < 4; i++) {
                    var teacher = data[i] || { teacher_id: '', vai_tro: 'Phó Lớp' };
                    addTeacherRow(teacher.teacher_id, teacher.vai_tro, i);
                }
                updateTeacherDropdowns();
            });
    }

    document.querySelectorAll('.btn-edit-class').forEach(function(btn) {
        btn.addEventListener('click', function() {
            loadClassData(
                btn.getAttribute('data-id'),
                btn.getAttribute('data-ten-lop'),
                btn.getAttribute('data-nganh'),
                btn.getAttribute('data-ma-xac-nhan')
            );
        });
    });
    <?php endif; ?>

    var resumeModal = document.getElementById('resumeStudentModal');
    if (resumeModal) {
        resumeModal.addEventListener('show.bs.modal', function(event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('resume_student_id').value = btn.getAttribute('data-id') || '';
            document.getElementById('resume_student_name').textContent = btn.getAttribute('data-name') || '';
            document.getElementById('resume_leave_days').textContent = btn.getAttribute('data-days') || '0';
        });
    }

    var pickModal = document.getElementById('pickClassModal');
    if (pickModal) {
        var pickMode = 'lop';
        var pickSelect = document.getElementById('pickClassSelect');
        var pickTitle = document.getElementById('pickClassModalLabel');
        var pickHint = document.getElementById('pickClassHint');
        var todayQr = <?php echo json_encode($today_qr); ?>;
        var pickTexts = {
            qr:  ['fa-qrcode', 'Chọn lớp để quét QR', 'Mở trang quét QR điểm danh của lớp.'],
            so:  ['fa-clipboard-list', 'Chọn lớp để điểm danh sổ', 'Mở phần điểm danh sổ của lớp.'],
            lop: ['fa-chalkboard-teacher', 'Chọn lớp để xem', 'Mở trang chi tiết lớp.']
        };
        pickModal.addEventListener('show.bs.modal', function(event) {
            var trigger = event.relatedTarget;
            pickMode = (trigger && trigger.getAttribute('data-pick-mode')) || 'lop';
            var t = pickTexts[pickMode] || pickTexts.lop;
            if (pickTitle) pickTitle.innerHTML = '<i class="fas ' + t[0] + ' me-2"></i>' + t[1];
            if (pickHint) pickHint.textContent = t[2];
            if (pickSelect) pickSelect.value = '';
        });
        var pickGo = document.getElementById('pickClassGo');
        if (pickGo) {
            pickGo.addEventListener('click', function() {
                var classId = pickSelect ? parseInt(pickSelect.value, 10) : 0;
                if (!classId) { alert('Vui lòng chọn lớp.'); return; }
                if (pickMode === 'qr') {
                    window.location.href = 'diem_danh_qr.php?class_id=' + classId + '&ngay=' + encodeURIComponent(todayQr);
                } else if (pickMode === 'so') {
                    window.location.href = 'class_details.php?id=' + classId + '&panel=diemdanh';
                } else {
                    window.location.href = 'class_details.php?id=' + classId;
                }
            });
        }
    }
})();
</script>
<?php login_include_bo_nhiem_popup(); ?>
</body>
</html>
<?php $conn->close(); ?>
