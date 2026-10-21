<?php
/**
 * Đồng bộ hồ sơ teachers từ data/huynh_truong_list.json (xuất từ danh_sach_huynh_truong.xlsx)
 */
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/huynh_profile.php';

$result = ht_profile_sync_from_list($conn);
if (!$result['ok']) {
    echo $result['message'] . "\n";
    exit(1);
}
echo "Updated teachers: {$result['updated']}\n";
if (!empty($result['missing'])) {
    echo "Missing in teachers table (" . count($result['missing']) . "):\n";
    foreach ($result['missing'] as $name) {
        echo " - $name\n";
    }
}
