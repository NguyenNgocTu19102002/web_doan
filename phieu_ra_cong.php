<?php
/**
 * Phiếu ra cổng.
 * - Trưởng / Phó lớp + Admin/HDT: tạo phiếu xin ra cổng cho em trong lớp.
 * - Mọi tài khoản đã đăng nhập: xem danh sách chờ và xác nhận cho ra cổng / từ chối (lưu tên người duyệt).
 */
session_start();
require_once 'login_redirect.php';

if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}

require_once 'db_connect.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

function prc_h($v) {
    return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

function prc_ensure_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS gate_passes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NOT NULL,
        ly_do VARCHAR(255) NOT NULL,
        status ENUM('cho_xac_nhan','da_ra_cong','tu_choi','da_huy') NOT NULL DEFAULT 'cho_xac_nhan',
        created_by INT NULL,
        created_by_name VARCHAR(150) NULL,
        created_at DATETIME NOT NULL,
        confirmed_by INT NULL,
        confirmed_by_name VARCHAR(150) NULL,
        confirmed_at DATETIME NULL,
        KEY idx_status (status),
        KEY idx_created_at (created_at),
        KEY idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function prc_status_badge($status) {
    switch ($status) {
        case 'da_ra_cong': return '<span class="badge bg-success">Đã ra cổng</span>';
        case 'tu_choi':    return '<span class="badge bg-danger">Từ chối</span>';
        case 'da_huy':     return '<span class="badge bg-secondary">Đã hủy</span>';
        default:           return '<span class="badge bg-warning text-dark">Chờ xác nhận</span>';
    }
}

prc_ensure_schema($conn);

$user_id = (int)$_SESSION['user_id'];
$current_role = login_current_role();
$is_admin = login_is_admin($current_role);
$is_hdt_manager = login_is_hdt_manager($current_role);
$can_confirm = true;

$user_name = '';
$user_teacher_id = 0;
if ($res_u = $conn->query("SELECT u.username, u.full_name, u.teacher_id, t.ten_thanh, t.ho_ten
                           FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id WHERE u.id = $user_id LIMIT 1")) {
    if ($u = $res_u->fetch_assoc()) {
        $user_teacher_id = (int)$u['teacher_id'];
        if (!empty($u['ho_ten'])) {
            $user_name = trim(($u['ten_thanh'] ? $u['ten_thanh'] . ' ' : '') . $u['ho_ten']);
        } elseif (!empty($u['full_name'])) {
            $user_name = $u['full_name'];
        } else {
            $user_name = $u['username'];
        }
    }
}

// Lớp được phép tạo phiếu
$my_classes = [];
if ($is_hdt_manager) {
    $sql_cls = "SELECT id, ten_lop, nganh FROM classes ORDER BY " . login_nganh_field_sql('nganh') . ", ten_lop";
} elseif ($user_teacher_id > 0) {
    $sql_cls = "SELECT c.id, c.ten_lop, c.nganh FROM class_teachers ct JOIN classes c ON c.id = ct.class_id
                WHERE ct.teacher_id = $user_teacher_id ORDER BY c.ten_lop";
} else {
    $sql_cls = '';
}
if ($sql_cls !== '' && ($res_cls = $conn->query($sql_cls))) {
    while ($row = $res_cls->fetch_assoc()) {
        $my_classes[(int)$row['id']] = $row;
    }
}
$can_create = !empty($my_classes);

$page_url = app_url('phieu_ra_cong.php', true);

// ================== POST ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $now = date('Y-m-d H:i:s');

    if ($action === 'create' && $can_create) {
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $ly_do = trim(isset($_POST['ly_do']) ? (string)$_POST['ly_do'] : '');
        if (function_exists('mb_substr')) {
            $ly_do = mb_substr($ly_do, 0, 255, 'UTF-8');
        }

        if (!isset($my_classes[$class_id])) {
            $_SESSION['error'] = 'Bạn không phụ trách lớp này.';
        } elseif ($ly_do === '') {
            $_SESSION['error'] = 'Vui lòng nhập lý do ra cổng.';
        } else {
            $chk = $conn->query("SELECT id FROM students WHERE id = $student_id AND class_id = $class_id AND status = 'active' LIMIT 1");
            if (!$chk || $chk->num_rows === 0) {
                $_SESSION['error'] = 'Không tìm thấy em này trong lớp.';
            } else {
                $dup = $conn->query("SELECT id FROM gate_passes WHERE student_id = $student_id AND status = 'cho_xac_nhan' LIMIT 1");
                if ($dup && $dup->num_rows > 0) {
                    $_SESSION['error'] = 'Em này đã có phiếu đang chờ xác nhận.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO gate_passes (student_id, class_id, ly_do, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('iisiss', $student_id, $class_id, $ly_do, $user_id, $user_name, $now);
                    $stmt->execute()
                        ? $_SESSION['success'] = 'Đã gửi phiếu ra cổng. Anh chị trực cổng sẽ xác nhận.'
                        : $_SESSION['error'] = 'Lỗi: ' . $stmt->error;
                }
            }
        }
        header('Location: ' . $page_url);
        exit;
    }

    if (($action === 'confirm' || $action === 'reject') && $can_confirm) {
        $pass_id = isset($_POST['pass_id']) ? (int)$_POST['pass_id'] : 0;
        $new_status = $action === 'confirm' ? 'da_ra_cong' : 'tu_choi';
        $stmt = $conn->prepare("UPDATE gate_passes SET status = ?, confirmed_by = ?, confirmed_by_name = ?, confirmed_at = ? WHERE id = ? AND status = 'cho_xac_nhan'");
        $stmt->bind_param('sissi', $new_status, $user_id, $user_name, $now, $pass_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success'] = $action === 'confirm' ? 'Đã xác nhận cho em ra cổng.' : 'Đã từ chối phiếu.';
        } else {
            $_SESSION['error'] = 'Phiếu không còn ở trạng thái chờ xác nhận.';
        }
        header('Location: ' . $page_url);
        exit;
    }

    if ($action === 'cancel') {
        $pass_id = isset($_POST['pass_id']) ? (int)$_POST['pass_id'] : 0;
        $owner_cond = $is_hdt_manager ? '' : " AND created_by = $user_id";
        $conn->query("UPDATE gate_passes SET status = 'da_huy' WHERE id = $pass_id AND status = 'cho_xac_nhan'$owner_cond");
        $conn->affected_rows > 0
            ? $_SESSION['success'] = 'Đã hủy phiếu.'
            : $_SESSION['error'] = 'Không hủy được phiếu này.';
        header('Location: ' . $page_url);
        exit;
    }

    header('Location: ' . $page_url);
    exit;
}

// ================== Dữ liệu hiển thị ==================
$pass_select = "SELECT gp.*, s.ho_ten, s.ten_thanh, c.ten_lop, c.nganh
                FROM gate_passes gp
                JOIN students s ON s.id = gp.student_id
                JOIN classes c ON c.id = gp.class_id";

$pending = [];
if ($can_confirm) {
    if ($res = $conn->query("$pass_select WHERE gp.status = 'cho_xac_nhan' ORDER BY gp.created_at ASC")) {
        while ($row = $res->fetch_assoc()) { $pending[] = $row; }
    }
}

// ================== Xuất Excel ==================
$export_ranges = ['1m' => ['1 MONTH', '1 tháng'], '6m' => ['6 MONTH', '6 tháng'], '1y' => ['1 YEAR', '1 năm']];
if (isset($_GET['export']) && isset($export_ranges[$_GET['export']])) {
    list($interval, $range_label) = $export_ranges[$_GET['export']];
    $rows = [];
    if ($res = $conn->query("$pass_select WHERE gp.created_at >= DATE_SUB(NOW(), INTERVAL $interval) ORDER BY gp.created_at DESC")) {
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    }
    $status_text = ['cho_xac_nhan' => 'Chờ xác nhận', 'da_ra_cong' => 'Đã ra cổng', 'tu_choi' => 'Từ chối', 'da_huy' => 'Đã hủy'];
    $filename = 'Phieu_ra_cong_' . $_GET['export'] . '_' . date('Ymd') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<h3>Danh sách xin ra cổng — ' . prc_h($range_label) . ' gần nhất (xuất ngày ' . date('d/m/Y H:i') . ')</h3>';
    echo '<table border="1" cellspacing="0" cellpadding="4">';
    echo '<tr style="background:#1565c0;color:#fff;font-weight:bold"><th>STT</th><th>Ngày</th><th>Giờ</th><th>Đoàn sinh</th><th>Lớp</th><th>Ngành</th><th>Lý do</th><th>Người gửi</th><th>Trạng thái</th><th>Người duyệt</th><th>Giờ duyệt</th></tr>';
    foreach ($rows as $i => $p) {
        $confirmed = !empty($p['confirmed_at']) && in_array($p['status'], ['da_ra_cong', 'tu_choi'], true);
        echo '<tr>'
            . '<td>' . ($i + 1) . '</td>'
            . '<td>' . date('d/m/Y', strtotime($p['created_at'])) . '</td>'
            . '<td>' . date('H:i', strtotime($p['created_at'])) . '</td>'
            . '<td>' . prc_h(trim(($p['ten_thanh'] ? $p['ten_thanh'] . ' ' : '') . $p['ho_ten'])) . '</td>'
            . '<td>' . prc_h($p['ten_lop']) . '</td>'
            . '<td>' . prc_h($p['nganh']) . '</td>'
            . '<td>' . prc_h($p['ly_do']) . '</td>'
            . '<td>' . prc_h($p['created_by_name']) . '</td>'
            . '<td>' . prc_h(isset($status_text[$p['status']]) ? $status_text[$p['status']] : $p['status']) . '</td>'
            . '<td>' . ($confirmed ? prc_h($p['confirmed_by_name']) : '') . '</td>'
            . '<td>' . ($confirmed ? date('H:i d/m/Y', strtotime($p['confirmed_at'])) : '') . '</td>'
            . '</tr>';
    }
    if (empty($rows)) {
        echo '<tr><td colspan="11">Không có phiếu nào.</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}

$today = date('Y-m-d');
$view_date = (isset($_GET['ngay']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ngay']) && strtotime($_GET['ngay'])) ? $_GET['ngay'] : $today;
$history_where = "gp.created_at >= '$view_date 00:00:00' AND gp.created_at <= '$view_date 23:59:59'";
$history = [];
if ($res = $conn->query("$pass_select WHERE $history_where ORDER BY gp.created_at DESC")) {
    while ($row = $res->fetch_assoc()) { $history[] = $row; }
}

// Danh sách thiếu nhi theo lớp cho form tạo phiếu
$students_by_class = [];
if ($can_create) {
    $ids = implode(',', array_keys($my_classes));
    if ($res = $conn->query("SELECT id, class_id, ho_ten, ten_thanh FROM students WHERE class_id IN ($ids) AND status = 'active' ORDER BY ho_ten")) {
        while ($row = $res->fetch_assoc()) {
            $students_by_class[(int)$row['class_id']][] = [
                'id' => (int)$row['id'],
                'name' => trim(($row['ten_thanh'] ? $row['ten_thanh'] . ' ' : '') . $row['ho_ten']),
            ];
        }
    }
}
$selected_class = isset($_GET['class_id']) && isset($my_classes[(int)$_GET['class_id']]) ? (int)$_GET['class_id'] : (int)key($my_classes);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu ra cổng — ĐOÀN TNTT Đaminh Savio Lập Trí</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(160deg, #e8f4fc, #f4f8fb 45%, #e3f2fd); min-height: 100vh; color: #263238; }
        .topbar { background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5); color: #fff; padding: .7rem 0; box-shadow: 0 8px 24px rgba(13,71,161,.22); position: sticky; top: 0; z-index: 100; }
        .topbar .brand { display: flex; align-items: center; gap: .6rem; color: #fff; text-decoration: none; font-weight: 800; font-size: .95rem; }
        .topbar .brand img { width: 40px; height: 40px; object-fit: contain; }
        .topbar nav a { color: rgba(255,255,255,.92); text-decoration: none; font-weight: 700; font-size: .88rem; padding: .4rem .75rem; border-radius: 999px; white-space: nowrap; }
        .topbar nav a:hover, .topbar nav a.active { color: #fff; background: rgba(255,255,255,.2); }
        .panel { background: #fff; border-radius: 1.15rem; box-shadow: 0 10px 30px rgba(21,101,192,.07); overflow: hidden; margin-bottom: 1.25rem; }
        .panel-head { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; padding: .9rem 1.25rem; font-weight: 800; display: flex; justify-content: space-between; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .panel-head.warn { background: linear-gradient(135deg, #e65100, #ffa726); }
        .panel-body { padding: 1.25rem; }
        .pass-card { border: 1px solid #ffe0b2; background: #fff8e1; border-radius: 1rem; padding: 1rem; height: 100%; display: flex; flex-direction: column; gap: .4rem; }
        .pass-card .name { font-weight: 800; font-size: 1.1rem; color: #1a237e; }
        .pass-card .meta { font-size: .85rem; color: #607d8b; font-weight: 600; }
        .pass-card .reason { font-weight: 700; color: #bf360c; }
        .btn-gate { border-radius: 999px; font-weight: 800; padding: .55rem 1rem; }
        .reason-chip { border: 1px solid #d6e4f0; background: #f8fbff; border-radius: 999px; padding: .25rem .7rem; font-size: .8rem; font-weight: 700; color: #455a64; cursor: pointer; }
        .reason-chip:hover { background: #e3f2fd; }
        .form-control, .form-select { border-radius: .75rem; font-weight: 600; }
        .history-date { width: 160px; border-radius: 999px; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/app_header.php'; app_header_render('Phiếu ra cổng'); ?>

<main class="container py-3 py-md-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="fas fa-check-circle me-2"></i><?php echo prc_h($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo prc_h($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($can_confirm): ?>
    <div class="panel">
        <div class="panel-head warn">
            <span><i class="fas fa-bell me-2"></i>Các em xin ra cổng — chờ xác nhận (<?php echo count($pending); ?>)</span>
            <span class="small fw-semibold opacity-75"><i class="fas fa-rotate me-1"></i>Tự cập nhật mỗi 30 giây</span>
        </div>
        <div class="panel-body">
            <?php if (empty($pending)): ?>
                <p class="text-center text-muted fw-bold py-3 mb-0">Hiện không có em nào xin ra cổng.</p>
            <?php else: ?>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="search" id="prc_pending_search" class="form-control" placeholder="Tìm theo tên đoàn sinh, lớp, lý do..." autocomplete="off">
                    </div>
                    <p id="prc_pending_empty" class="text-center text-muted small mt-2 mb-0 d-none">Không tìm thấy phiếu phù hợp.</p>
                </div>
                <div class="row g-3" id="prc_pending_list">
                    <?php foreach ($pending as $p): ?>
                        <?php $p_full_name = trim(($p['ten_thanh'] ? $p['ten_thanh'] . ' ' : '') . $p['ho_ten']); ?>
                        <div class="col-md-6 col-lg-4 pending-pass-item" data-search="<?php echo prc_h(mb_strtolower($p_full_name . ' ' . $p['ten_lop'] . ' ' . $p['nganh'] . ' ' . $p['ly_do'] . ' ' . $p['created_by_name'], 'UTF-8')); ?>">
                            <div class="pass-card">
                                <div class="name"><?php echo prc_h($p_full_name); ?></div>
                                <div class="meta"><i class="fas fa-chalkboard me-1"></i>Lớp <?php echo prc_h($p['ten_lop']); ?> (<?php echo prc_h($p['nganh']); ?>)</div>
                                <div class="reason"><i class="fas fa-comment-dots me-1"></i><?php echo prc_h($p['ly_do']); ?></div>
                                <div class="meta"><i class="fas fa-user-pen me-1"></i><?php echo prc_h($p['created_by_name']); ?> · <?php echo date('H:i d/m', strtotime($p['created_at'])); ?></div>
                                <div class="d-flex gap-2 mt-auto pt-2">
                                    <form method="POST" class="flex-fill">
                                        <input type="hidden" name="action" value="confirm">
                                        <input type="hidden" name="pass_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-gate w-100"><i class="fas fa-check me-1"></i>Cho ra cổng</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Từ chối phiếu này?');">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="pass_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-gate"><i class="fas fa-xmark"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_create): ?>
    <div class="panel">
        <div class="panel-head"><span><i class="fas fa-plus me-2"></i>Thêm em xin ra cổng</span></div>
        <div class="panel-body">
            <form method="POST" id="createPassForm">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Lớp</label>
                        <select name="class_id" id="prc_class" class="form-select" required>
                            <?php foreach ($my_classes as $cid => $cls): ?>
                                <option value="<?php echo (int)$cid; ?>" <?php echo $cid === $selected_class ? 'selected' : ''; ?>><?php echo prc_h($cls['ten_lop'] . ' (' . $cls['nganh'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Em xin ra cổng</label>
                        <select name="student_id" id="prc_student" class="form-select" required></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Lý do ra cổng</label>
                        <input type="text" name="ly_do" id="prc_reason" class="form-control" maxlength="255" required placeholder="VD: Về đi học thêm">
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach (['Về đi học thêm', 'Phụ huynh đón sớm', 'Bị ốm / mệt', 'Việc gia đình', 'Đi khám bệnh', 'Về sớm có phép'] as $reason): ?>
                                <button type="button" class="reason-chip" data-reason="<?php echo prc_h($reason); ?>"><?php echo prc_h($reason); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-gate mt-3"><i class="fas fa-paper-plane me-1"></i>Gửi phiếu ra cổng</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_confirm || $can_create): ?>
    <div class="panel">
        <div class="panel-head">
            <span><i class="fas fa-list me-2"></i><?php echo $view_date === $today ? 'Phiếu hôm nay' : 'Phiếu ngày'; ?> (<?php echo date('d/m/Y', strtotime($view_date)); ?>)</span>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <form method="GET" class="d-flex align-items-center gap-2 m-0">
                    <input type="date" name="ngay" class="form-control form-control-sm history-date" value="<?php echo prc_h($view_date); ?>" max="<?php echo prc_h($today); ?>" onchange="this.form.submit()" title="Chọn ngày để xem lại">
                    <?php if ($view_date !== $today): ?>
                        <a href="<?php echo prc_h($page_url); ?>" class="btn btn-sm btn-light fw-bold">Hôm nay</a>
                    <?php endif; ?>
                </form>
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-success fw-bold dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-file-excel me-1"></i>Xuất Excel
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($export_ranges as $key => $r): ?>
                            <li><a class="dropdown-item" href="phieu_ra_cong.php?export=<?php echo $key; ?>"><?php echo prc_h($r[1]); ?> gần nhất</a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="panel-body">
            <?php if (empty($history)): ?>
                <p class="text-center text-muted fw-bold py-3 mb-0">Không có phiếu nào trong ngày này.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Giờ</th>
                                <th>Đoàn sinh</th>
                                <th>Lớp</th>
                                <th>Lý do</th>
                                <th>Người gửi</th>
                                <th>Trạng thái</th>
                                <th>Người duyệt</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $p): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($p['created_at'])); ?></td>
                                    <td class="fw-bold"><?php echo prc_h(trim(($p['ten_thanh'] ? $p['ten_thanh'] . ' ' : '') . $p['ho_ten'])); ?></td>
                                    <td><?php echo prc_h($p['ten_lop']); ?></td>
                                    <td><?php echo prc_h($p['ly_do']); ?></td>
                                    <td><?php echo prc_h($p['created_by_name']); ?></td>
                                    <td><?php echo prc_status_badge($p['status']); ?></td>
                                    <td>
                                        <?php if (!empty($p['confirmed_at']) && in_array($p['status'], ['da_ra_cong', 'tu_choi'], true)): ?>
                                            <div class="fw-bold"><?php echo prc_h($p['confirmed_by_name']); ?></div>
                                            <div class="small text-muted"><?php echo date('H:i', strtotime($p['confirmed_at'])); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($p['status'] === 'cho_xac_nhan' && ($is_hdt_manager || (int)$p['created_by'] === $user_id)): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hủy phiếu này?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="pass_id" value="<?php echo (int)$p['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Hủy</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var studentsByClass = <?php echo json_encode($students_by_class, JSON_UNESCAPED_UNICODE); ?>;
    var classSel = document.getElementById('prc_class');
    var studentSel = document.getElementById('prc_student');
    var reasonInput = document.getElementById('prc_reason');

    function fillStudents() {
        if (!classSel || !studentSel) return;
        var list = studentsByClass[classSel.value] || [];
        studentSel.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = list.length ? '— Chọn em —' : 'Lớp chưa có đoàn sinh';
        studentSel.appendChild(first);
        list.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            studentSel.appendChild(opt);
        });
    }
    if (classSel) {
        classSel.addEventListener('change', fillStudents);
        fillStudents();
    }

    document.querySelectorAll('.reason-chip').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (reasonInput) {
                reasonInput.value = btn.getAttribute('data-reason');
                reasonInput.focus();
            }
        });
    });

    <?php if ($can_confirm): ?>
    var pendingSearch = document.getElementById('prc_pending_search');
    var pendingEmpty = document.getElementById('prc_pending_empty');
    function filterPendingPasses() {
        if (!pendingSearch) return;
        var q = (pendingSearch.value || '').trim().toLowerCase();
        var items = document.querySelectorAll('.pending-pass-item');
        var shown = 0;
        items.forEach(function (el) {
            var hay = el.getAttribute('data-search') || '';
            var ok = !q || hay.indexOf(q) !== -1;
            el.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });
        if (pendingEmpty) {
            pendingEmpty.classList.toggle('d-none', shown > 0 || items.length === 0);
        }
    }
    if (pendingSearch) {
        pendingSearch.addEventListener('input', filterPendingPasses);
    }

    // Tự tải lại để thấy phiếu mới, trừ khi đang nhập dở form tạo phiếu / đang tìm kiếm
    setInterval(function () {
        var typing = (studentSel && studentSel.value) || (reasonInput && reasonInput.value)
            || (pendingSearch && pendingSearch.value);
        if (!typing) {
            window.location.reload();
        }
    }, 30000);
    <?php endif; ?>
})();
</script>
</body>
</html>
