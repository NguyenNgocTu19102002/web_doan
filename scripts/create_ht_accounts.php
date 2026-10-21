<?php
/**
 * Tạo tài khoản đăng nhập cho mỗi Huynh Trưởng từ danh_sach_huynh_truong.xlsx
 * Username ưu tiên số điện thoại; nếu không có thì dùng họ tên không dấu.
 * Mật khẩu mặc định: ngày sinh ddmmyyyy; nếu không có thì Ht@2026
 */
require __DIR__ . '/../db_connect.php';

function ht_cell($row, $col) {
    if (!isset($row->c)) {
        return '';
    }
    foreach ($row->c as $c) {
        $ref = (string)$c['r'];
        if (preg_match('/^' . preg_quote($col, '/') . '\d+$/', $ref)) {
            if (isset($c->is->t)) {
                return html_entity_decode((string)$c->is->t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if (isset($c->v)) {
                return (string)$c->v;
            }
        }
    }
    return '';
}

function ht_unaccent($str) {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $map = [
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
        'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
        'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
        'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
        'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
        'đ'=>'d',
    ];
    $str = strtr($str, $map);
    $str = preg_replace('/[^a-z0-9]+/', '', $str);
    return $str;
}

function ht_parse_date($s) {
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

function ht_password_from_dob($dob_display) {
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($dob_display), $m)) {
        return sprintf('%02d%02d%04d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return 'Ht@2026';
}

// Ensure schema
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($col && ($info = $col->fetch_assoc())) {
    if (strpos($info['Type'], 'huynh_truong') === false) {
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy','huynh_truong') DEFAULT 'admin'");
    }
}
$has_teacher_id = false;
$tc = $conn->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
if (!$tc || $tc->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN teacher_id INT NULL AFTER full_name");
}
$has_teacher_id = true;

// Parse xlsx
$xlsx = __DIR__ . '/../danh_sach_huynh_truong.xlsx';
$zip = new ZipArchive();
if ($zip->open($xlsx) !== true) {
    die("Cannot open xlsx\n");
}
$xml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
$sx = simplexml_load_string($xml);
$sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
$rows = $sx->xpath('//m:sheetData/m:row');

$people = [];
foreach ($rows as $row) {
    $rnum = (int)$row['r'];
    if ($rnum <= 1) {
        continue;
    }
    $ten_thanh = trim(ht_cell($row, 'B'));
    $ho_ten = trim(ht_cell($row, 'C'));
    if ($ho_ten === '') {
        continue;
    }
    $ngay_sinh = trim(ht_cell($row, 'D'));
    $gioi_tinh = trim(ht_cell($row, 'E'));
    $lop = trim(ht_cell($row, 'F'));
    $nganh = trim(ht_cell($row, 'G'));
    $cap_bac = trim(ht_cell($row, 'H'));
    $sdt = preg_replace('/\D+/', '', trim(ht_cell($row, 'I')));
    $email = trim(ht_cell($row, 'J'));
    $vai_tro = trim(ht_cell($row, 'K'));
    $people[] = compact('ten_thanh', 'ho_ten', 'ngay_sinh', 'gioi_tinh', 'lop', 'nganh', 'cap_bac', 'sdt', 'email', 'vai_tro');
}

echo 'Parsed ' . count($people) . " people from xlsx\n";

// Load teachers for matching
$teachers = [];
$tr = $conn->query('SELECT id, ho_ten, ten_thanh FROM teachers');
while ($t = $tr->fetch_assoc()) {
    $key = ht_unaccent($t['ho_ten']);
    $teachers[$key] = $t;
}

$created = 0;
$updated = 0;
$skipped = 0;
$used_usernames = [];
$credentials = [];

// Prefill used usernames
$ur = $conn->query('SELECT username FROM users');
while ($u = $ur->fetch_assoc()) {
    $used_usernames[strtolower($u['username'])] = true;
}

foreach ($people as $p) {
    $full_name = trim(($p['ten_thanh'] ? $p['ten_thanh'] . ' ' : '') . $p['ho_ten']);
    $teacher_id = null;
    $key = ht_unaccent($p['ho_ten']);
    if (isset($teachers[$key])) {
        $teacher_id = (int)$teachers[$key]['id'];
    }

    // username
    if ($p['sdt'] !== '' && strlen($p['sdt']) >= 9) {
        $base_user = $p['sdt'];
    } else {
        $base_user = ht_unaccent($p['ho_ten']);
        if ($base_user === '') {
            $base_user = 'ht' . ($created + $updated + 1);
        }
    }
    $username = $base_user;
    $i = 2;
    while (isset($used_usernames[strtolower($username)])) {
        // nếu đã tồn tại cùng người (teacher_id) thì update
        break;
    }

    $password = ht_password_from_dob($p['ngay_sinh']);
    $dob_sql = ht_parse_date($p['ngay_sinh']);

    // Check existing by username or teacher_id
    $existing = null;
    $stmt = $conn->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $existing = $res->fetch_assoc();
    }
    $stmt->close();

    if (!$existing && $teacher_id) {
        $stmt = $conn->prepare('SELECT id, username FROM users WHERE teacher_id = ? LIMIT 1');
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $existing = $res->fetch_assoc();
        }
        $stmt->close();
    }

    // Ensure unique username if creating new
    if (!$existing) {
        $try = $base_user;
        $n = 2;
        while (isset($used_usernames[strtolower($try)])) {
            $try = $base_user . $n;
            $n++;
        }
        $username = $try;
    } else {
        $username = $existing['username'];
    }

    $role = 'huynh_truong';

    if ($existing) {
        if ($has_teacher_id) {
            $stmt = $conn->prepare('UPDATE users SET password = ?, role = ?, full_name = ?, teacher_id = ? WHERE id = ?');
            $stmt->bind_param('sssii', $password, $role, $full_name, $teacher_id, $existing['id']);
        } else {
            $stmt = $conn->prepare('UPDATE users SET password = ?, role = ?, full_name = ? WHERE id = ?');
            $stmt->bind_param('sssi', $password, $role, $full_name, $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        $updated++;
        $action = 'updated';
    } else {
        if ($has_teacher_id) {
            $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name, teacher_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssi', $username, $password, $role, $full_name, $teacher_id);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $username, $password, $role, $full_name);
        }
        if (!$stmt->execute()) {
            echo "ERR insert {$full_name}: {$stmt->error}\n";
            $skipped++;
            $stmt->close();
            continue;
        }
        $stmt->close();
        $created++;
        $action = 'created';
        $used_usernames[strtolower($username)] = true;
    }

    $credentials[] = [
        'full_name' => $full_name,
        'lop' => $p['lop'],
        'vai_tro' => $p['vai_tro'],
        'username' => $username,
        'password' => $password,
        'sdt' => $p['sdt'],
        'teacher_id' => $teacher_id,
        'action' => $action,
    ];
}

$out = __DIR__ . '/../tai_khoan_huynh_truong.csv';
$fp = fopen($out, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Ho ten', 'Lop (file)', 'Vai tro', 'Username', 'Password', 'SDT', 'Teacher ID', 'Action']);
foreach ($credentials as $c) {
    fputcsv($fp, [$c['full_name'], $c['lop'], $c['vai_tro'], $c['username'], $c['password'], $c['sdt'], $c['teacher_id'], $c['action']]);
}
fclose($fp);

echo "Created: $created\nUpdated: $updated\nSkipped: $skipped\n";
echo "Credentials saved to: tai_khoan_huynh_truong.csv\n";

echo "\n=== Sample ===\n";
foreach (array_slice($credentials, 0, 8) as $c) {
    echo "{$c['full_name']} | {$c['username']} / {$c['password']} | {$c['action']}\n";
}
