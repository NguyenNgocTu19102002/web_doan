<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/huynh_profile.php';

$dirs = [
    __DIR__ . '/../ảnh',
    __DIR__ . '/../ảnh 2',
];
if (isset($argv[1]) && $argv[1] !== '') {
    $dirs = [__DIR__ . '/../' . $argv[1]];
}

$totalMatched = 0;
$totalUnmatched = [];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Skip (not found): " . basename($dir) . "\n";
        continue;
    }
    echo "=== " . basename($dir) . " ===\n";
    $result = ht_profile_sync_avatars_from_folder($conn, $dir);
    if (!$result['ok']) {
        echo $result['message'] . "\n";
        continue;
    }
    echo "Matched: {$result['matched']}\n";
    $totalMatched += (int)$result['matched'];
    if (!empty($result['unmatched'])) {
        echo "Unmatched (" . count($result['unmatched']) . "):\n";
        foreach ($result['unmatched'] as $u) {
            echo " - $u\n";
            $totalUnmatched[] = basename($dir) . '/' . $u;
        }
    }
    echo "\n";
}

echo "Total matched: $totalMatched\n";
if ($totalUnmatched) {
    echo "Total unmatched:\n";
    foreach ($totalUnmatched as $u) {
        echo " - $u\n";
    }
}
