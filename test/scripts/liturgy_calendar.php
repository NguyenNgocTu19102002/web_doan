<?php

function liturgy_calendar_ensure_table($mysqli) {
    $sql = "CREATE TABLE IF NOT EXISTS liturgy_calendar (
        ngay DATE NOT NULL PRIMARY KEY,
        feast VARCHAR(500) NULL,
        liturgy_calendar VARCHAR(500) NULL,
        readings_summary VARCHAR(500) NULL,
        liturgical_color VARCHAR(50) NULL,
        liturgy_year VARCHAR(10) NULL,
        reading_1_ref VARCHAR(120) NULL,
        gospel_ref VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_liturgy_ngay (ngay)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $mysqli->query($sql);
}

function liturgy_calendar_load($mysqli, $date) {
    liturgy_calendar_ensure_table($mysqli);

    $sql = "SELECT feast, liturgy_calendar, readings_summary, liturgical_color,
                   liturgy_year, reading_1_ref, gospel_ref
            FROM liturgy_calendar WHERE ngay = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result(
        $feast,
        $liturgy_calendar,
        $readings_summary,
        $liturgical_color,
        $liturgy_year,
        $reading_1_ref,
        $gospel_ref
    );

    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }

    $stmt->close();
    return [
        'feast' => $feast,
        'liturgy_calendar' => $liturgy_calendar,
        'readings_summary' => $readings_summary,
        'liturgical_color' => $liturgical_color,
        'liturgy_year' => $liturgy_year,
        'reading_1_ref' => $reading_1_ref,
        'gospel_ref' => $gospel_ref,
    ];
}

function liturgy_calendar_apply(&$data, $calendar) {
    if (!$calendar) {
        return;
    }

    foreach (['feast', 'liturgy_calendar', 'readings_summary', 'liturgical_color', 'liturgy_year', 'gospel_ref'] as $key) {
        if (!empty($calendar[$key])) {
            $data[$key] = $calendar[$key];
        }
    }
}

function liturgy_calendar_parse_date($value) {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

function liturgy_calendar_import_csv($mysqli, $filePath) {
    liturgy_calendar_ensure_table($mysqli);

    if (!is_readable($filePath)) {
        return ['success' => false, 'message' => 'Không đọc được file CSV.'];
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ['success' => false, 'message' => 'Không mở được file CSV.'];
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'File CSV trống.'];
    }

    $map = [];
    foreach ($header as $i => $col) {
        $key = mb_strtolower(trim($col), 'UTF-8');
        $key = str_replace([' ', '-'], '_', $key);
        $aliases = [
            'ngay' => 'ngay',
            'date' => 'ngay',
            'le' => 'feast',
            'lễ' => 'feast',
            'feast' => 'feast',
            'ngay_phung_vu' => 'liturgy_calendar',
            'liturgy_calendar' => 'liturgy_calendar',
            'bai_doc' => 'readings_summary',
            'readings_summary' => 'readings_summary',
            'mau_ao_le' => 'liturgical_color',
            'liturgical_color' => 'liturgical_color',
            'nam_phung_vu' => 'liturgy_year',
            'liturgy_year' => 'liturgy_year',
            'bai_doc_1' => 'reading_1_ref',
            'reading_1_ref' => 'reading_1_ref',
            'tin_mung' => 'gospel_ref',
            'gospel_ref' => 'gospel_ref',
        ];
        if (isset($aliases[$key])) {
            $map[$aliases[$key]] = $i;
        }
    }

    if (!isset($map['ngay'])) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV cần cột ngay (YYYY-MM-DD hoặc DD/MM/YYYY).'];
    }

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
        fclose($handle);
        return ['success' => false, 'message' => 'Lỗi chuẩn bị truy vấn.'];
    }

    $imported = 0;
    $skipped = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $ngay_raw = isset($row[$map['ngay']]) ? $row[$map['ngay']] : '';
        $ngay = liturgy_calendar_parse_date($ngay_raw);
        if (!$ngay) {
            $skipped++;
            continue;
        }

        $get = function ($key) use ($map, $row) {
            if (!isset($map[$key]) || !isset($row[$map[$key]])) {
                return '';
            }
            return trim($row[$map[$key]]);
        };

        $feast = $get('feast');
        $liturgy_calendar = $get('liturgy_calendar');
        $readings_summary = $get('readings_summary');
        $liturgical_color = $get('liturgical_color');
        $liturgy_year = $get('liturgy_year');
        $reading_1_ref = $get('reading_1_ref');
        $gospel_ref = $get('gospel_ref');

        $stmt->bind_param(
            'ssssssss',
            $ngay,
            $feast,
            $liturgy_calendar,
            $readings_summary,
            $liturgical_color,
            $liturgy_year,
            $reading_1_ref,
            $gospel_ref
        );

        if ($stmt->execute()) {
            $imported++;
        } else {
            $skipped++;
        }
    }

    $stmt->close();
    fclose($handle);

    return [
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'message' => "Đã nhập $imported ngày" . ($skipped ? ", bỏ qua $skipped dòng" : '') . '.',
    ];
}
