<?php
require __DIR__ . '/../db_connect.php';
echo "=== users ===\n";
$r = $conn->query('DESCRIBE users');
while ($x = $r->fetch_assoc()) echo $x['Field'] . ' | ' . $x['Type'] . "\n";
echo "\n=== teachers ===\n";
$r = $conn->query('DESCRIBE teachers');
while ($x = $r->fetch_assoc()) echo $x['Field'] . ' | ' . $x['Type'] . "\n";
echo "\n=== sample teacher ===\n";
$r = $conn->query('SELECT * FROM teachers LIMIT 1');
print_r($r->fetch_assoc());
echo "\n=== sample user ===\n";
$r = $conn->query("SELECT id,username,role,full_name,teacher_id FROM users WHERE role='huynh_truong' LIMIT 1");
print_r($r->fetch_assoc());
echo "\n=== tables like huynh ===\n";
$r = $conn->query("SHOW TABLES");
while ($x = $r->fetch_array()) echo $x[0] . "\n";
