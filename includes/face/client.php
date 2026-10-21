<?php
function fa_config() {
    if (getenv('FACE_ENABLED') !== '1') throw new RuntimeException('Chức năng khuôn mặt chưa được bật.',503);
    $url=rtrim((string)getenv('FACE_SERVICE_URL'),'/');
    $service_key=(string)getenv('FACE_SERVICE_KEY'); $template_key=(string)getenv('FACE_TEMPLATE_KEY');
    if (!preg_match('#^https?://#',$url) || strlen($service_key)<32 || strlen($template_key)<32) {
        throw new RuntimeException('Chưa cấu hình dịch vụ/mã khóa khuôn mặt.',503);
    }
    return array('url'=>$url,'service_key'=>$service_key,'template_key'=>$template_key);
}

function fa_service_call($config,$action,$payload) {
    if (!function_exists('curl_init')) throw new RuntimeException('Máy chủ cần bật PHP cURL.',503);
    $curl=curl_init($config['url'].'/'.$action);
    curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>array('Content-Type: application/json','X-Face-Key: '.$config['service_key']),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>25,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2));
    $body=curl_exec($curl); $code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if ($body===false || $code===0 || $code>=500) throw new RuntimeException('Dịch vụ nhận diện chưa sẵn sàng. Dùng QR/thủ công hoặc thử lại.',503);
    $data=json_decode($body,true);
    if ($code!==200 || !is_array($data)) {
        $message=($code===422)?'Chụp lại một khuôn mặt rõ, đủ sáng, không quá gần/xa.':'Dịch vụ từ chối yêu cầu. Kiểm tra cấu hình hoặc đăng ký lại mẫu.';
        throw new RuntimeException($message,$code===409?409:422);
    }
    return $data;
}

function fa_validate_image($image) {
    if (!is_string($image) || strlen($image)>2000000 || $image==='') throw new InvalidArgumentException('Ảnh không hợp lệ hoặc quá lớn.');
    $decoded=base64_decode($image,true);
    if ($decoded===false || strlen($decoded)>1500000 || strlen($decoded)<100) throw new InvalidArgumentException('Ảnh không hợp lệ.');
    return $image;
}
