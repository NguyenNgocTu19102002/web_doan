<?php
// Hosting đang dùng PHP 5.6 — bật lỗi tạm khi thêm ?debug=1 vào URL
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_start();
require_once __DIR__ . '/login_redirect.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/thuchi.php';

$logged_in = isset($_SESSION['user_id']);
if (!$logged_in) {
    require_login_redirect();
}

@$ok_schema = thuchi_ensure_schema($conn);
if ($ok_schema === false && isset($_GET['debug'])) {
    echo '<pre>Schema warning: ' . htmlspecialchars($conn->error) . '</pre>';
}

$role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : (isset($_SESSION['role']) ? $_SESSION['role'] : '');
$is_admin = ($role === 'admin');
$is_thuquy = ($role === 'thuquy');
$can_edit = $is_thuquy; // chỉ thủ quỹ được nhập/sửa; tài khoản khác chỉ xem
$can_view = true; // mọi tài khoản đăng nhập đều xem được
$user_id = (int)$_SESSION['user_id'];

// ── POST (chỉ thủ quỹ) ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        $_SESSION['error'] = 'Bạn chỉ được xem, không thể chỉnh sửa thu chi.';
        header('Location: thu_chi.php');
        exit;
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        $loai = (isset($_POST['loai']) ? $_POST['loai'] : '') === 'chi' ? 'chi' : 'thu';
        $noi_dung = trim(isset($_POST['noi_dung']) ? $_POST['noi_dung'] : '');
        $so_tien = (float)str_replace(['.', ',', ' '], ['', '', ''], isset($_POST['so_tien']) ? $_POST['so_tien'] : '0');
        $ngay = isset($_POST['ngay']) ? $_POST['ngay'] : date('Y-m-d');
        $ghi_chu = trim(isset($_POST['ghi_chu']) ? $_POST['ghi_chu'] : '');
        $phan_loai = trim(isset($_POST['phan_loai']) ? $_POST['phan_loai'] : 'Khác');

        if ($noi_dung === '' || $so_tien <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngay)) {
            $_SESSION['error'] = 'Vui lòng nhập đầy đủ nội dung, số tiền (>0) và ngày hợp lệ.';
        } elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE thuchi_entries SET loai=?, noi_dung=?, so_tien=?, ngay=?, ghi_chu=?, phan_loai=? WHERE id=?");
            $stmt->bind_param('ssdsssi', $loai, $noi_dung, $so_tien, $ngay, $ghi_chu, $phan_loai, $id);
            $stmt->execute() ? $_SESSION['success'] = 'Đã cập nhật khoản mục.' : $_SESSION['error'] = 'Lỗi: ' . $conn->error;
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO thuchi_entries (loai, noi_dung, so_tien, ngay, ghi_chu, phan_loai, created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('ssdsssi', $loai, $noi_dung, $so_tien, $ngay, $ghi_chu, $phan_loai, $user_id);
            $stmt->execute() ? $_SESSION['success'] = 'Đã thêm khoản mục.' : $_SESSION['error'] = 'Lỗi: ' . $conn->error;
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        $stmt = $conn->prepare("DELETE FROM thuchi_entries WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute() ? $_SESSION['success'] = 'Đã xóa khoản mục.' : $_SESSION['error'] = 'Lỗi: ' . $conn->error;
        $stmt->close();
    } elseif ($action === 'add_category' && $can_edit) {
        $ten = trim(isset($_POST['ten']) ? $_POST['ten'] : '');
        if ($ten !== '') {
            $stmt = $conn->prepare("INSERT IGNORE INTO thuchi_categories (ten, sort_order) VALUES (?, 99)");
            $stmt->bind_param('s', $ten);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success'] = 'Đã thêm phân loại.';
        }
    }

    $qs = http_build_query([
        'period' => isset($_POST['period']) ? $_POST['period'] : 'thang',
        'ref' => isset($_POST['ref']) ? $_POST['ref'] : date('Y-m-d'),
        'tab' => isset($_POST['tab']) ? $_POST['tab'] : 'tongquan',
    ]);
    header('Location: thu_chi.php?' . $qs);
    exit;
}

// ── Filters ─────────────────────────────────────────────────────────────────
$period = isset($_GET['period']) ? $_GET['period'] : 'thang';
if (!in_array($period, ['tuan', 'thang', 'quy', 'nam'], true)) {
    $period = 'thang';
}
$ref = isset($_GET['ref']) ? $_GET['ref'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
    $ref = date('Y-m-d');
}
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'tongquan';
if (!in_array($tab, ['tongquan', 'thu', 'chi', 'baocao'], true)) {
    $tab = 'tongquan';
}

$__pr = thuchi_period_range($period, $ref); $date_from = $__pr[0]; $date_to = $__pr[1];
$period_label = thuchi_period_label($period, $date_from, $date_to);

// Categories
$categories = [];
$cr = $conn->query("SELECT ten FROM thuchi_categories ORDER BY sort_order, ten");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $categories[] = $row['ten'];
    }
}
if (!$categories) {
    $categories = thuchi_default_categories();
}

// Entries in period
$entries = [];
$stmt = $conn->prepare("SELECT * FROM thuchi_entries WHERE ngay BETWEEN ? AND ? ORDER BY ngay DESC, id DESC");
if ($stmt) {
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $entries = thuchi_stmt_fetch_all($stmt);
    $stmt->close();
}

$thu_list = [];
$chi_list = [];
foreach ($entries as $e) {
    if ($e['loai'] === 'thu') {
        $thu_list[] = $e;
    } else {
        $chi_list[] = $e;
    }
}

$sum_thu = 0;
$sum_chi = 0;
foreach ($thu_list as $e) {
    $sum_thu += (float)$e['so_tien'];
}
foreach ($chi_list as $e) {
    $sum_chi += (float)$e['so_tien'];
}
$balance_period = $sum_thu - $sum_chi;

// All-time balance (tổng còn lại toàn bộ)
$all = ['tong_thu' => 0, 'tong_chi' => 0];
$all_q = $conn->query("SELECT
    COALESCE(SUM(CASE WHEN loai='thu' THEN so_tien ELSE 0 END),0) AS tong_thu,
    COALESCE(SUM(CASE WHEN loai='chi' THEN so_tien ELSE 0 END),0) AS tong_chi
  FROM thuchi_entries");
if ($all_q) {
    $all = $all_q->fetch_assoc();
}
$all_thu = (float)$all['tong_thu'];
$all_chi = (float)$all['tong_chi'];
$all_balance = $all_thu - $all_chi;

// Xuất Excel theo kỳ (tháng / quý / năm / tuần đang chọn)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    thuchi_export_excel($entries, $period, $date_from, $date_to, $sum_thu, $sum_chi, $all_balance);
}

// Chart: by category
function thuchi_group_by_cat($list) {
    $out = [];
    foreach ($list as $e) {
        $k = $e['phan_loai'] ? $e['phan_loai'] : 'Khác';
        if (!isset($out[$k])) {
            $out[$k] = 0;
        }
        $out[$k] += (float)$e['so_tien'];
    }
    arsort($out);
    return $out;
}
$thu_by_cat = thuchi_group_by_cat($thu_list);
$chi_by_cat = thuchi_group_by_cat($chi_list);

// Chart: timeline buckets
function thuchi_timeline($entries, $period, $from, $to) {
    $buckets = [];
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);

    if ($period === 'nam') {
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%02d/%s', $m, $start->format('Y'));
            $buckets[$key] = ['thu' => 0, 'chi' => 0];
        }
        foreach ($entries as $e) {
            $key = date('m/Y', strtotime($e['ngay']));
            if (!isset($buckets[$key])) continue;
            $buckets[$key][$e['loai']] += (float)$e['so_tien'];
        }
    } elseif ($period === 'quy' || $period === 'thang') {
        $cur = $start;
        while ($cur <= $end) {
            $key = $cur->format('d/m');
            $buckets[$key] = ['thu' => 0, 'chi' => 0];
            $cur = $cur->modify('+1 day');
        }
        // If too many days (month), group by week for quy; for thang keep daily but Chart can handle ~31
        if ($period === 'quy') {
            $buckets = [];
            $cur = $start;
            $i = 1;
            while ($cur <= $end) {
                $wEnd = $cur->modify('+6 days');
                if ($wEnd > $end) {
                    $wEnd = $end;
                }
                $key = 'Tuần ' . $i;
                $fromKey = $cur->format('Y-m-d');
                $toKey = $wEnd->format('Y-m-d');
                $buckets[$key] = ['thu' => 0, 'chi' => 0, '_from' => $fromKey, '_to' => $toKey];
                $cur = $wEnd->modify('+1 day');
                $i++;
            }
            foreach ($entries as $e) {
                foreach ($buckets as $k => &$b) {
                    if ($e['ngay'] >= $b['_from'] && $e['ngay'] <= $b['_to']) {
                        $b[$e['loai']] += (float)$e['so_tien'];
                        break;
                    }
                }
                unset($b);
            }
            foreach ($buckets as &$b) {
                unset($b['_from'], $b['_to']);
            }
            unset($b);
        } else {
            foreach ($entries as $e) {
                $key = date('d/m', strtotime($e['ngay']));
                if (!isset($buckets[$key])) continue;
                $buckets[$key][$e['loai']] += (float)$e['so_tien'];
            }
        }
    } else { // tuan — daily
        $cur = $start;
        while ($cur <= $end) {
            $key = $cur->format('d/m');
            $buckets[$key] = ['thu' => 0, 'chi' => 0];
            $cur = $cur->modify('+1 day');
        }
        foreach ($entries as $e) {
            $key = date('d/m', strtotime($e['ngay']));
            if (!isset($buckets[$key])) continue;
            $buckets[$key][$e['loai']] += (float)$e['so_tien'];
        }
    }
    return $buckets;
}
$timeline = thuchi_timeline($entries, $period, $date_from, $date_to);

$timeline_thu = [];
$timeline_chi = [];
foreach (array_values($timeline) as $b) {
    $timeline_thu[] = $b['thu'];
    $timeline_chi[] = $b['chi'];
}

$flash_ok = isset($_SESSION['success']) ? $_SESSION['success'] : null;
$flash_err = isset($_SESSION['error']) ? $_SESSION['error'] : null;
unset($_SESSION['success'], $_SESSION['error']);

$current_page = 'thu_chi.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng hợp thu chi | TNTT Đaminh Savio</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <style>
        :root {
            --ink: #0f2744;
            --muted: #5a6f86;
            --line: #d7e2ef;
            --surface: #ffffff;
            --bg: #eef3f8;
            --brand: #1f5f8b;
            --brand2: #2a8f7b;
            --thu: #1a7a5c;
            --chi: #c45c3e;
            --gold: #c9a227;
            --shadow: 0 12px 40px rgba(15, 39, 68, 0.08);
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Be Vietnam Pro', sans-serif;
            background:
                radial-gradient(1200px 500px at 10% -10%, rgba(42, 143, 123, 0.12), transparent 60%),
                radial-gradient(900px 400px at 100% 0%, rgba(31, 95, 139, 0.14), transparent 55%),
                var(--bg);
            color: var(--ink);
            min-height: 100vh;
        }
        .tc-nav {
            background: linear-gradient(135deg, #1a4a6e 0%, #1f5f8b 55%, #2a8f7b 100%);
            color: #fff;
            padding: 0.85rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 8px 30px rgba(15, 39, 68, 0.2);
        }
        .tc-nav a { color: rgba(255,255,255,.88); text-decoration: none; font-weight: 500; font-size: .92rem; }
        .tc-nav a:hover, .tc-nav a.active { color: #fff; }
        .tc-nav .brand { font-weight: 700; letter-spacing: .3px; display: flex; align-items: center; gap: .6rem; }
        .tc-nav .brand img { width: 36px; height: 36px; border-radius: 50%; background: #fff; object-fit: contain; }
        .tc-wrap { max-width: 1180px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }

        .tc-hero {
            display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: flex-end;
            margin-bottom: 1.25rem;
        }
        .tc-hero h1 {
            font-family: 'Fraunces', serif;
            font-size: clamp(1.6rem, 3vw, 2.15rem);
            margin: 0 0 .35rem;
            letter-spacing: -0.02em;
        }
        .tc-hero p { margin: 0; color: var(--muted); font-size: .95rem; }
        .badge-role {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .35rem .7rem; border-radius: 999px; font-size: .78rem; font-weight: 600;
            background: rgba(31,95,139,.1); color: var(--brand);
        }
        .badge-role.view { background: rgba(201,162,39,.15); color: #8a6d10; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 900px) { .stat-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 520px) { .stat-grid { grid-template-columns: 1fr; } }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 1.15rem 1.2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .stat-card::after {
            content: '';
            position: absolute; right: -20px; top: -20px;
            width: 90px; height: 90px; border-radius: 50%;
            opacity: .12;
        }
        .stat-card.thu::after { background: var(--thu); }
        .stat-card.chi::after { background: var(--chi); }
        .stat-card.bal::after { background: var(--brand); }
        .stat-card.all::after { background: var(--gold); }
        .stat-card .lbl { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 600; }
        .stat-card .val { font-size: 1.45rem; font-weight: 800; margin-top: .35rem; letter-spacing: -0.02em; }
        .stat-card.thu .val { color: var(--thu); }
        .stat-card.chi .val { color: var(--chi); }
        .stat-card.bal .val { color: var(--brand); }
        .stat-card.all .val { color: #8a6d10; }

        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.25rem;
            margin-bottom: 1.15rem;
        }
        .panel h2 {
            font-size: 1.05rem; font-weight: 700; margin: 0 0 1rem;
            display: flex; align-items: center; gap: .5rem;
        }

        .filters { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; }
        .filters .pill {
            border: 1px solid var(--line);
            background: #f7fafc;
            color: var(--muted);
            border-radius: 999px;
            padding: .45rem .95rem;
            font-size: .85rem; font-weight: 600;
            text-decoration: none;
            transition: .2s;
        }
        .filters .pill:hover { border-color: var(--brand); color: var(--brand); }
        .filters .pill.on { background: var(--brand); color: #fff; border-color: var(--brand); }
        .filters input[type=date] {
            border: 1px solid var(--line); border-radius: 999px; padding: .4rem .85rem;
            font-family: inherit; font-size: .85rem;
        }

        .tabs {
            display: flex; gap: .4rem; flex-wrap: wrap;
            border-bottom: 1px solid var(--line);
            margin-bottom: 1.1rem; padding-bottom: .35rem;
        }
        .tabs a {
            padding: .55rem 1rem; border-radius: 10px 10px 0 0;
            color: var(--muted); text-decoration: none; font-weight: 600; font-size: .9rem;
        }
        .tabs a.on { color: var(--brand); background: rgba(31,95,139,.08); }

        .btn-tc {
            border: none; border-radius: 12px; padding: .55rem 1rem;
            font-weight: 600; font-size: .88rem; font-family: inherit;
            display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
            transition: .2s;
        }
        .btn-tc.primary { background: var(--brand); color: #fff; }
        .btn-tc.primary:hover { filter: brightness(1.08); }
        .btn-tc.soft { background: #eef5fa; color: var(--brand); }
        .btn-tc.danger { background: rgba(196,92,62,.12); color: var(--chi); }
        .btn-tc.gold { background: linear-gradient(135deg, #d4b84a, #c9a227); color: #1a1405; }
        .btn-tc.excel {
            background: linear-gradient(135deg, #2e7d32, #43a047);
            color: #fff;
        }
        .btn-tc.excel:hover { filter: brightness(1.08); color: #fff; }
        .export-row {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
            align-items: center;
            margin-top: .75rem;
            padding-top: .75rem;
            border-top: 1px dashed var(--line);
        }
        .export-row .lbl {
            font-size: .82rem;
            color: var(--muted);
            font-weight: 700;
            margin-right: .15rem;
        }

        .table-wrap { overflow-x: auto; }
        table.tc-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        table.tc-table th {
            text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            color: var(--muted); padding: .65rem .5rem; border-bottom: 2px solid var(--line);
            white-space: nowrap;
        }
        table.tc-table td {
            padding: .7rem .5rem; border-bottom: 1px solid var(--line); vertical-align: top;
        }
        table.tc-table tr:hover td { background: #f8fbfd; }
        .money { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .money.thu { color: var(--thu); }
        .money.chi { color: var(--chi); }
        .tag {
            display: inline-block; padding: .2rem .55rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600; background: #eef5fa; color: var(--brand);
        }
        .empty {
            text-align: center; padding: 2.5rem 1rem; color: var(--muted);
        }

        .chart-grid {
            display: grid; grid-template-columns: 1fr; gap: 1rem;
        }
        .chart-box {
            background: #f8fbfd; border: 1px solid var(--line); border-radius: 14px;
            padding: 1rem; min-height: 280px; position: relative;
        }
        .chart-box h3 { font-size: .9rem; margin: 0 0 .75rem; color: var(--ink); font-weight: 700; }
        .chart-empty {
            min-height: 220px; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            color: var(--muted); background: #fff; border: 1px dashed var(--line);
            border-radius: 12px; padding: 1.5rem;
        }
        .chart-empty i { font-size: 2rem; color: var(--brand); opacity: .7; margin-bottom: .75rem; }
        .chart-empty strong { display: block; color: var(--ink); font-size: 1rem; margin-bottom: .35rem; }
        .chart-empty span { font-size: .88rem; max-width: 260px; line-height: 1.45; }

        .shot-frame {
            background: #fff;
            border-radius: 16px;
            padding: 1.25rem;
        }
        .shot-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1rem; padding-bottom: .85rem; border-bottom: 2px solid var(--line);
        }
        .shot-header .title { font-family: 'Fraunces', serif; font-size: 1.25rem; margin: 0; }
        .shot-header .meta { font-size: .82rem; color: var(--muted); text-align: right; }

        .modal-content { border-radius: 16px; border: none; }
        .form-label { font-size: .82rem; font-weight: 600; color: var(--muted); }
        .form-control, .form-select {
            border-radius: 10px; border-color: var(--line); font-size: .92rem;
        }
        .alert { border-radius: 12px; border: none; }

        .readonly-banner {
            background: linear-gradient(90deg, rgba(201,162,39,.15), rgba(31,95,139,.08));
            border: 1px solid rgba(201,162,39,.35);
            color: #6b5610;
            border-radius: 12px;
            padding: .75rem 1rem;
            font-size: .9rem;
            margin-bottom: 1rem;
        }

        @media print {
            .tc-nav, .no-print { display: none !important; }
            body { background: #fff; }
            .panel, .stat-card { box-shadow: none; }
        }
    </style>
</head>
<body>
<header class="tc-nav no-print">
    <div class="container-fluid px-3 px-md-4 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <a href="index.php" class="brand">
            <img src="img/logo.png" alt="Logo">
            <span>TNTT Đaminh Savio · Thu chi</span>
        </a>
        <nav class="d-flex flex-wrap gap-3 align-items-center">
            <a href="index.php">Trang chủ</a>
            <a href="loichua.php">Lời Chúa</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
        </nav>
    </div>
</header>

<main class="tc-wrap">
    <div class="tc-hero">
        <div>
            <h1>Tổng hợp thu chi</h1>
            <p>Báo cáo quỹ đoàn · <?php echo htmlspecialchars($period_label); ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center no-print">
            <?php if ($can_edit): ?>
                <span class="badge-role"><i class="fas fa-pen"></i> Có quyền nhập liệu</span>
                <button class="btn-tc primary" data-bs-toggle="modal" data-bs-target="#entryModal" onclick="openEntry('thu')">
                    <i class="fas fa-plus"></i> Thêm thu
                </button>
                <button class="btn-tc danger" data-bs-toggle="modal" data-bs-target="#entryModal" onclick="openEntry('chi')">
                    <i class="fas fa-minus"></i> Thêm chi
                </button>
            <?php else: ?>
                <span class="badge-role view"><i class="fas fa-eye"></i> Chỉ xem — không chỉnh sửa</span>
            <?php endif; ?>
            <button class="btn-tc gold" type="button" onclick="captureReport()">
                <i class="fas fa-camera"></i> Chụp ảnh báo cáo
            </button>
        </div>
    </div>

    <?php if (!$can_edit): ?>
    <div class="readonly-banner no-print">
        <i class="fas fa-info-circle me-1"></i>
        Bạn đang xem ở chế độ chỉ đọc. Chỉ tài khoản <strong>thuquy</strong> (thủ quỹ) mới được thêm / sửa / xóa khoản thu chi.
    </div>
    <?php endif; ?>

    <?php if ($flash_ok): ?><div class="alert alert-success no-print"><?php echo htmlspecialchars($flash_ok); ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="alert alert-danger no-print"><?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>

    <div class="panel no-print">
        <form class="filters" method="get" id="filterForm">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <?php foreach (['tuan' => 'Tuần', 'thang' => 'Tháng', 'quy' => 'Quý', 'nam' => 'Năm'] as $k => $label): ?>
                <a class="pill <?php echo $period === $k ? 'on' : ''; ?>"
                   href="?period=<?php echo $k; ?>&ref=<?php echo urlencode($ref); ?>&tab=<?php echo urlencode($tab); ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
            <label class="ms-1" style="font-size:.82rem;color:var(--muted);font-weight:600;">Mốc ngày</label>
            <input type="date" name="ref" value="<?php echo htmlspecialchars($ref); ?>" onchange="this.form.submit()">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
        </form>
        <div class="export-row">
            <span class="lbl"><i class="fas fa-file-excel me-1"></i>Xuất Excel:</span>
            <a class="btn-tc excel" href="?export=excel&period=thang&ref=<?php echo urlencode($ref); ?>&tab=<?php echo urlencode($tab); ?>">
                <i class="fas fa-calendar-alt"></i> Tháng
            </a>
            <a class="btn-tc excel" href="?export=excel&period=quy&ref=<?php echo urlencode($ref); ?>&tab=<?php echo urlencode($tab); ?>">
                <i class="fas fa-calendar"></i> Quý
            </a>
            <a class="btn-tc excel" href="?export=excel&period=nam&ref=<?php echo urlencode($ref); ?>&tab=<?php echo urlencode($tab); ?>">
                <i class="fas fa-calendar-check"></i> Năm
            </a>
            <a class="btn-tc soft" href="?export=excel&period=<?php echo urlencode($period); ?>&ref=<?php echo urlencode($ref); ?>&tab=<?php echo urlencode($tab); ?>" title="Xuất đúng kỳ đang xem">
                <i class="fas fa-download"></i> Kỳ đang xem
            </a>
        </div>
    </div>

    <div id="reportShot" class="shot-frame">
        <div class="shot-header">
            <div>
                <p class="title">Đoàn TNTT Đaminh Savio Lập Trí</p>
                <div style="font-size:.9rem;color:var(--muted);">Báo cáo thu chi · <?php echo htmlspecialchars($period_label); ?></div>
            </div>
            <div class="meta">
                Xuất lúc <?php echo date('d/m/Y H:i'); ?><br>
                Số dư quỹ: <strong><?php echo thuchi_format_money($all_balance); ?></strong>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card thu">
                <div class="lbl">Tổng thu (kỳ)</div>
                <div class="val">+<?php echo thuchi_format_money($sum_thu); ?></div>
            </div>
            <div class="stat-card chi">
                <div class="lbl">Tổng chi (kỳ)</div>
                <div class="val">−<?php echo thuchi_format_money($sum_chi); ?></div>
            </div>
            <div class="stat-card bal">
                <div class="lbl">Chênh lệch kỳ</div>
                <div class="val"><?php echo ($balance_period >= 0 ? '+' : '') . thuchi_format_money($balance_period); ?></div>
            </div>
            <div class="stat-card all">
                <div class="lbl">Tổng còn lại (quỹ)</div>
                <div class="val"><?php echo thuchi_format_money($all_balance); ?></div>
            </div>
        </div>

        <div class="tabs no-print">
            <?php foreach (['tongquan' => 'Tổng quan', 'thu' => 'Thu', 'chi' => 'Chi', 'baocao' => 'Biểu đồ'] as $k => $label): ?>
                <a class="<?php echo $tab === $k ? 'on' : ''; ?>"
                   href="?period=<?php echo urlencode($period); ?>&ref=<?php echo urlencode($ref); ?>&tab=<?php echo $k; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'tongquan' || $tab === 'baocao'): ?>
        <?php $has_chart_data = ($sum_thu > 0 || $sum_chi > 0); ?>
        <div class="chart-box" style="margin-bottom:1rem;">
            <h3>Thu / Chi theo thời gian</h3>
            <?php if ($has_chart_data): ?>
                <canvas id="chartTimeline" height="120"></canvas>
            <?php else: ?>
                <div class="chart-empty">
                    <i class="fas fa-chart-column"></i>
                    <strong>Chưa có dữ liệu để vẽ biểu đồ</strong>
                    <span>Thêm khoản thu hoặc chi trong kỳ này (đăng nhập tài khoản thủ quỹ) để biểu đồ hiện rõ.</span>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        function render_table($list, $loai, $can_edit) {
            if (!$list) {
                echo '<div class="empty"><i class="fas fa-inbox fa-2x mb-2"></i><br>Chưa có khoản ' . ($loai === 'thu' ? 'thu' : 'chi') . ' trong kỳ này.</div>';
                return;
            }
            echo '<div class="table-wrap"><table class="tc-table"><thead><tr>';
            echo '<th>STT</th><th>Nội dung</th><th>Số tiền</th><th>Ngày</th><th>Phân loại</th><th>Ghi chú</th>';
            if ($can_edit) echo '<th class="no-print">Thao tác</th>';
            echo '</tr></thead><tbody>';
            foreach ($list as $i => $e) {
                $cls = $loai === 'thu' ? 'thu' : 'chi';
                echo '<tr>';
                echo '<td>' . ($i + 1) . '</td>';
                echo '<td><strong>' . htmlspecialchars($e['noi_dung']) . '</strong></td>';
                echo '<td class="money ' . $cls . '">' . thuchi_format_money($e['so_tien']) . '</td>';
                echo '<td>' . date('d/m/Y', strtotime($e['ngay'])) . '</td>';
                echo '<td><span class="tag">' . htmlspecialchars($e['phan_loai']) . '</span></td>';
                echo '<td style="color:var(--muted);max-width:220px;">' . htmlspecialchars($e['ghi_chu'] ?: '—') . '</td>';
                if ($can_edit) {
                    $payload = htmlspecialchars(json_encode([
                        'id' => (int)$e['id'],
                        'loai' => $e['loai'],
                        'noi_dung' => $e['noi_dung'],
                        'so_tien' => (string)(int)$e['so_tien'],
                        'ngay' => $e['ngay'],
                        'ghi_chu' => $e['ghi_chu'],
                        'phan_loai' => $e['phan_loai'],
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                    echo '<td class="no-print" style="white-space:nowrap;">';
                    echo '<button type="button" class="btn-tc soft btn-sm" onclick=\'editEntry(' . $payload . ')\'><i class="fas fa-edit"></i></button> ';
                    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Xóa khoản này?\')">';
                    echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$e['id'] . '">';
                    echo '<input type="hidden" name="period" value="' . htmlspecialchars(isset($_GET['period']) ? $_GET['period'] : 'thang') . '">';
                    echo '<input type="hidden" name="ref" value="' . htmlspecialchars(isset($_GET['ref']) ? $_GET['ref'] : date('Y-m-d')) . '">';
                    echo '<input type="hidden" name="tab" value="' . htmlspecialchars(isset($_GET['tab']) ? $_GET['tab'] : 'tongquan') . '">';
                    echo '<button class="btn-tc danger btn-sm" type="submit"><i class="fas fa-trash"></i></button></form>';
                    echo '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        ?>

        <?php if ($tab === 'tongquan' || $tab === 'thu'): ?>
        <div class="panel" style="box-shadow:none;border:1px solid var(--line);">
            <h2><i class="fas fa-arrow-down" style="color:var(--thu)"></i> Khoản thu
                <span class="ms-auto money thu" style="font-size:1rem;"><?php echo thuchi_format_money($sum_thu); ?></span>
            </h2>
            <?php render_table($thu_list, 'thu', $can_edit); ?>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'tongquan' || $tab === 'chi'): ?>
        <div class="panel" style="box-shadow:none;border:1px solid var(--line);">
            <h2><i class="fas fa-arrow-up" style="color:var(--chi)"></i> Khoản chi
                <span class="ms-auto money chi" style="font-size:1rem;"><?php echo thuchi_format_money($sum_chi); ?></span>
            </h2>
            <?php render_table($chi_list, 'chi', $can_edit); ?>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'baocao'): ?>
        <div class="panel" style="box-shadow:none;border:1px solid var(--line);">
            <h2><i class="fas fa-chart-pie"></i> Phân tích phân loại</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <h3 style="font-size:.9rem;color:var(--thu);">Thu theo loại</h3>
                    <?php if (!$thu_by_cat): ?>
                        <div class="empty">Không có dữ liệu</div>
                    <?php else: ?>
                        <table class="tc-table">
                            <thead><tr><th>Phân loại</th><th>Số tiền</th><th>%</th></tr></thead>
                            <tbody>
                            <?php foreach ($thu_by_cat as $cat => $amt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat); ?></td>
                                    <td class="money thu"><?php echo thuchi_format_money($amt); ?></td>
                                    <td><?php echo $sum_thu > 0 ? round($amt / $sum_thu * 100, 1) : 0; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <h3 style="font-size:.9rem;color:var(--chi);">Chi theo loại</h3>
                    <?php if (!$chi_by_cat): ?>
                        <div class="empty">Không có dữ liệu</div>
                    <?php else: ?>
                        <table class="tc-table">
                            <thead><tr><th>Phân loại</th><th>Số tiền</th><th>%</th></tr></thead>
                            <tbody>
                            <?php foreach ($chi_by_cat as $cat => $amt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat); ?></td>
                                    <td class="money chi"><?php echo thuchi_format_money($amt); ?></td>
                                    <td><?php echo $sum_chi > 0 ? round($amt / $sum_chi * 100, 1) : 0; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($can_edit): ?>
<!-- Modal thêm/sửa -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id" value="0">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
            <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="entryModalTitle">Thêm khoản thu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Loại</label>
                    <select name="loai" id="f_loai" class="form-select" required>
                        <option value="thu">Thu</option>
                        <option value="chi">Chi</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nội dung</label>
                    <input type="text" name="noi_dung" id="f_noi_dung" class="form-control" required maxlength="500" placeholder="VD: Thu đóng góp khóa học hè">
                </div>
                <div class="row g-2">
                    <div class="col-6 mb-3">
                        <label class="form-label">Số tiền (đ)</label>
                        <input type="text" name="so_tien" id="f_so_tien" class="form-control" required inputmode="numeric" placeholder="500000">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" name="ngay" id="f_ngay" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phân loại</label>
                    <select name="phan_loai" id="f_phan_loai" class="form-select" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Ghi chú</label>
                    <textarea name="ghi_chu" id="f_ghi_chu" class="form-control" rows="2" placeholder="Tùy chọn"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn-tc soft" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn-tc primary">Lưu</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal thêm phân loại -->
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="add_category">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
            <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-bold">Thêm phân loại</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" name="ten" class="form-control" required placeholder="Tên phân loại mới">
            </div>
            <div class="modal-footer border-0">
                <button class="btn-tc primary" type="submit">Thêm</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const chartColors = {
    thu: '#1a7a5c',
    chi: '#c45c3e'
};

const timeline = <?php echo json_encode([
    'labels' => array_keys($timeline),
    'thu' => $timeline_thu,
    'chi' => $timeline_chi,
], JSON_UNESCAPED_UNICODE); ?>;

function buildCharts() {
    const tl = document.getElementById('chartTimeline');
    if (!tl) return;
    const hasData = (timeline.thu || []).some(v => v > 0) || (timeline.chi || []).some(v => v > 0);
    if (!hasData) return;

    new Chart(tl, {
        type: 'bar',
        data: {
            labels: timeline.labels,
            datasets: [
                { label: 'Thu', data: timeline.thu, backgroundColor: '#1a7a5c', borderRadius: 6, maxBarThickness: 28 },
                { label: 'Chi', data: timeline.chi, backgroundColor: '#c45c3e', borderRadius: 6, maxBarThickness: 28 }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#0f2744', font: { weight: '600', size: 12 }, boxWidth: 14 }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#5a6f86', maxRotation: 45, minRotation: 0, font: { size: 11 } },
                    grid: { color: 'rgba(15,39,68,.06)' }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#5a6f86',
                        font: { size: 11 },
                        callback: v => new Intl.NumberFormat('vi-VN').format(v)
                    },
                    grid: { color: 'rgba(15,39,68,.08)' }
                }
            }
        }
    });
}
buildCharts();

<?php if ($can_edit): ?>
function openEntry(loai) {
    document.getElementById('f_id').value = 0;
    document.getElementById('f_loai').value = loai;
    document.getElementById('f_noi_dung').value = '';
    document.getElementById('f_so_tien').value = '';
    document.getElementById('f_ngay').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('f_ghi_chu').value = '';
    document.getElementById('f_phan_loai').selectedIndex = 0;
    document.getElementById('entryModalTitle').textContent = loai === 'chi' ? 'Thêm khoản chi' : 'Thêm khoản thu';
}
function editEntry(e) {
    document.getElementById('f_id').value = e.id;
    document.getElementById('f_loai').value = e.loai;
    document.getElementById('f_noi_dung').value = e.noi_dung;
    document.getElementById('f_so_tien').value = e.so_tien;
    document.getElementById('f_ngay').value = e.ngay;
    document.getElementById('f_ghi_chu').value = e.ghi_chu || '';
    const sel = document.getElementById('f_phan_loai');
    let found = false;
    for (const opt of sel.options) {
        if (opt.value === e.phan_loai) { opt.selected = true; found = true; break; }
    }
    if (!found && e.phan_loai) {
        const o = new Option(e.phan_loai, e.phan_loai, true, true);
        sel.add(o);
    }
    document.getElementById('entryModalTitle').textContent = 'Sửa khoản ' + (e.loai === 'chi' ? 'chi' : 'thu');
    new bootstrap.Modal(document.getElementById('entryModal')).show();
}
<?php endif; ?>

async function captureReport() {
    const el = document.getElementById('reportShot');
    const btn = event?.currentTarget;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang chụp...'; }
    try {
        const canvas = await html2canvas(el, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false
        });
        const link = document.createElement('a');
        link.download = 'bao-cao-thu-chi-<?php echo date('Ymd-Hi'); ?>.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    } catch (err) {
        alert('Không chụp được ảnh. Thử lại hoặc dùng Print (Ctrl+P).');
        console.error(err);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-camera"></i> Chụp ảnh báo cáo'; }
    }
}
</script>
</body>
</html>
