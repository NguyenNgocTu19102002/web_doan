<?php
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

session_start();

function hdt_val($arr, $key, $default = '') {
    if (!is_array($arr) || !isset($arr[$key]) || $arr[$key] === null) {
        return $default;
    }
    return $arr[$key];
}

require_once __DIR__ . '/login_redirect.php';

if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}

$current_role = login_current_role();
if (!login_is_hdt_manager($current_role)) {
    $_SESSION['error'] = 'Chỉ tài khoản Admin và quản lý Huynh Dự Trưởng mới được phép truy cập trang này.';
    header('Location: ' . app_url('index.php', true));
    exit;
}

require_once __DIR__ . '/db_connect.php';

$user_id = (int) $_SESSION['user_id'];

if (is_file(__DIR__ . '/includes/huynh_profile.php')) {
    require_once __DIR__ . '/includes/huynh_profile.php';
    ht_profile_ensure_extra_columns($conn);
}

$hdt_page = app_url('hdt_management.php');
$hdt_page_abs = app_url('hdt_management.php', true);

$check_column = $conn->query("SHOW COLUMNS FROM teachers LIKE 'loai_huynh'");
$has_loai_huynh = $check_column && $check_column->num_rows > 0;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_cap = isset($_GET['cap']) ? trim($_GET['cap']) : '';
$filter_nganh = isset($_GET['nganh']) ? trim($_GET['nganh']) : '';
$view_mode = (isset($_GET['view']) && $_GET['view'] === 'table') ? 'table' : 'cards';

$search_esc = $conn->real_escape_string($search_query);

if ($has_loai_huynh) {
    $sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.gioi_tinh, t.so_dien_thoai, t.email, t.avatar,
                       t.dia_chi, t.ngay_rua_toi, t.ngay_them_suc, t.ngay_gia_nhap, t.ghi_chu,
                       ct.vai_tro, c.ten_lop, c.nganh, t.loai_huynh, ct.class_id
                FROM class_teachers ct
                JOIN teachers t ON ct.teacher_id = t.id
                JOIN classes c ON ct.class_id = c.id
                WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')";
} else {
    $sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.gioi_tinh, t.so_dien_thoai, t.email, t.avatar,
                       t.dia_chi, t.ngay_rua_toi, t.ngay_them_suc, t.ngay_gia_nhap, t.ghi_chu,
                       ct.vai_tro, c.ten_lop, c.nganh, 'Huynh Trưởng' as loai_huynh, ct.class_id
                FROM class_teachers ct
                JOIN teachers t ON ct.teacher_id = t.id
                JOIN classes c ON ct.class_id = c.id
                WHERE 1=1";
}

if ($search_query !== '') {
    $sql_hdt .= " AND (t.ho_ten LIKE '%$search_esc%' OR t.ten_thanh LIKE '%$search_esc%' OR c.ten_lop LIKE '%$search_esc%' OR t.so_dien_thoai LIKE '%$search_esc%')";
}
if ($filter_cap !== '' && in_array($filter_cap, ['Huynh Trưởng', 'Huynh Dự Trưởng'], true)) {
    $sql_hdt .= " AND t.loai_huynh = '" . $conn->real_escape_string($filter_cap) . "'";
}
if ($filter_nganh !== '') {
    $sql_hdt .= " AND c.nganh = '" . $conn->real_escape_string($filter_nganh) . "'";
}

$sql_hdt .= " ORDER BY c.nganh, c.ten_lop, t.ho_ten";
$result_hdt = $conn->query($sql_hdt);
if (!$result_hdt) {
    die("Lỗi truy vấn Huynh Dự Trưởng: " . $conn->error);
}

$hdt_list = [];
while ($row = $result_hdt->fetch_assoc()) {
    $hdt_list[] = $row;
}

// Xuất Excel (HTML table .xls — Excel mở được), theo bộ lọc hiện tại
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = 'Danh_sach_Huynh_Truong_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $fmt = function ($d) {
        if (!$d || $d === '0000-00-00') {
            return '';
        }
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : '';
    };

    $filter_note = [];
    if ($search_query !== '') $filter_note[] = 'Tìm: ' . $search_query;
    if ($filter_cap !== '') $filter_note[] = 'Cấp bậc: ' . $filter_cap;
    if ($filter_nganh !== '') $filter_note[] = 'Ngành: ' . $filter_nganh;
    $subtitle = $filter_note ? implode(' · ', $filter_note) : 'Toàn bộ danh sách';

    echo "<html><head><meta charset='utf-8'><style>
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #000;padding:6px;vertical-align:middle}
        th{background:#1565c0;color:#fff;font-weight:700;text-align:center}
        .title{font-size:18px;font-weight:700;text-align:center}
        .sub{font-size:12px;text-align:center;color:#333}
        .num{text-align:center;width:40px}
      </style></head><body>";
    echo "<table>";
    echo "<tr><td class='title' colspan='14'>DANH SÁCH HUYNH TRƯỞNG &amp; DỰ TRƯỞNG</td></tr>";
    echo "<tr><td class='sub' colspan='14'>Đoàn TNTT Đaminh Savio Lập Trí · Xuất ngày " . date('d/m/Y H:i') . " · " . htmlspecialchars($subtitle) . " · " . count($hdt_list) . " dòng</td></tr>";
    echo "<tr>
            <th>STT</th>
            <th>Tên thánh</th>
            <th>Họ tên</th>
            <th>Ngày sinh</th>
            <th>Giới tính</th>
            <th>Cấp bậc</th>
            <th>Vai trò</th>
            <th>Lớp</th>
            <th>Ngành</th>
            <th>SĐT</th>
            <th>Email</th>
            <th>Địa chỉ</th>
            <th>Ngày gia nhập</th>
            <th>Ghi chú</th>
          </tr>";

    $stt = 1;
    foreach ($hdt_list as $hdt) {
        $cap = isset($hdt['loai_huynh']) ? $hdt['loai_huynh'] : 'Huynh Trưởng';
        $cap_label = ($cap === 'Huynh Dự Trưởng') ? 'Dự Trưởng' : $cap;
        echo '<tr>';
        echo '<td class="num">' . $stt++ . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'ten_thanh')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'ho_ten')) . '</td>';
        echo '<td>' . htmlspecialchars($fmt(hdt_val($hdt, 'ngay_sinh'))) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'gioi_tinh')) . '</td>';
        echo '<td>' . htmlspecialchars($cap_label) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'vai_tro')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'ten_lop')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'nganh')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'so_dien_thoai')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'email')) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'dia_chi')) . '</td>';
        echo '<td>' . htmlspecialchars($fmt(hdt_val($hdt, 'ngay_gia_nhap'))) . '</td>';
        echo '<td>' . htmlspecialchars(hdt_val($hdt, 'ghi_chu')) . '</td>';
        echo '</tr>';
    }

    if (empty($hdt_list)) {
        echo "<tr><td colspan='14' style='text-align:center'>Không có dữ liệu</td></tr>";
    }

    echo '</table></body></html>';
    exit;
}

// Thống kê (không phụ thuộc filter tìm kiếm chi tiết — lấy toàn bộ HT/HDT có lớp)
$stats = ['total' => 0, 'ht' => 0, 'hdt' => 0, 'with_avatar' => 0, 'unique' => 0];
$nganh_options = [];
$stat_sql = $has_loai_huynh
    ? "SELECT t.id, t.loai_huynh, t.avatar, c.nganh
       FROM class_teachers ct
       JOIN teachers t ON ct.teacher_id = t.id
       JOIN classes c ON ct.class_id = c.id
       WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')"
    : "SELECT t.id, 'Huynh Trưởng' AS loai_huynh, t.avatar, c.nganh
       FROM class_teachers ct
       JOIN teachers t ON ct.teacher_id = t.id
       JOIN classes c ON ct.class_id = c.id";
$stat_res = $conn->query($stat_sql);
$seen_ids = [];
if ($stat_res) {
    while ($s = $stat_res->fetch_assoc()) {
        if ($s['nganh'] !== '' && $s['nganh'] !== null) {
            $nganh_options[$s['nganh']] = true;
        }
        if (isset($seen_ids[$s['id']])) {
            continue;
        }
        $seen_ids[$s['id']] = true;
        $stats['unique']++;
        if ($s['loai_huynh'] === 'Huynh Dự Trưởng') {
            $stats['hdt']++;
        } else {
            $stats['ht']++;
        }
        if (!empty($s['avatar'])) {
            $stats['with_avatar']++;
        }
    }
}
$stats['total'] = $stats['unique'];
ksort($nganh_options);
$nganh_list = array_keys($nganh_options);

$sql_classes = "SELECT id, ten_lop, nganh FROM classes ORDER BY ten_lop";
$result_classes = $conn->query($sql_classes);
$classes = [];
if ($result_classes && $result_classes->num_rows > 0) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hdt'])) {
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $so_dien_thoai = $conn->real_escape_string($_POST['so_dien_thoai']);
    $email = $conn->real_escape_string($_POST['email']);
    $vai_tro = $conn->real_escape_string($_POST['vai_tro']);
    $loai_huynh = $conn->real_escape_string($_POST['loai_huynh']);
    $class_id = (int)$_POST['class_id'];

    if ($so_dien_thoai !== '' && !preg_match('/^\d{10}$/', $so_dien_thoai)) {
        $_SESSION['error'] = 'SĐT phải gồm đúng 10 số.';
        header('Location: ' . $hdt_page_abs);
        exit;
    }

    if ($has_loai_huynh) {
        $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, loai_huynh)
                        VALUES ('$ho_ten', '$ten_thanh', '$ngay_sinh', '$gioi_tinh', '$so_dien_thoai', '$email', '$loai_huynh')";
    } else {
        $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, so_dien_thoai, email)
                        VALUES ('$ho_ten', '$ten_thanh', '$ngay_sinh', '$so_dien_thoai', '$email')";
    }

    if ($conn->query($sql_teacher)) {
        $teacher_id = $conn->insert_id;
        $sql_class_teacher = "INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ($class_id, $teacher_id, '$vai_tro')";
        $conn->query($sql_class_teacher)
            ? $_SESSION['success'] = "Thêm Huynh Trưởng/Dự Trưởng thành công"
            : $_SESSION['error'] = "Lỗi: " . $conn->error;
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header('Location: ' . $hdt_page_abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_hdt'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $so_dien_thoai = $conn->real_escape_string($_POST['so_dien_thoai']);
    $email = $conn->real_escape_string($_POST['email']);
    $vai_tro = $conn->real_escape_string($_POST['vai_tro']);
    $loai_huynh = $conn->real_escape_string($_POST['loai_huynh']);
    $class_id = (int)$_POST['class_id'];

    if ($so_dien_thoai !== '' && !preg_match('/^\d{10}$/', $so_dien_thoai)) {
        $_SESSION['error'] = 'SĐT phải gồm đúng 10 số.';
        header('Location: ' . $hdt_page_abs);
        exit;
    }

    if ($has_loai_huynh) {
        $sql_teacher = "UPDATE teachers SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = '$ngay_sinh', gioi_tinh = '$gioi_tinh', so_dien_thoai = '$so_dien_thoai', email = '$email', loai_huynh = '$loai_huynh' WHERE id = $teacher_id";
    } else {
        $sql_teacher = "UPDATE teachers SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = '$ngay_sinh', so_dien_thoai = '$so_dien_thoai', email = '$email' WHERE id = $teacher_id";
    }

    if ($conn->query($sql_teacher)) {
        $sql_class_teacher = "UPDATE class_teachers SET class_id = $class_id, vai_tro = '$vai_tro' WHERE teacher_id = $teacher_id";
        $conn->query($sql_class_teacher);
        $_SESSION['success'] = "Sửa Huynh Dự Trưởng thành công";
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header('Location: ' . $hdt_page_abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_hdt'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $conn->query("DELETE FROM class_teachers WHERE teacher_id = $teacher_id");
    $conn->query("DELETE FROM teachers WHERE id = $teacher_id");
    $_SESSION['success'] = "Xóa Huynh Dự Trưởng thành công";
    header('Location: ' . $hdt_page_abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_extension !== 'csv') {
            $_SESSION['error'] = "Chỉ chấp nhận file CSV.";
            header('Location: ' . $hdt_page_abs);
            exit;
        }

        if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
            $header = fgetcsv($handle, 1000, ",");
            $expected_headers = ['ho_ten', 'ten_thanh', 'ngay_sinh', 'gioi_tinh', 'so_dien_thoai', 'email', 'loai_huynh', 'vai_tro', 'ten_lop'];

            if (array_diff($expected_headers, $header)) {
                $_SESSION['error'] = "File CSV không đúng định dạng. Cần có các cột: " . implode(', ', $expected_headers);
                header('Location: ' . $hdt_page_abs);
                exit;
            }

            $success_count = 0;
            $error_count = 0;
            $errors = [];

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 9) continue;

                $ho_ten = trim($data[0]);
                $ten_thanh = trim($data[1]);
                $ngay_sinh = trim($data[2]);
                $gioi_tinh = trim($data[3]);
                $so_dien_thoai = trim($data[4]);
                $email = trim($data[5]);
                $loai_huynh = trim($data[6]);
                $vai_tro = trim($data[7]);
                $ten_lop = trim($data[8]);

                $row_errors = [];
                if (empty($ho_ten)) $row_errors[] = "Họ tên không được để trống";
                if (empty($ngay_sinh)) $row_errors[] = "Ngày sinh không được để trống";
                if (!in_array($gioi_tinh, ['Nam', 'Nữ'])) $row_errors[] = "Giới tính phải là 'Nam' hoặc 'Nữ'";
                if (!empty($so_dien_thoai) && !preg_match('/^\d{10}$/', $so_dien_thoai)) $row_errors[] = "SĐT phải gồm đúng 10 số";
                if (!in_array($loai_huynh, ['Huynh Trưởng', 'Huynh Dự Trưởng'])) $row_errors[] = "Cấp bậc phải là 'Huynh Trưởng' hoặc 'Huynh Dự Trưởng'";
                if (empty($ten_lop)) $row_errors[] = "Tên lớp không được để trống";

                $class_id = 0;
                $class_result = $conn->query("SELECT id FROM classes WHERE ten_lop = '" . $conn->real_escape_string($ten_lop) . "'");
                if (!$class_result || $class_result->num_rows === 0) {
                    $row_errors[] = "Không tìm thấy lớp: $ten_lop";
                } else {
                    $class_id = (int)$class_result->fetch_assoc()['id'];
                }

                if (!empty($row_errors)) {
                    $error_count++;
                    $errors[] = "Dòng " . ($success_count + $error_count) . " - " . implode(', ', $row_errors);
                    continue;
                }

                if ($has_loai_huynh) {
                    $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, loai_huynh)
                                    VALUES ('" . $conn->real_escape_string($ho_ten) . "', '" . $conn->real_escape_string($ten_thanh) . "', '" . $conn->real_escape_string($ngay_sinh) . "', '" . $conn->real_escape_string($gioi_tinh) . "', '" . $conn->real_escape_string($so_dien_thoai) . "', '" . $conn->real_escape_string($email) . "', '" . $conn->real_escape_string($loai_huynh) . "')";
                } else {
                    $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, so_dien_thoai, email)
                                    VALUES ('" . $conn->real_escape_string($ho_ten) . "', '" . $conn->real_escape_string($ten_thanh) . "', '" . $conn->real_escape_string($ngay_sinh) . "', '" . $conn->real_escape_string($so_dien_thoai) . "', '" . $conn->real_escape_string($email) . "')";
                }

                if ($conn->query($sql_teacher)) {
                    $teacher_id = $conn->insert_id;
                    $sql_class_teacher = "INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ($class_id, $teacher_id, '" . $conn->real_escape_string($vai_tro) . "')";
                    if ($conn->query($sql_class_teacher)) {
                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Dòng " . ($success_count + $error_count) . " - Lỗi thêm vào class_teachers: " . $conn->error;
                    }
                } else {
                    $error_count++;
                    $errors[] = "Dòng " . ($success_count + $error_count) . " - Lỗi thêm vào teachers: " . $conn->error;
                }
            }
            fclose($handle);

            if ($success_count > 0) {
                $_SESSION['success'] = "Import thành công $success_count huynh trưởng/dự trưởng.";
            }
            if ($error_count > 0) {
                $_SESSION['error'] = "Có $error_count dòng lỗi. Chi tiết: " . implode('; ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? '...' : '');
            }
        } else {
            $_SESSION['error'] = "Không thể đọc file CSV.";
        }
    } else {
        $_SESSION['error'] = "Vui lòng chọn file CSV để upload.";
    }
    header('Location: ' . $hdt_page_abs);
    exit;
}

function hdt_initials($ten_thanh, $ho_ten) {
    $name = trim($ho_ten);
    $parts = preg_split('/\s+/u', $name);
    $last = $parts ? end($parts) : '';
    $first = mb_substr($last !== '' ? $last : '?', 0, 1, 'UTF-8');
    $saint = $ten_thanh ? mb_substr(trim($ten_thanh), 0, 1, 'UTF-8') : '';
    return mb_strtoupper($saint . $first, 'UTF-8');
}

function hdt_avatar_src($avatar) {
    if (empty($avatar)) {
        return '';
    }
    $url = ht_profile_avatar_url($avatar);
    if ($url && is_file(__DIR__ . '/' . $url)) {
        return $url;
    }
    return '';
}

$query_keep = [];
if ($search_query !== '') $query_keep['search'] = $search_query;
if ($filter_cap !== '') $query_keep['cap'] = $filter_cap;
if ($filter_nganh !== '') $query_keep['nganh'] = $filter_nganh;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Huynh Trưởng & Dự Trưởng - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --hdt-primary: #1565c0;
            --hdt-primary-2: #1e88e5;
            --hdt-accent: #c62828;
            --hdt-bg: #eef5fb;
            --hdt-card: #ffffff;
            --hdt-text: #1a237e;
            --hdt-muted: #607d8b;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(160deg, #e8f4fc 0%, #f4f8fb 45%, #e3f2fd 100%);
            min-height: 100vh;
            color: #263238;
        }
        .hdt-header {
            background: linear-gradient(135deg, #0d47a1, #1565c0 45%, #42a5f5);
            color: #fff;
            padding: 1.75rem 0 1.5rem;
            box-shadow: 0 8px 24px rgba(13, 71, 161, .25);
        }
        .hdt-header img { width: 64px; height: 64px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,.15)); }
        .hdt-header h1 { font-weight: 800; font-size: 1.65rem; margin: 0; letter-spacing: .02em; }
        .hdt-header .sub { opacity: .88; margin-top: .35rem; }
        .stat-card {
            background: var(--hdt-card);
            border: none;
            border-radius: 1rem;
            box-shadow: 0 8px 24px rgba(21, 101, 192, .08);
            padding: 1.1rem 1.25rem;
            height: 100%;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(21, 101, 192, .12); }
        .stat-card .icon {
            width: 48px; height: 48px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; color: #fff; flex-shrink: 0;
        }
        .stat-card .label { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: var(--hdt-muted); font-weight: 700; }
        .stat-card .value { font-size: 1.75rem; font-weight: 800; color: var(--hdt-text); line-height: 1.1; }
        .panel {
            background: #fff;
            border-radius: 1.15rem;
            box-shadow: 0 10px 30px rgba(21, 101, 192, .08);
            border: 1px solid rgba(21, 101, 192, .06);
            overflow: hidden;
        }
        .panel-head {
            background: linear-gradient(135deg, #1565c0, #42a5f5);
            color: #fff;
            padding: 1rem 1.25rem;
            display: flex; flex-wrap: wrap; gap: .75rem;
            align-items: center; justify-content: space-between;
        }
        .panel-head h2 { font-size: 1.15rem; font-weight: 800; margin: 0; }
        .toolbar .form-control, .toolbar .form-select {
            border-radius: .75rem;
            border-color: #d6e4f0;
        }
        .filter-chip {
            border: 1px solid #cfd8dc;
            background: #fff;
            color: #455a64;
            border-radius: 999px;
            padding: .35rem .9rem;
            font-weight: 700;
            font-size: .85rem;
            text-decoration: none;
            display: inline-flex; align-items: center; gap: .35rem;
        }
        .filter-chip.active, .filter-chip:hover {
            background: #e3f2fd;
            border-color: #90caf9;
            color: #1565c0;
        }
        .person-card {
            background: #fff;
            border: 1px solid #e8eef5;
            border-radius: 1rem;
            padding: 1.15rem;
            height: 100%;
            box-shadow: 0 6px 18px rgba(21, 101, 192, .05);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
            display: flex; flex-direction: column;
        }
        .person-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 28px rgba(21, 101, 192, .12);
            border-color: #bbdefb;
        }
        .avatar-circle {
            width: 88px; height: 88px; border-radius: 50%;
            background: linear-gradient(145deg, #42a5f5, #1565c0);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 1.45rem; font-weight: 800;
            box-shadow: 0 8px 18px rgba(21,101,192,.22);
            overflow: hidden; flex-shrink: 0;
            border: 3px solid #fff;
            outline: 2px solid #bbdefb;
        }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .avatar-sm {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(145deg, #42a5f5, #1565c0);
            color: #fff; display: inline-flex; align-items: center; justify-content: center;
            font-size: .85rem; font-weight: 800; overflow: hidden;
            border: 2px solid #fff; box-shadow: 0 2px 8px rgba(21,101,192,.2);
        }
        .avatar-sm img { width: 100%; height: 100%; object-fit: cover; }
        .person-name { font-weight: 800; color: var(--hdt-text); font-size: 1.05rem; margin: 0; }
        .person-saint { color: var(--hdt-muted); font-weight: 600; font-size: .9rem; }
        .meta-row { font-size: .88rem; color: #546e7a; display: flex; align-items: center; gap: .45rem; margin-top: .35rem; }
        .badge-cap {
            border-radius: 999px; font-weight: 700; font-size: .75rem; padding: .35em .7em;
        }
        .badge-ht { background: #ffebee; color: #c62828; }
        .badge-hdt { background: #fff8e1; color: #f57f17; }
        .badge-role { background: #e3f2fd; color: #1565c0; border-radius: 999px; font-weight: 700; font-size: .75rem; padding: .35em .7em; }
        .badge-nganh { background: #f3e5f5; color: #6a1b9a; border-radius: 999px; font-weight: 700; font-size: .75rem; padding: .35em .7em; }
        .btn-action {
            border-radius: 999px; font-weight: 700; font-size: .85rem;
            padding: .4rem .9rem;
        }
        .btn-primary-soft {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            border: none; color: #fff; border-radius: 999px; font-weight: 700;
        }
        .btn-primary-soft:hover { filter: brightness(1.05); color: #fff; }
        .table-modern thead th {
            background: #f5f9fc; color: #37474f; font-weight: 800;
            font-size: .8rem; text-transform: uppercase; letter-spacing: .04em;
            border-bottom: 2px solid #e3eef6; white-space: nowrap;
        }
        .table-modern td { vertical-align: middle; }
        .view-toggle .btn { border-radius: 999px; font-weight: 700; }
        .empty-state {
            text-align: center; padding: 3rem 1rem; color: var(--hdt-muted);
        }
        .empty-state i { font-size: 2.5rem; opacity: .45; margin-bottom: .75rem; }
        @media (max-width: 768px) {
            .hdt-header h1 { font-size: 1.3rem; }
            .avatar-circle { width: 72px; height: 72px; font-size: 1.2rem; }
            .stat-card .value { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
    <header class="hdt-header text-center">
        <div class="container">
            <img src="img/logo.png" alt="Logo Đoàn" class="mb-2">
            <h1>Quản Lý Huynh Trưởng &amp; Dự Trưởng</h1>
            <p class="sub mb-0">Đoàn TNTT Đaminh Savio Lập Trí · Hồ sơ nhân sự có ảnh đại diện</p>
        </div>
    </header>

    <main class="container my-4 mb-5">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$has_loai_huynh): ?>
            <div class="alert alert-warning">
                <strong>Cảnh báo:</strong> Database chưa có cột <code>loai_huynh</code>. Một số tính năng có thể chưa đầy đủ.
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card d-flex align-items-center gap-3">
                    <div class="icon" style="background:linear-gradient(135deg,#1565c0,#42a5f5)"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="label">Tổng số</div>
                        <div class="value"><?php echo (int)$stats['total']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card d-flex align-items-center gap-3">
                    <div class="icon" style="background:linear-gradient(135deg,#c62828,#ef5350)"><i class="fa-solid fa-user-tie"></i></div>
                    <div>
                        <div class="label">Huynh Trưởng</div>
                        <div class="value"><?php echo (int)$stats['ht']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card d-flex align-items-center gap-3">
                    <div class="icon" style="background:linear-gradient(135deg,#f9a825,#ffca28)"><i class="fa-solid fa-user-graduate"></i></div>
                    <div>
                        <div class="label">Dự Trưởng</div>
                        <div class="value"><?php echo (int)$stats['hdt']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card d-flex align-items-center gap-3">
                    <div class="icon" style="background:linear-gradient(135deg,#00897b,#26a69a)"><i class="fa-solid fa-id-badge"></i></div>
                    <div>
                        <div class="label">Có ảnh ĐD</div>
                        <div class="value"><?php echo (int)$stats['with_avatar']; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2><i class="fa-solid fa-address-card me-2"></i>Danh sách nhân sự
                    <span class="badge bg-light text-primary ms-1"><?php echo count($hdt_list); ?></span>
                </h2>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?php echo htmlspecialchars(app_url('hdt_attendance.php')); ?>" class="btn btn-warning btn-sm btn-action">
                        <i class="fa-solid fa-clipboard-check me-1"></i>Điểm danh
                    </a>
                    <?php
                    $export_q = array_merge($query_keep, ['export' => 'excel']);
                    ?>
                    <a href="?<?php echo htmlspecialchars(http_build_query($export_q)); ?>" class="btn btn-light btn-sm btn-action" title="Xuất Excel theo bộ lọc hiện tại">
                        <i class="fa-solid fa-file-excel me-1 text-success"></i>Xuất Excel
                    </a>
                    <button class="btn btn-light btn-sm btn-action" data-bs-toggle="modal" data-bs-target="#importCSVModal">
                        <i class="fa-solid fa-file-csv me-1"></i>Import CSV
                    </button>
                    <button class="btn btn-success btn-sm btn-action" data-bs-toggle="modal" data-bs-target="#addHDTModal">
                        <i class="fa-solid fa-plus me-1"></i>Thêm mới
                    </button>
                </div>
            </div>

            <div class="p-3 p-md-4">
                <form method="GET" action="<?php echo htmlspecialchars($hdt_page); ?>" class="toolbar mb-3">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary mb-1">Tìm kiếm</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Tên, tên thánh, lớp, SĐT..." value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-bold text-secondary mb-1">Cấp bậc</label>
                            <select name="cap" class="form-select">
                                <option value="">Tất cả</option>
                                <option value="Huynh Trưởng" <?php echo $filter_cap === 'Huynh Trưởng' ? 'selected' : ''; ?>>Huynh Trưởng</option>
                                <option value="Huynh Dự Trưởng" <?php echo $filter_cap === 'Huynh Dự Trưởng' ? 'selected' : ''; ?>>Dự Trưởng</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-bold text-secondary mb-1">Ngành</label>
                            <select name="nganh" class="form-select">
                                <option value="">Tất cả</option>
                                <?php foreach ($nganh_list as $ng): ?>
                                    <option value="<?php echo htmlspecialchars($ng); ?>" <?php echo $filter_nganh === $ng ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ng); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary-soft flex-grow-1">
                                <i class="fa-solid fa-filter me-1"></i>Lọc
                            </button>
                            <a href="<?php echo htmlspecialchars($hdt_page); ?>?view=<?php echo urlencode($view_mode); ?>" class="btn btn-outline-secondary btn-action">Xóa</a>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $base_q = $query_keep;
                        unset($base_q['cap']);
                        $base_q['view'] = $view_mode;
                        ?>
                        <a class="filter-chip <?php echo $filter_cap === '' ? 'active' : ''; ?>" href="?<?php echo http_build_query($base_q); ?>">Tất cả</a>
                        <a class="filter-chip <?php echo $filter_cap === 'Huynh Trưởng' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($base_q, ['cap' => 'Huynh Trưởng'])); ?>">Huynh Trưởng</a>
                        <a class="filter-chip <?php echo $filter_cap === 'Huynh Dự Trưởng' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($base_q, ['cap' => 'Huynh Dự Trưởng'])); ?>">Dự Trưởng</a>
                    </div>
                    <div class="view-toggle btn-group">
                        <?php $q_cards = array_merge($query_keep, ['view' => 'cards']); $q_table = array_merge($query_keep, ['view' => 'table']); ?>
                        <a href="?<?php echo http_build_query($q_cards); ?>" class="btn btn-sm <?php echo $view_mode === 'cards' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="fa-solid fa-grip me-1"></i>Thẻ
                        </a>
                        <a href="?<?php echo http_build_query($q_table); ?>" class="btn btn-sm <?php echo $view_mode === 'table' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="fa-solid fa-table me-1"></i>Bảng
                        </a>
                    </div>
                </div>

                <?php if (empty($hdt_list)): ?>
                    <div class="empty-state">
                        <div><i class="fa-regular fa-folder-open"></i></div>
                        <div class="fw-bold">Không tìm thấy huynh trưởng/dự trưởng phù hợp.</div>
                        <div class="small">Thử đổi bộ lọc hoặc thêm mới.</div>
                    </div>
                <?php elseif ($view_mode === 'cards'): ?>
                    <div class="row g-3">
                        <?php foreach ($hdt_list as $hdt):
                            $avatar = hdt_avatar_src(hdt_val($hdt, 'avatar'));
                            $cap = hdt_val($hdt, 'loai_huynh', 'Huynh Trưởng');
                            $cap_label = $cap === 'Huynh Dự Trưởng' ? 'Dự Trưởng' : $cap;
                            $is_hdt = $cap === 'Huynh Dự Trưởng';
                            $full_name = trim(($hdt['ten_thanh'] ? $hdt['ten_thanh'] . ' ' : '') . $hdt['ho_ten']);
                        ?>
                        <div class="col-12 col-sm-6 col-xl-4">
                            <div class="person-card">
                                <div class="d-flex gap-3 align-items-start mb-2">
                                    <div class="avatar-circle">
                                        <?php if ($avatar): ?>
                                            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($full_name); ?>">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars(hdt_initials(hdt_val($hdt, 'ten_thanh'), hdt_val($hdt, 'ho_ten'))); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <?php if (!empty($hdt['ten_thanh'])): ?>
                                            <div class="person-saint"><?php echo htmlspecialchars($hdt['ten_thanh']); ?></div>
                                        <?php endif; ?>
                                        <h3 class="person-name text-truncate" title="<?php echo htmlspecialchars($hdt['ho_ten']); ?>">
                                            <?php echo htmlspecialchars($hdt['ho_ten']); ?>
                                        </h3>
                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                            <span class="badge-cap <?php echo $is_hdt ? 'badge-hdt' : 'badge-ht'; ?>"><?php echo htmlspecialchars($cap_label); ?></span>
                                            <?php if (!empty($hdt['vai_tro'])): ?>
                                                <span class="badge-role"><?php echo htmlspecialchars($hdt['vai_tro']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($hdt['nganh'])): ?>
                                                <span class="badge-nganh"><?php echo htmlspecialchars($hdt['nganh']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="meta-row"><i class="fa-solid fa-chalkboard-user fa-fw text-primary"></i><span><?php echo htmlspecialchars($hdt['ten_lop'] ?: 'Chưa có lớp'); ?></span></div>
                                <?php if (!empty($hdt['ngay_sinh'])): ?>
                                    <div class="meta-row"><i class="fa-solid fa-cake-candles fa-fw text-primary"></i><span><?php echo date('d/m/Y', strtotime($hdt['ngay_sinh'])); ?><?php echo !empty($hdt['gioi_tinh']) ? ' · ' . htmlspecialchars($hdt['gioi_tinh']) : ''; ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($hdt['so_dien_thoai'])): ?>
                                    <div class="meta-row"><i class="fa-solid fa-phone fa-fw text-primary"></i><a class="text-decoration-none" href="tel:<?php echo htmlspecialchars($hdt['so_dien_thoai']); ?>"><?php echo htmlspecialchars($hdt['so_dien_thoai']); ?></a></div>
                                <?php endif; ?>
                                <?php if (!empty($hdt['email'])): ?>
                                    <div class="meta-row text-truncate"><i class="fa-solid fa-envelope fa-fw text-primary"></i><a class="text-decoration-none text-truncate" href="mailto:<?php echo htmlspecialchars($hdt['email']); ?>"><?php echo htmlspecialchars($hdt['email']); ?></a></div>
                                <?php endif; ?>

                                <div class="mt-auto pt-3 d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-action flex-grow-1"
                                        data-bs-toggle="modal" data-bs-target="#editHDTModal"
                                        onclick="loadHDT(<?php echo (int)$hdt['id']; ?>, <?php echo htmlspecialchars(json_encode($hdt['ho_ten']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'ten_thanh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'ngay_sinh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'gioi_tinh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'so_dien_thoai')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'email')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'vai_tro')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'loai_huynh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)$hdt['class_id']; ?>)">
                                        <i class="fa-solid fa-pen me-1"></i>Sửa
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Xác nhận xóa huynh trưởng/dự trưởng này?');">
                                        <input type="hidden" name="teacher_id" value="<?php echo (int)$hdt['id']; ?>">
                                        <button type="submit" name="delete_hdt" class="btn btn-sm btn-outline-danger btn-action">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:56px"></th>
                                    <th>Họ tên</th>
                                    <th>Lớp / Ngành</th>
                                    <th>Cấp bậc</th>
                                    <th>Vai trò</th>
                                    <th>Liên hệ</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hdt_list as $hdt):
                                    $avatar = hdt_avatar_src(hdt_val($hdt, 'avatar'));
                                    $cap = hdt_val($hdt, 'loai_huynh', 'Huynh Trưởng');
                                    $cap_label = $cap === 'Huynh Dự Trưởng' ? 'Dự Trưởng' : $cap;
                                    $is_hdt = $cap === 'Huynh Dự Trưởng';
                                    $full_name = trim(($hdt['ten_thanh'] ? $hdt['ten_thanh'] . ' ' : '') . $hdt['ho_ten']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="avatar-sm">
                                            <?php if ($avatar): ?>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(hdt_initials(hdt_val($hdt, 'ten_thanh'), hdt_val($hdt, 'ho_ten'))); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($full_name); ?></div>
                                        <div class="small text-muted">
                                            <?php
                                            $bits = [];
                                            if (!empty($hdt['ngay_sinh'])) $bits[] = date('d/m/Y', strtotime($hdt['ngay_sinh']));
                                            if (!empty($hdt['gioi_tinh'])) $bits[] = $hdt['gioi_tinh'];
                                            echo htmlspecialchars(implode(' · ', $bits));
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($hdt['ten_lop']); ?></div>
                                        <span class="badge-nganh"><?php echo htmlspecialchars($hdt['nganh'] ?: '—'); ?></span>
                                    </td>
                                    <td><span class="badge-cap <?php echo $is_hdt ? 'badge-hdt' : 'badge-ht'; ?>"><?php echo htmlspecialchars($cap_label); ?></span></td>
                                    <td><span class="badge-role"><?php echo htmlspecialchars($hdt['vai_tro'] ?: '—'); ?></span></td>
                                    <td class="small">
                                        <?php if (!empty($hdt['so_dien_thoai'])): ?>
                                            <div><i class="fa-solid fa-phone me-1 text-muted"></i><?php echo htmlspecialchars($hdt['so_dien_thoai']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($hdt['email'])): ?>
                                            <div class="text-truncate" style="max-width:180px"><i class="fa-solid fa-envelope me-1 text-muted"></i><?php echo htmlspecialchars($hdt['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editHDTModal"
                                            onclick="loadHDT(<?php echo (int)$hdt['id']; ?>, <?php echo htmlspecialchars(json_encode($hdt['ho_ten']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'ten_thanh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'ngay_sinh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'gioi_tinh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'so_dien_thoai')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'email')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'vai_tro')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(hdt_val($hdt, 'loai_huynh')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)$hdt['class_id']; ?>)">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Xác nhận xóa?');">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$hdt['id']; ?>">
                                            <button type="submit" name="delete_hdt" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="mt-4">
                    <a href="<?php echo htmlspecialchars(app_url('index.php')); ?>" class="btn btn-outline-secondary btn-action">
                        <i class="fa-solid fa-arrow-left me-1"></i>Quay lại trang chủ
                    </a>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Thêm -->
    <div class="modal fade" id="addHDTModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header text-white" style="background:linear-gradient(135deg,#1565c0,#42a5f5)">
                    <h5 class="modal-title fw-bold">Thêm Huynh Trưởng / Dự Trưởng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addHDTForm">
                        <input type="hidden" name="add_hdt" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Họ Tên</label>
                                <input type="text" name="ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Tên Thánh</label>
                                <input type="text" name="ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Giới Tính</label>
                                <select name="gioi_tinh" class="form-select" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Lớp</label>
                                <select name="class_id" class="form-select" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Cấp Bậc</label>
                                <select name="loai_huynh" class="form-select" required>
                                    <option value="Huynh Trưởng">Huynh Trưởng</option>
                                    <option value="Huynh Dự Trưởng">Dự Trưởng</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Vai Trò</label>
                                <select name="vai_tro" class="form-select" required>
                                    <option value="Trưởng Lớp">Trưởng Lớp</option>
                                    <option value="Phó Lớp">Phó Lớp</option>
                                    <option value="Huynh Dự Trưởng">Dự Trưởng</option>
                                    <option value="Phó Huynh Dự Trưởng">Phó Dự Trưởng</option>
                                    <option value="Thư Ký">Thư Ký</option>
                                    <option value="Thủ Quỹ">Thủ Quỹ</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4 text-end">
                            <button type="button" class="btn btn-outline-secondary btn-action me-2" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-primary-soft px-4">Thêm</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sửa -->
    <div class="modal fade" id="editHDTModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header text-white" style="background:linear-gradient(135deg,#1565c0,#42a5f5)">
                    <h5 class="modal-title fw-bold">Sửa thông tin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editHDTForm">
                        <input type="hidden" name="edit_hdt" value="1">
                        <input type="hidden" name="teacher_id" id="edit_hdt_teacher_id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Họ Tên</label>
                                <input type="text" name="ho_ten" id="edit_hdt_ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Tên Thánh</label>
                                <input type="text" name="ten_thanh" id="edit_hdt_ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" id="edit_hdt_ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Giới Tính</label>
                                <select name="gioi_tinh" id="edit_hdt_gioi_tinh" class="form-select" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Lớp</label>
                                <select name="class_id" id="edit_hdt_class_id" class="form-select" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" id="edit_hdt_so_dien_thoai" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" name="email" id="edit_hdt_email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Cấp Bậc</label>
                                <select name="loai_huynh" id="edit_hdt_loai_huynh" class="form-select" required>
                                    <option value="Huynh Trưởng">Huynh Trưởng</option>
                                    <option value="Huynh Dự Trưởng">Dự Trưởng</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Vai Trò</label>
                                <select name="vai_tro" id="edit_hdt_vai_tro" class="form-select" required>
                                    <option value="Trưởng Lớp">Trưởng Lớp</option>
                                    <option value="Phó Lớp">Phó Lớp</option>
                                    <option value="Huynh Dự Trưởng">Dự Trưởng</option>
                                    <option value="Phó Huynh Dự Trưởng">Phó Dự Trưởng</option>
                                    <option value="Thư Ký">Thư Ký</option>
                                    <option value="Thủ Quỹ">Thủ Quỹ</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4 text-end">
                            <button type="button" class="btn btn-outline-secondary btn-action me-2" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-primary-soft px-4">Lưu</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Import CSV -->
    <div class="modal fade" id="importCSVModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header text-white" style="background:linear-gradient(135deg,#1565c0,#42a5f5)">
                    <h5 class="modal-title fw-bold">Import từ CSV</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <h6 class="fw-bold"><i class="fa-solid fa-info-circle me-2"></i>Hướng dẫn</h6>
                        <ul class="mb-0 small">
                            <li>File CSV UTF-8, cột: ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, loai_huynh, vai_tro, ten_lop</li>
                            <li>Ngày sinh: YYYY-MM-DD · Giới tính: Nam/Nữ · Cấp bậc: Huynh Trưởng / Huynh Dự Trưởng</li>
                            <li>SĐT 10 số (có thể trống) · Tên lớp phải tồn tại trong hệ thống</li>
                        </ul>
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="importCSVForm">
                        <input type="hidden" name="import_csv" value="1">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Chọn file CSV</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        </div>
                        <div class="mb-3">
                            <a href="<?php echo htmlspecialchars(app_url('download_sample_csv.php')); ?>" class="btn btn-outline-primary btn-action">
                                <i class="fa-solid fa-download me-1"></i>Tải file mẫu
                            </a>
                        </div>
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-outline-secondary btn-action" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-primary-soft px-4">
                                <i class="fa-solid fa-upload me-1"></i>Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
                    alert('SĐT phải gồm đúng 10 số. Không thể lưu.');
                    return false;
                }
                return true;
            });
        }

        function loadHDT(id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, vai_tro, loai_huynh, class_id) {
            document.getElementById('edit_hdt_teacher_id').value = id || '';
            document.getElementById('edit_hdt_ho_ten').value = ho_ten || '';
            document.getElementById('edit_hdt_ten_thanh').value = ten_thanh || '';
            document.getElementById('edit_hdt_ngay_sinh').value = ngay_sinh || '';
            document.getElementById('edit_hdt_gioi_tinh').value = gioi_tinh || '';
            document.getElementById('edit_hdt_so_dien_thoai').value = so_dien_thoai || '';
            document.getElementById('edit_hdt_email').value = email || '';
            document.getElementById('edit_hdt_vai_tro').value = vai_tro || '';
            document.getElementById('edit_hdt_loai_huynh').value = loai_huynh || '';
            document.getElementById('edit_hdt_class_id').value = class_id || '';
        }

        $(document).ready(function() {
            attachPhoneValidation('#addHDTForm', "input[name='so_dien_thoai']");
            attachPhoneValidation('#editHDTForm', '#edit_hdt_so_dien_thoai');
        });
    </script>
</body>
</html>
