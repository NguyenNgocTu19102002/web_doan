<?php
/**
 * Sao lưu bảng Lời Chúa ra file SQL riêng (tách khỏi DB chính).
 *
 * Usage:
 *   php scripts/export_loichua_backup.php
 *   php scripts/export_loichua_backup.php --out=sql/loichua
 */

if (php_sapi_name() !== 'cli') {
    die("Chạy từ dòng lệnh.\n");
}

require_once dirname(__DIR__) . '/db_connect.php';

$outDir = dirname(__DIR__) . '/sql/loichua';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outDir = $m[1];
        if (!preg_match('/^[A-Za-z]:\\\\|^\//', $outDir)) {
            $outDir = dirname(__DIR__) . '/' . ltrim($outDir, '/\\');
        }
    }
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$config = require dirname(__DIR__) . '/db_env.php';
$db = $config[$config['mode']];
$dbName = $db['db_name'];
$host = $db['db_host'];
$user = $db['db_user'];
$pass = $db['db_password'];
$port = isset($db['db_port']) ? (int)$db['db_port'] : 3306;

$tables = ['loichua_daily', 'liturgy_calendar'];
$ts = date('Y-m-d_His');

$mysqldump = 'mysqldump';
$candidates = [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
];
foreach ($candidates as $path) {
    if (file_exists($path)) {
        $mysqldump = $path;
        break;
    }
}

$files = [
    "loichua_backup_{$ts}.sql" => false,
    'loichua_latest.sql' => false,
    'loichua_schema.sql' => true,
];

foreach ($files as $name => $schemaOnly) {
    $target = $outDir . DIRECTORY_SEPARATOR . $name;
    $args = [$mysqldump, '-h', $host, '-P', (string)$port, '-u', $user];
    if ($pass !== '') {
        $args[] = '-p' . $pass;
    }
    $args = array_merge($args, [
        '--no-tablespaces', '--single-transaction', '--routines=false', '--triggers=false',
    ]);
    if ($schemaOnly) {
        $args[] = '--no-data';
    }
    $args[] = $dbName;
    foreach ($tables as $tbl) {
        $args[] = $tbl;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $target, 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($args, $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Không chạy được mysqldump: $name\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0 || !is_file($target) || filesize($target) < 100) {
        fwrite(STDERR, "Lỗi export $name (code $code): $stderr\n");
        exit(1);
    }

    echo "Đã ghi: $target (" . number_format(filesize($target)) . " bytes)\n";
}

$counts = [];
foreach ($tables as $tbl) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$tbl`");
    $counts[$tbl] = $res ? (int)$res->fetch_assoc()['c'] : 0;
}

$manifest = $outDir . DIRECTORY_SEPARATOR . 'manifest.txt';
$lines = [
    'Lời Chúa — sao lưu SQL',
    'Thời gian: ' . date('Y-m-d H:i:s'),
    'Database nguồn: ' . $dbName . '@' . $host,
    'Bảng: ' . implode(', ', $tables),
    'loichua_daily: ' . $counts['loichua_daily'] . ' dòng',
    'liturgy_calendar: ' . $counts['liturgy_calendar'] . ' dòng',
    '',
    'File dùng import: loichua_latest.sql (đủ cấu trúc + dữ liệu)',
    'File chỉ cấu trúc: loichua_schema.sql',
    '',
    'Import sang DB khác:',
    '  mysql -u USER -p TEN_DB_MOI < sql/loichua/loichua_latest.sql',
    '  hoặc: php scripts/import_loichua_backup.php --db=TEN_DB_MOI',
];
file_put_contents($manifest, implode(PHP_EOL, $lines) . PHP_EOL);
echo "Đã ghi: $manifest\n";

$conn->close();
