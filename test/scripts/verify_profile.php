<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/huynh_profile.php';
$r = $conn->query("SELECT id FROM users WHERE username='NDS17' LIMIT 1");
$u = $r->fetch_assoc();
$p = ht_profile_get_by_user_id($conn, (int)$u['id']);
echo "username={$p['username']}\n";
echo "ten_thanh={$p['ten_thanh']}\n";
echo "ho_ten={$p['ho_ten']}\n";
echo "ngay_sinh={$p['ngay_sinh']}\n";
echo "sdt={$p['so_dien_thoai']}\n";
echo "email={$p['email']}\n";
echo "loai={$p['loai_huynh']}\n";
echo "lop={$p['lop_phu_trach']}\n";
echo "nganh={$p['nganh_phu_trach']}\n";
echo "vai_tro={$p['vai_tro_lop']}\n";
echo "lop_dang_day={$p['lop_dang_day']}\n";
