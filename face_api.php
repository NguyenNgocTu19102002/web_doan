<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/login_redirect.php';
require_once __DIR__.'/includes/face/workflow.php';
require_once __DIR__.'/includes/face/client.php';
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Chỉ chấp nhận POST.',405);
    if (empty($_SESSION['user_id'])) throw new RuntimeException('Vui lòng đăng nhập lại.',401);
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>7000000) throw new RuntimeException('Ảnh quá lớn.',413);
    $class_id=isset($_POST['class_id'])?(int)$_POST['class_id']:0;
    $csrf=isset($_POST['csrf'])?$_POST['csrf']:'';
    $expected=isset($_SESSION['face_csrf'])?$_SESSION['face_csrf']:'';
    // Validate CSRF before opening DB connection.
    if (!is_string($csrf) || $expected==='' || !hash_equals($expected,$csrf)) throw new RuntimeException('Phiên xác thực không hợp lệ. Tải lại trang.',403);
    require __DIR__.'/db_connect.php';
    fa_authorize($_SESSION['user_id'],login_is_hdt_manager(),login_is_class_leader($conn,$class_id),$expected,$csrf);
    $ctx=fa_context($_SESSION['user_id'],$class_id,isset($_POST['date'])?$_POST['date']:'',isset($_POST['activity'])?$_POST['activity']:'',date('Y-m-d'));
    $config=fa_config();
    $repo=new FaceRepository($conn,$config['template_key']);
    $flow=new FaceWorkflow($repo,function($action,$payload) use ($config) { return fa_service_call($config,$action,$payload); });
    $action=isset($_POST['action'])?$_POST['action']:'';
    if ($action==='identify' || $action==='enroll' || $action==='auto_scan') {
        $last=isset($_SESSION['face_last_capture'])?$_SESSION['face_last_capture']:0;
        if (microtime(true)-$last<2) throw new RuntimeException('Vui lòng đợi 2 giây rồi thử lại.',429);
        $_SESSION['face_last_capture']=microtime(true);
    }
    if ($action!=='auto_scan') unset($_SESSION['face_auto_streak']);
    if ($action==='auto_scan') {
        $image=fa_validate_image(isset($_POST['image'])?$_POST['image']:'');
        $data=$flow->auto_scan($ctx,$image,isset($_POST['mode'])?$_POST['mode']:'',isset($_POST['run_id'])?$_POST['run_id']:'',$_SESSION);
    } elseif ($action==='enroll') {
        $images=isset($_POST['images'])?$_POST['images']:array();
        if (!is_array($images) || count($images)!==3) throw new InvalidArgumentException('Cần chụp 3 ảnh.');
        foreach ($images as $image) fa_validate_image($image);
        $data=$flow->enroll($ctx,isset($_POST['student_id'])?(int)$_POST['student_id']:0,$images);
        unset($_SESSION['face_confirmation']);
    } elseif ($action==='delete') {
        $data=$flow->remove($ctx,isset($_POST['student_id'])?(int)$_POST['student_id']:0);
        unset($_SESSION['face_confirmation']);
    } elseif ($action==='identify') {
        $image=fa_validate_image(isset($_POST['image'])?$_POST['image']:'');
        $data=$flow->identify($ctx,$image,$_SESSION);
    } elseif ($action==='confirm') {
        $data=$flow->confirm($ctx,isset($_POST['confirmation_token'])?$_POST['confirmation_token']:'',isset($_POST['mode'])?$_POST['mode']:'',$_SESSION);
    } else { throw new InvalidArgumentException('Thao tác không hợp lệ.'); }
    echo json_encode(array('ok'=>true,'data'=>$data),JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    unset($_SESSION['face_auto_streak']);
    $code=(int)$e->getCode();
    if ($e instanceof InvalidArgumentException) $code=422;
    if (!in_array($code,array(401,403,405,409,413,422,429,503),true)) {
        $code=500;
        // Never expose DB credentials, vectors, service key or stack traces.
        $message='Không lưu được dữ liệu. Kiểm tra cấu hình, migration hoặc thử lại.';
        error_log('Face attendance request failed: '.get_class($e));
    } else { $message=$e->getMessage(); }
    http_response_code($code);
    echo json_encode(array('ok'=>false,'message'=>$message),JSON_UNESCAPED_UNICODE);
}
