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
if (!login_can_view_year_summary($current_role)) {
    header('Location: index.php');
    exit;
}

$is_huynh_truong = login_is_huynh_truong($current_role);
$is_hdt_manager = login_is_hdt_manager($current_role);
$year_list_page = login_year_summary_list_page($current_role);
$ht_class_ids = array();
$ht_snap_ids = array();
if ($is_huynh_truong) {
    $ht_class_ids = ay_user_teacher_class_ids($conn, (int)$_SESSION['user_id']);
}

$year_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$year = ay_get_year($conn, $year_id);
if (!$year) {
    $_SESSION['error'] = 'Không tìm thấy năm học.';
    header('Location: ' . $year_list_page);
    exit;
}

if ($is_huynh_truong) {
    $ht_snap_ids = ay_year_class_snapshot_ids_for_classes($conn, $year_id, $ht_class_ids);
}

$stats = ay_year_summary_stats($conn, $year_id, $is_huynh_truong ? $ht_class_ids : null);
if ($stats['students'] <= 0) {
    if ($is_huynh_truong && empty($ht_class_ids)) {
        $_SESSION['error'] = 'Tài khoản chưa được gán lớp phụ trách trên hệ thống.';
    } elseif ($is_huynh_truong) {
        $_SESSION['error'] = 'Năm học này chưa có tổng kết cho lớp bạn phụ trách.';
    } else {
        $_SESSION['error'] = 'Năm học này chưa có dữ liệu tổng kết. Hãy lưu tổng kết trước.';
    }
    header('Location: ' . $year_list_page);
    exit;
}

$is_preview = ($year['trang_thai'] === 'active');

$classes = array();
$class_sql = "SELECT * FROM year_class_snapshots WHERE academic_year_id = $year_id";
if ($is_huynh_truong && !empty($ht_class_ids)) {
    $class_sql .= ' AND class_id IN (' . implode(',', array_map('intval', $ht_class_ids)) . ')';
}
$class_sql .= " ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop";
$res_cls = $conn->query($class_sql);
if ($res_cls) {
    while ($row = $res_cls->fetch_assoc()) {
        $classes[] = $row;
    }
}

$class_id_filter = isset($_GET['class_snap']) ? (int)$_GET['class_snap'] : 0;
if ($class_id_filter > 0) {
    $allowed_filter = false;
    foreach ($classes as $c) {
        if ((int)$c['id'] === $class_id_filter) {
            $allowed_filter = true;
            break;
        }
    }
    if (!$allowed_filter) {
        $class_id_filter = 0;
    }
}

$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$sql_students = "SELECT * FROM year_student_snapshots WHERE academic_year_id = $year_id";
if ($is_huynh_truong && !empty($ht_snap_ids)) {
    $sql_students .= ' AND year_class_snapshot_id IN (' . implode(',', array_map('intval', $ht_snap_ids)) . ')';
}
if ($class_id_filter > 0) {
    $sql_students .= " AND year_class_snapshot_id = $class_id_filter";
}
if ($search !== '') {
    $sql_students .= " AND (ho_ten LIKE '%$search%' OR ten_thanh LIKE '%$search%' OR ten_lop LIKE '%$search%')";
}
$sql_students .= " ORDER BY ten_lop, xep_hang, ho_ten";

$students = [];
$res_st = $conn->query($sql_students);
if ($res_st) {
    while ($row = $res_st->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng Kết <?php echo htmlspecialchars($year['ten_nam_hoc']); ?> - ĐOÀN TNTT</title>
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
        .stat-box { background: #f8f9fa; border-radius: 10px; padding: 0.85rem; text-align: center; }
        .stat-box h4 { margin: 0; color: #1565c0; font-size: 1.35rem; }
        .absent-high { color: #dc3545; font-weight: 600; }
        @media (max-width: 768px) {
            .table { font-size: 0.8rem; }
            .col-hide-mobile { display: none !important; }
            .table-responsive { -webkit-overflow-scrolling: touch; }
        }
    </style>
</head>
<body class="page-classes">
<header class="text-center">
    <div class="container">
        <h1 class="h4 mb-1"><i class="fas fa-clipboard-list me-2"></i>Tổng Kết Năm Học <?php echo htmlspecialchars($year['ten_nam_hoc']); ?></h1>
        <?php if (!empty($year['archived_at'])): ?>
            <p class="mb-0 small opacity-75">Lưu ngày <?php echo date('d/m/Y H:i', strtotime($year['archived_at'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($year['ghi_chu'])): ?>
            <p class="mb-0 small"><em><?php echo htmlspecialchars($year['ghi_chu']); ?></em></p>
        <?php endif; ?>
        <?php if ($is_preview): ?>
            <p class="mb-0 small"><span class="badge bg-warning text-dark">Bản tổng kết đã lưu — năm học vẫn đang hoạt động</span></p>
        <?php endif; ?>
        <?php if ($is_huynh_truong): ?>
            <p class="mb-0 small opacity-75"><i class="fas fa-info-circle me-1"></i>Chỉ hiển thị lớp bạn phụ trách</p>
        <?php endif; ?>
    </div>
</header>

<main class="container my-4">
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="stat-box"><div class="text-muted small">Số lớp</div><h4><?php echo (int)$stats['classes']; ?></h4></div></div>
        <div class="col-md-4"><div class="stat-box"><div class="text-muted small">Số đoàn sinh</div><h4><?php echo (int)$stats['students']; ?></h4></div></div>
        <div class="col-md-4"><div class="stat-box"><div class="text-muted small">Điểm TB chung</div><h4><?php echo $stats['avg_tb'] !== null ? $stats['avg_tb'] : '—'; ?></h4></div></div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-chalkboard me-2"></i>Các lớp năm <?php echo htmlspecialchars($year['ten_nam_hoc']); ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Lớp</th>
                            <th>Ngành</th>
                            <th>Sĩ số</th>
                            <th>Đứng lớp</th>
                            <th>Phụ lớp</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['ten_lop']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['nganh']); ?></td>
                                <td><?php echo (int)$c['si_so']; ?></td>
                                <td class="small"><?php echo htmlspecialchars(!empty($c['truong_lop']) ? $c['truong_lop'] : '—'); ?></td>
                                <td class="small"><?php echo htmlspecialchars(!empty($c['pho_lop']) ? $c['pho_lop'] : '—'); ?></td>
                                <td>
                                    <a href="nien_hoc_tong_ket.php?id=<?php echo $year_id; ?>&class_snap=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-primary">Lọc lớp này</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($classes)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Chưa có dữ liệu lớp.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fas fa-users me-2"></i>Chi tiết đoàn sinh (<?php echo count($students); ?>)</span>
            <?php if ($class_id_filter > 0 && count($classes) > 1): ?>
                <a href="nien_hoc_tong_ket.php?id=<?php echo $year_id; ?>" class="btn btn-sm btn-light">Xem tất cả lớp</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="id" value="<?php echo $year_id; ?>">
                <?php if ($class_id_filter > 0): ?>
                    <input type="hidden" name="class_snap" value="<?php echo $class_id_filter; ?>">
                <?php endif; ?>
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control" placeholder="Tìm theo tên, tên thánh, lớp..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Tìm</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Tên Thánh</th>
                            <th>Họ Tên</th>
                            <th class="col-hide-mobile">NS</th>
                            <th>Lớp</th>
                            <th class="col-hide-mobile">15p1</th>
                            <th class="col-hide-mobile">15p2</th>
                            <th class="col-hide-mobile">1T1</th>
                            <th class="col-hide-mobile">1T2</th>
                            <th class="col-hide-mobile">CK1</th>
                            <th class="col-hide-mobile">CK2</th>
                            <th>ĐTB</th>
                            <th>XH</th>
                            <th title="Vắng có phép">CP</th>
                            <th title="Vắng không phép">KP</th>
                            <th title="Đi muộn">Muộn</th>
                            <th>% Nghỉ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <?php
                            $pct_cls = ((float)$s['att_percent'] > 40) ? 'absent-high' : '';
                            $co_phep = isset($s['att_co_phep']) ? (int)$s['att_co_phep'] : 0;
                            $khong_phep = isset($s['att_khong_phep']) ? (int)$s['att_khong_phep'] : (int)$s['att_absent'];
                            $di_muon = isset($s['att_di_muon']) ? (int)$s['att_di_muon'] : 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['ten_thanh'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($s['ho_ten']); ?></td>
                                <td class="col-hide-mobile"><?php echo $s['ngay_sinh'] ? date('d/m/Y', strtotime($s['ngay_sinh'])) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($s['ten_lop']); ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_15p1'] !== null ? $s['diem_15p1'] : '—'; ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_15p2'] !== null ? $s['diem_15p2'] : '—'; ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_1tiet1'] !== null ? $s['diem_1tiet1'] : '—'; ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_1tiet2'] !== null ? $s['diem_1tiet2'] : '—'; ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_ck1'] !== null ? $s['diem_ck1'] : '—'; ?></td>
                                <td class="col-hide-mobile"><?php echo $s['diem_ck2'] !== null ? $s['diem_ck2'] : '—'; ?></td>
                                <td><?php echo $s['diem_tb'] !== null ? $s['diem_tb'] : '—'; ?></td>
                                <td><?php echo $s['xep_hang'] !== null ? (int)$s['xep_hang'] : '—'; ?></td>
                                <td><?php echo $co_phep; ?></td>
                                <td><?php echo $khong_phep; ?></td>
                                <td><?php echo $di_muon; ?></td>
                                <td class="<?php echo $pct_cls; ?>"><?php echo $s['att_percent']; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="16" class="text-center text-muted">Không có dữ liệu.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="<?php echo htmlspecialchars($year_list_page); ?>" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-1"></i><?php echo $is_huynh_truong ? 'Danh sách tổng kết' : 'Quản lý năm học'; ?></a>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
