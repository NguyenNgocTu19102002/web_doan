# Điểm danh khuôn mặt

Cài local trên Windows + XAMPP: xem [hướng dẫn từng bước](WINDOWS-XAMPP.md)
(database, Apache setting, Python hoặc Docker, camera và xử lý lỗi).

## Luồng sử dụng
1. Admin/HDT hoặc Trưởng/Phó lớp mở **Điểm danh khuôn mặt** từ phần điểm danh lớp.
2. Chọn đoàn sinh, chụp 3 ảnh
   nhìn thẳng/nghiêng nhẹ trái/phải và lưu. Lưu lại thay mẫu cũ; có nút thu hồi mẫu.
3. Chọn ngày, hoạt động và Có/Đi muộn; bấm **Bắt đầu điểm danh tự động**.
   Camera mở một lần, tự lấy khung hình khoảng 2,2 giây sau mỗi response.
4. Mỗi em đứng rõ trước camera. Ngay lần nhận diện thành công đầu tiên, hệ thống
   tự lưu, hiện tên và phát âm báo khi ghi mới, tiếp tục nhận diện người kế tiếp.
5. `attendance` được ghi ngay khi nhận diện thành công. Giữ trạng thái có/đi muộn nếu đã điểm danh;
   cập nhật từ vắng và giữ ghi chú. Không tự ghi vắng các em khác. QR/thủ công vẫn dùng được.

Bấm Dừng tự động để ngừng quét; Dừng camera hoặc chuyển tab cũng dừng. Request
đã gửi trước khi dừng vẫn có thể hoàn tất và lưu. Lỗi mạng/service dừng vòng lặp;
khung hình không rõ tiếp tục chờ. Nhận diện khuôn mặt không phải liveness/chống giả mạo.
Huynh Trưởng vẫn giám sát tại lớp. Có thể mở mục **Điểm danh từng em có xác nhận**
để dùng luồng chụp/xác nhận thủ công như trước. Đăng ký ban đầu vẫn cần 3 ảnh mẫu.

Một repo nhưng PHP và Python chạy thành hai tiến trình. Python chỉ nhận request
từ PHP có API key, không kết nối database. Mẫu mã hóa nằm trong MySQL;
ảnh camera không ghi xuống đĩa. `face_attendance_audit` ghi ai đăng ký/xóa mẫu,
ai xác nhận, trạng thái trước/sau và similarity (không phải xác suất đúng).

## Chuẩn bị máy chủ
- Python 3.11+ hoặc Docker Compose; CPU đủ chạy OpenCV, giới hạn container mặc định 1 GiB.
  Đây là cấu hình giới hạn ban đầu, cần đo RAM/độ trễ thực tế trước khi chọn gói server.
- PHP >=5.6 với mysqli **mysqlnd**, OpenSSL, cURL. Nên dùng PHP đang được hỗ trợ
  sau khi xác nhận tương thích toàn bộ website; tính năng mới giữ cú pháp PHP 5.6.
- MySQL/MariaDB InnoDB; bảng `attendance` phải có UNIQUE
  `(student_id,class_id,ngay_diem_danh,activity_type)` như dump `tntt_lap_tri.sql`.
- HTTPS cho website; camera PC/Android/iPhone yêu cầu quyền trình duyệt.
- Hosting PHP thông thường chưa chắc chạy được service; xác nhận hỗ trợ trước khi bật.

## Cài đặt (chạy tại máy chủ sau khi review)

Người cấu hình server xem file [`.env.face.example`](../../.env.face.example).
Mô hình hosting PHP + Python riêng dùng `FACE_SERVICE_URL=https://face-api.saviolaptri.com`.
Đây là địa chỉ dự kiến: cần cấu hình DNS, HTTPS và reverse proxy tới service Python
trước khi sử dụng. Trên hosting PHP, cấp đủ bốn biến trong file mẫu; phía Python
chỉ cần cùng `FACE_SERVICE_KEY`. Thay các placeholder khóa bằng khóa server thực tế.
Không upload file chứa khóa vào thư mục public. PHP không tự đọc `.env`;
người triển khai phải cấp biến qua cấu hình hosting/PHP hoặc Docker.

1. Sao lưu DB. Import **chỉ** `sql/face_attendance/setup.sql` vào DB website.
   Migration bổ sung hai bảng, không reset dữ liệu. Không cần import lại toàn bộ dump.
2. Tạo hai secret độc lập bằng `openssl rand -hex 32` (chạy hai lần), lưu ngoài
   document root, ngoài Git, ví dụ `/etc/web-doan/face.env`, quyền đọc hạn chế.
3. Cấu hình môi trường cho tiến trình PHP/LiteSpeed/PHP-FPM:

   ```text
   FACE_ENABLED=1
   FACE_SERVICE_URL=http://127.0.0.1:8000
   FACE_SERVICE_KEY=<secret dành cho API>
   FACE_TEMPLATE_KEY=<secret mã hóa mẫu, ít nhất 32 ký tự>
   ```

   File `.env` không tự được PHP đọc. Cần truyền env qua cấu hình hosting/FPM/
   container. Compose chỉ truyền `FACE_SERVICE_KEY` cho Python, không tự cấu hình PHP.
   Giữ nguyên `FACE_TEMPLATE_KEY` và sao lưu an toàn cùng chính sách backup DB;
   mất/đổi key sẽ không giải mã được mẫu cũ, cần đăng ký lại.
4. Trong repo chạy:

   ```bash
   docker compose --env-file /etc/web-doan/face.env -f compose.face.yaml up -d --build
   ```

   File env chứa `FACE_SERVICE_KEY` cùng giá trị phía PHP. Docker build tải YuNet/
   SFace từ commit cố định OpenCV Zoo, kiểm SHA-256 và giữ license MIT/Apache-2.0.
   Không mở port 8000 ra Internet. Nếu PHP cũng trong Docker, ghép Compose vào cùng
   stack/network và đặt `FACE_SERVICE_URL=http://face-service:8000`; có thể bỏ ports.
   Nếu service ở máy khác: dùng HTTPS có xác minh chứng chỉ và giới hạn mạng/IP.
5. Cấu hình web server không phục vụ `face-service/`, `tests/face_attendance/`,
   `sql/face_attendance/`, tài liệu và file cấu hình/secrets. Các thư mục service/test/
   migration đã có `.htaccess` cho Apache/LiteSpeed; Nginx phải cấu hình `deny all`
   riêng. Không đưa secret vào document root.
6. Chạy thử camera, đăng ký/thay/xóa mẫu, nhận diện và lưu điểm danh trên một lớp trước khi mở rộng.

Chạy Python không Docker:

```bash
cd face-service
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
FACE_MODEL_DIR=./models .venv/bin/python download_models.py
# Cấp FACE_SERVICE_KEY qua môi trường được bảo vệ trước khi chạy.
FACE_MODEL_DIR=./models .venv/bin/uvicorn face_service.api:from_env --factory --host 127.0.0.1 --port 8000 --workers 1
```

## Giới hạn và vận hành
- Dùng có giám sát, nhận diện một em mỗi lần. Chưa có liveness/chống dùng ảnh/video;
  không dùng để tự điểm danh từ xa. Có QR/thủ công cho người không đăng ký.
- Ngưỡng cosine mặc định 0.60, margin 0.10 là điểm khởi đầu thận trọng, không phải
  cam kết độ chính xác. Đánh giá false match/reject với camera, ánh sáng và độ tuổi
  thực tế; không dùng accuracy trong tài liệu mô hình để thay thế đánh giá tại đoàn.
- Ba mẫu phải tương đồng cosine >=0.60 từng cặp. Mỗi request yêu cầu đúng một mặt,
  kiểm tra kích thước/ánh sáng/độ mờ cơ bản. Đây không phải chống giả mạo.
- Model version gắn mẫu; đổi mô hình/tiền xử lý phải đổi version và đăng ký lại.
  Python có một worker và khóa quanh OpenCV để tránh truy cập đồng thời vào detector.
- Confirmation nằm trong session, gắn user/lớp/ngày/hoạt động/phiên bản mẫu, đơn dụng.
  Session PHP khóa request cùng phiên; mở tab khác nhận diện sẽ thay kết quả trước.
- Quyền/CSRF, giới hạn ảnh và tốc độ được kiểm tra phía PHP. PHP không tin student_id
  do trình duyệt gửi khi xác nhận. Các luồng điểm danh cũ giữ nguyên quyền hiện tại.
- Audit cùng transaction với mẫu/attendance. Mẫu được mã hóa AES-256-CBC + HMAC-SHA256
  với khóa dẫn xuất độc lập (encrypt-then-MAC). Không ghi vector/ảnh vào log.
- Thu hồi mẫu qua nút Xóa; xóa đoàn sinh xóa mẫu theo FK. Audit vẫn giữ ID lịch sử;
  cần thiết lập thời hạn lưu/xóa audit và backup phù hợp với quy trình của đoàn.
- Tắt tính năng: bỏ `FACE_ENABLED=1`, restart tiến trình PHP; QR/thủ công vẫn hoạt động.

Nguồn kỹ thuật: https://docs.opencv.org/4.x/d0/dd4/tutorial_dnn_face.html và
https://github.com/opencv/opencv_zoo (model/license tại commit trong download_models.py).

## Nâng cấp bản đã cài trước khi bỏ checkbox đăng ký

Bản hiện tại đăng ký bằng cách chọn đoàn sinh + chụp 3 ảnh + lưu, không có bước
confirm/checkbox đồng ý trong UI/API. Vẫn giữ quyền người phụ trách và kiểm tra ảnh.
Metadata lưu người/thời điểm đăng ký là `registered_by`, `registered_at`.
Nếu DB cũ còn `consent_recorded_by`/`consent_recorded_at`, chạy một lần
`sql/face_attendance/upgrade_registration_metadata.sql` trước khi dùng code mới.
Migration đổi tên cột, giữ nguyên mẫu đã mã hóa. DB tạo mới bằng setup.sql không
chạy upgrade này. Môi trường Ubuntu local hiện tại đã được nâng cấp.
