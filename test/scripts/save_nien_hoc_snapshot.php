<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/academic_year.php';

ay_ensure_schema($conn);
$current = ay_get_current_year($conn);
echo "Current year: {$current['ten_nam_hoc']} (id={$current['id']})\n";

$result = ay_save_year_snapshot($conn, (int)$current['id'], $current['ten_nam_hoc'], 'Tổng kết năm học 2025-2026');
if (!$result['ok']) {
    echo "ERROR: {$result['message']}\n";
    exit(1);
}

echo "Saved: {$result['class_count']} classes, {$result['student_count']} students\n";

$stats = ay_year_summary_stats($conn, (int)$current['id']);
echo "Stats: classes={$stats['classes']}, students={$stats['students']}, avg_tb={$stats['avg_tb']}\n";

$r = $conn->query("SELECT ten_lop, truong_lop, pho_lop, si_so FROM year_class_snapshots WHERE academic_year_id = {$current['id']} LIMIT 3");
echo "\nSample classes:\n";
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

$r = $conn->query("SELECT ho_ten, diem_15p1, diem_tb, att_co_phep, att_khong_phep, att_di_muon, att_percent FROM year_student_snapshots WHERE academic_year_id = {$current['id']} LIMIT 3");
echo "\nSample students:\n";
while ($row = $r->fetch_assoc()) {
    print_r($row);
}
