<?php
/**
 * Kiem tra ket noi DB tren hosting. XOA file nay sau khi xong.
 * https://saviolaptri.com/check_db.php?k=tntt2026
 */
header('Content-Type: text/plain; charset=utf-8');
if (!isset($_GET['k']) || $_GET['k'] !== 'tntt2026') {
    http_response_code(404);
    echo "Not found";
    exit;
}

echo "PHP " . PHP_VERSION . "\n";
echo "HTTP_HOST=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . "\n";
echo "mysqli=" . (extension_loaded('mysqli') ? 'yes' : 'NO') . "\n";

$config = include __DIR__ . '/db_env.php';
echo "db_env_mode=" . (isset($config['mode']) ? $config['mode'] : '?') . "\n";
echo "prod_db=" . $config['production']['db_name'] . "\n";
echo "prod_user=" . $config['production']['db_user'] . "\n";

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$hosts = array('localhost', '127.0.0.1');
foreach ($hosts as $h) {
    $m = @new mysqli(
        $h,
        $config['production']['db_user'],
        $config['production']['db_password'],
        $config['production']['db_name'],
        3306
    );
    if ($m && !$m->connect_error) {
        echo "CONNECT OK host={$h} db=" . $config['production']['db_name'] . "\n";
        $r = @$m->query('SELECT COUNT(*) c FROM users');
        if ($r) {
            $row = $r->fetch_assoc();
            echo "users_count=" . $row['c'] . "\n";
        } else {
            echo "users query fail: " . $m->error . "\n";
        }
        $m->close();
    } else {
        $errno = $m ? $m->connect_errno : 0;
        $err = $m ? $m->connect_error : 'no mysqli';
        echo "CONNECT FAIL host={$h} errno={$errno} err={$err}\n";
    }
}

echo "DONE\n";
