<?php
require __DIR__ . '/../db_connect.php';

echo "=== users schema ===\n";
$r = $conn->query('DESCRIBE users');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' ' . $row['Type'] . "\n";
}

echo "\n=== existing users ===\n";
$r = $conn->query('SELECT * FROM users');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== teachers sample ===\n";
$r = $conn->query('SELECT id, ho_ten, ten_thanh FROM teachers ORDER BY ho_ten LIMIT 20');
while ($row = $r->fetch_assoc()) {
    echo $row['id'] . ' | ' . ($row['ten_thanh'] ?: '') . ' ' . $row['ho_ten'] . "\n";
}

echo "\n=== teacher count ===\n";
echo $conn->query('SELECT COUNT(*) c FROM teachers')->fetch_assoc()['c'] . "\n";

echo "\n=== classes ===\n";
$r = $conn->query('SELECT id, ten_lop, nganh, ma_xac_nhan FROM classes ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo $row['id'] . ' | ' . $row['ten_lop'] . ' | code=' . $row['ma_xac_nhan'] . "\n";
}
