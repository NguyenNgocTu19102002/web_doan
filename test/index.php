<?php
session_start();
require_once 'login_redirect.php';
$logged_in = isset($_SESSION['user_id']);
// Thiết lập charset cho kết nối MySQL nếu có
if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

// Kiểm tra quyền
$current_role = login_current_role();
$is_admin = login_is_admin($current_role);
$is_hdt_manager = login_is_hdt_manager($current_role);
$is_huynh_truong = login_is_huynh_truong($current_role);
$can_view_year_summary = login_can_view_year_summary($current_role);

// Xác định trang hiện tại
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ĐOÀN TNTT DEMO — Môi trường Test</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800&family=Crimson+Text:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Nunito', sans-serif;
            line-height: 1.7;
            overflow-x: hidden;
            font-weight: 400;
        }

        /* Header hiện đại - giữ nguyên màu nền và kích cỡ */
        .modern-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.95) 0%, rgba(74, 144, 164, 0.95) 100%);
            backdrop-filter: blur(15px);
            z-index: 1000;
            padding: 0;
            transition: all 0.4s ease;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 25px rgba(44, 90, 160, 0.15);
            border-bottom-left-radius: 15px;
            border-bottom-right-radius: 15px;
            overflow: visible;
        }

        .modern-header.hidden {
            transform: translateY(-100%);
        }

        .modern-header.scrolled {
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.98) 0%, rgba(74, 144, 164, 0.98) 100%);
            box-shadow: 0 4px 30px rgba(44, 90, 160, 0.25);
        }

        .header-main {
            padding: 1rem 0;
        }

        .modern-header .container {
            max-width: 100%;
            padding-left: 3.375rem;
            padding-right: 3.375rem;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: nowrap;
            gap: 0.75rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-shrink: 0;
            min-width: 0;
        }

        .logo-section img {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            object-fit: contain;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-section img:hover {
            transform: scale(1.08) rotate(5deg);
        }

        .logo-text {
            font-family: 'Nunito', sans-serif;
            font-weight: 600;
            font-size: 1.05rem;
            color: white;
            text-decoration: none;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            letter-spacing: 0.3px;
            white-space: nowrap;
            line-height: 1.2;
        }

        .logo-text:hover {
            color: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }

        .main-nav {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: nowrap;
            flex-shrink: 1;
            min-width: 0;
            justify-content: flex-end;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            margin: 0;
            list-style: none;
            flex-wrap: nowrap;
            flex-direction: row;
            flex-shrink: 1;
            min-width: 0;
        }

        .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.85) !important;
            font-weight: 400;
            padding: 0.55rem 0.95rem;
            margin: 0;
            font-size: 0.9rem;
            letter-spacing: 0.1px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            border-radius: 25px;
            white-space: nowrap;
            display: inline-block;
            position: relative;
            flex-shrink: 0;
        }

        .navbar-nav .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 25px;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .navbar-nav .nav-link:hover {
            color: white !important;
            transform: translateY(-2px);
        }

        .navbar-nav .nav-link:hover::before {
            opacity: 1;
        }

        .navbar-nav .nav-link.active {
            color: white !important;
            background: rgba(255, 255, 255, 0.15);
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .navbar-nav .nav-link.active::before {
            opacity: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 0.5rem;
            flex-shrink: 0;
        }

        .login-btn {
            width: auto;
            padding: 0.55rem 1rem;
            border-radius: 25px;
            font-size: 0.88rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.85) !important;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1;
        }

        .login-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
            color: white !important;
            transform: translateY(-2px);
        }

        .navbar-toggler {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem;
            border-radius: 5px;
            position: relative;
            z-index: 1001;
            cursor: pointer;
        }
        
        .navbar-toggler:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 255, 255, 0.25);
        }

        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.85%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        @media (max-width: 992px) {
            .main-nav {
                display: none;
            }
            
            .navbar-toggler {
                display: block !important;
                position: relative;
                z-index: 1001;
            }
        }

        @media (min-width: 993px) and (max-width: 1400px) {
            .logo-section img {
                width: 52px;
                height: 52px;
            }

            .logo-text {
                font-size: 0.92rem;
            }

            .navbar-nav .nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.84rem;
            }

            .login-btn {
                padding: 0.5rem 0.85rem;
                font-size: 0.82rem;
            }

            .main-nav {
                gap: 0.45rem;
            }
        }
        
        @media (max-width: 576px) {
            .header-main {
                padding: 0.6rem 0;
            }
            
            .logo-section img {
                width: 40px;
                height: 40px;
            }
            
            .logo-text {
                font-size: 0.65rem;
                white-space: nowrap;
            }
            
            .hero-title {
                font-size: 2rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
        }

        /* Hero Section với ảnh nền */
        .hero-section {
            height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(44, 90, 160, 0.6)), 
                        url('img/backgroud.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            position: relative;
            will-change: transform;
        }

        .hero-content {
            max-width: 800px;
            padding: 0 20px;
            animation: fadeInUp 1s ease-out;
        }

        .hero-title {
            font-family: 'Crimson Text', serif;
            font-size: 3.8rem;
            font-weight: 700;
            margin-bottom: 25px;
            text-shadow: 0 4px 25px rgba(0, 0, 0, 0.6);
            line-height: 1.2;
            letter-spacing: 1px;
        }

        .hero-subtitle {
            font-size: 1.35rem;
            margin-bottom: 35px;
            opacity: 0.95;
            font-weight: 400;
            text-shadow: 0 2px 15px rgba(0, 0, 0, 0.4);
            line-height: 1.5;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-hero {
            padding: 15px 35px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .btn-hero.primary {
            background: #2c5aa0;
            color: white;
            border-color: #2c5aa0;
        }

        .btn-hero.primary:hover {
            background: transparent;
            color: white;
            border-color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .btn-hero.secondary {
            background: transparent;
            color: white;
            border-color: white;
        }

        .btn-hero.secondary:hover {
            background: white;
            color: #2c5aa0;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        /* Scroll indicator */
        .scroll-indicator {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            animation: bounce 2s infinite;
        }

        .scroll-indicator i {
            font-size: 2rem;
            color: white;
            opacity: 0.8;
        }

        /* Main content */
        .main-content {
            padding: 100px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-title h2 {
            font-family: 'Crimson Text', serif;
            font-size: 2.6rem;
            color: #2c5aa0;
            margin-bottom: 18px;
            position: relative;
            font-weight: 600;
        }

        .section-title h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: linear-gradient(90deg, #2c5aa0, #4a90a4);
            border-radius: 2px;
        }

        .section-title p {
            font-size: 1.15rem;
            color: #666;
            max-width: 650px;
            margin: 0 auto;
            font-weight: 400;
            line-height: 1.6;
        }

        /* Cards hiện đại */
        .feature-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .feature-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2c5aa0, #4a90a4);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .feature-card:hover::before {
            transform: translateX(0);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #2c5aa0, #4a90a4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            transition: all 0.3s ease;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.1);
        }

        .feature-icon i {
            font-size: 2rem;
            color: white;
        }

        .feature-card h3 {
            font-size: 1.45rem;
            color: #2c5aa0;
            margin-bottom: 18px;
            font-weight: 600;
            font-family: 'Nunito', sans-serif;
        }

        .feature-card p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .btn-card {
            background: linear-gradient(135deg, #2c5aa0, #4a90a4);
            color: white;
            padding: 12px 25px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-block;
            border: 2px solid transparent;
            box-shadow: 0 4px 15px rgba(44, 90, 160, 0.2);
        }

        .btn-card:hover {
            background: transparent;
            color: white;
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 6px 20px rgba(255, 255, 255, 0.1);
        }

        /* Footer hiện đại */
        .modern-footer {
            background: linear-gradient(135deg, #1e3a5f 0%, #2c5aa0 100%);
            color: white;
            padding: 60px 0 30px;
            position: relative;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-section h3 {
            font-family: 'Crimson Text', serif;
            font-size: 1.35rem;
            margin-bottom: 22px;
            color: white;
            font-weight: 600;
        }

        .footer-section p,
        .footer-section a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            margin-bottom: 10px;
            display: block;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: white;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            background: white;
            color: #2c5aa0;
            transform: translateY(-2px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateX(-50%) translateY(0);
            }
            40% {
                transform: translateX(-50%) translateY(-10px);
            }
            60% {
                transform: translateX(-50%) translateY(-5px);
            }
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            /* Tắt parallax và transform trên mobile để tránh giật */
            .hero-section {
                background-attachment: scroll !important;
                transform: none !important;
                -webkit-transform: none !important;
            }
            
            .main-nav {
                display: none;
            }
            
            /* Header Mobile */
            .modern-header {
                padding: 0;
                overflow: visible !important;
            }
            
            .header-main {
                padding: 0.8rem 0;
            }
            
            .header-content {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
            
            .logo-section {
                flex: 1;
                gap: 10px;
            }
            
            .logo-section img {
                width: 45px;
                height: 45px;
            }
            
            .logo-text {
                font-size: 0.75rem;
                line-height: 1.2;
                white-space: nowrap;
            }
            
            .navbar-toggler {
                margin-left: auto;
                padding: 0.4rem;
                position: relative;
                z-index: 1001;
                display: block !important;
                cursor: pointer;
            }
            
            /* Mobile Menu Dropdown */
            .navbar-collapse {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: linear-gradient(135deg, rgba(44, 90, 160, 0.98) 0%, rgba(74, 144, 164, 0.98) 100%);
                backdrop-filter: blur(10px);
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
                margin-top: 0;
                border-radius: 0 0 15px 15px;
                z-index: 999;
            }
            
            .navbar-collapse .container {
                padding: 0;
            }
            
            .navbar-collapse .navbar-nav {
                flex-direction: column;
                padding: 1rem 0;
                gap: 0;
            }
            
            .navbar-collapse .nav-item {
                width: 100%;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .navbar-collapse .nav-item:last-child {
                border-bottom: none;
            }
            
            .navbar-collapse .nav-link {
                padding: 0.8rem 1.5rem;
                color: rgba(255, 255, 255, 0.95) !important;
                border-radius: 0;
                text-align: left;
                display: block;
                width: 100%;
            }
            
            .navbar-collapse .nav-link:hover,
            .navbar-collapse .nav-link.active {
                background: rgba(255, 255, 255, 0.15);
                color: white !important;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.1rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .feature-cards {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .section-title h2 {
                font-size: 2rem;
            }

            /* Tối ưu performance trên mobile */
            * {
                -webkit-tap-highlight-color: transparent;
            }

            .feature-card {
                transform: translateZ(0);
                -webkit-transform: translateZ(0);
            }

            /* Smooth scrolling cho mobile */
            body {
                -webkit-overflow-scrolling: touch;
                overflow-scrolling: touch;
            }
        }

        /* Loading animation */
        .loading {
            opacity: 0;
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .loading:nth-child(1) { animation-delay: 0.1s; }
        .loading:nth-child(2) { animation-delay: 0.2s; }
        .loading:nth-child(3) { animation-delay: 0.3s; }
        .loading:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <!-- Header hiện đại -->
    <header class="modern-header" id="header">
        <div class="header-main">
            <div class="container">
                <div class="header-content">
                    <div class="logo-section">
                        <img src="img/logo.png" alt="Logo Đoàn">
                        <a href="index.php" class="logo-text">ĐOÀN TNTT DEMO TEST</a>
                    </div>
                    
                    <nav class="main-nav">
                        <ul class="navbar-nav">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">Trang chủ</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'classes.php' ? 'active' : ''; ?>" href="classes.php">Quản lý đoàn sinh</a>
                            </li>
                            <?php if ($logged_in && $is_hdt_manager): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'diemdanh.php' ? 'active' : ''; ?>" href="diemdanh.php">Quản lý điểm danh</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo in_array($current_page, array('nien_hoc.php', 'nien_hoc_tong_ket.php')) ? 'active' : ''; ?>" href="nien_hoc.php">Năm học</a>
                            </li>
                            <?php elseif ($logged_in && $is_huynh_truong): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo in_array($current_page, array('nien_hoc_xem.php', 'nien_hoc_tong_ket.php')) ? 'active' : ''; ?>" href="nien_hoc_xem.php">Tổng kết năm học</a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'loichua.php' ? 'active' : ''; ?>" href="loichua.php">Lời Chúa</a>
                            </li>
                        </ul>
                        
                        <div class="header-actions">
                            <?php if ($logged_in): ?>
                                <a href="tai_khoan.php" class="login-btn <?php echo $current_page == 'tai_khoan.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-user-circle"></i> Tài khoản
                                </a>
                                <a href="logout.php" class="login-btn">
                                    <i class="fas fa-sign-out-alt"></i> Đăng xuất
                                </a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars(login_page_url()); ?>" class="login-btn <?php echo $current_page == 'login.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-sign-in-alt"></i> Đăng nhập
                                </a>
                            <?php endif; ?>
                        </div>
                    </nav>
                    
                    <!-- Mobile Toggler -->
                    <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </div>
            
            <!-- Collapsed Mobile Menu -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="container">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">Trang chủ</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'classes.php' ? 'active' : ''; ?>" href="classes.php">Quản lý đoàn sinh</a>
                        </li>
                        <?php if ($logged_in && $is_hdt_manager): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'diemdanh.php' ? 'active' : ''; ?>" href="diemdanh.php">Quản lý điểm danh</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, array('nien_hoc.php', 'nien_hoc_tong_ket.php')) ? 'active' : ''; ?>" href="nien_hoc.php">Năm học</a>
                        </li>
                        <?php elseif ($logged_in && $is_huynh_truong): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, array('nien_hoc_xem.php', 'nien_hoc_tong_ket.php')) ? 'active' : ''; ?>" href="nien_hoc_xem.php">Tổng kết năm học</a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'loichua.php' ? 'active' : ''; ?>" href="loichua.php">Lời Chúa</a>
                        </li>
                        <?php if ($logged_in): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'tai_khoan.php' ? 'active' : ''; ?>" href="tai_khoan.php">Tài khoản</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="logout.php">Đăng xuất</a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'login.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(login_page_url()); ?>">Đăng nhập</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-content">
            <h1 class="hero-title">ĐOÀN TNTT<br>THÁNH GIUSE DEMO</h1>
            <p class="hero-subtitle">Trang demo để thử nghiệm hệ thống quản lý đoàn sinh. Mọi tên, số liên hệ và hoạt động hiển thị ở đây đều là thông tin mẫu, không phản ánh dữ liệu thật.</p>
            <div class="hero-buttons">
                <a href="classes.php" class="btn-hero primary">
                    <i class="fas fa-users me-2"></i>Quản lý đoàn sinh
                </a>
                <a href="#features" class="btn-hero secondary">
                    <i class="fas fa-info-circle me-2"></i>Tìm hiểu thêm
                </a>
            </div>
        </div>
        <div class="scroll-indicator">
            <i class="fas fa-chevron-down"></i>
        </div>
    </section>

    <!-- Main Content -->
    <main class="main-content" id="features">
        <div class="container">
            <div class="section-title">
                <h2>Tính năng chính</h2>
                <p>Hệ thống quản lý đoàn sinh — phiên bản thử nghiệm (dữ liệu giả lập)</p>
            </div>
            
            <div class="feature-cards">
                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Quản lý đoàn sinh</h3>
                    <p>Theo dõi thông tin, điểm danh và đánh giá kết quả học tập của đoàn sinh một cách hiệu quả</p>
                    <a href="classes.php" class="btn-card">Truy cập ngay</a>
                </div>

                <?php if ($logged_in && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'hdt_manager'): ?>
                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h3>Quản lý điểm danh</h3>
                    <p>Ghi nhận và theo dõi điểm danh các buổi sinh hoạt của đoàn sinh</p>
                    <a href="diemdanh.php" class="btn-card">Điểm danh ngay</a>
                </div>

                
                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h3>Quản lý Huynh Dự Trưởng</h3>
                    <p>Quản lý thông tin và hoạt động của đội ngũ Huynh Trưởng và Huynh Dự Trưởng</p>
                    <a href="hdt_management.php" class="btn-card">Quản lý HDT</a>
                </div>

                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>Điểm danh Huynh Trưởng</h3>
                    <p>Theo dõi tham gia các hoạt động của Huynh Trưởng trong đoàn</p>
                    <a href="hdt_attendance.php" class="btn-card">Điểm danh HT</a>
                </div>
                <?php endif; ?>

                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-bible"></i>
                    </div>
                    <h3>Lời Chúa hàng ngày</h3>
                    <p id="daily-reading">Đang tải Lời Chúa...</p>
                    <a href="#" class="btn-card">Đọc thêm</a>
                </div>

                <div class="feature-card loading">
                    <div class="feature-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <h3>Thông báo</h3>
                    <p>Cập nhật các hoạt động và thông báo mới nhất của đoàn</p>
                    <div style="text-align: left; margin-top: 20px;">
                        <div style="margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 8px; font-size: 0.9rem;">
                            <i class="fas fa-calendar text-primary me-2"></i>
                            <strong>Sinh hoạt tuần (mẫu):</strong> Thứ Bảy, 14h00 — Nhà sinh hoạt Demo
                        </div>
                        <div style="margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 8px; font-size: 0.9rem;">
                            <i class="fas fa-campground text-success me-2"></i>
                            <strong>Trại hè thử nghiệm:</strong> 01–03/07/2026 — Khu du lịch Demo, tỉnh Test
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer hiện đại -->
    <footer class="modern-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Đoàn TNTT Demo Test</h3>
                    <p>Môi trường thử nghiệm phần mềm quản lý đoàn sinh. Nội dung chỉ dùng để demo, không phải thông tin liên hệ hay hoạt động thực tế.</p>
                    <div class="social-links">
                        <a href="#" title="Facebook demo (không liên kết thật)">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="mailto:demo@test.local">
                            <i class="fas fa-envelope"></i>
                        </a>
                        <a href="tel:0900000000">
                            <i class="fas fa-phone"></i>
                        </a>
                    </div>
                </div>
                
                <div class="footer-section">
                    <h3>Liên hệ (giả lập)</h3>
                    <a href="mailto:demo@test.local">demo@test.local</a>
                    <a href="tel:0900000000">0900 000 000</a>
                    <p>Địa chỉ: 123 Đường Demo, Phường Test, TP. Hà Nội</p>
                </div>
                
                <div class="footer-section">
                    <h3>Hoạt động</h3>
                    <a href="#">Sinh hoạt hàng tuần</a>
                    <a href="#">Trại hè</a>
                    <a href="#">Hoạt động từ thiện</a>
                    <a href="#">Giáo lý</a>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2026 Đoàn TNTT Demo Test — Môi trường thử nghiệm, dữ liệu giả.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Header scroll effect - giống header chung
        let lastScrollTop = 0;
        const header = document.getElementById('header');
        const scrollThreshold = 10;
        
        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop > scrollThreshold) {
                // Cuộn xuống
                if (scrollTop > lastScrollTop) {
                    header.classList.add('hidden');
                } 
                // Cuộn lên
                else {
                    header.classList.remove('hidden');
                }
            } else {
                // Ở đầu trang, luôn hiển thị header
                header.classList.remove('hidden');
            }
            
            // Thêm class scrolled khi scroll
            if (scrollTop > 100) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
            
            lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
        });

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Load daily reading (placeholder)
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                document.getElementById('daily-reading').textContent = 
                    'Phúc cho ai có lòng nghèo khó, vì Nước Trời là của họ. (Mt 5,3)';
            }, 1000);
        });

        // Parallax effect for hero background (disabled on mobile for better performance)
        if (window.innerWidth > 768) {
            window.addEventListener('scroll', function() {
                const scrolled = window.pageYOffset;
                const parallax = document.querySelector('.hero-section');
                const speed = scrolled * 0.5;
                if (parallax) {
                    parallax.style.transform = `translateY(${speed}px)`;
                }
            });
        }
    </script>
    <?php login_include_bo_nhiem_popup(); ?>
</body>
</html>