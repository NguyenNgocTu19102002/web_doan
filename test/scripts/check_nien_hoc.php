<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/academic_year.php';
ay_ensure_schema($conn);

echo "=== Academic Years ===\n";
$r = $conn->query('SELECT * FROM academic_years ORDER BY id');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Classes nien_khoa ===\n";
$r = $conn->query('SELECT nien_khoa, COUNT(*) c FROM classes GROUP BY nien_khoa');
while ($row = $r->fetch_assoc()) {
    echo $row['nien_khoa'] . ' : ' . $row['c'] . "\n";
}

echo "\n=== Snapshot counts ===\n";
$r = $conn->query('SELECT ay.ten_nam_hoc, COUNT(DISTINCT ycs.id) classes, COUNT(yss.id) students
    FROM academic_years ay
    LEFT JOIN year_class_snapshots ycs ON ycs.academic_year_id = ay.id
    LEFT JOIN year_student_snapshots yss ON yss.academic_year_id = ay.id
    GROUP BY ay.id');
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "\n=== Attendance statuses ===\n";
$r = $conn->query('SELECT status, COUNT(*) c FROM attendance GROUP BY status');
while ($row = $r->fetch_assoc()) {
    echo $row['status'] . ' : ' . $row['c'] . "\n";
}

echo "\n=== Grade types ===\n";
$r = $conn->query('SELECT loai_diem, COUNT(*) c FROM grades GROUP BY loai_diem');
while ($row = $r->fetch_assoc()) {
    echo $row['loai_diem'] . ' : ' . $row['c'] . "\n";
}

echo "\n=== year_student_snapshots columns ===\n";
$r = $conn->query('SHOW COLUMNS FROM year_student_snapshots');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}

echo "\n=== year_class_snapshots columns ===\n";
$r = $conn->query('SHOW COLUMNS FROM year_class_snapshots');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}
