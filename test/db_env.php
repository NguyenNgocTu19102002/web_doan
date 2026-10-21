<?php
/**
 * Cấu hình database — bản TEST (/test)
 * Tách riêng DB với trang chính; user/password hosting giống production chính.
 *
 * mode:
 * - 'auto'       : localhost → local (DB test), hosting → production (DB o0kgbp36f9sl_test)
 * - 'local'      : ép XAMPP
 * - 'production' : ép hosting test DB
 */

$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$is_local_host = ($host === '' || $host === 'localhost' || strpos($host, '127.0.0.1') !== false || strpos($host, 'localhost:') === 0);

return [
    'mode' => 'auto',

    // Local XAMPP — database riêng cho môi trường test
    'local' => [
        'db_host'     => 'localhost',
        'db_name'     => 'test',
        'db_user'     => 'root',
        'db_password' => '',
    ],

    // Hosting — database test (user/mk giống trang chính)
    'production' => [
        'db_host'     => 'localhost',
        'db_name'     => 'o0kgbp36f9sl_test',
        'db_user'     => 'o0kgbp36f9sl_sang17',
        'db_password' => 'Sang17092001',
    ],

    '_detected' => $is_local_host ? 'local' : 'production',
];
