<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once __DIR__.'/login_redirect.php';
if (empty($_SESSION['user_id'])) require_login_redirect();
require_once __DIR__.'/db_connect.php';
require_once __DIR__.'/includes/face/repository.php';
require_once __DIR__.'/includes/face/client.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function faf_h($v) { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$class_id=isset($_GET['class_id'])?(int)$_GET['class_id']:0;
if (!login_is_hdt_manager() && !login_is_class_leader($conn,$class_id)) { http_response_code(403); exit('Bạn không phụ trách lớp này.'); }
$res=$conn->query('SELECT id,ten_lop FROM classes WHERE id='.$class_id);
$class=$res?$res->fetch_assoc():null;
if (!$class) { http_response_code(404); exit('Lớp không tồn tại.'); }
if (empty($_SESSION['face_csrf'])) $_SESSION['face_csrf']=fa_random_token();
$error=''; $students=array();
try {
    $config=fa_config();
    $students=(new FaceRepository($conn,$config['template_key']))->students($class_id);
} catch (Exception $e) { $error='Chức năng chưa sẵn sàng. Cần bật cấu hình và chạy migration theo hướng dẫn triển khai.'; }
$date=isset($_GET['ngay'])?$_GET['ngay']:date('Y-m-d');
$activity=isset($_GET['activity']) && $_GET['activity']==='Đi lễ'?'Đi lễ':'Học Giáo Lý';
try { fa_context($_SESSION['user_id'],$class_id,$date,$activity,date('Y-m-d')); }
catch (Exception $e) { $date=date('Y-m-d'); }
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Điểm danh khuôn mặt · <?php echo faf_h($class['ten_lop']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f3f7fb;color:#263238}main{max-width:1000px;margin:auto;padding:20px}.face-panel{background:white;border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 4px 18px #132a4610}
video{width:100%;max-height:420px;background:#102030;border-radius:12px;object-fit:contain}#preview{max-width:150px;border-radius:8px}.samples img{width:86px;border-radius:8px;margin:4px}button{min-height:44px}[hidden]{display:none!important}
</style></head><body>
<main>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
<h1 class="h4 fw-bold mb-0">Điểm danh khuôn mặt · <?php echo faf_h($class['ten_lop']); ?></h1>
<a class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 text-nowrap" href="class_details.php?id=<?php echo $class_id; ?>&amp;ngay_diem_danh=<?php echo urlencode($date); ?>&amp;activity=<?php echo urlencode($activity); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M15 8a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 0 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 7.5H14.5a.5.5 0 0 1 .5.5z"/></svg>Về trang lớp</a>
</div>
<?php if ($error): ?><div class="alert alert-warning"><?php echo faf_h($error); ?></div><?php else: ?>
<p>Huynh Trưởng giám sát người đứng trước camera. Chế độ tự động sẽ lưu khi nhận diện thành công một lần.</p>
<div id="faceApp" data-class-id="<?php echo $class_id; ?>" data-csrf="<?php echo faf_h($_SESSION['face_csrf']); ?>">
<section class="face-panel">
<div class="row g-2 mb-3">
<div class="col-sm"><label for="faceDate" class="form-label">Ngày</label><input id="faceDate" type="date" class="form-control" value="<?php echo faf_h($date); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
<div class="col-sm"><label for="faceActivity" class="form-label">Hoạt động</label><select id="faceActivity" class="form-select"><option<?php echo $activity==='Học Giáo Lý'?' selected':''; ?>>Học Giáo Lý</option><option<?php echo $activity==='Đi lễ'?' selected':''; ?>>Đi lễ</option></select></div>
<div class="col-sm"><label for="cameraFacing" class="form-label">Camera</label><select id="cameraFacing" class="form-select"><option value="user">Camera trước / Webcam</option><option value="environment">Camera sau</option></select></div>
</div>
<video id="faceVideo" autoplay muted playsinline></video>
<div class="d-flex gap-2 mt-2"><button id="startCamera" type="button" class="btn btn-primary">Mở camera</button><button id="stopCamera" type="button" class="btn btn-outline-secondary">Dừng camera</button></div>
</section>
<div id="faceMessage" class="alert alert-info" role="status" aria-live="polite">Mở camera rồi đưa một khuôn mặt rõ vào khung.</div>
<section class="face-panel">
<h2 class="h5">Điểm danh</h2>
<label for="attendanceMode" class="form-label">Trạng thái khi xác nhận</label>
<select id="attendanceMode" class="form-select mb-3"><option value="present">Có mặt</option><option value="late">Đi muộn</option></select>
<div class="d-flex flex-wrap gap-2 mb-3"><button id="startAutoFace" type="button" class="btn btn-success">Bắt đầu điểm danh tự động</button><button id="stopAutoFace" type="button" class="btn btn-outline-danger" disabled>Dừng tự động</button></div>
<p class="small">Mở camera một lần, từng em đứng rõ trong khung để hệ thống nhận diện. Hệ thống tự lưu và báo tên, không cần bấm chụp hay xác nhận. Không dùng ảnh/video để điểm danh.</p>
<div id="autoFaceStatus" class="fw-bold mb-2" role="status" aria-live="polite"></div>
<ul id="autoFaceHistory" class="list-group mb-3" aria-label="Kết quả điểm danh tự động"></ul>
<details><summary>Điểm danh từng em có xác nhận</summary>
<button id="identifyFace" type="button" class="btn btn-success">Chụp và nhận diện</button>
<div id="faceResult" class="mt-3" hidden><img id="preview" alt="Ảnh vừa chụp"><p id="matchedName" class="fw-bold mt-2"></p><p>Kiểm tra tên và người thực tế. Kết quả có hiệu lực 120 giây.</p><button id="confirmFace" type="button" class="btn btn-primary">Đúng người — Lưu điểm danh</button> <button id="cancelFace" type="button" class="btn btn-outline-secondary">Hủy kết quả</button></div>
 </details></section>
<section class="face-panel">
<h2 class="h5">Đăng ký / quản lý mẫu</h2>
<label for="faceStudent" class="form-label">Đoàn sinh đang sinh hoạt</label>
<select id="faceStudent" class="form-select mb-3"><option value="">Chọn đoàn sinh</option>
<?php foreach ($students as $student): ?><option value="<?php echo (int)$student['id']; ?>"><?php echo faf_h(trim($student['ten_thanh'].' '.$student['ho_ten']).($student['has_face']?' — Đã có mẫu':' — Chưa có mẫu')); ?></option><?php endforeach; ?>
</select>
<p>Chụp 3 ảnh: nhìn thẳng, nghiêng nhẹ trái, nghiêng nhẹ phải. Mỗi ảnh chỉ có một khuôn mặt. Đăng ký lại sẽ thay mẫu cũ.</p>
<div class="d-flex flex-wrap gap-2"><button id="captureSample" type="button" class="btn btn-outline-primary">Chụp ảnh mẫu (0/3)</button><button id="clearSamples" type="button" class="btn btn-outline-secondary">Chụp lại từ đầu</button><button id="enrollFace" type="button" class="btn btn-primary">Lưu 3 ảnh mẫu</button><button id="deleteFace" type="button" class="btn btn-outline-danger">Xóa mẫu</button></div>
<div id="faceSamples" class="samples mt-2"></div>
<p class="small text-muted mt-3 mb-0">Ảnh camera chỉ xử lý trong yêu cầu, không được lưu làm ảnh hồ sơ. Có thể dùng QR/thủ công nếu không đăng ký khuôn mặt.</p>
</section></div>
<script src="js/face_attendance.js" defer></script>
<?php endif; ?>
</main></body></html>
