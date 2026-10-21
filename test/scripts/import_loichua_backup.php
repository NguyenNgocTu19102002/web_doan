<?php
/**
 * Import file SQL Lời Chúa vào database (cùng hoặc khác DB).
 *
 * Usage:
 *   php scripts/import_loichua_backup.php
 *   php scripts/import_loichua_backup.php --file=sql/loichua/loichua_latest.sql
 *   php scripts/import_loichua_backup.php --db=ten_database_khac
 */

if (php_sapi_name() !== 'cli') {
    die("Chạy từ dòng lệnh.\n");
}

$file = dirname(__DIR__) . '/sql/loichua/loichua_latest.sql';
$targetDb = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
        if (!preg_match('/^[A-Za-z]:\\\\|^\//', $file)) {
            $file = dirname(__DIR__) . '/' . ltrim($file, '/\\');
        }
    } elseif (preg_match('/^--db=(.+)$/', $arg, $m)) {
        $targetDb = $m[1];
    }
}

if (!is_file($file)) {
    fwrite(STDERR, "Không tìm thấy file: $file\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/db_env.php';
$db = $config[$config['mode']];
$dbName = $targetDb ?: $db['db_name'];

$conn = new mysqli(
    $db['db_host'],
    $db['db_user'],
    $db['db_password'],
    $dbName,
    isset($db['db_port']) ? (int)$db['db_port'] : 3306
);

if ($conn->connect_error) {
    fwrite(STDERR, 'Kết nối thất bại: ' . $conn->connect_error . PHP_EOL);
    exit(1);
}

$conn->set_charset('utf8mb4');

$sql = file_get_contents($file);
if ($sql === false || $sql === '') {
    fwrite(STDERR, "File SQL rỗng hoặc không đọc được.\n");
    exit(1);
}

if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
    $sql = substr($sql, 3);
}

$conn->query('SET FOREIGN_KEY_CHECKS=0');
$conn->query('SET NAMES utf8mb4');

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
}

if ($conn->error) {
    fwrite(STDERR, 'Lỗi import: ' . $conn->error . PHP_EOL);
    $conn->close();
    exit(1);
}

$conn->query('SET FOREIGN_KEY_CHECKS=1');

foreach (['loichua_daily', 'liturgy_calendar'] as $tbl) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$tbl`");
    if ($res) {
        echo "$tbl: " . (int)$res->fetch_assoc()['c'] . " dòng\n";
    }
}

echo "Import xong vào database: $dbName\n";
$conn->close();
