<?php
require_once __DIR__ . '/domain.php';

class FaceRepository {
    private $db;
    private $key;
    public function __construct($db, $key) { $this->db=$db; $this->key=$key; }
    public function begin() { if (!$this->db->begin_transaction()) throw new RuntimeException('Không bắt đầu được giao dịch.'); }
    public function commit() { if (!$this->db->commit()) throw new RuntimeException('Không lưu được giao dịch.'); }
    public function rollback() { $this->db->rollback(); }

    private function query($sql,$types='',$values=array()) {
        $stmt=$this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Không truy cập được dữ liệu khuôn mặt. Kiểm tra migration.');
        if ($types !== '') {
            $args=array($types);
            foreach ($values as $i=>$value) { $args[]=&$values[$i]; }
            call_user_func_array(array($stmt,'bind_param'),$args);
        }
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Không lưu được dữ liệu khuôn mặt.'); }
        $rows=array();
        if ($stmt->field_count) {
            $result=$stmt->get_result();
            while ($row=$result->fetch_assoc()) $rows[]=$row;
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    public function student($sid,$class_id,$lock=false) {
        $rows=$this->query("SELECT id,ten_thanh,ho_ten FROM students WHERE id=? AND class_id=? AND status='active'" . ($lock?' FOR UPDATE':''),'ii',array($sid,$class_id));
        if (!$rows) throw new RuntimeException('Đoàn sinh không còn sinh hoạt trong lớp này.',409);
        $rows[0]['id']=(int)$rows[0]['id'];
        return $rows[0];
    }

    public function students($class_id) {
        return $this->query("SELECT s.id,s.ten_thanh,s.ho_ten,(f.student_id IS NOT NULL) AS has_face FROM students s LEFT JOIN student_face_templates f ON f.student_id=s.id WHERE s.class_id=? AND s.status='active' ORDER BY s.ho_ten",'i',array($class_id));
    }

    public function template($sid) {
        $rows=$this->query('SELECT * FROM student_face_templates WHERE student_id=?','i',array($sid));
        return $rows ? $rows[0] : null;
    }

    public function candidates($class_id) {
        $rows=$this->query("SELECT f.* FROM student_face_templates f INNER JOIN students s ON s.id=f.student_id WHERE s.class_id=? AND s.status='active'",'i',array($class_id));
        $candidates=array(); $model=null; $versions=array();
        foreach ($rows as $row) {
            if ($model !== null && $model !== $row['model_version']) throw new RuntimeException('Mẫu khác phiên bản. Cần đăng ký lại các mẫu cũ.',409);
            $model=$row['model_version'];
            $vectors=json_decode(fa_unseal($row['encrypted_vectors'],$this->key),true);
            if (!is_array($vectors)) throw new RuntimeException('Mẫu khuôn mặt bị lỗi.');
            $sid=(int)$row['student_id'];
            $candidates[]=array('student_id'=>$sid,'vectors'=>$vectors);
            $versions[$sid]=$row['template_version'];
        }
        if (!$candidates) throw new RuntimeException('Lớp chưa có mẫu khuôn mặt. Vui lòng đăng ký trước.',409);
        return array('candidates'=>$candidates,'model_version'=>$model,'versions'=>$versions);
    }

    public function save_template($sid,$vectors,$model,$actor) {
        $sealed=fa_seal(json_encode($vectors),$this->key); $version=fa_random_token();
        $this->query('INSERT INTO student_face_templates (student_id,encrypted_vectors,model_version,template_version,registered_by,registered_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE encrypted_vectors=VALUES(encrypted_vectors),model_version=VALUES(model_version),template_version=VALUES(template_version),registered_by=VALUES(registered_by),registered_at=NOW()',
            'isssi',array($sid,$sealed,$model,$version,$actor));
    }
    public function delete_template($sid) { $this->query('DELETE FROM student_face_templates WHERE student_id=?','i',array($sid)); }
    public function attendance($ctx,$sid) {
        $rows=$this->query('SELECT status FROM attendance WHERE student_id=? AND class_id=? AND ngay_diem_danh=? AND activity_type=? FOR UPDATE','iiss',array($sid,$ctx['class_id'],$ctx['date'],$ctx['activity']));
        return $rows?$rows[0]['status']:null;
    }
    public function save_attendance($ctx,$sid,$status) {
        // Keep existing ghi_chu. Unique key also protects concurrent QR/manual requests.
        $this->query("INSERT INTO attendance (student_id,class_id,ngay_diem_danh,activity_type,status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=IF(status IN ('có','đi muộn'),status,VALUES(status))",
            'iisss',array($sid,$ctx['class_id'],$ctx['date'],$ctx['activity'],$status));
    }
    public function audit($ctx,$sid,$event,$before=null,$after=null,$score=null) {
        $this->query('INSERT INTO face_attendance_audit (student_id,class_id,actor_user_id,event,attendance_date,activity_type,previous_status,new_status,similarity) VALUES (?,?,?,?,?,?,?,?,?)',
            'iiisssssd',array($sid,$ctx['class_id'],$ctx['user_id'],$event,$ctx['date'],$ctx['activity'],$before,$after,$score));
    }
}
