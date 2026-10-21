<?php
require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../includes/huynh_profile.php';

$r = $conn->query("SELECT u.username, t.ho_ten, t.ten_thanh, t.avatar FROM users u JOIN teachers t ON t.id=u.teacher_id WHERE u.username='NDS17' OR t.ho_ten LIKE '%Khánh Ly%'");
while ($x = $r->fetch_assoc()) {
    echo $x['username'] . ' | ' . $x['ten_thanh'] . ' ' . $x['ho_ten'] . ' => ' . ($x['avatar'] ?: '(none)') . "\n";
}

$dir = __DIR__ . '/../ảnh';
foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (stripos($f, 'Khanh') !== false || stripos(ht_profile_unaccent($f), 'khanh') !== false) {
        $path = $dir . DIRECTORY_SEPARATOR . $f;
        $fh = fopen($path, 'rb');
        $head = bin2hex(fread($fh, 12));
        fclose($fh);
        echo "Anna file: [$f] size=" . filesize($path) . " head=$head\n";
        // FF D8 FF = jpeg, 89 50 4E 47 = png
        if (strpos($head, 'ffd8ff') === 0) {
            $new = $dir . DIRECTORY_SEPARATOR . $f . '.jpg';
            if (!file_exists($new)) {
                rename($path, $new);
                echo "Renamed to .jpg\n";
            }
        } elseif (strpos($head, '89504e47') === 0) {
            $new = $dir . DIRECTORY_SEPARATOR . $f . '.png';
            if (!file_exists($new)) {
                rename($path, $new);
                echo "Renamed to .png\n";
            }
        }
    }
}
