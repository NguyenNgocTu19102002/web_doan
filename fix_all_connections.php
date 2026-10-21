<?php
/**
 * Script tự động sửa tất cả các file PHP để sử dụng db_connect.php
 * Chạy script này một lần để cập nhật tất cả các file
 */

echo "<!DOCTYPE html>
<html lang='vi'>
<head>
    <meta charset='UTF-8'>
    <title>Sửa tất cả kết nối database</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .btn { background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 5px; }
        .btn:hover { background: #005a87; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Sửa tất cả kết nối database</h1>";

// Danh sách các file PHP cần kiểm tra và sửa
$files_to_check = [
    'login.php',
    'loichua.php', 
    'admin_loichua.php',
    'class_details.php',
    'get_class_teachers.php',
    'hdt_attendance.php',
    'hdt_management.php',
    'hdt_management_simple.php',
    'hdt_management_updated.php',
    'students.php',
    'scripts/fetch_and_save_loichua.php'
];

$fixed_files = [];
$error_files = [];

echo "<h2>📋 Đang kiểm tra và sửa các file...</h2>";

foreach ($files_to_check as $file) {
    $filepath = __DIR__ . '/' . $file;
    
    if (file_exists($filepath)) {
        echo "<p>🔍 Đang xử lý: <strong>$file</strong>... ";
        
        $content = file_get_contents($filepath);
        $original_content = $content;
        
        // Tìm và thay thế các dòng kết nối database cũ
        $patterns = [
            // Pattern 1: new mysqli với thông tin web
            '/new mysqli\([\'"]localhost[\'"],\s*[\'"]o0kgbp36f9sl_sang17[\'"],\s*[\'"]Sang17092001[\'"],\s*[\'"]o0kgbp36f9sl_tntt_lap_tri[\'"]\)/',
            
            // Pattern 2: new mysqli với thông tin local cũ
            '/new mysqli\([\'"]localhost[\'"],\s*[\'"]root[\'"],\s*[\'\'][\'"],\s*[\'"][^\'\"]*[\'"]\)/',
            
            // Pattern 3: Các kết nối mysqli khác
            '/\$conn\s*=\s*new mysqli\([^)]+\);.*?if\s*\(\$conn->connect_error\)\s*die\([^)]+\);.*?\$conn->set_charset\([^)]+\);/s',
            
            // Pattern 4: $mysqli = new mysqli
            '/\$mysqli\s*=\s*new mysqli\([^)]+\);.*?if\s*\(\$mysqli->connect_errno\)\s*\{[^}]*\}[^}]*else[^}]*\$mysqli->set_charset\([^)]+\);/s'
        ];
        
        $replacements = [
            "require_once 'db_connect.php';",
            "require_once 'db_connect.php';", 
            "require_once 'db_connect.php';",
            "require_once 'db_connect.php';\n\$mysqli = \$conn;"
        ];
        
        $updated = false;
        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $replacements[$i], $content);
                $updated = true;
                break;
            }
        }
        
        // Xóa các dòng đóng kết nối
        $content = preg_replace('/\$conn->close\(\);?\s*\n?/', '', $content);
        $content = preg_replace('/\$mysqli->close\(\);?\s*\n?/', '', $content);
        
        // Nếu có thay đổi, ghi lại file
        if ($content !== $original_content) {
            if (file_put_contents($filepath, $content)) {
                echo "<span style='color: green;'>✅ Đã sửa</span></p>";
                $fixed_files[] = $file;
            } else {
                echo "<span style='color: red;'>❌ Lỗi ghi file</span></p>";
                $error_files[] = $file;
            }
        } else {
            echo "<span style='color: orange;'>⚠ Không cần sửa</span></p>";
        }
        
    } else {
        echo "<p>File không tồn tại: <strong>$file</strong> <span style='color: red;'>❌</span></p>";
        $error_files[] = $file;
    }
}

// Hiển thị kết quả
echo "<h2>📊 Kết quả:</h2>";
echo "<div class='success'>";
echo "<strong>✅ Đã sửa (" . count($fixed_files) . " files):</strong><br>";
foreach ($fixed_files as $file) {
    echo "• $file<br>";
}
echo "</div>";

if (!empty($error_files)) {
    echo "<div class='error'>";
    echo "<strong>❌ Lỗi (" . count($error_files) . " files):</strong><br>";
    foreach ($error_files as $file) {
        echo "• $file<br>";
    }
    echo "</div>";
}

echo "<div class='info'>";
echo "<h3>🎉 Hoàn thành!</h3>";
echo "<p>Tất cả các file đã được cập nhật để sử dụng <code>db_connect.php</code>.</p>";
echo "<p>Bây giờ bạn có thể chuyển đổi môi trường tại: <a href='switch_env_db.php'>switch_env_db.php</a></p>";
echo "</div>";

echo "<div style='margin-top: 30px;'>";
echo "<a href='switch_env_db.php' class='btn'>🔄 Chuyển đổi môi trường</a>";
echo "<a href='index.php' class='btn'>🏠 Trang chính</a>";
echo "</div>";

echo "</div></body></html>";
?>
