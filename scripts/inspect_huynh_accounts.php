<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

echo "=== users columns ===\n";
$r = $conn->query('SHOW COLUMNS FROM users');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}

echo "\n=== existing users ===\n";
$r = $conn->query('SELECT id, username, role, password FROM users ORDER BY id');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== teachers sample ===\n";
$r = $conn->query('SELECT id, ho_ten, ten_thanh, so_dien_thoai FROM teachers ORDER BY ho_ten LIMIT 20');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}
echo "teachers count: " . $conn->query('SELECT COUNT(*) c FROM teachers')->fetch_assoc()['c'] . "\n";

echo "\n=== XLSX ===\n";
$spreadsheet = IOFactory::load(__DIR__ . '/../danh_sach_huynh_truong.xlsx');
$sheet = $spreadsheet->getActiveSheet();
foreach ($sheet->toArray(null, true, true, true) as $i => $row) {
    $vals = array_map(function ($v) {
        return $v === null ? '' : trim((string)$v);
    }, $row);
    echo $i . ' | ' . implode(' || ', $vals) . "\n";
}
