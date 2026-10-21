<?php
/**
 * Trang tài khoản — hiện đại, đầy đủ thông tin Huynh Trưởng.
 */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

session_start();

function tk_h($v) {
    return htmlspecialchars((string) (isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

function tk_val($arr, $key, $default = '') {
    if (!is_array($arr) || !isset($arr[$key]) || $arr[$key] === null) {
        return $default;
    }
    return $arr[$key];
}

function tk_dash($v) {
    $s = trim((string) $v);
    return $s !== '' ? $s : '—';
}

require_once __DIR__ . '/login_redirect.php';

if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}

require_once __DIR__ . '/db_connect.php';

$user_id = (int) $_SESSION['user_id'];
$profile_lib = is_file(__DIR__ . '/includes/huynh_profile.php');
if ($profile_lib) {
    require_once __DIR__ . '/includes/huynh_profile.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile_lib) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'update_profile') {
        $err = null;
        if (ht_profile_update_personal($conn, $user_id, $_POST, $err)) {
            $_SESSION['success'] = 'Đã cập nhật thông tin cá nhân.';
        } else {
            $_SESSION['error'] = $err ? $err : 'Cập nhật thất bại.';
        }
    } elseif ($action === 'change_password') {
        $err = null;
        $ok = ht_profile_change_password(
            $conn,
            $user_id,
            isset($_POST['current_password']) ? $_POST['current_password'] : '',
            isset($_POST['new_password']) ? $_POST['new_password'] : '',
            isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '',
            $err
        );
        if ($ok) {
            $_SESSION['success'] = 'Đã đổi mật khẩu thành công.';
        } else {
            $_SESSION['error'] = $err ? $err : 'Đổi mật khẩu thất bại.';
        }
    }
    header('Location: ' . app_url('tai_khoan.php', true));
    exit;
}

$profile = null;
if ($profile_lib && function_exists('ht_profile_get_by_user_id')) {
    $profile = ht_profile_get_by_user_id($conn, $user_id);
}
if (!$profile) {
    $res = @$conn->query('SELECT id AS user_id, username, role, full_name, teacher_id FROM users WHERE id = ' . $user_id . ' LIMIT 1');
    $profile = ($res) ? $res->fetch_assoc() : null;
    if ($res) {
        $res->free();
    }
}

if (!$profile) {
    $_SESSION['error'] = 'Không tìm thấy tài khoản.';
    header('Location: ' . app_url('index.php', true));
    exit;
}

// Gộp lớp/ngành/vai trò: ưu tiên danh sách bổ nhiệm → cột teachers → hệ thống
$ho_ten = tk_val($profile, 'ho_ten');
if ($ho_ten === '') {
    $ho_ten = tk_val($profile, 'full_name');
}
$ten_thanh = tk_val($profile, 'ten_thanh');
$display_lop = tk_val($profile, 'lop_phu_trach');
$display_nganh = tk_val($profile, 'nganh_phu_trach');
$display_vai_tro = tk_val($profile, 'vai_tro_lop');

if ($profile_lib && function_exists('ht_profile_find_bo_nhiem_display') && tk_val($profile, 'role') === 'huynh_truong') {
    $bn_disp = ht_profile_find_bo_nhiem_display($ho_ten, $ten_thanh);
    if ($bn_disp) {
        if (ht_get($bn_disp, 'ten_lop') !== '') {
            $display_lop = ht_get($bn_disp, 'ten_lop');
        }
        if (ht_get($bn_disp, 'nganh') !== '') {
            $display_nganh = ht_get($bn_disp, 'nganh');
        }
        if (ht_get($bn_disp, 'vai_tro') !== '') {
            $display_vai_tro = ht_get($bn_disp, 'vai_tro');
        }
    }
}
if ($display_lop === '') {
    $display_lop = tk_val($profile, 'ten_lop_he_thong');
}
if ($display_nganh === '') {
    $display_nganh = tk_val($profile, 'nganh_he_thong');
}
if ($display_vai_tro === '') {
    $display_vai_tro = tk_val($profile, 'vai_tro_he_thong');
}

$role_labels = array(
    'admin' => 'Quản trị viên',
    'hdt_manager' => 'Quản lý HDT',
    'thuquy' => 'Thủ quỹ',
    'huynh_truong' => 'Huynh Trưởng',
);
$role = tk_val($profile, 'role');
$role_label = isset($role_labels[$role]) ? $role_labels[$role] : $role;

$loai_huynh = tk_val($profile, 'loai_huynh');
$cap_label = $loai_huynh;
if ($cap_label === 'Huynh Dự Trưởng') {
    $cap_label = 'Dự Trưởng';
}
$is_hdt = ($loai_huynh === 'Huynh Dự Trưởng');

$ngay_sinh_display = function_exists('ht_profile_format_date') ? ht_profile_format_date(tk_val($profile, 'ngay_sinh')) : '';
$ngay_gia_nhap_display = function_exists('ht_profile_format_date') ? ht_profile_format_date(tk_val($profile, 'ngay_gia_nhap')) : '';
$ngay_rua_toi_display = function_exists('ht_profile_format_date') ? ht_profile_format_date(tk_val($profile, 'ngay_rua_toi')) : '';
$ngay_them_suc_display = function_exists('ht_profile_format_date') ? ht_profile_format_date(tk_val($profile, 'ngay_them_suc')) : '';
$tenure = function_exists('ht_profile_tenure_text') ? ht_profile_tenure_text(tk_val($profile, 'ngay_gia_nhap')) : '';

$avatar_url = '';
if (function_exists('ht_profile_avatar_url') && tk_val($profile, 'avatar') !== '') {
    $avatar_url = ht_profile_avatar_url($profile['avatar']);
}

$full_name = trim(($ten_thanh !== '' ? $ten_thanh . ' ' : '') . $ho_ten);
$ini = 'HT';
$parts = preg_split('/\s+/', trim($ho_ten));
if ($parts && $parts[0] !== '') {
    $ini = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tài khoản — ĐOÀN TNTT Đaminh Savio Lập Trí</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --acc-primary: #1565c0;
            --acc-primary-2: #42a5f5;
            --acc-text: #1a237e;
            --acc-muted: #607d8b;
        }
        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(160deg, #e8f4fc 0%, #f4f8fb 45%, #e3f2fd 100%);
            min-height: 100vh;
            color: #263238;
        }
        .acc-topbar {
            background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5);
            color: #fff;
            padding: 1rem 0;
            box-shadow: 0 8px 24px rgba(13,71,161,.22);
            position: relative;
            z-index: 10;
        }
        .acc-topbar a { color: rgba(255,255,255,.92); text-decoration: none; font-weight: 700; font-size: .9rem; }
        .acc-topbar a:hover { color: #fff; }
        .acc-topbar a.active { background: rgba(255,255,255,.18); border-radius: 999px; padding: .35rem .85rem; }

        main.container.acc-main {
            padding-top: 1.5rem;
        }

        .hero-card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 14px 40px rgba(21,101,192,.1);
            border: 1px solid rgba(21,101,192,.06);
            overflow: hidden;
            margin-top: 0;
            position: relative;
        }
        .hero-banner {
            height: 72px;
            background: linear-gradient(120deg, #0d47a1, #1565c0 40%, #64b5f6);
        }
        .hero-body {
            padding: 1.25rem 1.75rem 1.75rem;
            margin-top: 0;
            background: #fff;
        }
        .hero-profile-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.25rem;
        }
        .hero-text-block {
            flex: 1 1 240px;
            min-width: 0;
            padding-top: 0;
        }
        .avatar-xl {
            width: 96px; height: 96px; border-radius: 50%;
            background: linear-gradient(145deg, #42a5f5, #1565c0);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 1.85rem; font-weight: 800;
            border: 4px solid #fff;
            box-shadow: 0 6px 18px rgba(21,101,192,.18);
            overflow: hidden; flex-shrink: 0;
        }
        .avatar-xl img { width: 100%; height: 100%; object-fit: cover; }
        .hero-name {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--acc-text);
            margin: 0;
            line-height: 1.25;
        }
        .hero-saint {
            color: var(--acc-muted);
            font-weight: 600;
            font-size: .95rem;
            margin-bottom: .15rem;
        }
        .hero-divider {
            border: none;
            border-top: 1px solid #e8eef5;
            margin: 1.25rem 0 0;
        }
        .badge-cap { border-radius: 999px; font-weight: 700; font-size: .78rem; padding: .38em .75em; }
        .badge-ht { background: #ffebee; color: #c62828; }
        .badge-hdt { background: #fff8e1; color: #f57f17; }
        .badge-role { background: #e3f2fd; color: #1565c0; }
        .badge-nganh { background: #f3e5f5; color: #6a1b9a; }
        .badge-account { background: #eceff1; color: #455a64; }

        .mini-stat {
            background: #f8fbff;
            border: 1px solid #e3eef6;
            border-radius: 1rem;
            padding: .85rem 1rem;
            text-align: center;
            height: 100%;
        }
        .mini-stat .ico { font-size: 1.25rem; color: var(--acc-primary); margin-bottom: .25rem; }
        .mini-stat .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--acc-muted); font-weight: 700; }
        .mini-stat .val { font-size: 1rem; font-weight: 800; color: var(--acc-text); margin-top: .15rem; }

        .panel {
            background: #fff;
            border-radius: 1.15rem;
            box-shadow: 0 10px 30px rgba(21,101,192,.07);
            border: 1px solid rgba(21,101,192,.05);
            overflow: hidden;
        }
        .panel-head {
            background: linear-gradient(135deg, #1565c0, #42a5f5);
            color: #fff;
            padding: 1rem 1.25rem;
            font-weight: 800;
            font-size: 1.05rem;
        }
        .panel-body { padding: 1.35rem 1.25rem; }

        .info-tile {
            background: #f8fbff;
            border: 1px solid #e8eef5;
            border-radius: .9rem;
            padding: .85rem 1rem;
            height: 100%;
        }
        .info-tile .lbl {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            color: var(--acc-muted); font-weight: 700; margin-bottom: .25rem;
            display: flex; align-items: center; gap: .4rem;
        }
        .info-tile .lbl i { color: var(--acc-primary); width: 1rem; text-align: center; }
        .info-tile .val { font-weight: 700; color: var(--acc-text); word-break: break-word; }
        .info-tile .val a { color: var(--acc-primary); text-decoration: none; }
        .info-tile .val a:hover { text-decoration: underline; }

        .nav-tabs-acc {
            border: none;
            gap: .35rem;
            margin-bottom: 1.25rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: .25rem;
        }
        .nav-tabs-acc .nav-link {
            border: none;
            border-radius: 999px;
            font-weight: 700;
            color: #546e7a;
            padding: .5rem 1.1rem;
            white-space: nowrap;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .nav-tabs-acc .nav-link.active {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            color: #fff;
        }
        .form-label { font-weight: 700; color: #37474f; font-size: .9rem; }
        .form-control, .form-select {
            border-radius: .75rem;
            border-color: #d6e4f0;
        }
        .btn-save {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            border: none; border-radius: 999px;
            padding: .65rem 1.6rem; font-weight: 800; color: #fff;
        }
        .btn-save:hover { filter: brightness(1.05); color: #fff; }
        .note-box {
            background: #fffde7;
            border: 1px solid #fff59d;
            border-radius: .85rem;
            padding: .85rem 1rem;
            font-size: .92rem;
            color: #5d4037;
        }
        @media (max-width: 768px) {
            main.container.acc-main { padding-top: 1rem; }
            .hero-body { padding: 1rem 1rem 1.25rem; }
            .avatar-xl { width: 76px; height: 76px; font-size: 1.45rem; }
            .hero-name { font-size: 1.2rem; }
            .hero-banner { height: 56px; }
            .acc-topbar .fw-bold { font-size: .85rem; }
        }
    </style>
</head>
<body>

<div class="acc-topbar">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <img src="img/logo.png" alt="" width="36" height="36" style="object-fit:contain">
            <span class="fw-bold">ĐOÀN TNTT Đaminh Savio Lập Trí</span>
        </div>
        <nav class="d-flex flex-wrap gap-2 align-items-center">
            <a href="index.php"><i class="fas fa-home me-1"></i>Trang chủ</a>
            <a href="classes.php"><i class="fas fa-users me-1"></i>Đoàn sinh</a>
            <?php if (login_can_view_student_report()): ?>
            <a href="bao_cao_doan_sinh.php"><i class="fas fa-file-lines me-1"></i>Báo cáo Đoàn sinh</a>
            <?php endif; ?>
            <a href="tai_khoan.php" class="active"><i class="fas fa-id-card me-1"></i>Tài khoản</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>Đăng xuất</a>
        </nav>
    </div>
</div>

<main class="container pb-5 acc-main">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mt-3">
            <?php echo tk_h($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mt-3">
            <?php echo tk_h($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="hero-card mb-4">
        <div class="hero-banner"></div>
        <div class="hero-body">
            <div class="hero-profile-row">
                <div class="avatar-xl">
                    <?php if ($avatar_url !== '' && is_file(__DIR__ . '/' . $avatar_url)): ?>
                        <img src="<?php echo tk_h($avatar_url); ?>" alt="">
                    <?php else: ?>
                        <?php echo tk_h($ini); ?>
                    <?php endif; ?>
                </div>
                <div class="hero-text-block">
                    <?php if ($ten_thanh !== ''): ?>
                        <div class="hero-saint"><?php echo tk_h($ten_thanh); ?></div>
                    <?php endif; ?>
                    <h1 class="hero-name"><?php echo tk_h($ho_ten); ?></h1>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php
                        // Tránh hiện 2 badge "Huynh Trưởng" trùng nhau
                        $show_role_badge = ($role_label !== $cap_label && $role_label !== $loai_huynh);
                        if ($show_role_badge):
                        ?>
                            <span class="badge-cap badge-account"><i class="fas fa-user-shield me-1"></i><?php echo tk_h($role_label); ?></span>
                        <?php endif; ?>
                        <?php if ($loai_huynh !== ''): ?>
                            <span class="badge-cap <?php echo $is_hdt ? 'badge-hdt' : 'badge-ht'; ?>"><?php echo tk_h($cap_label); ?></span>
                        <?php elseif ($role === 'huynh_truong'): ?>
                            <span class="badge-cap badge-ht"><?php echo tk_h($role_label); ?></span>
                        <?php endif; ?>
                        <?php if ($display_vai_tro !== ''): ?>
                            <span class="badge-cap badge-role"><?php echo tk_h($display_vai_tro); ?></span>
                        <?php endif; ?>
                        <?php if ($display_nganh !== ''): ?>
                            <span class="badge-cap badge-nganh"><?php echo tk_h($display_nganh); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small mt-2"><i class="fas fa-at me-1"></i><?php echo tk_h(tk_val($profile, 'username')); ?></div>
                </div>
            </div>

            <hr class="hero-divider">

            <div class="row g-2 mt-3">
                <div class="col-6 col-md-3">
                    <div class="mini-stat">
                        <div class="ico"><i class="fas fa-chalkboard-user"></i></div>
                        <div class="lbl">Lớp phụ trách</div>
                        <div class="val"><?php echo tk_h(tk_dash($display_lop)); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mini-stat">
                        <div class="ico"><i class="fas fa-calendar-check"></i></div>
                        <div class="lbl">Gia nhập Đoàn</div>
                        <div class="val"><?php echo tk_h($ngay_gia_nhap_display !== '' ? $ngay_gia_nhap_display : '—'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mini-stat">
                        <div class="ico"><i class="fas fa-hourglass-half"></i></div>
                        <div class="lbl">Thời gian phục vụ</div>
                        <div class="val"><?php echo tk_h($tenure !== '' ? $tenure : '—'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mini-stat">
                        <div class="ico"><i class="fas fa-layer-group"></i></div>
                        <div class="lbl">Ngành</div>
                        <div class="val"><?php echo tk_h(tk_dash($display_nganh)); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs-acc" id="accTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ca-nhan" type="button"><i class="fas fa-user me-1"></i>Cá nhân</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-doan" type="button"><i class="fas fa-cross me-1"></i>Phục vụ Đoàn</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bao-mat" type="button"><i class="fas fa-lock me-1"></i>Bảo mật</button></li>
    </ul>

    <div class="tab-content">
        <!-- Tab Cá nhân -->
        <div class="tab-pane fade show active" id="tab-ca-nhan">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="panel h-100">
                        <div class="panel-head"><i class="fas fa-id-card me-2"></i>Hồ sơ cá nhân</div>
                        <div class="panel-body">
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-cake-candles"></i>Ngày sinh</div>
                                        <div class="val"><?php echo tk_h(tk_dash($ngay_sinh_display)); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-venus-mars"></i>Giới tính</div>
                                        <div class="val"><?php echo tk_h(tk_dash(tk_val($profile, 'gioi_tinh'))); ?></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-phone"></i>Số điện thoại</div>
                                        <div class="val">
                                            <?php $sdt = tk_val($profile, 'so_dien_thoai'); ?>
                                            <?php if ($sdt !== ''): ?>
                                                <a href="tel:<?php echo tk_h($sdt); ?>"><?php echo tk_h($sdt); ?></a>
                                            <?php else: ?>—<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-envelope"></i>Email</div>
                                        <div class="val">
                                            <?php $em = tk_val($profile, 'email'); ?>
                                            <?php if ($em !== ''): ?>
                                                <a href="mailto:<?php echo tk_h($em); ?>"><?php echo tk_h($em); ?></a>
                                            <?php else: ?>—<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-location-dot"></i>Địa chỉ</div>
                                        <div class="val"><?php echo tk_h(tk_dash(tk_val($profile, 'dia_chi'))); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-water"></i>Rửa tội</div>
                                        <div class="val"><?php echo tk_h(tk_dash($ngay_rua_toi_display)); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-dove"></i>Thêm sức</div>
                                        <div class="val"><?php echo tk_h(tk_dash($ngay_them_suc_display)); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="panel">
                        <div class="panel-head"><i class="fas fa-pen me-2"></i>Cập nhật thông tin</div>
                        <div class="panel-body">
                            <p class="text-muted small mb-3">Chỉnh sửa các mục bạn có thể tự cập nhật. Thông tin Đoàn (cấp bậc, lớp, ngày gia nhập…) do quản lý cập nhật.</p>
                            <form method="POST" autocomplete="off">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Tên thánh</label>
                                        <input type="text" name="ten_thanh" class="form-control" value="<?php echo tk_h($ten_thanh); ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Họ và tên <span class="text-danger">*</span></label>
                                        <input type="text" name="ho_ten" class="form-control" required value="<?php echo tk_h($ho_ten); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Giới tính</label>
                                        <select name="gioi_tinh" class="form-select">
                                            <option value="">— Chọn —</option>
                                            <option value="Nam" <?php echo (tk_val($profile, 'gioi_tinh') === 'Nam') ? 'selected' : ''; ?>>Nam</option>
                                            <option value="Nữ" <?php echo (tk_val($profile, 'gioi_tinh') === 'Nữ') ? 'selected' : ''; ?>>Nữ</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Ngày sinh</label>
                                        <input type="text" name="ngay_sinh" class="form-control" placeholder="dd/mm/yyyy" value="<?php echo tk_h($ngay_sinh_display); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Số điện thoại</label>
                                        <input type="text" name="so_dien_thoai" class="form-control" value="<?php echo tk_h(tk_val($profile, 'so_dien_thoai')); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo tk_h(tk_val($profile, 'email')); ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Địa chỉ</label>
                                        <input type="text" name="dia_chi" class="form-control" value="<?php echo tk_h(tk_val($profile, 'dia_chi')); ?>">
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-save"><i class="fas fa-save me-2"></i>Lưu thông tin</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Phục vụ Đoàn -->
        <div class="tab-pane fade" id="tab-doan">
            <div class="panel mb-4">
                <div class="panel-head"><i class="fas fa-users me-2"></i>Thông tin phục vụ Đoàn</div>
                <div class="panel-body">
                    <div class="row g-2">
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-medal"></i>Cấp bậc</div>
                                <div class="val"><?php echo tk_h(tk_dash($cap_label !== '' ? $cap_label : $loai_huynh)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-user-tag"></i>Vai trò lớp</div>
                                <div class="val"><?php echo tk_h(tk_dash($display_vai_tro)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-chalkboard"></i>Lớp phụ trách</div>
                                <div class="val"><?php echo tk_h(tk_dash($display_lop)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-layer-group"></i>Ngành</div>
                                <div class="val"><?php echo tk_h(tk_dash($display_nganh)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-calendar-plus"></i>Ngày gia nhập Đoàn</div>
                                <div class="val"><?php echo tk_h(tk_dash($ngay_gia_nhap_display)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-clock"></i>Thời gian sinh hoạt</div>
                                <div class="val"><?php echo tk_h(tk_dash($tenure)); ?></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="info-tile">
                                <div class="lbl"><i class="fas fa-link"></i>Lớp đang gán trên hệ thống</div>
                                <div class="val"><?php echo tk_h(tk_dash(tk_val($profile, 'lop_dang_day')) !== '—' ? tk_val($profile, 'lop_dang_day') : 'Chưa gán lớp'); ?></div>
                            </div>
                        </div>
                        <?php if (tk_val($profile, 'ghi_chu') !== ''): ?>
                        <div class="col-12">
                            <div class="note-box">
                                <strong><i class="fas fa-sticky-note me-1"></i>Ghi chú:</strong>
                                <?php echo tk_h(tk_val($profile, 'ghi_chu')); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Cần chỉnh cấp bậc, lớp hoặc ngày gia nhập? Liên hệ quản lý Huynh Trưởng / Admin.
                    </p>
                </div>
            </div>
        </div>

        <!-- Tab Bảo mật -->
        <div class="tab-pane fade" id="tab-bao-mat">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="panel">
                        <div class="panel-head"><i class="fas fa-key me-2"></i>Đổi mật khẩu</div>
                        <div class="panel-body">
                            <form method="POST" autocomplete="off">
                                <input type="hidden" name="action" value="change_password">
                                <div class="mb-3">
                                    <label class="form-label">Mật khẩu hiện tại</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Mật khẩu mới</label>
                                    <input type="password" name="new_password" class="form-control" minlength="4" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Xác nhận mật khẩu mới</label>
                                    <input type="password" name="confirm_password" class="form-control" minlength="4" required>
                                </div>
                                <button type="submit" class="btn btn-save"><i class="fas fa-lock me-2"></i>Đổi mật khẩu</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="panel h-100">
                        <div class="panel-head"><i class="fas fa-shield-halved me-2"></i>Tài khoản đăng nhập</div>
                        <div class="panel-body">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-user"></i>Tên đăng nhập</div>
                                        <div class="val"><?php echo tk_h(tk_val($profile, 'username')); ?></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-user-shield"></i>Quyền hệ thống</div>
                                        <div class="val"><?php echo tk_h($role_label); ?></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-tile">
                                        <div class="lbl"><i class="fas fa-id-badge"></i>Mã Huynh Trưởng</div>
                                        <div class="val"><?php echo tk_h(tk_val($profile, 'teacher_id') !== '' ? tk_val($profile, 'teacher_id') : '—'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
if (function_exists('login_include_sinh_nhat_popup')) {
    login_include_sinh_nhat_popup();
} elseif (is_file(__DIR__ . '/includes/sinh_nhat_popup.php')) {
    include __DIR__ . '/includes/sinh_nhat_popup.php';
}
?>
</body>
</html>
