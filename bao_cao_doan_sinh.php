<?php
session_start();
require_once __DIR__ . '/login_redirect.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/bao_cao.php';

if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}

bc_ensure_schema($conn);

$uid = (int) $_SESSION['user_id'];
$role = login_current_role();
$is_admin = login_is_admin($role);
$is_hdt = login_is_hdt_manager($role);
$is_bph = login_is_ban_phu_huynh($role);

$leader_classes = bc_leader_classes($conn, $uid);
$report_classes = $is_hdt ? bc_all_classes($conn) : $leader_classes;
$can_report = !empty($report_classes) && !$is_bph;
$can_approve = $is_hdt;
$can_bph = $is_bph || $is_admin;
$csrf = bc_csrf_token();

$tabs = array();
if ($can_report) {
    $tabs['lop'] = array('Báo cáo lớp', 'fa-list-check');
    $tabs['da_gui'] = array('Yêu cầu đã gửi', 'fa-paper-plane');
}
if ($can_approve) {
    $tabs['duyet'] = array('Duyệt yêu cầu', 'fa-user-shield');
}
if ($can_bph) {
    $tabs['bph'] = array('Ban Phụ Huynh', 'fa-people-roof');
}

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tabs) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $redirect_tab = isset($_POST['tab']) ? $_POST['tab'] : '';
    $redirect_class = isset($_POST['class']) ? (int) $_POST['class'] : 0;

    if (!bc_csrf_ok()) {
        $_SESSION['error'] = 'Phiên làm việc đã hết hạn, vui lòng tải lại trang rồi thử lại.';
    } elseif ($action === 'create_report' && $can_report) {
        $sid = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        $reason_len = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        $st = $conn->query("SELECT id, class_id, status, ho_ten, ten_thanh FROM students WHERE id = $sid LIMIT 1");
        $student = ($st && ($row = $st->fetch_assoc())) ? $row : null;

        if (!$student || $student['status'] !== 'active' || !isset($report_classes[(int) $student['class_id']])) {
            $_SESSION['error'] = 'Không tìm thấy đoàn sinh trong lớp bạn phụ trách.';
        } elseif ($reason_len < 10) {
            $_SESSION['error'] = 'Vui lòng ghi lý do đầy đủ, rõ ràng (ít nhất 10 ký tự).';
        } else {
            $open = bc_open_reports_by_student($conn, array($sid));
            if (isset($open[$sid])) {
                $_SESSION['error'] = 'Đoàn sinh này đã có yêu cầu đang được xử lý.';
            } else {
                $cid = (int) $student['class_id'];
                $reason_sql = $conn->real_escape_string($reason);
                $name_sql = $conn->real_escape_string(bc_user_display_name($conn, $uid));
                $ok = $conn->query("INSERT INTO student_reports (student_id, class_id, reason, status, created_by, created_by_name, created_at)
                                    VALUES ($sid, $cid, '$reason_sql', 'pending', $uid, '$name_sql', NOW())");
                $sname = trim(($student['ten_thanh'] ? $student['ten_thanh'] . ' ' : '') . $student['ho_ten']);
                $_SESSION[$ok ? 'success' : 'error'] = $ok
                    ? "Đã gửi yêu cầu cho $sname. Yêu cầu sẽ chuyển tới Ban Phụ Huynh sau khi BĐH xác nhận."
                    : 'Lỗi khi gửi yêu cầu: ' . $conn->error;
                $redirect_tab = 'da_gui';
            }
        }
    } elseif ($action === 'hdt_decide' && $can_approve) {
        $rid = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        $decision = isset($_POST['decision']) ? $_POST['decision'] : '';
        $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        if ($decision !== 'approve' && $decision !== 'reject') {
            $_SESSION['error'] = 'Thao tác không hợp lệ.';
        } elseif ($decision === 'reject' && $note === '') {
            $_SESSION['error'] = 'Vui lòng ghi lý do không duyệt.';
        } else {
            $new_status = $decision === 'approve' ? 'approved' : 'rejected';
            $note_sql = $note === '' ? 'NULL' : "'" . $conn->real_escape_string($note) . "'";
            $name_sql = $conn->real_escape_string(bc_user_display_name($conn, $uid));
            $conn->query("UPDATE student_reports
                          SET status = '$new_status', hdt_by = $uid, hdt_by_name = '$name_sql', hdt_note = $note_sql, hdt_at = NOW()
                          WHERE id = $rid AND status = 'pending'");
            if ($conn->affected_rows > 0) {
                $_SESSION['success'] = $decision === 'approve'
                    ? 'Đã xác nhận — yêu cầu đã chuyển tới Ban Phụ Huynh.'
                    : 'Đã từ chối yêu cầu.';
            } else {
                $_SESSION['error'] = 'Yêu cầu không còn ở trạng thái chờ duyệt.';
            }
        }
        $redirect_tab = 'duyet';
    } elseif ($action === 'bph_met' && $can_bph) {
        $rid = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
        $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        if ($note === '') {
            $_SESSION['error'] = 'Vui lòng ghi nội dung đã trao đổi với phụ huynh.';
        } else {
            $note_sql = $conn->real_escape_string($note);
            $name_sql = $conn->real_escape_string(bc_user_display_name($conn, $uid));
            $conn->query("UPDATE student_reports
                          SET status = 'met', bph_by = $uid, bph_by_name = '$name_sql', bph_note = '$note_sql', bph_at = NOW()
                          WHERE id = $rid AND status = 'approved'");
            $_SESSION[$conn->affected_rows > 0 ? 'success' : 'error'] = $conn->affected_rows > 0
                ? 'Đã xác nhận gặp phụ huynh.'
                : 'Yêu cầu không còn ở trạng thái chờ gặp phụ huynh.';
        }
        $redirect_tab = 'bph';
    }

    $qs = array();
    if (isset($tabs[$redirect_tab])) {
        $qs['tab'] = $redirect_tab;
    }
    if ($redirect_class > 0) {
        $qs['class'] = $redirect_class;
    }
    header('Location: bao_cao_doan_sinh.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : '';
if (!isset($tabs[$tab])) {
    $tab_keys = array_keys($tabs);
    $tab = $tab_keys ? $tab_keys[0] : '';
}

$pending_count = 0;
$approved_count = 0;
if ($can_approve || $can_bph) {
    $cres = $conn->query("SELECT status, COUNT(*) AS c FROM student_reports WHERE status IN ('pending','approved') GROUP BY status");
    if ($cres) {
        while ($row = $cres->fetch_assoc()) {
            if ($row['status'] === 'pending') $pending_count = (int) $row['c'];
            if ($row['status'] === 'approved') $approved_count = (int) $row['c'];
        }
    }
}

// ── Dữ liệu theo tab ────────────────────────────────────────────────────────
$selected_class = 0;
$students = array();
$stats = array();
$open_reports = array();
if ($tab === 'lop') {
    $selected_class = isset($_GET['class']) ? (int) $_GET['class'] : 0;
    if (!isset($report_classes[$selected_class])) {
        $class_keys = array_keys($report_classes);
        $selected_class = $class_keys ? (int) $class_keys[0] : 0;
    }
    $res = $conn->query("SELECT id, ho_ten, ten_thanh, ngay_sinh FROM students
                         WHERE class_id = $selected_class AND status = 'active' ORDER BY ho_ten");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $students[] = $row;
        }
    }
    $ids = array();
    foreach ($students as $s) {
        $ids[] = (int) $s['id'];
    }
    $stats = bc_student_stats($conn, $ids);
    $open_reports = bc_open_reports_by_student($conn, $ids);
}

$report_groups = array();
if ($tab === 'da_gui') {
    if ($is_hdt) {
        $where = "r.created_by = $uid";
    } else {
        $leader_ids = $leader_classes ? implode(',', array_map('intval', array_keys($leader_classes))) : '0';
        $where = "(r.created_by = $uid OR r.class_id IN ($leader_ids))";
    }
    $report_groups[] = array('', bc_fetch_reports($conn, $where), 'ht');
} elseif ($tab === 'duyet') {
    $report_groups[] = array('Chờ xác nhận', bc_fetch_reports($conn, "r.status = 'pending'"), 'hdt');
    $report_groups[] = array('Đã xử lý gần đây', bc_fetch_reports($conn, "r.status <> 'pending'", 30), 'view');
} elseif ($tab === 'bph') {
    $report_groups[] = array('Chờ gặp phụ huynh', bc_fetch_reports($conn, "r.status = 'approved'"), 'bph');
    $report_groups[] = array('Đã gặp phụ huynh', bc_fetch_reports($conn, "r.status = 'met'", 50), 'view');
}

function bc_render_report_card($conn, $r, $mode) {
    $sid = (int) $r['student_id'];
    $s = bc_student_full($conn, $sid);
    $all_stats = bc_student_stats($conn, array($sid));
    $st = isset($all_stats[$sid]) ? $all_stats[$sid] : bc_empty_stats();
    $hist = bc_absence_history($conn, $sid);
    $info = bc_status_info($r['status']);
    $name = trim((!empty($r['ten_thanh']) ? $r['ten_thanh'] . ' ' : '') . $r['ho_ten']);
    $rid = (int) $r['id'];
    ?>
    <div class="report-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <div class="rc-name"><?php echo bc_h($name !== '' ? $name : 'Đoàn sinh #' . $sid); ?></div>
                <div class="rc-meta">
                    <i class="fas fa-chalkboard me-1"></i><?php echo bc_h($r['ten_lop'] ? $r['ten_lop'] : '—'); ?>
                    · Gửi bởi <strong><?php echo bc_h($r['created_by_name']); ?></strong> lúc <?php echo bc_datetime($r['created_at']); ?>
                </div>
            </div>
            <span class="st-badge <?php echo $info[1]; ?>"><i class="fas <?php echo $info[2]; ?> me-1"></i><?php echo bc_h($info[0]); ?></span>
        </div>

        <div class="rc-quick">
            <span>Nghỉ học: <?php echo bc_percent_badge(bc_absent_percent($st), true); ?></span>
            <span>Vắng CP: <strong><?php echo (int) $st['gl']['cp']; ?></strong></span>
            <span>Vắng KP: <strong><?php echo (int) $st['gl']['kp']; ?></strong></span>
            <span>Đi lễ: <?php echo bc_percent_badge(bc_mass_percent($st), false); ?></span>
        </div>

        <div class="rc-box rc-reason">
            <div class="rc-box-title"><i class="fas fa-comment-dots me-1"></i>Lý do Huynh Trưởng gửi</div>
            <?php echo nl2br(bc_h($r['reason'])); ?>
        </div>

        <?php if (!empty($r['hdt_at'])): ?>
            <div class="rc-box <?php echo $r['status'] === 'rejected' ? 'rc-reject' : 'rc-hdt'; ?>">
                <div class="rc-box-title">
                    <i class="fas fa-user-shield me-1"></i>
                    <?php echo $r['status'] === 'rejected' ? 'BĐH không duyệt' : 'BĐH đã xác nhận'; ?>
                    — <?php echo bc_h($r['hdt_by_name']); ?>, <?php echo bc_datetime($r['hdt_at']); ?>
                </div>
                <?php echo $r['hdt_note'] !== null && $r['hdt_note'] !== '' ? nl2br(bc_h($r['hdt_note'])) : '<span class="text-muted">Không có ghi chú.</span>'; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($r['bph_at'])): ?>
            <div class="rc-box rc-met">
                <div class="rc-box-title">
                    <i class="fas fa-handshake me-1"></i>Nội dung đã trao đổi với phụ huynh
                    — <?php echo bc_h($r['bph_by_name']); ?>, <?php echo bc_datetime($r['bph_at']); ?>
                </div>
                <?php echo nl2br(bc_h($r['bph_note'])); ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-2">
            <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#rcDetail<?php echo $rid; ?>">
                <i class="fas fa-id-card me-1"></i>Xem chi tiết đầy đủ
            </button>
            <?php if ($mode === 'hdt' && $r['status'] === 'pending'): ?>
                <button class="btn btn-sm btn-success rounded-pill fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#hdtModal"
                        data-id="<?php echo $rid; ?>" data-name="<?php echo bc_h($name); ?>">
                    <i class="fas fa-check me-1"></i>Xác nhận / Từ chối
                </button>
            <?php endif; ?>
            <?php if ($mode === 'bph' && $r['status'] === 'approved'): ?>
                <button class="btn btn-sm btn-bph rounded-pill fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#bphModal"
                        data-id="<?php echo $rid; ?>" data-name="<?php echo bc_h($name); ?>">
                    <i class="fas fa-handshake me-1"></i>Xác nhận đã gặp phụ huynh
                </button>
            <?php endif; ?>
        </div>

        <div class="collapse" id="rcDetail<?php echo $rid; ?>">
            <div class="row g-3 mt-1">
                <div class="col-lg-6">
                    <div class="detail-block">
                        <div class="detail-title"><i class="fas fa-user me-1"></i>Thông tin cá nhân</div>
                        <?php if ($s): ?>
                            <?php
                            $status_label = $s['status'] === 'on_leave' ? 'Đang tạm nghỉ' : ($s['status'] === 'active' ? 'Đang học' : $s['status']);
                            $fields = array(
                                'Tên thánh' => $s['ten_thanh'],
                                'Họ và tên' => $s['ho_ten'],
                                'Ngày sinh' => bc_date($s['ngay_sinh']),
                                'Giới tính' => $s['gioi_tinh'],
                                'Lớp / Ngành' => trim(($s['ten_lop'] ? $s['ten_lop'] : '—') . ($s['nganh'] ? ' · ' . $s['nganh'] : '')),
                                'Trạng thái' => $status_label,
                                'Ngày rửa tội' => bc_date(isset($s['ngay_rua_toi']) ? $s['ngay_rua_toi'] : ''),
                                'Ngày thêm sức' => bc_date(isset($s['ngay_them_suc']) ? $s['ngay_them_suc'] : ''),
                                'Ngày gia nhập' => bc_date(isset($s['ngay_gia_nhap']) ? $s['ngay_gia_nhap'] : ''),
                                'Họ tên bố' => isset($s['ho_ten_bo']) ? $s['ho_ten_bo'] : '',
                                'Họ tên mẹ' => isset($s['ho_ten_me']) ? $s['ho_ten_me'] : '',
                                'Email phụ huynh' => isset($s['email_phu_huynh']) ? $s['email_phu_huynh'] : '',
                                'Địa chỉ' => isset($s['dia_chi']) ? $s['dia_chi'] : '',
                                'Ghi chú hồ sơ' => isset($s['ghi_chu']) ? $s['ghi_chu'] : '',
                            );
                            $phone = isset($s['sdt_lien_lac']) ? trim($s['sdt_lien_lac']) : '';
                            ?>
                            <dl class="detail-list">
                                <?php foreach ($fields as $label => $val): ?>
                                    <dt><?php echo bc_h($label); ?></dt>
                                    <dd><?php echo ($val !== null && trim((string) $val) !== '') ? bc_h($val) : '<span class="text-muted">—</span>'; ?></dd>
                                <?php endforeach; ?>
                                <dt>SĐT liên lạc</dt>
                                <dd><?php echo $phone !== '' ? '<a href="tel:' . bc_h(preg_replace('/[^0-9+]/', '', $phone)) . '">' . bc_h($phone) . '</a>' : '<span class="text-muted">—</span>'; ?></dd>
                            </dl>
                        <?php else: ?>
                            <p class="text-muted mb-0">Không tìm thấy hồ sơ đoàn sinh.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="detail-block">
                        <div class="detail-title"><i class="fas fa-chart-simple me-1"></i>Chuyên cần</div>
                        <table class="table table-sm stat-table mb-0">
                            <thead><tr><th></th><th>Học Giáo Lý</th><th>Đi lễ</th></tr></thead>
                            <tbody>
                                <tr><td>Tổng số buổi</td><td><?php echo (int) $st['gl']['total']; ?></td><td><?php echo (int) $st['le']['total']; ?></td></tr>
                                <tr><td>Có mặt</td><td><?php echo (int) $st['gl']['co']; ?></td><td><?php echo (int) $st['le']['co']; ?></td></tr>
                                <tr><td>Đi muộn</td><td><?php echo (int) $st['gl']['muon']; ?></td><td><?php echo (int) $st['le']['muon']; ?></td></tr>
                                <tr><td>Vắng có phép</td><td><?php echo (int) $st['gl']['cp']; ?></td><td><?php echo (int) $st['le']['cp']; ?></td></tr>
                                <tr><td>Vắng không phép</td><td><?php echo (int) $st['gl']['kp']; ?></td><td><?php echo (int) $st['le']['kp']; ?></td></tr>
                                <tr class="fw-bold"><td>Tỷ lệ</td>
                                    <td>Nghỉ: <?php echo bc_percent_badge(bc_absent_percent($st), true); ?></td>
                                    <td>Đi lễ: <?php echo bc_percent_badge(bc_mass_percent($st), false); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="detail-block mt-3">
                        <div class="detail-title"><i class="fas fa-clock-rotate-left me-1"></i>Vắng / đi muộn / ghi chú gần đây</div>
                        <?php if (empty($hist)): ?>
                            <p class="text-muted mb-0">Chưa có buổi vắng, đi muộn hay ghi chú nào.</p>
                        <?php else: ?>
                            <ul class="hist-list">
                                <?php foreach ($hist as $h): ?>
                                    <li>
                                        <strong><?php echo bc_date($h['ngay_diem_danh']); ?></strong>
                                        <span class="text-muted">(<?php echo bc_h($h['activity_type']); ?>)</span>
                                        — <?php echo bc_h(ucfirst($h['status'])); ?>
                                        <?php if (!empty($h['ghi_chu'])): ?>
                                            <div class="hist-note"><i class="fas fa-note-sticky me-1"></i><?php echo bc_h($h['ghi_chu']); ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo Đoàn sinh — ĐOÀN TNTT</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(160deg, #e8f4fc, #f4f8fb 45%, #e3f2fd); min-height: 100vh; color: #263238; }
        .topbar { background: linear-gradient(135deg, #0d47a1, #1565c0 50%, #42a5f5); color: #fff; padding: .8rem 0; box-shadow: 0 8px 24px rgba(13,71,161,.22); position: sticky; top: 0; z-index: 100; }
        .topbar a { color: rgba(255,255,255,.92); text-decoration: none; font-weight: 700; font-size: .88rem; padding: .35rem .7rem; border-radius: 999px; white-space: nowrap; }
        .topbar a:hover, .topbar a.active { color: #fff; background: rgba(255,255,255,.18); }
        .page-head { background: #fff; border-radius: 1.15rem; padding: 1.1rem 1.35rem; margin: 1.25rem 0 1rem; box-shadow: 0 10px 30px rgba(21,101,192,.07); }
        .page-head h1 { font-size: 1.35rem; font-weight: 800; color: #1a237e; margin: 0; }
        .page-head p { margin: .2rem 0 0; color: #607d8b; font-weight: 600; font-size: .9rem; }
        .bc-tabs { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: 1rem; }
        .bc-tabs a { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; border-radius: 999px; background: #fff; color: #455a64; font-weight: 800; font-size: .88rem; text-decoration: none; border: 1px solid #e3eef6; }
        .bc-tabs a.active { background: linear-gradient(135deg, #1565c0, #1e88e5); color: #fff; border-color: transparent; }
        .bc-tabs .cnt { background: #ef5350; color: #fff; border-radius: 999px; font-size: .72rem; padding: .05rem .45rem; }
        .panel { background: #fff; border-radius: 1.15rem; box-shadow: 0 10px 30px rgba(21,101,192,.07); overflow: hidden; margin-bottom: 1.25rem; }
        .panel-head { background: linear-gradient(135deg, #1565c0, #42a5f5); color: #fff; padding: .9rem 1.25rem; font-weight: 800; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem; }
        .panel-body { padding: 1.1rem 1.25rem; }
        .form-select, .form-control { border-radius: .75rem; font-weight: 600; }
        .bc-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: #607d8b; white-space: nowrap; }
        .bc-table td { vertical-align: middle; font-weight: 600; }
        .pct { font-weight: 800; }
        .pct-bad { color: #d32f2f; }
        .pct-ok { color: #2e7d32; }
        .btn-send { background: linear-gradient(135deg, #7b1fa2, #ab47bc); color: #fff; border: none; border-radius: 999px; font-weight: 800; font-size: .8rem; padding: .35rem .8rem; white-space: nowrap; }
        .btn-send:hover { color: #fff; filter: brightness(1.07); }
        .btn-bph { background: linear-gradient(135deg, #7b1fa2, #ab47bc); color: #fff; border: none; }
        .btn-bph:hover { color: #fff; filter: brightness(1.07); }
        .st-badge { display: inline-flex; align-items: center; border-radius: 999px; font-size: .76rem; font-weight: 800; padding: .3rem .7rem; white-space: nowrap; }
        .st-pending { background: #fff8e1; color: #e65100; }
        .st-approved { background: #f3e5f5; color: #7b1fa2; }
        .st-rejected { background: #ffebee; color: #c62828; }
        .st-met { background: #e8f5e9; color: #2e7d32; }
        .report-card { border: 1px solid #e3eef6; border-radius: 1rem; padding: 1rem 1.1rem; margin-bottom: .9rem; background: #fbfdff; }
        .rc-name { font-weight: 800; color: #1a237e; font-size: 1.08rem; }
        .rc-meta { font-size: .82rem; color: #607d8b; font-weight: 600; }
        .rc-quick { display: flex; flex-wrap: wrap; gap: .4rem 1rem; margin: .6rem 0; font-size: .86rem; font-weight: 700; color: #455a64; }
        .rc-box { border-radius: .8rem; padding: .7rem .85rem; margin-top: .55rem; font-weight: 600; font-size: .9rem; white-space: normal; }
        .rc-box-title { font-weight: 800; font-size: .8rem; margin-bottom: .25rem; }
        .rc-reason { background: #fff; border: 1px dashed #b0bec5; }
        .rc-hdt { background: #f3e5f5; }
        .rc-reject { background: #ffebee; }
        .rc-met { background: #e8f5e9; }
        .detail-block { background: #fff; border: 1px solid #e3eef6; border-radius: .9rem; padding: .85rem 1rem; height: auto; }
        .detail-title { font-weight: 800; color: #1565c0; font-size: .88rem; margin-bottom: .5rem; }
        .detail-list { display: grid; grid-template-columns: 9.5rem 1fr; gap: .25rem .75rem; margin: 0; font-size: .88rem; }
        .detail-list dt { color: #607d8b; font-weight: 700; }
        .detail-list dd { margin: 0; font-weight: 700; word-break: break-word; }
        .stat-table td, .stat-table th { font-size: .85rem; text-align: center; }
        .stat-table td:first-child { text-align: left; color: #607d8b; font-weight: 700; }
        .hist-list { list-style: none; padding: 0; margin: 0; font-size: .86rem; }
        .hist-list li { padding: .35rem 0; border-bottom: 1px dashed #e3eef6; }
        .hist-list li:last-child { border-bottom: none; }
        .hist-note { color: #6d4c41; background: #fff8e1; border-radius: .5rem; padding: .2rem .5rem; margin-top: .2rem; }
        .group-title { font-weight: 800; color: #1a237e; margin: .25rem 0 .75rem; }
        .empty { text-align: center; color: #90a4ae; font-weight: 700; padding: 1.5rem .5rem; }
        @media (max-width: 575px) {
            .detail-list { grid-template-columns: 1fr; }
            .detail-list dd { margin-bottom: .35rem; }
            .panel-body { padding: .9rem; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <img src="img/logo.png" alt="" width="36" height="36" style="object-fit:contain">
            <span class="fw-bold">ĐOÀN TNTT Đaminh Savio Lập Trí</span>
        </div>
        <nav class="d-flex flex-wrap gap-1 align-items-center">
            <a href="index.php"><i class="fas fa-home me-1"></i>Trang chủ</a>
            <a href="classes.php"><i class="fas fa-users me-1"></i>Đoàn sinh</a>
            <a href="bao_cao_doan_sinh.php" class="active"><i class="fas fa-file-lines me-1"></i>Báo cáo Đoàn sinh</a>
            <a href="tai_khoan.php"><i class="fas fa-id-card me-1"></i>Tài khoản</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>Đăng xuất</a>
        </nav>
    </div>
</div>

<main class="container pb-5">
    <div class="page-head">
        <h1><i class="fas fa-file-lines me-2 text-primary"></i>Báo cáo Đoàn sinh</h1>
        <p>Theo dõi chuyên cần, gửi Ban Phụ Huynh những trường hợp cần trao đổi với gia đình.</p>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo bc_h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?php echo bc_h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!$tabs): ?>
        <div class="panel"><div class="panel-body empty">
            <i class="fas fa-lock fa-2x mb-2 d-block"></i>
            Tài khoản của bạn chưa phụ trách lớp nào nên chưa có báo cáo để xem.<br>
            Liên hệ BĐH để được phân công lớp.
        </div></div>
    <?php else: ?>

    <div class="bc-tabs">
        <?php foreach ($tabs as $tk => $tv): ?>
            <a href="?tab=<?php echo $tk; ?>" class="<?php echo $tab === $tk ? 'active' : ''; ?>">
                <i class="fas <?php echo $tv[1]; ?>"></i><?php echo bc_h($tv[0]); ?>
                <?php if ($tk === 'duyet' && $pending_count > 0): ?><span class="cnt"><?php echo $pending_count; ?></span><?php endif; ?>
                <?php if ($tk === 'bph' && $approved_count > 0): ?><span class="cnt"><?php echo $approved_count; ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'lop'): ?>
        <div class="panel">
            <div class="panel-head">
                <span><i class="fas fa-list-check me-2"></i>Danh sách đoàn sinh (<?php echo count($students); ?>)</span>
                <form method="get" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="tab" value="lop">
                    <select name="class" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:200px">
                        <?php foreach ($report_classes as $cid => $cls): ?>
                            <option value="<?php echo (int) $cid; ?>" <?php echo $cid === $selected_class ? 'selected' : ''; ?>>
                                <?php echo bc_h($cls['ten_lop'] . ' (' . $cls['nganh'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="panel-body">
                <?php if (empty($students)): ?>
                    <div class="empty">Lớp này chưa có đoàn sinh đang học.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover bc-table mb-0">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Tên Thánh</th>
                                    <th>Họ và tên</th>
                                    <th>Ngày sinh</th>
                                    <th class="text-center" title="Học Giáo Lý: số buổi vắng / tổng buổi">% Nghỉ học</th>
                                    <th class="text-center" title="Đi lễ: số buổi có mặt / tổng buổi">% Đi lễ</th>
                                    <th class="text-center">Ban Phụ Huynh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $i => $s): ?>
                                    <?php
                                    $sid = (int) $s['id'];
                                    $st = isset($stats[$sid]) ? $stats[$sid] : bc_empty_stats();
                                    $abs = bc_absent_percent($st);
                                    $mass = bc_mass_percent($st);
                                    $full_name = trim(($s['ten_thanh'] ? $s['ten_thanh'] . ' ' : '') . $s['ho_ten']);
                                    ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo bc_h($s['ten_thanh']); ?></td>
                                        <td><?php echo bc_h($s['ho_ten']); ?></td>
                                        <td><?php echo bc_date($s['ngay_sinh']); ?></td>
                                        <td class="text-center" title="Vắng CP <?php echo (int) $st['gl']['cp']; ?> · Vắng KP <?php echo (int) $st['gl']['kp']; ?> / <?php echo (int) $st['gl']['total']; ?> buổi">
                                            <?php echo bc_percent_badge($abs, true); ?>
                                        </td>
                                        <td class="text-center" title="<?php echo (int) ($st['le']['co'] + $st['le']['muon']); ?> / <?php echo (int) $st['le']['total']; ?> buổi">
                                            <?php echo bc_percent_badge($mass, false); ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (isset($open_reports[$sid])): ?>
                                                <?php $oi = bc_status_info($open_reports[$sid]); ?>
                                                <span class="st-badge <?php echo $oi[1]; ?>"><i class="fas <?php echo $oi[2]; ?> me-1"></i><?php echo bc_h($oi[0]); ?></span>
                                            <?php else: ?>
                                                <button type="button" class="btn-send" data-bs-toggle="modal" data-bs-target="#sendModal"
                                                        data-id="<?php echo $sid; ?>"
                                                        data-name="<?php echo bc_h($full_name); ?>"
                                                        data-summary="<?php echo bc_h('Nghỉ học: ' . ($abs === null ? '—' : $abs . '%') . ' (vắng CP ' . (int) $st['gl']['cp'] . ', vắng KP ' . (int) $st['gl']['kp'] . ' / ' . (int) $st['gl']['total'] . ' buổi) · Đi lễ: ' . ($mass === null ? '—' : $mass . '%')); ?>">
                                                    <i class="fas fa-paper-plane me-1"></i>Gửi Ban Phụ Huynh
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mt-2 mb-0">
                        <i class="fas fa-circle-info me-1"></i>% nghỉ học trên 40% và % đi lễ dưới 60% được tô đỏ.
                        Yêu cầu gửi Ban Phụ Huynh cần BĐH xác nhận trước khi Ban Phụ Huynh nhận được.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($report_groups as $group): ?>
            <div class="panel">
                <div class="panel-body">
                    <?php if ($group[0] !== ''): ?>
                        <div class="group-title"><?php echo bc_h($group[0]); ?> (<?php echo count($group[1]); ?>)</div>
                    <?php endif; ?>
                    <?php if (empty($group[1])): ?>
                        <div class="empty">Chưa có yêu cầu nào.</div>
                    <?php else: ?>
                        <?php foreach ($group[1] as $r): ?>
                            <?php bc_render_report_card($conn, $r, $group[2]); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
</main>

<?php if ($can_report): ?>
<div class="modal fade" id="sendModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header" style="background:linear-gradient(135deg,#7b1fa2,#ab47bc);color:#fff">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Gửi Ban Phụ Huynh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?php echo bc_h($csrf); ?>">
                    <input type="hidden" name="action" value="create_report">
                    <input type="hidden" name="tab" value="lop">
                    <input type="hidden" name="class" value="<?php echo (int) $selected_class; ?>">
                    <input type="hidden" name="student_id" id="send_student_id">
                    <p class="mb-1">Đoàn sinh: <strong id="send_student_name"></strong></p>
                    <p class="small text-muted mb-3" id="send_student_summary"></p>
                    <label class="form-label fw-bold">Lý do gửi Ban Phụ Huynh <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="5" required minlength="10"
                              placeholder="VD: Em nghỉ học không phép 3 buổi liên tiếp (13/09, 20/09, 27/09), thường xuyên không mang sách vở, ít tham gia Thánh lễ. Kính nhờ Ban Phụ Huynh trao đổi với gia đình."></textarea>
                    <div class="form-text">Ghi đầy đủ, rõ ràng để Ban Phụ Huynh nắm được tình hình khi gặp gia đình.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-bph rounded-pill fw-bold"><i class="fas fa-check me-1"></i>Xác nhận gửi</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_approve): ?>
<div class="modal fade" id="hdtModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="hdtForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-user-shield me-2"></i>Xác nhận chuyển Ban Phụ Huynh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?php echo bc_h($csrf); ?>">
                    <input type="hidden" name="action" value="hdt_decide">
                    <input type="hidden" name="tab" value="duyet">
                    <input type="hidden" name="report_id" id="hdt_report_id">
                    <p class="mb-2">Đoàn sinh: <strong id="hdt_student_name"></strong></p>
                    <label class="form-label fw-bold">Ghi chú của BĐH</label>
                    <textarea name="note" id="hdt_note" class="form-control" rows="3" placeholder="Không bắt buộc khi xác nhận; bắt buộc khi không duyệt."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="decision" value="reject" class="btn btn-outline-danger rounded-pill fw-bold" id="hdtRejectBtn"><i class="fas fa-ban me-1"></i>Không duyệt</button>
                    <button type="submit" name="decision" value="approve" class="btn btn-success rounded-pill fw-bold"><i class="fas fa-share me-1"></i>Xác nhận, chuyển BPH</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_bph): ?>
<div class="modal fade" id="bphModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header" style="background:linear-gradient(135deg,#7b1fa2,#ab47bc);color:#fff">
                    <h5 class="modal-title"><i class="fas fa-handshake me-2"></i>Xác nhận đã gặp phụ huynh</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?php echo bc_h($csrf); ?>">
                    <input type="hidden" name="action" value="bph_met">
                    <input type="hidden" name="tab" value="bph">
                    <input type="hidden" name="report_id" id="bph_report_id">
                    <p class="mb-2">Đoàn sinh: <strong id="bph_student_name"></strong></p>
                    <label class="form-label fw-bold">Nội dung đã trao đổi với phụ huynh <span class="text-danger">*</span></label>
                    <textarea name="note" class="form-control" rows="5" required
                              placeholder="VD: Đã gặp mẹ em ngày 30/09. Gia đình cho biết em bận học thêm, hứa sẽ sắp xếp để em đi học và đi lễ đều đặn từ tuần sau."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-bph rounded-pill fw-bold"><i class="fas fa-check me-1"></i>Xác nhận</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    function bindModal(id, fill) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('show.bs.modal', function(e) {
            if (e.relatedTarget) fill(e.relatedTarget);
        });
    }
    bindModal('sendModal', function(btn) {
        document.getElementById('send_student_id').value = btn.getAttribute('data-id');
        document.getElementById('send_student_name').textContent = btn.getAttribute('data-name');
        document.getElementById('send_student_summary').textContent = btn.getAttribute('data-summary');
    });
    bindModal('hdtModal', function(btn) {
        document.getElementById('hdt_report_id').value = btn.getAttribute('data-id');
        document.getElementById('hdt_student_name').textContent = btn.getAttribute('data-name');
        document.getElementById('hdt_note').value = '';
    });
    bindModal('bphModal', function(btn) {
        document.getElementById('bph_report_id').value = btn.getAttribute('data-id');
        document.getElementById('bph_student_name').textContent = btn.getAttribute('data-name');
    });
    var rejectBtn = document.getElementById('hdtRejectBtn');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', function(e) {
            var note = document.getElementById('hdt_note');
            if (!note.value.trim()) {
                e.preventDefault();
                alert('Vui lòng ghi lý do không duyệt.');
                note.focus();
            }
        });
    }
})();
</script>
</body>
</html>
<?php $conn->close(); ?>
