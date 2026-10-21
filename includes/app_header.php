<?php
/**
 * Header dùng chung cho các trang mới (Phiếu ra cổng, Quét QR, Thẻ QR).
 * Dùng: require_once __DIR__ . '/includes/app_header.php'; app_header_render('Phiếu ra cổng');
 * CSS/JS tự chứa (không cần Bootstrap). Trang cần nạp Font Awesome 6.
 */
require_once dirname(__DIR__) . '/login_redirect.php';

if (!function_exists('app_header_render')) {

function app_header_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function app_header_active_key($page) {
    $map = [
        'home'          => ['index.php'],
        'lop'           => ['classes.php', 'class_details.php', 'the_qr.php'],
        'phieu_ra_cong' => ['phieu_ra_cong.php'],
        'quet_qr'       => ['diem_danh_qr.php'],
        'diem_danh'     => ['diemdanh.php'],
        'tai_khoan_ht'  => ['quan_ly_tai_khoan.php'],
        'bao_cao'       => ['bao_cao_doan_sinh.php'],
        'loichua'       => ['loichua.php'],
        'tai_khoan'     => ['tai_khoan.php'],
    ];
    foreach ($map as $key => $pages) {
        if (in_array($page, $pages, true)) {
            return $key;
        }
    }
    return '';
}

function app_header_render($subtitle = '') {
    $logged_in = !empty($_SESSION['user_id']);
    $role = login_current_role();
    $is_admin = login_is_admin($role);
    $is_hdt = login_is_hdt_manager($role);
    $page = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    $active = app_header_active_key($page);

    $my_classes = array();
    if ($logged_in && !$is_hdt) {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $db_file = dirname(__DIR__) . '/db_connect.php';
            if (is_file($db_file)) {
                require_once $db_file;
            }
        }
        if (isset($conn) && $conn instanceof mysqli) {
            $my_classes = login_user_assigned_classes($conn);
        }
    }

    // [key, url, icon, nhãn] là link đơn; ['group' => nhãn, 'icon' => ..., 'items' => [...]] là nhóm xổ xuống.
    $menu = [];
    $menu[] = ['home', 'index.php', 'fa-home', 'Trang chủ'];
    $menu[] = ['lop', 'classes.php', 'fa-users', 'Đoàn sinh'];
    if ($logged_in) {
        $menu[] = ['phieu_ra_cong', 'phieu_ra_cong.php', 'fa-door-open', 'Phiếu ra cổng'];
    }
    if (!empty($my_classes)) {
        $today = date('Y-m-d');
        $qr_items = array();
        foreach ($my_classes as $c) {
            $qr_items[] = [
                'quet_qr',
                'diem_danh_qr.php?class_id=' . (int)$c['id'] . '&ngay=' . urlencode($today),
                'fa-qrcode',
                count($my_classes) === 1 ? 'Quét QR' : $c['ten_lop'],
            ];
        }
        $menu[] = ['group' => 'Quét QR', 'icon' => 'fa-qrcode', 'items' => $qr_items];
    }
    if ($logged_in && $is_hdt) {
        $menu[] = ['diem_danh', 'diemdanh.php', 'fa-clipboard-check', 'Điểm danh'];
        $menu[] = ['tai_khoan_ht', 'quan_ly_tai_khoan.php', 'fa-user-cog', 'Tài khoản HT'];
    }
    if ($logged_in && function_exists('login_can_view_student_report') && login_can_view_student_report($role)) {
        $menu[] = ['bao_cao', 'bao_cao_doan_sinh.php', 'fa-file-lines', 'Báo cáo'];
    }
    $menu[] = ['loichua', 'loichua.php', 'fa-bible', 'Lời Chúa'];
    if ($logged_in) {
        $menu[] = ['group' => 'Tài khoản', 'icon' => 'fa-id-card', 'items' => [
            ['tai_khoan', 'tai_khoan.php', 'fa-id-card', 'Thông tin cá nhân'],
            ['', 'logout.php', 'fa-sign-out-alt', 'Đăng xuất'],
        ]];
    } else {
        $menu[] = ['', login_page_url(), 'fa-sign-in-alt', 'Đăng nhập'];
    }
    foreach ($menu as $i => $m) {
        if (isset($m['group']) && count($m['items']) === 1) {
            $menu[$i] = $m['items'][0];
        }
    }
    ?>
<style>
    .app-topbar, .app-topbar * { box-sizing: border-box; }
    .app-topbar { font-family: 'Nunito', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5); color: #fff; box-shadow: 0 8px 24px rgba(13,71,161,.22); position: sticky; top: 0; z-index: 1030; padding: .6rem 0; line-height: 1.3; text-align: left; }
    .app-topbar .app-container { width: 100%; max-width: 1320px; margin: 0 auto; padding: 0 12px; }
    @media (min-width: 576px)  { .app-topbar .app-container { max-width: 540px; } }
    @media (min-width: 768px)  { .app-topbar .app-container { max-width: 720px; } }
    @media (min-width: 992px)  { .app-topbar .app-container { max-width: 960px; } }
    @media (min-width: 1200px) { .app-topbar .app-container { max-width: 1140px; } }
    @media (min-width: 1400px) { .app-topbar .app-container { max-width: 1320px; } }
    .app-topbar .app-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-height: 48px; }
    .app-topbar .app-brand { display: flex; align-items: center; gap: .65rem; color: #fff; text-decoration: none; min-width: 0; flex: 1; }
    .app-topbar .app-brand:hover { color: #fff; opacity: .95; }
    .app-topbar .app-brand img { width: 42px; height: 42px; object-fit: contain; flex-shrink: 0; margin: 0; max-width: none; }
    .app-topbar .app-brand-text { font-weight: 800; font-size: .95rem; min-width: 0; }
    .app-topbar .app-brand-text .app-sub { display: block; font-size: .72rem; font-weight: 600; opacity: .85; margin-top: .1rem; }
    .app-topbar .app-badge { display: inline-flex; align-items: center; gap: .3rem; font-size: .7rem; font-weight: 800; padding: .25rem .55rem; border-radius: .4rem; white-space: nowrap; flex-shrink: 0; }
    .app-topbar .app-badge.admin { background: #dc3545; color: #fff; }
    .app-topbar .app-badge.hdt { background: #ffc107; color: #212529; }
    .app-topbar .app-menu-btn { flex-shrink: 0; width: 42px; height: 42px; border: none; border-radius: 12px; background: rgba(255,255,255,.16); color: #fff; font-size: 1.1rem; display: none; align-items: center; justify-content: center; cursor: pointer; }
    .app-topbar .app-menu-btn:hover, .app-topbar .app-menu-btn[aria-expanded="true"] { background: rgba(255,255,255,.28); }
    .app-topbar .app-nav { display: flex; align-items: center; gap: .15rem; margin: 0; padding: 0; }
    .app-topbar .app-nav-link { display: inline-flex; align-items: center; gap: .4rem; color: rgba(255,255,255,.92); text-decoration: none; font-weight: 700; font-size: .86rem; padding: .45rem .65rem; border-radius: 999px; border: none; white-space: nowrap; transition: background .15s, color .15s; }
    .app-topbar .app-nav-link:hover { color: #fff; background: rgba(255,255,255,.14); text-decoration: none; }
    .app-topbar .app-nav-link.active { background: rgba(255,255,255,.22); color: #fff; }
    .app-topbar .app-nav-link i { width: 1.1rem; text-align: center; opacity: .9; }
    .app-topbar .app-dd { position: relative; }
    .app-topbar .app-dd-toggle { background: transparent; cursor: pointer; font-family: inherit; line-height: inherit; }
    .app-topbar .app-dd-caret { font-size: .65rem; width: auto !important; transition: transform .15s; }
    .app-topbar .app-dd.open .app-dd-caret { transform: rotate(180deg); }
    .app-topbar .app-dd-menu { display: none; }
    .app-topbar .app-dd.open .app-dd-menu { display: block; }
    .app-topbar .app-dd-item { display: flex; align-items: center; gap: .55rem; text-decoration: none; font-weight: 700; font-size: .86rem; white-space: nowrap; }
    .app-topbar .app-dd-item i { width: 1.1rem; text-align: center; }
    @media (min-width: 1200px) {
        .app-topbar .app-dd-menu { position: absolute; top: calc(100% + 6px); left: 0; min-width: 210px; background: #fff; border-radius: .85rem; padding: .35rem; box-shadow: 0 12px 32px rgba(13,71,161,.25); z-index: 1040; }
        .app-topbar .app-dd:last-child .app-dd-menu { left: auto; right: 0; }
        .app-topbar .app-dd-item { color: #263238; padding: .55rem .75rem; border-radius: .6rem; }
        .app-topbar .app-dd-item i { color: #1565c0; }
        .app-topbar .app-dd-item:hover { background: #e3f2fd; color: #0d47a1; }
        .app-topbar .app-dd-item.active { background: #1565c0; color: #fff; }
        .app-topbar .app-dd-item.active i { color: #fff; }
        .app-topbar .app-shell { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .app-topbar .app-head { flex: 0 1 auto; min-width: 0; }
        .app-topbar .app-brand-text .app-name { white-space: nowrap; }
        .app-topbar .app-nav { flex-wrap: nowrap; justify-content: flex-end; }
    }
    @media (max-width: 1199.98px) {
        .app-topbar .app-dd-toggle { justify-content: flex-start; }
        .app-topbar .app-dd-caret { margin-left: auto; }
        .app-topbar .app-dd-menu { padding: .15rem 0 .3rem 1.4rem; }
        .app-topbar .app-dd-item { color: rgba(255,255,255,.9); padding: .6rem .85rem; border-radius: .75rem; white-space: normal; }
        .app-topbar .app-dd-item:hover, .app-topbar .app-dd-item.active { background: rgba(255,255,255,.18); color: #fff; }
        .app-topbar .app-menu-btn { display: inline-flex; }
        .app-topbar .app-nav-panel { display: none; border-top: 1px solid rgba(255,255,255,.14); margin-top: .5rem; padding-top: .35rem; }
        .app-topbar .app-nav-panel.open { display: block; }
        .app-topbar .app-nav { flex-direction: column; align-items: stretch; gap: .15rem; padding-bottom: .25rem; }
        .app-topbar .app-nav-link { width: 100%; border-radius: .75rem; padding: .7rem .85rem; white-space: normal; }
    }
    @media (max-width: 575.98px) {
        .app-topbar .app-brand-text { font-size: .85rem; }
        .app-topbar .app-brand img { width: 36px; height: 36px; }
        .app-topbar .app-badge { display: none; }
    }
    @media print { .app-topbar { display: none !important; } }
</style>
<div class="app-topbar">
    <div class="app-container app-shell">
        <div class="app-head">
            <a class="app-brand" href="index.php">
                <img src="img/logo.png" alt="Logo Đoàn">
                <span class="app-brand-text">
                    <span class="app-name">ĐOÀN TNTT Đaminh Savio Lập Trí</span>
                    <?php if ($subtitle !== ''): ?>
                        <span class="app-sub"><?php echo app_header_h($subtitle); ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php if ($is_admin): ?>
                <span class="app-badge admin"><i class="fas fa-crown"></i>Admin</span>
            <?php elseif ($is_hdt): ?>
                <span class="app-badge hdt"><i class="fas fa-user-shield"></i>HDT</span>
            <?php endif; ?>
            <button type="button" class="app-menu-btn" aria-expanded="false" aria-controls="appNavPanel" aria-label="Mở menu"
                onclick="var p=document.getElementById('appNavPanel');var o=p.classList.toggle('open');this.setAttribute('aria-expanded',o?'true':'false');">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="app-nav-panel" id="appNavPanel">
            <div class="app-nav" role="navigation" aria-label="Menu chính">
                <?php foreach ($menu as $m): ?>
                    <?php if (isset($m['group'])): ?>
                        <?php
                            $group_active = false;
                            foreach ($m['items'] as $it) {
                                if ($it[0] !== '' && $it[0] === $active) {
                                    $group_active = true;
                                }
                            }
                        ?>
                        <div class="app-dd">
                            <button type="button" class="app-nav-link app-dd-toggle<?php echo $group_active ? ' active' : ''; ?>" aria-expanded="false">
                                <i class="fas <?php echo app_header_h($m['icon']); ?>"></i><span><?php echo app_header_h($m['group']); ?></span>
                                <i class="fas fa-chevron-down app-dd-caret"></i>
                            </button>
                            <div class="app-dd-menu">
                                <?php foreach ($m['items'] as $it): ?>
                                    <a href="<?php echo app_header_h($it[1]); ?>" class="app-dd-item<?php echo ($it[0] !== '' && $it[0] === $active) ? ' active' : ''; ?>">
                                        <i class="fas <?php echo app_header_h($it[2]); ?>"></i><span><?php echo app_header_h($it[3]); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo app_header_h($m[1]); ?>" class="app-nav-link<?php echo ($m[0] !== '' && $m[0] === $active) ? ' active' : ''; ?>">
                            <i class="fas <?php echo app_header_h($m[2]); ?>"></i><span><?php echo app_header_h($m[3]); ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var dds = document.querySelectorAll('.app-topbar .app-dd');
    function closeAll(except) {
        for (var i = 0; i < dds.length; i++) {
            if (dds[i] !== except) {
                dds[i].classList.remove('open');
                dds[i].querySelector('.app-dd-toggle').setAttribute('aria-expanded', 'false');
            }
        }
    }
    for (var i = 0; i < dds.length; i++) {
        dds[i].querySelector('.app-dd-toggle').addEventListener('click', function (e) {
            e.stopPropagation();
            var dd = this.parentNode;
            closeAll(dd);
            var open = dd.classList.toggle('open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('.app-topbar .app-dd')) {
            closeAll(null);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
    });
})();
</script>
    <?php
}

}
