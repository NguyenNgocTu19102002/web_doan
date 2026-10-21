<?php
/**
 * Probe tạm — XÓA ngay sau khi kiểm tra hosting.
 * Mở: https://saviolaptri.com/_probe_tk.php?k=tntt2026
 */
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_GET['k']) || $_GET['k'] !== 'tntt2026') {
    http_response_code(404);
    echo "Not found";
    exit;
}

echo "PHP " . PHP_VERSION . "\n";
echo "mysqli: " . (extension_loaded('mysqli') ? 'yes' : 'NO') . "\n";
echo "mysqlnd: " . (defined('MYSQLND_VERSION') || function_exists('mysqli_fetch_all') ? 'yes' : 'maybe-no') . "\n";
echo "mbstring: " . (extension_loaded('mbstring') ? 'yes' : 'NO') . "\n";
echo "get_result: " . (method_exists('mysqli_stmt', 'get_result') ? 'yes' : 'NO') . "\n";

try {
    require_once __DIR__ . '/db_connect.php';
    echo "db_connect: OK mode=" . (function_exists('getCurrentEnvironment') ? getCurrentEnvironment() : '?') . "\n";
    echo "db_name: " . (isset($db_name) ? $db_name : '?') . "\n";
} catch (Throwable $e) {
    echo "db_connect FAIL: " . $e->getMessage() . "\n";
    exit;
}

try {
    require_once __DIR__ . '/includes/huynh_profile.php';
    echo "huynh_profile: OK\n";
} catch (Throwable $e) {
    echo "huynh_profile FAIL: " . $e->getMessage() . "\n";
}

$r = @$conn->query("SELECT id, username, role FROM users LIMIT 1");
if ($r) {
    $u = $r->fetch_assoc();
    echo "users query: OK sample=" . ($u['username'] ?? '') . " role=" . ($u['role'] ?? '') . "\n";
} else {
    echo "users query FAIL: " . $conn->error . "\n";
}

$cols = @$conn->query("SHOW COLUMNS FROM teachers");
if ($cols) {
    $names = [];
    while ($c = $cols->fetch_assoc()) {
        $names[] = $c['Field'];
    }
    echo "teachers cols: " . implode(',', $names) . "\n";
    foreach (['avatar', 'lop_phu_trach', 'nganh_phu_trach', 'vai_tro_lop', 'dia_chi'] as $need) {
        echo "col $need: " . (in_array($need, $names, true) ? 'yes' : 'NO') . "\n";
    }
} else {
    echo "SHOW COLUMNS FAIL: " . $conn->error . "\n";
}

echo "bo_nhiem json: " . (is_file(__DIR__ . '/data/bo_nhiem_lop.json') ? 'yes' : 'NO') . "\n";
echo "DONE\n";
