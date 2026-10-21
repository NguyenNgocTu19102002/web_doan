<?php
require_once __DIR__ . '/repository.php';

class FaceWorkflow {
    private $repo;
    private $service;
    public function __construct($repo,$service) { $this->repo=$repo; $this->service=$service; }
    private function request($action,$body) { return call_user_func($this->service,$action,$body); }

    public function enroll($ctx,$sid,$images) {
        if (!is_array($images) || count($images)!==3) throw new InvalidArgumentException('Cần chụp 3 ảnh rõ khuôn mặt.');
        $this->repo->student($sid,$ctx['class_id']);
        $vectors=array(); $model=null;
        foreach ($images as $image) {
            $result=$this->request('extract',array('image'=>$image));
            if (!isset($result['vector'],$result['model_version']) || !is_array($result['vector']) || count($result['vector'])!==128 ||
                !is_string($result['model_version']) || strlen($result['model_version'])>96 ||
                ($model!==null && $model!==$result['model_version'])) throw new RuntimeException('Kết quả dịch vụ không hợp lệ.');
            $model=$result['model_version']; $vectors[]=$result['vector'];
        }
        fa_validate_samples($vectors);
        $this->repo->begin();
        try {
            $this->repo->student($sid,$ctx['class_id'],true);
            $this->repo->save_template($sid,$vectors,$model,$ctx['user_id']);
            $this->repo->audit($ctx,$sid,'enroll');
            $this->repo->commit();
        } catch (Exception $e) { $this->repo->rollback(); throw $e; }
        return array('message'=>'Đã đăng ký/thay mẫu khuôn mặt.');
    }

    public function remove($ctx,$sid) {
        $this->repo->begin();
        try {
            $this->repo->student($sid,$ctx['class_id'],true);
            $this->repo->delete_template($sid);
            $this->repo->audit($ctx,$sid,'delete_template');
            $this->repo->commit();
        } catch (Exception $e) { $this->repo->rollback(); throw $e; }
        return array('message'=>'Đã xóa mẫu khuôn mặt. Điểm danh đã lưu được giữ nguyên.');
    }

    public function identify($ctx,$image,&$session) {
        // One current result per browser session. New capture invalidates old result.
        unset($session['face_confirmation']);
        $templates=$this->repo->candidates($ctx['class_id']);
        $result=$this->request('identify',array('image'=>$image,'model_version'=>$templates['model_version'],'candidates'=>$templates['candidates']));
        if (!isset($result['student_id'])) return array('matched'=>false,'message'=>'Chưa xác định rõ đoàn sinh. Chụp lại hoặc dùng QR/thủ công.');
        $sid=(int)$result['student_id'];
        if (!isset($templates['versions'][$sid],$result['score'],$result['model_version']) ||
            $result['model_version']!==$templates['model_version'] || !is_numeric($result['score']) ||
            !is_finite((float)$result['score']) || $result['score'] < -1 || $result['score'] > 1.000001) throw new RuntimeException('Kết quả nhận diện không hợp lệ.');
        $student=$this->repo->student($sid,$ctx['class_id']);
        $token=fa_random_token();
        $session['face_confirmation']=array('token'=>$token,'data'=>fa_challenge($ctx,$sid,$templates['versions'][$sid],$result['score'],time()));
        return array('matched'=>true,'student'=>$student,'confirmation_token'=>$token);
    }

    public function auto_scan($ctx,$image,$mode,$run_id,&$session) {
        // Each valid match is saved immediately; confirm still enforces locks/version/idempotency.
        unset($session['face_auto_streak']);
        try {
            fa_next_status(null,$mode);
            if (!is_string($run_id) || !preg_match('/\A[a-f0-9]{32}\z/',$run_id)) throw new InvalidArgumentException('Phiên camera không hợp lệ.');
            $match=$this->identify($ctx,$image,$session);
            if (!$match['matched']) return array('matched'=>false,'recorded'=>false,'message'=>$match['message']);
            $pending=$session['face_confirmation'];
            $saved=$this->confirm($ctx,$pending['token'],$mode,$session);
            return array_merge($saved,array('matched'=>true,'recorded'=>true,'student'=>$match['student']));
        } catch (Exception $e) {
            unset($session['face_confirmation']);
            throw $e;
        }
    }

    public function confirm($ctx,$token,$mode,&$session) {
        // Consume even on failure: request must be recaptured after error/expiry.
        $pending=isset($session['face_confirmation'])?$session['face_confirmation']:null;
        unset($session['face_confirmation']);
        if (!$pending || !is_string($token) || !hash_equals($pending['token'],$token)) throw new RuntimeException('Kết quả không còn hợp lệ. Chụp lại khuôn mặt.',409);
        $sid=(int)$pending['data']['student_id'];
        fa_next_status(null,$mode);
        $this->repo->begin();
        try {
            $this->repo->student($sid,$ctx['class_id'],true);
            $template=$this->repo->template($sid);
            fa_verify_challenge($pending['data'],$ctx,$template?$template['template_version']:'',time());
            $before=$this->repo->attendance($ctx,$sid);
            $already=in_array($before,array('có','đi muộn'),true);
            $status=fa_next_status($before,$mode);
            if (!$already) {
                $this->repo->save_attendance($ctx,$sid,$status);
                $status=$this->repo->attendance($ctx,$sid);
                $this->repo->audit($ctx,$sid,'attendance',$before,$status,$pending['data']['score']);
            }
            $this->repo->commit();
        } catch (Exception $e) { $this->repo->rollback(); throw $e; }
        return array('status'=>$status,'already_recorded'=>$already,'message'=>$already?'Em đã được điểm danh trước đó.':'Đã lưu điểm danh: '.$status.'.');
    }
}
