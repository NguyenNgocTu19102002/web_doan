<?php
/**
 * File chuyển đổi môi trường database nhanh chóng
 * Dựa trên cấu hình từ db_env.php
 */

// Load cấu hình
$config = require_once 'db_env.php';
$current_mode = $config['mode'];

// Xử lý chuyển đổi
if (isset($_POST['mode']) && in_array($_POST['mode'], ['auto', 'local', 'production'], true)) {
    $new_mode = $_POST['mode'];
    
    // Đọc và cập nhật file db_env.php
    $env_file = __DIR__ . '/db_env.php';
    $content = file_get_contents($env_file);
    
    // Thay đổi mode
    $content = preg_replace("/'mode'\s*=>\s*'.*?'/", "'mode' => '$new_mode'", $content);
    
    // Ghi lại file
    if (file_put_contents($env_file, $content)) {
        // Redirect về trang chính
        header("Location: index.php");
        exit;
    } else {
        $error = "Không thể cập nhật file cấu hình!";
    }
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chuyển đổi Database Environment</title>
    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 600px;
            width: 90%;
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            font-weight: 600;
        }
        .current-env {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid #007cba;
        }
        .current-env strong {
            color: #007cba;
            font-size: 28px;
            display: block;
            margin-bottom: 10px;
        }
        .env-details {
            text-align: left;
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
        }
        .env-details div {
            margin: 5px 0;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .btn {
            padding: 15px 35px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .btn-local {
            background: #28a745;
            color: white;
        }
        .btn-local:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .btn-production {
            background: #007cba;
            color: white;
        }
        .btn-production:hover {
            background: #005a87;
            transform: translateY(-2px);
        }
        .btn-back {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        .btn-back:hover {
            background: #545b62;
        }
        .info {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
            font-size: 14px;
            color: #1565c0;
            text-align: left;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔄</div>
        <h1>Chuyển đổi Database Environment</h1>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="current-env">
            <p>Môi trường hiện tại:</p>
            <strong>
                <?php 
                if ($current_mode === 'auto') {
                    $detected = isset($config['_detected']) ? $config['_detected'] : 'local';
                    echo "⚡ AUTO → đang dùng " . strtoupper($detected);
                } elseif ($current_mode === 'local') {
                    echo "🏠 LOCAL (XAMPP)";
                } else {
                    echo "🌐 PRODUCTION (Web Hosting)";
                }
                ?>
            </strong>
            
            <div class="env-details">
                <strong>Chi tiết cấu hình:</strong>
                <?php
                $show_key = $current_mode;
                if ($show_key === 'auto') {
                    $show_key = isset($config['_detected']) ? $config['_detected'] : 'local';
                }
                if ($show_key === 'local'): ?>
                    <div><strong>Host:</strong> <?php echo $config['local']['db_host']; ?></div>
                    <div><strong>Database:</strong> <?php echo $config['local']['db_name']; ?></div>
                    <div><strong>Username:</strong> <?php echo $config['local']['db_user']; ?></div>
                    <div><strong>Password:</strong> <?php echo $config['local']['db_password'] ?: '(trống)'; ?></div>
                <?php else: ?>
                    <div><strong>Host:</strong> <?php echo $config['production']['db_host']; ?></div>
                    <div><strong>Database:</strong> <?php echo $config['production']['db_name']; ?></div>
                    <div><strong>Username:</strong> <?php echo $config['production']['db_user']; ?></div>
                    <div><strong>Password:</strong> ••••••••</div>
                <?php endif; ?>
            </div>
        </div>
        
        <form method="post">
            <div class="form-group">
                <button type="submit" name="mode" value="auto" class="btn btn-production" style="background:#0d9488;">
                    ⚡ Dùng AUTO (khuyến nghị)
                </button>
                <button type="submit" name="mode" value="local" class="btn btn-local">
                    🏠 Chuyển sang LOCAL
                </button>
                <button type="submit" name="mode" value="production" class="btn btn-production">
                    🌐 Chuyển sang PRODUCTION
                </button>
            </div>
        </form>
        
        <div class="info">
            <strong>ℹ️ Thông tin môi trường:</strong><br><br>

            <strong>⚡ AUTO (khuyến nghị):</strong><br>
            • localhost → dùng LOCAL<br>
            • domain hosting → dùng PRODUCTION<br>
            • An toàn nhất khi upload cùng code lên cả 2 nơi<br><br>
            
            <strong>🏠 LOCAL (XAMPP):</strong><br>
            • Database: <code>tntt_lap_tri</code><br>
            • Dùng cho phát triển và test trên máy local<br>
            • Nhanh và ổn định<br><br>
            
            <strong>🌐 PRODUCTION (Web Hosting):</strong><br>
            • Database: <code>o0kgbp36f9sl_tntt_lap_tri</code><br>
            • Dùng cho môi trường thực tế trên hosting<br>
            • Dữ liệu thật của người dùng<br><br>
            
            <strong>📝 Lưu ý:</strong><br>
            • Sau khi chuyển đổi, bạn sẽ được quay về trang chính<br>
            • Đảm bảo database tương ứng đã được thiết lập<br>
            • Dữ liệu giữa hai môi trường là riêng biệt<br>
            • <strong>Không để mode = local trên hosting</strong> — sẽ mất kết nối DB
        </div>
        
        <a href="index.php" class="btn btn-back">← Quay lại trang chính</a>
    </div>
</body>
</html>
