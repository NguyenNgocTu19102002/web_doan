<?php
// PHP 5.6 compatible. No database, session or HTTP side effects.
function fa_context($user_id, $class_id, $date, $activity, $today) {
    $dt = is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) ? DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Ho_Chi_Minh')) : false;
    if (!$dt || $dt->format('Y-m-d') !== $date || $date > $today || $user_id <= 0 || $class_id <= 0) {
        throw new InvalidArgumentException('Ngày hoặc lớp không hợp lệ (không chọn ngày tương lai).');
    }
    if (!in_array($activity, array('Học Giáo Lý', 'Đi lễ'), true)) {
        throw new InvalidArgumentException('Hoạt động không hợp lệ.');
    }
    return array('user_id'=>(int)$user_id, 'class_id'=>(int)$class_id, 'date'=>$date, 'activity'=>$activity);
}

function fa_authorize($user_id, $manager, $leader, $expected_csrf, $actual_csrf) {
    if (!$user_id || (!$manager && !$leader)) {
        throw new RuntimeException('Bạn không có quyền thao tác lớp này.', 403);
    }
    if (!is_string($actual_csrf) || !is_string($expected_csrf) || $expected_csrf === '' || !hash_equals($expected_csrf, $actual_csrf)) {
        throw new RuntimeException('Phiên xác thực không hợp lệ. Hãy tải lại trang.', 403);
    }
    return true;
}

function fa_random_token() {
    $strong = false;
    $bytes = openssl_random_pseudo_bytes(32, $strong);
    if ($bytes === false || !$strong) { throw new RuntimeException('Không tạo được mã an toàn.'); }
    return bin2hex($bytes);
}

function fa_challenge($context, $student_id, $version, $score, $now) {
    return array('context'=>$context, 'student_id'=>(int)$student_id, 'template_version'=>$version,
        'score'=>(float)$score, 'expires_at'=>$now + 120);
}

function fa_verify_challenge($challenge, $context, $version, $now) {
    if (!is_array($challenge) || !isset($challenge['context'], $challenge['expires_at'], $challenge['student_id'], $challenge['template_version']) ||
        $challenge['context'] !== $context || $now >= $challenge['expires_at'] ||
        !is_string($version) || !hash_equals($challenge['template_version'], $version) || $challenge['student_id'] <= 0) {
        throw new RuntimeException('Kết quả nhận diện hết hạn hoặc đã thay đổi. Vui lòng chụp lại.', 409);
    }
    return (int)$challenge['student_id'];
}

function fa_seal($plaintext, $key) {
    if (strlen($key) < 32) { throw new RuntimeException('FACE_TEMPLATE_KEY cần ít nhất 32 ký tự.'); }
    $enc_key = hash_hmac('sha256', 'face:encryption', $key, true);
    $mac_key = hash_hmac('sha256', 'face:authentication', $key, true);
    $iv = hex2bin(substr(fa_random_token(), 0, 32));
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) { throw new RuntimeException('Không mã hóa được mẫu.'); }
    return base64_encode($iv . hash_hmac('sha256', $iv . $ciphertext, $mac_key, true) . $ciphertext);
}

function fa_unseal($sealed, $key) {
    if (strlen($key) < 32) { throw new RuntimeException('FACE_TEMPLATE_KEY cần ít nhất 32 ký tự.'); }
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < 64) { throw new RuntimeException('Mẫu khuôn mặt không hợp lệ.'); }
    $iv = substr($raw,0,16); $mac = substr($raw,16,32); $ciphertext = substr($raw,48);
    $expected = hash_hmac('sha256', $iv . $ciphertext, hash_hmac('sha256','face:authentication',$key,true), true);
    if (!hash_equals($expected,$mac)) { throw new RuntimeException('Mẫu khuôn mặt không xác thực được.'); }
    $plaintext = openssl_decrypt($ciphertext,'aes-256-cbc',hash_hmac('sha256','face:encryption',$key,true),OPENSSL_RAW_DATA,$iv);
    if ($plaintext === false) { throw new RuntimeException('Không giải mã được mẫu.'); }
    return $plaintext;
}

function fa_next_status($existing, $mode) {
    if (!in_array($mode,array('present','late'),true)) { throw new InvalidArgumentException('Chế độ điểm danh không hợp lệ.'); }
    if (in_array($existing,array('có','đi muộn'),true)) { return $existing; }
    return $mode === 'late' ? 'đi muộn' : 'có';
}

function fa_validate_samples($vectors) {
    if (!is_array($vectors) || count($vectors)!==3) throw new InvalidArgumentException('Cần 3 mẫu khuôn mặt.');
    $normalized=array();
    foreach ($vectors as $vector) {
        if (!is_array($vector) || count($vector)!==128) throw new RuntimeException('Mẫu sai kích thước.');
        $norm=0.0;
        foreach ($vector as $v) {
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)) throw new RuntimeException('Mẫu không hợp lệ.');
            $norm+=$v*$v;
        }
        $norm=sqrt($norm);
        if (!is_finite($norm) || $norm<0.000000001) throw new RuntimeException('Mẫu rỗng.');
        $normalized[]=array_map(function($v) use ($norm) { return $v/$norm; },$vector);
    }
    for ($i=0;$i<3;$i++) {
        for ($j=$i+1;$j<3;$j++) {
            $score=0.0;
            for ($k=0;$k<128;$k++) $score+=$normalized[$i][$k]*$normalized[$j][$k];
            if ($score<0.60) throw new InvalidArgumentException('Ba ảnh chưa khớp cùng một người. Chụp lại với góc nghiêng nhẹ.');
        }
    }
    return true;
}
