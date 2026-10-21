<?php
/**
 * Quản lý tài khoản đăng nhập — Admin / HDT Manager.
 * Thêm / sửa / xóa tài khoản, liên kết Huynh Trưởng (teachers).
 * Tương thích PHP 5.6 (hosting).
 */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

session_start();
require_once __DIR__ . '/login_redirect.php';

if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}

$current_role = login_current_role();
if (!login_can_manage_accounts($current_role)) {
    $_SESSION['error'] = 'Chỉ Admin / Quản lý HDT được quản lý tài khoản.';
    header('Location: ' . app_url('index.php', true));
    exit;
}

require_once __DIR__ . '/db_connect.php';

function qltk_h($v) {
    return htmlspecialchars((string) (isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

function qltk_role_labels() {
    return array(
        'admin' => 'Quản trị viên',
        'hdt_manager' => 'Quản lý HDT',
        'thuquy' => 'Thủ quỹ',
        'huynh_truong' => 'Huynh Trưởng',
        'ban_phu_huynh' => 'Ban Phụ Huynh',
    );
}

function qltk_ensure_users_schema($conn) {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col && ($info = $col->fetch_assoc())) {
        if (strpos($info['Type'], 'huynh_truong') === false || strpos($info['Type'], 'ban_phu_huynh') === false) {
            @$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy','huynh_truong','ban_phu_huynh') DEFAULT 'huynh_truong'");
        }
    }
    $tc = $conn->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
    if ($tc && $tc->num_rows == 0) {
        @$conn->query('ALTER TABLE users ADD COLUMN teacher_id INT NULL DEFAULT NULL AFTER full_name');
    }
    $fn = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
    if ($fn && $fn->num_rows == 0) {
        @$conn->query('ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NULL AFTER role');
    }
}

qltk_ensure_users_schema($conn);

$role_labels = qltk_role_labels();
$allowed_roles = array_keys($role_labels);
$self_id = (int) $_SESSION['user_id'];
$page_rel = 'quan_ly_tai_khoan.php';
$page_abs = app_url($page_rel, true);

// Danh sách HT chưa / đã liên kết
$teachers = array();
$res_t = $conn->query("SELECT t.id, t.ho_ten, t.ten_thanh,
    (SELECT u.id FROM users u WHERE u.teacher_id = t.id LIMIT 1) AS linked_user_id
    FROM teachers t ORDER BY t.ho_ten");
if ($res_t) {
    while ($row = $res_t->fetch_assoc()) {
        $teachers[] = $row;
    }
}

// ========== POST handlers ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add_user') {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $role = isset($_POST['role']) ? trim($_POST['role']) : 'huynh_truong';
        $teacher_id = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;

        if ($username === '' || $password === '') {
            $_SESSION['error'] = 'Tên đăng nhập và mật khẩu không được để trống.';
        } elseif (!in_array($role, $allowed_roles, true)) {
            $_SESSION['error'] = 'Vai trò không hợp lệ.';
        } else {
            $ue = $conn->real_escape_string($username);
            $chk = $conn->query("SELECT id FROM users WHERE username = '$ue' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $_SESSION['error'] = 'Tên đăng nhập đã tồn tại.';
            } else {
                if ($teacher_id > 0) {
                    $link_chk = $conn->query("SELECT id, username FROM users WHERE teacher_id = $teacher_id LIMIT 1");
                    if ($link_chk && ($lr = $link_chk->fetch_assoc())) {
                        $_SESSION['error'] = 'Huynh Trưởng này đã liên kết với tài khoản "' . $lr['username'] . '".';
                        header('Location: ' . $page_abs);
                        exit;
                    }
                    // Lấy tên HT nếu chưa nhập full_name
                    if ($full_name === '') {
                        $tn = $conn->query("SELECT ho_ten FROM teachers WHERE id = $teacher_id LIMIT 1");
                        if ($tn && ($tr = $tn->fetch_assoc())) {
                            $full_name = $tr['ho_ten'];
                        }
                    }
                }
                $pe = $conn->real_escape_string($password);
                $fe = $conn->real_escape_string($full_name);
                $re = $conn->real_escape_string($role);
                $tid_sql = $teacher_id > 0 ? (string) $teacher_id : 'NULL';
                $ok = $conn->query("INSERT INTO users (username, password, role, full_name, teacher_id) VALUES ('$ue', '$pe', '$re', '$fe', $tid_sql)");
                if ($ok) {
                    $_SESSION['success'] = 'Đã thêm tài khoản "' . $username . '".';
                } else {
                    $_SESSION['error'] = 'Lỗi thêm tài khoản: ' . $conn->error;
                }
            }
        }
        header('Location: ' . $page_abs);
        exit;
    }

    if ($action === 'edit_user') {
        $uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $role = isset($_POST['role']) ? trim($_POST['role']) : 'huynh_truong';
        $teacher_id = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;

        if ($uid <= 0 || $username === '') {
            $_SESSION['error'] = 'Thiếu thông tin tài khoản.';
        } elseif (!in_array($role, $allowed_roles, true)) {
            $_SESSION['error'] = 'Vai trò không hợp lệ.';
        } else {
            // Không cho hạ quyền / đổi role tài khoản đang đăng nhập nếu tự hạ admin cuối?
            $ue = $conn->real_escape_string($username);
            $chk = $conn->query("SELECT id FROM users WHERE username = '$ue' AND id != $uid LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $_SESSION['error'] = 'Tên đăng nhập đã được dùng bởi tài khoản khác.';
            } else {
                if ($teacher_id > 0) {
                    $link_chk = $conn->query("SELECT id, username FROM users WHERE teacher_id = $teacher_id AND id != $uid LIMIT 1");
                    if ($link_chk && ($lr = $link_chk->fetch_assoc())) {
                        $_SESSION['error'] = 'Huynh Trưởng này đã liên kết với tài khoản "' . $lr['username'] . '".';
                        header('Location: ' . $page_abs);
                        exit;
                    }
                }
                $fe = $conn->real_escape_string($full_name);
                $re = $conn->real_escape_string($role);
                $tid_sql = $teacher_id > 0 ? (string) $teacher_id : 'NULL';
                if ($password !== '') {
                    $pe = $conn->real_escape_string($password);
                    $sql = "UPDATE users SET username = '$ue', password = '$pe', role = '$re', full_name = '$fe', teacher_id = $tid_sql WHERE id = $uid";
                } else {
                    $sql = "UPDATE users SET username = '$ue', role = '$re', full_name = '$fe', teacher_id = $tid_sql WHERE id = $uid";
                }
                if ($conn->query($sql)) {
                    $_SESSION['success'] = 'Đã cập nhật tài khoản.';
                    if ($password !== '') {
                        login_remember_revoke_user($conn, $uid);
                    }
                    if ($uid === $self_id) {
                        $_SESSION['user_role'] = $role;
                    }
                } else {
                    $_SESSION['error'] = 'Lỗi cập nhật: ' . $conn->error;
                }
            }
        }
        header('Location: ' . $page_abs);
        exit;
    }

    if ($action === 'delete_user') {
        $uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($uid <= 0) {
            $_SESSION['error'] = 'Tài khoản không hợp lệ.';
        } elseif ($uid === $self_id) {
            $_SESSION['error'] = 'Không thể xóa tài khoản đang đăng nhập.';
        } else {
            // Không xóa admin cuối cùng
            $urow = $conn->query("SELECT role FROM users WHERE id = $uid LIMIT 1");
            $urole = ($urow && ($r = $urow->fetch_assoc())) ? $r['role'] : '';
            if ($urole === 'admin') {
                $ac = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
                $admin_count = ($ac && ($ar = $ac->fetch_assoc())) ? (int) $ar['c'] : 0;
                if ($admin_count <= 1) {
                    $_SESSION['error'] = 'Không thể xóa admin cuối cùng của hệ thống.';
                    header('Location: ' . $page_abs);
                    exit;
                }
            }
            if ($conn->query("DELETE FROM users WHERE id = $uid")) {
                login_remember_revoke_user($conn, $uid);
                $_SESSION['success'] = 'Đã xóa tài khoản.';
            } else {
                $_SESSION['error'] = 'Lỗi xóa: ' . $conn->error;
            }
        }
        header('Location: ' . $page_abs);
        exit;
    }
}

// ========== List ==========
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_role = isset($_GET['role']) ? trim($_GET['role']) : '';
if ($filter_role !== '' && !in_array($filter_role, $allowed_roles, true)) {
    $filter_role = '';
}

$where = 'WHERE 1=1';
if ($search !== '') {
    $se = $conn->real_escape_string($search);
    $where .= " AND (u.username LIKE '%$se%' OR u.full_name LIKE '%$se%' OR t.ho_ten LIKE '%$se%' OR t.ten_thanh LIKE '%$se%')";
}
if ($filter_role !== '') {
    $where .= " AND u.role = '" . $conn->real_escape_string($filter_role) . "'";
}

$users = array();
$sql = "SELECT u.id, u.username, u.password, u.role, u.full_name, u.teacher_id,
               t.ho_ten AS teacher_name, t.ten_thanh AS teacher_saint,
               (SELECT GROUP_CONCAT(CONCAT(c.ten_lop, ' (', ct.vai_tro, ')') SEPARATOR ', ')
                FROM class_teachers ct JOIN classes c ON c.id = ct.class_id
                WHERE ct.teacher_id = u.teacher_id) AS lop_phu_trach
        FROM users u
        LEFT JOIN teachers t ON t.id = u.teacher_id
        $where
        ORDER BY FIELD(u.role, 'admin', 'hdt_manager', 'thuquy', 'huynh_truong', 'ban_phu_huynh'), u.username";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}

$counts = array('all' => 0, 'admin' => 0, 'hdt_manager' => 0, 'thuquy' => 0, 'huynh_truong' => 0, 'ban_phu_huynh' => 0);
$cres = $conn->query('SELECT role, COUNT(*) AS c FROM users GROUP BY role');
if ($cres) {
    while ($cr = $cres->fetch_assoc()) {
        $counts['all'] += (int) $cr['c'];
        if (isset($counts[$cr['role']])) {
            $counts[$cr['role']] = (int) $cr['c'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý tài khoản — ĐOÀN TNTT</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root { --p: #1565c0; --p2: #42a5f5; --text: #1a237e; --muted: #607d8b; }
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(160deg, #e8f4fc, #f4f8fb 45%, #e3f2fd); min-height: 100vh; color: #263238; }
        .topbar { background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5); color: #fff; padding: .85rem 0; box-shadow: 0 8px 24px rgba(13,71,161,.22); position: sticky; top: 0; z-index: 100; }
        .topbar a { color: rgba(255,255,255,.92); text-decoration: none; font-weight: 700; font-size: .88rem; padding: .35rem .7rem; border-radius: 999px; }
        .topbar a:hover, .topbar a.active { color: #fff; background: rgba(255,255,255,.18); }
        .panel { background: #fff; border-radius: 1.15rem; box-shadow: 0 10px 30px rgba(21,101,192,.07); border: 1px solid rgba(21,101,192,.05); overflow: hidden; margin-bottom: 1.25rem; }
        .panel-head { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; padding: 1rem 1.25rem; font-weight: 800; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem; }
        .panel-body { padding: 1.25rem; }
        .stat { background: #fff; border: 1px solid #e3eef6; border-radius: 1rem; padding: .9rem 1rem; text-align: center; height: 100%; box-shadow: 0 4px 14px rgba(21,101,192,.05); }
        .stat .v { font-size: 1.4rem; font-weight: 800; color: var(--text); }
        .stat .l { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 700; }
        .chip { display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .85rem; border-radius: 999px; font-size: .82rem; font-weight: 700; text-decoration: none; border: 1px solid #e3eef6; background: #fff; color: #455a64; margin: .15rem; }
        .chip.active { background: linear-gradient(135deg, #1565c0, #1e88e5); color: #fff; border-color: transparent; }
        .badge-role { border-radius: 999px; font-weight: 700; font-size: .72rem; padding: .3em .65em; }
        .badge-admin { background: #ffebee; color: #c62828; }
        .badge-hdt { background: #fff8e1; color: #f57f17; }
        .badge-thuquy { background: #e8f5e9; color: #2e7d32; }
        .badge-ht { background: #e3f2fd; color: #1565c0; }
        .badge-bph { background: #f3e5f5; color: #7b1fa2; }
        .btn-main { background: linear-gradient(135deg, #1565c0, #1e88e5); border: none; border-radius: 999px; padding: .55rem 1.2rem; font-weight: 800; color: #fff; }
        .btn-main:hover { filter: brightness(1.05); color: #fff; }
        .form-control, .form-select { border-radius: .75rem; border-color: #d6e4f0; font-weight: 600; }
        .user-card { border: 1px solid #e3eef6; border-radius: 1rem; padding: 1rem; margin-bottom: .75rem; background: #f8fbff; }
        .user-card .name { font-weight: 800; color: var(--text); font-size: 1.05rem; }
        .user-card .meta { font-size: .85rem; color: var(--muted); font-weight: 600; }
        .table-users thead th { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: var(--text); background: #f8fbff; font-weight: 800; }
        .modal-header { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; border: none; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .modal-content { border: none; border-radius: 1.1rem; overflow: hidden; }
        @media (max-width: 991px) {
            .desk-table { display: none !important; }
        }
        @media (min-width: 992px) {
            .mob-cards { display: none !important; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <img src="img/logo.png" alt="" width="36" height="36" style="object-fit:contain">
            <span class="fw-bold">Quản lý tài khoản</span>
        </div>
        <nav class="d-flex flex-wrap gap-1">
            <a href="index.php"><i class="fas fa-home me-1"></i>Trang chủ</a>
            <a href="classes.php"><i class="fas fa-users me-1"></i>Đoàn sinh</a>
            <a href="hdt_management.php"><i class="fas fa-user-tie me-1"></i>Huynh Trưởng</a>
            <a href="bao_cao_doan_sinh.php"><i class="fas fa-file-lines me-1"></i>Báo cáo</a>
            <a href="quan_ly_tai_khoan.php" class="active"><i class="fas fa-user-cog me-1"></i>Tài khoản</a>
            <a href="tai_khoan.php"><i class="fas fa-id-card me-1"></i>Cá nhân</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>Thoát</a>
        </nav>
    </div>
</div>

<main class="container py-3 py-md-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="fas fa-check-circle me-2"></i><?php echo qltk_h($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo qltk_h($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="stat"><div class="v"><?php echo (int)$counts['all']; ?></div><div class="l">Tổng TK</div></div></div>
        <div class="col-6 col-md-3"><div class="stat"><div class="v"><?php echo (int)$counts['huynh_truong']; ?></div><div class="l">Huynh Trưởng</div></div></div>
        <div class="col-6 col-md-3"><div class="stat"><div class="v"><?php echo (int)$counts['hdt_manager']; ?></div><div class="l">HDT</div></div></div>
        <div class="col-6 col-md-3"><div class="stat"><div class="v"><?php echo (int)$counts['admin'] + (int)$counts['thuquy']; ?></div><div class="l">Admin + Thủ quỹ</div></div></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <span><i class="fas fa-filter me-2"></i>Lọc & tìm kiếm</span>
            <button type="button" class="btn btn-light btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-1"></i>Thêm tài khoản
            </button>
        </div>
        <div class="panel-body">
            <div class="mb-3">
                <a href="quan_ly_tai_khoan.php" class="chip <?php echo $filter_role === '' ? 'active' : ''; ?>">Tất cả (<?php echo (int)$counts['all']; ?>)</a>
                <?php foreach ($role_labels as $rk => $rl): ?>
                    <a href="quan_ly_tai_khoan.php?role=<?php echo urlencode($rk); ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"
                       class="chip <?php echo $filter_role === $rk ? 'active' : ''; ?>">
                        <?php echo qltk_h($rl); ?> (<?php echo (int)$counts[$rk]; ?>)
                    </a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="row g-2">
                <?php if ($filter_role !== ''): ?>
                    <input type="hidden" name="role" value="<?php echo qltk_h($filter_role); ?>">
                <?php endif; ?>
                <div class="col-md-9">
                    <input type="text" name="search" class="form-control" placeholder="Tìm username, họ tên, tên thánh HT..." value="<?php echo qltk_h($search); ?>">
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-main" type="submit"><i class="fas fa-search me-1"></i>Tìm</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <span><i class="fas fa-users-cog me-2"></i>Danh sách tài khoản (<?php echo count($users); ?>)</span>
        </div>
        <div class="panel-body">
            <?php if (empty($users)): ?>
                <div class="text-center text-muted py-4 fw-bold">Không có tài khoản phù hợp.</div>
            <?php else: ?>

            <div class="mob-cards">
                <?php foreach ($users as $u): ?>
                    <?php
                    $rb = 'badge-ht';
                    if ($u['role'] === 'admin') $rb = 'badge-admin';
                    elseif ($u['role'] === 'hdt_manager') $rb = 'badge-hdt';
                    elseif ($u['role'] === 'thuquy') $rb = 'badge-thuquy';
                            elseif ($u['role'] === 'ban_phu_huynh') $rb = 'badge-bph';
                    $rl = isset($role_labels[$u['role']]) ? $role_labels[$u['role']] : $u['role'];
                    ?>
                    <div class="user-card">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="name"><?php echo qltk_h($u['full_name'] !== '' && $u['full_name'] !== null ? $u['full_name'] : $u['username']); ?></div>
                                <div class="meta"><i class="fas fa-user me-1"></i><?php echo qltk_h($u['username']); ?></div>
                            </div>
                            <span class="badge-role <?php echo $rb; ?>"><?php echo qltk_h($rl); ?></span>
                        </div>
                        <div class="meta mt-2">
                            <?php if (!empty($u['teacher_id'])): ?>
                                <i class="fas fa-link me-1 text-success"></i>
                                HT: <?php echo qltk_h($u['teacher_name']); ?>
                                <?php if (!empty($u['teacher_saint'])): ?> (<?php echo qltk_h($u['teacher_saint']); ?>)<?php endif; ?>
                                <?php if (!empty($u['lop_phu_trach'])): ?>
                                    <br><i class="fas fa-chalkboard me-1"></i><?php echo qltk_h($u['lop_phu_trach']); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="fas fa-unlink me-1 text-warning"></i>Chưa liên kết Huynh Trưởng
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-sm btn-main flex-fill btn-edit-user"
                                data-id="<?php echo (int)$u['id']; ?>"
                                data-username="<?php echo qltk_h($u['username']); ?>"
                                data-fullname-name="<?php echo qltk_h($u['full_name']); ?>"
                                data-role="<?php echo qltk_h($u['role']); ?>"
                                data-teacher-id="<?php echo (int)$u['teacher_id']; ?>"
                                data-bs-toggle="modal" data-bs-target="#editUserModal">
                                <i class="fas fa-edit me-1"></i>Sửa
                            </button>
                            <?php if ((int)$u['id'] !== $self_id): ?>
                            <form method="POST" class="flex-fill" onsubmit="return confirm('Xóa tài khoản <?php echo qltk_h($u['username']); ?>?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="fas fa-trash me-1"></i>Xóa</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive desk-table">
                <table class="table table-hover align-middle table-users mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Họ tên</th>
                            <th>Vai trò</th>
                            <th>Liên kết HT</th>
                            <th>Lớp phụ trách</th>
                            <th class="text-end">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $stt = 1; foreach ($users as $u): ?>
                            <?php
                            $rb = 'badge-ht';
                            if ($u['role'] === 'admin') $rb = 'badge-admin';
                            elseif ($u['role'] === 'hdt_manager') $rb = 'badge-hdt';
                            elseif ($u['role'] === 'thuquy') $rb = 'badge-thuquy';
                            elseif ($u['role'] === 'ban_phu_huynh') $rb = 'badge-bph';
                            $rl = isset($role_labels[$u['role']]) ? $role_labels[$u['role']] : $u['role'];
                            ?>
                            <tr>
                                <td class="text-muted fw-bold"><?php echo $stt++; ?></td>
                                <td><strong><?php echo qltk_h($u['username']); ?></strong></td>
                                <td><?php echo qltk_h($u['full_name'] ? $u['full_name'] : '—'); ?></td>
                                <td><span class="badge-role <?php echo $rb; ?>"><?php echo qltk_h($rl); ?></span></td>
                                <td class="small">
                                    <?php if (!empty($u['teacher_id'])): ?>
                                        <span class="text-success"><i class="fas fa-link me-1"></i><?php echo qltk_h($u['teacher_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-unlink me-1"></i>Chưa liên kết</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?php echo qltk_h(!empty($u['lop_phu_trach']) ? $u['lop_phu_trach'] : '—'); ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-main btn-edit-user"
                                        data-id="<?php echo (int)$u['id']; ?>"
                                        data-username="<?php echo qltk_h($u['username']); ?>"
                                        data-fullname-name="<?php echo qltk_h($u['full_name']); ?>"
                                        data-role="<?php echo qltk_h($u['role']); ?>"
                                        data-teacher-id="<?php echo (int)$u['teacher_id']; ?>"
                                        data-bs-toggle="modal" data-bs-target="#editUserModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ((int)$u['id'] !== $self_id): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Xóa tài khoản này?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
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
</main>

<!-- Modal Thêm -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Thêm tài khoản</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_user">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tên đăng nhập *</label>
                        <input type="text" name="username" class="form-control" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Mật khẩu *</label>
                        <input type="text" name="password" class="form-control" required value="1234" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Họ tên hiển thị</label>
                        <input type="text" name="full_name" class="form-control" placeholder="Để trống sẽ lấy tên HT nếu có liên kết">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vai trò *</label>
                        <select name="role" class="form-select" required>
                            <?php foreach ($role_labels as $rk => $rl): ?>
                                <option value="<?php echo qltk_h($rk); ?>" <?php echo $rk === 'huynh_truong' ? 'selected' : ''; ?>><?php echo qltk_h($rl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Liên kết Huynh Trưởng</label>
                        <select name="teacher_id" class="form-select">
                            <option value="0">— Không liên kết —</option>
                            <?php foreach ($teachers as $t): ?>
                                <?php if (!empty($t['linked_user_id'])) continue; ?>
                                <option value="<?php echo (int)$t['id']; ?>">
                                    <?php echo qltk_h($t['ho_ten'] . (!empty($t['ten_thanh']) ? ' (' . $t['ten_thanh'] . ')' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Chỉ hiện HT chưa có tài khoản. Liên kết giúp Trưởng/Phó sửa lớp không cần mã.</div>
                    </div>
                    <button type="submit" class="btn btn-main w-100"><i class="fas fa-check me-1"></i>Tạo tài khoản</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Sửa -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Sửa tài khoản</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tên đăng nhập *</label>
                        <input type="text" name="username" id="edit_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Mật khẩu mới</label>
                        <input type="text" name="password" class="form-control" placeholder="Để trống nếu giữ mật khẩu cũ" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Họ tên hiển thị</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vai trò *</label>
                        <select name="role" id="edit_role" class="form-select" required>
                            <?php foreach ($role_labels as $rk => $rl): ?>
                                <option value="<?php echo qltk_h($rk); ?>"><?php echo qltk_h($rl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Liên kết Huynh Trưởng</label>
                        <select name="teacher_id" id="edit_teacher_id" class="form-select">
                            <option value="0">— Không liên kết —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"
                                    data-linked="<?php echo !empty($t['linked_user_id']) ? (int)$t['linked_user_id'] : 0; ?>">
                                    <?php
                                    $label = $t['ho_ten'] . (!empty($t['ten_thanh']) ? ' (' . $t['ten_thanh'] . ')' : '');
                                    if (!empty($t['linked_user_id'])) {
                                        $label .= ' — đang liên kết TK #' . $t['linked_user_id'];
                                    }
                                    echo qltk_h($label);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-main w-100"><i class="fas fa-save me-1"></i>Lưu thay đổi</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.btn-edit-user').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var uid = btn.getAttribute('data-id');
        document.getElementById('edit_user_id').value = uid;
        document.getElementById('edit_username').value = btn.getAttribute('data-username') || '';
        document.getElementById('edit_full_name').value = btn.getAttribute('data-full-name') || '';
        document.getElementById('edit_role').value = btn.getAttribute('data-role') || 'huynh_truong';
        var tid = btn.getAttribute('data-teacher-id') || '0';
        var sel = document.getElementById('edit_teacher_id');
        sel.value = tid;
        // Ẩn option đã liên kết tài khoản khác
        Array.prototype.forEach.call(sel.options, function(opt) {
            if (opt.value === '0') { opt.disabled = false; opt.hidden = false; return; }
            var linked = opt.getAttribute('data-linked') || '0';
            if (linked !== '0' && linked !== uid) {
                opt.disabled = true;
                opt.hidden = true;
            } else {
                opt.disabled = false;
                opt.hidden = false;
            }
        });
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
