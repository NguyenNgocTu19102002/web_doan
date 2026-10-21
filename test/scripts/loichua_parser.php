<?php

function loichua_ensure_columns($mysqli) {
    $columns = [
        'feast' => 'VARCHAR(500) NULL',
        'liturgy_calendar' => 'VARCHAR(500) NULL',
        'readings_summary' => 'VARCHAR(500) NULL',
        'liturgical_color' => 'VARCHAR(50) NULL',
        'reading_1' => 'LONGTEXT NULL',
        'reading_psalm' => 'LONGTEXT NULL',
        'reading_alleluia' => 'LONGTEXT NULL',
        'gospel_ref' => 'VARCHAR(120) NULL',
        'liturgy_year' => 'VARCHAR(10) NULL',
    ];

    foreach ($columns as $name => $definition) {
        $check = $mysqli->query("SHOW COLUMNS FROM loichua_daily LIKE '$name'");
        if ($check && $check->num_rows === 0) {
            $mysqli->query("ALTER TABLE loichua_daily ADD COLUMN $name $definition");
        }
    }
}

function loichua_build_source_url($date) {
    $today = date('Y-m-d');
    if ($date === $today) {
        return 'https://loichuahomnay.vn/';
    }
    $slugDate = date('d-m-Y', strtotime($date));
    return 'https://loichuahomnay.vn/suy-niem-loi-chua-ngay-' . $slugDate;
}

function loichua_fetch_html($date) {
    $url = loichua_build_source_url($date);
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 25,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            ],
        ]);
        return @file_get_contents($url, false, $ctx);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($html && (int)$code === 200) ? $html : false;
}

function loichua_normalize_reading_text($text) {
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\xC2\xAB", "\xC2\xBB", '“', '”'], '"', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/,(\d)/u', ', $1', $text);
    $text = preg_replace('/([\.\?!])([A-ZÀ-ỸĂÂÊÔƠƯĐ"Ð])/u', '$1 $2', $text);
    $text = preg_replace('/"([A-ZÀ-ỸĂÂÊÔƠƯĐÐ])/u', '" $1', $text);
    $text = preg_replace_callback('/"([^"]*)"/u', function ($m) {
        return '"' . trim($m[1]) . '"';
    }, $text);
    $text = preg_replace('/(\d+(?:-\d+)?)(["\'])/u', '$1 $2', $text);
    $text = preg_replace('/(["\'][^"\']+["\']\.)\s*(Bài trích)/u', "$1\n\n$2", $text);
    $text = preg_replace('/(\d+(?:\.\s*\d+(?:-\d+)?)*)\s*"([^"]+)"\.?/u', "$1\n\n\"$2\".", $text);

    $breaks = [
        'Trích sách ',
        'Bài trích Phúc Âm',
        'Ðó là lời Chúa',
        'Đó là lời Chúa',
        'Ðó là Lời Chúa',
        'Đó là Lời Chúa',
        'Đáp:',
        'Ðáp:',
        'Xướng:',
        'Alleluia, alleluia',
    ];
    foreach ($breaks as $marker) {
        $text = preg_replace('/(' . preg_quote($marker, '/') . ')/u', "\n\n$1", $text);
    }

    $text = preg_replace('/([^\s])(Ðáp:|Đáp:)/u', "$1\n\n$2", $text);
    $text = preg_replace('/(\))(\d+)\./u', "$1\n$2.", $text);
    $text = preg_replace('/(\.)(\d+)\.\s+/u', "$1\n$2. ", $text);
    $text = preg_replace('/([\?\!])(\d+)\./u', "$1\n$2.", $text);
    $text = preg_replace('/:\s*(\d+)\.\s+/u', ":\n$1. ", $text);
    $text = preg_replace('/(\d+:\d+)([A-Za-zÀ-ỹ])/u', '$1 $2', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);

    return trim($text);
}

function loichua_text_to_html($text) {
    $text = loichua_normalize_reading_text($text);
    if ($text === '') {
        return '';
    }

    $parts = preg_split("/\r\n|\n|\r/", $text);
    $html = '';
    foreach ($parts as $line) {
        $line = trim($line);
        if ($line === '' || $line === '---') {
            continue;
        }
        if (preg_match('/^([^"]+)"\.?$/u', $line) && !preg_match('/^"/u', $line)) {
            $line = '"' . rtrim($line, '".') . '".';
        }
        if (preg_match('/^".+"\.?$/u', $line)) {
            $html .= '<p><em>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</em></p>';
        } else {
            $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }
    return $html;
}

function loichua_clean_html($html) {
    $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/iu', '', $html);
    $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/iu', '', $html);
    $html = preg_replace('/<ins\b[^>]*>[\s\S]*?<\/ins>/iu', '', $html);
    return $html;
}

function loichua_section_html_by_heading($html, $headingNeedle) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . loichua_clean_html($html));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//h1|//h2|//h3|//h4');
    if (!$nodes) {
        return '';
    }

    $capture = false;
    $chunks = [];
    foreach ($nodes as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if (!$capture) {
            if (mb_stripos($text, $headingNeedle, 0, 'UTF-8') !== false) {
                $capture = true;
            }
            continue;
        }
        if (preg_match('/^(Tin Mừng|Suy Niệm|Lời Chúa Hằng Ngày|LẼ SỐNG|HẠNH CÁC THÁNH|Mỗi ngày|Đọc Kinh)/iu', $text)) {
            break;
        }
        $next = $node->nextSibling;
        while ($next) {
            if ($next->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($next->nodeName);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                    break 2;
                }
                $chunks[] = $dom->saveHTML($next);
            }
            $next = $next->nextSibling;
        }
    }

    return trim(implode("\n", $chunks));
}

function loichua_html_to_reading_html($html) {
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (trim($text) === '') {
        return '';
    }
    if (strip_tags($html) !== $text && preg_match('/<p|<br/i', $html)) {
        return $html;
    }
    return loichua_text_to_html($text);
}

function loichua_extract_article_plain($plain, $date = '') {
    if (preg_match('/Lời Chúa Hằng Ngày\s*-\s*\d{2}\/\d{2}\/\d{4}:.+?(?=Suy Niệm Hôm Nay|SUY NIỆM LỜI CHÚA)/uis', $plain, $m)) {
        return trim($m[0]);
    }

    $displayDate = $date ? date('d/m/Y', strtotime($date)) : '';
    if ($displayDate !== '' && preg_match(
        '/LỜI CHÚA\s*\(BÀI ĐỌC\s*&\s*TIN MỪNG\)\s*-\s*NGÀY\s*' . preg_quote($displayDate, '/') . '.+?(?=SUY NIỆM LỜI CHÚA|LẼ SỐNG TRONG LỜI CHÚA|HẠNH CÁC THÁNH)/uis',
        $plain,
        $m
    )) {
        return trim($m[0]);
    }

    if (preg_match('/LỜI CHÚA\s*\(BÀI ĐỌC\s*&\s*TIN MỪNG\)\s*-\s*NGÀY\s*\d{2}\/\d{2}\/\d{4}.+?(?=SUY NIỆM LỜI CHÚA|LẼ SỐNG TRONG LỜI CHÚA|HẠNH CÁC THÁNH)/uis', $plain, $m)) {
        return trim($m[0]);
    }

    return $plain;
}

function loichua_parse_html($html, $date) {
    $result = [
        'title' => '',
        'feast' => '',
        'liturgy_calendar' => '',
        'readings_summary' => '',
        'liturgical_color' => '',
        'reading_1' => '',
        'reading_psalm' => '',
        'reading_alleluia' => '',
        'gospel' => '',
        'reflection' => '',
        'gospel_ref' => '',
        'liturgy_year' => '',
    ];

    if (!$html) {
        return $result;
    }

    $html = loichua_clean_html($html);
    $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace("/[ \t]+/u", ' ', $plain);
    $plain = preg_replace("/\n{3,}/u", "\n\n", $plain);
    $article = loichua_extract_article_plain($plain, $date);

    if (preg_match('/Lễ Hôm Nay:\s*(.+?)(?:\n|Các bài đọc)/u', $plain, $m)) {
        $result['feast'] = trim($m[1]);
    }

    if (preg_match('/Lịch công giáo ngày\s*\d{2}-\d{2}-\d{4}\s*\n+([^\n]+)/iu', $plain, $m)) {
        $result['liturgy_calendar'] = trim($m[1]);
    } elseif (preg_match('/Lịch công giáo[^\n]*\n+([^\n]+)\n+Các bài đọc/iu', $plain, $m)) {
        $result['liturgy_calendar'] = trim($m[1]);
    } elseif (preg_match('/###\s*(Thứ [^\n]+)/u', $plain, $m)) {
        $result['liturgy_calendar'] = trim($m[1]);
    }

    if (preg_match('/Lời Chúa Hằng Ngày\s*-\s*\d{2}\/\d{2}\/\d{4}:\s*(.+?)(?:\n|$)/u', $plain, $m)) {
        $result['title'] = trim($m[1]);
    } elseif (preg_match('/Lời Chúa Ngày\s+\d{2}\/\d{2}\/\d{4}:\s*(.+?)(?:\n|$)/u', $plain, $m)) {
        $result['title'] = trim($m[1]);
    }

    if (preg_match('/Các bài đọc và tin mừng hôm nay\s*\n+([^\n]+)/u', $plain, $m)) {
        $result['readings_summary'] = trim($m[1]);
    } elseif (preg_match('/Các bài đọc và tin mừng hôm nay\s*\n+([^\n]+)/iu', $plain, $m)) {
        $result['readings_summary'] = trim($m[1]);
    }

    if ($result['readings_summary'] === '' && preg_match('/Các bài đọc và tin mừng[^\n]*\n+([^\n]+)/iu', $plain, $m)) {
        $result['readings_summary'] = trim($m[1]);
    }

    if (preg_match('/Áo Lễ Linh Mục:\s*([^\n]+)/u', $plain, $m)) {
        $result['liturgical_color'] = trim($m[1]);
    } elseif (preg_match('/Áo Lễ:\s*([^\n\[]+)/u', $plain, $m)) {
        $result['liturgical_color'] = trim($m[1]);
    }

    $calendarSource = ($result['liturgy_calendar'] ?: $result['title']);
    if (preg_match('/năm\s+([ABC]|I{1,2})/ui', $calendarSource, $m)) {
        $result['liturgy_year'] = strtoupper($m[1]);
    }

    if (preg_match('/(?:Tin mừng|Phúc Âm):\s*([A-Za-z]{1,3}\s*\d+,\s*\d+(?:-\d+)?)/u', $article, $m)) {
        $result['gospel_ref'] = trim($m[1]);
    } elseif (preg_match('/\(([A-Za-z]{1,3}\s*\d+,\s*\d+(?:-\d+)?)\)/u', $result['title'], $m)) {
        $result['gospel_ref'] = trim($m[1]);
    }

    if (preg_match('/BÀI ĐỌC I[^:]*:\s*(.+?)(?=ĐÁP CA:|Ðáp Ca:|Đáp Ca:|Alleluia:|Tin mừng:|Phúc Âm:|TIN MỪNG:|Tin Mừng Hôm Nay)/uis', $article, $m)) {
        $result['reading_1'] = loichua_text_to_html($m[1]);
    } elseif (preg_match('/Bài Ðọc I:\s*(.+?)(?=Ðáp Ca:|Đáp Ca:|ĐÁP CA:|Alleluia:|Phúc Âm:|Tin Mừng)/uis', $article, $m)) {
        $result['reading_1'] = loichua_text_to_html($m[1]);
    }

    if (preg_match('/(?:ĐÁP CA|Ðáp Ca|Đáp Ca):\s*(.+?)(?=Alleluia:|ALLELUIA:|Phúc Âm:|Tin mừng:|TIN MỪNG)/uis', $article, $m)) {
        $result['reading_psalm'] = loichua_text_to_html($m[1]);
    }

    if (preg_match('/Alleluia:\s*(.+?)(?=Phúc Âm:|Tin mừng:|TIN MỪNG|Tin Mừng Hôm Nay)/uis', $article, $m)) {
        $result['reading_alleluia'] = loichua_text_to_html($m[1]);
    }

    if (preg_match('/Phúc Âm:\s*([A-Za-zÀ-ỹ]{1,3}\s*\d+,\s*\d+(?:-\d+)?)\s*["\']?(.+?)(?=Ðó là Lời Chúa\.|Đó là lời Chúa\.)/uis', $article, $m)) {
        $result['gospel_ref'] = trim($m[1]);
        $gospelText = trim($m[2]);
        if (strlen($gospelText) > 30) {
            $result['gospel'] = loichua_text_to_html($gospelText);
            $result['gospel'] .= '<p><em>Đó là Lời Chúa.</em></p>';
        }
    } elseif (preg_match('/Tin mừng:\s*([A-Za-zÀ-ỹ]{1,3}\s*\d+,\s*\d+(?:-\d+)?)\s*(.+?)(?=Ðó là Lời Chúa\.|Đó là lời Chúa\.)/uis', $article, $m)) {
        $result['gospel_ref'] = trim($m[1]);
        $gospelText = trim($m[2]);
        if (strlen($gospelText) > 30) {
            $result['gospel'] = loichua_text_to_html($gospelText);
            $result['gospel'] .= '<p><em>Đó là Lời Chúa.</em></p>';
        }
    } elseif (preg_match('/Tin mừng:\s*[^\n]+\s*\n+(.+?)(?=Ðó là Lời Chúa\.|Đó là lời Chúa\.|SUY NIỆM)/uis', $article, $m)) {
        $gospelText = trim($m[1]);
        if (strlen($gospelText) > 30) {
            $result['gospel'] = loichua_text_to_html($gospelText);
        }
    } elseif (preg_match('/Tin mừng:\s*[^\n]+\s*\n+(.+)$/uis', $article, $m)) {
        $gospelText = trim($m[1]);
        if (strlen($gospelText) > 30) {
            $result['gospel'] = loichua_text_to_html($gospelText);
        }
    } elseif (preg_match('/Tin Mừng Hôm Nay\s*(?:Tin mừng:\s*[^\n]+\s*)?(.+)$/uis', $article, $m)) {
        $gospelText = trim($m[1]);
        $gospelText = preg_replace('/^Tin mừng:\s*[^\n]+\s*/iu', '', $gospelText);
        if (strlen($gospelText) > 30) {
            $result['gospel'] = loichua_text_to_html($gospelText);
        }
    }

    if (preg_match('/Suy Niệm Hôm Nay\s*(.+?)(?=Mỗi ngày 1 câu lời Chúa|## Mỗi ngày|Lời Chúa Hằng Ngày\s*$)/uis', $plain, $m)) {
        $reflectionText = trim($m[1]);
        if (preg_match('/^(.+?)(?=\n\s*Suy niệm:\s*\(|$)/uis', $reflectionText, $first)) {
            $reflectionText = trim($first[1]);
        }
        if (strlen($reflectionText) > 80 && !preg_match('/^(Lời Chúa Là Ngọn|Trang chủ)/u', $reflectionText)) {
            $result['reflection'] = loichua_text_to_html($reflectionText);
        }
    } elseif (preg_match('/SUY NIỆM LỜI CHÚA[^\n]*\s*(.+?)(?=LẼ SỐNG TRONG LỜI CHÚA|HẠNH CÁC THÁNH NGÀY|$)/uis', $plain, $m)) {
        $reflectionText = trim($m[1]);
        if (preg_match('/^(.+?)(?=\n\s*Suy niệm:\s*\(|$)/uis', $reflectionText, $first)) {
            $reflectionText = trim($first[1]);
        }
        if (strlen($reflectionText) > 80 && !preg_match('/^(Lời Chúa Là Ngọn|Trang chủ|Suy niệm)/iu', $reflectionText)) {
            $result['reflection'] = loichua_text_to_html($reflectionText);
        }
    }

    if ($result['liturgy_calendar'] === '' && $result['title'] !== '') {
        $result['liturgy_calendar'] = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $result['title']));
    }

    if ($result['title'] === '' && $result['liturgy_calendar'] !== '') {
        $displayDate = date('d/m/Y', strtotime($date));
        $suffix = $result['gospel_ref'] ? ' (' . $result['gospel_ref'] . ')' : '';
        $result['title'] = $displayDate . ': ' . $result['liturgy_calendar'] . $suffix;
    }

    return $result;
}

function loichua_save_to_db($mysqli, $date, $parsed) {
    loichua_ensure_columns($mysqli);

    $sql = "INSERT INTO loichua_daily (
                ngay, title, gospel, reflection, feast, liturgy_calendar,
                readings_summary, liturgical_color, reading_1, reading_psalm,
                reading_alleluia, gospel_ref, liturgy_year
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                gospel = VALUES(gospel),
                reflection = VALUES(reflection),
                feast = VALUES(feast),
                liturgy_calendar = VALUES(liturgy_calendar),
                readings_summary = VALUES(readings_summary),
                liturgical_color = VALUES(liturgical_color),
                reading_1 = VALUES(reading_1),
                reading_psalm = VALUES(reading_psalm),
                reading_alleluia = VALUES(reading_alleluia),
                gospel_ref = VALUES(gospel_ref),
                liturgy_year = VALUES(liturgy_year),
                updated_at = CURRENT_TIMESTAMP";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $title = isset($parsed['title']) ? $parsed['title'] : '';
    $gospel = isset($parsed['gospel']) ? $parsed['gospel'] : '';
    $reflection = isset($parsed['reflection']) ? $parsed['reflection'] : '';
    $feast = isset($parsed['feast']) ? $parsed['feast'] : '';
    $liturgy_calendar = isset($parsed['liturgy_calendar']) ? $parsed['liturgy_calendar'] : '';
    $readings_summary = isset($parsed['readings_summary']) ? $parsed['readings_summary'] : '';
    $liturgical_color = isset($parsed['liturgical_color']) ? $parsed['liturgical_color'] : '';
    $reading_1 = isset($parsed['reading_1']) ? $parsed['reading_1'] : '';
    $reading_psalm = isset($parsed['reading_psalm']) ? $parsed['reading_psalm'] : '';
    $reading_alleluia = isset($parsed['reading_alleluia']) ? $parsed['reading_alleluia'] : '';
    $gospel_ref = isset($parsed['gospel_ref']) ? $parsed['gospel_ref'] : '';
    $liturgy_year = isset($parsed['liturgy_year']) ? $parsed['liturgy_year'] : '';

    $stmt->bind_param(
        'sssssssssssss',
        $date,
        $title,
        $gospel,
        $reflection,
        $feast,
        $liturgy_calendar,
        $readings_summary,
        $liturgical_color,
        $reading_1,
        $reading_psalm,
        $reading_alleluia,
        $gospel_ref,
        $liturgy_year
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function loichua_merge_calendar($parsed, $calendar) {
    if (!$calendar) {
        return $parsed;
    }
    foreach (['feast', 'liturgy_calendar', 'readings_summary', 'liturgical_color', 'liturgy_year', 'gospel_ref'] as $key) {
        if (!empty($calendar[$key])) {
            $parsed[$key] = $calendar[$key];
        }
    }
    return $parsed;
}

function loichua_fetch_and_save($mysqli, $date) {
    $html = loichua_fetch_html($date);
    if (!$html) {
        return ['success' => false, 'message' => 'Không tải được trang nguồn.'];
    }

    $parsed = loichua_parse_html($html, $date);

    if (function_exists('liturgy_calendar_load')) {
        require_once __DIR__ . '/liturgy_calendar.php';
        $calendar = liturgy_calendar_load($mysqli, $date);
        $parsed = loichua_merge_calendar($parsed, $calendar);
    }

    if (empty($parsed['gospel']) && empty($parsed['reflection'])
        && empty($parsed['reading_1']) && empty($parsed['reading_psalm'])) {
        return ['success' => false, 'message' => 'Không trích xuất được nội dung Lời Chúa.'];
    }

    if (!loichua_save_to_db($mysqli, $date, $parsed)) {
        return ['success' => false, 'message' => 'Không lưu được vào CSDL.'];
    }

    return ['success' => true, 'data' => $parsed];
}

function loichua_load_from_db($mysqli, $date) {
    loichua_ensure_columns($mysqli);

    $sql = "SELECT title, gospel, reflection, feast, liturgy_calendar, readings_summary,
                   liturgical_color, reading_1, reading_psalm, reading_alleluia,
                   gospel_ref, liturgy_year
            FROM loichua_daily WHERE ngay = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result(
        $title,
        $gospel,
        $reflection,
        $feast,
        $liturgy_calendar,
        $readings_summary,
        $liturgical_color,
        $reading_1,
        $reading_psalm,
        $reading_alleluia,
        $gospel_ref,
        $liturgy_year
    );

    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }

    $stmt->close();
    return [
        'title' => $title,
        'gospel' => $gospel,
        'reflection' => $reflection,
        'feast' => $feast,
        'liturgy_calendar' => $liturgy_calendar,
        'readings_summary' => $readings_summary,
        'liturgical_color' => $liturgical_color,
        'reading_1' => $reading_1,
        'reading_psalm' => $reading_psalm,
        'reading_alleluia' => $reading_alleluia,
        'gospel_ref' => $gospel_ref,
        'liturgy_year' => $liturgy_year,
    ];
}
