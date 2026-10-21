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

    loichua_ensure_columns($mysqli);

    liturgy_calendar_ensure_table($mysqli);

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



$calendar_max_date = '2026-09-30';
$today = date('Y-m-d');

$view_date = $today;

if (isset($_GET['ngay']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ngay'])) {

    $view_date = $_GET['ngay'];

}

if ($view_date > $calendar_max_date) {
    $view_date = $calendar_max_date;
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
            && ($reading_1_html !== '' || $gospel_html !== '');

        if ($stale_readings) {

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

            <a href="index.php" class="lc-back"><i class="fa-solid fa-arrow-left"></i> Trang chủ</a>

            <h1 class="lc-topbar-title"><i class="fa-solid fa-book-bible"></i> Lời Chúa <?php echo $is_today ? 'Hôm Nay' : $reading_date_display; ?></h1>

        </div>

    </header>



    <main class="lc-main">



        <section class="lc-banner">

            <div class="lc-banner-accent"></div>

            <div class="lc-banner-body">

                <div class="lc-banner-top">

                    <div class="lc-date-widget">

                        <div>

                            <div class="lc-weekday"><?php echo htmlspecialchars($reading_day_name); ?></div>

                            <div class="lc-day-num"><?php echo htmlspecialchars($day_of_month); ?></div>

                            <div class="lc-month">

                                <?php if ($is_today): ?><span class="lc-today-dot"></span><?php endif; ?>

                                Tháng <?php echo htmlspecialchars($month_year); ?>

                            </div>

                        </div>

                    </div>



                    <div class="lc-banner-info">

                        <div class="lc-date-controls">

                            <a href="loichua.php?ngay=<?php echo $prev_date; ?>" class="lc-nav-btn" title="Ngày trước"><i class="fa-solid fa-chevron-left"></i></a>

                            <div class="lc-date-picker-wrap">

                                <i class="fa-solid fa-calendar-days"></i>

                                <input type="date" class="lc-date-picker" id="lcDatePicker"

                                       value="<?php echo htmlspecialchars($view_date); ?>"

                                       max="<?php echo htmlspecialchars($calendar_max_date); ?>"

                                       aria-label="Chọn ngày">

                            </div>

                            <a href="loichua.php?ngay=<?php echo $next_date; ?>" class="lc-nav-btn <?php echo $can_go_next ? '' : 'disabled'; ?>" title="Ngày sau"><i class="fa-solid fa-chevron-right"></i></a>

                            <?php if (!$is_today): ?>

                                <a href="loichua.php" class="lc-btn-today"><i class="fa-solid fa-sun me-1"></i> Hôm nay</a>

                            <?php endif; ?>

                        </div>



                        <?php if ($feast): ?>

                            <h2><?php echo htmlspecialchars($feast); ?></h2>

                        <?php endif; ?>

                        <?php if ($liturgy_calendar): ?>

                            <p class="lc-liturgy-day"><?php echo htmlspecialchars($liturgy_calendar); ?></p>

                        <?php endif; ?>



                        <div class="lc-chips">

                            <?php if ($liturgical_color): ?>

                                <span class="lc-chip color"><i class="fa-solid fa-palette"></i> <?php echo htmlspecialchars($liturgical_color); ?></span>

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

            </div>

        </section>



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

                    <h3><i class="fa-solid fa-book-bible"></i> Lời Chúa (Bài Đọc &amp; Tin Mừng)</h3>

                    <span class="lc-ref"><?php echo htmlspecialchars($reading_date_display); ?></span>

                </div>

                <div class="lc-reading-body">



                    <?php if ($has_reading_1): ?>

                    <section class="lc-subsection">

                        <h4>Bài Đọc I<?php if ($reading_1_ref): ?>: <?php echo htmlspecialchars($reading_1_ref); ?><?php elseif ($liturgy_year): ?> <small>(năm <?php echo htmlspecialchars($liturgy_year); ?>)</small><?php endif; ?></h4>

                        <div class="lc-reading-text"><?php echo $reading_1_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_psalm): ?>

                    <section class="lc-subsection">

                        <h4>Đáp Ca</h4>

                        <div class="lc-reading-text"><?php echo $reading_psalm_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_alleluia): ?>

                    <section class="lc-subsection">

                        <h4>Alleluia</h4>

                        <div class="lc-reading-text"><?php echo $reading_alleluia_html; ?></div>

                    </section>

                    <?php endif; ?>



                    <?php if ($has_gospel): ?>

                    <section class="lc-subsection gospel">

                        <h4>Phúc Âm<?php if ($gospel_ref): ?>: <?php echo htmlspecialchars($gospel_ref); ?><?php endif; ?></h4>

                        <div class="lc-reading-text"><?php echo $gospel_html; ?></div>

                    </section>

                    <?php endif; ?>



                </div>

            </article>

            <?php endif; ?>



            <?php if ($has_gospel && !$has_loi_chua): ?>

            <article class="lc-reading gospel" id="tin-mung">

                <div class="lc-reading-header">

                    <h3><i class="fa-solid fa-cross"></i> Tin Mừng</h3>

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

                    <h3><i class="fa-solid fa-hands-praying"></i> Suy Niệm</h3>

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

        Đoàn TNTT Đaminh Savio Lập Trí &middot; <a href="index.php">Về trang chủ</a>

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


