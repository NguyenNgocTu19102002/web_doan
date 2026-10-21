<?php

session_start();



require_once 'db_connect.php';

require_once __DIR__ . '/scripts/loichua_parser.php';

require_once __DIR__ . '/scripts/liturgy_calendar.php';



$mysqli = $conn;

$db_error = '';



if (!$db_error) {

    $create_table_sql = "CREATE TABLE IF NOT EXISTS loichua_daily (

      id INT AUTO_INCREMENT PRIMARY KEY,

      ngay DATE NOT NULL UNIQUE,

      title VARCHAR(255),

      gospel LONGTEXT,

      reflection LONGTEXT,

      feast VARCHAR(500) NULL,

      liturgy_calendar VARCHAR(500) NULL,

      readings_summary VARCHAR(500) NULL,

      liturgical_color VARCHAR(50) NULL,

      reading_1 LONGTEXT NULL,

      reading_psalm LONGTEXT NULL,

      reading_alleluia LONGTEXT NULL,

      gospel_ref VARCHAR(120) NULL,

      liturgy_year VARCHAR(10) NULL,

      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

      INDEX idx_ngay (ngay)

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $mysqli->query($create_table_sql);

    // Tự xóa sau 30 ngày: hôm nay 21/10 → xóa 21/09 trở về trước
    $mysqli->query("DELETE FROM loichua_daily WHERE ngay <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

    loichua_ensure_columns($mysqli);

    liturgy_calendar_ensure_table($mysqli);

}



/** Bản ghi của ngày $date được tải/cập nhật cách đây hơn $seconds giây. */
function loichua_fetched_before($mysqli, $date, $seconds) {
    $d = $mysqli->real_escape_string($date);
    $res = $mysqli->query("SELECT UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) AS ts FROM loichua_daily WHERE ngay = '$d' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || $row['ts'] === null) {
        return true;
    }
    return ((int) $row['ts']) < time() - $seconds;
}

function loichua_theme_class($liturgical_color) {
    if (function_exists('mb_strtolower')) {
        $c = mb_strtolower(trim($liturgical_color), 'UTF-8');
    } else {
        $c = strtolower(trim($liturgical_color));
    }

    if ($c === '') {
        return 'theme-default';
    }

    $has = function ($needle) use ($c) {
        if (function_exists('mb_strpos')) {
            return mb_strpos($c, $needle, 0, 'UTF-8') !== false;
        }
        return strpos($c, $needle) !== false;
    };

    if ($has('xanh')) {
        return 'theme-green';
    }

    if ($has('tím') || $has('tim')) {
        return 'theme-purple';
    }

    if ($has('trắng') || $has('trang')) {
        return 'theme-white';
    }

    if ($has('đỏ') || $has('do')) {
        return 'theme-red';
    }

    if ($has('hồng') || $has('hong')) {
        return 'theme-rose';
    }

    return 'theme-default';
}



$today = date('Y-m-d');
// Xem trước được 1 năm (nguồn loichuahomnay.vn đã có bài đọc các ngày sắp tới)
$calendar_max_date = date('Y-m-d', strtotime('+365 days'));

$view_date = $today;

if (isset($_GET['ngay']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ngay'])) {

    $view_date = $_GET['ngay'];

}

if ($view_date > $calendar_max_date) {
    $view_date = $calendar_max_date;
}

// Ngày đã quá 30 ngày bị xóa tự động — không cho xem / tải lại
$calendar_min_date = date('Y-m-d', strtotime('-29 days'));
if ($view_date < $calendar_min_date) {
    $view_date = $calendar_min_date;
}



$title = '';

$gospel_html = '';

$reflection_html = '';

$reading_1_html = '';

$reading_psalm_html = '';

$reading_alleluia_html = '';

$feast = '';

$liturgy_calendar = '';

$readings_summary = '';

$liturgical_color = '';

$gospel_ref = '';

$reading_1_ref = '';

$liturgy_year = '';

$has_content = false;

$has_calendar = false;



$days_vn = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];

$reading_day_name = $days_vn[(int)date('w', strtotime($view_date))];

$reading_date_display = date('d/m/Y', strtotime($view_date));

$prev_date = date('Y-m-d', strtotime($view_date . ' -1 day'));

$next_date = date('Y-m-d', strtotime($view_date . ' +1 day'));

$can_go_next = $next_date <= $calendar_max_date;

$can_go_prev = $prev_date >= $calendar_min_date;

$is_today = ($view_date === $today);

$day_of_month = date('d', strtotime($view_date));

$month_year = date('m/Y', strtotime($view_date));



if (!$db_error) {

    $calendar_row = liturgy_calendar_load($mysqli, $view_date);

    if ($calendar_row) {

        $has_calendar = true;

        $feast = $calendar_row['feast'] ?: '';

        $liturgy_calendar = $calendar_row['liturgy_calendar'] ?: '';

        $readings_summary = $calendar_row['readings_summary'] ?: '';

        $liturgical_color = $calendar_row['liturgical_color'] ?: '';

        $liturgy_year = $calendar_row['liturgy_year'] ?: '';

        $gospel_ref = $calendar_row['gospel_ref'] ?: '';

        $reading_1_ref = $calendar_row['reading_1_ref'] ?: '';

    }



    $row = loichua_load_from_db($mysqli, $view_date);

    if ($row) {

        $title = $row['title'] ?: '';

        $gospel_html = $row['gospel'] ?: '';

        $reflection_html = $row['reflection'] ?: '';

        $reading_1_html = $row['reading_1'] ?: '';

        $reading_psalm_html = $row['reading_psalm'] ?: '';

        $reading_alleluia_html = $row['reading_alleluia'] ?: '';

        if (!$has_calendar) {

            $feast = $row['feast'] ?: '';

            $liturgy_calendar = $row['liturgy_calendar'] ?: '';

            $readings_summary = $row['readings_summary'] ?: '';

            $liturgical_color = $row['liturgical_color'] ?: '';

            $gospel_ref = $row['gospel_ref'] ?: '';

            $liturgy_year = $row['liturgy_year'] ?: '';

        }

        $has_content = ($gospel_html !== '' || $reflection_html !== '' || $reading_1_html !== ''
            || $reading_psalm_html !== '' || $reading_alleluia_html !== '');

        $stale_readings = $has_content && $reading_psalm_html === ''
            && ($reading_1_html !== '' || $gospel_html !== '')
            && loichua_fetched_before($mysqli, $view_date, 6 * 3600);

        // Ngày tương lai được lưu khi nguồn chưa có Suy niệm → tới ngày thì tải lại (tối đa 6 giờ/lần)
        $missing_reflection = $has_content && $reflection_html === '' && $view_date <= $today
            && loichua_fetched_before($mysqli, $view_date, 6 * 3600);

        if ($stale_readings || $missing_reflection) {

            $fetchResult = loichua_fetch_and_save($mysqli, $view_date);

            if (empty($fetchResult['success'])) {
                $mysqli->query("UPDATE loichua_daily SET updated_at = NOW() WHERE ngay = '" . $mysqli->real_escape_string($view_date) . "'");
            }

            if (!empty($fetchResult['success'])) {

                $row = loichua_load_from_db($mysqli, $view_date);

                if ($row) {

                    $title = $row['title'] ?: '';

                    $gospel_html = $row['gospel'] ?: '';

                    $reflection_html = $row['reflection'] ?: '';

                    $reading_1_html = $row['reading_1'] ?: '';

                    $reading_psalm_html = $row['reading_psalm'] ?: '';

                    $reading_alleluia_html = $row['reading_alleluia'] ?: '';

                }

            }

        }

    }



    if (!$has_content) {

        $fetchResult = loichua_fetch_and_save($mysqli, $view_date);

        if (!empty($fetchResult['success'])) {

            $row = loichua_load_from_db($mysqli, $view_date);

            if ($row) {

                $title = $row['title'] ?: '';

                $gospel_html = $row['gospel'] ?: '';

                $reflection_html = $row['reflection'] ?: '';

                $reading_1_html = $row['reading_1'] ?: '';

                $reading_psalm_html = $row['reading_psalm'] ?: '';

                $reading_alleluia_html = $row['reading_alleluia'] ?: '';

                if (!$has_calendar) {

                    $feast = $row['feast'] ?: '';

                    $liturgy_calendar = $row['liturgy_calendar'] ?: '';

                    $readings_summary = $row['readings_summary'] ?: '';

                    $liturgical_color = $row['liturgical_color'] ?: '';

                    $gospel_ref = $row['gospel_ref'] ?: '';

                    $liturgy_year = $row['liturgy_year'] ?: '';

                }

                $has_content = true;

            }

        }

    }



    if ($has_calendar && $calendar_row) {

        if (!$gospel_ref && !empty($calendar_row['gospel_ref'])) {

            $gospel_ref = $calendar_row['gospel_ref'];

        }

        if (!$reading_1_ref && !empty($calendar_row['reading_1_ref'])) {

            $reading_1_ref = $calendar_row['reading_1_ref'];

        }

    } elseif (!$reading_1_ref && $readings_summary) {

        if (preg_match('/^([^;]+)/u', $readings_summary, $m)) {

            $reading_1_ref = trim($m[1]);

        }

    }

}



if (!$liturgy_calendar && $title) {

    $liturgy_calendar = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $title));

}



$empty_message = '';

if (!$has_content) {

    $empty_message = $has_calendar

        ? 'Đã có lịch phụng vụ nhưng chưa tải được nội dung bài đọc. Thử tải lại sau hoặc kiểm tra kết nối mạng.'

        : 'Chưa có Lời Chúa cho ngày này. Hãy nhập lịch phụng vụ hoặc đợi hệ thống tự tải.';

}



$theme_class = loichua_theme_class($liturgical_color);

$has_reading_1 = $has_content && $reading_1_html !== '';

$has_psalm = $has_content && $reading_psalm_html !== '';

$has_alleluia = $has_content && $reading_alleluia_html !== '';

$has_gospel = $has_content && $gospel_html !== '';

$has_reflection = $has_content && $reflection_html !== '';

$has_loi_chua = $has_reading_1 || $has_psalm || $has_alleluia || $has_gospel;

$show_tabs = $has_loi_chua && $has_reflection;

$liturgy_name_fixes = array(
    'Mùa Quanh Năm' => 'Mùa Thường Niên',
    'mùa Quanh Năm' => 'mùa Thường Niên',
    'Mùa quanh năm' => 'Mùa Thường Niên',
    'mùa quanh năm' => 'mùa Thường Niên',
    'MÙA QUANH NĂM' => 'MÙA THƯỜNG NIÊN',
    'Quanh Năm' => 'Thường Niên',
    'quanh năm' => 'Thường Niên',
    'Thường niên' => 'Thường Niên',
    'thường niên' => 'Thường Niên',
);
$feast = strtr($feast, $liturgy_name_fixes);
$liturgy_calendar = strtr($liturgy_calendar, $liturgy_name_fixes);

?>

<!DOCTYPE html>

<html lang="vi">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lời Chúa <?php echo $is_today ? 'Hôm Nay' : $reading_date_display; ?> | TNTT Đaminh Savio</title>

    <link rel="icon" type="image/png" href="img/logo.png">

    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Crimson+Text:wght@400;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <link rel="stylesheet" href="css/loichua.css">

</head>

<body class="loichua-page <?php echo htmlspecialchars($theme_class); ?>">



    <header class="lc-topbar">

        <div class="lc-topbar-inner">

            <a href="index.php" class="lc-back" title="Trang chủ"><i class="fa-solid fa-arrow-left"></i><span>Trang chủ</span></a>

            <h1 class="lc-topbar-title"><i class="fa-solid fa-book-bible"></i> Lời Chúa <?php echo $is_today ? 'Hôm Nay' : $reading_date_display; ?></h1>

            <img src="img/logo.png" alt="" class="lc-topbar-logo">

        </div>

    </header>



    <main class="lc-main">



        <section class="lc-hero">
            <div class="lc-hero-inner">
                <div class="lc-hero-date">
                    <span class="lc-hero-weekday"><?php echo htmlspecialchars($reading_day_name); ?></span>
                    <span class="lc-hero-day"><?php echo htmlspecialchars($day_of_month); ?></span>
                    <span class="lc-hero-month">Tháng <?php echo htmlspecialchars($month_year); ?></span>
                </div>

                <div class="lc-hero-info">
                    <div class="lc-hero-kicker">
                        <?php if ($is_today): ?><span class="lc-today-badge"><span class="lc-today-dot"></span>Hôm nay</span><?php endif; ?>
                        <span>Lời Chúa mỗi ngày</span>
                    </div>
                    <?php $hero_title = $liturgy_calendar ? $liturgy_calendar : $feast; ?>
                    <?php if ($hero_title): ?>
                        <h2 class="lc-hero-title"><?php echo htmlspecialchars($hero_title); ?></h2>
                    <?php endif; ?>

                    <div class="lc-chips">
                        <?php if ($liturgical_color): ?>
                            <span class="lc-chip"><span class="lc-swatch"></span> Áo lễ <?php echo htmlspecialchars($liturgical_color); ?></span>
                        <?php endif; ?>
                        <?php if ($liturgy_year): ?>
                            <span class="lc-chip"><i class="fa-solid fa-book"></i> Năm <?php echo htmlspecialchars($liturgy_year); ?></span>
                        <?php endif; ?>
                        <?php if ($reading_1_ref): ?>
                            <span class="lc-chip ref"><i class="fa-solid fa-scroll"></i> <?php echo htmlspecialchars($reading_1_ref); ?></span>
                        <?php endif; ?>
                        <?php if ($gospel_ref): ?>
                            <span class="lc-chip ref"><i class="fa-solid fa-cross"></i> <?php echo htmlspecialchars($gospel_ref); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <div class="lc-datebar">
            <a href="loichua.php?ngay=<?php echo $prev_date; ?>" class="lc-nav-btn <?php echo $can_go_prev ? '' : 'disabled'; ?>" title="Ngày trước">
                <i class="fa-solid fa-chevron-left"></i><span>Ngày trước</span>
            </a>
            <div class="lc-date-picker-wrap">
                <i class="fa-solid fa-calendar-days"></i>
                <input type="date" class="lc-date-picker" id="lcDatePicker"
                       value="<?php echo htmlspecialchars($view_date); ?>"
                       min="<?php echo htmlspecialchars($calendar_min_date); ?>"
                       max="<?php echo htmlspecialchars($calendar_max_date); ?>"
                       aria-label="Chọn ngày">
            </div>
            <a href="loichua.php?ngay=<?php echo $next_date; ?>" class="lc-nav-btn <?php echo $can_go_next ? '' : 'disabled'; ?>" title="Ngày sau">
                <span>Ngày sau</span><i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php if (!$is_today): ?>
                <a href="loichua.php" class="lc-btn-today"><i class="fa-solid fa-sun"></i> Hôm nay</a>
            <?php endif; ?>
        </div>



        <?php if ($show_tabs): ?>

        <div class="lc-tabs-wrap">

            <nav class="lc-tabs" aria-label="Mục đọc">

                <a href="#loi-chua" class="lc-tab active">Lời Chúa</a>

                <a href="#suy-niem" class="lc-tab">Suy Niệm</a>

            </nav>

        </div>

        <?php endif; ?>



        <div class="lc-readings">



            <?php if ($has_loi_chua): ?>

            <article class="lc-reading" id="loi-chua">

                <div class="lc-reading-header">

                    <h3><span class="lc-h-ico"><i class="fa-solid fa-book-bible"></i></span> Bài Đọc &amp; Tin Mừng</h3>

                    <span class="lc-ref"><?php echo htmlspecialchars($reading_date_display); ?></span>

                </div>

                <div class="lc-reading-body">



                    <?php if ($has_reading_1): ?>

                    <section class="lc-subsection">

                        <h4 class="lc-sub-head"><span class="lc-sub-label">Bài Đọc I</span><?php if ($reading_1_ref): ?><span class="lc-sub-ref"><?php echo htmlspecialchars($reading_1_ref); ?></span><?php elseif ($liturgy_year): ?><span class="lc-sub-ref">Năm <?php echo htmlspecialchars($liturgy_year); ?></span><?php endif; ?></h4>

                        <div class="lc-reading-text"><?php echo $reading_1_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_psalm): ?>

                    <section class="lc-subsection psalm">

                        <h4 class="lc-sub-head"><span class="lc-sub-label">Đáp Ca</span></h4>

                        <div class="lc-reading-text"><?php echo $reading_psalm_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_alleluia): ?>

                    <section class="lc-subsection alleluia">

                        <h4 class="lc-sub-head"><span class="lc-sub-label">Alleluia</span></h4>

                        <div class="lc-reading-text"><?php echo $reading_alleluia_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_gospel): ?>

                    <section class="lc-subsection gospel">

                        <h4 class="lc-sub-head"><span class="lc-sub-label"><i class="fa-solid fa-cross"></i> Phúc Âm</span><?php if ($gospel_ref): ?><span class="lc-sub-ref"><?php echo htmlspecialchars($gospel_ref); ?></span><?php endif; ?></h4>

                        <div class="lc-reading-text"><?php echo $gospel_html; ?></div>

                    </section>

                    <?php endif; ?>



                </div>

            </article>

            <?php endif; ?>



            <?php if ($has_gospel && !$has_loi_chua): ?>

            <article class="lc-reading gospel" id="tin-mung">

                <div class="lc-reading-header">

                    <h3><span class="lc-h-ico"><i class="fa-solid fa-cross"></i></span> Tin Mừng</h3>

                    <?php if ($gospel_ref): ?><span class="lc-ref"><?php echo htmlspecialchars($gospel_ref); ?></span><?php endif; ?>

                </div>

                <div class="lc-reading-body">

                    <div class="lc-reading-text"><?php echo $gospel_html; ?></div>

                </div>

            </article>

            <?php endif; ?>



            <?php if ($has_reflection): ?>

            <article class="lc-reading reflection" id="suy-niem">

                <div class="lc-reading-header">

                    <h3><span class="lc-h-ico"><i class="fa-solid fa-hands-praying"></i></span> Suy Niệm</h3>

                </div>

                <div class="lc-reading-body">

                    <div class="lc-reading-text"><?php echo $reflection_html; ?></div>

                </div>

            </article>

            <?php endif; ?>



            <?php if (!$has_content): ?>

            <div class="lc-reading">

                <div class="lc-empty">

                    <i class="fa-solid fa-book-open"></i>

                    <p><?php echo htmlspecialchars($empty_message); ?></p>

                </div>

            </div>

            <?php endif; ?>



        </div>

    </main>



    <footer class="lc-footer">

        <img src="img/logo.png" alt="" class="lc-footer-logo">
        <div>Đoàn TNTT Đaminh Savio Lập Trí</div>
        <a href="index.php"><i class="fa-solid fa-house"></i> Về trang chủ</a>

    </footer>



    <script>

        document.getElementById('lcDatePicker').addEventListener('change', function () {

            if (this.value) {

                window.location.href = 'loichua.php?ngay=' + this.value;

            }

        });



        document.querySelectorAll('.lc-tab').forEach(function (tab) {

            tab.addEventListener('click', function () {

                document.querySelectorAll('.lc-tab').forEach(function (t) { t.classList.remove('active'); });

                tab.classList.add('active');

            });

        });



        var sections = document.querySelectorAll('.lc-reading[id]');

        if (sections.length && 'IntersectionObserver' in window) {

            var observer = new IntersectionObserver(function (entries) {

                entries.forEach(function (entry) {

                    if (entry.isIntersecting) {

                        var id = entry.target.getAttribute('id');

                        document.querySelectorAll('.lc-tab').forEach(function (tab) {

                            tab.classList.toggle('active', tab.getAttribute('href') === '#' + id);

                        });

                    }

                });

            }, { rootMargin: '-40% 0px -50% 0px', threshold: 0 });

            sections.forEach(function (s) { observer.observe(s); });

        }

    </script>

</body>

</html>


