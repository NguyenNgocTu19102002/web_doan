<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db_connect.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/../danh_sach_huynh_truong.xlsx';
$sheet = IOFactory::load($path)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

echo "=== XLSX rows ===\n";
foreach ($rows as $i => $row) {
    $vals = [];
    foreach ($row as $k => $v) {
        $vals[] = trim((string)$v);
    }
    echo $i . ' | ' . implode(' || ', $vals) . "\n";
}

echo "\n=== users schema ===\n";
$r = $conn->query('DESCRIBE users');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . "\n";
}

echo "\n=== existing users ===\n";
$r = $conn->query('SELECT id, username, role, password FROM users');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== teachers count ===\n";
$r = $conn->query('SELECT COUNT(*) c FROM teachers');
echo $r->fetch_assoc()['c'] . "\n";

echo "\n=== sample teachers ===\n";
$r = $conn->query('SELECT id, ho_ten, ten_thanh FROM teachers LIMIT 10');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== classes ===\n";
$r = $conn->query('SELECT id, ten_lop, nganh, ma_xac_nhan FROM classes ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo $row['id'] . ' | ' . $row['ten_lop'] . ' | ' . $row['nganh'] . ' | code=' . $row['ma_xac_nhan'] . "\n";
}
