<?php
session_start();
require_once 'login_redirect.php';
if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}
require_once 'db_connect.php';
require_once __DIR__ . '/includes/student_qr.php';

sqr_ensure_schema($conn);

function tqr_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$class_id = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
$res_class = $conn->query("SELECT id, ten_lop, nganh FROM classes WHERE id = $class_id");
$class = $res_class ? $res_class->fetch_assoc() : null;
if (!$class) {
    die('Lớp không tồn tại.');
}
$can_manage = sqr_can_scan($conn, $class_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage && isset($_POST['regenerate'])) {
    $sid = (int)$_POST['regenerate'];
    $chk = $conn->query("SELECT ho_ten FROM students WHERE id = $sid AND class_id = $class_id");
    if ($chk && ($row = $chk->fetch_assoc()) && sqr_regenerate_token($conn, $sid)) {
        $_SESSION['success'] = 'Đã cấp lại mã QR cho ' . $row['ho_ten'] . '. Thẻ cũ không còn dùng được.';
    } else {
        $_SESSION['error'] = 'Không cấp lại được mã QR.';
    }
    header('Location: the_qr.php?class_id=' . $class_id);
    exit;
}

$students = [];
if ($can_manage) {
    sqr_ensure_class_tokens($conn, $class_id);
    $res = $conn->query("SELECT id, ten_thanh, ho_ten, ngay_sinh, qr_token FROM students WHERE class_id = $class_id AND status = 'active' ORDER BY ho_ten");
    while ($res && ($r = $res->fetch_assoc())) {
        $students[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thẻ QR - <?php echo tqr_h($class['ten_lop']); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(160deg, #e8f4fc, #f4f8fb 45%, #e3f2fd); min-height: 100vh; color: #263238; }
        .qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .qr-item { background: #fff; border: 2px solid #1565c0; border-radius: 12px; padding: 10px; text-align: center; page-break-inside: avoid; break-inside: avoid; }
        .qr-item .qr-top { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: .68rem; font-weight: 800; color: #0d47a1; margin-bottom: 6px; line-height: 1.2; }
        .qr-item .qr-top img { width: 26px; height: 26px; object-fit: contain; }
        .qr-item .qr-code { display: flex; justify-content: center; margin: 4px 0; }
        .qr-item .qr-name { font-weight: 800; font-size: .95rem; line-height: 1.25; }
        .qr-item .qr-meta { font-size: .75rem; color: #546e7a; }
        .qr-item .qr-token { font-family: monospace; font-size: .7rem; color: #78909c; letter-spacing: .5px; }
        .qr-item .qr-actions { margin-top: 6px; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            main { padding: 0 !important; max-width: none !important; }
            .qr-grid { grid-template-columns: repeat(4, 1fr); gap: 8px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/app_header.php'; app_header_render('Thẻ QR đoàn sinh'); ?>

<main class="container py-3 py-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <h1 class="h4 fw-bold mb-0"><i class="fas fa-id-card me-2 text-primary"></i>Thẻ QR · <?php echo tqr_h($class['ten_lop']); ?></h1>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_manage): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>In thẻ</button>
                <a href="diem_danh_qr.php?class_id=<?php echo $class_id; ?>" class="btn btn-success btn-sm"><i class="fas fa-qrcode me-1"></i>Quét điểm danh</a>
            <?php endif; ?>
            <a href="class_details.php?id=<?php echo $class_id; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Về trang lớp</a>
        </div>
    </div>

    <?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): ?>
        <?php if (isset($_SESSION[$k])): ?>
            <div class="alert alert-<?php echo $cls; ?> no-print"><?php echo tqr_h($_SESSION[$k]); unset($_SESSION[$k]); ?></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!$can_manage): ?>
        <div class="alert alert-warning"><i class="fas fa-lock me-2"></i>Chỉ Trưởng / Phó lớp <?php echo tqr_h($class['ten_lop']); ?> hoặc Admin/HDT mới xem được thẻ QR.</div>
    <?php elseif (empty($students)): ?>
        <div class="alert alert-info">Lớp chưa có đoàn sinh.</div>
    <?php else: ?>
        <p class="text-muted small no-print">Mỗi em có một mã cố định. Khi thẻ bị mất, bấm <strong>Cấp lại</strong>: thẻ cũ sẽ không quét được nữa, cần in lại thẻ mới.</p>
        <div class="qr-grid">
            <?php foreach ($students as $s): ?>
                <div class="qr-item">
                    <div class="qr-top">
                        <img src="img/logo.png" alt="">
                        <span>Đoàn TNTT Đaminh Savio<br>Lập Trí</span>
                    </div>
                    <div class="qr-code" data-qr="<?php echo tqr_h(SQR_PREFIX . $s['qr_token']); ?>"></div>
                    <div class="qr-name"><?php echo tqr_h(trim(($s['ten_thanh'] ? $s['ten_thanh'] . ' ' : '') . $s['ho_ten'])); ?></div>
                    <div class="qr-meta">Lớp <?php echo tqr_h($class['ten_lop']); ?><?php echo !empty($s['ngay_sinh']) ? ' · ' . date('d/m/Y', strtotime($s['ngay_sinh'])) : ''; ?></div>
                    <div class="qr-token"><?php echo tqr_h($s['qr_token']); ?></div>
                    <form method="POST" class="qr-actions no-print" onsubmit="return confirm('Cấp lại mã QR cho em này? Thẻ cũ sẽ không dùng được nữa.');">
                        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                        <button type="submit" name="regenerate" value="<?php echo (int)$s['id']; ?>" class="btn btn-outline-danger btn-sm py-0"><i class="fas fa-rotate me-1"></i>Cấp lại</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php if (!empty($students)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr-code[data-qr]').forEach(function(el) {
    new QRCode(el, { text: el.getAttribute('data-qr'), width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M });
});
</script>
<?php endif; ?>
</body>
</html>
