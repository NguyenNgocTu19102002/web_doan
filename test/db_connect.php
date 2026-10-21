<?php
/**
 * Kết nối database — thư mục /test (DB riêng: local=test, hosting=o0kgbp36f9sl_test)
 */

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$config = include __DIR__ . '/db_env.php';
if (!is_array($config)) {
    die('Lỗi cấu hình db_env.php');
}

$mode = isset($config['mode']) ? $config['mode'] : 'local';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
$is_local_host = ($host === '' || $host === 'localhost' || strpos($host, '127.0.0.1') !== false || strpos($host, 'localhost:') === 0);
$is_live_domain = (strpos($host, 'saviolaptri.com') !== false);

if ($is_live_domain) {
    $mode = 'production';
    $is_local_host = false;
} elseif ($mode === 'auto') {
    $mode = isset($config['_detected']) ? $config['_detected'] : ($is_local_host ? 'local' : 'production');
}

if (!$is_local_host && $mode === 'local') {
    $mode = 'production';
}

if (!isset($config[$mode]) || !is_array($config[$mode])) {
    $mode = $is_local_host ? 'local' : 'production';
}
$db_config = $config[$mode];

$hosts_to_try = array();
$hosts_to_try[] = isset($db_config['db_host']) ? $db_config['db_host'] : 'localhost';
if (!in_array('127.0.0.1', $hosts_to_try, true)) {
    $hosts_to_try[] = '127.0.0.1';
}
if (!in_array('localhost', $hosts_to_try, true)) {
    $hosts_to_try[] = 'localhost';
}

$port = isset($db_config['db_port']) ? (int) $db_config['db_port'] : 3306;
$db_name = $db_config['db_name'];
$db_user = $db_config['db_user'];
$db_password = $db_config['db_password'];

$conn = null;
$last_error = '';
$last_errno = 0;
$connected_host = '';

foreach ($hosts_to_try as $try_host) {
    $mysqli = @new mysqli($try_host, $db_user, $db_password, $db_name, $port);
    if ($mysqli && !$mysqli->connect_error) {
        $conn = $mysqli;
        $connected_host = $try_host;
        break;
    }
    if ($mysqli) {
        $last_error = $mysqli->connect_error;
        $last_errno = (int) $mysqli->connect_errno;
        @$mysqli->close();
    } else {
        $last_error = 'Không khởi tạo được mysqli';
        $last_errno = 0;
    }
}

if (!$conn) {
    $db_host = $db_config['db_host'];
    if ($mode === 'local') {
        die("<div style='background:#f8d7da;color:#721c24;padding:20px;border-radius:5px;font-family:Arial'>"
            . "<h3>Loi ket noi database</h3>"
            . "<p>Moi truong: {$mode}</p>"
            . "<p>Host: {$db_host}</p>"
            . "<p>Database: {$db_name}</p>"
            . "<p>User: {$db_user}</p>"
            . "<p>Chi tiet: [{$last_errno}] {$last_error}</p>"
            . "</div>");
    }
    die("<div style='background:#f8d7da;color:#721c24;padding:20px;border-radius:5px;font-family:Arial;text-align:center'>"
        . "<h3>Khong ket noi duoc database</h3>"
        . "<p>Ma loi MySQL: <strong>{$last_errno}</strong></p>"
        . "<p>" . htmlspecialchars($last_error, ENT_QUOTES, 'UTF-8') . "</p>"
        . "<p>Domain: " . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . " &rarr; mode production</p>"
        . "<p>DB: " . htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8') . " / user: " . htmlspecialchars($db_user, ENT_QUOTES, 'UTF-8') . "</p>"
        . "<p>Vao cPanel &rarr; MySQL Databases: kiem tra ten DB, user va password con khop db_env.php khong.</p>"
        . "</div>");
}

$conn->set_charset('utf8mb4');
$db_host = $connected_host;
$db_name = $db_config['db_name'];
$db_user = $db_config['db_user'];
$db_password = $db_config['db_password'];

if (!function_exists('getCurrentEnvironment')) {
    function getCurrentEnvironment() {
        global $mode;
        return $mode;
    }
}
if (!function_exists('getDatabaseInfo')) {
    function getDatabaseInfo() {
        global $db_config;
        return $db_config;
    }
}
if (!function_exists('testConnection')) {
    function testConnection() {
        global $conn;
        return $conn && !$conn->connect_error;
    }
}
