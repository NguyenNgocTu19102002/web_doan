# Cài điểm danh khuôn mặt trên Windows + XAMPP

Hướng dẫn cho người mới nhận source. Ví dụ XAMPP ở `C:\xampp`, repo ở
`C:\xampp\htdocs\web_doan`. Thay đường dẫn nếu máy bạn dùng vị trí khác.
PHP chạy bằng Apache của XAMPP; service nhận diện chạy bằng Python trên cùng máy.
Không cần Docker cho cách này.

## 1. Chuẩn bị

- XAMPP có Apache, PHP, MySQL/MariaDB; mở XAMPP Control Panel.
- Python **3.11 64-bit**, kèm pip và Python Launcher (`py`).
- Internet để cài thư viện và tải model lần đầu; webcam và trình duyệt Chrome/Edge.
- Source có `face-service/`, `diem_danh_face.php` và `sql/face_attendance/setup.sql`.

Mở PowerShell, kiểm tra:

```powershell
& C:\xampp\php\php.exe -v
py -3.11 --version
```

PHP phần mới đã được kiểm tra với 5.6 và 8.3; cần kiểm tra tương thích toàn website
nếu thay phiên bản XAMPP/PHP. Các bước dưới đây dùng XAMPP chạy PHP qua Apache.

## 2. Đặt source và cấu hình database local

1. Clone/copy repo vào `C:\xampp\htdocs\web_doan`.
2. Start **Apache** và **MySQL** trong XAMPP Control Panel.
3. Mở `http://localhost/phpmyadmin`, tạo database `tntt_lap_tri` dùng utf8mb4.
4. Nếu DB mới: import bản dump local đã được phép sử dụng, có cấu trúc như
   `tntt_lap_tri.sql`. Nếu DB đã có dữ liệu, không import lại toàn bộ dump.
5. Chọn đúng DB local rồi import thêm `web_doan\sql\face_attendance\setup.sql`.
   Migration tạo `student_face_templates` và `face_attendance_audit`.
6. Trong `web_doan\db_env.php`, kiểm tra nhóm `local`:

   ```php
   'db_host'     => 'localhost',
   'db_name'     => 'tntt_lap_tri',
   'db_user'     => 'root',
   'db_password' => '', // Thay bằng mật khẩu MySQL local thực tế.
   ```

   Giữ mode `auto` và truy cập bằng `localhost`. Nếu MySQL dùng port khác 3306,
   thêm `'db_port' => 3307` (thay bằng port thực tế) vào nhóm `local`.
7. Mở `http://localhost/web_doan/classes.php` và kiểm tra đăng nhập/xem lớp hoạt động.

Source hiện chọn DB theo host. **Dùng `localhost`, không dùng domain production,
custom domain hoặc IP LAN trong bước này**; `db_connect.php` có thể chọn cấu hình
production khi host không được nhận diện là local. Không commit mật khẩu local.
Không đưa dữ liệu/ảnh đoàn sinh thật vào môi trường thử khi chưa được phép.

## 3. Bật extension và giới hạn request PHP

Trong XAMPP Control Panel → Apache → **Config → PHP (php.ini)**, tìm và bật
các extension có sẵn trong bản XAMPP (bỏ dấu `;` phía trước, không thêm trùng):

```ini
extension=curl
extension=openssl
extension=mysqli
```

Tên dòng có thể là `extension=php_curl.dll` ở bản PHP cũ; giữ đúng cách viết có sẵn.
`mysqli` cần hỗ trợ mysqlnd/get_result. Tăng giới hạn nếu cấu hình hiện tại nhỏ hơn:

```ini
post_max_size=32M
upload_max_filesize=32M
memory_limit=256M
max_execution_time=120
```

Stop/Start Apache sau khi sửa. Kiểm tra qua HTTP ở bước 5, vì PHP chạy trong Apache
có thể khác cấu hình PHP CLI trong PowerShell.

## 4. Tạo khóa và file setting dành cho Apache

**Source hiện chưa tự đọc `.env`.** Cách bên dưới dùng file Apache `.conf` để
`getenv()` của PHP nhận được cấu hình, không cần sửa code hay cài dotenv.

Tạo thư mục `C:\xampp\private`. Chạy lệnh sau **hai lần**, mỗi lần lấy một khóa khác:

```powershell
& C:\xampp\php\php.exe -r "echo bin2hex(openssl_random_pseudo_bytes(32)), PHP_EOL;"
```

- Khóa thứ nhất: `FACE_SERVICE_KEY`, dùng chung PHP và Python.
- Khóa thứ hai: `FACE_TEMPLATE_KEY`, chỉ dùng PHP để mã hóa mẫu.

Tạo file **`C:\xampp\private\web-doan-face.conf`** bằng trình soạn thảo, nội dung:

```apache
<Directory "C:/xampp/htdocs/web_doan">
    SetEnv FACE_ENABLED "1"
    SetEnv FACE_SERVICE_URL "http://127.0.0.1:8000"
    SetEnv FACE_SERVICE_KEY "THAY_BANG_KHOA_API_VUA_TAO"
    SetEnv FACE_TEMPLATE_KEY "THAY_BANG_KHOA_MA_HOA_VUA_TAO"
</Directory>
```

Thay cả hai placeholder bằng khóa thật. Đường dẫn Directory phải khớp thư mục repo.
File này nằm **ngoài `htdocs` và ngoài Git**; không chia sẻ khóa trong chat/ảnh chụp.
Giữ nguyên khóa mã hóa khi khởi động lại, nếu đổi/mất khóa thì mẫu cũ không đọc được.

Mở `C:\xampp\apache\conf\httpd.conf`, thêm ở cuối file, bên ngoài các block:

```apache
Include "C:/xampp/private/web-doan-face.conf"
```

Kiểm tra dòng sau trong `httpd.conf` đang được bật:

```apache
LoadModule env_module modules/mod_env.so
```

Kiểm tra cấu hình rồi Stop/Start Apache:

```powershell
& C:\xampp\apache\bin\httpd.exe -t
```

Kết quả cần có `Syntax OK`. File `.conf` dùng cú pháp Apache, không phải cú pháp
`KEY=value` của `.env`. Biến `$env:...` đặt ở PowerShell không tự truyền sang Apache
đã chạy bằng XAMPP Control Panel.

## 5. Kiểm tra PHP nhận được setting

Tạm tạo `C:\xampp\htdocs\web_doan\face-local-check.php` với nội dung sau.
Chỉ dùng local và xóa file sau khi kiểm tra; đoạn này không in giá trị khóa:

```php
<?php
header('Content-Type: text/plain; charset=utf-8');
echo 'Enabled: ', getenv('FACE_ENABLED') === '1' ? 'OK' : 'MISSING', PHP_EOL;
echo 'Service URL: ', getenv('FACE_SERVICE_URL'), PHP_EOL;
foreach (array('FACE_SERVICE_KEY', 'FACE_TEMPLATE_KEY') as $name) {
    echo $name, ': ', strlen((string)getenv($name)) >= 32 ? 'SET' : 'MISSING', PHP_EOL;
}
foreach (array('curl', 'openssl', 'mysqli') as $name) {
    echo $name, ': ', extension_loaded($name) ? 'OK' : 'MISSING', PHP_EOL;
}
echo 'mysqlnd/get_result: ', function_exists('mysqli_stmt_get_result') ? 'OK' : 'MISSING', PHP_EOL;
```

Mở `http://localhost/web_doan/face-local-check.php`. Cần thấy Enabled `OK`, hai khóa
`SET`, extension `OK`, URL `http://127.0.0.1:8000`. Nếu thiếu: kiểm tra Include,
đường dẫn Directory và restart Apache. Không dùng `phpinfo()` để chia sẻ setting
vì có thể hiển thị khóa/mật khẩu.

## 6. Cài và chạy service Python (không cần Docker)

Mở PowerShell mới, chạy:

```powershell
cd C:\xampp\htdocs\web_doan\face-service
py -3.11 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt

$env:FACE_MODEL_DIR = (Join-Path (Get-Location) "models")
.\.venv\Scripts\python.exe download_models.py

$env:FACE_SERVICE_KEY = "THAY_BANG_CUNG_KHOA_API_TRONG_FILE_APACHE"
.\.venv\Scripts\python.exe -m uvicorn face_service.api:from_env --factory --host 127.0.0.1 --port 8000 --workers 1
```

Khóa API phải giống chính xác `FACE_SERVICE_KEY` ở bước 4. Không đặt khóa mã hóa
vào Python. Dùng đường dẫn `python.exe` trong venv nên không cần Activate.ps1
hoặc đổi ExecutionPolicy. Giữ cửa sổ PowerShell này mở trong khi test.

Lần đầu script tải hai model và kiểm checksum; cần đợi hoàn tất. Khi chạy thành
công có dòng `Uvicorn running on http://127.0.0.1:8000`. Mở URL `/` hoặc `/docs`
có thể trả 404 vì service chỉ cung cấp POST `/extract` và `/identify` có API key.

Mỗi lần mở PowerShell mới, chỉ cần đặt lại hai biến và chạy service:

```powershell
cd C:\xampp\htdocs\web_doan\face-service
$env:FACE_MODEL_DIR = (Join-Path (Get-Location) "models")
$env:FACE_SERVICE_KEY = "THAY_BANG_CUNG_KHOA_API_TRONG_FILE_APACHE"
.\.venv\Scripts\python.exe -m uvicorn face_service.api:from_env --factory --host 127.0.0.1 --port 8000 --workers 1
```

Không cần cài lại thư viện/model mỗi lần. Không mở port 8000 ra mạng ngoài.

### Nếu muốn Python chạy bằng Docker Desktop

Thay bước chạy Python bằng cách tạo file `C:\xampp\private\web-doan-face.env`:

```dotenv
FACE_SERVICE_KEY=THAY_BANG_CUNG_KHOA_API_TRONG_FILE_APACHE
```

Từ PowerShell tại thư mục repo:

```powershell
cd C:\xampp\htdocs\web_doan
docker compose --env-file C:\xampp\private\web-doan-face.env -f compose.face.yaml up -d --build
docker compose --env-file C:\xampp\private\web-doan-face.env -f compose.face.yaml logs --tail 30
```

Chỉ chọn **Python trực tiếp hoặc Docker**, tránh hai service cùng chiếm port 8000.
Apache XAMPP vẫn dùng URL `http://127.0.0.1:8000`, vì Compose publish port này về
Windows. File `.env` ở đây được **Docker Compose** đọc; PHP vẫn nhận setting qua
file `.conf`, không tự đọc file `.env` đó.

## 7. Test chức năng trên trình duyệt

1. Apache/MySQL đang chạy và service Python đang chạy.
2. Mở `http://localhost/web_doan/classes.php`.
3. Đăng nhập Admin/HDT hoặc tài khoản Trưởng/Phó được gán đúng lớp.
4. Mở lớp → **Điểm Danh Đoàn Sinh → Điểm danh khuôn mặt**.
5. Bấm mở camera và cấp quyền; chọn đoàn sinh đang sinh hoạt.
6. Chụp 3 ảnh rõ của cùng người
   (thẳng, nghiêng nhẹ trái, nghiêng nhẹ phải), rồi lưu mẫu.
7. Chọn ngày không ở tương lai, hoạt động và trạng thái Có/Đi muộn.
8. Bấm **Bắt đầu điểm danh tự động**. Từng người đứng rõ trước camera khoảng
   cho tới khi nhận diện thành công; hệ thống tự lưu ngay và báo tên. Không cần
   bấm chụp/xác nhận từng người. Muốn thao tác có xác nhận, mở mục thủ công.
9. Về trang lớp, chọn đúng ngày/hoạt động để xem kết quả. Có thể kiểm tra DB:

   ```sql
   SELECT student_id, class_id, ngay_diem_danh, activity_type, status, ghi_chu
   FROM attendance
   ORDER BY id DESC
   LIMIT 20;
   ```

Chế độ tự động ghi ngay lần nhận diện thành công đầu tiên. Luồng
thủ công chỉ ghi khi xác nhận. Không tự ghi vắng các em khác.
Trên cùng PC, `http://localhost` dùng được camera. Điện thoại truy cập IP máy
Windows cần HTTPS và cấu hình DB local phù hợp; không chỉ thay localhost bằng IP
với cấu hình DB hiện tại.

## 8. Lỗi thường gặp

| Hiện tượng | Kiểm tra |
|---|---|
| Apache không Start sau khi thêm setting | Chạy `httpd.exe -t`, xem `C:\xampp\apache\logs\error.log`; kiểm tra file Include tồn tại, dấu ngoặc và mod_env |
| Không thấy nút điểm danh khuôn mặt | `FACE_ENABLED=1` qua HTTP, restart Apache, quyền tài khoản đúng lớp |
| Trang báo chức năng chưa sẵn sàng | Bước 5, hai khóa đủ dài, đúng DB local, hai bảng migration đã có |
| Dịch vụ chưa sẵn sàng / không kết nối | PowerShell service còn mở, model đã tải, port 8000 đúng, Python/Docker đang chạy |
| Dịch vụ từ chối yêu cầu | So lại khóa API phía Apache/Python, model version và đăng ký lại mẫu nếu đã đổi model |
| Camera không mở | Dùng localhost, cấp quyền camera, đóng app khác đang chiếm camera |
| Model không tải được | Kiểm tra mạng/proxy đến GitHub, chạy lại `download_models.py`, không bỏ kiểm checksum |
| Không nhận diện được | Đủ sáng, một mặt, mặt đủ lớn/rõ, mẫu của đúng lớp, đoàn sinh active |
| Ba ảnh không khớp | Cùng một người, chỉ nghiêng nhẹ, chụp lại cả ba ảnh |
| Lỗi sau khi đổi khóa mã hóa | Khôi phục khóa cũ hoặc đăng ký lại mẫu với khóa mới; không đổi khóa tùy ý |
| CLI test chạy nhưng web lỗi | CLI và Apache khác php.ini/extension/env; kiểm tra bằng HTTP bước 5 |

Xóa `face-local-check.php` sau khi xong. Muốn tắt tính năng: đổi
`SetEnv FACE_ENABLED "0"`, restart Apache; dừng Python bằng Ctrl+C (hoặc dừng
service Compose). Giữ hai khóa nếu còn muốn dùng lại mẫu đã đăng ký.

Tài liệu đối chiếu: [Apache SetEnv](https://httpd.apache.org/docs/2.4/mod/mod_env.html#setenv),
[Apache Include](https://httpd.apache.org/docs/2.4/mod/core.html#include).
Hướng dẫn này được đối chiếu với source; chưa chạy trực tiếp trên máy Windows/XAMPP.
