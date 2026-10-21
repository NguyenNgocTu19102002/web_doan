<?php
session_start();
require_once 'login_redirect.php';
$logged_in = isset($_SESSION['user_id']);

// Kiểm tra quyền truy cập
if (!$logged_in) {
    require_login_redirect();
}

// Kiểm tra quyền admin hoặc hdt_manager
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$is_hdt_manager = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'hdt_manager';
if (!isset($_SESSION['user_role']) && isset($_SESSION['role'])) {
    $is_admin = $_SESSION['role'] === 'admin';
    $is_hdt_manager = $_SESSION['role'] === 'hdt_manager';
}

if (!$is_admin && !$is_hdt_manager) {
    die("Bạn không có quyền truy cập trang này.");
}

// Kết nối database
require_once 'db_connect.php';

// Xử lý chọn ngày
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_activity = isset($_GET['activity']) ? $_GET['activity'] : 'Học Giáo Lý';

// Lấy danh sách tất cả các lớp
$classes_query = "SELECT id, ten_lop, nganh FROM classes ORDER BY nganh, ten_lop";
$classes_result = $conn->query($classes_query);
$all_classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $all_classes[$row['id']] = $row;
}

// Lấy tổng số đoàn sinh
$total_students_query = "SELECT COUNT(*) as total FROM students WHERE status = 'active'";
$total_students_result = $conn->query($total_students_query);
$total_students = $total_students_result->fetch_assoc()['total'];

// Thống kê điểm danh theo status
$stats_query = "SELECT 
    status,
    COUNT(*) as count
FROM attendance 
WHERE ngay_diem_danh = ? AND activity_type = ?
GROUP BY status";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("ss", $selected_date, $selected_activity);
$stmt->execute();
$stats_result = $stmt->get_result();

$stats = [
    'có' => 0,
    'Có mặt' => 0,
    'đi muộn' => 0,
    'Đi muộn' => 0,
    'vắng có phép' => 0,
    'Vắng có phép' => 0,
    'vắng không phép' => 0,
    'Vắng mặt' => 0
];

while ($row = $stats_result->fetch_assoc()) {
    $status = $row['status'];
    if (isset($stats[$status])) {
        $stats[$status] = $row['count'];
    }
}

// Tính tổng hợp (chuẩn hóa tên status)
$total_present = $stats['có'] + $stats['Có mặt'];
$total_late = $stats['đi muộn'] + $stats['Đi muộn'];
$total_excused = $stats['vắng có phép'] + $stats['Vắng có phép'];
$total_absent = $stats['vắng không phép'] + $stats['Vắng mặt'];
$total_attended = $total_present + $total_late + $total_excused;
$total_not_attended = $total_absent;
$total_recorded = $total_attended + $total_not_attended;

// Lấy danh sách lớp đã điểm danh
$classes_with_attendance_query = "SELECT DISTINCT class_id 
FROM attendance 
WHERE ngay_diem_danh = ? AND activity_type = ?";
$stmt2 = $conn->prepare($classes_with_attendance_query);
$stmt2->bind_param("ss", $selected_date, $selected_activity);
$stmt2->execute();
$classes_with_result = $stmt2->get_result();
$classes_with_attendance = [];
while ($row = $classes_with_result->fetch_assoc()) {
    $classes_with_attendance[] = $row['class_id'];
}

// Lấy danh sách lớp chưa điểm danh
$classes_without_attendance = [];
foreach ($all_classes as $class_id => $class) {
    if (!in_array($class_id, $classes_with_attendance)) {
        // Kiểm tra xem lớp có đoàn sinh không
        $students_count_query = "SELECT COUNT(*) as count FROM students WHERE class_id = $class_id AND status = 'active'";
        $students_count_result = $conn->query($students_count_query);
        $students_count = $students_count_result->fetch_assoc()['count'];
        
        if ($students_count > 0) {
            $classes_without_attendance[] = [
                'id' => $class_id,
                'ten_lop' => $class['ten_lop'],
                'nganh' => $class['nganh'],
                'students_count' => $students_count
            ];
        }
    }
}

// Lấy chi tiết điểm danh theo lớp
$attendance_by_class = [];
foreach ($all_classes as $class_id => $class) {
    $class_stats_query = "SELECT 
        status,
        COUNT(*) as count
    FROM attendance 
    WHERE class_id = ? AND ngay_diem_danh = ? AND activity_type = ?
    GROUP BY status";
    $stmt3 = $conn->prepare($class_stats_query);
    $stmt3->bind_param("iss", $class_id, $selected_date, $selected_activity);
    $stmt3->execute();
    $class_stats_result = $stmt3->get_result();
    
    $class_stats = [
        'có' => 0,
        'Có mặt' => 0,
        'đi muộn' => 0,
        'Đi muộn' => 0,
        'vắng có phép' => 0,
        'Vắng có phép' => 0,
        'vắng không phép' => 0,
        'Vắng mặt' => 0
    ];
    
    while ($row = $class_stats_result->fetch_assoc()) {
        $status = $row['status'];
        if (isset($class_stats[$status])) {
            $class_stats[$status] = $row['count'];
        }
    }
    
    $present = $class_stats['có'] + $class_stats['Có mặt'];
    $late = $class_stats['đi muộn'] + $class_stats['Đi muộn'];
    $excused = $class_stats['vắng có phép'] + $class_stats['Vắng có phép'];
    $absent = $class_stats['vắng không phép'] + $class_stats['Vắng mặt'];
    $total = $present + $late + $excused + $absent;
    
    if ($total > 0 || in_array($class_id, $classes_with_attendance)) {
        // Lấy số lượng học sinh của lớp
        $students_count_query = "SELECT COUNT(*) as count FROM students WHERE class_id = $class_id AND status = 'active'";
        $students_count_result = $conn->query($students_count_query);
        $students_count = $students_count_result->fetch_assoc()['count'];
        
        $attendance_by_class[] = [
            'id' => $class_id,
            'ten_lop' => $class['ten_lop'],
            'nganh' => $class['nganh'],
            'present' => $present,
            'late' => $late,
            'excused' => $excused,
            'absent' => $absent,
            'total' => $total,
            'students_count' => $students_count,
            'has_attendance' => $total > 0
        ];
    }
}

$page_title = 'Quản lý điểm danh tổng hợp';
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="vi" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600;700&family=Dancing+Script:wght@600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { 
            --primary: #86d3ffff; 
            --accent: #45d4ffff; 
            --success: #10B981; 
            --bg: #f2fdffff; 
            --card: #FFFFFF; 
            --text: #0288D1; 
            --shadow: 0 10px 25px rgba(0,0,0,0.1); 
            --stats-text: #03b4d3ff; 
        }
        [data-theme="dark"] { --bg: #0F172A; --card: #1E293B; --text: #F1F5F9; --stats-text: #CE93D8; }
        body { 
            background: linear-gradient(135deg, var(--bg) 0%, #b7fcf5ff 100%); 
            color: var(--text); 
            font-family: 'Roboto', sans-serif; 
            transition: all 0.3s; 
        }
        header { 
            background: linear-gradient(135deg, var(--primary), var(--accent)); 
            box-shadow: var(--shadow); 
            padding: 1rem 0; 
            border-radius: 0 0 2rem 2rem; 
        }
        .header-content { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
        }
        header img { 
            max-width: 100px; 
            transition: transform 0.4s; 
        }
        header img:hover { 
            transform: rotate(360deg) scale(1.1); 
        }
        header h1 { 
            font-size: 2rem; 
            margin: 0;
            flex: 1;
            text-align: center;
        }
        .navbar-nav .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
        }
        
        /* Custom styles for attendance overview */
    .stats-card {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        transition: transform 0.3s ease;
        text-align: center;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }
    
    .stats-card h3 {
        font-size: 1.1rem;
        color: #666;
        margin-bottom: 15px;
        font-weight: 600;
    }
    
    .stats-card .number {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 5px;
    }
    
    .stats-card.present .number { color: #28a745; }
    .stats-card.late .number { color: #007bff; }
    .stats-card.excused .number { color: #ffc107; }
    .stats-card.absent .number { color: #dc3545; }
    .stats-card.total .number { color: #6c757d; }
    
    .alert-warning-custom {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .alert-warning-custom h4 {
        color: #856404;
        margin-bottom: 15px;
    }
    
    .alert-warning-custom ul {
        margin: 0;
        padding-left: 20px;
    }
    
    .alert-warning-custom li {
        margin-bottom: 8px;
        color: #856404;
    }
    
    .table-responsive {
        border-radius: 10px;
        overflow: hidden;
    }
    
    .badge-status {
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    
        .badge-present { background: #d4edda; color: #155724; }
        .badge-late { background: #cce5ff; color: #004085; }
        .badge-excused { background: #fff3cd; color: #856404; }
        .badge-absent { background: #f8d7da; color: #721c24; }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .btn.btn-light[onclick="toggleTheme()"] {
                display: none !important;
            }
            
            header h1 {
                font-size: 1.2rem;
            }
            
            header img {
                max-width: 60px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .stats-card {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .stats-card .number {
                font-size: 2rem;
            }
            
            .stats-card h3 {
                font-size: 0.95rem;
            }
            
            .table {
                font-size: 0.85rem;
            }
            
            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .alert-warning-custom {
                padding: 15px;
            }
            
            .navbar-nav {
                text-align: center;
            }
            
            .navbar-nav .nav-link {
                padding: 10px 15px;
            }
        }
        
        @media (max-width: 576px) {
            header h1 {
                font-size: 1rem;
            }
            
            header img {
                max-width: 50px;
            }
            
            .stats-card {
                padding: 12px;
            }
            
            .stats-card .number {
                font-size: 1.8rem;
            }
            
            .stats-card h3 {
                font-size: 0.9rem;
            }
            
            .table {
                font-size: 0.75rem;
            }
            
            .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.85rem;
            }
            
            .badge-status {
                font-size: 0.75rem;
                padding: 3px 8px;
            }
            
            /* Form điểm danh trên mobile */
            .card-body .row.g-3.mb-3 {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 0.5rem !important;
                margin-bottom: 1rem !important;
            }
            
            .card-body .row.g-3.mb-3 .col-6 {
                flex: 0 0 calc(50% - 0.25rem) !important;
                max-width: calc(50% - 0.25rem) !important;
            }
            
            .card-body .form-label {
                font-size: 0.8rem !important;
                margin-bottom: 0.25rem !important;
                text-align: left !important;
            }
            
            .card-body .form-control,
            .card-body .form-select {
                font-size: 0.85rem !important;
                padding: 0.5rem !important;
            }
            
            .card-body .btn {
                font-size: 0.9rem !important;
                padding: 0.6rem !important;
            }
            
            .card.mb-4 {
                border-radius: 1rem !important;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .card-body {
                padding: 1rem !important;
            }
            
            main .container h1 {
                font-size: 1.3rem !important;
            }
            
            /* Căn giữa stats card */
            .stats-card {
                text-align: center !important;
            }
        }
    </style>
</head>
<body>
    <header class="text-white text-center">
        <div class="container">
            <div class="header-content">
                <img src="img/logo.png" alt="Logo Đoàn">
                <h1>
                    ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ
                    <?php if ($is_admin): ?>
                        <span class="badge bg-danger ms-2" style="font-size: 0.5em; vertical-align: middle;">
                            <i class="fas fa-crown"></i> Admin
                        </span>
                    <?php elseif ($is_hdt_manager): ?>
                        <span class="badge bg-success ms-2" style="font-size: 0.5em; vertical-align: middle;">
                            <i class="fas fa-user-shield"></i> HDT Manager
                        </span>
                    <?php endif; ?>
                </h1>
                <button class="btn btn-light" onclick="toggleTheme()"><i class="fas fa-moon"></i></button>
            </div>
            <nav class="navbar navbar-expand-lg navbar-dark mt-3">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Trang chủ</a></li>
                            <li class="nav-item"><a class="nav-link" href="classes.php"><i class="fas fa-users"></i> Quản lý đoàn sinh</a></li>
                            <li class="nav-item"><a class="nav-link active" href="diemdanh.php"><i class="fas fa-calendar-check"></i> Quản lý điểm danh</a></li>
                            <li class="nav-item"><a class="nav-link" href="loichua.php"><i class="fas fa-bible"></i> Lời Chúa</a></li>
                            <?php if ($logged_in): ?>
                                <li class="nav-item"><a class="nav-link" href="tai_khoan.php"><i class="fas fa-user-circle"></i> Tài khoản</a></li>
                                <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a></li>
                            <?php else: ?>
                                <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars(login_page_url()); ?>"><i class="fas fa-sign-in-alt"></i> Đăng nhập</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    </header>
    <main class="container my-5">
<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-4 text-center" style="font-size: 1.6rem;"><i class="fas fa-calendar-check me-2"></i>Quản lý điểm danh tổng hợp</h1>
            
            <!-- Form chọn ngày và loại hoạt động -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="diemdanh.php">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4 col-6">
                                <label for="date" class="form-label">Chọn ngày</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                            </div>
                            <div class="col-md-4 col-6">
                                <label for="activity" class="form-label">Loại hoạt động</label>
                                <select class="form-select" id="activity" name="activity" required>
                                    <option value="Học Giáo Lý" <?php echo $selected_activity == 'Học Giáo Lý' ? 'selected' : ''; ?>>Học Giáo Lý</option>
                                    <option value="Đi lễ" <?php echo $selected_activity == 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i>Xem điểm danh
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Thống kê tổng hợp -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card present">
                <h3><i class="fas fa-check-circle me-2"></i>Có mặt</h3>
                <div class="number"><?php echo $total_present; ?></div>
                <small class="text-muted">Tổng: <?php echo $total_students; ?> đoàn sinh</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card late">
                <h3><i class="fas fa-clock me-2"></i>Đi muộn</h3>
                <div class="number"><?php echo $total_late; ?></div>
                <small class="text-muted">Tổng: <?php echo $total_students; ?> đoàn sinh</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card excused">
                <h3><i class="fas fa-user-times me-2"></i>Vắng có phép</h3>
                <div class="number"><?php echo $total_excused; ?></div>
                <small class="text-muted">Tổng: <?php echo $total_students; ?> đoàn sinh</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card absent">
                <h3><i class="fas fa-times-circle me-2"></i>Vắng không phép</h3>
                <div class="number"><?php echo $total_not_attended; ?></div>
                <small class="text-muted">Tổng: <?php echo $total_students; ?> đoàn sinh</small>
            </div>
        </div>
    </div>
    
    <!-- Thông báo lớp chưa điểm danh -->
    <?php if (!empty($classes_without_attendance)): ?>
    <div class="alert-warning-custom">
        <h4><i class="fas fa-exclamation-triangle me-2"></i>Cảnh báo: Có <?php echo count($classes_without_attendance); ?> lớp chưa điểm danh ngày <?php echo date('d/m/Y', strtotime($selected_date)); ?></h4>
        <ul>
            <?php foreach ($classes_without_attendance as $class): ?>
                <li>
                    <strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong> 
                    (<?php echo htmlspecialchars($class['nganh']); ?>) 
                    - <?php echo $class['students_count']; ?> đoàn sinh
                    <a href="class_details.php?id=<?php echo $class['id']; ?>&ngay_diem_danh=<?php echo $selected_date; ?>&activity=<?php echo urlencode($selected_activity); ?>" class="btn btn-sm btn-warning ms-2">
                        <i class="fas fa-edit me-1"></i>Điểm danh ngay
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>Tất cả các lớp đã hoàn thành điểm danh cho ngày <?php echo date('d/m/Y', strtotime($selected_date)); ?>
    </div>
    <?php endif; ?>
    
    <!-- Chi tiết điểm danh theo lớp -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-list me-2"></i>Chi tiết điểm danh theo lớp</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Tên lớp</th>
                                    <th>Ngành</th>
                                    <th>Sĩ số</th>
                                    <th>Có mặt</th>
                                    <th>Đi muộn</th>
                                    <th>Vắng có phép</th>
                                    <th>Vắng không phép</th>
                                    <th>Tổng đã điểm danh</th>
                                    <th>Trạng thái</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($attendance_by_class)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted">
                                            Chưa có dữ liệu điểm danh cho ngày <?php echo date('d/m/Y', strtotime($selected_date)); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $stt = 1; foreach ($attendance_by_class as $class): ?>
                                        <tr>
                                            <td><?php echo $stt++; ?></td>
                                            <td><strong><?php echo htmlspecialchars($class['ten_lop']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($class['nganh']); ?></td>
                                            <td><?php echo $class['students_count']; ?></td>
                                            <td><span class="badge badge-status badge-present"><?php echo $class['present']; ?></span></td>
                                            <td><span class="badge badge-status badge-late"><?php echo $class['late']; ?></span></td>
                                            <td><span class="badge badge-status badge-excused"><?php echo $class['excused']; ?></span></td>
                                            <td><span class="badge badge-status badge-absent"><?php echo $class['absent']; ?></span></td>
                                            <td><strong><?php echo $class['total']; ?></strong></td>
                                            <td>
                                                <?php if ($class['total'] == $class['students_count']): ?>
                                                    <span class="badge bg-success">Hoàn thành</span>
                                                <?php elseif ($class['total'] > 0): ?>
                                                    <span class="badge bg-warning">Chưa hoàn thành</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Chưa điểm danh</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="class_details.php?id=<?php echo $class['id']; ?>&ngay_diem_danh=<?php echo $selected_date; ?>&activity=<?php echo urlencode($selected_activity); ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye me-1"></i>Xem chi tiết
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    </main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        html.setAttribute('data-theme', currentTheme === 'dark' ? 'light' : 'dark');
        localStorage.setItem('theme', currentTheme === 'dark' ? 'light' : 'dark');
    }
    
    // Load saved theme
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
</script>
<?php login_include_bo_nhiem_popup(); ?>
</body>
</html>
<?php $conn->close(); ?>
