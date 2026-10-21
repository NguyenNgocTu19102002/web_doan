<?php
/**
 * Nhập lịch phụng vụ + tải Lời Chúa theo tháng.
 * Usage: php scripts/batch_loichua_months.php --months=7,8,9 --year=2026 [--calendar-only] [--fetch-only] [--delay=600]
 */

if (php_sapi_name() !== 'cli') {
    die("Chạy từ dòng lệnh: php scripts/batch_loichua_months.php --months=7,8,9\n");
}

require_once dirname(__DIR__) . '/db_connect.php';
require_once __DIR__ . '/parse_lich_cong_giao.php';
require_once __DIR__ . '/liturgy_calendar.php';
require_once __DIR__ . '/loichua_parser.php';

$opts = [
    'months' => '7,8,9',
    'year' => '2026',
    'source' => dirname(__DIR__) . '/uploads/lich-cong-giao-0.md',
    'calendar-only' => false,
    'fetch-only' => false,
    'delay' => 600,
    'skip-existing' => true,
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--months=(.+)$/', $arg, $m)) {
        $opts['months'] = $m[1];
    } elseif (preg_match('/^--year=(\d+)$/', $arg, $m)) {
        $opts['year'] = (int)$m[1];
    } elseif (preg_match('/^--source=(.+)$/', $arg, $m)) {
        $opts['source'] = $m[1];
    } elseif ($arg === '--calendar-only') {
        $opts['calendar-only'] = true;
    } elseif ($arg === '--fetch-only') {
        $opts['fetch-only'] = true;
    } elseif (preg_match('/^--delay=(\d+)$/', $arg, $m)) {
        $opts['delay'] = (int)$m[1];
    } elseif ($arg === '--force') {
        $opts['skip-existing'] = false;
    }
}

$months = array_map('intval', explode(',', $opts['months']));
$year = (int)$opts['year'];

if (!file_exists($opts['source'])) {
    $alt = dirname(__DIR__) . '/data/lich-cong-giao-2026.md';
    if (file_exists($alt)) {
        $opts['source'] = $alt;
    } else {
        fwrite(STDERR, "Không tìm thấy file lịch: {$opts['source']}\n");
        exit(1);
    }
}

echo "Nguồn lịch: {$opts['source']}\n";
echo "Tháng: " . implode(', ', $months) . " / $year\n";

$entries = lich_cong_giao_parse_md_file($opts['source'], $months, $year);
echo "Đã parse " . count($entries) . " ngày.\n";

if (empty($entries)) {
    fwrite(STDERR, "Không parse được ngày nào.\n");
    exit(1);
}

$csvPath = dirname(__DIR__) . '/data/liturgy_calendar_' . implode('-', $months) . "_$year.csv";
lich_cong_giao_entries_to_csv($entries, $csvPath);
echo "Đã ghi CSV: $csvPath\n";

if (!$opts['fetch-only']) {
    $imported = lich_cong_giao_import_entries($conn, $entries);
    echo "Đã nhập $imported ngày vào bảng liturgy_calendar.\n";
}

if ($opts['calendar-only']) {
    exit(0);
}

$ok = 0;
$fail = 0;
$skip = 0;
$total = count($entries);

foreach ($entries as $i => $e) {
    $date = $e['ngay'];
    $n = $i + 1;

    if ($opts['skip-existing']) {
        $existing = loichua_load_from_db($conn, $date);
        if ($existing && $existing['gospel'] !== '' && $existing['reading_1'] !== '') {
            $skip++;
            echo "[$n/$total] $date — bỏ qua (đã có)\n";
            continue;
        }
    }

    echo "[$n/$total] $date — đang tải... ";
    $result = loichua_fetch_and_save($conn, $date);
    if (!empty($result['success'])) {
        $ok++;
        echo "OK\n";
    } else {
        $fail++;
        $msg = $result['message'] ?? 'lỗi';
        echo "LỖI: $msg\n";
    }

    if ($opts['delay'] > 0 && $n < $total) {
        usleep($opts['delay'] * 1000);
    }
}

echo "\nHoàn tất: $ok thành công, $fail lỗi, $skip bỏ qua.\n";
