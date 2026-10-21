<?php
/**
 * Popup chúc mừng sinh nhật — hiện với tài khoản có ngày sinh trong tuần (T2–CN).
 */
if (defined('TNTT_SINH_NHAT_POPUP_LOADED')) {
    return;
}
define('TNTT_SINH_NHAT_POPUP_LOADED', true);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (empty($_SESSION['user_id'])) {
    return;
}

try {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/../db_connect.php';
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $conn = $GLOBALS['conn'];
        }
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }
    if (!function_exists('sn_should_show_popup')) {
        require_once __DIR__ . '/sinh_nhat.php';
    }
    if (!function_exists('app_url')) {
        require_once __DIR__ . '/../login_redirect.php';
    }

    $uid = (int) $_SESSION['user_id'];
    if (!sn_should_show_popup($conn, $uid)) {
        return;
    }
    $me = sn_current_user_birthday_this_week($conn, $uid);
    if (!$me) {
        return;
    }
    $week = isset($me['week']) ? $me['week'] : sn_week_bounds();
    $dismiss_url = app_url('dismiss_sinh_nhat.php');
    $page_url = app_url('index.php') . '#sinh-nhat';
} catch (Exception $e) {
    return;
}

function sn_popup_h($v) {
    return htmlspecialchars((string) (isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}

$display_name = trim((isset($me['ten_thanh']) && $me['ten_thanh'] !== '' ? $me['ten_thanh'] . ' ' : '') . (isset($me['ho_ten']) ? $me['ho_ten'] : ''));
$tuoi = isset($me['tuoi']) ? $me['tuoi'] : null;
$is_female = !empty($me['is_female']);
$theme = $is_female ? 'nu' : 'nam';
$avatar_url = isset($me['avatar_url']) ? $me['avatar_url'] : '';
$initials = isset($me['initials']) && $me['initials'] !== '' ? $me['initials'] : '?';
$week_label = date('d/m', strtotime($week['start'])) . ' – ' . date('d/m', strtotime($week['end']));
?>
<style>
#snBirthdayModal .modal-content {
    border: none;
    border-radius: 1.4rem;
    overflow: hidden;
    background: #fff;
}
#snBirthdayModal.sn-theme-nam .modal-content {
    background: linear-gradient(180deg, #fff 0%, #eef7ff 55%, #e3f2fd 100%);
}
#snBirthdayModal.sn-theme-nu .modal-content {
    background: linear-gradient(180deg, #fff 0%, #fff0f5 55%, #fce4ec 100%);
}
#snBirthdayModal .sn-banner {
    color: #fff;
    text-align: center;
    padding: 1.35rem 1.15rem 2.6rem;
    position: relative;
}
#snBirthdayModal.sn-theme-nam .sn-banner {
    background: linear-gradient(125deg, #1565c0, #42a5f5 48%, #81d4fa);
}
#snBirthdayModal.sn-theme-nu .sn-banner {
    background: linear-gradient(125deg, #ad1457, #ec407a 48%, #f8bbd0);
}
#snBirthdayModal .sn-cake-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: .35rem;
    filter: drop-shadow(0 4px 10px rgba(0,0,0,.18));
}
#snBirthdayModal .sn-cake-wrap svg { width: 3.1rem; height: 3.1rem; display: block; }
#snBirthdayModal .sn-title {
    font-family: 'Dancing Script', 'Nunito', cursive;
    font-size: clamp(1.7rem, 5vw, 2.05rem);
    font-weight: 700;
    margin: 0;
    line-height: 1.15;
    text-shadow: 0 2px 8px rgba(0,0,0,.12);
}
#snBirthdayModal .sn-org {
    font-weight: 700;
    opacity: .96;
    font-size: .9rem;
    margin-top: .3rem;
}
#snBirthdayModal .sn-body {
    padding: 0 1.25rem 1.35rem;
    text-align: center;
    margin-top: -2.1rem;
    position: relative;
    z-index: 2;
}
#snBirthdayModal .sn-avatar {
    width: 92px;
    height: 92px;
    border-radius: 50%;
    margin: 0 auto .85rem;
    border: 4px solid #fff;
    box-shadow: 0 8px 22px rgba(0,0,0,.14);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.55rem;
    color: #fff;
    position: relative;
}
#snBirthdayModal.sn-theme-nam .sn-avatar { background: linear-gradient(145deg, #64b5f6, #1e88e5); }
#snBirthdayModal.sn-theme-nu .sn-avatar { background: linear-gradient(145deg, #f48fb1, #ec407a); }
#snBirthdayModal .sn-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
#snBirthdayModal .sn-avatar-badge {
    position: absolute;
    right: -2px;
    bottom: -2px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
}
#snBirthdayModal .sn-avatar-badge svg { width: 18px; height: 18px; }
#snBirthdayModal .sn-name {
    font-size: 1.28rem;
    font-weight: 800;
    margin-bottom: .45rem;
    line-height: 1.3;
}
#snBirthdayModal.sn-theme-nam .sn-name { color: #0d47a1; }
#snBirthdayModal.sn-theme-nu .sn-name { color: #880e4f; }
#snBirthdayModal .sn-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .28rem .75rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    margin-bottom: .85rem;
}
#snBirthdayModal.sn-theme-nam .sn-chip {
    background: rgba(33,150,243,.12);
    color: #1565c0;
}
#snBirthdayModal.sn-theme-nu .sn-chip {
    background: rgba(233,30,99,.12);
    color: #ad1457;
}
#snBirthdayModal .sn-wish-box {
    text-align: left;
    font-weight: 600;
    font-size: .88rem;
    line-height: 1.55;
    color: #455a64;
    background: rgba(255,255,255,.78);
    border-radius: 1rem;
    padding: .85rem .9rem;
    margin-bottom: 1rem;
    border: 1px solid rgba(0,0,0,.05);
}
#snBirthdayModal .sn-wish-box p {
    margin: 0 0 .55rem;
}
#snBirthdayModal .sn-wish-box p:last-child {
    margin-bottom: 0;
}
#snBirthdayModal .sn-wish-headline {
    text-align: center;
    font-weight: 800;
    margin-bottom: .65rem;
}
#snBirthdayModal .sn-wish-h1 {
    font-size: clamp(.82rem, 3.5vw, .95rem);
    line-height: 1.35;
    letter-spacing: .01em;
}
#snBirthdayModal .sn-wish-h2 {
    margin-top: .28rem;
    font-size: .86rem;
    font-weight: 800;
}
#snBirthdayModal .sn-wish-meta {
    display: inline-block;
    white-space: nowrap;
    padding: .12rem .65rem;
    border-radius: 999px;
    background: rgba(33,150,243,.1);
}
#snBirthdayModal.sn-theme-nu .sn-wish-meta {
    background: rgba(233,30,99,.1);
}
#snBirthdayModal.sn-theme-nam .sn-wish-headline { color: #0d47a1; }
#snBirthdayModal.sn-theme-nu .sn-wish-headline { color: #880e4f; }
#snBirthdayModal .sn-wish-end {
    text-align: center;
    font-weight: 800;
    font-size: .86rem;
    line-height: 1.4;
    margin-top: .15rem;
}
#snBirthdayModal.sn-theme-nam .sn-wish-box { border-color: rgba(33,150,243,.18); }
#snBirthdayModal.sn-theme-nu .sn-wish-box { border-color: rgba(233,30,99,.16); }
#snBirthdayModal .sn-wish-box strong { font-weight: 800; }
#snBirthdayModal.sn-theme-nam .sn-wish-box strong { color: #1565c0; }
#snBirthdayModal.sn-theme-nu .sn-wish-box strong { color: #c2185b; }
#snBirthdayModal .btn-sn-primary {
    border: none;
    border-radius: 999px;
    font-weight: 800;
    color: #fff;
    padding: .62rem 1.35rem;
}
#snBirthdayModal.sn-theme-nam .btn-sn-primary {
    background: linear-gradient(135deg, #1976d2, #42a5f5);
}
#snBirthdayModal.sn-theme-nu .btn-sn-primary {
    background: linear-gradient(135deg, #c2185b, #ec407a);
}
#snBirthdayModal .btn-sn-ghost {
    border-radius: 999px;
    font-weight: 800;
    padding: .55rem 1.2rem;
    background: #fff;
}
#snBirthdayModal.sn-theme-nam .btn-sn-ghost {
    border: 2px solid #90caf9;
    color: #1565c0;
}
#snBirthdayModal.sn-theme-nu .btn-sn-ghost {
    border: 2px solid #f48fb1;
    color: #ad1457;
}
@media (max-width: 480px) {
    #snBirthdayModal .sn-body { padding: 0 1rem 1.15rem; }
    #snBirthdayModal .sn-avatar { width: 84px; height: 84px; }
    #snBirthdayModal .sn-wish-box { font-size: .84rem; padding: .75rem .8rem; }
}
</style>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<div class="modal fade sn-theme-<?php echo $theme; ?>" id="snBirthdayModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="sn-banner">
                <div class="sn-cake-wrap" aria-hidden="true">
                    <svg viewBox="0 0 64 64" focusable="false">
                        <ellipse cx="32" cy="54" rx="26" ry="5" fill="#f48fb1"/>
                        <path d="M10 36h44v16c0 2-10 4-22 4s-22-2-22-4V36z" fill="#ffcc80"/>
                        <path d="M10 36c0 2 10 4 22 4s22-2 22-4c0-2-10-4-22-4s-22 2-22 4z" fill="#ffe0b2"/>
                        <path d="M14 28h36v10c0 1.5-8 3-18 3s-18-1.5-18-3V28z" fill="#f8bbd0"/>
                        <path d="M14 28c0 1.5 8 3 18 3s18-1.5 18-3-8-3-18-3-18 1.5-18 3z" fill="#fce4ec"/>
                        <path d="M18 22h28v8c0 1.2-6 2.4-14 2.4S18 31.2 18 30v-8z" fill="#ef5350"/>
                        <path d="M18 22c0 1.2 6 2.4 14 2.4s14-1.2 14-2.4-6-2.4-14-2.4S18 20.8 18 22z" fill="#e57373"/>
                        <rect x="22" y="12" width="3" height="10" rx="1.2" fill="#fff59d"/>
                        <rect x="30.5" y="10" width="3" height="12" rx="1.2" fill="#fff59d"/>
                        <rect x="39" y="12" width="3" height="10" rx="1.2" fill="#fff59d"/>
                        <circle cx="23.5" cy="11" r="2.2" fill="#ff7043"/>
                        <circle cx="32" cy="9" r="2.4" fill="#42a5f5"/>
                        <circle cx="40.5" cy="11" r="2.2" fill="#66bb6a"/>
                        <circle cx="20" cy="40" r="2" fill="#ec407a"/>
                        <circle cx="32" cy="42" r="2" fill="#ab47bc"/>
                        <circle cx="44" cy="40" r="2" fill="#26c6da"/>
                    </svg>
                </div>
                <h2 class="sn-title">Chúc Mừng Sinh Nhật!</h2>
                <div class="sn-org">Đoàn TNTT Đaminh Savio Lập Trí</div>
            </div>
            <div class="sn-body">
                <div class="sn-avatar">
                    <?php if ($avatar_url !== ''): ?>
                        <img src="<?php echo sn_popup_h($avatar_url); ?>" alt="<?php echo sn_popup_h($display_name); ?>">
                    <?php else: ?>
                        <span><?php echo sn_popup_h($initials); ?></span>
                    <?php endif; ?>
                    <span class="sn-avatar-badge" aria-hidden="true">
                        <svg viewBox="0 0 64 64" focusable="false">
                            <ellipse cx="32" cy="54" rx="26" ry="5" fill="#f48fb1"/>
                            <path d="M10 36h44v16c0 2-10 4-22 4s-22-2-22-4V36z" fill="#ffcc80"/>
                            <path d="M14 28h36v10c0 1.5-8 3-18 3s-18-1.5-18-3V28z" fill="#f8bbd0"/>
                            <path d="M18 22h28v8c0 1.2-6 2.4-14 2.4S18 31.2 18 30v-8z" fill="#ef5350"/>
                            <rect x="30.5" y="10" width="3" height="12" rx="1.2" fill="#fff59d"/>
                            <circle cx="32" cy="9" r="2.4" fill="#42a5f5"/>
                        </svg>
                    </span>
                </div>

                <div class="sn-name"><?php echo sn_popup_h($display_name); ?></div>
                <div class="sn-chip">
                    <span aria-hidden="true">🎉</span>
                    Sinh nhật tuần này · <?php echo sn_popup_h($week_label); ?>
                </div>

                <div class="sn-wish-box">
                    <div class="sn-wish-headline">
                        <div class="sn-wish-h1">🎉 CHÚC MỪNG SINH NHẬT TRƯỞNG 🎂</div>
                        <?php if (!empty($me['ngay_md']) || $tuoi !== null): ?>
                            <div class="sn-wish-h2">
                                <span class="sn-wish-meta">
                                    <?php if (!empty($me['ngay_md'])): ?>
                                        <?php echo sn_popup_h($me['ngay_md']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($me['ngay_md']) && $tuoi !== null): ?> · <?php endif; ?>
                                    <?php if ($tuoi !== null): ?>
                                        TUỔI <?php echo (int) $tuoi; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p>
                        Nhân ngày sinh nhật, Đoàn TNTT Đaminh Savio Lập Trí thân chúc Trưởng tuổi mới thật nhiều niềm vui, bình an, sức khỏe và hồng&nbsp;ân.
                    </p>
                    <p>
                        Nguyện xin Chúa Giêsu và Mẹ Maria luôn đồng hành, gìn giữ và ban thêm lòng nhiệt thành, yêu thương để Trưởng luôn vui tươi và bền tâm trên hành trình phục vụ các em thiếu&nbsp;nhi. 💙🙏
                    </p>
                    <p class="sn-wish-end">
                        Chúc Trưởng một tuổi mới tràn đầy hồng&nbsp;ân<br>
                        và thật nhiều điều tốt đẹp! 🎂🎉
                    </p>
                </div>

                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="<?php echo sn_popup_h($page_url); ?>" class="btn btn-sn-primary">
                        <i class="fas fa-gift me-1"></i>Xem trên trang chủ
                    </a>
                    <form method="POST" action="<?php echo sn_popup_h($dismiss_url); ?>" class="d-inline">
                        <input type="hidden" name="week_key" value="<?php echo sn_popup_h($week['key']); ?>">
                        <input type="hidden" name="return" value="<?php echo sn_popup_h(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index.php'); ?>">
                        <button type="submit" class="btn btn-sn-ghost">Đóng</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    function showSnPopup() {
        var el = document.getElementById('snBirthdayModal');
        if (!el || typeof bootstrap === 'undefined') return;
        var modal = bootstrap.Modal.getOrCreateInstance(el);
        modal.show();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showSnPopup);
    } else {
        showSnPopup();
    }
})();
</script>
