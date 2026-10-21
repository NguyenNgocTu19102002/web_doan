<?php

/**
 * Parse bảng lịch công giáo từ markdown/HTML (loichuahomnay.vn).
 */

function lich_cong_giao_parse_md_file($filePath, array $months = [], $year = 2026) {
    if (!is_readable($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    $entries = [];

    if (!preg_match_all(
        '/##\s*Lịch Công Giáo Tháng\s*(\d+)\s*-\s*' . preg_quote((string)$year, '/') . '\s*(.*?)(?=##\s*Lịch Công Giáo Tháng|\z)/uis',
        $content,
        $sections,
        PREG_SET_ORDER
    )) {
        return [];
    }

    foreach ($sections as $section) {
        $month = (int)$section[1];
        if ($months && !in_array($month, $months, true)) {
            continue;
        }

        $body = $section[2];
        if (!preg_match_all('/^\|\s*(\d{2}\/\d{2}[^|]*)\|\s*([^|]+)\|/mu', $body, $rows, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($rows as $row) {
            $parsed = lich_cong_giao_parse_row($row[1], $row[2], $year);
            if ($parsed) {
                $entries[$parsed['ngay']] = $parsed;
            }
        }
    }

    ksort($entries);
    return array_values($entries);
}

function lich_cong_giao_parse_row($col1, $col2, $year = 2026) {
    $col1 = trim($col1);
    $col2 = trim($col2);

    if (!preg_match('/^(\d{2})\/(\d{2})/', $col1, $dm)) {
        return null;
    }

    $ngay = sprintf('%04d-%02d-%02d', $year, (int)$dm[2], (int)$dm[1]);
    $dayLabel = trim(preg_replace('/^\d{2}\/\d{2}/u', '', $col1));

    $readings_summary = '';
    $liturgical_color = '';
    if (preg_match('/Bài đọc:\s*(.+?)(?:Áo Lễ:|$)/uis', $col2, $m)) {
        $readings_summary = trim(preg_replace('/\s+/u', ' ', $m[1]));
    } elseif (preg_match('/^\s*Áo Lễ:/u', $col2)) {
        $readings_summary = '';
    }

    if (preg_match('/Áo Lễ:\s*([^\[]+)/u', $col2, $m)) {
        $liturgical_color = trim($m[1]);
    }

    $reading_1_ref = '';
    $gospel_ref = '';
    if ($readings_summary !== '') {
        $parts = preg_split('/;\s*/u', $readings_summary);
        $refs = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^\([A-Za-zĐXTr]+\)$/u', $part)) {
                continue;
            }
            $refs[] = $part;
        }
        if (!empty($refs)) {
            $reading_1_ref = $refs[0];
            foreach ($refs as $ref) {
                if (preg_match('/^(Mt|Mk|Mc|Lc|Ga|Jn|Giô|Kh|Gr|Hs|St|Am|Is|Ed|Ep|1\s|2\s|3\s|Cv|Rm|Pl|Dt|Ds|Dcr|Cn|Gv|Gl|Hc|Kinh|Xh|Đn|Kh|Mk|Plm|Tt|1\s*Cr|2\s*Cr|2\s*Tx|2\s*Ga|2\s*Pr|2\s*Sb|1\s*Ga|1\s*V|1\s*Tx)/iu', $ref)) {
                    if (preg_match('/^(Mt|Mk|Mc|Lc|Ga|Jn)/iu', $ref)) {
                        $gospel_ref = $ref;
                        break;
                    }
                }
            }
            if ($gospel_ref === '' && count($refs) >= 2) {
                $gospel_ref = $refs[count($refs) - 1];
                if (preg_match('/^\(/', $gospel_ref)) {
                    $gospel_ref = $refs[count($refs) - 2] ?? '';
                }
            }
        }
    }

    $liturgy_year = '';
    $searchYear = $dayLabel . ' ' . $readings_summary;
    if (preg_match('/năm\s+([ABC]|I{1,2})/ui', $searchYear, $m)) {
        $liturgy_year = strtoupper($m[1]);
    }

    $feast = lich_cong_giao_extract_feast($dayLabel, $col2);

    return [
        'ngay' => $ngay,
        'feast' => $feast,
        'liturgy_calendar' => $dayLabel,
        'readings_summary' => $readings_summary,
        'liturgical_color' => $liturgical_color,
        'liturgy_year' => $liturgy_year,
        'reading_1_ref' => $reading_1_ref,
        'gospel_ref' => $gospel_ref,
    ];
}

function lich_cong_giao_extract_feast($dayLabel, $col2) {
    if (preg_match('/^(THÁNH|ĐỨC|CHÚA|SINH|CÁC|DANH|SUY|CUNG|HẾT|NGÀY)/u', $dayLabel)) {
        $name = preg_replace('/\.\s*Thánh.*$/u', '', $dayLabel);
        $name = preg_replace('/\.\s*Ban\s.*$/u', '', $name);
        if (mb_strlen($name, 'UTF-8') > 8) {
            return trim($name);
        }
    }

    if (preg_match('/(Lễ trọng|Lễ kính|Lễ nhớ|Lễ vọng)([^Bài]+)/u', $col2, $m)) {
        $extra = trim($m[2]);
        if ($extra !== '') {
            return trim($m[1] . $extra);
        }
    }

    return '';
}

function lich_cong_giao_import_entries($mysqli, array $entries) {
    require_once __DIR__ . '/liturgy_calendar.php';
    liturgy_calendar_ensure_table($mysqli);

    $sql = "INSERT INTO liturgy_calendar (
                ngay, feast, liturgy_calendar, readings_summary,
                liturgical_color, liturgy_year, reading_1_ref, gospel_ref
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                feast = VALUES(feast),
                liturgy_calendar = VALUES(liturgy_calendar),
                readings_summary = VALUES(readings_summary),
                liturgical_color = VALUES(liturgical_color),
                liturgy_year = VALUES(liturgy_year),
                reading_1_ref = VALUES(reading_1_ref),
                gospel_ref = VALUES(gospel_ref),
                updated_at = CURRENT_TIMESTAMP";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $count = 0;
    foreach ($entries as $e) {
        $stmt->bind_param(
            'ssssssss',
            $e['ngay'],
            $e['feast'],
            $e['liturgy_calendar'],
            $e['readings_summary'],
            $e['liturgical_color'],
            $e['liturgy_year'],
            $e['reading_1_ref'],
            $e['gospel_ref']
        );
        if ($stmt->execute()) {
            $count++;
        }
    }
    $stmt->close();
    return $count;
}

function lich_cong_giao_entries_to_csv(array $entries, $filePath) {
    $fp = fopen($filePath, 'w');
    if (!$fp) {
        return false;
    }

    fputcsv($fp, [
        'ngay', 'feast', 'liturgy_calendar', 'readings_summary',
        'liturgical_color', 'liturgy_year', 'reading_1_ref', 'gospel_ref',
    ]);

    foreach ($entries as $e) {
        fputcsv($fp, [
            $e['ngay'],
            $e['feast'],
            $e['liturgy_calendar'],
            $e['readings_summary'],
            $e['liturgical_color'],
            $e['liturgy_year'],
            $e['reading_1_ref'],
            $e['gospel_ref'],
        ]);
    }

    fclose($fp);
    return true;
}
