<?php
/**
 * Tạo tài khoản Huynh Trưởng từ danh sách cố định.
 * Username = viết tắt họ tên (chữ cái đầu mỗi từ) + ngày sinh (dd)
 * Ví dụ: Nguyễn Đình Sang - 17/09/2001 → NDS17
 * Mật khẩu mặc định: 1234
 */
require __DIR__ . '/../db_connect.php';

$people = [
    ['Nguyễn Hữu Sơn', '27/10/2003'],
    ['Nguyễn Thị Dịu', '01/01/1990'],
    ['Nguyễn Yến Nhi', '29/08/2008'],
    ['Phan Thảo Nguyên', '31/01/2006'],
    ['Nguyễn Ngọc Nhung', '10/11/2007'],
    ['Phạm Thị Hường', '03/11/2003'],
    ['Bùi Thúy Nga', '11/02/2005'],
    ['Nguyễn Thành Chí', '02/06/2003'],
    ['Vương Văn Trường', '16/12/2007'],
    ['Nguyễn Trần Ngọc Sơn', '22/06/2007'],
    ['Đinh Thị Trang', '04/09/2004'],
    ['Dương Thị Phương Thu', '29/11/2008'],
    ['Phan Thanh Huyền', '02/03/2004'],
    ['Lê Thị Thương Uyển', '21/10/2003'],
    ['Nguyễn Thị Hương Trà', '04/12/2006'],
    ['Nguyễn Thị Thu Huyền', '28/09/2008'],
    ['Nguyễn Thị Tĩnh', '21/06/1968'],
    ['Bùi Thị Khánh Ly', '30/10/2005'],
    ['Nguyễn Ngọc Ánh', '28/10/2008'],
    ['Bùi Thiên Trang', '01/01/2006'],
    ['Nguyễn Như Quỳnh', '09/11/2007'],
    ['Nguyễn Mạnh Hiệu', '10/03/1998'],
    ['Nguyễn Đức Trường', '14/01/2002'],
    ['Nguyễn Ngọc Tú', '13/10/2002'],
    ['Nguyễn Thị Chăm', '01/01/1985'],
    ['Đào Trà My', '04/08/1999'],
    ['Bùi Thị Thoa', '20/10/1991'],
    ['Phạm Thị Trang', '11/08/2000'],
    ['Ngô Quang Luân', '18/08/1995'],
    ['Nguyễn Hoàng Sơn', '04/08/2001'],
    ['Nguyễn Văn Hiếu', '28/02/1994'],
    ['Phạm Tuyết Mai', '26/12/2002'],
    ['Vũ Xuân Sơn', '17/11/1994'],
    ['Kiều Việt Thành', '31/03/2008'],
    ['Nguyễn Thị Ngọc Uyên', '08/05/2002'],
    ['Hoàng Thuỳ Trang', '06/03/2002'],
    ['Nguyễn Thị Huyền', '23/01/2006'],
    ['Nguyễn Ngọc Hải', '24/04/2008'],
    ['Nguyễn Đình Sang', '17/09/2001'],
    ['Bùi Thanh Luân', '01/01/2006'],
    ['Bùi Thu Hà', '03/06/2008'],
    ['Nguyễn Ngọc Hiếu', '19/10/2007'],
    ['Nguyễn Thị Chuyên', '01/01/2000'],
    ['Phạm Thị Hương Giang', '24/07/2004'],
    ['Nguyễn Thành Công', '22/11/2007'],
    ['Nguyễn Thị Tuyết Mai', '29/11/2005'],
    ['Đào Trọng Tiến', '12/06/2001'],
    ['Bùi Thị Thanh Thu', '04/01/2006'],
    ['Bùi Văn Thắng', '26/04/2005'],
    ['Nguyễn Văn Thành', '29/09/2008'],
];

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
    return strtr($str, $map);
}

/** Viết tắt + ngày sinh: Nguyễn Đình Sang 17/09/2001 → NDS17 */
function ht_username_from_name_dob($ho_ten, $dob) {
    $plain = ht_unaccent($ho_ten);
    $parts = preg_split('/\s+/', trim($plain));
    $initials = '';
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $initials .= strtoupper($p[0]);
    }
    $day = '00';
    if (preg_match('/^(\d{1,2})\//', trim($dob), $m)) {
        $day = sprintf('%02d', (int)$m[1]);
    }
    return $initials . $day;
}

// Ensure role enum
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($col && ($info = $col->fetch_assoc())) {
    if (strpos($info['Type'], 'huynh_truong') === false) {
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy','huynh_truong') DEFAULT 'admin'");
    }
}

$has_teacher_id = false;
$tc = $conn->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
if ($tc && $tc->num_rows > 0) {
    $has_teacher_id = true;
} else {
    $conn->query("ALTER TABLE users ADD COLUMN teacher_id INT NULL AFTER full_name");
    $has_teacher_id = true;
}

// Map teachers by unaccented name
$teachers = [];
$tr = $conn->query('SELECT id, ho_ten FROM teachers');
if ($tr) {
    while ($t = $tr->fetch_assoc()) {
        $teachers[ht_unaccent($t['ho_ten'])] = (int)$t['id'];
    }
}

$used = [];
$ur = $conn->query('SELECT username FROM users');
while ($u = $ur->fetch_assoc()) {
    $used[strtolower($u['username'])] = true;
}

$password = '1234';
$role = 'huynh_truong';
$created = 0;
$updated = 0;
$credentials = [];

foreach ($people as $row) {
    list($ho_ten, $ngay_sinh) = $row;
    $base = ht_username_from_name_dob($ho_ten, $ngay_sinh);
    $teacher_id = isset($teachers[ht_unaccent($ho_ten)]) ? $teachers[ht_unaccent($ho_ten)] : null;

    // Prefer match by teacher_id, then by full_name, then create
    $existing = null;
    if ($teacher_id) {
        $stmt = $conn->prepare('SELECT id, username FROM users WHERE teacher_id = ? LIMIT 1');
        $stmt->bind_param('i', $teacher_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $existing = $res->fetch_assoc();
        }
        $stmt->close();
    }
    if (!$existing) {
        $stmt = $conn->prepare('SELECT id, username FROM users WHERE full_name = ? AND role = ? LIMIT 1');
        $stmt->bind_param('ss', $ho_ten, $role);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $existing = $res->fetch_assoc();
        }
        $stmt->close();
    }

    if ($existing) {
        $username = $base;
        // If updating username, ensure unique (allow keeping same id's username)
        $n = 2;
        $try = $username;
        while (isset($used[strtolower($try)]) && strtolower($try) !== strtolower($existing['username'])) {
            $try = $base . $n;
            $n++;
        }
        $username = $try;

        if ($has_teacher_id && $teacher_id) {
            $stmt = $conn->prepare('UPDATE users SET username = ?, password = ?, role = ?, full_name = ?, teacher_id = ? WHERE id = ?');
            $stmt->bind_param('ssssii', $username, $password, $role, $ho_ten, $teacher_id, $existing['id']);
        } else {
            $stmt = $conn->prepare('UPDATE users SET username = ?, password = ?, role = ?, full_name = ? WHERE id = ?');
            $stmt->bind_param('ssssi', $username, $password, $role, $ho_ten, $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        unset($used[strtolower($existing['username'])]);
        $used[strtolower($username)] = true;
        $updated++;
        $action = 'updated';
    } else {
        $username = $base;
        $n = 2;
        while (isset($used[strtolower($username)])) {
            $username = $base . $n;
            $n++;
        }

        if ($has_teacher_id && $teacher_id) {
            $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name, teacher_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssi', $username, $password, $role, $ho_ten, $teacher_id);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $username, $password, $role, $ho_ten);
        }
        if (!$stmt->execute()) {
            echo "ERR {$ho_ten}: {$stmt->error}\n";
            $stmt->close();
            continue;
        }
        $stmt->close();
        $used[strtolower($username)] = true;
        $created++;
        $action = 'created';
    }

    $credentials[] = [
        'ho_ten' => $ho_ten,
        'ngay_sinh' => $ngay_sinh,
        'username' => $username,
        'password' => $password,
        'teacher_id' => $teacher_id,
        'action' => $action,
    ];
}

$out = __DIR__ . '/../tai_khoan_huynh_truong.csv';
$fp = fopen($out, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Ho ten', 'Ngay sinh', 'Username', 'Password', 'Teacher ID', 'Action']);
foreach ($credentials as $c) {
    fputcsv($fp, [$c['ho_ten'], $c['ngay_sinh'], $c['username'], $c['password'], $c['teacher_id'], $c['action']]);
}
fclose($fp);

echo "Created: $created | Updated: $updated | Total: " . count($credentials) . "\n";
echo "CSV: tai_khoan_huynh_truong.csv\n\n";
foreach ($credentials as $c) {
    echo "{$c['ho_ten']} | {$c['username']} / {$c['password']} | {$c['action']}\n";
}
