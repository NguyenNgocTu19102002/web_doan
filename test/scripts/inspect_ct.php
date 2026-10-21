<?php
require __DIR__ . '/../db_connect.php';

echo "=== class_teachers ===\n";
$r = $conn->query('DESCRIBE class_teachers');
while ($x = $r->fetch_assoc()) echo $x['Field'] . ' | ' . $x['Type'] . "\n";

echo "\n=== sample class_teachers + class ===\n";
$r = $conn->query("SELECT ct.*, c.ten_lop, c.nganh, t.ho_ten FROM class_teachers ct JOIN classes c ON c.id=ct.class_id JOIN teachers t ON t.id=ct.teacher_id LIMIT 5");
while ($x = $r->fetch_assoc()) print_r($x);

echo "\n=== user NDS17 ===\n";
$r = $conn->query("SELECT u.*, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.so_dien_thoai, t.email, t.loai_huynh FROM users u LEFT JOIN teachers t ON t.id=u.teacher_id WHERE u.username='NDS17'");
print_r($r->fetch_assoc());

echo "\n=== users without teacher_id ===\n";
$r = $conn->query("SELECT COUNT(*) c FROM users WHERE role='huynh_truong' AND (teacher_id IS NULL OR teacher_id=0)");
print_r($r->fetch_assoc());
