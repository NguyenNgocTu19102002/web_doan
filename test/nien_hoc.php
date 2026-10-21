<?php
session_start();
require_once 'login_redirect.php';
require_once 'db_connect.php';
require_once 'includes/academic_year.php';

$logged_in = isset($_SESSION['user_id']);
if (!$logged_in) {
    require_login_redirect();
}

$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$is_hdt_manager = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'hdt_manager';
if (!isset($_SESSION['user_role']) && isset($_SESSION['role'])) {
    $is_hdt_manager = $_SESSION['role'] === 'hdt_manager';
    $is_admin = $_SESSION['role'] === 'admin';
}
if (!$is_admin && !$is_hdt_manager) {
    $_SESSION['error'] = 'Chỉ tài khoản Quản lý HDT mới được truy cập quản lý năm học.';
    header('Location: index.php');
    exit;
}

ay_ensure_schema($conn);
$current_year = ay_get_current_year($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_academic_year_snapshot'])) {
    $confirm_code = isset($_POST['confirm_code']) ? $_POST['confirm_code'] : '';
    if (!ay_verify_end_confirm_code($confirm_code)) {
        $_SESSION['error'] = 'Mã xác nhận không đúng.';
    } else {
        $ghi_chu = isset($_POST['ghi_chu']) ? trim($_POST['ghi_chu']) : '';
        $result = ay_save_year_snapshot($conn, (int)$current_year['id'], $current_year['ten_nam_hoc'], $ghi_chu);
        if ($result['ok']) {
            $_SESSION['success'] = 'Đã lưu tổng kết năm học ' . $current_year['ten_nam_hoc']
                . ': ' . $result['class_count'] . ' lớp, ' . $result['student_count'] . ' đoàn sinh.'
                . ' Điểm và điểm danh hiện tại vẫn giữ nguyên.';
        } else {
            $_SESSION['error'] = $result['message'];
        }
    }
    header('Location: nien_hoc.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_academic_year'])) {
    $confirm = isset($_POST['confirm_year']) ? trim($_POST['confirm_year']) : '';
    $confirm_code = isset($_POST['confirm_code']) ? $_POST['confirm_code'] : '';
    if (!ay_verify_end_confirm_code($confirm_code)) {
        $_SESSION['error'] = 'Mã xác nhận tổng kết năm học không đúng.';
    } elseif ($confirm !== $current_year['ten_nam_hoc']) {
        $_SESSION['error'] = 'Tên năm học xác nhận không khớp. Không thực hiện kết thúc năm.';
    } else {
        $ghi_chu = isset($_POST['ghi_chu']) ? trim($_POST['ghi_chu']) : '';
        $result = ay_end_year_and_start_new($conn, $ghi_chu);
        if ($result['ok']) {
            $_SESSION['success'] = 'Đã kết thúc năm học ' . $result['archived_year']
                . '. Tổng kết: ' . $result['class_count'] . ' lớp, ' . $result['student_count'] . ' đoàn sinh.'
                . ' Năm mới: ' . $result['new_year'] . '. Đã reset điểm và điểm danh.';
        } else {
            $_SESSION['error'] = $result['message'];
        }
    }
    header('Location: nien_hoc.php');
    exit;
}

$years = ay_list_years($conn);
$year_stats = [];
foreach ($years as $y) {
    $year_stats[(int)$y['id']] = ay_year_summary_stats($conn, (int)$y['id']);
}

$att_count = 0;
$grade_count = 0;
$r1 = $conn->query('SELECT COUNT(*) AS c FROM attendance');
if ($r1 && ($row = $r1->fetch_assoc())) {
    $att_count = (int)$row['c'];
}
$r2 = $conn->query('SELECT COUNT(*) AS c FROM grades');
if ($r2 && ($row = $r2->fetch_assoc())) {
    $grade_count = (int)$row['c'];
}
$next_year_label = ay_next_nien_khoa_label($current_year['ten_nam_hoc']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Năm Học - ĐOÀN TNTT</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: #e3f2fd; }
        header { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; padding: 1.25rem 0; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .card-header { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; border-radius: 1rem 1rem 0 0 !important; }
        .year-badge-active { font-size: 0.85rem; }
        .stat-box { background: #f8f9fa; border-radius: 10px; padding: 1rem; text-align: center; }
        .stat-box h3 { margin: 0; color: #1565c0; }
        @media (max-width: 768px) {
            .table { font-size: 0.85rem; }
            .btn { font-size: 0.85rem; }
        }
    </style>
</head>
<body class="page-classes">
<header class="text-center">
    <div class="container">
        <h1 class="h3 mb-1"><i class="fas fa-calendar-alt me-2"></i>Quản Lý Năm Học</h1>
        <p class="mb-0 opacity-75">Dành cho Quản lý Huynh Dự Trưởng</p>
    </div>
</header>

<main class="container my-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-box">
                <div class="text-muted small">Năm học hiện tại</div>
                <h3><?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></h3>
                <span class="badge bg-success year-badge-active">Đang hoạt động</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="text-muted small">Bản ghi điểm danh (sẽ reset khi kết thúc năm)</div>
                <h3><?php echo number_format($att_count); ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="text-muted small">Bản ghi điểm số (sẽ reset khi kết thúc năm)</div>
                <h3><?php echo number_format($grade_count); ?></h3>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-save me-2"></i>Lưu tổng kết năm học <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></div>
        <div class="card-body">
            <p>Lưu toàn bộ dữ liệu năm học vừa qua vào bản tổng kết <strong><?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></strong>:</p>
            <ul>
                <li><strong>Lớp:</strong> tên lớp, ngành, sĩ số, HT đứng lớp, HT phụ lớp</li>
                <li><strong>Điểm:</strong> 15p, 1 tiết, cuối kỳ từng đoàn sinh + điểm TB + xếp hạng</li>
                <li><strong>Điểm danh:</strong> vắng có phép, không phép, đi muộn, % nghỉ</li>
            </ul>
            <p class="text-muted small mb-3">Thao tác này <strong>không xóa</strong> điểm/điểm danh hiện tại. Có thể lưu lại nếu cần cập nhật tổng kết.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#saveSnapshotModal">
                <i class="fas fa-database me-2"></i>Lưu tổng kết <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?>
            </button>
            <?php
            $cur_stats = ay_year_summary_stats($conn, (int)$current_year['id']);
            if ($cur_stats['students'] > 0):
            ?>
                <a href="nien_hoc_tong_ket.php?id=<?php echo (int)$current_year['id']; ?>" class="btn btn-outline-primary ms-2">
                    <i class="fas fa-eye me-1"></i>Xem tổng kết đã lưu
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-flag-checkered me-2"></i>Kết thúc năm học & bắt đầu năm mới</div>
        <div class="card-body">
            <p>Khi kết thúc năm học <strong><?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></strong>, hệ thống sẽ:</p>
            <ul>
                <li>Lưu <strong>tổng kết</strong> (lớp, HT, điểm từng loại, điểm danh chi tiết từng đoàn sinh)</li>
                <li>Chuyển năm <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?> vào mục <strong>Tổng kết năm học</strong></li>
                <li>Bắt đầu năm mới <strong><?php echo htmlspecialchars($next_year_label); ?></strong> (cập nhật niên khóa tất cả lớp)</li>
                <li><strong class="text-danger">Xóa toàn bộ</strong> điểm danh và điểm số hiện tại (danh sách đoàn sinh & lớp giữ nguyên)</li>
            </ul>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#endYearModal">
                <i class="fas fa-archive me-2"></i>Kết thúc năm học <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?>
            </button>
            <p class="text-muted small mt-2 mb-0"><i class="fas fa-lock me-1"></i>Cần mã xác nhận tổng kết năm học và nhập đúng tên năm.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-history me-2"></i>Danh sách & Tổng kết năm học</div>
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
                            <?php $sid = (int)$y['id']; $st = isset($year_stats[$sid]) ? $year_stats[$sid] : ['classes'=>0,'students'=>0,'avg_tb'=>null]; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($y['ten_nam_hoc']); ?></strong></td>
                                <td>
                                    <?php if ($y['trang_thai'] === 'active' && $y['is_current']): ?>
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a href="classes.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>Quay lại quản lý lớp</a>
    </div>
</main>

<div class="modal fade" id="saveSnapshotModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" onsubmit="return confirm('Lưu tổng kết năm học <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?>? Dữ liệu tổng kết cũ (nếu có) sẽ được ghi đè.');">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-save me-2"></i>Lưu tổng kết năm học</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="save_academic_year_snapshot" value="1">
                <p>Lưu toàn bộ thông tin lớp, điểm, điểm danh vào năm học <strong><?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></strong>.</p>
                <p class="text-muted small">Không xóa điểm/điểm danh hiện tại. Có thể lưu lại sau nếu cần cập nhật.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Mã xác nhận <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_code" class="form-control" placeholder="Nhập mã xác nhận" required autocomplete="off">
                </div>
                <label class="form-label">Ghi chú (tuỳ chọn)</label>
                <textarea name="ghi_chu" class="form-control" rows="2" placeholder="VD: Tổng kết sau lễ bổn mạng..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Lưu tổng kết</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="endYearModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" onsubmit="return confirm('Bạn chắc chắn muốn kết thúc năm học? Hành động này không thể hoàn tác.');">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Kết thúc năm học</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="end_academic_year" value="1">
                <p class="text-danger fw-bold">Thao tác này sẽ xóa toàn bộ điểm và điểm danh hiện tại sau khi lưu tổng kết.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Mã xác nhận tổng kết năm học <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_code" class="form-control" placeholder="Nhập mã xác nhận" required autocomplete="off">
                    <div class="form-text">Chỉ Quản lý HDT có mã mới được kết thúc năm học.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nhập chính xác tên năm học <span class="text-danger">*</span></label>
                    <p class="mb-2"><code><?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?></code></p>
                    <input type="text" name="confirm_year" class="form-control" placeholder="Nhập <?php echo htmlspecialchars($current_year['ten_nam_hoc']); ?>" required autocomplete="off">
                </div>
                <label class="form-label">Ghi chú tổng kết (tuỳ chọn)</label>
                <textarea name="ghi_chu" class="form-control" rows="2" placeholder="VD: Năm học kết thúc sau lễ bổn mạng..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-danger">Xác nhận kết thúc năm</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
