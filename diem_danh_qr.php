<?php
session_start();
require_once 'login_redirect.php';
if (empty($_SESSION['user_id'])) {
    require_login_redirect();
}
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}
require_once 'db_connect.php';
require_once __DIR__ . '/includes/student_qr.php';

sqr_ensure_schema($conn);

function ddqr_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ddqr_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function ddqr_valid_date($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
}

$class_id = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
$res_class = $conn->query("SELECT id, ten_lop, nganh FROM classes WHERE id = $class_id");
$class = $res_class ? $res_class->fetch_assoc() : null;
if (!$class) {
    die('Lớp không tồn tại.');
}
$can_scan = sqr_can_scan($conn, $class_id);

$date = isset($_REQUEST['ngay']) && ddqr_valid_date($_REQUEST['ngay']) ? $_REQUEST['ngay'] : date('Y-m-d');
$activity = (isset($_REQUEST['activity']) && $_REQUEST['activity'] === 'Đi lễ') ? 'Đi lễ' : 'Học Giáo Lý';

function ddqr_class_list($conn, $class_id, $date, $activity) {
    $stmt = $conn->prepare(
        "SELECT s.id, s.ten_thanh, s.ho_ten, a.status
         FROM students s
         LEFT JOIN attendance a ON a.student_id = s.id AND a.class_id = s.class_id
              AND a.ngay_diem_danh = ? AND a.activity_type = ?
         WHERE s.class_id = ? AND s.status = 'active'
         ORDER BY s.ho_ten"
    );
    $stmt->bind_param('ssi', $date, $activity, $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($r = $res->fetch_assoc()) {
        $list[] = [
            'id' => (int)$r['id'],
            'name' => trim(($r['ten_thanh'] ? $r['ten_thanh'] . ' ' : '') . $r['ho_ten']),
            'status' => $r['status'] !== null ? $r['status'] : '',
        ];
    }
    $stmt->close();
    return $list;
}

/** Các em sinh hoạt chưa có bản ghi điểm danh trong buổi này → mặc định vắng không phép. */
function ddqr_default_absent($conn, $class_id, $date, $activity) {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO attendance (student_id, class_id, ngay_diem_danh, activity_type, status)
         SELECT s.id, s.class_id, ?, ?, 'vắng không phép'
         FROM students s
         WHERE s.class_id = ? AND s.status = 'active'"
    );
    $stmt->bind_param('ssi', $date, $activity, $class_id);
    $stmt->execute();
    $added = $stmt->affected_rows;
    $stmt->close();
    return max(0, (int)$added);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_scan) {
        ddqr_json(['ok' => false, 'type' => 'error', 'message' => 'Bạn không phụ trách lớp này nên không được điểm danh.']);
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'list') {
        ddqr_json(['ok' => true, 'list' => ddqr_class_list($conn, $class_id, $date, $activity)]);
    }

    if ($action === 'scan') {
        $token = sqr_parse_payload(isset($_POST['payload']) ? $_POST['payload'] : '');
        if ($token === '') {
            ddqr_json(['ok' => false, 'type' => 'error', 'message' => 'Mã QR không hợp lệ (không phải thẻ đoàn sinh).']);
        }
        $stmt = $conn->prepare(
            "SELECT s.id, s.ten_thanh, s.ho_ten, s.class_id, s.status, c.ten_lop
             FROM students s LEFT JOIN classes c ON c.id = s.class_id
             WHERE s.qr_token = ? LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $st = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$st) {
            ddqr_json(['ok' => false, 'type' => 'error', 'message' => 'Không tìm thấy em nào với mã này (thẻ cũ đã được cấp lại?).']);
        }
        $name = trim(($st['ten_thanh'] ? $st['ten_thanh'] . ' ' : '') . $st['ho_ten']);
        if ((int)$st['class_id'] !== $class_id) {
            $other = $st['ten_lop'] ? 'lớp ' . $st['ten_lop'] : 'lớp khác';
            ddqr_json(['ok' => false, 'type' => 'error', 'name' => $name, 'message' => "$name thuộc $other, không phải lớp này."]);
        }
        if ($st['status'] !== 'active') {
            ddqr_json(['ok' => false, 'type' => 'error', 'name' => $name, 'message' => "$name đang không sinh hoạt (tạm nghỉ / đã chuyển)."]);
        }

        $sid = (int)$st['id'];
        $stmt = $conn->prepare("SELECT id, status FROM attendance WHERE student_id = ? AND class_id = ? AND ngay_diem_danh = ? AND activity_type = ? LIMIT 1");
        $stmt->bind_param('iiss', $sid, $class_id, $date, $activity);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing && in_array($existing['status'], ['có', 'đi muộn'], true)) {
            ddqr_default_absent($conn, $class_id, $date, $activity);
            ddqr_json(['ok' => true, 'type' => 'warning', 'student_id' => $sid, 'name' => $name,
                'status' => $existing['status'],
                'message' => "$name đã được điểm danh trước đó ({$existing['status']}).",
                'list' => ddqr_class_list($conn, $class_id, $date, $activity)]);
        }
        $status = (isset($_POST['mode']) && $_POST['mode'] === 'late') ? 'đi muộn' : 'có';
        if ($existing) {
            $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $aid = (int)$existing['id'];
            $stmt->bind_param('si', $status, $aid);
        } else {
            $stmt = $conn->prepare("INSERT INTO attendance (student_id, class_id, ngay_diem_danh, activity_type, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisss', $sid, $class_id, $date, $activity, $status);
        }
        $saved = $stmt->execute();
        $stmt->close();
        if (!$saved) {
            ddqr_json(['ok' => false, 'type' => 'error', 'message' => 'Lỗi khi lưu điểm danh: ' . $conn->error]);
        }
        ddqr_default_absent($conn, $class_id, $date, $activity);
        ddqr_json([
            'ok' => true,
            'type' => 'success',
            'student_id' => $sid,
            'name' => $name,
            'status' => $status,
            'message' => $name . ': ' . ($status === 'đi muộn' ? 'Đi muộn' : 'Có mặt'),
            'list' => ddqr_class_list($conn, $class_id, $date, $activity),
        ]);
    }

    ddqr_json(['ok' => false, 'type' => 'error', 'message' => 'Yêu cầu không hợp lệ.']);
}

$list = $can_scan ? ddqr_class_list($conn, $class_id, $date, $activity) : [];
$back_url = 'class_details.php?id=' . $class_id . '&ngay_diem_danh=' . urlencode($date) . '&activity=' . urlencode($activity);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quét QR điểm danh - <?php echo ddqr_h($class['ten_lop']); ?></title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(160deg, #e8f4fc, #f4f8fb 45%, #e3f2fd); min-height: 100vh; color: #263238; }
        .qr-card { background: #fff; border-radius: 1.1rem; box-shadow: 0 6px 22px rgba(13,71,161,.08); padding: 1rem; }
        #reader { width: 100%; max-width: 460px; margin: 0 auto; border-radius: .9rem; overflow: hidden; background: #0d1b2a; min-height: 200px; position: relative; }
        #reader video { display: block; width: 100% !important; height: auto !important; max-height: 46vh; object-fit: cover; border-radius: .9rem; }
        @media (max-width: 991.98px) {
            #reader { max-width: 340px; min-height: 0; }
            #reader video { max-height: 40vh; }
            .scan-result { position: sticky; top: .5rem; z-index: 5; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
            .form-control, .form-select { font-size: 16px; }
        }
        #reader.mode-late { outline: 4px solid #ffb300; outline-offset: 2px; }
        .scan-result { border-radius: .9rem; padding: .85rem 1rem; font-weight: 800; font-size: 1.05rem; display: flex; align-items: center; gap: .6rem; min-height: 56px; }
        .scan-result.idle { background: #eceff1; color: #546e7a; }
        .scan-result.success { background: #e8f5e9; color: #1b5e20; }
        .scan-result.warning { background: #fff8e1; color: #8d6e00; }
        .scan-result.error { background: #ffebee; color: #b71c1c; }
        .stat-pill { border-radius: 999px; padding: .35rem .85rem; font-weight: 800; font-size: .9rem; }
        .st-list { max-height: 60vh; overflow-y: auto; position: relative; }
        .st-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem .25rem; border-bottom: 1px solid #eef2f5; font-weight: 600; }
        .st-row.flash { animation: flash 1.2s ease; }
        @keyframes flash { 0% { background: #c8e6c9; } 100% { background: transparent; } }
        .form-control, .form-select { border-radius: .75rem; font-weight: 600; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/app_header.php'; app_header_render('Quét QR điểm danh'); ?>

<main class="container py-3 py-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 fw-bold mb-0"><i class="fas fa-qrcode me-2 text-primary"></i>Quét QR điểm danh · <?php echo ddqr_h($class['ten_lop']); ?></h1>
        <div class="d-flex gap-2">
            <a href="the_qr.php?class_id=<?php echo $class_id; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-id-card me-1"></i>Thẻ QR</a>
            <a href="<?php echo ddqr_h($back_url); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Về trang lớp</a>
        </div>
    </div>

    <?php if (!$can_scan): ?>
        <div class="alert alert-warning"><i class="fas fa-lock me-2"></i>Chỉ Trưởng / Phó lớp <?php echo ddqr_h($class['ten_lop']); ?> hoặc Admin/HDT mới được điểm danh bằng QR.</div>
    <?php else: ?>
    <form method="GET" class="qr-card mb-3 d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
        <input type="date" name="ngay" class="form-control" style="max-width: 190px" value="<?php echo ddqr_h($date); ?>" onchange="this.form.submit()">
        <select name="activity" class="form-select" style="max-width: 190px" onchange="this.form.submit()">
            <option value="Học Giáo Lý" <?php echo $activity === 'Học Giáo Lý' ? 'selected' : ''; ?>>Học Giáo Lý</option>
            <option value="Đi lễ" <?php echo $activity === 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
        </select>
        <span class="small text-muted">Khi quét em đầu tiên, các em chưa được quét sẽ mặc định là <strong>Vắng không phép</strong>. Muốn sửa (vắng có phép...) thì chỉnh ở bảng điểm danh của lớp.</span>
    </form>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="qr-card">
                <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 mb-3">
                    <span class="fw-bold text-muted small">Quét thẻ sẽ ghi:</span>
                    <div class="btn-group" role="group" aria-label="Chế độ quét">
                        <input type="radio" class="btn-check" name="scanMode" id="modePresent" value="present" checked>
                        <label class="btn btn-outline-success fw-bold" for="modePresent"><i class="fas fa-check me-1"></i>Có mặt</label>
                        <input type="radio" class="btn-check" name="scanMode" id="modeLate" value="late">
                        <label class="btn btn-outline-warning fw-bold" for="modeLate"><i class="fas fa-clock me-1"></i>Đi muộn</label>
                    </div>
                </div>
                <div id="reader"></div>
                <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                    <button type="button" id="btnStart" class="btn btn-primary"><i class="fas fa-camera me-1"></i>Bật camera</button>
                    <button type="button" id="btnStop" class="btn btn-outline-secondary d-none"><i class="fas fa-stop me-1"></i>Tắt camera</button>
                </div>
                <div id="scanResult" class="scan-result idle mt-3"><i class="fas fa-info-circle"></i><span>Bật camera rồi đưa thẻ QR vào khung.</span></div>
                <form id="manualForm" class="d-flex gap-2 mt-3">
                    <input type="text" id="manualCode" class="form-control" placeholder="Hoặc nhập mã trên thẻ (16 ký tự)" autocomplete="off">
                    <button class="btn btn-outline-primary text-nowrap" type="submit"><i class="fas fa-check me-1"></i>Điểm danh</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="qr-card">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="stat-pill bg-success-subtle text-success-emphasis">Có mặt: <span id="cntPresent">0</span></span>
                    <span class="stat-pill bg-warning-subtle text-warning-emphasis">Đi muộn: <span id="cntLate">0</span></span>
                    <span class="stat-pill bg-danger-subtle text-danger-emphasis">Vắng: <span id="cntAbsent">0</span></span>
                    <span class="stat-pill bg-secondary-subtle text-secondary-emphasis">Chưa điểm danh: <span id="cntPending">0</span></span>
                    <span class="stat-pill bg-light text-dark">Sĩ số: <span id="cntTotal">0</span></span>
                </div>
                <div class="st-list" id="stList"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php if ($can_scan): ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function() {
    var CLASS_ID = <?php echo (int)$class_id; ?>;
    var DATE = <?php echo json_encode($date); ?>;
    var ACTIVITY = <?php echo json_encode($activity); ?>;
    var list = <?php echo json_encode($list, JSON_UNESCAPED_UNICODE); ?>;
    var resultBox = document.getElementById('scanResult');
    var lastCode = '', lastAt = 0, busy = false, scanner = null;

    var STATUS_LABEL = { 'có': 'Có', 'đi muộn': 'Đi muộn', 'vắng có phép': 'Vắng phép', 'vắng không phép': 'Vắng KP' };
    var STATUS_CLASS = { 'có': 'bg-success', 'đi muộn': 'bg-warning text-dark', 'vắng có phép': 'bg-info text-dark', 'vắng không phép': 'bg-danger' };

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function scanMode() {
        return document.getElementById('modeLate').checked ? 'late' : 'present';
    }

    function render(flashId) {
        var html = '', present = 0, late = 0, absent = 0;
        list.forEach(function(st) {
            if (st.status === 'có') present++;
            else if (st.status === 'đi muộn') late++;
            else if (st.status === 'vắng có phép' || st.status === 'vắng không phép') absent++;
            var badge = st.status
                ? '<span class="badge ' + STATUS_CLASS[st.status] + '">' + STATUS_LABEL[st.status] + '</span>'
                : '<span class="badge bg-light text-muted border">Chưa</span>';
            html += '<div class="st-row' + (st.id === flashId ? ' flash' : '') + '" data-id="' + st.id + '"><span>' + esc(st.name) + '</span>' + badge + '</div>';
        });
        document.getElementById('stList').innerHTML = html || '<div class="text-muted text-center py-3">Lớp chưa có đoàn sinh.</div>';
        document.getElementById('cntPresent').textContent = present;
        document.getElementById('cntLate').textContent = late;
        document.getElementById('cntAbsent').textContent = absent;
        document.getElementById('cntPending').textContent = list.filter(function(s) { return !s.status; }).length;
        document.getElementById('cntTotal').textContent = list.length;
        if (flashId) {
            var box = document.getElementById('stList');
            var row = box.querySelector('.st-row[data-id="' + flashId + '"]');
            if (row) box.scrollTo({ top: row.offsetTop - box.clientHeight / 2, behavior: 'smooth' });
        }
    }

    function showResult(type, message) {
        var icon = { success: 'fa-check-circle', warning: 'fa-exclamation-triangle', error: 'fa-times-circle' }[type] || 'fa-info-circle';
        resultBox.className = 'scan-result ' + type + ' mt-3';
        resultBox.innerHTML = '<i class="fas ' + icon + '"></i><span>' + esc(message) + '</span>';
    }

    function feedback(type) {
        if (navigator.vibrate) navigator.vibrate(type === 'success' ? 120 : [80, 60, 80]);
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = type === 'success' ? 880 : 300;
            g.gain.value = 0.15;
            o.connect(g); g.connect(ctx.destination);
            o.start(); o.stop(ctx.currentTime + 0.15);
        } catch (e) {}
    }

    function post(data) {
        var body = new URLSearchParams(Object.assign({ class_id: CLASS_ID, ngay: DATE, activity: ACTIVITY }, data));
        return fetch('diem_danh_qr.php', { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r) { return r.json(); });
    }

    function submitCode(code) {
        var now = Date.now();
        if (busy || (code === lastCode && now - lastAt < 3000)) return;
        lastCode = code; lastAt = now; busy = true;
        post({ action: 'scan', payload: code, mode: scanMode() }).then(function(res) {
            showResult(res.type || (res.ok ? 'success' : 'error'), res.message);
            feedback(res.type);
            if (res.ok && res.student_id) {
                if (res.list) {
                    list = res.list;
                } else {
                    list.forEach(function(st) {
                        if (st.id === res.student_id && res.status) st.status = res.status;
                    });
                }
                render(res.student_id);
            }
        }).catch(function() {
            showResult('error', 'Mất kết nối, vui lòng quét lại.');
        }).finally(function() { busy = false; });
    }

    // iPhone/Safari tự phóng video ra toàn màn hình nếu thiếu playsinline → gắn ngay khi thư viện tạo thẻ video.
    function keepInline(video) {
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('muted', '');
        video.setAttribute('disablepictureinpicture', '');
        video.playsInline = true;
        video.muted = true;
        video.addEventListener('webkitbeginfullscreen', function() {
            if (video.webkitExitFullscreen) video.webkitExitFullscreen();
        });
    }
    new MutationObserver(function() {
        document.querySelectorAll('#reader video').forEach(function(v) {
            if (!v.dataset.inline) { v.dataset.inline = '1'; keepInline(v); }
        });
    }).observe(document.getElementById('reader'), { childList: true, subtree: true });

    document.getElementById('btnStart').addEventListener('click', function() {
        if (!window.Html5Qrcode) { showResult('error', 'Không tải được thư viện quét QR (kiểm tra kết nối mạng).'); return; }
        scanner = scanner || new Html5Qrcode('reader');
        var scanConfig = {
            fps: 10,
            aspectRatio: 1.0,
            disableFlip: true,
            qrbox: function(w, h) {
                var s = Math.floor(Math.min(w, h) * 0.72);
                return { width: s, height: s };
            }
        };
        scanner.start({ facingMode: 'environment' }, scanConfig, submitCode, function() {})
            .then(function() {
                document.getElementById('btnStart').classList.add('d-none');
                document.getElementById('btnStop').classList.remove('d-none');
                showResult('idle', 'Đang quét... đưa thẻ QR vào khung.');
            })
            .catch(function(err) {
                var msg = 'Không mở được camera. ';
                if (location.protocol !== 'https:' && location.hostname !== 'localhost') msg += 'Trình duyệt chỉ cho dùng camera trên trang HTTPS.';
                else msg += 'Hãy cho phép trình duyệt truy cập camera.';
                showResult('error', msg);
            });
    });
    document.getElementById('btnStop').addEventListener('click', function() {
        if (!scanner) return;
        scanner.stop().then(function() {
            document.getElementById('btnStop').classList.add('d-none');
            document.getElementById('btnStart').classList.remove('d-none');
            showResult('idle', 'Đã tắt camera.');
        });
    });
    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var v = document.getElementById('manualCode').value.trim();
        if (v) { lastCode = ''; submitCode(v); document.getElementById('manualCode').value = ''; }
    });

    document.querySelectorAll('input[name="scanMode"]').forEach(function(r) {
        r.addEventListener('change', function() {
            var late = scanMode() === 'late';
            document.getElementById('reader').classList.toggle('mode-late', late);
            showResult(late ? 'warning' : 'idle', late ? 'Chế độ ĐI MUỘN: các em được quét từ giờ sẽ ghi Đi muộn.' : 'Chế độ CÓ MẶT: các em được quét sẽ ghi Có.');
        });
    });

    render(0);
    setInterval(function() {
        post({ action: 'list' }).then(function(res) { if (res.ok) { list = res.list; render(0); } });
    }, 20000);
})();
</script>
<?php endif; ?>
</body>
</html>
