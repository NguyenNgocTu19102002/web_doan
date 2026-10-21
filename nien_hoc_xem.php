<?php
session_start();
require_once 'login_redirect.php';
require_once 'db_connect.php';
require_once 'includes/academic_year.php';

$logged_in = isset($_SESSION['user_id']);
if (!$logged_in) {
    require_login_redirect();
}

$current_role = login_current_role();
if (!login_is_huynh_truong($current_role) && !login_can_manage_academic_year($current_role)) {
    header('Location: index.php');
    exit;
}

$is_huynh_truong = login_is_huynh_truong($current_role);
$ht_class_ids = array();
if ($is_huynh_truong) {
    $ht_class_ids = ay_user_teacher_class_ids($conn, (int)$_SESSION['user_id']);
}

ay_ensure_schema($conn);
$years = ay_list_years($conn);
$year_stats = array();
foreach ($years as $y) {
    $sid = (int)$y['id'];
    $year_stats[$sid] = ay_year_summary_stats(
        $conn,
        $sid,
        $is_huynh_truong ? $ht_class_ids : null
    );
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng Kết Năm Học - ĐOÀN TNTT</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: #e3f2fd; }
        header { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; padding: 1.25rem 0; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 8px 24px rgba(0,0,0,0.08); margin-bottom: 1rem; }
        .card-header { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; }
    </style>
</head>
<body class="page-classes">
<header class="text-center">
    <div class="container">
        <h1 class="h4 mb-1"><i class="fas fa-history me-2"></i>Tổng Kết Năm Học</h1>
        <p class="mb-0 small opacity-75">Xem lại điểm, điểm danh và thông tin lớp các năm học đã lưu</p>
        <?php if ($is_huynh_truong): ?>
            <p class="mb-0 small opacity-75 mt-1"><i class="fas fa-info-circle me-1"></i>Chỉ hiển thị lớp bạn phụ trách</p>
        <?php endif; ?>
    </div>
</header>

<main class="container my-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($is_huynh_truong && empty($ht_class_ids)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Tài khoản chưa được gán lớp phụ trách. Liên hệ Quản lý HDT để được phân công lớp trên hệ thống.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-list me-2"></i>Danh sách năm học</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Năm học</th>
                            <th>Trạng thái</th>
                            <th>Số lớp</th>
                            <th>Số đoàn sinh</th>
                            <th>ĐTB chung</th>
                            <th>Ngày lưu tổng kết</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($years as $y): ?>
                            <?php
                            $sid = (int)$y['id'];
                            $st = isset($year_stats[$sid]) ? $year_stats[$sid] : array('classes' => 0, 'students' => 0, 'avg_tb' => null);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($y['ten_nam_hoc']); ?></strong></td>
                                <td>
                                    <?php if ($y['trang_thai'] === 'active' && !empty($y['is_current'])): ?>
                                        <span class="badge bg-success">Đang hoạt động</span>
                                    <?php elseif ($y['trang_thai'] === 'archived'): ?>
                                        <span class="badge bg-secondary">Đã tổng kết</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($y['trang_thai']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$st['classes']; ?></td>
                                <td><?php echo (int)$st['students']; ?></td>
                                <td><?php echo $st['avg_tb'] !== null ? $st['avg_tb'] : '—'; ?></td>
                                <td><?php echo !empty($y['archived_at']) ? date('d/m/Y H:i', strtotime($y['archived_at'])) : '—'; ?></td>
                                <td>
                                    <?php if ($st['students'] > 0): ?>
                                        <a href="nien_hoc_tong_ket.php?id=<?php echo $sid; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye me-1"></i>Xem tổng kết
                                        </a>
                                    <?php elseif ($y['trang_thai'] === 'active'): ?>
                                        <span class="text-muted small">Chưa lưu tổng kết</span>
                                    <?php else: ?>
                                        <span class="text-muted small">Chưa có dữ liệu</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($years)): ?>
                            <tr><td colspan="7" class="text-center text-muted">Chưa có năm học nào.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="<?php echo $is_huynh_truong ? 'tai_khoan.php' : 'index.php'; ?>" class="btn btn-outline-primary">
        <i class="fas fa-arrow-left me-1"></i><?php echo $is_huynh_truong ? 'Quay lại tài khoản' : 'Trang chủ'; ?>
    </a>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
