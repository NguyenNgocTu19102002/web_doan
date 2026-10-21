<?php
/**
 * Cấu hình môi trường database - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ
 *
 * mode:
 * - 'auto'       : tự chọn theo domain (localhost → local, còn lại → production)
 * - 'local'      : ép dùng XAMPP
 * - 'production' : ép dùng hosting
 */

$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$is_local_host = ($host === '' || $host === 'localhost' || strpos($host, '127.0.0.1') !== false || strpos($host, 'localhost:') === 0);

return [
    // 'auto' = localhost dùng local, domain hosting dùng production
    'mode' => 'auto', // 'auto' | 'local' | 'production'

    // Cấu hình MySQL trên localhost (XAMPP)
    'local' => [
        'db_host'     => 'localhost',
        'db_name'     => 'tntt_lap_tri',
        'db_user'     => 'root',
        'db_password' => '',
    ],

    // Cấu hình MySQL trên hosting (web)
    'production' => [
        'db_host'     => 'localhost',
        'db_name'     => 'o0kgbp36f9sl_tntt_lap_tri',
        'db_user'     => 'o0kgbp36f9sl_sang17',
        'db_password' => 'Sang17092001',
    ],

    // Dùng nội bộ bởi db_connect khi mode = auto
    '_detected' => $is_local_host ? 'local' : 'production',
];
