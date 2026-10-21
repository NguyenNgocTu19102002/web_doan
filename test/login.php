<?php
session_start();
require_once 'login_redirect.php';

// Đã đăng nhập → quay lại trang redirect
login_redirect_if_already_authenticated(isset($_GET['redirect']) ? $_GET['redirect'] : '');

$redirect = get_safe_login_redirect(isset($_GET['redirect']) ? $_GET['redirect'] : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'db_connect.php';

    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $posted_redirect = isset($_POST['redirect']) ? $_POST['redirect'] : $redirect;

    $username_esc = $conn->real_escape_string($username);
    $password_esc = $conn->real_escape_string($password);
    $result = $conn->query("SELECT * FROM users WHERE username = '$username_esc' AND password = '$password_esc'");

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $role = isset($user['role']) ? trim((string)$user['role']) : 'admin';
        $_SESSION['user_role'] = $role;

        login_redirect_after_auth($role, $posted_redirect);
    }

    $error = 'Sai tài khoản hoặc mật khẩu';
    $redirect = get_safe_login_redirect($posted_redirect);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(app_url('img/logo.png')); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Nunito', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(91, 163, 217, .35), transparent 55%),
                radial-gradient(ellipse 70% 50% at 85% 85%, rgba(26, 69, 139, .28), transparent 50%),
                linear-gradient(145deg, #0d47a1 0%, #1565c0 38%, #1a458b 100%);
            overflow-x: hidden;
        }

        .login-bg-shape {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(60px);
            opacity: .45;
        }
        .login-bg-shape-1 {
            width: 420px; height: 420px;
            background: #64b5f6;
            top: -120px; right: -80px;
            animation: float 8s ease-in-out infinite;
        }
        .login-bg-shape-2 {
            width: 320px; height: 320px;
            background: #fdb913;
            bottom: -100px; left: -60px;
            animation: float 10s ease-in-out infinite reverse;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-18px) scale(1.04); }
        }

        .login-wrap {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            animation: fadeUp .6s cubic-bezier(.16, 1, .3, 1) both;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: none; }
        }

        .login-card {
            background: rgba(255, 255, 255, .94);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, .65);
            box-shadow:
                0 24px 64px rgba(0, 0, 0, .22),
                0 0 0 1px rgba(255, 255, 255, .08) inset;
            overflow: hidden;
        }

        .login-header {
            text-align: center;
            padding: 2rem 1.75rem 1.5rem;
            background: linear-gradient(180deg, rgba(227, 242, 253, .6) 0%, transparent 100%);
        }

        .login-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            background: #fff;
            padding: 6px;
            box-shadow:
                0 0 0 3px rgba(26, 69, 139, .15),
                0 8px 24px rgba(26, 69, 139, .18);
        }
        .login-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
            display: block;
        }

        .login-header h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #1a458b;
            letter-spacing: -.01em;
            line-height: 1.3;
        }
        .login-header p {
            margin-top: .35rem;
            font-size: .88rem;
            color: #78909c;
            font-weight: 600;
        }

        .login-body {
            padding: 0 1.75rem 2rem;
        }

        .login-alert {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            border-radius: 12px;
            background: #ffebee;
            border: 1px solid #ffcdd2;
            color: #c62828;
            font-size: .88rem;
            font-weight: 600;
            animation: shake .4s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        .field-group {
            margin-bottom: 1.15rem;
        }
        .field-group label {
            display: block;
            font-size: .82rem;
            font-weight: 700;
            color: #455a64;
            margin-bottom: .45rem;
            letter-spacing: .02em;
        }

        .input-wrap {
            position: relative;
        }
        .input-wrap > i.field-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #90a4ae;
            font-size: .95rem;
            pointer-events: none;
            transition: color .2s ease;
        }
        .input-wrap input {
            width: 100%;
            padding: .82rem 1rem .82rem 2.65rem;
            border: 2px solid #e3eaf3;
            border-radius: 14px;
            font-family: inherit;
            font-size: .95rem;
            font-weight: 600;
            color: #263238;
            background: #f8fafc;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
            outline: none;
        }
        .input-wrap input::placeholder {
            color: #b0bec5;
            font-weight: 500;
        }
        .input-wrap input:focus {
            border-color: #1565c0;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(21, 101, 192, .12);
        }
        .input-wrap:focus-within > i.field-icon {
            color: #1565c0;
        }

        .input-wrap.has-toggle input {
            padding-right: 2.75rem;
        }
        .toggle-pw {
            position: absolute;
            right: .75rem;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #90a4ae;
            cursor: pointer;
            padding: .35rem;
            font-size: .95rem;
            line-height: 1;
            border-radius: 8px;
            transition: color .2s ease, background .2s ease;
        }
        .toggle-pw:hover {
            color: #1565c0;
            background: rgba(21, 101, 192, .08);
        }

        .login-submit {
            width: 100%;
            margin-top: .5rem;
            padding: .9rem 1.25rem;
            border: none;
            border-radius: 14px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: .03em;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #2a5fad 0%, #1565c0 50%, #1a458b 100%);
            box-shadow: 0 8px 24px rgba(21, 101, 192, .35);
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
        }
        .login-submit:hover {
            filter: brightness(1.06);
            box-shadow: 0 12px 28px rgba(21, 101, 192, .42);
            transform: translateY(-1px);
        }
        .login-submit:active {
            transform: translateY(0);
            box-shadow: 0 6px 16px rgba(21, 101, 192, .3);
        }
        .login-submit:disabled {
            opacity: .7;
            cursor: not-allowed;
            transform: none;
        }

        .login-footer {
            text-align: center;
            margin-top: 1.25rem;
            padding-top: 1.15rem;
            border-top: 1px solid #eceff1;
        }
        .login-footer a {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            color: #546e7a;
            font-size: .88rem;
            font-weight: 700;
            text-decoration: none;
            transition: color .2s ease;
        }
        .login-footer a:hover {
            color: #1565c0;
        }

        @media (max-width: 480px) {
            .login-header { padding: 1.5rem 1.25rem 1.15rem; }
            .login-body { padding: 0 1.25rem 1.5rem; }
            .login-header h1 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <div class="login-bg-shape login-bg-shape-1" aria-hidden="true"></div>
    <div class="login-bg-shape login-bg-shape-2" aria-hidden="true"></div>

    <div class="login-wrap">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <img src="<?php echo htmlspecialchars(app_url('img/logo.png')); ?>" alt="Logo Đoàn TNTT">
                </div>
                <h1>Đăng Nhập</h1>
                <p>ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</p>
            </div>

            <div class="login-body">
                <?php if (isset($error)): ?>
                    <div class="login-alert" role="alert">
                        <i class="fas fa-circle-exclamation"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" novalidate>
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                    <div class="field-group">
                        <label for="username">Tên đăng nhập</label>
                        <div class="input-wrap">
                            <i class="fas fa-user field-icon"></i>
                            <input type="text" id="username" name="username" required autocomplete="username" placeholder="Nhập tên đăng nhập" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                    </div>

                    <div class="field-group">
                        <label for="password">Mật khẩu</label>
                        <div class="input-wrap has-toggle">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu">
                            <button type="button" class="toggle-pw" id="togglePw" aria-label="Hiện mật khẩu">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-submit" id="loginBtn">
                        <i class="fas fa-right-to-bracket"></i>
                        Đăng Nhập
                    </button>
                </form>

                <div class="login-footer">
                    <a href="<?php echo htmlspecialchars(app_url('index.php')); ?>">
                        <i class="fas fa-arrow-left"></i>
                        Về trang chủ
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var toggle = document.getElementById('togglePw');
        var pw = document.getElementById('password');
        var form = document.getElementById('loginForm');
        var btn = document.getElementById('loginBtn');

        if (toggle && pw) {
            toggle.addEventListener('click', function () {
                var show = pw.type === 'password';
                pw.type = show ? 'text' : 'password';
                toggle.innerHTML = show
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
                toggle.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
            });
        }

        if (form && btn) {
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang đăng nhập...';
            });
        }
    })();
    </script>
</body>
</html>
