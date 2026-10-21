<?php
session_start();
// Simple admin page to paste daily Gospel and Reflection and save to DB
// Note: In production, add authentication and CSRF protection.

// Load database configuration
require_once 'db_connect.php';
require_once __DIR__ . '/scripts/liturgy_calendar.php';
$mysqli = $conn;

$today = date('Y-m-d');
$message = '';
$calendar_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_calendar'])) {
    if (!empty($_FILES['calendar_csv']['tmp_name'])) {
        $result = liturgy_calendar_import_csv($mysqli, $_FILES['calendar_csv']['tmp_name']);
        $calendar_message = isset($result['message'])
            ? $result['message']
            : ($result['success'] ? 'Nhập lịch thành công.' : 'Nhập lịch thất bại.');
    } else {
        $calendar_message = 'Vui lòng chọn file CSV.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['import_calendar'])) {
    $title = isset($_POST['title']) ? $_POST['title'] : '';
    $gospel = isset($_POST['gospel']) ? $_POST['gospel'] : '';
    $reflection = isset($_POST['reflection']) ? $_POST['reflection'] : '';

    $sql = "INSERT INTO loichua_daily (ngay, title, gospel, reflection, created_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE title=VALUES(title), gospel=VALUES(gospel), reflection=VALUES(reflection), created_at=NOW()";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssss', $today, $title, $gospel, $reflection);
        if ($stmt->execute()) {
            $message = 'Đã lưu Lời Chúa cho ' . $today;
        } else {
            $message = 'Lỗi khi lưu: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Lỗi chuẩn bị truy vấn: ' . $mysqli->error;
    }
}

// Load current if exists
$title_val = '';
$gospel_val = '';
$reflection_val = '';
$sel = $mysqli->prepare("SELECT title, gospel, reflection FROM loichua_daily WHERE ngay = ? LIMIT 1");
if ($sel) {
    $sel->bind_param('s', $today);
    $sel->execute();
    $sel->bind_result($t0, $g0, $r0);
    if ($sel->fetch()) {
        $title_val = $t0;
        $gospel_val = $g0;
        $reflection_val = $r0;
    }
    $sel->close();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Lời Chúa - Lưu nhanh</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h3>Admin Lời Chúa - <?php echo $today; ?></h3>
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <button type="button" id="autoFetchBtn" class="btn btn-success">Tự tải nội dung hôm nay</button>
            <span id="fetchStatus" style="margin-left:12px"></span>
        </div>
        <div class="mb-3">
            <label class="form-label">Tiêu đề (tùy chọn)</label>
            <input name="title" class="form-control" value="<?php echo htmlspecialchars($title_val); ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Tin Mừng (HTML)</label>
            <textarea name="gospel" rows="8" class="form-control"><?php echo htmlspecialchars($gospel_val); ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Suy niệm (HTML)</label>
            <textarea name="reflection" rows="8" class="form-control"><?php echo htmlspecialchars($reflection_val); ?></textarea>
        </div>
        <button class="btn btn-primary">Lưu (Ghi đè nếu đã có)</button>
    </form>
    <hr>
    <h4>Nhập lịch phụng vụ (CSV)</h4>
    <?php if ($calendar_message): ?>
        <div class="alert alert-secondary"><?php echo htmlspecialchars($calendar_message); ?></div>
    <?php endif; ?>
    <p class="text-muted small">Cột: <code>ngay, feast, liturgy_calendar, readings_summary, liturgical_color, liturgy_year, reading_1_ref, gospel_ref</code>. Ngày dạng <code>YYYY-MM-DD</code> hoặc <code>DD/MM/YYYY</code>.</p>
    <p><a href="data/liturgy_calendar_sample.csv" download>Mẫu file CSV</a></p>
    <form method="post" enctype="multipart/form-data" class="mb-4">
        <input type="hidden" name="import_calendar" value="1">
        <div class="mb-3">
            <input type="file" name="calendar_csv" accept=".csv,text/csv" class="form-control" required>
        </div>
        <button class="btn btn-outline-primary">Nhập lịch phụng vụ</button>
    </form>
    <hr>
    <p>Ghi chú: Trang này là thao tác thủ công nhanh để nếu tự động không hoạt động bạn có thể dán nội dung vào và lưu. Lịch phụng vụ CSV sẽ là nguồn chính cho tên lễ, màu áo lễ và trích dẫn bài đọc; nội dung đầy đủ vẫn tự tải theo ngày.</p>
</div>
<script>
document.getElementById('autoFetchBtn').addEventListener('click', async function(){
    const btn = this;
    const status = document.getElementById('fetchStatus');
    btn.disabled = true; status.textContent = 'Đang tải...';
    try {
        const resp = await fetch('scripts/fetch_and_save_loichua.php', { method: 'POST' });
        const data = await resp.json();
        if (data.success) {
            // fill fields
            document.querySelector('input[name="title"]').value = data.title || '';
            document.querySelector('textarea[name="gospel"]').value = data.gospel || '';
            document.querySelector('textarea[name="reflection"]').value = data.reflection || '';
            status.textContent = 'Đã tải xong.';
        } else {
            status.textContent = 'Không tải được: ' + (data.message || 'Lỗi');
        }
    } catch (err) {
        status.textContent = 'Lỗi: ' + err.message;
    } finally {
        btn.disabled = false;
    }
});
</script>
</body>
</html>