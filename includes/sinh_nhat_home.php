<?php
/**
 * Khối sinh nhật trên trang chủ — include sau khi đã có $conn, session, sinh_nhat.php.
 * Biến dùng: $sn_birthdays, $sn_wishes, $sn_month_label, $sn_week, $logged_in, $can_moderate_wishes
 */
if (!isset($sn_birthdays) || !is_array($sn_birthdays)) {
    $sn_birthdays = array();
}
if (!isset($sn_wishes) || !is_array($sn_wishes)) {
    $sn_wishes = array();
}
if (!isset($sn_month_label)) {
    $sn_month_label = function_exists('sn_month_label') ? sn_month_label() : ('Tháng ' . date('n'));
}
if (!isset($sn_week) || !is_array($sn_week)) {
    $sn_week = function_exists('sn_week_bounds') ? sn_week_bounds() : array('start' => date('Y-m-d'), 'end' => date('Y-m-d'));
}
if (!isset($logged_in)) {
    $logged_in = !empty($_SESSION['user_id']);
}
if (!isset($can_moderate_wishes)) {
    $can_moderate_wishes = false;
}

function sn_home_h($v) {
    return htmlspecialchars((string) (isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
}
?>
<section class="sn-home-section" id="sinh-nhat">
    <div class="sn-fw" aria-hidden="true">
        <canvas id="snFireworks"></canvas>
    </div>
    <div class="container" style="position:relative;z-index:2">
        <?php if (isset($_SESSION['sn_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3">
                <?php echo sn_home_h($_SESSION['sn_success']); unset($_SESSION['sn_success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['sn_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3">
                <?php echo sn_home_h($_SESSION['sn_error']); unset($_SESSION['sn_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="sn-formal-card">
            <h2 class="sn-formal-title">
                <span class="sn-formal-org">Đoàn TNTT Đaminh Savio Lập Trí</span>
                <span class="sn-formal-sep" aria-hidden="true">·</span>
                <span class="sn-formal-main"><span class="sn-cake-ico" aria-hidden="true"><svg viewBox="0 0 64 64" width="1.15em" height="1.15em" focusable="false"><ellipse cx="32" cy="54" rx="26" ry="5" fill="#f48fb1"/><path d="M10 36h44v16c0 2-10 4-22 4s-22-2-22-4V36z" fill="#ffcc80"/><path d="M10 36c0 2 10 4 22 4s22-2 22-4c0-2-10-4-22-4s-22 2-22 4z" fill="#ffe0b2"/><path d="M14 28h36v10c0 1.5-8 3-18 3s-18-1.5-18-3V28z" fill="#f8bbd0"/><path d="M14 28c0 1.5 8 3 18 3s18-1.5 18-3-8-3-18-3-18 1.5-18 3z" fill="#fce4ec"/><path d="M18 22h28v8c0 1.2-6 2.4-14 2.4S18 31.2 18 30v-8z" fill="#ef5350"/><path d="M18 22c0 1.2 6 2.4 14 2.4s14-1.2 14-2.4-6-2.4-14-2.4S18 20.8 18 22z" fill="#e57373"/><rect x="22" y="12" width="3" height="10" rx="1.2" fill="#fff59d"/><rect x="30.5" y="10" width="3" height="12" rx="1.2" fill="#fff59d"/><rect x="39" y="12" width="3" height="10" rx="1.2" fill="#fff59d"/><circle cx="23.5" cy="11" r="2.2" fill="#ff7043"/><circle cx="32" cy="9" r="2.4" fill="#42a5f5"/><circle cx="40.5" cy="11" r="2.2" fill="#66bb6a"/><circle cx="20" cy="40" r="2" fill="#ec407a"/><circle cx="32" cy="42" r="2" fill="#ab47bc"/><circle cx="44" cy="40" r="2" fill="#26c6da"/></svg></span>Chúc Mừng Sinh Nhật Quý Trưởng sinh</span>
                <span class="sn-formal-month-fx"><?php echo sn_home_h($sn_month_label); ?></span>
            </h2>
            <div class="sn-formal-divider" aria-hidden="true"></div>
            <div class="sn-formal-message">
                <p>
                    Nhân dịp sinh nhật, Đoàn TNTT Đaminh Savio Lập Trí xin kính gửi đến Quý Trưởng những lời chúc mừng chân thành và tốt đẹp nhất.
                    Nguyện xin Chúa Giêsu Thánh Thể và Mẹ Maria luôn đồng hành, gìn giữ và ban cho Quý Trưởng bình an, sức khỏe, niềm vui và tràn đầy hồng ân, để Quý Trưởng luôn giữ được lòng nhiệt thành, tình yêu thương và tinh thần hy sinh, tiếp tục quảng đại phục vụ Chúa và các em thiếu nhi.
                </p>
                <p class="sn-formal-message-end">
                    💙 Kính chúc Quý Trưởng tuổi mới luôn hạnh phúc, bình an và vững bước trên hành trình phục vụ. 🙏
                </p>
            </div>

            <?php if (empty($sn_birthdays)): ?>
                <div class="sn-home-empty sn-home-empty-inner">
                    <div class="sn-home-empty-ico">🎉</div>
                    <p>Tháng này chưa có quý trưởng trong danh sách sinh nhật.</p>
                </div>
            <?php else: ?>
                <div class="sn-home-grid">
                    <?php foreach ($sn_birthdays as $b): ?>
                        <?php
                        $in_week = function_exists('sn_md_in_week')
                            ? sn_md_in_week($b['ngay_sinh'], $sn_week['start'], $sn_week['end'])
                            : false;
                        $avatar = function_exists('sn_avatar_url') ? sn_avatar_url(isset($b['avatar']) ? $b['avatar'] : '') : '';
                        $initials = function_exists('sn_initials')
                            ? sn_initials(isset($b['ho_ten']) ? $b['ho_ten'] : '', isset($b['ten_thanh']) ? $b['ten_thanh'] : '')
                            : '?';
                        $role_line = '';
                        if (!empty($b['lop_phu_trach'])) {
                            $role_line = $b['lop_phu_trach'];
                        } elseif (!empty($b['loai_huynh'])) {
                            $role_line = $b['loai_huynh'];
                        } else {
                            $role_line = 'Huynh Trưởng';
                        }
                        $disp_name = trim((isset($b['ten_thanh']) && $b['ten_thanh'] !== '' ? $b['ten_thanh'] . ' ' : '') . (isset($b['ho_ten']) ? $b['ho_ten'] : ''));
                        ?>
                        <article class="sn-person-card <?php echo $in_week ? 'is-week' : ''; ?>">
                            <div class="sn-avatar-wrap">
                                <div class="sn-avatar">
                                    <?php if ($avatar !== ''): ?>
                                        <img src="<?php echo sn_home_h($avatar); ?>" alt="<?php echo sn_home_h($disp_name); ?>">
                                    <?php else: ?>
                                        <span><?php echo sn_home_h($initials); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="sn-avatar-badge" title="Sinh nhật"><i class="fas fa-birthday-cake"></i></span>
                            </div>
                            <h3 class="sn-person-name"><?php echo sn_home_h($disp_name); ?></h3>
                            <p class="sn-person-role"><?php echo sn_home_h($role_line); ?></p>
                            <?php if ($in_week): ?>
                                <span class="sn-pill sn-pill-week"><i class="fas fa-star me-1"></i>Trong tuần này</span>
                            <?php else: ?>
                                <span class="sn-pill">Sinh nhật — <?php echo sn_home_h($sn_month_label); ?></span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="sn-wish-panel">
            <div class="sn-wish-head">
                <h3><i class="fas fa-heart me-2"></i>Gửi Lời Chúc tới Quý Trưởng <?php echo sn_home_h($sn_month_label); ?></h3>
            </div>

            <?php if ($logged_in): ?>
                <form method="POST" action="index.php#sinh-nhat" class="sn-wish-form">
                    <input type="hidden" name="sn_action" value="add_wish">
                    <textarea name="content" id="sn_wish_content" rows="2" maxlength="1000" required
                        data-ph-desktop="VD: Chúc mừng sinh nhật Quý Trưởng! Nguyện xin Chúa ban bình an, sức khỏe và tràn đầy hồng ân phục vụ..."
                        data-ph-mobile="VD: Chúc mừng sinh nhật Quý Trưởng..."
                        placeholder="VD: Chúc mừng sinh nhật Quý Trưởng..."></textarea>
                    <div class="sn-wish-actions">
                        <button type="submit" class="sn-btn-send"><i class="fas fa-paper-plane me-1"></i>Gửi lời chúc</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="sn-wish-login">
                    <a href="<?php echo sn_home_h(function_exists('login_page_url') ? login_page_url('index.php#sinh-nhat') : 'login.php'); ?>" class="sn-btn-send">
                        <i class="fas fa-sign-in-alt me-1"></i>Đăng nhập để gửi lời chúc
                    </a>
                </div>
            <?php endif; ?>

            <div class="sn-wish-list">
                <?php if (empty($sn_wishes)): ?>
                    <p class="sn-wish-empty">Chưa có lời chúc nào. Hãy là người đầu tiên!</p>
                <?php else: ?>
                    <?php foreach ($sn_wishes as $w): ?>
                        <?php
                        $w_avatar = isset($w['avatar_url']) ? $w['avatar_url'] : '';
                        $w_init = isset($w['initials']) ? $w['initials'] : '?';
                        ?>
                        <div class="sn-wish-item">
                            <div class="sn-wish-avatar">
                                <?php if ($w_avatar !== ''): ?>
                                    <img src="<?php echo sn_home_h($w_avatar); ?>" alt="">
                                <?php else: ?>
                                    <span><?php echo sn_home_h($w_init); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="sn-wish-body">
                                <div class="sn-wish-meta">
                                    <strong><?php echo sn_home_h($w['author_name']); ?></strong>
                                    <span><?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?></span>
                                </div>
                                <div class="sn-wish-text"><?php echo nl2br(sn_home_h($w['content'])); ?></div>
                                <?php if ($can_moderate_wishes): ?>
                                    <div class="sn-wish-mod">
                                        <button type="button" class="btn btn-sm btn-outline-primary sn-edit-wish"
                                            data-id="<?php echo (int)$w['id']; ?>"
                                            data-content="<?php echo sn_home_h($w['content']); ?>"
                                            data-bs-toggle="modal" data-bs-target="#snEditWishModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" action="index.php#sinh-nhat" class="d-inline" onsubmit="return confirm('Xóa lời chúc này?');">
                                            <input type="hidden" name="sn_action" value="delete_wish">
                                            <input type="hidden" name="wish_id" value="<?php echo (int)$w['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($can_moderate_wishes)): ?>
<div class="modal fade" id="snEditWishModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:1.1rem;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#c62828,#ef5350);color:#fff;border:none">
                <h5 class="modal-title fw-bold">Sửa lời chúc</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="index.php#sinh-nhat">
                    <input type="hidden" name="sn_action" value="edit_wish">
                    <input type="hidden" name="wish_id" id="sn_edit_wish_id">
                    <textarea name="content" id="sn_edit_wish_content" class="form-control" rows="4" maxlength="1000" required></textarea>
                    <button type="submit" class="sn-btn-send w-100 mt-3">Lưu</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.sn-edit-wish').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('sn_edit_wish_id').value = btn.getAttribute('data-id');
        document.getElementById('sn_edit_wish_content').value = btn.getAttribute('data-content') || '';
    });
});
</script>
<?php endif; ?>

<style>
.sn-home-section { position: relative; overflow: hidden; }
.sn-fw {
    position: absolute !important;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 1;
}
.sn-fw canvas {
    position: absolute;
    inset: 0;
    width: 100% !important;
    height: 100% !important;
    display: block;
    pointer-events: none;
}
.sn-wish-item {
    display: flex;
    gap: .75rem;
    align-items: flex-start;
}
.sn-wish-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(145deg, #ef9a9a, #e57373);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .85rem; overflow: hidden; flex-shrink: 0;
}
.sn-wish-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sn-wish-body { flex: 1; min-width: 0; }
</style>
<script>
(function() {
    var canvas = document.getElementById('snFireworks');
    var section = document.getElementById('sinh-nhat');
    if (!canvas || !section) return;

    var ctx = canvas.getContext('2d');
    var rockets = [];
    var particles = [];
    var lastLaunch = 0;
    var running = false;
    var colors = ['#e53935', '#ff7043', '#fdd835', '#66bb6a', '#42a5f5', '#ab47bc', '#ec407a', '#ffa726'];

    function resize() {
        var rect = section.getBoundingClientRect();
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.max(1, Math.floor(rect.width * dpr));
        canvas.height = Math.max(1, Math.floor(rect.height * dpr));
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function launch() {
        var w = section.clientWidth;
        var h = section.clientHeight;
        rockets.push({
            x: w * (0.15 + Math.random() * 0.7),
            y: h,
            tx: w * (0.2 + Math.random() * 0.6),
            ty: h * (0.12 + Math.random() * 0.28),
            color: colors[Math.floor(Math.random() * colors.length)],
            speed: 4.2 + Math.random() * 2.2
        });
    }

    function explode(x, y, color) {
        var n = 28 + Math.floor(Math.random() * 18);
        for (var i = 0; i < n; i++) {
            var a = (Math.PI * 2 * i) / n + Math.random() * 0.3;
            var sp = 1.2 + Math.random() * 3.8;
            particles.push({
                x: x,
                y: y,
                vx: Math.cos(a) * sp,
                vy: Math.sin(a) * sp,
                life: 45 + Math.floor(Math.random() * 25),
                max: 70,
                color: Math.random() > 0.25 ? color : colors[Math.floor(Math.random() * colors.length)],
                size: 1.5 + Math.random() * 2.2
            });
        }
    }

    function tick(ts) {
        if (!running) return;
        var w = section.clientWidth;
        var h = section.clientHeight;
        ctx.clearRect(0, 0, w, h);

        if (ts - lastLaunch > 900 + Math.random() * 700) {
            launch();
            if (Math.random() > 0.55) launch();
            lastLaunch = ts;
        }

        for (var i = rockets.length - 1; i >= 0; i--) {
            var r = rockets[i];
            var dx = r.tx - r.x;
            var dy = r.ty - r.y;
            var dist = Math.sqrt(dx * dx + dy * dy) || 1;
            r.x += (dx / dist) * r.speed;
            r.y += (dy / dist) * r.speed;
            ctx.beginPath();
            ctx.fillStyle = r.color;
            ctx.arc(r.x, r.y, 2.2, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.strokeStyle = r.color;
            ctx.globalAlpha = 0.35;
            ctx.moveTo(r.x, r.y);
            ctx.lineTo(r.x - (dx / dist) * 10, r.y - (dy / dist) * 10);
            ctx.stroke();
            ctx.globalAlpha = 1;
            if (dist < 8) {
                explode(r.x, r.y, r.color);
                rockets.splice(i, 1);
            }
        }

        for (var j = particles.length - 1; j >= 0; j--) {
            var p = particles[j];
            p.vy += 0.045;
            p.vx *= 0.99;
            p.x += p.vx;
            p.y += p.vy;
            p.life--;
            var alpha = Math.max(0, p.life / p.max);
            ctx.beginPath();
            ctx.fillStyle = p.color;
            ctx.globalAlpha = alpha;
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = 1;
            if (p.life <= 0) particles.splice(j, 1);
        }

        requestAnimationFrame(tick);
    }

    function start() {
        if (running) return;
        running = true;
        resize();
        lastLaunch = 0;
        requestAnimationFrame(tick);
    }

    function stop() {
        running = false;
        rockets = [];
        particles = [];
        ctx.clearRect(0, 0, section.clientWidth, section.clientHeight);
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries) {
            if (entries[0].isIntersecting) start();
            else stop();
        }, { threshold: 0.15 });
        io.observe(section);
    } else {
        start();
    }

    window.addEventListener('resize', function() {
        if (running) resize();
    });
})();

(function() {
    var ta = document.getElementById('sn_wish_content');
    if (!ta) return;
    var desk = ta.getAttribute('data-ph-desktop') || '';
    var mob = ta.getAttribute('data-ph-mobile') || '';
    function applyPh() {
        ta.placeholder = (window.matchMedia('(max-width: 768px)').matches ? mob : desk) || ta.placeholder;
    }
    applyPh();
    window.addEventListener('resize', applyPh);
})();
</script>
