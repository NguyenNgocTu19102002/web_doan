<?php
/**
 * Popup bổ nhiệm lớp — thư trang trọng, tone màu theo ngành.
 * Include gần cuối </body>.
 * Không được làm sập trang nếu lỗi DB/JSON trên hosting.
 */
if (defined('TNTT_BO_NHIEM_POPUP_LOADED')) {
    return;
}
define('TNTT_BO_NHIEM_POPUP_LOADED', true);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['show_bo_nhiem']) || empty($_SESSION['user_id'])) {
    return;
}

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/../db_connect.php';
    }
    require_once __DIR__ . '/huynh_profile.php';

    $bn = ht_profile_get_appointment($conn, (int)$_SESSION['user_id']);
    if (!$bn) {
        unset($_SESSION['show_bo_nhiem']);
        return;
    }
} catch (Exception $e) {
    // Trên hosting: bỏ qua popup, không chặn trang tài khoản / trang chủ
    unset($_SESSION['show_bo_nhiem']);
    return;
}

// URL dismiss thư — dùng app_url (local /tntt vs hosting root)
if (!function_exists('app_url')) {
    require_once __DIR__ . '/../login_redirect.php';
}
$bn_dismiss_url = app_url('dismiss_bo_nhiem.php');
$bn_logo_url = app_url('img/logo.png');

$bn_initials = '';
if ($bn['ho_ten'] !== '') {
    $parts = preg_split('/\s+/u', $bn['ho_ten']);
    $last = $parts ? end($parts) : '';
    $bn_initials = mb_strtoupper(
        mb_substr($bn['ten_thanh'] !== '' ? $bn['ten_thanh'] : 'T', 0, 1, 'UTF-8')
        . mb_substr($last !== '' ? $last : '?', 0, 1, 'UTF-8'),
        'UTF-8'
    );
}

$bn_blessing = 'Xin Thiên Chúa nâng đỡ và ban ơn giúp Trưởng luôn hăng say phục vụ.';

$nganh = trim((string) (isset($bn['nganh']) ? $bn['nganh'] : ''));
$themes = [
    'Chiên Con' => [
        'slug' => 'chien-con', 'label' => 'Ngành Chiên Con', 'icon' => 'fa-child',
        'c1' => '#ff80ab', 'c2' => '#ec407a', 'c3' => '#ad1457',
        'bg' => '#fff5f8', 'soft' => '#fce4ec', 'ink' => '#880e4f',
    ],
    'Ấu Nhi' => [
        'slug' => 'au-nhi', 'label' => 'Ngành Ấu Nhi', 'icon' => 'fa-seedling',
        'c1' => '#81c784', 'c2' => '#43a047', 'c3' => '#1b5e20',
        'bg' => '#f3faf3', 'soft' => '#e8f5e9', 'ink' => '#1b5e20',
    ],
    'Thiếu Nhi' => [
        'slug' => 'thieu-nhi', 'label' => 'Ngành Thiếu Nhi', 'icon' => 'fa-users',
        'c1' => '#64b5f6', 'c2' => '#1e88e5', 'c3' => '#0d47a1',
        'bg' => '#f3f8ff', 'soft' => '#e3f2fd', 'ink' => '#0d47a1',
    ],
    'Nghĩa Sĩ' => [
        'slug' => 'nghia-si', 'label' => 'Ngành Nghĩa Sĩ', 'icon' => 'fa-shield-halved',
        'c1' => '#ffd54f', 'c2' => '#ffb300', 'c3' => '#ff6f00',
        'bg' => '#fffdf2', 'soft' => '#fff8e1', 'ink' => '#e65100',
    ],
    'Hiệp Sĩ' => [
        'slug' => 'hiep-si', 'label' => 'Ngành Hiệp Sĩ', 'icon' => 'fa-crown',
        'c1' => '#a1887f', 'c2' => '#6d4c41', 'c3' => '#3e2723',
        'bg' => '#faf7f5', 'soft' => '#efebe9', 'ink' => '#3e2723',
    ],
];
$th = isset($themes[$nganh]) ? $themes[$nganh] : [
    'slug' => 'default', 'label' => ($nganh !== '' ? 'Ngành ' . $nganh : 'Đoàn TNTT'),
    'icon' => 'fa-certificate',
    'c1' => '#64b5f6', 'c2' => '#1565c0', 'c3' => '#0d47a1',
    'bg' => '#f5f9ff', 'soft' => '#e3f2fd', 'ink' => '#0d47a1',
];
?>
<style>
#bn-overlay {
    --bn-c1: <?php echo $th['c1']; ?>;
    --bn-c2: <?php echo $th['c2']; ?>;
    --bn-c3: <?php echo $th['c3']; ?>;
    --bn-bg: <?php echo $th['bg']; ?>;
    --bn-soft: <?php echo $th['soft']; ?>;
    --bn-ink: <?php echo $th['ink']; ?>;
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.1rem;
    background: rgba(0,0,0,0);
    backdrop-filter: blur(0);
    -webkit-backdrop-filter: blur(0);
    transition: background .75s ease, backdrop-filter .75s ease, opacity .75s ease;
    opacity: 0;
}
#bn-overlay.bn-show {
    background:
        radial-gradient(ellipse at 50% 20%, color-mix(in srgb, var(--bn-c2) 35%, transparent), transparent 55%),
        rgba(4, 8, 18, .88);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 1;
}

/* ===== Thư đóng — tone màu logo Đoàn ===== */
#bn-letter {
    --lg-navy: #1a458b;
    --lg-sky: #5ba3d9;
    --lg-red: #e31e24;
    --lg-gold: #fdb913;
    --lg-green: #39823f;
    --lg-pink: #d65595;
    --lg-brown: #7b4b2a;
    width: min(520px, 96vw);
    cursor: pointer;
    transform: translateY(40px) scale(.88);
    opacity: 0;
    transition: transform 1s cubic-bezier(.16,1,.3,1), opacity .75s ease;
    filter: drop-shadow(0 32px 52px rgba(0,0,0,.55));
    border: 0; background: transparent; padding: 0; font: inherit; outline: none;
}
#bn-overlay.bn-show #bn-letter { transform: none; opacity: 1; }
#bn-overlay.bn-opening #bn-letter,
#bn-overlay.bn-opened #bn-letter {
    pointer-events: none; opacity: 0;
    transform: translateY(-28px) scale(1.05) rotate(-2deg);
    transition: opacity .4s ease, transform .55s ease;
}
.bn-envelope {
    position: relative; width: 100%;
    aspect-ratio: 1.38 / 1;
    border-radius: 18px;
    background:
        radial-gradient(circle at 16% 84%, rgba(227,30,36,.09), transparent 32%),
        radial-gradient(circle at 84% 80%, rgba(57,130,63,.1), transparent 30%),
        radial-gradient(circle at 50% 92%, rgba(253,185,19,.12), transparent 34%),
        linear-gradient(165deg, #ffffff 0%, #f3f8ff 42%, #eaf3fc 100%);
    box-shadow:
        inset 0 0 0 2px rgba(255,255,255,.95),
        inset 0 0 0 5px rgba(26,69,139,.18),
        0 0 0 1px rgba(26,69,139,.28);
    overflow: hidden;
}
.bn-env-rainbow {
    position: absolute; left: 10%; right: 10%; top: 0; height: 5px; z-index: 7;
    border-radius: 0 0 8px 8px;
    background: linear-gradient(90deg,
        var(--lg-red) 0%,
        var(--lg-gold) 22%,
        var(--lg-green) 44%,
        var(--lg-pink) 66%,
        var(--lg-sky) 84%,
        var(--lg-brown) 100%);
}
.bn-envelope::before,
.bn-envelope::after {
    content: '';
    position: absolute; width: 44px; height: 44px; z-index: 5; pointer-events: none;
    border: 2px solid rgba(26,69,139,.35);
}
.bn-envelope::before { top: 14px; left: 14px; border-right: 0; border-bottom: 0; border-radius: 10px 0 0 0; }
.bn-envelope::after { bottom: 14px; right: 14px; border-left: 0; border-top: 0; border-radius: 0 0 10px 0; }
.bn-env-corner-tr, .bn-env-corner-bl {
    position: absolute; width: 44px; height: 44px; z-index: 5; pointer-events: none;
    border: 2px solid rgba(26,69,139,.35);
}
.bn-env-corner-tr { top: 14px; right: 14px; border-left: 0; border-bottom: 0; border-radius: 0 10px 0 0; }
.bn-env-corner-bl { bottom: 14px; left: 14px; border-right: 0; border-top: 0; border-radius: 0 0 0 10px; }

.bn-flap {
    position: absolute; left: 0; right: 0; top: 0; height: 42%;
    background: linear-gradient(180deg, #2a5fad 0%, var(--lg-navy) 55%, #123568 100%);
    clip-path: polygon(0 0, 100% 0, 50% 100%);
    z-index: 3;
    filter: drop-shadow(0 10px 16px rgba(18,53,104,.35));
}
.bn-flap-left, .bn-flap-right {
    position: absolute; top: 0; bottom: 52%; width: 52%; z-index: 2;
    opacity: .55; pointer-events: none;
}
.bn-flap-left {
    left: 0;
    background: linear-gradient(135deg, rgba(91,163,217,.45), transparent 70%);
    clip-path: polygon(0 0, 100% 0, 0 100%);
}
.bn-flap-right {
    right: 0;
    background: linear-gradient(225deg, rgba(91,163,217,.45), transparent 70%);
    clip-path: polygon(0 0, 100% 0, 100% 100%);
}
.bn-flap::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(115deg, transparent 40%, rgba(255,255,255,.2) 50%, transparent 60%);
    animation: bn-shine 3.2s ease-in-out infinite;
}
.bn-flap::after {
    content: '';
    position: absolute; left: 50%; top: 18%;
    width: 58%; height: 2px; transform: translateX(-50%);
    background: linear-gradient(90deg, transparent, rgba(253,185,19,.8), transparent);
    border-radius: 2px;
}
@keyframes bn-shine {
    0%, 100% { opacity: .35; transform: translateX(-18%); }
    50% { opacity: .9; transform: translateX(18%); }
}

.bn-wax {
    position: absolute; left: 50%; top: 38%;
    transform: translate(-50%, -50%);
    width: 84px; height: 84px; border-radius: 50%;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    z-index: 6;
    border: 3px solid #fff;
    box-shadow:
        0 0 0 3px var(--lg-navy),
        0 0 0 6px rgba(253,185,19,.55),
        0 12px 24px rgba(18,53,104,.4);
    overflow: hidden;
    padding: 5px;
}
.bn-wax img {
    width: 100%; height: 100%;
    object-fit: contain;
    display: block;
    border-radius: 50%;
}
.bn-wax::after {
    content: '';
    position: absolute; inset: 2px;
    border-radius: 50%;
    border: 1.5px dashed rgba(26,69,139,.35);
    pointer-events: none;
}

.bn-pocket {
    position: absolute; left: 5%; right: 5%; bottom: 6%; top: 50%;
    background: linear-gradient(180deg, rgba(255,255,255,.98), #f5f9ff 70%, #eef5fc);
    border-radius: 14px;
    border: 1px solid rgba(26,69,139,.18);
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.95),
        0 8px 18px rgba(26,69,139,.06);
    z-index: 2;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: .42rem; padding: .85rem .9rem .75rem;
    text-align: center;
}
.bn-env-ornament {
    display: flex; align-items: center; justify-content: center; gap: .35rem;
    width: 100%; margin-bottom: .05rem;
}
.bn-env-ornament span {
    height: 2px; flex: 1; max-width: 56px; border-radius: 2px;
}
.bn-env-ornament span:first-child {
    background: linear-gradient(90deg, transparent, var(--lg-red), var(--lg-gold));
}
.bn-env-ornament span:last-child {
    background: linear-gradient(90deg, var(--lg-green), var(--lg-sky), transparent);
}
.bn-env-ornament i { color: var(--lg-navy); font-size: .78rem; }

.bn-env-title {
    font-family: 'Nunito', system-ui, sans-serif;
    font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
    color: var(--lg-navy); font-size: .92rem;
    padding-left: .14em;
    line-height: 1.3;
}
.bn-env-nganh {
    display: inline-flex; align-items: center; justify-content: center;
    font-family: 'Nunito', system-ui, sans-serif;
    font-weight: 800; font-size: .86rem; color: #fff;
    background: linear-gradient(135deg, #2a5fad, var(--lg-navy) 55%, #123568);
    padding: .42rem 1.05rem;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,.35);
    box-shadow: 0 6px 14px rgba(18,53,104,.28);
    margin-top: .05rem;
    line-height: 1.3;
    text-align: center;
    max-width: 100%;
}
.bn-env-hint {
    font-family: 'Nunito', system-ui, sans-serif;
    color: #78909c; font-size: .84rem; font-weight: 700; margin-top: .2rem;
    display: inline-flex; align-items: center; gap: .35rem;
}
.bn-env-hint i { animation: bn-pulse 1.35s ease-in-out infinite; color: var(--lg-navy); }
@keyframes bn-pulse {
    0%,100% { opacity: .5; transform: translateY(0); }
    50% { opacity: 1; transform: translateY(2px); }
}
#bn-letter:hover .bn-envelope { transform: translateY(-5px) scale(1.02); }
#bn-letter:hover .bn-wax { transform: translate(-50%, -50%) scale(1.05); }
#bn-letter .bn-envelope,
#bn-letter .bn-wax { transition: transform .28s ease; }
#bn-letter:focus-visible .bn-envelope {
    outline: 3px solid rgba(91,163,217,.8);
    outline-offset: 5px;
}

/* ===== Họa tiết + pháo hoa ===== */
.bn-fireworks {
    position: absolute; inset: 0;
    pointer-events: none; z-index: 0;
    overflow: hidden;
    opacity: 0;
    transition: opacity .6s ease;
}
#bn-overlay.bn-opened .bn-fireworks,
#bn-overlay.bn-reveal .bn-fireworks { opacity: 1; }
.bn-fw {
    position: absolute;
    width: 6px; height: 6px;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    opacity: 0;
}
.bn-fw::before,
.bn-fw::after {
    content: '';
    position: absolute; left: 50%; top: 50%;
    width: 2px; height: 14px;
    margin: -7px 0 0 -1px;
    border-radius: 2px;
    background: currentColor;
    box-shadow:
        0 0 6px currentColor,
        18px 0 0 currentColor,
        -18px 0 0 currentColor,
        0 18px 0 currentColor,
        0 -18px 0 currentColor,
        13px 13px 0 currentColor,
        -13px 13px 0 currentColor,
        13px -13px 0 currentColor,
        -13px -13px 0 currentColor;
    animation: bn-burst 1.8s ease-out infinite;
}
.bn-fw::after {
    width: 10px; height: 10px; margin: -5px 0 0 -5px;
    background: transparent;
    border: 2px solid currentColor;
    box-shadow: 0 0 12px currentColor;
    animation: bn-ring 1.8s ease-out infinite;
}
@keyframes bn-burst {
    0% { transform: scale(.2); opacity: 0; }
    18% { opacity: 1; }
    100% { transform: scale(1.35); opacity: 0; }
}
@keyframes bn-ring {
    0% { transform: scale(.3); opacity: .9; }
    100% { transform: scale(2.2); opacity: 0; }
}
.bn-spark {
    position: absolute;
    width: 3px; height: 10px;
    border-radius: 2px;
    background: linear-gradient(180deg, #fff, currentColor);
    box-shadow: 0 0 8px currentColor;
    opacity: 0;
    animation: bn-spark-fall 2.4s linear infinite;
}
@keyframes bn-spark-fall {
    0% { transform: translateY(0) rotate(0deg); opacity: 0; }
    10% { opacity: 1; }
    100% { transform: translateY(120px) rotate(180deg); opacity: 0; }
}

.bn-motif {
    position: absolute; z-index: 1; pointer-events: none;
    width: 78px; height: 78px;
    opacity: .22;
    color: var(--bn-c2);
}
.bn-motif svg { width: 100%; height: 100%; display: block; }
.bn-motif-tl { top: 8px; left: 8px; }
.bn-motif-tr { top: 8px; right: 8px; transform: scaleX(-1); }
.bn-motif-bl { bottom: 8px; left: 8px; transform: scaleY(-1); }
.bn-motif-br { bottom: 8px; right: 8px; transform: scale(-1); }

.bn-vine-side {
    position: absolute; top: 18%; bottom: 18%; width: 18px;
    pointer-events: none; z-index: 1; opacity: .28;
}
.bn-vine-side span {
    position: absolute; left: 50%; width: 8px; height: 8px;
    margin-left: -4px; border-radius: 50% 0 50% 50%;
    background: var(--bn-c2);
    box-shadow: 0 28px 0 var(--bn-c1), 0 56px 0 var(--bn-c2), 0 84px 0 var(--bn-c1), 0 112px 0 var(--bn-c2);
}
.bn-vine-l { left: 4px; }
.bn-vine-r { right: 4px; }
.bn-vine-r span { border-radius: 0 50% 50% 50%; transform: scaleX(-1); }

.bn-frame-pattern {
    position: absolute; inset: 10px;
    border-radius: 1rem;
    pointer-events: none; z-index: 0;
    opacity: .14;
    background-image:
        radial-gradient(circle at 12px 12px, var(--bn-c2) 1.5px, transparent 2px),
        radial-gradient(circle at 28px 28px, var(--bn-c1) 1px, transparent 2px);
    background-size: 32px 32px;
}

/* ===== Thẻ nội dung — cuộn mở từ trên xuống ===== */
#bn-card {
    width: min(460px, 100%);
    position: absolute;
    z-index: 2;
    opacity: 0; pointer-events: none;
    transform: translateY(-24px);
    transform-origin: top center;
    transition: opacity .45s ease, transform .55s cubic-bezier(.16,1,.3,1);
}
#bn-overlay.bn-opening #bn-card,
#bn-overlay.bn-opened #bn-card {
    position: relative; opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}
.bn-frame {
    position: relative;
    background:
        linear-gradient(180deg, #fff 0%, var(--bn-bg) 48%, var(--bn-soft) 100%);
    border-radius: 1.55rem;
    padding: 1.15rem;
    box-shadow:
        0 34px 80px rgba(0,0,0,.5),
        0 0 0 1px color-mix(in srgb, var(--bn-c2) 45%, transparent),
        0 0 40px color-mix(in srgb, var(--bn-c1) 25%, transparent);
    overflow: hidden;
    /* Cuộn từ trên xuống */
    clip-path: inset(0 0 100% 0);
    max-height: 0;
    transition:
        clip-path 1.45s cubic-bezier(.22, 1, .36, 1),
        max-height 1.45s cubic-bezier(.22, 1, .36, 1);
}
#bn-overlay.bn-opened .bn-frame,
#bn-overlay.bn-reveal .bn-frame {
    clip-path: inset(0 0 0 0);
    max-height: 960px;
}
.bn-frame::after {
    content: '';
    position: absolute; left: 6%; right: 6%; bottom: 0;
    height: 18px;
    background: linear-gradient(180deg, transparent, rgba(0,0,0,.08));
    border-radius: 50%;
    pointer-events: none;
    opacity: 0;
    z-index: 5;
    transition: opacity .3s ease, bottom 1.45s cubic-bezier(.22, 1, .36, 1);
}
#bn-overlay.bn-opening .bn-frame::after,
#bn-overlay.bn-opened .bn-frame::after {
    opacity: 1;
}
#bn-overlay.bn-reveal .bn-frame::after {
    opacity: 0;
    bottom: -8px;
}
.bn-frame-inner {
    position: relative;
    border: 1.5px solid color-mix(in srgb, var(--bn-c2) 45%, transparent);
    border-radius: 1.15rem;
    padding: 1.55rem 1.2rem 1.3rem;
    text-align: center;
    background:
        radial-gradient(circle at 50% 0%, color-mix(in srgb, var(--bn-c1) 22%, transparent), transparent 48%),
        linear-gradient(180deg, rgba(255,255,255,.92), transparent 40%);
    overflow: hidden;
    z-index: 1;
}
.bn-frame-inner::before,
.bn-frame-inner::after {
    content: '';
    position: absolute; width: 54px; height: 54px;
    border: 2px solid color-mix(in srgb, var(--bn-c2) 55%, transparent);
    pointer-events: none; z-index: 2;
}
.bn-frame-inner::before { top: 10px; left: 10px; border-right: 0; border-bottom: 0; border-radius: 10px 0 0 0; }
.bn-frame-inner::after { bottom: 10px; right: 10px; border-left: 0; border-top: 0; border-radius: 0 0 10px 0; }
.bn-corner-tr, .bn-corner-bl {
    position: absolute; width: 54px; height: 54px;
    border: 2px solid color-mix(in srgb, var(--bn-c2) 55%, transparent);
    z-index: 2; pointer-events: none;
}
.bn-corner-tr { top: 10px; right: 10px; border-left: 0; border-bottom: 0; border-radius: 0 10px 0 0; }
.bn-corner-bl { bottom: 10px; left: 10px; border-right: 0; border-top: 0; border-radius: 0 0 0 10px; }

.bn-ribbon {
    position: relative; z-index: 3;
    display: flex; align-items: center; justify-content: center;
    margin: -.35rem auto 1rem;
    width: fit-content; max-width: 92%;
    background: linear-gradient(135deg, var(--bn-c1), var(--bn-c2) 45%, var(--bn-c3));
    color: #fff; font-family: 'Nunito', system-ui, sans-serif;
    font-weight: 800; letter-spacing: .28em; text-transform: uppercase;
    font-size: .78rem; padding: .55rem 1.35rem .55rem 1.63rem;
    text-align: center;
    clip-path: polygon(8% 0, 92% 0, 100% 50%, 92% 100%, 8% 100%, 0 50%);
    box-shadow: 0 10px 22px color-mix(in srgb, var(--bn-c3) 35%, transparent);
    text-shadow: 0 1px 0 rgba(0,0,0,.15);
    opacity: 0; transform: translateY(-18px);
    transition: opacity .55s ease, transform .7s cubic-bezier(.16,1,.3,1);
}
#bn-overlay.bn-reveal .bn-ribbon { opacity: 1; transform: translateY(0); transition-delay: .15s; }

.bn-avatar {
    width: 104px; height: 104px; margin: 0 auto .95rem;
    border-radius: 50%; overflow: hidden;
    border: 4px solid #fff;
    box-shadow:
        0 0 0 3px var(--bn-c2),
        0 0 0 7px color-mix(in srgb, var(--bn-c1) 40%, transparent),
        0 14px 30px color-mix(in srgb, var(--bn-c3) 30%, transparent);
    background: linear-gradient(145deg, var(--bn-c1), var(--bn-c3));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.55rem; position: relative; z-index: 1;
    opacity: 0; transform: translateY(-22px);
    transition: opacity .6s ease, transform .75s cubic-bezier(.16,1,.3,1);
}
#bn-overlay.bn-reveal .bn-avatar { opacity: 1; transform: translateY(0); transition-delay: .32s; }
.bn-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

.bn-ornament {
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    margin: 0 auto .7rem; position: relative; z-index: 1;
    opacity: 0; transform: translateY(-16px);
    transition: opacity .55s ease, transform .7s cubic-bezier(.16,1,.3,1);
    color: var(--bn-c2);
}
#bn-overlay.bn-reveal .bn-ornament { opacity: 1; transform: translateY(0); transition-delay: .45s; }
.bn-ornament span {
    height: 2px; width: 42px; border-radius: 2px;
    background: linear-gradient(90deg, transparent, var(--bn-c2), transparent);
}
.bn-ornament .bn-flora {
    font-size: .95rem;
    filter: drop-shadow(0 0 4px color-mix(in srgb, var(--bn-c1) 40%, transparent));
}

.bn-kicker {
    font-family: 'Nunito', system-ui, sans-serif;
    letter-spacing: .24em; text-transform: uppercase;
    font-weight: 800; font-size: .72rem; color: #c62828;
    margin: 0 auto .2rem; position: relative; z-index: 1;
    text-align: center;
    padding-left: .24em;
    opacity: 0; transform: translateY(-14px);
    transition: opacity .55s ease, transform .7s cubic-bezier(.16,1,.3,1);
}
#bn-overlay.bn-reveal .bn-kicker { opacity: 1; transform: translateY(0); transition-delay: .52s; }

.bn-line {
    font-family: 'Nunito', system-ui, sans-serif;
    position: relative; z-index: 1;
    display: block;
    text-align: center;
    opacity: 0; transform: translateY(-16px);
    transition: opacity .6s ease, transform .75s cubic-bezier(.16,1,.3,1);
}
#bn-overlay.bn-reveal .bn-line { opacity: 1; transform: translateY(0); }
.bn-title-person { font-size: 1.02rem; color: var(--bn-c2); font-weight: 800; margin: 0 auto; text-align: center; }
.bn-name {
    font-size: 1.42rem; font-weight: 800; color: var(--bn-ink);
    margin: .12rem auto .55rem; line-height: 1.25;
    text-align: center;
    text-shadow: 0 1px 0 rgba(255,255,255,.8);
}
.bn-nganh-badge {
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
    margin: 0 auto .95rem;
    padding: .4rem .95rem;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--bn-c1), var(--bn-c2));
    color: #fff; font-weight: 800; font-size: .86rem;
    box-shadow: 0 8px 18px color-mix(in srgb, var(--bn-c3) 28%, transparent);
    border: 1px solid rgba(255,255,255,.35);
}
.bn-nganh-badge i { font-size: .9rem; opacity: .95; }

.bn-meta {
    background: rgba(255,255,255,.78);
    border: 1px solid color-mix(in srgb, var(--bn-c2) 28%, #fff);
    border-radius: 1rem;
    padding: .9rem 1rem;
    margin: 0 auto 1rem;
    text-align: center;
    max-width: 360px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.9), 0 8px 18px rgba(0,0,0,.04);
}
.bn-meta-row {
    display: flex; gap: .55rem; align-items: baseline; justify-content: center;
    font-size: .94rem; color: #455a64; margin: .38rem 0;
}
.bn-meta-row strong {
    color: var(--bn-c3); font-weight: 800;
}
.bn-meta-row span { font-weight: 650; color: #37474f; }

.bn-bless {
    font-style: italic; color: #546e7a; font-size: .93rem;
    line-height: 1.55; margin: 0 auto 1.15rem; position: relative; z-index: 1;
    text-align: center;
    opacity: 0; transform: translateY(-14px);
    transition: opacity .6s ease, transform .75s cubic-bezier(.16,1,.3,1);
    padding: 0 .35rem;
    max-width: 360px;
}
#bn-overlay.bn-reveal .bn-bless { opacity: 1; transform: translateY(0); transition-delay: 1.15s; }

.bn-btn {
    display: inline-flex; align-items: center; justify-content: center;
    border: none; border-radius: 999px;
    background: linear-gradient(135deg, var(--bn-c1), var(--bn-c2) 50%, var(--bn-c3));
    color: #fff; font-weight: 800; padding: .72rem 1.7rem;
    box-shadow: 0 10px 24px color-mix(in srgb, var(--bn-c3) 32%, transparent);
    position: relative; z-index: 1;
    margin: 0 auto;
    opacity: 0; transform: translateY(-14px);
    transition: opacity .6s ease, transform .75s cubic-bezier(.16,1,.3,1), filter .15s ease;
}
#bn-overlay.bn-reveal .bn-btn { opacity: 1; transform: translateY(0); transition-delay: 1.32s; }
.bn-btn:hover { filter: brightness(1.07); color: #fff; }

.bn-delay-1 { transition-delay: .62s !important; }
.bn-delay-2 { transition-delay: .76s !important; }
.bn-delay-3 { transition-delay: .9s !important; }
.bn-delay-4 { transition-delay: 1.05s !important; }

@media (max-width: 480px) {
    .bn-frame { padding: .85rem; border-radius: 1.2rem; }
    .bn-frame-inner { padding: 1.25rem .9rem 1.1rem; }
    .bn-name { font-size: 1.18rem; }
    .bn-avatar { width: 86px; height: 86px; }
    .bn-ribbon { letter-spacing: .18em; font-size: .7rem; padding: .48rem 1.2rem; }
}
</style>

<div id="bn-overlay" role="dialog" aria-modal="true" aria-labelledby="bn-heading" data-nganh="<?php echo htmlspecialchars($th['slug']); ?>">
    <div class="bn-fireworks" id="bn-fireworks" aria-hidden="true"></div>

    <button type="button" id="bn-letter" aria-label="Mở thư bổ nhiệm lớp">
        <div class="bn-envelope">
            <div class="bn-env-rainbow"></div>
            <div class="bn-env-corner-tr"></div>
            <div class="bn-env-corner-bl"></div>
            <div class="bn-flap-left"></div>
            <div class="bn-flap-right"></div>
            <div class="bn-flap"></div>
            <div class="bn-wax">
                <img src="<?php echo htmlspecialchars($bn_logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Đoàn TNTT Đaminh Savio Lập Trí">
            </div>
            <div class="bn-pocket">
                <div class="bn-env-ornament"><span></span><i class="fa-solid fa-cross"></i><span></span></div>
                <div class="bn-env-title">Thư bổ nhiệm lớp</div>
                <div class="bn-env-nganh">Đoàn TNTT Đaminh Savio Lập Trí</div>
                <div class="bn-env-hint"><i class="fa-solid fa-hand-pointer"></i>Nhấn để mở thư</div>
            </div>
        </div>
    </button>

    <div id="bn-card" hidden>
        <div class="bn-frame">
            <div class="bn-frame-pattern"></div>
            <div class="bn-motif bn-motif-tl" aria-hidden="true">
                <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 72 C8 40 20 20 48 12 C36 28 34 44 40 58 C28 52 16 58 8 72Z" fill="currentColor"/>
                    <path d="M48 12 C58 8 70 14 72 28 C60 24 52 30 48 12Z" fill="currentColor" opacity=".7"/>
                    <circle cx="52" cy="22" r="4" fill="currentColor"/>
                    <path d="M20 30 C28 18 42 14 52 22" stroke="currentColor" stroke-width="2" opacity=".55"/>
                </svg>
            </div>
            <div class="bn-motif bn-motif-tr" aria-hidden="true">
                <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 72 C8 40 20 20 48 12 C36 28 34 44 40 58 C28 52 16 58 8 72Z" fill="currentColor"/>
                    <path d="M48 12 C58 8 70 14 72 28 C60 24 52 30 48 12Z" fill="currentColor" opacity=".7"/>
                    <circle cx="52" cy="22" r="4" fill="currentColor"/>
                </svg>
            </div>
            <div class="bn-motif bn-motif-bl" aria-hidden="true">
                <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 72 C8 40 20 20 48 12 C36 28 34 44 40 58 C28 52 16 58 8 72Z" fill="currentColor"/>
                    <circle cx="52" cy="22" r="4" fill="currentColor"/>
                </svg>
            </div>
            <div class="bn-motif bn-motif-br" aria-hidden="true">
                <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 72 C8 40 20 20 48 12 C36 28 34 44 40 58 C28 52 16 58 8 72Z" fill="currentColor"/>
                    <circle cx="52" cy="22" r="4" fill="currentColor"/>
                </svg>
            </div>
            <div class="bn-vine-side bn-vine-l" aria-hidden="true"><span></span></div>
            <div class="bn-vine-side bn-vine-r" aria-hidden="true"><span></span></div>

            <div class="bn-frame-inner">
                <div class="bn-corner-tr"></div>
                <div class="bn-corner-bl"></div>

                <div class="bn-ribbon">Bổ nhiệm lớp</div>
                <div class="bn-avatar">
                    <?php if ($bn['avatar']): ?>
                        <img src="<?php echo htmlspecialchars($bn['avatar']); ?>" alt="">
                    <?php else: ?>
                        <?php echo htmlspecialchars($bn_initials); ?>
                    <?php endif; ?>
                </div>

                <div class="bn-ornament">
                    <span></span>
                    <i class="fa-solid fa-fan bn-flora"></i>
                    <i class="fa-solid fa-certificate bn-flora"></i>
                    <i class="fa-solid fa-fan bn-flora"></i>
                    <span></span>
                </div>
                <div class="bn-kicker" id="bn-heading">Quyết định bổ nhiệm</div>
                <p class="bn-line bn-title-person bn-delay-1"><?php echo htmlspecialchars($bn['title']); ?></p>
                <h2 class="bn-line bn-name bn-delay-2"><?php echo htmlspecialchars($bn['full_name']); ?></h2>
                <div class="bn-line bn-delay-3">
                    <span class="bn-nganh-badge">
                        <i class="fa-solid <?php echo htmlspecialchars($th['icon']); ?>"></i>
                        <?php echo htmlspecialchars($th['label']); ?>
                    </span>
                </div>

                <div class="bn-line bn-meta bn-delay-4">
                    <div class="bn-meta-row"><strong>Vai trò</strong> <span><?php echo htmlspecialchars($bn['vai_tro']); ?></span></div>
                    <div class="bn-meta-row"><strong>Lớp</strong> <span><?php echo htmlspecialchars($bn['ten_lop']); ?></span></div>
                    <div class="bn-meta-row"><strong>Năm học</strong> <span><?php echo htmlspecialchars($bn['nam_hoc']); ?></span></div>
                </div>

                <p class="bn-bless"><?php echo htmlspecialchars($bn_blessing); ?></p>
                <button type="button" class="bn-btn" id="bn-dismiss">
                    <i class="fa-solid fa-check me-1"></i> Đã nhận bổ nhiệm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('bn-overlay');
    var letter = document.getElementById('bn-letter');
    var card = document.getElementById('bn-card');
    var fwBox = document.getElementById('bn-fireworks');
    if (!overlay || !letter || !card) return;

    document.body.style.overflow = 'hidden';
    var opened = false;
    var fwColors = ['#ff5252', '#ffd54f', '#69f0ae', '#40c4ff', '#ea80fc', '#ffab40', '#ffffff', '#fdb913', '#e31e24'];

    function spawnFireworks() {
        if (!fwBox) return;
        fwBox.innerHTML = '';
        var spots = [
            [12, 18], [88, 16], [18, 42], [82, 38], [10, 68], [90, 72],
            [28, 12], [72, 10], [8, 30], [92, 28], [22, 78], [78, 82],
            [50, 8], [6, 55], [94, 58], [35, 85], [65, 86]
        ];
        spots.forEach(function (pos, i) {
            var el = document.createElement('div');
            el.className = 'bn-fw';
            el.style.left = pos[0] + '%';
            el.style.top = pos[1] + '%';
            el.style.color = fwColors[i % fwColors.length];
            fwBox.appendChild(el);
            setTimeout(function () { el.classList.add('bn-fw-on'); }, i * 160);
        });
        for (var s = 0; s < 32; s++) {
            var sp = document.createElement('div');
            sp.className = 'bn-spark';
            sp.style.left = (6 + Math.random() * 88) + '%';
            sp.style.top = (4 + Math.random() * 45) + '%';
            sp.style.color = fwColors[s % fwColors.length];
            sp.style.animationDelay = (Math.random() * 2.2) + 's';
            sp.style.animationDuration = (1.7 + Math.random() * 1.5) + 's';
            fwBox.appendChild(sp);
        }
    }

    if (!document.getElementById('bn-fw-style')) {
        var styleTag = document.createElement('style');
        styleTag.id = 'bn-fw-style';
        styleTag.textContent =
            '.bn-fw::before,.bn-fw::after{animation-play-state:paused;opacity:0;}' +
            '.bn-fw-on::before{animation:bn-burst 1.9s ease-out infinite;animation-play-state:running;opacity:1;}' +
            '.bn-fw-on::after{animation:bn-ring 1.9s ease-out infinite;animation-play-state:running;opacity:1;}';
        document.head.appendChild(styleTag);
    }

    requestAnimationFrame(function () { overlay.classList.add('bn-show'); });

    function openLetter() {
        if (opened) return;
        opened = true;
        card.hidden = false;
        overlay.classList.add('bn-opening');
        spawnFireworks();
        // Bắt đầu cuộn mở ngay, rồi lần lượt hiện nội dung từ trên xuống
        requestAnimationFrame(function () {
            overlay.classList.add('bn-opened');
            setTimeout(function () {
                overlay.classList.add('bn-reveal');
                if (letter.parentNode) letter.parentNode.removeChild(letter);
            }, 380);
        });
    }

    letter.addEventListener('click', openLetter);
    letter.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLetter(); }
    });

    function closeBn() {
        overlay.classList.remove('bn-reveal', 'bn-opened', 'bn-opening', 'bn-show');
        overlay.style.transition = 'opacity .45s ease';
        overlay.style.opacity = '0';
        document.body.style.overflow = '';
        fetch(<?php echo json_encode($bn_dismiss_url, JSON_UNESCAPED_SLASHES); ?>, { credentials: 'same-origin', method: 'POST' }).catch(function () {});
        setTimeout(function () {
            if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 480);
    }

    var btn = document.getElementById('bn-dismiss');
    if (btn) btn.addEventListener('click', closeBn);
})();
</script>