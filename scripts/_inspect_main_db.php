<?php
$_SERVER['HTTP_HOST'] = 'localhost';
chdir(dirname(__DIR__, 2));
require 'db_connect.php';
$tables = [];
$r = $conn->query('SHOW TABLES');
while ($row = $r->fetch_row()) {
    $tables[] = $row[0];
}
echo "DB: $db_name\n";
foreach ($tables as $t) {
    $c = $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
    echo "$t: $c\n";
}
